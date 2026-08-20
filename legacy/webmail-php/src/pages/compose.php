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

<div class="container">
    <div class="compose-form">
        <h2><?php echo $replyEmail ? 'Balas Email' : 'Tulis Email Baru'; ?></h2>
        
        <form method="POST" action="?page=send" enctype="multipart/form-data">
            <div class="form-group">
                <label>Kepada</label>
                <input type="email" name="to" required placeholder="penerima@domain.com" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            
            <div class="form-group">
                <label>CC (opsional)</label>
                <input type="text" name="cc" placeholder="email1@domain.com, email2@domain.com">
            </div>
            
            <div class="form-group">
                <label>Subjek</label>
                <input type="text" name="subject" required value="<?php echo htmlspecialchars($subject); ?>">
            </div>
            
            <div class="form-group">
                <label>Pesan</label>
                <div id="editor-container"><?php echo $body; ?></div>
                <textarea name="body" id="body-field" style="display:none;" required></textarea>
            </div>
            
            <div class="form-group">
                <label>Lampiran</label>
                <input type="file" name="attachments[]" multiple>
                <small style="color: #7f8c8d;">Maksimal 50MB per file</small>
            </div>
            
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-success" id="submit-btn">📤 Kirim</button>
                <a href="?page=inbox" class="btn">Batal</a>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    // Wait for DOM to be ready
    if (typeof Quill === 'undefined') {
        console.error('Quill library not loaded');
        return;
    }
    
    // Initialize Quill editor
    var quill = new Quill('#editor-container', {
        theme: 'snow',
        placeholder: 'Tulis pesan email di sini...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'align': [] }],
                ['link'],
                ['clean']
            ]
        }
    });
    
    // On form submit, copy Quill content to hidden textarea
    var submitBtn = document.getElementById('submit-btn');
    if (submitBtn) {
        submitBtn.addEventListener('click', function(e) {
            var bodyField = document.getElementById('body-field');
            if (bodyField && quill) {
                bodyField.value = quill.root.innerHTML;
            }
        });
    }
})();
</script>
