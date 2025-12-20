<?php
$user = getCurrentUser();
$db = getDB();

// Get secondary email
$stmt = $db->prepare("SELECT secondary_email FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
$secondaryEmail = $userDetails['secondary_email'] ?? '';

// Check if there's a pending OTP verification
$stmt = $db->prepare("SELECT email, otp, expires_at FROM secondary_email_otp WHERE user_id = ? AND is_verified = FALSE ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$pendingOtp = $stmt->fetch(PDO::FETCH_ASSOC);

// Step 1: Request OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_otp'])) {
    $newSecondaryEmail = trim($_POST['secondary_email'] ?? '');
    
    if (!empty($newSecondaryEmail) && !filter_var($newSecondaryEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Format email tidak valid';
    } elseif ($newSecondaryEmail === $user['email']) {
        $_SESSION['error'] = 'Email recovery tidak boleh sama dengan email utama';
    } else {
        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Delete old OTP for this user
        $stmt = $db->prepare("DELETE FROM secondary_email_otp WHERE user_id = ? AND is_verified = FALSE");
        $stmt->execute([$user['id']]);
        
        // Save OTP
        $stmt = $db->prepare("INSERT INTO secondary_email_otp (user_id, email, otp, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $newSecondaryEmail, $otp, $expiresAt]);
        
        // Send OTP via email
        $emailData = [
            'from' => 'noreply@imel.id',
            'to' => $newSecondaryEmail,
            'subject' => 'Kode Verifikasi Email - imel.id',
            'body' => "Halo {$user['full_name']},\n\nKode verifikasi Anda adalah: {$otp}\n\nKode ini berlaku selama 10 menit.\n\nJika Anda tidak meminta kode ini, abaikan email ini.\n\nSalam,\nTim imel.id",
            'html_body' => "
                <h2>Kode Verifikasi Email</h2>
                <p>Halo <strong>{$user['full_name']}</strong>,</p>
                <p>Kode verifikasi Anda adalah:</p>
                <h1 style='background-color: #f4f4f4; padding: 20px; text-align: center; font-size: 36px; letter-spacing: 5px; color: #dc143c;'>{$otp}</h1>
                <p><small>Kode ini berlaku selama 10 menit.</small></p>
                <p>Jika Anda tidak meminta kode ini, abaikan email ini.</p>
                <p>Salam,<br>Tim imel.id</p>
            "
        ];
        
        // Queue email
        try {
            $redis = new \Predis\Client([
                'scheme' => 'tcp',
                'host' => getenv('REDIS_HOST') ?: 'redis',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            ]);
            $redis->rpush('email_queue', json_encode($emailData));
            
            $_SESSION['success'] = 'Kode OTP telah dikirim ke ' . $newSecondaryEmail;
            header('Location: ?page=settings');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Gagal mengirim OTP. Silakan coba lagi.';
        }
    }
}

// Step 2: Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otpInput = trim($_POST['otp'] ?? '');
    
    if (empty($otpInput)) {
        $_SESSION['error'] = 'Kode OTP harus diisi';
    } else {
        // Get pending OTP
        $stmt = $db->prepare("SELECT id, email, otp, expires_at FROM secondary_email_otp WHERE user_id = ? AND is_verified = FALSE ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otpRecord) {
            $_SESSION['error'] = 'Tidak ada OTP yang aktif. Silakan request OTP baru.';
        } elseif (strtotime($otpRecord['expires_at']) < time()) {
            $_SESSION['error'] = 'Kode OTP sudah expired. Silakan request OTP baru.';
        } elseif ($otpRecord['otp'] !== $otpInput) {
            $_SESSION['error'] = 'Kode OTP salah. Silakan coba lagi.';
        } else {
            // OTP valid - update secondary email
            $stmt = $db->prepare("UPDATE users SET secondary_email = ? WHERE id = ?");
            $stmt->execute([$otpRecord['email'], $user['id']]);
            
            // Mark OTP as verified
            $stmt = $db->prepare("UPDATE secondary_email_otp SET is_verified = TRUE WHERE id = ?");
            $stmt->execute([$otpRecord['id']]);
            
            $_SESSION['success'] = 'Email recovery berhasil diverifikasi dan diperbarui!';
            header('Location: ?page=settings');
            exit;
        }
    }
}

// Cancel OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_otp'])) {
    $stmt = $db->prepare("DELETE FROM secondary_email_otp WHERE user_id = ? AND is_verified = FALSE");
    $stmt->execute([$user['id']]);
    $_SESSION['success'] = 'Verifikasi OTP dibatalkan';
    header('Location: ?page=settings');
    exit;
}
?>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-cog"></i> Pengaturan Akun</h1>
            </div>
        </div>
    </div>
</section>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?php 
                echo htmlspecialchars($_SESSION['success']); 
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?php 
                echo htmlspecialchars($_SESSION['error']); 
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user"></i> Informasi Akun</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4"><i class="fas fa-envelope"></i> Email:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($user['email']); ?></dd>
                            
                            <dt class="col-sm-4"><i class="fas fa-id-card"></i> Nama Lengkap:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($user['full_name']); ?></dd>
                        </dl>
                    </div>
                </div>
                
                <!-- Secondary Email Card -->
                <div class="card card-info card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-shield-alt"></i> Email Recovery</h3>
                    </div>
                    
                    <?php if ($pendingOtp && strtotime($pendingOtp['expires_at']) > time()): ?>
                        <!-- OTP Verification Form -->
                        <form method="POST">
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-paper-plane"></i> Kode OTP telah dikirim ke <strong><?php echo htmlspecialchars($pendingOtp['email']); ?></strong>
                                </div>
                                <div class="form-group">
                                    <label>Masukkan Kode OTP (6 digit)</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                        </div>
                                        <input type="text" name="otp" class="form-control" 
                                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" 
                                               required autocomplete="off">
                                    </div>
                                    <small class="form-text text-muted">
                                        Kode expire pada <?php echo date('H:i:s', strtotime($pendingOtp['expires_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="submit" name="verify_otp" class="btn btn-success">
                                    <i class="fas fa-check"></i> Verifikasi OTP
                                </button>
                                <button type="submit" name="cancel_otp" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Batal
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Request OTP Form -->
                        <form method="POST">
                            <div class="card-body">
                                <p class="text-muted">
                                    <i class="fas fa-info-circle"></i> Email recovery digunakan untuk reset password. Verifikasi OTP diperlukan.
                                </p>
                                <?php if ($secondaryEmail): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> Email recovery aktif: <strong><?php echo htmlspecialchars($secondaryEmail); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label>Email Recovery Baru</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        </div>
                                        <input type="email" name="secondary_email" class="form-control" 
                                               placeholder="recovery@example.com" 
                                               value="<?php echo htmlspecialchars($secondaryEmail); ?>"
                                               required>
                                    </div>
                                    <small class="form-text text-muted">Kode OTP akan dikirim ke email ini untuk verifikasi</small>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="submit" name="request_otp" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Kirim Kode OTP
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card card-warning card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-lock"></i> Ganti Password</h3>
                    </div>
                    
                    <form method="POST" action="?page=change-password">
                        <div class="card-body">
                            <div class="form-group">
                                <label>Password Saat Ini</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    </div>
                                    <input type="password" name="current_password" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Password Baru</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    </div>
                                    <input type="password" name="new_password" class="form-control" required minlength="6">
                                </div>
                                <small class="form-text text-muted">Minimal 6 karakter</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Konfirmasi Password Baru</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-check"></i></span>
                                    </div>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save"></i> Simpan Password
                            </button>
                            <a href="?page=inbox" class="btn btn-default">
                                <i class="fas fa-times"></i> Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
