<?php
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?page=inbox');
    exit;
}

$to = $_POST['to'] ?? '';
$cc = $_POST['cc'] ?? '';
$subject = $_POST['subject'] ?? '';
$body = $_POST['body'] ?? '';

if (empty($to) || empty($body)) {
    $_SESSION['error'] = 'Penerima dan pesan harus diisi';
    header('Location: ?page=compose');
    exit;
}

// Handle file uploads
$attachmentPaths = [];
if (!empty($_FILES['attachments']['name'][0])) {
    $uploadDir = '/storage/attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
            $filename = basename($_FILES['attachments']['name'][$key]);
            $targetPath = $uploadDir . uniqid() . '_' . $filename;
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $attachmentPaths[] = [
                    'filename' => $filename,
                    'path' => $targetPath,
                    'size' => $_FILES['attachments']['size'][$key],
                    'type' => $_FILES['attachments']['type'][$key]
                ];
            }
        }
    }
}

// Generate message ID
$messageId = '<' . uniqid() . '@imel.id>';
$from = $user['email'];

// Create plain text version (strip HTML tags)
$plainBody = strip_tags($body);

$emailContent = "From: $from\r\n";
$emailContent .= "To: $to\r\n";
if (!empty($cc)) {
    $emailContent .= "CC: $cc\r\n";
}
$emailContent .= "Subject: $subject\r\n";
$emailContent .= "Message-ID: $messageId\r\n";
$emailContent .= "Date: " . date('r') . "\r\n";
$emailContent .= "Content-Type: text/html; charset=UTF-8\r\n";
$emailContent .= "\r\n";
$emailContent .= $body;

$db = getDB();

// Check if recipient is internal (same domain @imel.id)
$isInternal = str_ends_with($to, '@imel.id');

if ($isInternal) {
    // Internal email - save directly to database
    // Check if recipient exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$to]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recipient) {
        // Save to recipient's inbox
        $stmt = $db->prepare("
            INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, received_at, size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'inbox', NOW(), ?)
        ");
        
        $stmt->execute([
            $messageId,
            $recipient['id'],
            $from,
            $to,
            $cc,
            $subject,
            $plainBody,
            $body,
            strlen($emailContent)
        ]);
        
        $inboxEmailId = $db->lastInsertId();
        
        // Save attachments for recipient
        if (!empty($attachmentPaths)) {
            $stmt = $db->prepare("
                INSERT INTO attachments (email_id, filename, content_type, size, storage_path)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($attachmentPaths as $attachment) {
                $stmt->execute([
                    $inboxEmailId,
                    $attachment['filename'],
                    $attachment['type'],
                    $attachment['size'],
                    $attachment['path']
                ]);
            }
        }
        
        // Save to sender's sent folder
        $stmt = $db->prepare("
            INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, sent_at, size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
        ");
        
        $stmt->execute([
            $messageId . '-sent',
            $user['id'],
            $from,
            $to,
            $cc,
            $subject,
            $plainBody,
            $body,
            strlen($emailContent)
        ]);
        
        $sentEmailId = $db->lastInsertId();
        
        // Save attachments for sender
        if (!empty($attachmentPaths)) {
            $stmt = $db->prepare("
                INSERT INTO attachments (email_id, filename, content_type, size, storage_path)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($attachmentPaths as $attachment) {
                $stmt->execute([
                    $sentEmailId,
                    $attachment['filename'],
                    $attachment['type'],
                    $attachment['size'],
                    $attachment['path']
                ]);
            }
        }
        
        $_SESSION['success'] = 'Email berhasil dikirim!';
    } else {
        $_SESSION['error'] = 'Penerima tidak ditemukan';
    }
} else {
    // External email - send via SMTP
    $mailHost = getenv('MAIL_HOST') ?: 'mailserver';
    $mailPort = getenv('MAIL_PORT') ?: '25';
    
    $socket = @fsockopen($mailHost, $mailPort, $errno, $errstr, 10);
    
    if ($socket) {
        fgets($socket); // Read greeting
        
        fwrite($socket, "HELO imel.id\r\n");
        fgets($socket);
        
        fwrite($socket, "MAIL FROM:<$from>\r\n");
        fgets($socket);
        
        fwrite($socket, "RCPT TO:<$to>\r\n");
        fgets($socket);
        
        fwrite($socket, "DATA\r\n");
        fgets($socket);
        
        fwrite($socket, $emailContent . "\r\n.\r\n");
        fgets($socket);
        
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        
        // Save to sent folder
        $stmt = $db->prepare("
            INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, sent_at, size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
        ");
        
        $stmt->execute([
            $messageId,
            $user['id'],
            $from,
            $to,
            $cc,
            $subject,
            $plainBody,
            $body,
            strlen($emailContent)
        ]);
        
        $emailId = $db->lastInsertId();
        
        // Save attachments
        if (!empty($attachmentPaths)) {
            $stmt = $db->prepare("
                INSERT INTO attachments (email_id, filename, content_type, size, storage_path)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($attachmentPaths as $attachment) {
                $stmt->execute([
                    $emailId,
                    $attachment['filename'],
                    $attachment['type'],
                    $attachment['size'],
                    $attachment['path']
                ]);
            }
        }
        
        $_SESSION['success'] = 'Email berhasil dikirim!';
    } else {
        $_SESSION['error'] = 'Gagal terhubung ke mail server';
    }
}

header('Location: ?page=inbox&folder=sent');
exit;
