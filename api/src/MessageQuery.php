<?php

namespace Imel\Api;

/**
 * Translates a label selection plus a Gmail-style search string into SQL.
 *
 * Supported operators:
 *   from:  to:  cc:  subject:  label:  in:  filename:
 *   is:unread is:read is:starred is:important is:draft
 *   has:attachment
 *   before:YYYY-MM-DD  after:YYYY-MM-DD  older_than:7d  newer_than:7d
 *   "quoted phrase"    -exclusion
 */
final class MessageQuery
{
    public const VIRTUAL_LABELS = ['starred', 'important', 'snoozed', 'archive', 'search'];

    /**
     * @return array{where:string, params:array}
     */
    public static function build(int $userId, string $label, string $q): array
    {
        $where  = ['m.user_id = ?'];
        $params = [$userId];

        $parsed = self::parseQuery($q);
        $scopeOverride = $parsed['in'] ?? null;
        $effectiveLabel = $scopeOverride ?: $label;

        self::applyLabel($effectiveLabel, $q !== '', $where, $params, $userId);
        self::applyTerms($parsed, $where, $params, $userId);

        return ['where' => implode(' AND ', $where), 'params' => $params];
    }

    private static function applyLabel(string $label, bool $searching, array &$where, array &$params, int $userId): void
    {
        $notDeleted = "m.folder NOT IN ('trash','spam')";

        switch ($label) {
            case 'inbox':
            case 'sent':
            case 'drafts':
            case 'trash':
            case 'spam':
                $where[] = 'm.folder = ?';
                $params[] = $label;
                return;

            case 'starred':
                $where[] = 'm.is_starred AND ' . $notDeleted;
                return;

            case 'important':
                $where[] = 'm.is_important AND ' . $notDeleted;
                return;

            case 'snoozed':
                $where[] = 'FALSE';   // snooze scheduling is not enabled yet
                return;

            case 'archive':
            case 'all':
            case 'search':
            case '':
                $where[] = $notDeleted;
                return;

            default:
                // user-defined or category label
                $where[] = 'EXISTS (
                    SELECT 1 FROM message_labels ml
                    JOIN labels l ON l.id = ml.label_id
                    WHERE ml.message_id = m.id AND l.user_id = ? AND l.slug = ?
                )';
                $params[] = $userId;
                $params[] = $label;
                if (!$searching) {
                    $where[] = $notDeleted;
                }
        }
    }

    private static function applyTerms(array $parsed, array &$where, array &$params, int $userId): void
    {
        foreach ($parsed['from'] as $value) {
            $where[] = '(m.from_email ILIKE ? OR m.from_name ILIKE ?)';
            $params[] = "%{$value}%";
            $params[] = "%{$value}%";
        }
        foreach ($parsed['to'] as $value) {
            $where[] = 'm.to_json ILIKE ?';
            $params[] = "%{$value}%";
        }
        foreach ($parsed['cc'] as $value) {
            $where[] = 'm.cc_json ILIKE ?';
            $params[] = "%{$value}%";
        }
        foreach ($parsed['subject'] as $value) {
            $where[] = 'm.subject ILIKE ?';
            $params[] = "%{$value}%";
        }
        foreach ($parsed['filename'] as $value) {
            $where[] = 'EXISTS (SELECT 1 FROM attachments a WHERE a.message_id = m.id AND a.filename ILIKE ?)';
            $params[] = "%{$value}%";
        }
        foreach ($parsed['label'] as $value) {
            $where[] = 'EXISTS (
                SELECT 1 FROM message_labels ml JOIN labels l ON l.id = ml.label_id
                WHERE ml.message_id = m.id AND l.user_id = ? AND (l.slug = ? OR lower(l.name) = ?)
            )';
            $params[] = $userId;
            $params[] = strtolower($value);
            $params[] = strtolower($value);
        }

        foreach ($parsed['is'] as $flag) {
            $where[] = match (strtolower($flag)) {
                'unread'          => 'NOT m.is_read',
                'read'            => 'm.is_read',
                'starred'         => 'm.is_starred',
                'unstarred'       => 'NOT m.is_starred',
                'important'       => 'm.is_important',
                'draft'           => 'm.is_draft',
                default           => 'TRUE',
            };
        }

        foreach ($parsed['has'] as $flag) {
            if (strtolower($flag) === 'attachment') {
                $where[] = 'm.has_attachment';
            }
        }

        if (isset($parsed['after'])) {
            $where[] = 'm.internal_date >= ?';
            $params[] = $parsed['after'];
        }
        if (isset($parsed['before'])) {
            $where[] = 'm.internal_date <= ?';
            $params[] = $parsed['before'];
        }

        foreach ($parsed['exclude'] as $value) {
            $where[] = 'NOT (m.subject ILIKE ? OR m.body_text ILIKE ? OR m.from_email ILIKE ?)';
            array_push($params, "%{$value}%", "%{$value}%", "%{$value}%");
        }

        if ($parsed['text'] !== '') {
            // full-text first, ILIKE fallback so partial words still match
            $where[] = "(m.search_tsv @@ plainto_tsquery('simple', ?)
                         OR m.subject ILIKE ? OR m.body_text ILIKE ?
                         OR m.from_email ILIKE ? OR m.from_name ILIKE ?)";
            $params[] = $parsed['text'];
            $like = '%' . $parsed['text'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
    }

    /**
     * @return array{from:string[],to:string[],cc:string[],subject:string[],label:string[],
     *               filename:string[],is:string[],has:string[],exclude:string[],text:string,
     *               in?:string,before?:string,after?:string}
     */
    public static function parseQuery(string $q): array
    {
        $out = [
            'from' => [], 'to' => [], 'cc' => [], 'subject' => [], 'label' => [],
            'filename' => [], 'is' => [], 'has' => [], 'exclude' => [], 'text' => '',
        ];

        $q = trim($q);
        if ($q === '') {
            return $out;
        }

        $free = [];
        // split on spaces but keep "quoted phrases" together
        preg_match_all('/"[^"]*"|\S+/', $q, $tokens);

        foreach ($tokens[0] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if ($token[0] === '-' && strlen($token) > 1 && !str_contains($token, ':')) {
                $out['exclude'][] = trim(substr($token, 1), '"');
                continue;
            }

            if (!preg_match('/^(-?)([a-z_]+):(.*)$/i', $token, $m)) {
                $free[] = trim($token, '"');
                continue;
            }

            $operator = strtolower($m[2]);
            $value = trim($m[3], '"');
            if ($value === '') {
                continue;
            }

            switch ($operator) {
                case 'from': case 'to': case 'cc': case 'subject': case 'label': case 'filename':
                    $out[$operator][] = $value;
                    break;
                case 'is': case 'has':
                    $out[$operator][] = $value;
                    break;
                case 'in':
                    $out['in'] = strtolower($value) === 'anywhere' ? 'all' : strtolower($value);
                    break;
                case 'before':
                    $out['before'] = self::date($value);
                    break;
                case 'after':
                    $out['after'] = self::date($value);
                    break;
                case 'older_than':
                    $out['before'] = self::relative($value);
                    break;
                case 'newer_than':
                    $out['after'] = self::relative($value);
                    break;
                default:
                    $free[] = $token;
            }
        }

        $out['text'] = trim(implode(' ', $free));
        $out = array_filter($out, static fn($v) => $v !== null);

        return $out;
    }

    private static function date(string $value): string
    {
        $timestamp = strtotime($value) ?: time();
        return date('c', $timestamp);
    }

    /** "7d", "2m", "1y" relative to now */
    private static function relative(string $value): string
    {
        if (!preg_match('/^(\d+)([dmy])$/i', trim($value), $m)) {
            return date('c');
        }
        $unit = ['d' => 'days', 'm' => 'months', 'y' => 'years'][strtolower($m[2])];
        return date('c', strtotime("-{$m[1]} {$unit}") ?: time());
    }
}
