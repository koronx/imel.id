<?php

namespace Imel\Shared;

final class Util
{
    public const SYSTEM_FOLDERS = ['inbox', 'sent', 'drafts', 'trash', 'spam', 'archive'];

    public static function mailDomain(): string
    {
        return strtolower(getenv('MAIL_DOMAIN') ?: 'imel.id');
    }

    public static function isLocalAddress(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)));
        return count($parts) === 2 && $parts[1] === self::mailDomain();
    }

    public static function randomToken(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function messageId(): string
    {
        return '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . self::mailDomain() . '>';
    }

    /** Strip "Re:", "Fwd:", "[list]" style prefixes so replies group into one thread. */
    public static function normalizeSubject(string $subject): string
    {
        $s = trim($subject);
        do {
            $before = $s;
            $s = preg_replace('/^\s*(re|fw|fwd|balas|bls)\s*(\[\d+\])?\s*:\s*/i', '', $s) ?? $s;
        } while ($s !== $before);

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Conversation key: replies keep the key of the message they answer,
     * otherwise it is derived from the normalized subject + participants.
     */
    public static function threadKey(string $subject, array $participants, ?string $parentKey = null): string
    {
        if ($parentKey !== null && $parentKey !== '') {
            return $parentKey;
        }

        $normalized = mb_strtolower(self::normalizeSubject($subject));
        $people = array_map('strtolower', array_filter($participants));
        sort($people);

        if ($normalized === '') {
            $normalized = 'no-subject';
        }

        return substr(sha1($normalized . '|' . implode(',', $people)), 0, 40);
    }

    /** Short preview text shown next to the subject in the message list. */
    public static function snippet(string $text, string $html = '', int $length = 300): string
    {
        $source = trim($text);
        if ($source === '' && $html !== '') {
            $source = self::htmlToText($html);
        }
        $source = trim(preg_replace('/\s+/u', ' ', $source) ?? $source);
        return mb_substr($source, 0, $length);
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Remove active content from message HTML before it reaches the browser.
     * Remote images stay intact; the client renders them inside a sandboxed frame.
     */
    public static function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style|iframe|object|embed|form|meta|link|base)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed|form|meta|link|base)\b[^>]*/?>#i', '', $html) ?? $html;
        // inline event handlers: onclick="..." / onerror='...'
        $html = preg_replace('#\son[a-z-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        // javascript: and data: URLs in href/src
        $html = preg_replace('#(href|src|action)\s*=\s*("|\')\s*(javascript|vbscript|data)\s*:[^"\']*\2#i', '$1="#"', $html) ?? $html;

        return $html;
    }

    public static function emailsFromJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public static function validEmail(string $email): bool
    {
        return (bool) filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    }

    /** Split "a@x.id, Nama <b@y.id>; c@z.id" into a clean address list. */
    public static function parseAddressList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (preg_match('/<([^>]+)>/', $piece, $m)) {
                $piece = $m[1];
            }
            $piece = trim($piece, "\"' \t");
            if (self::validEmail($piece)) {
                $out[] = strtolower($piece);
            }
        }
        return array_values(array_unique($out));
    }

    public static function displayName(string $email, string $name = ''): string
    {
        if ($name !== '') {
            return $name;
        }
        $local = explode('@', $email)[0] ?? $email;
        return ucwords(str_replace(['.', '_', '-'], ' ', $local));
    }

    public static function log(string $scope, string $message): void
    {
        fwrite(STDOUT, sprintf("[%s] %-10s %s\n", date('Y-m-d H:i:s'), $scope, $message));
    }
}
