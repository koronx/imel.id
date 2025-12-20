<?php
header('Content-Type: application/json');

// Handle preflight requests (CORS is handled by Apache)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load Composer autoload
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    '/var/www/html/vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Database configuration
$DB_HOST = getenv('DB_HOST') ?: 'database';
$DB_PORT = getenv('DB_PORT') ?: '5432';
$DB_NAME = getenv('DB_NAME') ?: 'maildb';
$DB_USER = getenv('DB_USER') ?: 'mailuser';
$DB_PASSWORD = getenv('DB_PASSWORD') ?: 'mailpassword';

// Database connection
function getDB() {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASSWORD;
    
    try {
        $pdo = new PDO(
            "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME",
            $DB_USER,
            $DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        sendError("Database connection failed: " . $e->getMessage());
    }
}

// Helper functions
function sendSuccess($data = [], $message = null) {
    $response = ['success' => true];
    if ($message) $response['message'] = $message;
    if (!empty($data)) $response = array_merge($response, $data);
    echo json_encode($response);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

function generateToken($userId) {
    return base64_encode(random_bytes(32) . '|' . $userId . '|' . time());
}

function validateToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        sendError('Unauthorized', 401);
    }
    
    $token = $matches[1];
    $decoded = base64_decode($token);
    $parts = explode('|', $decoded);
    
    if (count($parts) !== 3) {
        sendError('Invalid token', 401);
    }
    
    $userId = $parts[1];
    $timestamp = $parts[2];
    
    // Token expires after 30 days
    if (time() - $timestamp > 30 * 24 * 60 * 60) {
        sendError('Token expired', 401);
    }
    
    return $userId;
}

// Get action
$action = $_GET['action'] ?? '';

// Routes that don't require authentication
if ($action === 'login') {
    $input = getJsonInput();
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        sendError('Email dan password harus diisi');
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, password, full_name, secondary_email, quota_bytes, quota_used FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($password, $user['password'])) {
        sendError('Email atau password salah', 401);
    }
    
    $token = generateToken($user['id']);
    
    sendSuccess([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'secondary_email' => $user['secondary_email'],
            'quota_bytes' => (int)$user['quota_bytes'],
            'quota_used' => (int)$user['quota_used']
        ]
    ], 'Login berhasil');
}

if ($action === 'register') {
    $input = getJsonInput();
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    $fullName = $input['full_name'] ?? '';
    
    if (empty($email) || empty($password) || empty($fullName)) {
        sendError('Semua field harus diisi');
    }
    
    if (strlen($password) < 8) {
        sendError('Password minimal 8 karakter');
    }
    
    $db = getDB();
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendError('Email sudah terdaftar');
    }
    
    // Create user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (email, password, full_name) VALUES (?, ?, ?)");
    
    try {
        $stmt->execute([$email, $passwordHash, $fullName]);
        sendSuccess([], 'Registrasi berhasil');
    } catch (PDOException $e) {
        sendError('Registrasi gagal: ' . $e->getMessage());
    }
}

// All routes below require authentication
$userId = validateToken();

if ($action === 'get_user') {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, full_name, secondary_email, quota_bytes, quota_used FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        sendError('User tidak ditemukan', 404);
    }
    
    $user['quota_bytes'] = (int)$user['quota_bytes'];
    $user['quota_used'] = (int)$user['quota_used'];
    
    sendSuccess(['user' => $user]);
}

