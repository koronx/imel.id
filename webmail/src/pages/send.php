<?php
$user = getCurrentUser();

// Rate limiting functions
function checkExternalEmailRateLimit($userId, $db) {
    // Check how many external emails sent in the last hour
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM external_email_log 
        WHERE user_id = ? 
        AND sent_at > NOW() - INTERVAL '1 hour'
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $maxEmailsPerHour = 10;
    $currentCount = $result['count'] ?? 0;
    
    return [
        'allowed' => $currentCount < $maxEmailsPerHour,
        'current' => $currentCount,
        'limit' => $maxEmailsPerHour,
        'remaining' => max(0, $maxEmailsPerHour - $currentCount)
    ];
}

function logExternalEmail($userId, $toEmail, $db) {
    $stmt = $db->prepare("
        INSERT INTO external_email_log (user_id, to_email, sent_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$userId, $toEmail]);
}

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

// Build list of all external recipients
$allRecipients = array_map('trim', explode(',', $to));
if (!empty($cc)) {
    $ccRecipients = array_map('trim', explode(',', $cc));
    $allRecipients = array_merge($allRecipients, $ccRecipients);
}

// Count external recipients
$externalCount = 0;
foreach ($allRecipients as $recipient) {
    if (!empty($recipient) && !str_ends_with($recipient, '@imel.id')) {
        $externalCount++;
    }
}

// Check if any recipient is internal (same domain @imel.id)
$isInternal = str_ends_with($to, '@imel.id');
$hasExternal = $externalCount > 0;

// Check rate limit for external emails
if ($hasExternal) {
    $rateLimit = checkExternalEmailRateLimit($user['id'], $db);
    
    // Check if user has enough quota for all external recipients
    if ($rateLimit['remaining'] < $externalCount) {
        $_SESSION['error'] = "Batas pengiriman email eksternal tercapai. Anda membutuhkan {$externalCount} kuota tetapi hanya tersisa {$rateLimit['remaining']} dari {$rateLimit['limit']} email per jam. Silakan coba lagi nanti.";
        header('Location: ?page=compose');
        exit;
    }
}

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
            INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, received_at, size)
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
    // External email - send via queue
    try {
        // Log untuk debugging
        error_log("[WEBMAIL] Sending external email to: " . $to);
        
        // Connect to Redis
        $redisHost = getenv('REDIS_HOST') ?: 'redis';
        $redisPort = getenv('REDIS_PORT') ?: 6379;
        
        error_log("[WEBMAIL] Connecting to Redis: $redisHost:$redisPort");
        
        $redis = new \Predis\Client([
            'scheme' => 'tcp',
            'host' => $redisHost,
            'port' => $redisPort,
        ]);
        
        error_log("[WEBMAIL] Redis connected successfully");
        
        // Prepare attachments data with actual content
        $attachmentsData = [];
        if (!empty($attachmentPaths)) {
            error_log("[WEBMAIL] Processing " . count($attachmentPaths) . " attachments");
            foreach ($attachmentPaths as $attachment) {
                $filePath = $attachment['path'];
                error_log("[WEBMAIL] Reading attachment: $filePath");
                
                if (!file_exists($filePath)) {
                    error_log("[WEBMAIL] ERROR: Attachment file not found: $filePath");
                    continue;
                }
                
                $content = file_get_contents($filePath);
                if ($content === false) {
                    error_log("[WEBMAIL] ERROR: Failed to read attachment: $filePath");
                    continue;
                }
                
                error_log("[WEBMAIL] Attachment read successfully, size: " . strlen($content));
                
                $attachmentsData[] = [
                    'filename' => $attachment['filename'],
                    'content_type' => $attachment['type'],
                    'content' => base64_encode($content),  // Base64 encode for safe JSON transport
                    'encoded' => true  // Flag to indicate already base64 encoded
                ];
            }
            
            error_log("[WEBMAIL] Total attachments prepared: " . count($attachmentsData));
        }
        
        // Push to queue
        $emailJob = [
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'body' => $body,  // HTML body
            'html_body' => '',  // Will be detected by worker
            'attachments' => $attachmentsData,
            'received_at' => date('Y-m-d H:i:s')
        ];
        
        error_log("[WEBMAIL] Pushing email to queue");
        $redis->rpush('email_queue', json_encode($emailJob));
        error_log("[WEBMAIL] Email pushed to queue successfully");
        
        // Log external email for rate limiting (all external recipients)
        foreach ($allRecipients as $recipient) {
            if (!empty($recipient) && !str_ends_with($recipient, '@imel.id')) {
                logExternalEmail($user['id'], $recipient, $db);
                error_log("[WEBMAIL] External email logged for rate limiting: " . $recipient);
            }
        }
        
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
    } catch (Exception $e) {
        error_log("[WEBMAIL] ERROR: " . $e->getMessage());
        error_log("[WEBMAIL] Stack trace: " . $e->getTraceAsString());
        $_SESSION['error'] = 'Gagal mengirim email: ' . $e->getMessage();
    }
}

header('Location: ?page=inbox&folder=sent');
exit;
