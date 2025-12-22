<?php
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $secondary_email = $_POST['secondary_email'] ?? '';
    
    if (empty($email) || empty($password) || empty($full_name) || empty($secondary_email)) {
        $error = 'Semua field harus diisi';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid';
    } elseif (!str_ends_with($email, '@imel.id')) {
        $error = 'Email harus menggunakan domain @imel.id';
    } elseif (!filter_var($secondary_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email recovery tidak valid';
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
            // Get default rate limit from settings
            $stmt = $db->query("SELECT setting_value FROM rate_limit_settings WHERE setting_key = 'default_daily_external_limit'");
            $rateLimitSetting = $stmt->fetch(PDO::FETCH_ASSOC);
            $defaultLimit = (int)($rateLimitSetting['setting_value'] ?? 100);
            
            // Create user with default rate limit
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (email, password, full_name, secondary_email, daily_external_limit) VALUES (?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$email, $hashed_password, $full_name, $secondary_email, $defaultLimit])) {
                // Send welcome email
                $redisHost = getenv('REDIS_HOST') ?: 'redis';
                $redisPort = getenv('REDIS_PORT') ?: 6379;
                
                try {
                    $redis = new \Predis\Client([
                        'scheme' => 'tcp',
                        'host' => $redisHost,
                        'port' => $redisPort,
                    ]);
                    
                    $welcomeBody = "Halo {$full_name},\n\nSelamat datang di imel.id!\n\nAkun Anda telah berhasil dibuat dengan detail berikut:\n\nEmail: {$email}\nPassword: {$password}\n\nSilakan login ke https://imel.id untuk mulai menggunakan layanan email kami.\n\nTerima kasih,\nTim imel.id";
                    
                    // Email ke akun imel.id (internal)
                    $emailToImel = [
                        'from' => 'noreply@imel.id',
                        'to' => [$email],
                        'cc' => [],
                        'subject' => 'Selamat Datang di imel.id',
                        'body' => $welcomeBody,
                        'html_body' => '',
                        'attachments' => [],
                        'received_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Email ke secondary email (external)
                    $emailToSecondary = [
                        'from' => 'noreply@imel.id',
                        'to' => [$secondary_email],
                        'cc' => [],
                        'subject' => 'Akun imel.id Anda Telah Dibuat',
                        'body' => $welcomeBody,
                        'html_body' => '',
                        'attachments' => [],
                        'received_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $redis->rpush('email_queue', json_encode($emailToImel));
                    $redis->rpush('email_queue', json_encode($emailToSecondary));
                } catch (Exception $e) {
                    // Ignore email send error, user already registered
                }
                
                $success = 'Registrasi berhasil! Email selamat datang telah dikirim. Silakan login.';
            } else {
                $error = 'Terjadi kesalahan saat registrasi';
            }
        }
    }
}
?>

<div class="register-box" style="margin: auto;">
    <div class="card card-outline card-primary">
        <div class="card-header text-center">
            <a href="?page=login" class="h1"><i class="fas fa-envelope-open-text"></i> <b>imel</b>.id</a>
        </div>
        <div class="card-body">
            <p class="login-box-msg">Daftar akun baru</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="icon fas fa-ban"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="icon fas fa-check"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group mb-3">
                    <input type="text" name="full_name" class="form-control" placeholder="Nama Lengkap" required>
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-user"></span>
                        </div>
                    </div>
                </div>
                <div class="input-group mb-3">
                    <input type="email" name="email" class="form-control" placeholder="username@imel.id" required>
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-envelope"></span>
                        </div>
                    </div>
                </div>
                <small class="form-text text-muted mb-3">Email harus menggunakan domain @imel.id</small>
                <div class="input-group mb-3">
                    <input type="email" name="secondary_email" class="form-control" placeholder="Email Recovery (Gmail, Outlook, dll)" required>
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-envelope"></span>
                        </div>
                    </div>
                </div>
                <small class="form-text text-muted mb-3">Email recovery untuk reset password</small>
                <div class="input-group mb-3">
                    <input type="password" name="password" class="form-control" placeholder="Password" required minlength="6">
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-lock"></span>
                        </div>
                    </div>
                </div>
                <div class="input-group mb-3">
                    <input type="password" name="confirm_password" class="form-control" placeholder="Konfirmasi Password" required minlength="6">
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-lock"></span>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-block">Daftar</button>
                    </div>
                </div>
            </form>

            <p class="mb-0 mt-3 text-center">
                <a href="?page=login" class="text-center">Sudah punya akun? Login di sini</a>
            </p>
        </div>
    </div>
</div>
