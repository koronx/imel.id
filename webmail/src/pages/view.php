<?php
$user = getCurrentUser();
$emailId = $_GET['id'] ?? 0;

// Get email details
$db = getDB();
$stmt = $db->prepare("
    SELECT * FROM emails 
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$emailId, $user['id']]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$email) {
    echo '<div class="container"><div class="alert alert-error">Email tidak ditemukan</div></div>';
    exit;
}

// Mark as read
if (!$email['is_read']) {
    $stmt = $db->prepare("UPDATE emails SET is_read = true WHERE id = ?");
    $stmt->execute([$emailId]);
}

// Get attachments
$stmt = $db->prepare("SELECT * FROM attachments WHERE email_id = ?");
$stmt->execute([$emailId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-envelope-open"></i> Detail Email</h1>
            </div>
            <div class="col-sm-6">
                <div class="float-right">
                    <a href="?page=inbox" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                    <a href="?page=compose&reply=<?php echo $emailId; ?>" class="btn btn-primary">
                        <i class="fas fa-reply"></i> Balas
                    </a>
                </div>
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
                            <?php echo htmlspecialchars($email['subject'] ?: '(Tanpa Subjek)'); ?>
                        </h3>
                    </div>
                    
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-2"><i class="fas fa-user"></i> Dari:</dt>
                            <dd class="col-sm-10"><?php echo htmlspecialchars($email['from_email']); ?></dd>
                            
                            <dt class="col-sm-2"><i class="fas fa-envelope"></i> Kepada:</dt>
                            <dd class="col-sm-10"><?php echo htmlspecialchars($email['to_email']); ?></dd>
                            
                            <?php if ($email['cc']): ?>
                                <dt class="col-sm-2"><i class="fas fa-copy"></i> CC:</dt>
                                <dd class="col-sm-10"><?php echo htmlspecialchars($email['cc']); ?></dd>
                            <?php endif; ?>
                            
                            <dt class="col-sm-2"><i class="fas fa-calendar"></i> Tanggal:</dt>
                            <dd class="col-sm-10"><?php echo date('d F Y H:i', strtotime($email['received_at'])); ?></dd>
                        </dl>
                        
                        <hr>
                        
                        <div class="email-content">
                            <?php 
                            // Display HTML body if available, otherwise show plain text
                            if (!empty($email['html_body'])) {
                                echo $email['html_body'];
                            } else {
                                echo nl2br(htmlspecialchars($email['body']));
                            }
                            ?>
                        </div>
                        
                        <?php if (!empty($attachments)): ?>
                            <hr>
                            <div class="attachments">
                                <h5><i class="fas fa-paperclip"></i> Lampiran (<?php echo count($attachments); ?>)</h5>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-file"></i>
                                                <strong><?php echo htmlspecialchars($attachment['filename']); ?></strong>
                                                <small class="text-muted ml-2">
                                                    (<?php echo number_format($attachment['size'] / 1024, 2); ?> KB)
                                                </small>
                                            </div>
                                            <a href="?page=download&id=<?php echo $attachment['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-download"></i> Unduh
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
