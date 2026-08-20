<?php

namespace Imel\Shared\Mime;

/**
 * RFC 5322 / MIME parser: turns a raw message into headers, bodies and attachments.
 * Handles multipart (mixed / alternative / related), base64 and quoted-printable
 * transfer encodings, RFC 2047 encoded headers and non UTF-8 charsets.
 */
final class Parser
{
    /**
     * @return array{
     *   headers: array<string,string>, message_id: string, in_reply_to: string,
     *   references: string[], subject: string, from_email: string, from_name: string,
     *   to: string[], cc: string[], bcc: string[], reply_to: string, date: ?int,
     *   text: string, html: string, attachments: array<int,array>
     * }
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = self::splitOnce($raw);
        $headers = self::parseHeaders($headerBlock);

        $result = [
            'headers'     => $headers,
            'message_id'  => trim($headers['message-id'] ?? ''),
            'in_reply_to' => trim($headers['in-reply-to'] ?? ''),
            'references'  => preg_split('/\s+/', trim($headers['references'] ?? '')) ?: [],
            'subject'     => self::decodeHeader($headers['subject'] ?? ''),
            'from_email'  => '',
            'from_name'   => '',
            'to'          => self::addresses($headers['to'] ?? ''),
            'cc'          => self::addresses($headers['cc'] ?? ''),
            'bcc'         => self::addresses($headers['bcc'] ?? ''),
            'reply_to'    => self::addresses($headers['reply-to'] ?? '')[0] ?? '',
            'date'        => isset($headers['date']) ? (strtotime($headers['date']) ?: null) : null,
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        $from = self::addressWithName($headers['from'] ?? '');
        $result['from_email'] = $from['email'];
        $result['from_name']  = $from['name'];
        $result['references'] = array_values(array_filter($result['references']));

        self::walkPart($headers, $body, $result);

        if ($result['text'] === '' && $result['html'] !== '') {
            $result['text'] = \Imel\Shared\Util::htmlToText($result['html']);
        }

        return $result;
    }

    private static function splitOnce(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string,string> lower-cased header name => unfolded value */
    public static function parseHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $headers[$name] .= ' ' . trim($line);   // folded continuation
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }
        return $headers;
    }

    private static function walkPart(array $headers, string $body, array &$result, int $depth = 0): void
    {
        if ($depth > 12) {
            return;
        }

        $contentType = strtolower($headers['content-type'] ?? 'text/plain');
        $mime = trim(explode(';', $contentType)[0]);
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $disposition = strtolower($headers['content-disposition'] ?? '');

        if (str_starts_with($mime, 'multipart/')) {
            $boundary = self::param($contentType, 'boundary');
            if ($boundary === '') {
                return;
            }
            foreach (self::splitParts($body, $boundary) as $part) {
                [$partHeaderBlock, $partBody] = self::splitOnce($part);
                self::walkPart(self::parseHeaders($partHeaderBlock), $partBody, $result, $depth + 1);
            }
            return;
        }

        $content = self::decodeBody($body, $encoding);
        $filename = self::decodeHeader(
            self::param($disposition, 'filename') ?: self::param($contentType, 'name')
        );
        $isAttachment = str_starts_with($disposition, 'attachment')
            || ($filename !== '' && !str_starts_with($mime, 'text/'))
            || (str_starts_with($disposition, 'inline') && !str_starts_with($mime, 'text/'));

        if ($isAttachment) {
            $result['attachments'][] = [
                'filename'     => $filename !== '' ? $filename : 'attachment-' . (count($result['attachments']) + 1),
                'content_type' => $mime,
                'content'      => $content,
                'content_id'   => trim($headers['content-id'] ?? '', '<> '),
                'is_inline'    => str_starts_with($disposition, 'inline'),
            ];
            return;
        }

        $charset = self::param($contentType, 'charset') ?: 'utf-8';
        $content = self::toUtf8($content, $charset);

        if ($mime === 'text/html') {
            $result['html'] .= $content;
        } elseif (str_starts_with($mime, 'text/')) {
            $result['text'] .= $content;
        }
    }

    /** @return string[] */
    private static function splitParts(string $body, string $boundary): array
    {
        $chunks = preg_split('/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*$/m', $body) ?: [];
        $parts = [];
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                continue;                     // preamble
            }
            $chunk = ltrim($chunk, "\n");
            if (trim($chunk) !== '') {
                $parts[] = $chunk;
            }
        }
        return $parts;
    }

    public static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    public static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\"' "));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        return $converted === false ? $text : $converted;
    }

    /** Extract a parameter such as boundary= or filename= from a header value. */
    public static function param(string $headerValue, string $name): string
    {
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $headerValue, $m)) {
            return $m[1];
        }
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $headerValue, $m)) {
            return trim($m[1], '"\'');
        }
        // RFC 2231 continuation: filename*=UTF-8''name
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\*\s*=\s*([^\';]*)\'[^\']*\'([^;]+)/i', $headerValue, $m)) {
            return rawurldecode($m[2]);
        }
        return '';
    }

    /** Decode RFC 2047 encoded words, e.g. =?UTF-8?B?...?= */
    public static function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $text = strtoupper($m[2]) === 'B'
                    ? (string) base64_decode($m[3], false)
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                return self::toUtf8($text, $m[1]);
            },
            $value
        );
        return trim($decoded ?? $value);
    }

    /** @return string[] */
    public static function addresses(string $headerValue): array
    {
        return \Imel\Shared\Util::parseAddressList(self::decodeHeader($headerValue));
    }

    /** @return array{email:string,name:string} */
    public static function addressWithName(string $headerValue): array
    {
        $value = self::decodeHeader($headerValue);
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>/', $value, $m)) {
            return ['email' => strtolower(trim($m[2])), 'name' => trim($m[1], " \t\"'")];
        }
        return ['email' => strtolower(trim($value, " \t<>\"'")), 'name' => ''];
    }
}
