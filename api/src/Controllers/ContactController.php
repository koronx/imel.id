<?php

namespace Imel\Api\Controllers;

use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Shared\Db;
use Imel\Shared\Util;

/** Recipient autocomplete: known contacts first, then other accounts on this server. */
final class ContactController
{
    public function __construct(private Db $db)
    {
    }

    public function index(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $q = trim((string) $request->query('q', ''));
        $like = '%' . $q . '%';
        $limit = max(1, min(25, $request->queryInt('limit', 10)));

        $contacts = $this->db->all(
            'SELECT email, name, times_contacted
             FROM contacts
             WHERE user_id = ? AND (?::text = \'\' OR email ILIKE ? OR name ILIKE ?)
             ORDER BY times_contacted DESC, last_contacted_at DESC NULLS LAST
             LIMIT ?',
            [$user['id'], $q, $like, $like, $limit]
        );

        $known = array_column($contacts, 'email');
        $placeholders = $known ? implode(',', array_fill(0, count($known), '?')) : "''";

        $directory = $this->db->all(
            "SELECT email, full_name AS name, 0 AS times_contacted
             FROM users
             WHERE id <> ? AND is_active
               AND (?::text = '' OR email ILIKE ? OR full_name ILIKE ?)
               AND email NOT IN ({$placeholders})
             ORDER BY full_name
             LIMIT ?",
            [$user['id'], $q, $like, $like, ...$known, $limit]
        );

        $out = [];
        foreach ([...$contacts, ...$directory] as $row) {
            $out[] = [
                'email' => $row['email'],
                'name'  => Util::displayName($row['email'], (string) $row['name']),
                'score' => (int) $row['times_contacted'],
            ];
        }

        Response::ok(['contacts' => array_slice($out, 0, $limit)]);
    }
}
