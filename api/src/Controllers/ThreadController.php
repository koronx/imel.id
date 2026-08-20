<?php

namespace Imel\Api\Controllers;

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Api\MessageQuery;
use Imel\Api\Serializer;
use Imel\Shared\Db;

/**
 * Conversation list: messages grouped by thread_key, Gmail style.
 */
final class ThreadController
{
    public function __construct(private Db $db)
    {
    }

    public function index(Request $request): void
    {
        $user    = Auth::requireUser($this->db, $request);
        $userId  = (int) $user['id'];
        $label   = (string) $request->query('label', 'inbox');
        $q       = trim((string) $request->query('q', ''));
        $perPage = max(10, min(100, $request->queryInt('per_page', 50)));
        $page    = max(1, $request->queryInt('page', 1));
        $offset  = ($page - 1) * $perPage;
        $flat    = $request->queryBool('flat', false);

        $built  = MessageQuery::build($userId, $label, $q);
        $where  = $built['where'];
        $params = $built['params'];

        $groupBy = $flat ? 'm.id, m.thread_key' : 'm.thread_key';

        $rows = $this->db->all(
            "SELECT
                m.thread_key,
                MAX(m.internal_date)                            AS last_date,
                COUNT(*)                                        AS message_count,
                COUNT(*) FILTER (WHERE NOT m.is_read)           AS unread_count,
                BOOL_OR(m.is_starred)                           AS is_starred,
                BOOL_OR(m.is_important)                         AS is_important,
                BOOL_OR(m.has_attachment)                       AS has_attachment,
                BOOL_OR(m.is_draft)                             AS has_draft,
                (ARRAY_AGG(m.id ORDER BY m.internal_date DESC))[1] AS last_id,
                ARRAY_AGG(DISTINCT COALESCE(NULLIF(m.from_name, ''), m.from_email)) AS senders,
                ARRAY_AGG(m.id)                                 AS message_ids
             FROM messages m
             WHERE {$where}
             GROUP BY {$groupBy}
             ORDER BY last_date DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        $total = (int) $this->db->value(
            $flat
                ? "SELECT COUNT(*) FROM messages m WHERE {$where}"
                : "SELECT COUNT(DISTINCT m.thread_key) FROM messages m WHERE {$where}",
            $params
        );

        Response::ok([
            'threads' => $this->hydrate($userId, $rows),
            'paging'  => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int) ceil($total / $perPage),
                'from'     => $total === 0 ? 0 : $offset + 1,
                'to'       => min($offset + $perPage, $total),
            ],
            'label'   => $label,
            'query'   => $q,
        ]);
    }

    /** Full conversation: every message the user holds under this thread key. */
    public function show(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $threadKey = (string) ($request->params['key'] ?? '');

        $messages = $this->db->all(
            'SELECT * FROM messages WHERE user_id = ? AND thread_key = ? ORDER BY internal_date ASC',
            [$user['id'], $threadKey]
        );
        if (!$messages) {
            throw ApiException::notFound('Percakapan tidak ditemukan');
        }

        $ids = array_map(static fn($m) => (int) $m['id'], $messages);
        $attachments = Serializer::attachmentsByMessage($this->db, $ids);
        $labels = Serializer::labelsByMessage($this->db, $ids);

        if ($request->queryBool('mark_read', true)) {
            $this->db->run(
                'UPDATE messages SET is_read = TRUE WHERE user_id = ? AND thread_key = ? AND NOT is_read',
                [$user['id'], $threadKey]
            );
            foreach ($messages as &$message) {
                $message['is_read'] = true;
            }
            unset($message);
        }

        Response::ok([
            'thread' => [
                'thread_key' => $threadKey,
                'subject'    => $messages[count($messages) - 1]['subject'],
                'messages'   => array_map(
                    static fn(array $m) => Serializer::message($m, $attachments[(int) $m['id']] ?? [], $labels[(int) $m['id']] ?? []),
                    $messages
                ),
            ],
        ]);
    }

    /** Attach the last message of every thread so the list can render a row. */
    private function hydrate(int $userId, array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $lastIds = array_map(static fn($r) => (int) $r['last_id'], $rows);
        $placeholders = implode(',', array_fill(0, count($lastIds), '?'));
        $messages = $this->db->all(
            "SELECT * FROM messages WHERE user_id = ? AND id IN ({$placeholders})",
            [$userId, ...$lastIds]
        );

        $byId = [];
        foreach ($messages as $message) {
            $byId[(int) $message['id']] = $message;
        }

        $labels = Serializer::labelsByMessage($this->db, $lastIds);

        $out = [];
        foreach ($rows as $row) {
            $last = $byId[(int) $row['last_id']] ?? null;
            if (!$last) {
                continue;
            }
            $out[] = [
                'thread_key'     => $row['thread_key'],
                'message_count'  => (int) $row['message_count'],
                'unread_count'   => (int) $row['unread_count'],
                'is_read'        => (int) $row['unread_count'] === 0,
                'is_starred'     => $this->pgBool($row['is_starred']),
                'is_important'   => $this->pgBool($row['is_important']),
                'has_attachment' => $this->pgBool($row['has_attachment']),
                'has_draft'      => $this->pgBool($row['has_draft']),
                'senders'        => Serializer::pgArray($row['senders']),
                'message_ids'    => array_map('intval', Serializer::pgArray($row['message_ids'])),
                'last_message'   => Serializer::message($last, [], $labels[(int) $last['id']] ?? [], false),
                'date'           => $row['last_date'],
            ];
        }

        return $out;
    }

    private function pgBool($value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
