<?php

namespace Imel\MailServer;

use Imel\Shared\Db;
use Imel\Shared\Smtp\Client;
use Imel\Shared\Storage;
use Imel\Shared\Util;
use Throwable;

/**
 * Delivers queued messages addressed outside our own domain directly to the
 * recipient's MX hosts over SMTP port 25.
 */
final class QueueWorker
{
    private const MAX_ATTEMPTS = 5;
    private const BATCH = 10;

    public function tick(): void
    {
        try {
            $db = Db::get();
            $jobs = $db->all(
                "SELECT * FROM outbound_queue
                 WHERE status = 'queued' AND next_attempt_at <= NOW()
                 ORDER BY id
                 LIMIT " . self::BATCH
            );
        } catch (Throwable $e) {
            Util::log('queue', 'cannot read queue: ' . $e->getMessage());
            Db::reset();
            return;
        }

        foreach ($jobs as $job) {
            $this->process($db, $job);
        }
    }

    private function process(Db $db, array $job): void
    {
        $db->run("UPDATE outbound_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ?", [$job['id']]);

        $recipients = json_decode((string) $job['recipients'], true) ?: [];
        $attempts = (int) $job['attempts'] + 1;

        try {
            if (!Storage::isInside((string) $job['raw_path']) || !is_file($job['raw_path'])) {
                throw new \RuntimeException('Raw message file is missing');
            }
            $raw = (string) file_get_contents($job['raw_path']);

            foreach ($this->groupByDomain($recipients) as $domain => $addresses) {
                $this->deliverDomain((string) $job['from_email'], $domain, $addresses, $raw);
            }

            $db->run("UPDATE outbound_queue SET status = 'sent', last_error = '' WHERE id = ?", [$job['id']]);
            Util::log('queue', 'delivered job ' . $job['id'] . ' directly to ' . implode(', ', $recipients));
        } catch (Throwable $e) {
            $failed = $attempts >= self::MAX_ATTEMPTS;
            $backoff = min(3600, 60 * (2 ** $attempts));

            $db->run(
                "UPDATE outbound_queue
                 SET status = ?, last_error = ?, next_attempt_at = NOW() + (? || ' seconds')::interval
                 WHERE id = ?",
                [$failed ? 'failed' : 'queued', $e->getMessage(), $backoff, $job['id']]
            );

            Util::log('queue', sprintf(
                'job %s attempt %d %s: %s',
                $job['id'],
                $attempts,
                $failed ? 'permanently failed' : 'deferred',
                $e->getMessage()
            ));

            if ($failed) {
                $this->notifySender($db, $job, $recipients, $e->getMessage());
            }
        }
    }

    /** @param string[] $addresses */
    private function deliverDomain(string $from, string $domain, array $addresses, string $raw): void
    {
        $hosts = Client::resolveMx($domain);
        if (!$hosts) {
            throw new \RuntimeException("No MX record for {$domain}");
        }

        $lastError = '';
        foreach ($hosts as $host) {
            try {
                // Direct delivery uses the recipient MX on standard SMTP port 25.
                (new Client($host, 25))->send($from, $addresses, $raw);
                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException("All MX hosts for {$domain} failed: {$lastError}");
    }

    /** @return array<string,string[]> */
    private function groupByDomain(array $recipients): array
    {
        $grouped = [];
        foreach ($recipients as $recipient) {
            $domain = strtolower(explode('@', (string) $recipient)[1] ?? '');
            if ($domain !== '') {
                $grouped[$domain][] = $recipient;
            }
        }
        return $grouped;
    }

    /** Give up: tell the sender with a delivery status notification in their inbox. */
    private function notifySender(Db $db, array $job, array $recipients, string $error): void
    {
        if ($job['user_id'] === null) {
            return;
        }

        $body = "Pesan Anda tidak dapat dikirim ke:\n  " . implode("\n  ", $recipients)
            . "\n\nAlasan: {$error}\n\nPesan sudah dicoba " . self::MAX_ATTEMPTS . " kali.\n";

        try {
            \Imel\Shared\MailStore::deliver($db, (int) $job['user_id'], [
                'message_id'  => Util::messageId(),
                'from_email'  => 'mailer-daemon@' . Util::mailDomain(),
                'from_name'   => 'Mail Delivery Subsystem',
                'to'          => [$job['from_email']],
                'cc'          => [],
                'bcc'         => [],
                'subject'     => 'Delivery Status Notification (Failure)',
                'text'        => $body,
                'html'        => '<pre style="font-family:Roboto,Arial,sans-serif">'
                    . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>',
                'date'        => time(),
                'attachments' => [],
                'headers'     => [],
            ], ['folder' => 'inbox', 'is_important' => true]);
        } catch (Throwable $e) {
            Util::log('queue', 'bounce delivery failed: ' . $e->getMessage());
        }
    }
}
