<?php

namespace Imel\Api\Controllers;

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Api\Mailer;
use Imel\Api\Serializer;
use Imel\Shared\Db;
use Imel\Shared\Storage;
use Imel\Shared\Util;

final class MessageController
{
    private const FOLDER_ACTIONS = [
        'archive'  => 'archive',
        'inbox'    => 'inbox',
        'trash'    => 'trash',
        'spam'     => 'spam',
        'not_spam' => 'inbox',
        'restore'  => 'inbox',
    ];

    public function __construct(private Db $db)
    {
    }

    public function show(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $message = $this->ownedMessage((int) $user['id'], (int) $request->params['id']);

        if ($request->queryBool('mark_read', true) && !Serializer::bool($message['is_read'])) {
            $this->db->run('UPDATE messages SET is_read = TRUE WHERE id = ?', [$message['id']]);
            $message['is_read'] = true;
        }

        $attachments = Serializer::attachmentsByMessage($this->db, [(int) $message['id']]);
        $labels = Serializer::labelsByMessage($this->db, [(int) $message['id']]);

        Response::ok([
            'message' => Serializer::message(
                $message,
                $attachments[(int) $message['id']] ?? [],
                $labels[(int) $message['id']] ?? []
            ),
        ]);
    }

    /** Raw RFC822 source, the equivalent of Gmail's "Show original". */
    public function raw(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $message = $this->ownedMessage((int) $user['id'], (int) $request->params['id']);

        $raw = '';
        if ($message['raw_path'] !== '' && Storage::isInside($message['raw_path'])) {
            $raw = Storage::read($message['raw_path']);
        }

        Response::ok(['raw' => $raw, 'available' => $raw !== '']);
    }

