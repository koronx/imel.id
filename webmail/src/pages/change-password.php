<?php
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?page=settings');
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Validation
if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    $_SESSION['error'] = 'Semua field harus diisi';
    header('Location: ?page=settings');
    exit;
}

if (strlen($newPassword) < 6) {
    $_SESSION['error'] = 'Password baru minimal 6 karakter';
    header('Location: ?page=settings');
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['error'] = 'Password baru dan konfirmasi password tidak cocok';
    header('Location: ?page=settings');
    exit;
}

// Verify current password
$db = getDB();
$stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userData || !password_verify($currentPassword, $userData['password'])) {
    $_SESSION['error'] = 'Password saat ini salah';
    header('Location: ?page=settings');
    exit;
}

// Update password
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
$stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");

if ($stmt->execute([$hashedPassword, $user['id']])) {
    $_SESSION['success'] = 'Password berhasil diubah!';
} else {
    $_SESSION['error'] = 'Gagal mengubah password';
}

header('Location: ?page=settings');
exit;
