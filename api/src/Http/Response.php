<?php

namespace Imel\Api\Http;

final class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function ok($data = null): void
    {
        self::json($data ?? ['ok' => true]);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(['error' => $message] + $extra, $status);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }

    public static function file(string $path, string $filename, string $contentType, bool $inline = false): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string) filesize($path));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            addslashes(preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'file'),
            rawurlencode($filename)
        ));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }
}
