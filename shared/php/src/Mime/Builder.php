<?php

namespace Imel\Shared\Mime;

/**
 * Builds an RFC 5322 message: multipart/alternative for the bodies wrapped in
 * multipart/mixed when attachments are present.
 */
final class Builder
{
    public static function build(array $m): string
    {
        $eol         = "\r\n";
        $boundaryAlt = 'alt_' . bin2hex(random_bytes(10));
        $boundaryMix = 'mix_' . bin2hex(random_bytes(10));
        $attachments = $m['attachments'] ?? [];
        $text        = (string) ($m['text'] ?? '');
        $html        = (string) ($m['html'] ?? '');

        $headers = [];
        $headers[] = 'Date: ' . date('r', $m['date'] ?? time());
        $headers[] = 'Message-ID: ' . ($m['message_id'] ?? \Imel\Shared\Util::messageId());
        $headers[] = 'From: ' . self::formatAddress((string) $m['from_email'], (string) ($m['from_name'] ?? ''));
        $headers[] = 'To: ' . implode(', ', $m['to'] ?? []);
        if (!empty($m['cc'])) {
            $headers[] = 'Cc: ' . implode(', ', $m['cc']);
        }
        if (!empty($m['reply_to'])) {
            $headers[] = 'Reply-To: ' . $m['reply_to'];
        }
        if (!empty($m['in_reply_to'])) {
            $headers[] = 'In-Reply-To: ' . $m['in_reply_to'];
        }
        if (!empty($m['references'])) {
            $headers[] = 'References: ' . implode(' ', (array) $m['references']);
        }
        $headers[] = 'Subject: ' . self::encodeHeader((string) ($m['subject'] ?? ''));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: imel.id';

        $body = '';
        if ($attachments) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMix . '"';
            $body .= '--' . $boundaryMix . $eol;
            $body .= self::alternativePart($text, $html, $boundaryAlt, $eol);
            foreach ($attachments as $attachment) {
                $body .= '--' . $boundaryMix . $eol . self::attachmentPart($attachment, $eol);
            }
            $body .= '--' . $boundaryMix . '--' . $eol;
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $body .= self::alternativeBody($text, $html, $boundaryAlt, $eol);
        }

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    private static function alternativePart(string $text, string $html, string $boundary, string $eol): string
    {
        return 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . $eol . $eol
            . self::alternativeBody($text, $html, $boundary, $eol);
    }

    private static function alternativeBody(string $text, string $html, string $boundary, string $eol): string
    {
        $out = '--' . $boundary . $eol;
        $out .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
        $out .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $out .= chunk_split(base64_encode($text !== '' ? $text : ' '), 76, $eol);

        $out .= '--' . $boundary . $eol;
        $out .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $out .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $out .= chunk_split(base64_encode($html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))), 76, $eol);

        return $out . '--' . $boundary . '--' . $eol;
    }

    private static function attachmentPart(array $attachment, string $eol): string
    {
        $content = $attachment['content'] ?? (isset($attachment['path']) ? (string) file_get_contents($attachment['path']) : '');
        $name    = self::encodeHeader((string) ($attachment['filename'] ?? 'file'));
        $type    = $attachment['content_type'] ?? 'application/octet-stream';
        $inline  = !empty($attachment['is_inline']);

        $part  = 'Content-Type: ' . $type . '; name="' . $name . '"' . $eol;
        $part .= 'Content-Transfer-Encoding: base64' . $eol;
        $part .= 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"' . $eol;
        if (!empty($attachment['content_id'])) {
            $part .= 'Content-ID: <' . $attachment['content_id'] . '>' . $eol;
        }
        return $part . $eol . chunk_split(base64_encode($content), 76, $eol);
    }

    public static function formatAddress(string $email, string $name = ''): string
    {
        return $name === '' ? $email : self::encodeHeader($name) . ' <' . $email . '>';
    }

    /** RFC 2047 encode a header value when it contains non-ASCII characters. */
    public static function encodeHeader(string $value): string
    {
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Escape leading dots so message data cannot terminate the SMTP DATA stage. */
    public static function dotStuff(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = str_replace("\n", "\r\n", $raw);
        return preg_replace('/^\./m', '..', $raw) ?? $raw;
    }
}
