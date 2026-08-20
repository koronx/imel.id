<?php
$user = getCurrentUser();
?>

<div class="container">
    <div style="max-width: 600px; margin: 2rem auto;">
        <h2>⚙️ Pengaturan Akun</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php 
                echo htmlspecialchars($_SESSION['success']); 
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php 
                echo htmlspecialchars($_SESSION['error']); 
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <div style="background: white; padding: 2rem; border-radius: 8px; margin-top: 1rem;">
            <h3>Informasi Akun</h3>
            <div style="margin-top: 1rem;">
                <div style="margin-bottom: 1rem;">
                    <strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?>
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Nama Lengkap:</strong> <?php echo htmlspecialchars($user['full_name']); ?>
                </div>
            </div>
        </div>
        
        <div style="background: white; padding: 2rem; border-radius: 8px; margin-top: 1rem;">
            <h3>Ganti Password</h3>
            
            <form method="POST" action="?page=change-password" style="margin-top: 1rem;">
                <div class="form-group">
                    <label>Password Saat Ini</label>
                    <input type="password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label>Password Baru</label>
                    <input type="password" name="new_password" required minlength="6">
                    <small style="color: #7f8c8d;">Minimal 6 karakter</small>
                </div>
                
                <div class="form-group">
                    <label>Konfirmasi Password Baru</label>
                    <input type="password" name="confirm_password" required minlength="6">
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-success">💾 Simpan Password</button>
                    <a href="?page=inbox" class="btn">Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>
