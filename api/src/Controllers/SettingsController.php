<?php

namespace Imel\Api\Controllers;

use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Shared\Db;

final class SettingsController
{
    private const ENUMS = [
        'theme'        => ['light', 'dark', 'system'],
        'density'      => ['default', 'comfortable', 'compact'],
        'reading_pane' => ['none', 'right', 'bottom'],
    ];

    public function __construct(private Db $db)
    {
    }

    public function show(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $row = $this->db->one('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]) ?? [];
        Response::ok(['settings' => self::normalize($row)]);
    }

    public function update(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $current = $this->db->one('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]);
        if (!$current) {
            $this->db->run('INSERT INTO user_settings (user_id) VALUES (?)', [$user['id']]);
            $current = $this->db->one('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]);
        }

        $body = $request->body();
        $values = [
            'theme'             => $this->enum('theme', $body['theme'] ?? $current['theme']),
            'density'           => $this->enum('density', $body['density'] ?? $current['density']),
            'reading_pane'      => $this->enum('reading_pane', $body['reading_pane'] ?? $current['reading_pane']),
            'per_page'          => max(10, min(100, (int) ($body['per_page'] ?? $current['per_page']))),
            'conversation_view' => $this->bool($body['conversation_view'] ?? $current['conversation_view']),
            'undo_send_seconds' => max(0, min(30, (int) ($body['undo_send_seconds'] ?? $current['undo_send_seconds']))),
            'signature'         => mb_substr((string) ($body['signature'] ?? $current['signature']), 0, 5000),
            'signature_enabled' => $this->bool($body['signature_enabled'] ?? $current['signature_enabled']),
            'vacation_enabled'  => $this->bool($body['vacation_enabled'] ?? $current['vacation_enabled']),
            'vacation_subject'  => mb_substr((string) ($body['vacation_subject'] ?? $current['vacation_subject']), 0, 250),
            'vacation_body'     => mb_substr((string) ($body['vacation_body'] ?? $current['vacation_body']), 0, 5000),
            'language'          => in_array($body['language'] ?? $current['language'], ['id', 'en'], true)
                ? ($body['language'] ?? $current['language'])
                : 'id',
        ];

        $this->db->run(
            'UPDATE user_settings SET
                theme = ?, density = ?, reading_pane = ?, per_page = ?, conversation_view = ?,
                undo_send_seconds = ?, signature = ?, signature_enabled = ?, vacation_enabled = ?,
                vacation_subject = ?, vacation_body = ?, language = ?, updated_at = NOW()
             WHERE user_id = ?',
            [...array_values($values), $user['id']]
        );

        $fresh = $this->db->one('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]);
        Response::ok(['settings' => self::normalize($fresh)]);
    }

    private function enum(string $field, $value): string
    {
        $allowed = self::ENUMS[$field];
        return in_array($value, $allowed, true) ? (string) $value : $allowed[0];
    }

    private function bool($value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 'true' : 'false';
    }

    /** Cast the raw row into the JSON shape the client expects. */
    public static function normalize(array $row): array
    {
        return [
            'theme'             => $row['theme'] ?? 'light',
            'density'           => $row['density'] ?? 'default',
            'reading_pane'      => $row['reading_pane'] ?? 'none',
            'per_page'          => (int) ($row['per_page'] ?? 50),
            'conversation_view' => (bool) ($row['conversation_view'] ?? true),
            'undo_send_seconds' => (int) ($row['undo_send_seconds'] ?? 5),
            'signature'         => $row['signature'] ?? '',
            'signature_enabled' => (bool) ($row['signature_enabled'] ?? false),
            'vacation_enabled'  => (bool) ($row['vacation_enabled'] ?? false),
            'vacation_subject'  => $row['vacation_subject'] ?? '',
            'vacation_body'     => $row['vacation_body'] ?? '',
            'language'          => $row['language'] ?? 'id',
        ];
    }
}
