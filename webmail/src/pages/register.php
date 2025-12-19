<?php
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($email) || empty($password) || empty($full_name)) {
        $error = 'Semua field harus diisi';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } elseif (!str_ends_with($email, '@imel.id')) {
        $error = 'Email harus menggunakan domain @imel.id';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter';
    } elseif ($password !== $confirm_password) {
        $error = 'Password tidak cocok';
    } else {
        $db = getDB();
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->fetch()) {
            $error = 'Email sudah terdaftar';
        } else {
            // Create user
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (email, password, full_name) VALUES (?, ?, ?)");
            
            if ($stmt->execute([$email, $hashed_password, $full_name])) {
                $success = 'Registrasi berhasil! Silakan login.';
            } else {
                $error = 'Terjadi kesalahan saat registrasi';
            }
        }
    }
}
?>

<div class="register-container">
    <h2 style="text-align: center; margin-bottom: 2rem;">Daftar imel.id</h2>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Nama Lengkap</label>
            <input type="text" name="full_name" required>
        </div>
        
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required placeholder="username@imel.id">
            <small style="color: #7f8c8d;">Harus menggunakan domain @imel.id</small>
        </div>
        
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required minlength="6">
        </div>
        
        <div class="form-group">
            <label>Konfirmasi Password</label>
            <input type="password" name="confirm_password" required minlength="6">
        </div>
        
        <button type="submit" class="btn" style="width: 100%;">Daftar</button>
    </form>
    
    <p style="text-align: center; margin-top: 1rem;">
        Sudah punya akun? <a href="?page=login">Login di sini</a>
    </p>
</div>
