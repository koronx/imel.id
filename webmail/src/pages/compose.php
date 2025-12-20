<?php
$user = getCurrentUser();

// Check if this is a reply
$replyToId = $_GET['reply'] ?? 0;
$replyEmail = null;

if ($replyToId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM emails WHERE id = ? AND user_id = ?");
    $stmt->execute([$replyToId, $user['id']]);
    $replyEmail = $stmt->fetch(PDO::FETCH_ASSOC);
}

$to = $replyEmail ? $replyEmail['from_email'] : '';
$subject = $replyEmail ? 'Re: ' . $replyEmail['subject'] : '';

// Prepare reply body with HTML or plain text
if ($replyEmail) {
    $originalBody = !empty($replyEmail['html_body']) ? $replyEmail['html_body'] : nl2br(htmlspecialchars($replyEmail['body']));
    $body = '<p><br></p><p><br></p><hr><p><strong>Pesan asli:</strong></p><p><strong>Dari:</strong> ' . htmlspecialchars($replyEmail['from_email']) . '<br><strong>Tanggal:</strong> ' . date('d F Y H:i', strtotime($replyEmail['received_at'])) . '<br><strong>Subjek:</strong> ' . htmlspecialchars($replyEmail['subject']) . '</p>' . $originalBody;
} else {
    $body = '';
}
?>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-envelope"></i> <?php echo $replyEmail ? 'Balas Email' : 'Tulis Email Baru'; ?></h1>
            </div>
        </div>
    </div>
</section>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-edit"></i> Compose Email
                        </h3>
                    </div>
                    
                    <form method="POST" action="?page=send" enctype="multipart/form-data">
                        <div class="card-body">
                            <div class="form-group">
                                <label>Kepada (pisahkan dengan koma untuk multiple email)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    </div>
                                    <input type="text" name="to" class="form-control" required placeholder="penerima1@domain.com, penerima2@domain.com" value="<?php echo htmlspecialchars($to); ?>">
                                </div>
                                <small class="form-text text-muted">Contoh: user1@imel.id, user2@gmail.com, user3@yahoo.com</small>
                            </div>
                            
                            <div class="form-group">
                                <label>CC - Carbon Copy (opsional, pisahkan dengan koma)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-users"></i></span>
                                    </div>
                                    <input type="text" name="cc" class="form-control" placeholder="email1@domain.com, email2@domain.com">
                                </div>
                                <small class="form-text text-muted">CC akan menerima salinan email dan semua penerima bisa melihat alamat email mereka</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Subjek</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                    </div>
                                    <input type="text" name="subject" class="form-control" required value="<?php echo htmlspecialchars($subject); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Pesan</label>
                                <div id="editor-container" style="height: 300px;"><?php echo $body; ?></div>
                                <textarea name="body" id="body-field" style="display:none;" required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Lampiran</label>
                                <div class="custom-file">
                                    <input type="file" name="attachments[]" class="custom-file-input" id="attachments" multiple>
                                    <label class="custom-file-label" for="attachments">Pilih file...</label>
                                </div>
                                <small class="form-text text-muted">Maksimal 10MB per file</small>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary" id="submit-btn">
                                <i class="fas fa-paper-plane"></i> Kirim
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
