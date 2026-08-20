<?php
$user = getCurrentUser();
$attachmentId = $_GET['id'] ?? 0;

if (!$attachmentId) {
    die('Attachment ID tidak valid');
}

// Get attachment details
$db = getDB();
$stmt = $db->prepare("
    SELECT a.*, e.user_id 
    FROM attachments a
    JOIN emails e ON a.email_id = e.id
    WHERE a.id = ?
");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if attachment exists and user has access
if (!$attachment || $attachment['user_id'] != $user['id']) {
    die('Attachment tidak ditemukan atau Anda tidak memiliki akses');
}

// Check if file exists
if (!file_exists($attachment['storage_path'])) {
    die('File tidak ditemukan di server');
}

// Set headers for download
header('Content-Type: ' . $attachment['content_type']);
header('Content-Disposition: attachment; filename="' . $attachment['filename'] . '"');
header('Content-Length: ' . $attachment['size']);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Output file
readfile($attachment['storage_path']);
exit;
