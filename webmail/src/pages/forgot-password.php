<?php
$error = '';
$success = '';
$step = $_GET['step'] ?? 'request'; // request, sent, reset

// Step 1: Request password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Email harus diisi';
    } else {
        $db = getDB();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Check rate limit - max 10 attempts per hour per email
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM forgot_password_attempts 
            WHERE email = ? AND attempted_at > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$email]);
        $attempts = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($attempts >= 10) {
            $error = 'Terlalu banyak percobaan. Silakan coba lagi dalam 1 jam.';
        } else {
            // Log attempt
            $stmt = $db->prepare("INSERT INTO forgot_password_attempts (email, ip_address) VALUES (?, ?)");
            $stmt->execute([$email, $ipAddress]);
            
            // Check if user exists
            $stmt = $db->prepare("SELECT id, email, secondary_email FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmt = $db->prepare("
                    INSERT INTO password_reset_tokens (user_id, token, expires_at) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user['id'], $token, $expiresAt]);
                
                // Send reset email to secondary email if available, otherwise primary
                $targetEmail = !empty($user['secondary_email']) ? $user['secondary_email'] : $user['email'];
                $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "?page=forgot-password&step=reset&token=" . $token;
                
                // Queue email via mailserver
                $emailData = [
                    'from' => 'noreply@imel.id',
                    'to' => $targetEmail,
                    'subject' => 'Reset Password - imel.id',
                    'body' => "Halo,\n\nAnda menerima email ini karena ada permintaan reset password untuk akun: {$user['email']}\n\nKlik link berikut untuk reset password:\n{$resetLink}\n\nLink ini berlaku selama 1 jam.\n\nJika Anda tidak meminta reset password, abaikan email ini.\n\nSalam,\nTim imel.id",
                    'html_body' => "
                        <h2>Reset Password</h2>
                        <p>Halo,</p>
                        <p>Anda menerima email ini karena ada permintaan reset password untuk akun: <strong>{$user['email']}</strong></p>
                        <p><a href='{$resetLink}' style='background-color: #dc143c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a></p>
                        <p>Atau copy link berikut ke browser:</p>
                        <p>{$resetLink}</p>
                        <p><small>Link ini berlaku selama 1 jam.</small></p>
                        <p>Jika Anda tidak meminta reset password, abaikan email ini.</p>
                        <p>Salam,<br>Tim imel.id</p>
                    "
                ];
                
                // Queue email via Redis for external sending
                try {
                    $redis = new \Predis\Client([
                        'scheme' => 'tcp',
                        'host' => getenv('REDIS_HOST') ?: 'redis',
                        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                    ]);
                    
                    $redis->rpush('email_queue', json_encode($emailData));
                } catch (Exception $e) {
                    error_log("[FORGOT-PASSWORD] Failed to queue email: " . $e->getMessage());
                }
            }
            
            // Always show success message (don't reveal if email exists)
            $success = 'Jika email terdaftar, link reset password telah dikirim ke email recovery Anda.';
            $step = 'sent';
        }
    }
}

// Step 3: Reset password with token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'reset') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Password harus diisi';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Password tidak cocok';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password minimal 6 karakter';
    } else {
        $db = getDB();
        
        // Verify token
        $stmt = $db->prepare("
            SELECT prt.*, u.email 
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used = FALSE
        ");
        $stmt->execute([$token]);
        $resetToken = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resetToken) {
            $error = 'Token tidak valid atau sudah kadaluarsa';
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $resetToken['user_id']]);
            
            // Mark token as used
            $stmt = $db->prepare("UPDATE password_reset_tokens SET used = TRUE WHERE id = ?");
            $stmt->execute([$resetToken['id']]);
            
            $success = 'Password berhasil direset. Silakan login dengan password baru.';
            
            // Redirect to login after 3 seconds
            header("Refresh: 3; url=?page=login");
        }
    }
}
?>

<div class="login-box" style="margin: auto;">
    <div class="card card-outline card-primary" style="border-top-color: #dc143c;">
        <div class="card-header text-center">
            <a href="?page=login" class="h1">
                <img src="/assets/logo.png" alt="imel.id" style="width: 60px; height: 60px; vertical-align: middle;">
                <b>imel</b>.id
            </a>
        </div>
        <div class="card-body">
            <?php if ($step === 'request'): ?>
                <p class="login-box-msg">Masukkan email Anda untuk reset password</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                        <i class="icon fas fa-ban"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="input-group mb-3">
                        <input type="email" name="email" class="form-control" placeholder="Email" required>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-envelope"></span>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-block">Kirim Link Reset</button>
                        </div>
                    </div>
                </form>

                <p class="mt-3 mb-1 text-center">
                    <a href="?page=login">Kembali ke Login</a>
                </p>
            
            <?php elseif ($step === 'sent'): ?>
                <div class="alert alert-success">
                    <i class="icon fas fa-check"></i> <?php echo htmlspecialchars($success); ?>
                </div>
                <p class="text-center">
                    <a href="?page=login" class="btn btn-primary">Kembali ke Login</a>
                </p>
            
            <?php elseif ($step === 'reset'): ?>
                <p class="login-box-msg">Masukkan password baru Anda</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                        <i class="icon fas fa-ban"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="icon fas fa-check"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
                        
                        <div class="input-group mb-3">
                            <input type="password" name="new_password" class="form-control" placeholder="Password Baru" required minlength="6">
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
                                <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <p class="mt-3 mb-1 text-center">
                    <a href="?page=login">Kembali ke Login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
