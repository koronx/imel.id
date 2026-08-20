<?php

namespace Imel\MailServer;

use Imel\Shared\Db;
use Imel\Shared\MailStore;
use Imel\Shared\Mime\Parser;
use Imel\Shared\Storage;
use Imel\Shared\Util;
use Throwable;

/**
 * One SMTP conversation. The worker feeds raw socket chunks in and gets
 * protocol replies out, so the session owns all buffering and state.
 */
final class SmtpSession
{
    private const MAX_MESSAGE_BYTES = 26214400;   // 25 MB

    private string $buffer = '';
    private string $state = 'INIT';       // INIT|GREETED|MAIL|RCPT|DATA
    private string $authStage = '';       // '', USERNAME, PASSWORD
    private string $authUser = '';
    private string $from = '';
    private array $recipients = [];
    private string $data = '';
    private bool $close = false;
    private ?array $user = null;          // authenticated submitter

    public function __construct(private bool $submission = false)
    {
    }

    public function greeting(): string
    {
        return '220 ' . $this->hostname() . ' ESMTP imel.id ready' . "\r\n";
    }

    public function shouldClose(): bool
    {
        return $this->close;
    }

    /**
     * @return string[] replies to write back to the client
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $replies = [];

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r\n");
            $this->buffer = substr($this->buffer, $pos + 1);

            foreach ($this->line($line) as $reply) {
                $replies[] = $reply;
            }
            if ($this->close) {
                break;
            }
        }

        return $replies;
    }

    /** @return string[] */
    private function line(string $line): array
    {
        if ($this->state === 'DATA') {
            return $this->dataLine($line);
        }
        if ($this->authStage !== '') {
            return $this->authLine($line);
        }

        $verb = strtoupper(substr(trim($line), 0, 4));

        return match ($verb) {
            'HELO'      => $this->helo($line, false),
            'EHLO'      => $this->helo($line, true),
            'AUTH'      => $this->auth($line),
            'MAIL'      => $this->mailFrom($line),
            'RCPT'      => $this->rcptTo($line),
            'DATA'      => $this->dataStart(),
            'RSET'      => $this->reset(),
            'NOOP'      => ["250 OK\r\n"],
            'VRFY'      => ["252 Cannot VRFY user\r\n"],
            'QUIT'      => $this->quit(),
            'STAR'      => ["454 TLS not available on this listener\r\n"],  // STARTTLS
            default     => ["500 5.5.2 Command not recognized\r\n"],
        };
    }

    private function helo(string $line, bool $extended): array
    {
        $this->state = 'GREETED';
        $peer = trim(substr($line, 4)) ?: 'unknown';

        if (!$extended) {
            return ["250 {$this->hostname()} Hello {$peer}\r\n"];
        }

        return [
            "250-{$this->hostname()} Hello {$peer}\r\n",
            '250-SIZE ' . self::MAX_MESSAGE_BYTES . "\r\n",
            "250-8BITMIME\r\n",
            "250-AUTH PLAIN LOGIN\r\n",
            "250-ENHANCEDSTATUSCODES\r\n",
            "250 PIPELINING\r\n",
        ];
    }

    private function auth(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        $mechanism = strtoupper($parts[1] ?? '');

        if ($mechanism === 'LOGIN') {
            $this->authStage = 'USERNAME';
            return ["334 " . base64_encode('Username:') . "\r\n"];
        }

        if ($mechanism === 'PLAIN') {
            $credentials = $parts[2] ?? '';
            if ($credentials === '') {
                $this->authStage = 'PLAIN';
                return ["334 \r\n"];
            }
            return $this->authPlain($credentials);
        }

        return ["504 5.5.4 Authentication mechanism not supported\r\n"];
    }

    private function authLine(string $line): array
    {
        $decoded = (string) base64_decode(trim($line), true);

        switch ($this->authStage) {
            case 'USERNAME':
                $this->authUser = $decoded;
                $this->authStage = 'PASSWORD';
                return ["334 " . base64_encode('Password:') . "\r\n"];

            case 'PASSWORD':
                $this->authStage = '';
                return $this->verify($this->authUser, $decoded);

            case 'PLAIN':
                $this->authStage = '';
                return $this->authPlain(trim($line));
        }

        $this->authStage = '';
        return ["535 5.7.8 Authentication failed\r\n"];
    }

    private function authPlain(string $credentials): array
    {
        $decoded = (string) base64_decode($credentials, true);
        $parts = explode("\0", $decoded);       // authzid \0 authcid \0 password

        return $this->verify($parts[1] ?? '', $parts[2] ?? '');
    }

    private function verify(string $email, string $password): array
    {
        try {
            $row = Db::get()->one('SELECT id, email, full_name, password FROM users WHERE lower(email) = lower(?) AND is_active', [$email]);
        } catch (Throwable $e) {
            Util::log('smtp', 'auth lookup failed: ' . $e->getMessage());
            return ["451 4.3.0 Temporary authentication failure\r\n"];
        }

        if (!$row || !password_verify($password, $row['password'])) {
            Util::log('smtp', "auth failed for {$email}");
            return ["535 5.7.8 Username and Password not accepted\r\n"];
        }

        $this->user = $row;
        Util::log('smtp', "auth ok for {$row['email']}");

        return ["235 2.7.0 Authentication successful\r\n"];
    }

