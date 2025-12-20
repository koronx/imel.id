<?php
$user = getCurrentUser();
$db = getDB();

// Get secondary email
$stmt = $db->prepare("SELECT secondary_email FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
$secondaryEmail = $userDetails['secondary_email'] ?? '';

// Handle secondary email update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_secondary_email'])) {
    $newSecondaryEmail = trim($_POST['secondary_email'] ?? '');
    
    if (!empty($newSecondaryEmail) && !filter_var($newSecondaryEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Format email tidak valid';
    } elseif ($newSecondaryEmail === $user['email']) {
        $_SESSION['error'] = 'Email recovery tidak boleh sama dengan email utama';
    } else {
        $stmt = $db->prepare("UPDATE users SET secondary_email = ? WHERE id = ?");
        $stmt->execute([empty($newSecondaryEmail) ? null : $newSecondaryEmail, $user['id']]);
        $_SESSION['success'] = 'Email recovery berhasil diperbarui';
        header('Location: ?page=settings');
        exit;
    }
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
                    
                    <form method="POST">
                        <div class="card-body">
                            <p class="text-muted">
                                <i class="fas fa-info-circle"></i> Email recovery digunakan untuk reset password dan verifikasi 2FA.
                            </p>
                            <div class="form-group">
                                <label>Email Recovery</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    </div>
                                    <input type="email" name="secondary_email" class="form-control" 
                                           placeholder="recovery@example.com" 
                                           value="<?php echo htmlspecialchars($secondaryEmail); ?>">
                                </div>
                                <small class="form-text text-muted">Kosongkan jika tidak ingin menggunakan email recovery</small>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button type="submit" name="update_secondary_email" class="btn btn-info">
                                <i class="fas fa-save"></i> Simpan Email Recovery
                            </button>
                        </div>
                    </form>
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
