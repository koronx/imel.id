<?php

namespace Imel\Api\Controllers;

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Shared\Db;

final class LabelController
{
    public function __construct(private Db $db)
    {
    }

    public function index(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        Response::ok([
            'labels' => $this->labelsWithCounts((int) $user['id']),
        ]);
    }

    /** Sidebar payload: every label plus unread and total counters. */
    public function labelsWithCounts(int $userId): array
    {
        $labels = $this->db->all(
            'SELECT id, slug, name, color, type, position, show_in_list
             FROM labels WHERE user_id = ? ORDER BY position, name',
            [$userId]
        );

        $folderCounts = $this->db->all(
            'SELECT folder,
                    COUNT(*)                                AS total,
                    COUNT(*) FILTER (WHERE NOT is_read)     AS unread
             FROM messages WHERE user_id = ? GROUP BY folder',
            [$userId]
        );
        $byFolder = [];
        foreach ($folderCounts as $row) {
            $byFolder[$row['folder']] = ['total' => (int) $row['total'], 'unread' => (int) $row['unread']];
        }

        $labelCounts = $this->db->all(
            "SELECT l.slug,
                    COUNT(*)                            AS total,
                    COUNT(*) FILTER (WHERE NOT m.is_read) AS unread
             FROM message_labels ml
             JOIN labels l   ON l.id = ml.label_id
             JOIN messages m ON m.id = ml.message_id
             WHERE l.user_id = ? AND m.folder NOT IN ('trash','spam')
             GROUP BY l.slug",
            [$userId]
        );
        $byLabel = [];
        foreach ($labelCounts as $row) {
            $byLabel[$row['slug']] = ['total' => (int) $row['total'], 'unread' => (int) $row['unread']];
        }

        $starred = $this->db->one(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE NOT is_read) AS unread
             FROM messages WHERE user_id = ? AND is_starred AND folder NOT IN ('trash','spam')",
            [$userId]
        );
        $important = $this->db->one(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE NOT is_read) AS unread
             FROM messages WHERE user_id = ? AND is_important AND folder NOT IN ('trash','spam')",
            [$userId]
        );
        $allMail = $this->db->one(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE NOT is_read) AS unread
             FROM messages WHERE user_id = ? AND folder NOT IN ('trash','spam')",
            [$userId]
        );

        foreach ($labels as &$label) {
            $counts = match ($label['slug']) {
                'inbox', 'sent', 'drafts', 'trash', 'spam' => $byFolder[$label['slug']] ?? ['total' => 0, 'unread' => 0],
                'starred'   => ['total' => (int) $starred['total'],   'unread' => (int) $starred['unread']],
                'important' => ['total' => (int) $important['total'], 'unread' => (int) $important['unread']],
                'archive'   => ['total' => (int) $allMail['total'],   'unread' => (int) $allMail['unread']],
                'snoozed'   => ['total' => 0, 'unread' => 0],
                default     => $byLabel[$label['slug']] ?? ['total' => 0, 'unread' => 0],
            };

            $label['id']           = (int) $label['id'];
            $label['position']     = (int) $label['position'];
            $label['show_in_list'] = (bool) $label['show_in_list'];
            $label['total']        = $counts['total'];
            $label['unread']       = $counts['unread'];
        }

        return $labels;
    }

    public function store(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $name = $request->string('name');
        $color = $request->string('color', '#5f6368');

        if ($name === '') {
            throw ApiException::validation('Nama label wajib diisi', ['name' => 'Wajib diisi']);
        }
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $color = '#5f6368';
        }

        $slug = $this->slugify($name);
        if ($this->db->one('SELECT id FROM labels WHERE user_id = ? AND slug = ?', [$user['id'], $slug])) {
            throw ApiException::validation('Label sudah ada', ['name' => 'Nama label sudah digunakan']);
        }

        $id = (int) $this->db->insert(
            'INSERT INTO labels (user_id, slug, name, color, type, position)
             VALUES (?,?,?,?,\'user\', COALESCE((SELECT MAX(position) + 1 FROM labels WHERE user_id = ?), 30))
             RETURNING id',
            [$user['id'], $slug, $name, $color, $user['id']]
        );

        Response::json(['label' => $this->db->one('SELECT * FROM labels WHERE id = ?', [$id])], 201);
    }

    public function update(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $label = $this->ownedLabel((int) $user['id'], (int) $request->params['id']);

        if ($label['type'] !== 'user') {
            throw new ApiException('Label sistem tidak dapat diubah', 403);
        }

        $name  = $request->string('name', $label['name']);
        $color = $request->string('color', $label['color']);
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $color = $label['color'];
        }

        $this->db->run(
            'UPDATE labels SET name = ?, slug = ?, color = ? WHERE id = ?',
            [$name, $this->slugify($name), $color, $label['id']]
        );

        Response::ok(['label' => $this->db->one('SELECT * FROM labels WHERE id = ?', [$label['id']])]);
    }

    public function destroy(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $label = $this->ownedLabel((int) $user['id'], (int) $request->params['id']);

        if ($label['type'] !== 'user') {
            throw new ApiException('Label sistem tidak dapat dihapus', 403);
        }

        $this->db->run('DELETE FROM labels WHERE id = ?', [$label['id']]);
        Response::ok(['ok' => true]);
    }

    private function ownedLabel(int $userId, int $labelId): array
    {
        $label = $this->db->one('SELECT * FROM labels WHERE id = ? AND user_id = ?', [$labelId, $userId]);
        if (!$label) {
            throw ApiException::notFound('Label tidak ditemukan');
        }
        return $label;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? $name, '-'));
        return $slug !== '' ? substr($slug, 0, 100) : 'label-' . bin2hex(random_bytes(3));
    }
}
