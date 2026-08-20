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

<div class="container">
    <div style="margin-bottom: 1rem; display: flex; gap: 1rem;">
        <a href="?page=inbox" class="btn">← Kembali ke Inbox</a>
        <a href="?page=compose&reply=<?php echo $emailId; ?>" class="btn btn-success">↩️ Balas</a>
    </div>
    
    <div class="email-detail">
        <div class="email-detail-header">
            <h2><?php echo htmlspecialchars($email['subject'] ?: '(Tanpa Subjek)'); ?></h2>
            
            <div style="margin-top: 1rem; color: #7f8c8d;">
                <div><strong>Dari:</strong> <?php echo htmlspecialchars($email['from_email']); ?></div>
                <div><strong>Kepada:</strong> <?php echo htmlspecialchars($email['to_email']); ?></div>
                <?php if ($email['cc']): ?>
                    <div><strong>CC:</strong> <?php echo htmlspecialchars($email['cc']); ?></div>
                <?php endif; ?>
                <div><strong>Tanggal:</strong> <?php echo date('d F Y H:i', strtotime($email['received_at'])); ?></div>
            </div>
        </div>
        
        <div class="email-detail-body">
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
            <div class="attachment-list">
                <h3>Lampiran (<?php echo count($attachments); ?>)</h3>
                <?php foreach ($attachments as $attachment): ?>
                    <div class="attachment-item">
                        <div>
                            <strong>📎 <?php echo htmlspecialchars($attachment['filename']); ?></strong>
                            <small style="color: #7f8c8d; margin-left: 0.5rem;">
                                (<?php echo number_format($attachment['size'] / 1024, 2); ?> KB)
                            </small>
                        </div>
                        <a href="?page=download&id=<?php echo $attachment['id']; ?>" class="btn" style="padding: 0.5rem 1rem;">
                            Unduh
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