if ($action === 'get_inbox') {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $db = getDB();
    
    // Get inbox emails using user_id and folder
    $stmt = $db->prepare("
        SELECT id, from_email as sender, to_email as recipient, subject, body, received_at as created_at, is_read,
               (SELECT COUNT(*) FROM attachments WHERE email_id = emails.id) > 0 as has_attachment
        FROM emails 
        WHERE user_id = ? AND folder = 'inbox'
        ORDER BY received_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccess(['emails' => $emails]);
}

if ($action === 'get_sent') {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $db = getDB();
    
    // Get sent emails using user_id and folder
    $stmt = $db->prepare("
        SELECT id, from_email as sender, to_email as recipient, subject, body, received_at as created_at, true as is_read,
               (SELECT COUNT(*) FROM attachments WHERE email_id = emails.id) > 0 as has_attachment
        FROM emails 
        WHERE user_id = ? AND folder = 'sent'
        ORDER BY received_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccess(['emails' => $emails]);
}

if ($action === 'get_email') {
    $emailId = (int)($_GET['id'] ?? 0);
    
    if (!$emailId) {
        sendError('Email ID harus diisi');
    }
    
    $db = getDB();
    
    // Get email detail using user_id
    $stmt = $db->prepare("
        SELECT id, from_email as sender, to_email as recipient, subject, body, received_at as created_at, is_read
        FROM emails 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$emailId, $userId]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$email) {
        sendError('Email tidak ditemukan', 404);
    }
    
    // Get attachments
    $stmt = $db->prepare("SELECT id, filename, content_type as mime_type, size FROM attachments WHERE email_id = ?");
    $stmt->execute([$emailId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccess(['email' => $email, 'attachments' => $attachments]);
}

if ($action === 'send_email') {
    $input = getJsonInput();
    $to = $input['to'] ?? '';
    $subject = $input['subject'] ?? '';
    $body = $input['body'] ?? '';
    $cc = !empty($input['cc']) ? (is_array($input['cc']) ? implode(',', $input['cc']) : $input['cc']) : '';
    $bcc = $input['bcc'] ?? [];
    
    if (empty($to) || empty($subject) || empty($body)) {
        sendError('Penerima, subjek, dan isi email harus diisi');
    }
    
    $db = getDB();
    
    // Get user info
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $from = $stmt->fetchColumn();
    
    // Rate limiting functions
    function checkExternalEmailRateLimit($userId, $db) {
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
    
    // Generate message ID
    $messageId = '<' . uniqid() . '@imel.id>';
    
    // Create plain text version
    $plainBody = strip_tags($body);
    
    // Build email content
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
    
    // Build list of all recipients
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
    
    // Check if internal or external
    $isInternal = str_ends_with($to, '@imel.id');
    $hasExternal = $externalCount > 0;
    
    // Check rate limit for external emails
    if ($hasExternal) {
        $rateLimit = checkExternalEmailRateLimit($userId, $db);
        
        if ($rateLimit['remaining'] < $externalCount) {
            sendError("Batas pengiriman email eksternal tercapai. Anda membutuhkan {$externalCount} kuota tetapi hanya tersisa {$rateLimit['remaining']} dari {$rateLimit['limit']} email per jam. Silakan coba lagi nanti.");
        }
    }
    
    try {
        if ($isInternal) {
            // Internal email - save directly to database
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
                
                // Save to sender's sent folder
                $stmt = $db->prepare("
                    INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, received_at, size)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
                ");
                
                $stmt->execute([
                    $messageId . '-sent',
                    $userId,
                    $from,
                    $to,
                    $cc,
                    $subject,
                    $plainBody,
                    $body,
                    strlen($emailContent)
                ]);
                
                sendSuccess(['email_id' => $db->lastInsertId()], 'Email berhasil dikirim!');
            } else {
                sendError('Penerima tidak ditemukan');
            }
        } else {
            // External email - send via Redis queue
            $redisHost = getenv('REDIS_HOST') ?: 'redis';
            $redisPort = getenv('REDIS_PORT') ?: 6379;
            
            $redis = new \Predis\Client([
                'scheme' => 'tcp',
                'host' => $redisHost,
                'port' => $redisPort,
            ]);
            
            // Push to queue
            $emailJob = [
                'from' => $from,
                'to' => $to,
                'cc' => $cc,
                'subject' => $subject,
                'body' => $body,
                'html_body' => '',
                'attachments' => [],
                'received_at' => date('Y-m-d H:i:s')
            ];
            
            $redis->rpush('email_queue', json_encode($emailJob));
            
            // Log external email for rate limiting
            foreach ($allRecipients as $recipient) {
                if (!empty($recipient) && !str_ends_with($recipient, '@imel.id')) {
                    logExternalEmail($userId, $recipient, $db);
                }
            }
            
            // Save to sent folder
            $stmt = $db->prepare("
                INSERT INTO emails (message_id, user_id, from_email, to_email, cc, subject, body, html_body, folder, received_at, size)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
            ");
            
            $stmt->execute([
                $messageId,
                $userId,
                $from,
                $to,
                $cc,
                $subject,
                $plainBody,
                $body,
                strlen($emailContent)
            ]);
            
            sendSuccess(['email_id' => $db->lastInsertId()], 'Email berhasil dikirim!');
        }
    } catch (Exception $e) {
        error_log("[API] ERROR: " . $e->getMessage());
        sendError('Gagal mengirim email: ' . $e->getMessage());
    }
}

if ($action === 'mark_read') {
    $input = getJsonInput();
    $emailId = (int)($input['email_id'] ?? 0);
    
    if (!$emailId) {
        sendError('Email ID harus diisi');
    }
    
    $db = getDB();
    
    // Mark as read using user_id
    $stmt = $db->prepare("UPDATE emails SET is_read = true WHERE id = ? AND user_id = ?");
    $stmt->execute([$emailId, $userId]);
    
    sendSuccess([], 'Email ditandai sudah dibaca');
}

if ($action === 'delete_email') {
    $input = getJsonInput();
    $emailId = (int)($input['email_id'] ?? 0);
    
    if (!$emailId) {
        sendError('Email ID harus diisi');
    }
    
    $db = getDB();
    
    // Delete email using user_id
    $stmt = $db->prepare("DELETE FROM emails WHERE id = ? AND user_id = ?");
    $stmt->execute([$emailId, $userId]);
    
    if ($stmt->rowCount() > 0) {
        sendSuccess([], 'Email berhasil dihapus');
    } else {
        sendError('Email tidak ditemukan atau tidak dapat dihapus', 404);
    }
}

// Admin endpoints
if ($action === 'admin_get_users') {
    // Check if user is admin
    $db = getDB();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentUser['email'] !== 'admin@imel.id') {
        sendError('Unauthorized - Admin only', 403);
    }
    
    // Get all users with quota info
    $stmt = $db->prepare("
        SELECT id, email, full_name, quota_bytes, quota_used,
               ROUND((quota_used::NUMERIC / NULLIF(quota_bytes, 0)::NUMERIC) * 100, 2) as usage_percent
        FROM users
        ORDER BY email
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to integers
    foreach ($users as &$user) {
        $user['quota_bytes'] = (int)$user['quota_bytes'];
        $user['quota_used'] = (int)$user['quota_used'];
        $user['usage_percent'] = (float)$user['usage_percent'];
    }
    
    sendSuccess(['users' => $users]);
}

if ($action === 'admin_update_quota') {
    // Check if user is admin
    $db = getDB();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentUser['email'] !== 'admin@imel.id') {
        sendError('Unauthorized - Admin only', 403);
    }
    
    $input = getJsonInput();
    $targetUserId = (int)($input['user_id'] ?? 0);
    $newQuota = (int)($input['quota_bytes'] ?? 0);
    
    if (!$targetUserId || $newQuota < 0) {
        sendError('User ID dan quota harus diisi dengan benar');
    }
    
    // Update quota
    $stmt = $db->prepare("UPDATE users SET quota_bytes = ? WHERE id = ?");
    $stmt->execute([$newQuota, $targetUserId]);
    
    if ($stmt->rowCount() > 0) {
        sendSuccess([], 'Quota berhasil diupdate');
    } else {
        sendError('User tidak ditemukan', 404);
    }
}

if ($action === 'admin_edit_user') {
    // Check if user is admin
    $db = getDB();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentUser['email'] !== 'admin@imel.id') {
        sendError('Unauthorized - Admin only', 403);
    }
    
    $input = getJsonInput();
    $targetUserId = (int)($input['user_id'] ?? 0);
    $fullName = $input['full_name'] ?? '';
    $secondaryEmail = $input['secondary_email'] ?? null;
    $password = $input['password'] ?? '';
    $quotaBytes = isset($input['quota_bytes']) ? (int)$input['quota_bytes'] : null;
    $resetQuota = $input['reset_quota'] ?? false;
    
    if (!$targetUserId) {
        sendError('User ID harus diisi');
    }
    
    // Build update query dynamically
    $updates = [];
    $params = [];
    
    if (!empty($fullName)) {
        $updates[] = "full_name = ?";
        $params[] = $fullName;
    }
    
    if ($secondaryEmail !== null) {
        $updates[] = "secondary_email = ?";
        $params[] = $secondaryEmail ?: null;
    }
    
    if (!empty($password)) {
        $updates[] = "password = ?";
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    
    if ($quotaBytes !== null) {
        $updates[] = "quota_bytes = ?";
        $params[] = $quotaBytes;
    }
    
    if ($resetQuota) {
        $updates[] = "quota_used = 0";
    }
    
    if (empty($updates)) {
        sendError('Tidak ada data yang diupdate');
    }
    
    // Add user_id to params
    $params[] = $targetUserId;
    
    // Execute update
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() > 0) {
        sendSuccess([], 'User berhasil diupdate');
    } else {
        sendError('User tidak ditemukan atau tidak ada perubahan', 404);
    }
}

if ($action === 'admin_delete_user') {
    // Check if user is admin
    $db = getDB();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentUser['email'] !== 'admin@imel.id') {
        sendError('Unauthorized - Admin only', 403);
    }
    
    $input = getJsonInput();
    $targetUserId = (int)($input['user_id'] ?? 0);
    
    if (!$targetUserId) {
        sendError('User ID harus diisi');
    }
    
    // Don't allow deleting admin user
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        sendError('User tidak ditemukan', 404);
    }
    
    if ($targetUser['email'] === 'admin@imel.id') {
        sendError('Tidak dapat menghapus user admin');
    }
    
    $db->beginTransaction();
    try {
        // Archive all emails from and to this user
        // 1. Archive emails where user is the owner
        $stmt = $db->prepare("
            INSERT INTO archived_emails 
            (original_email_id, message_id, user_id, user_email, from_email, to_email, cc, bcc, subject, body, html_body, is_read, is_starred, folder, received_at, sent_at, size, archived_reason)
            SELECT id, message_id, user_id, ?, from_email, to_email, cc, bcc, subject, body, html_body, is_read, is_starred, folder, received_at, sent_at, size, 'user_deleted'
            FROM emails
            WHERE user_id = ?
        ");
        $stmt->execute([$targetUser['email'], $targetUserId]);
        
        // 2. Archive emails from this user (as sender)
        $stmt = $db->prepare("
            INSERT INTO archived_emails 
            (original_email_id, message_id, user_id, user_email, from_email, to_email, cc, bcc, subject, body, html_body, is_read, is_starred, folder, received_at, sent_at, size, archived_reason)
            SELECT e.id, e.message_id, e.user_id, ?, e.from_email, e.to_email, e.cc, e.bcc, e.subject, e.body, e.html_body, e.is_read, e.is_starred, e.folder, e.received_at, e.sent_at, e.size, 'sender_deleted'
            FROM emails e
            WHERE e.from_email = ?
            AND NOT EXISTS (
                SELECT 1 FROM archived_emails ae WHERE ae.original_email_id = e.id
            )
        ");
        $stmt->execute([$targetUser['email'], $targetUser['email']]);
        
        // 3. Archive emails to this user (as recipient)
        $stmt = $db->prepare("
            INSERT INTO archived_emails 
            (original_email_id, message_id, user_id, user_email, from_email, to_email, cc, bcc, subject, body, html_body, is_read, is_starred, folder, received_at, sent_at, size, archived_reason)
            SELECT e.id, e.message_id, e.user_id, ?, e.from_email, e.to_email, e.cc, e.bcc, e.subject, e.body, e.html_body, e.is_read, e.is_starred, e.folder, e.received_at, e.sent_at, e.size, 'recipient_deleted'
            FROM emails e
            WHERE e.to_email = ?
            AND NOT EXISTS (
                SELECT 1 FROM archived_emails ae WHERE ae.original_email_id = e.id
            )
        ");
        $stmt->execute([$targetUser['email'], $targetUser['email']]);
        
        // Archive attachments
        $stmt = $db->prepare("
            INSERT INTO archived_attachments 
            (original_attachment_id, archived_email_id, email_id, filename, content_type, size, storage_path, created_at)
            SELECT a.id, ae.id, a.email_id, a.filename, a.content_type, a.size, a.storage_path, a.created_at
            FROM attachments a
            JOIN emails e ON a.email_id = e.id
            JOIN archived_emails ae ON e.id = ae.original_email_id
            WHERE e.user_id = ? OR e.from_email = ? OR e.to_email = ?
        ");
        $stmt->execute([$targetUserId, $targetUser['email'], $targetUser['email']]);
        
        // Delete the user (CASCADE will delete emails and related data)
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        
        $db->commit();
        sendSuccess([], 'User berhasil dihapus dan email diarsipkan');
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[API] Delete user error: " . $e->getMessage());
        sendError('Gagal menghapus user: ' . $e->getMessage());
    }
}

sendError('Invalid action', 400);
