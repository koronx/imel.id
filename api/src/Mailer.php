<?php

namespace Imel\Api;

use Imel\Shared\Db;
use Imel\Shared\MailStore;
use Imel\Shared\Mime\Builder;
use Imel\Shared\Storage;
use Imel\Shared\Util;

/**
 * Outbound pipeline: build the MIME message, drop a copy in Sent, deliver
 * locally addressed recipients straight away and queue the rest for relay.
 */
final class Mailer
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @param array $data to[], cc[], bcc[], subject, html, text, attachments[],
     *                    in_reply_to, references[], thread_key
     * @return array{message_id:int, delivered:string[], queued:string[]}
     */
    public function send(array $user, array $data): array
    {
        $to  = $data['to']  ?? [];
        $cc  = $data['cc']  ?? [];
        $bcc = $data['bcc'] ?? [];
        $recipients = array_values(array_unique(array_merge($to, $cc, $bcc)));

        if (!$recipients) {
            throw ApiException::validation('Minimal satu penerima diperlukan', ['to' => 'Wajib diisi']);
        }

        $html = Util::sanitizeHtml((string) ($data['html'] ?? ''));
        $text = (string) ($data['text'] ?? '');
        if ($text === '') {
            $text = Util::htmlToText($html);
        }

        $rfcMessageId = Util::messageId();
        $attachments  = $data['attachments'] ?? [];

        $raw = Builder::build([
            'message_id'  => $rfcMessageId,
            'from_email'  => $user['email'],
            'from_name'   => $user['full_name'],
            'to'          => $to,
            'cc'          => $cc,
            'subject'     => (string) ($data['subject'] ?? ''),
            'text'        => $text,
            'html'        => $html,
            'in_reply_to' => $data['in_reply_to'] ?? '',
            'references'  => $data['references'] ?? [],
            'attachments' => $attachments,
            'date'        => time(),
        ]);

        $parsed = [
            'message_id'  => $rfcMessageId,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references'  => $data['references'] ?? [],
            'from_email'  => $user['email'],
            'from_name'   => $user['full_name'],
            'to'          => $to,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'reply_to'    => '',
            'subject'     => (string) ($data['subject'] ?? ''),
            'text'        => $text,
            'html'        => $html,
            'date'        => time(),
            'attachments' => $attachments,
            'raw'         => $raw,
            'headers'     => [],
        ];

        // 1. sender's own copy
        $sentId = MailStore::deliver($this->db, (int) $user['id'], $parsed, [
            'folder'     => 'sent',
            'is_read'    => true,
            'is_draft'   => false,
            'sent_at'    => date('c'),
        ]);

        // 2. local delivery + 3. queue for everything outside our domain
        $delivered = [];
        $queued = [];
        $external = [];

        foreach ($recipients as $recipient) {
            if (Util::isLocalAddress($recipient)) {
                $target = MailStore::findUser($this->db, $recipient);
                if ($target && (int) $target['id'] !== (int) $user['id']) {
                    $copy = $parsed;
                    $copy['message_id'] = $rfcMessageId;   // same RFC id, different mailbox
                    MailStore::deliver($this->db, (int) $target['id'], $copy, ['folder' => 'inbox']);
                    $delivered[] = $recipient;
                } elseif ($target) {
                    // message to self: keep a copy in the inbox too
                    $copy = $parsed;
                    $copy['message_id'] = '<self-' . bin2hex(random_bytes(6)) . '@' . Util::mailDomain() . '>';
                    MailStore::deliver($this->db, (int) $user['id'], $copy, ['folder' => 'inbox']);
                    $delivered[] = $recipient;
                } else {
                    $this->bounce($user, $recipient, (string) ($data['subject'] ?? ''));
                }
                continue;
            }
            $external[] = $recipient;
        }

        if ($external) {
            $rawPath = Storage::saveRaw($raw, (int) $user['id']);
            $this->db->run(
                'INSERT INTO outbound_queue (user_id, message_row_id, from_email, recipients, raw_path)
                 VALUES (?,?,?,?,?)',
                [$user['id'], $sentId ?: null, $user['email'], json_encode($external), $rawPath]
            );
            $queued = $external;
        }

        return ['message_id' => $sentId, 'delivered' => $delivered, 'queued' => $queued];
    }

    /** Local recipient does not exist: deliver a delivery-status notice to the sender. */
    private function bounce(array $user, string $recipient, string $subject): void
    {
        $body = "Pesan Anda tidak dapat dikirim.\n\n"
            . "Alamat tujuan: {$recipient}\n"
            . "Alasan: alamat tersebut tidak terdaftar di " . Util::mailDomain() . "\n"
            . "Subjek asli: " . ($subject !== '' ? $subject : '(tanpa subjek)') . "\n";

        MailStore::deliver($this->db, (int) $user['id'], [
            'message_id'  => Util::messageId(),
            'from_email'  => 'mailer-daemon@' . Util::mailDomain(),
            'from_name'   => 'Mail Delivery Subsystem',
            'to'          => [$user['email']],
            'cc'          => [],
            'bcc'         => [],
            'subject'     => 'Delivery Status Notification (Failure)',
            'text'        => $body,
            'html'        => '<pre style="font-family:Roboto,Arial,sans-serif">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>',
            'date'        => time(),
            'attachments' => [],
            'headers'     => [],
        ], ['folder' => 'inbox', 'is_important' => true]);
    }
}
