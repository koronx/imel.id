<?php

namespace Imel\Shared;

/**
 * Single place where a message becomes a row in someone's mailbox.
 * Used by the inbound SMTP server and by the REST API when it delivers
 * a locally addressed message straight into the recipient's inbox.
 */
final class MailStore
{
    /** @return array{id:int,email:string,full_name:string}|null */
    public static function findUser(Db $db, string $email): ?array
    {
        $row = $db->one(
            'SELECT id, email, full_name FROM users WHERE lower(email) = lower(?) AND is_active',
            [trim($email)]
        );
        return $row ?: null;
    }

    /**
     * Store one message for one user.
     *
     * @param array $msg  Parsed message (see Mime\Parser::parse) plus optional
     *                    keys: raw, attachments[{filename,content_type,content|path}]
     * @param array $opts folder, is_read, is_starred, is_important, is_draft, sent_at
     */
    public static function deliver(Db $db, int $userId, array $msg, array $opts = []): int
    {
        $folder    = $opts['folder'] ?? 'inbox';
        $raw       = (string) ($msg['raw'] ?? '');
        $rawPath   = $raw !== '' ? Storage::saveRaw($raw, $userId) : '';
        $text      = (string) ($msg['text'] ?? '');
        $html      = Util::sanitizeHtml((string) ($msg['html'] ?? ''));
        $to        = array_values($msg['to'] ?? []);
        $cc        = array_values($msg['cc'] ?? []);
        $bcc       = array_values($msg['bcc'] ?? []);
        $messageId = trim((string) ($msg['message_id'] ?? '')) ?: Util::messageId();
        $threadKey = self::resolveThreadKey($db, $userId, $msg);
        $size      = $raw !== '' ? strlen($raw) : strlen($text) + strlen($html);

        $id = (int) $db->insert(
            'INSERT INTO messages
                (user_id, message_id, thread_key, in_reply_to, reference_ids, from_email, from_name,
                 to_json, cc_json, bcc_json, reply_to, subject, snippet, body_text, body_html,
                 raw_path, folder, is_read, is_starred, is_important, is_draft, has_attachment,
                 size_bytes, internal_date, sent_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (user_id, message_id) WHERE message_id IS NOT NULL DO NOTHING
             RETURNING id',
            [
                $userId,
                $messageId,
                $threadKey,
                $msg['in_reply_to'] ?? null,
                implode(' ', (array) ($msg['references'] ?? [])),
                strtolower((string) $msg['from_email']),
                (string) ($msg['from_name'] ?? ''),
                json_encode($to), json_encode($cc), json_encode($bcc),
                (string) ($msg['reply_to'] ?? ''),
                mb_substr((string) ($msg['subject'] ?? ''), 0, 900),
                Util::snippet($text, $html),
                $text,
                $html,
                $rawPath,
                $folder,
                (bool) ($opts['is_read'] ?? false)      ? 'true' : 'false',
                (bool) ($opts['is_starred'] ?? false)   ? 'true' : 'false',
                (bool) ($opts['is_important'] ?? false) ? 'true' : 'false',
                (bool) ($opts['is_draft'] ?? false)     ? 'true' : 'false',
                !empty($msg['attachments'])             ? 'true' : 'false',
                $size,
                date('c', ($msg['date'] ?? null) ?: time()),
                $opts['sent_at'] ?? null,
            ]
        );

        if ($id === 0) {
            return 0;   // duplicate Message-ID for this user, already delivered
        }

        foreach ($msg['attachments'] ?? [] as $attachment) {
            self::storeAttachment($db, $userId, $id, $attachment);
        }

        self::applyAutoLabels($db, $userId, $id, $msg, $folder);
        self::rememberContacts($db, $userId, $folder, $msg);

        $db->run('UPDATE users SET used_bytes = used_bytes + ? WHERE id = ?', [$size, $userId]);

        return $id;
    }

    public static function storeAttachment(Db $db, int $userId, int $messageId, array $attachment): void
    {
        $content = $attachment['content'] ?? null;
        if ($content === null && !empty($attachment['path'])) {
            $content = (string) file_get_contents($attachment['path']);
        }
        if ($content === null) {
            return;
        }

        $filename = (string) ($attachment['filename'] ?? 'attachment');
        $path = Storage::saveAttachment($userId, $filename, $content);

        $db->run(
            'INSERT INTO attachments (message_id, user_id, filename, content_type, size_bytes, storage_path, content_id, is_inline)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $messageId,
                $userId,
                mb_substr($filename, 0, 250),
                (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                strlen($content),
                $path,
                (string) ($attachment['content_id'] ?? ''),
                !empty($attachment['is_inline']) ? 'true' : 'false',
            ]
        );
    }

    /**
     * Replies join the conversation of the message they answer; otherwise the
     * key is derived from the normalized subject plus the participants.
     */
    public static function resolveThreadKey(Db $db, int $userId, array $msg): string
    {
        $candidates = array_filter(array_merge(
            [(string) ($msg['in_reply_to'] ?? '')],
            (array) ($msg['references'] ?? [])
        ));

        foreach (array_reverse($candidates) as $reference) {
            $existing = $db->value(
                'SELECT thread_key FROM messages WHERE user_id = ? AND message_id = ? LIMIT 1',
                [$userId, trim($reference)]
            );
            if ($existing) {
                return (string) $existing;
            }
        }

        $participants = array_merge(
            [(string) $msg['from_email']],
            (array) ($msg['to'] ?? []),
            (array) ($msg['cc'] ?? [])
        );

        return Util::threadKey((string) ($msg['subject'] ?? ''), $participants);
    }

    /** Gmail-style category guessing plus the label mirroring the folder. */
    private static function applyAutoLabels(Db $db, int $userId, int $messageId, array $msg, string $folder): void
    {
        $slugs = [];
        if (in_array($folder, ['inbox', 'sent', 'drafts', 'trash', 'spam'], true)) {
            $slugs[] = $folder;
        }

        if ($folder === 'inbox') {
            $headers = $msg['headers'] ?? [];
            $from    = strtolower((string) ($msg['from_email'] ?? ''));
            $subject = mb_strtolower((string) ($msg['subject'] ?? ''));

            if (isset($headers['list-unsubscribe']) || preg_match('/(promo|diskon|sale|deals?|newsletter)/u', $subject)) {
                $slugs[] = 'promotions';
            } elseif (preg_match('/^(no-?reply|do-?not-?reply|noreply|notification|alert|billing|invoice|portal)/', explode('@', $from)[0] ?? '')) {
                $slugs[] = 'updates';
            } elseif (preg_match('/(facebook|twitter|instagram|linkedin|tiktok)\./', $from)) {
                $slugs[] = 'social';
            }
        }

        foreach (array_unique($slugs) as $slug) {
            $db->run(
                'INSERT INTO message_labels (message_id, label_id)
                 SELECT ?, id FROM labels WHERE user_id = ? AND slug = ?
                 ON CONFLICT DO NOTHING',
                [$messageId, $userId, $slug]
            );
        }
    }

    /** Autocomplete source: everyone the user writes to, and everyone who writes in. */
    private static function rememberContacts(Db $db, int $userId, string $folder, array $msg): void
    {
        $entries = [];
        if ($folder === 'sent') {
            foreach (array_merge((array) ($msg['to'] ?? []), (array) ($msg['cc'] ?? [])) as $email) {
                $entries[$email] = '';
            }
        } elseif ($folder === 'inbox') {
            $entries[(string) $msg['from_email']] = (string) ($msg['from_name'] ?? '');
        }

        foreach ($entries as $email => $name) {
            if (!Util::validEmail((string) $email)) {
                continue;
            }
            $db->run(
                'INSERT INTO contacts (user_id, email, name, times_contacted, last_contacted_at)
                 VALUES (?,?,?,1,NOW())
                 ON CONFLICT (user_id, email) DO UPDATE
                 SET times_contacted = contacts.times_contacted + 1,
                     last_contacted_at = NOW(),
                     name = CASE WHEN contacts.name = '' THEN EXCLUDED.name ELSE contacts.name END',
                [$userId, strtolower((string) $email), $name]
            );
        }
    }
}