    private function mailFrom(string $line): array
    {
        if ($this->submission && $this->user === null) {
            return ["530 5.7.0 Authentication required\r\n"];
        }
        if (!preg_match('/FROM:\s*<([^>]*)>/i', $line, $m)) {
            return ["501 5.5.4 Syntax: MAIL FROM:<address>\r\n"];
        }

        $from = strtolower(trim($m[1]));
        if ($this->user !== null && $from !== '' && $from !== strtolower($this->user['email'])) {
            return ["550 5.7.1 Sender address does not match the authenticated user\r\n"];
        }

        $this->from = $from;
        $this->recipients = [];
        $this->state = 'MAIL';

        return ["250 2.1.0 Sender OK\r\n"];
    }

    private function rcptTo(string $line): array
    {
        if (!in_array($this->state, ['MAIL', 'RCPT'], true)) {
            return ["503 5.5.1 Need MAIL before RCPT\r\n"];
        }
        if (!preg_match('/TO:\s*<([^>]+)>/i', $line, $m)) {
            return ["501 5.5.4 Syntax: RCPT TO:<address>\r\n"];
        }

        $recipient = strtolower(trim($m[1]));
        if (!Util::validEmail($recipient)) {
            return ["550 5.1.3 Invalid recipient address\r\n"];
        }
        if (count($this->recipients) >= 100) {
            return ["452 4.5.3 Too many recipients\r\n"];
        }

        // Relaying for strangers is refused; authenticated users may send anywhere.
        if (!Util::isLocalAddress($recipient) && $this->user === null) {
            return ["550 5.7.1 Relay access denied\r\n"];
        }
        if (Util::isLocalAddress($recipient) && !MailStore::findUser(Db::get(), $recipient)) {
            return ["550 5.1.1 No such user here\r\n"];
        }

        $this->recipients[] = $recipient;
        $this->state = 'RCPT';

        return ["250 2.1.5 Recipient OK\r\n"];
    }

    private function dataStart(): array
    {
        if ($this->state !== 'RCPT' || !$this->recipients) {
            return ["503 5.5.1 Need RCPT before DATA\r\n"];
        }
        $this->state = 'DATA';
        $this->data = '';

        return ["354 End data with <CR><LF>.<CR><LF>\r\n"];
    }

    private function dataLine(string $line): array
    {
        if ($line !== '.') {
            // undo dot-stuffing, then buffer the line
            $this->data .= (str_starts_with($line, '..') ? substr($line, 1) : $line) . "\r\n";

            if (strlen($this->data) > self::MAX_MESSAGE_BYTES) {
                $this->reset();
                return ["552 5.3.4 Message exceeds size limit\r\n"];
            }
            return [];
        }

        $reply = $this->store();
        $this->reset();

        return [$reply];
    }

    private function store(): string
    {
        try {
            $db = Db::get();
            $parsed = Parser::parse($this->data);
            $parsed['raw'] = $this->data;
            if ($parsed['from_email'] === '') {
                $parsed['from_email'] = $this->from;
            }

            $local = [];
            $remote = [];
            foreach ($this->recipients as $recipient) {
                if (Util::isLocalAddress($recipient)) {
                    $local[] = $recipient;
                } else {
                    $remote[] = $recipient;
                }
            }

            foreach ($local as $recipient) {
                $target = MailStore::findUser($db, $recipient);
                if (!$target) {
                    continue;
                }
                $copy = $parsed;
                if (count($local) > 1 || $remote) {
                    // one row per mailbox needs its own Message-ID uniqueness scope
                    $copy['message_id'] = $parsed['message_id'] ?: Util::messageId();
                }
                MailStore::deliver($db, (int) $target['id'], $copy, ['folder' => 'inbox']);
                Util::log('smtp', "delivered to {$recipient} from {$this->from}");
            }

            if ($remote) {
                $rawPath = Storage::saveRaw($this->data, $this->user['id'] ?? null);
                $db->run(
                    'INSERT INTO outbound_queue (user_id, from_email, recipients, raw_path) VALUES (?,?,?,?)',
                    [$this->user['id'] ?? null, $this->from, json_encode($remote), $rawPath]
                );
                Util::log('smtp', 'queued ' . count($remote) . ' external recipient(s)');
            }

            return "250 2.0.0 OK: message accepted\r\n";
        } catch (Throwable $e) {
            Util::log('smtp', 'store failed: ' . $e->getMessage());
            Db::reset();
            return "451 4.3.0 Temporary failure storing message\r\n";
        }
    }

    private function reset(): array
    {
        $this->from = '';
        $this->recipients = [];
        $this->data = '';
        $this->state = $this->state === 'INIT' ? 'INIT' : 'GREETED';

        return ["250 2.0.0 OK\r\n"];
    }

    private function quit(): array
    {
        $this->close = true;
        return ['221 2.0.0 ' . $this->hostname() . " closing connection\r\n"];
    }

    private function hostname(): string
    {
        return getenv('MAIL_HOSTNAME') ?: ('mail.' . Util::mailDomain());
    }
}