    /**
     * Bulk state changes for messages and/or whole conversations.
     * Body: { ids: [], thread_keys: [], action: "...", label_id: 12 }
     */
    public function batch(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $userId = (int) $user['id'];
        $action = $request->string('action');
        $ids = $this->resolveIds($userId, $request);

        if (!$ids) {
            throw ApiException::validation('Tidak ada pesan yang dipilih', ['ids' => 'Wajib diisi']);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $scope = [$userId, ...$ids];

        switch ($action) {
            case 'read':
            case 'unread':
                $this->db->run(
                    "UPDATE messages SET is_read = ? WHERE user_id = ? AND id IN ({$placeholders})",
                    [$action === 'read' ? 'true' : 'false', ...$scope]
                );
                break;

            case 'star':
            case 'unstar':
                $this->db->run(
                    "UPDATE messages SET is_starred = ? WHERE user_id = ? AND id IN ({$placeholders})",
                    [$action === 'star' ? 'true' : 'false', ...$scope]
                );
                break;

            case 'important':
            case 'unimportant':
                $this->db->run(
                    "UPDATE messages SET is_important = ? WHERE user_id = ? AND id IN ({$placeholders})",
                    [$action === 'important' ? 'true' : 'false', ...$scope]
                );
                break;

            case 'archive':
            case 'inbox':
            case 'trash':
            case 'spam':
            case 'not_spam':
            case 'restore':
                $this->moveFolder($userId, $ids, self::FOLDER_ACTIONS[$action]);
                break;

            case 'delete':
                $this->deleteForever($userId, $ids);
                break;

            case 'add_label':
            case 'remove_label':
                $this->toggleLabel($userId, $ids, $request, $action === 'add_label');
                break;

            default:
                throw ApiException::validation('Aksi tidak dikenal: ' . $action, ['action' => 'Tidak valid']);
        }

        Response::ok(['ok' => true, 'affected' => count($ids), 'ids' => $ids, 'action' => $action]);
    }

    public function send(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $payload = $this->composePayload($user, $request);

        if (!$payload['to'] && !$payload['cc'] && !$payload['bcc']) {
            throw ApiException::validation('Penerima wajib diisi', ['to' => 'Wajib diisi']);
        }

        $draftId = $request->int('draft_id');
        $result = (new Mailer($this->db))->send($user, $payload);

        if ($draftId > 0) {
            $this->deleteDraft((int) $user['id'], $draftId);
        }
        $this->consumeUploads((int) $user['id'], $payload['upload_ids']);

        Response::json([
            'ok'        => true,
            'message_id' => $result['message_id'],
            'delivered' => $result['delivered'],
            'queued'    => $result['queued'],
        ], 201);
    }

    /** Create or update a draft; the client calls this while the user types. */
    public function saveDraft(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $userId = (int) $user['id'];
        $payload = $this->composePayload($user, $request);
        $draftId = $request->int('draft_id');

        $subject = (string) $payload['subject'];
        $html    = $payload['html'];
        $text    = $payload['text'] !== '' ? $payload['text'] : Util::htmlToText($html);
        $threadKey = $request->string('thread_key') ?: Util::threadKey(
            $subject,
            array_merge([$user['email']], $payload['to'])
        );

        if ($draftId > 0 && $this->db->one('SELECT id FROM messages WHERE id = ? AND user_id = ? AND is_draft', [$draftId, $userId])) {
            $this->db->run(
                'UPDATE messages SET to_json = ?, cc_json = ?, bcc_json = ?, subject = ?, snippet = ?,
                        body_text = ?, body_html = ?, internal_date = NOW(), size_bytes = ?
                 WHERE id = ? AND user_id = ?',
                [
                    json_encode($payload['to']), json_encode($payload['cc']), json_encode($payload['bcc']),
                    mb_substr($subject, 0, 900), Util::snippet($text, $html),
                    $text, $html, strlen($text) + strlen($html), $draftId, $userId,
                ]
            );
        } else {
            $draftId = (int) $this->db->insert(
                'INSERT INTO messages
                    (user_id, message_id, thread_key, in_reply_to, from_email, from_name, to_json, cc_json,
                     bcc_json, subject, snippet, body_text, body_html, folder, is_read, is_draft, size_bytes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, \'drafts\', TRUE, TRUE, ?)
                 RETURNING id',
                [
                    $userId, Util::messageId(), $threadKey, $request->string('in_reply_to') ?: null,
                    $user['email'], $user['full_name'],
                    json_encode($payload['to']), json_encode($payload['cc']), json_encode($payload['bcc']),
                    mb_substr($subject, 0, 900), Util::snippet($text, $html), $text, $html,
                    strlen($text) + strlen($html),
                ]
            );
            $this->db->run(
                "INSERT INTO message_labels (message_id, label_id)
                 SELECT ?, id FROM labels WHERE user_id = ? AND slug = 'drafts' ON CONFLICT DO NOTHING",
                [$draftId, $userId]
            );
        }

        // move uploads onto the draft so they survive a page reload
        if ($payload['upload_ids']) {
            $placeholders = implode(',', array_fill(0, count($payload['upload_ids']), '?'));
            $this->db->run(
                "UPDATE attachments SET message_id = ?, token = NULL
                 WHERE user_id = ? AND token IS NOT NULL AND id IN ({$placeholders})",
                [$draftId, $userId, ...$payload['upload_ids']]
            );
            $this->db->run('UPDATE messages SET has_attachment = TRUE WHERE id = ?', [$draftId]);
        }

        $draft = $this->ownedMessage($userId, $draftId);
        $attachments = Serializer::attachmentsByMessage($this->db, [$draftId]);

        Response::ok(['draft' => Serializer::message($draft, $attachments[$draftId] ?? [])]);
    }

    public function destroyDraft(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $this->deleteDraft((int) $user['id'], (int) $request->params['id']);
        Response::ok(['ok' => true]);
    }

    /** Everything Compose needs to answer a message. */
    public function replyContext(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $message = $this->ownedMessage((int) $user['id'], (int) $request->params['id']);
        $mode = strtolower((string) $request->query('mode', 'reply'));

        $to = $message['reply_to'] !== '' ? [$message['reply_to']] : [$message['from_email']];
        $cc = [];

        if ($mode === 'reply_all') {
            $others = array_merge(
                Util::emailsFromJson($message['to_json']),
                Util::emailsFromJson($message['cc_json'])
            );
            $cc = array_values(array_diff(array_unique($others), [strtolower($user['email'])], $to));
        }
        if ($mode === 'forward') {
            $to = [];
        }

        $prefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';
        $subject = $message['subject'];
        if (stripos($subject, rtrim($prefix, ' ')) !== 0) {
            $subject = $prefix . $subject;
        }

        $quoted = sprintf(
            '<br><br><div class="imel-quote" style="border-left:2px solid #dadce0;padding-left:12px;color:#5f6368">'
            . '<div>Pada %s, %s &lt;%s&gt; menulis:</div>%s</div>',
            date('d M Y H:i', strtotime((string) $message['internal_date']) ?: time()),
            htmlspecialchars(Util::displayName($message['from_email'], (string) $message['from_name']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($message['from_email'], ENT_QUOTES, 'UTF-8'),
            $message['body_html'] !== '' ? $message['body_html'] : nl2br(htmlspecialchars($message['body_text'], ENT_QUOTES, 'UTF-8'))
        );

        Response::ok([
            'compose' => [
                'to'          => $to,
                'cc'          => $cc,
                'subject'     => $subject,
                'body_html'   => $quoted,
                'thread_key'  => $message['thread_key'],
                'in_reply_to' => $message['message_id'],
                'references'  => array_values(array_filter(array_merge(
                    explode(' ', (string) $message['reference_ids']),
                    [(string) $message['message_id']]
                ))),
                'mode'        => $mode,
            ],
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function composePayload(array $user, Request $request): array
    {
        $html = (string) $request->input('body_html', $request->input('html', ''));
        $text = (string) $request->input('body_text', $request->input('text', ''));

        $uploadIds = array_values(array_filter(array_map('intval', $request->arrayInput('attachment_ids'))));
        $attachments = $this->attachmentsForCompose((int) $user['id'], $uploadIds);

        // files posted together with the message instead of pre-uploaded
        foreach ($request->files('attachments') as $file) {
            $attachments[] = [
                'filename'     => (string) $file['name'],
                'content_type' => (string) $file['type'],
                'content'      => (string) file_get_contents($file['tmp_name']),
            ];
        }

        return [
            'to'          => $this->addressList($request, 'to'),
            'cc'          => $this->addressList($request, 'cc'),
            'bcc'         => $this->addressList($request, 'bcc'),
            'subject'     => (string) $request->input('subject', ''),
            'html'        => $html,
            'text'        => $text,
            'in_reply_to' => $request->string('in_reply_to'),
            'references'  => array_map('strval', $request->arrayInput('references')),
            'thread_key'  => $request->string('thread_key'),
            'attachments' => $attachments,
            'upload_ids'  => $uploadIds,
        ];
    }

    private function addressList(Request $request, string $field): array
    {
        $value = $request->input($field, []);
        if (is_string($value)) {
            return Util::parseAddressList($value);
        }
        return Util::parseAddressList(implode(',', array_map('strval', (array) $value)));
    }

    private function attachmentsForCompose(int $userId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all(
            "SELECT * FROM attachments WHERE user_id = ? AND id IN ({$placeholders})",
            [$userId, ...$ids]
        );

        $out = [];
        foreach ($rows as $row) {
            if (!Storage::isInside($row['storage_path'])) {
                continue;
            }
            $out[] = [
                'filename'     => $row['filename'],
                'content_type' => $row['content_type'],
                'path'         => $row['storage_path'],
            ];
        }
        return $out;
    }

    /** Uploads referenced by a sent message are no longer needed as staging rows. */
    private function consumeUploads(int $userId, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all(
            "SELECT id, storage_path FROM attachments
             WHERE user_id = ? AND token IS NOT NULL AND message_id IS NULL AND id IN ({$placeholders})",
            [$userId, ...$ids]
        );
        foreach ($rows as $row) {
            Storage::delete($row['storage_path']);
            $this->db->run('DELETE FROM attachments WHERE id = ?', [$row['id']]);
        }
    }

    /** @return int[] message ids from explicit ids plus whole conversations */
    private function resolveIds(int $userId, Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', $request->arrayInput('ids'))));
        $threadKeys = array_values(array_filter(array_map('strval', $request->arrayInput('thread_keys'))));

        if ($threadKeys) {
            $placeholders = implode(',', array_fill(0, count($threadKeys), '?'));
            $rows = $this->db->all(
                "SELECT id FROM messages WHERE user_id = ? AND thread_key IN ({$placeholders})",
                [$userId, ...$threadKeys]
            );
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        if (!$ids) {
            return [];
        }

        // keep only ids this user actually owns
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $owned = $this->db->all(
            "SELECT id FROM messages WHERE user_id = ? AND id IN ({$placeholders})",
            [$userId, ...array_unique($ids)]
        );

        return array_map(static fn($r) => (int) $r['id'], $owned);
    }

    private function moveFolder(int $userId, array $ids, string $folder): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $this->db->run(
            "UPDATE messages SET folder = ?, is_draft = CASE WHEN ? = 'drafts' THEN is_draft ELSE FALSE END
             WHERE user_id = ? AND id IN ({$placeholders})",
            [$folder, $folder, $userId, ...$ids]
        );

        // keep the mirrored folder labels in sync
        $this->db->run(
            "DELETE FROM message_labels ml
             USING labels l
             WHERE ml.label_id = l.id AND l.user_id = ?
               AND l.slug IN ('inbox','trash','spam','archive')
               AND ml.message_id IN ({$placeholders})",
            [$userId, ...$ids]
        );

        if (in_array($folder, ['inbox', 'trash', 'spam'], true)) {
            foreach ($ids as $id) {
                $this->db->run(
                    'INSERT INTO message_labels (message_id, label_id)
                     SELECT ?, id FROM labels WHERE user_id = ? AND slug = ?
                     ON CONFLICT DO NOTHING',
                    [$id, $userId, $folder]
                );
            }
        }
    }

    private function deleteForever(int $userId, array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $attachments = $this->db->all(
            "SELECT storage_path FROM attachments WHERE user_id = ? AND message_id IN ({$placeholders})",
            [$userId, ...$ids]
        );
        $messages = $this->db->all(
            "SELECT raw_path, size_bytes FROM messages WHERE user_id = ? AND id IN ({$placeholders})",
            [$userId, ...$ids]
        );

        foreach ($attachments as $row) {
            Storage::delete($row['storage_path']);
        }
        $freed = 0;
        foreach ($messages as $row) {
            Storage::delete((string) $row['raw_path']);
            $freed += (int) $row['size_bytes'];
        }

        $this->db->run("DELETE FROM messages WHERE user_id = ? AND id IN ({$placeholders})", [$userId, ...$ids]);
        $this->db->run('UPDATE users SET used_bytes = GREATEST(0, used_bytes - ?) WHERE id = ?', [$freed, $userId]);
    }

    private function toggleLabel(int $userId, array $ids, Request $request, bool $add): void
    {
        $labelId = $request->int('label_id');
        $slug = $request->string('label_slug');

        $label = $labelId > 0
            ? $this->db->one('SELECT id FROM labels WHERE id = ? AND user_id = ?', [$labelId, $userId])
            : $this->db->one('SELECT id FROM labels WHERE slug = ? AND user_id = ?', [$slug, $userId]);

        if (!$label) {
            throw ApiException::notFound('Label tidak ditemukan');
        }

        if ($add) {
            foreach ($ids as $id) {
                $this->db->run(
                    'INSERT INTO message_labels (message_id, label_id) VALUES (?,?) ON CONFLICT DO NOTHING',
                    [$id, $label['id']]
                );
            }
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->run(
            "DELETE FROM message_labels WHERE label_id = ? AND message_id IN ({$placeholders})",
            [$label['id'], ...$ids]
        );
    }

    private function deleteDraft(int $userId, int $draftId): void
    {
        $draft = $this->db->one(
            'SELECT id FROM messages WHERE id = ? AND user_id = ? AND is_draft',
            [$draftId, $userId]
        );
        if ($draft) {
            $this->deleteForever($userId, [(int) $draft['id']]);
        }
    }

    private function ownedMessage(int $userId, int $id): array
    {
        $message = $this->db->one('SELECT * FROM messages WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$message) {
            throw ApiException::notFound('Pesan tidak ditemukan');
        }
        return $message;
    }
}
