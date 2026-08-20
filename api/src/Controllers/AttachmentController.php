<?php

namespace Imel\Api\Controllers;

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Api\Serializer;
use Imel\Shared\Db;
use Imel\Shared\Storage;
use Imel\Shared\Util;

final class AttachmentController
{
    private const MAX_BYTES = 26214400;   // 25 MB per file, same as Gmail

    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf', 'text/plain',
    ];

    public function __construct(private Db $db)
    {
    }

    /** Stage an upload for a compose window; it is linked to a message on send. */
    public function store(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $files = $request->files('file') ?: $request->files('attachments');

        if (!$files) {
            throw ApiException::validation('Tidak ada berkas yang diunggah', ['file' => 'Wajib diisi']);
        }

        $saved = [];
        foreach ($files as $file) {
            if ((int) $file['size'] > self::MAX_BYTES) {
                throw ApiException::validation(
                    sprintf('Ukuran %s melebihi batas 25 MB', $file['name']),
                    ['file' => 'Terlalu besar']
                );
            }

            $contents = (string) file_get_contents($file['tmp_name']);
            $path = Storage::saveAttachment((int) $user['id'], (string) $file['name'], $contents);
            $token = Util::randomToken(16);

            $id = (int) $this->db->insert(
                'INSERT INTO attachments (message_id, user_id, filename, content_type, size_bytes, storage_path, token)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?) RETURNING id',
                [
                    $user['id'],
                    mb_substr((string) $file['name'], 0, 250),
                    (string) ($file['type'] ?: 'application/octet-stream'),
                    strlen($contents),
                    $path,
                    $token,
                ]
            );

            $saved[] = Serializer::attachment($this->db->one('SELECT * FROM attachments WHERE id = ?', [$id]));
        }

        Response::json(['attachments' => $saved], 201);
    }

    public function download(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $row = $this->db->one(
            'SELECT * FROM attachments WHERE id = ? AND user_id = ?',
            [(int) $request->params['id'], $user['id']]
        );

        if (!$row || !Storage::isInside($row['storage_path']) || !is_file($row['storage_path'])) {
            throw ApiException::notFound('Lampiran tidak ditemukan');
        }

        $inline = $request->queryBool('inline', false) && in_array($row['content_type'], self::INLINE_TYPES, true);
        Response::file($row['storage_path'], $row['filename'], $row['content_type'], $inline);
    }

    public function destroy(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $row = $this->db->one(
            'SELECT * FROM attachments WHERE id = ? AND user_id = ?',
            [(int) $request->params['id'], $user['id']]
        );

        if (!$row) {
            throw ApiException::notFound('Lampiran tidak ditemukan');
        }
        if ($row['message_id'] !== null) {
            throw new ApiException('Lampiran pesan yang sudah terkirim tidak dapat dihapus', 403);
        }

        Storage::delete($row['storage_path']);
        $this->db->run('DELETE FROM attachments WHERE id = ?', [$row['id']]);

        Response::ok(['ok' => true]);
    }
}
