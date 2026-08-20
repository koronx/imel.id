<?php

namespace Imel\Api;

use Imel\Shared\Db;
use Imel\Shared\Util;

/** Shapes database rows into the JSON contract used by the React client. */
final class Serializer
{
    public static function message(array $row, array $attachments = [], array $labels = [], bool $withBody = true): array
    {
        $message = [
            'id'             => (int) $row['id'],
            'thread_key'     => $row['thread_key'],
            'message_id'     => $row['message_id'],
            'from'           => [
                'email' => $row['from_email'],
                'name'  => Util::displayName($row['from_email'], (string) $row['from_name']),
            ],
            'to'             => Util::emailsFromJson($row['to_json'] ?? '[]'),
            'cc'             => Util::emailsFromJson($row['cc_json'] ?? '[]'),
            'bcc'            => Util::emailsFromJson($row['bcc_json'] ?? '[]'),
            'reply_to'       => $row['reply_to'] ?? '',
            'subject'        => $row['subject'],
            'snippet'        => $row['snippet'],
            'folder'         => $row['folder'],
            'is_read'        => self::bool($row['is_read']),
            'is_starred'     => self::bool($row['is_starred']),
            'is_important'   => self::bool($row['is_important']),
            'is_draft'       => self::bool($row['is_draft']),
            'has_attachment' => self::bool($row['has_attachment']),
            'size_bytes'     => (int) $row['size_bytes'],
            'date'           => $row['internal_date'],
            'sent_at'        => $row['sent_at'] ?? null,
            'labels'         => $labels,
            'attachments'    => $attachments,
        ];

        if ($withBody) {
            $message['body_html'] = $row['body_html'] ?? '';
            $message['body_text'] = $row['body_text'] ?? '';
            $message['in_reply_to'] = $row['in_reply_to'] ?? '';
            $message['references'] = array_values(array_filter(explode(' ', (string) ($row['reference_ids'] ?? ''))));
        }

        return $message;
    }

    public static function attachment(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'filename'     => $row['filename'],
            'content_type' => $row['content_type'],
            'size_bytes'   => (int) $row['size_bytes'],
            'is_inline'    => self::bool($row['is_inline']),
            'content_id'   => $row['content_id'] ?? '',
            'url'          => '/api/attachments/' . (int) $row['id'],
        ];
    }

    /** @return array<int,array> keyed by message id */
    public static function attachmentsByMessage(Db $db, array $messageIds): array
    {
        if (!$messageIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $rows = $db->all(
            "SELECT * FROM attachments WHERE message_id IN ({$placeholders}) ORDER BY id",
            $messageIds
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['message_id']][] = self::attachment($row);
        }
        return $out;
    }

    /** @return array<int,array> keyed by message id */
    public static function labelsByMessage(Db $db, array $messageIds): array
    {
        if (!$messageIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $rows = $db->all(
            "SELECT ml.message_id, l.id, l.slug, l.name, l.color, l.type
             FROM message_labels ml
             JOIN labels l ON l.id = ml.label_id
             WHERE ml.message_id IN ({$placeholders})
             ORDER BY l.position",
            $messageIds
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['message_id']][] = [
                'id'    => (int) $row['id'],
                'slug'  => $row['slug'],
                'name'  => $row['name'],
                'color' => $row['color'],
                'type'  => $row['type'],
            ];
        }
        return $out;
    }

    public static function bool($value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    /** Decode the PostgreSQL array literal PDO hands back as a string. */
    public static function pgArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $literal = trim((string) $value);
        if ($literal === '' || $literal === '{}') {
            return [];
        }
        $literal = substr($literal, 1, -1);

        $items = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;

        foreach (str_split($literal) as $char) {
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }
            if ($char === ',' && !$inQuotes) {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $items[] = $current;

        return array_values(array_filter($items, static fn($v) => $v !== '' && $v !== 'NULL'));
    }
}
