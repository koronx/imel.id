<?php

namespace Imel\Shared\Smtp;

use Imel\Shared\Mime\Builder;
use RuntimeException;

/**
 * Blocking SMTP client used to relay outbound mail.
 * Talks to a configured smart host (SMTP_RELAY_HOST) when present,
 * otherwise it resolves the recipient's MX records and delivers directly.
 */
final class Client
{
    private $socket = null;
    private string $lastReply = '';

    public function __construct(
        private string $host,
        private int $port = 25,
        private int $timeout = 20,
        private bool $useTls = false,
        private string $username = '',
        private string $password = ''
    ) {
    }

    public static function fromEnv(): ?self
    {
        $host = getenv('SMTP_RELAY_HOST') ?: '';
        if ($host === '') {
            return null;
        }
        return new self(
            $host,
            (int) (getenv('SMTP_RELAY_PORT') ?: 587),
            20,
            filter_var(getenv('SMTP_RELAY_TLS') ?: 'true', FILTER_VALIDATE_BOOL),
            getenv('SMTP_RELAY_USER') ?: '',
            getenv('SMTP_RELAY_PASSWORD') ?: ''
        );
    }

    /** @param string[] $recipients */
    public function send(string $from, array $recipients, string $rawMessage): void
    {
        $this->connect();
        try {
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->write(Builder::dotStuff($rawMessage) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221, 250]);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout
        );
        if (!$this->socket) {
            throw new RuntimeException("Connect to {$this->host}:{$this->port} failed: {$errstr}");
        }
        stream_set_timeout($this->socket, $this->timeout);

        $this->expect([220]);
        $hostname = getenv('MAIL_HOSTNAME') ?: (getenv('MAIL_DOMAIN') ?: 'imel.id');
        $this->command('EHLO ' . $hostname, [250]);

        if ($this->useTls) {
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->command('EHLO ' . $hostname, [250]);
        }

        if ($this->username !== '') {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        }
    }

    private function command(string $line, array $expected): string
    {
        $this->write($line . "\r\n");
        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Broken SMTP connection while writing');
        }
    }

    private function expect(array $codes): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 2048)) !== false) {
            $reply .= $line;
            // multiline replies look like "250-STARTTLS"; the last one uses a space
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $this->lastReply = trim($reply);
        $code = (int) substr($this->lastReply, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Unexpected SMTP reply: ' . $this->lastReply);
        }
        return $this->lastReply;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @return string[] mail exchangers for a domain, best preference first */
    public static function resolveMx(string $domain): array
    {
        $hosts = [];
        $weights = [];
        if (function_exists('getmxrr') && @getmxrr($domain, $hosts, $weights) && $hosts) {
            array_multisort($weights, $hosts);
            return $hosts;
        }
        return checkdnsrr($domain, 'A') ? [$domain] : [];
    }
}
