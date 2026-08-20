<?php

namespace Imel\Shared;

use RuntimeException;

/**
 * File storage for attachments and raw RFC822 messages.
 * Backed by the shared ./storage volume mounted into every PHP container.
 */
final class Storage
{
    public static function root(): string
    {
        return rtrim(getenv('STORAGE_PATH') ?: '/storage', '/');
    }

    private static function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create storage directory: {$dir}");
        }
        return $dir;
    }

    /** Attachments are sharded per user and per month to keep directories small. */
    public static function attachmentPath(int $userId, string $filename): string
    {
        $dir = self::ensureDir(self::root() . '/attachments/' . $userId . '/' . date('Y-m'));
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'file';
        return $dir . '/' . bin2hex(random_bytes(8)) . '_' . substr($safe, -120);
    }

    public static function saveAttachment(int $userId, string $filename, string $contents): string
    {
        $path = self::attachmentPath($userId, $filename);
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Failed writing attachment {$filename}");
        }
        return $path;
    }

    public static function saveRaw(string $raw, ?int $userId = null): string
    {
        $dir = self::ensureDir(self::root() . '/raw/' . ($userId !== null ? $userId . '/' : '') . date('Y-m-d'));
        $path = $dir . '/' . bin2hex(random_bytes(10)) . '.eml';
        if (file_put_contents($path, $raw) === false) {
            throw new RuntimeException('Failed writing raw message');
        }
        return $path;
    }

    public static function read(string $path): string
    {
        if (!self::isInside($path) || !is_file($path)) {
            throw new RuntimeException('File not found');
        }
        return (string) file_get_contents($path);
    }

    public static function delete(string $path): void
    {
        if ($path !== '' && self::isInside($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /** Guard against path traversal coming from database rows. */
    public static function isInside(string $path): bool
    {
        $real = realpath($path);
        $root = realpath(self::root());
        return $real !== false && $root !== false && str_starts_with($real, $root);
    }

    public static function size(string $path): int
    {
        return is_file($path) ? (int) filesize($path) : 0;
    }
}
