<?php
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?page=inbox');
    exit;
}

$emailIds = $_POST['email_ids'] ?? [];
$folder = $_POST['folder'] ?? 'inbox';

if (empty($emailIds)) {
    $_SESSION['error'] = 'Tidak ada email yang dipilih';
    header("Location: ?page=inbox&folder=$folder");
    exit;
}

// Validate email IDs are integers
$emailIds = array_filter($emailIds, function($id) {
    return is_numeric($id) && $id > 0;
});

if (empty($emailIds)) {
    $_SESSION['error'] = 'ID email tidak valid';
    header("Location: ?page=inbox&folder=$folder");
    exit;
}

$db = getDB();

// Move to trash or delete permanently
if ($folder === 'trash') {
    // Delete permanently - HANYA email milik user yang login
    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
    $stmt = $db->prepare("DELETE FROM emails WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute(array_merge($emailIds, [$user['id']]));
    
    $deletedCount = $stmt->rowCount();
    
    if ($deletedCount > 0) {
        $_SESSION['success'] = $deletedCount . ' email berhasil dihapus permanen';
    } else {
        $_SESSION['error'] = 'Tidak ada email yang berhasil dihapus (bukan milik Anda)';
    }
} else {
    // Move to trash - HANYA email milik user yang login
    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
    $stmt = $db->prepare("UPDATE emails SET folder = 'trash' WHERE id IN ($placeholders) AND user_id = ?");
    $stmt->execute(array_merge($emailIds, [$user['id']]));
    
    $movedCount = $stmt->rowCount();
    
    if ($movedCount > 0) {
        $_SESSION['success'] = $movedCount . ' email dipindahkan ke sampah';
    } else {
        $_SESSION['error'] = 'Tidak ada email yang berhasil dipindahkan (bukan milik Anda)';
    }
}

header("Location: ?page=inbox&folder=$folder");
exit;
