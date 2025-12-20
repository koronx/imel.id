<?php
// Enable error logging for debugging
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Handle Google OAuth callback
error_log('[OAUTH_CALLBACK] Request received: ' . print_r($_GET, true));

$error = '';
$code = $_GET['code'] ?? '';

if (empty($code)) {
    $error = 'Kode otorisasi tidak ditemukan';
    error_log('[OAUTH_CALLBACK] No code found');
} else {
    error_log('[OAUTH_CALLBACK] Code received: ' . substr($code, 0, 20) . '...');
    try {
        error_log('[OAUTH_CALLBACK] Loading config...');
        $config = include __DIR__ . '/../google-config.php';
        error_log('[OAUTH_CALLBACK] Config loaded. Client ID: ' . substr($config['client_id'], 0, 20) . '...');
        
        // Exchange code for access token
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenData = [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code'
        ];
        
        error_log('[OAUTH_CALLBACK] Exchanging code for token...');
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
        $tokenResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        error_log('[OAUTH_CALLBACK] Token response HTTP code: ' . $httpCode);
        
        if ($curlError) {
            throw new Exception('CURL Error: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            error_log('[OAUTH_CALLBACK] Token error response: ' . $tokenResponse);
            throw new Exception('Gagal mendapatkan access token. HTTP ' . $httpCode . ': ' . $tokenResponse);
        }
        
        $tokenInfo = json_decode($tokenResponse, true);
        $accessToken = $tokenInfo['access_token'] ?? '';
        
        error_log('[OAUTH_CALLBACK] Access token received: ' . ($accessToken ? 'YES' : 'NO'));
        
        if (empty($accessToken)) {
            throw new Exception('Access token tidak ditemukan dalam response');
        }
        
        // Get user info from Google
        error_log('[OAUTH_CALLBACK] Getting user info from Google...');
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
        $ch = curl_init($userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        $userInfoResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('CURL Error getting user info: ' . $curlError);
        }
        
        error_log('[OAUTH_CALLBACK] User info response: ' . $userInfoResponse);
        
        $userInfo = json_decode($userInfoResponse, true);
        
        if (empty($userInfo['email'])) {
            throw new Exception('Email tidak ditemukan dari Google');
        }
        
        $googleEmail = $userInfo['email'];
        $googleName = $userInfo['name'] ?? '';
        
        error_log('[OAUTH_CALLBACK] Google email: ' . $googleEmail . ', name: ' . $googleName);
        
        // Extract username from email (part before @)
        $username = explode('@', $googleEmail)[0];
        $imelEmail = $username . '@imel.id';
        
        error_log('[OAUTH_CALLBACK] Creating/finding user: ' . $imelEmail);
        
        $db = getDB();
        
        // Check if user already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$imelEmail]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingUser) {
            // Auto create user
            error_log('[OAUTH_CALLBACK] Creating new user...');
            $randomPassword = bin2hex(random_bytes(16)); // Random password (won't be used for Google login)
            $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                INSERT INTO users (email, password, full_name, secondary_email, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $imelEmail,
                $hashedPassword,
                $googleName ?: $username,
                $googleEmail
            ]);
            
            $userId = $db->lastInsertId();
            error_log('[OAUTH_CALLBACK] User created with ID: ' . $userId);
            
            // Mark secondary email as verified
            $stmt = $db->prepare("
                INSERT INTO secondary_email_otp (user_id, email, otp, is_verified, created_at, expires_at)
                VALUES (?, ?, '000000', TRUE, NOW(), NOW() + INTERVAL '1 year')
            ");
            $stmt->execute([$userId, $googleEmail]);
            
            // Send welcome email
            try {
                error_log('[OAUTH_CALLBACK] Sending welcome email to: ' . $imelEmail);
                
                $mailHost = getenv('MAIL_HOST') ?: 'mailserver';
                $mailPort = getenv('MAIL_PORT') ?: 25;
                
                $from = 'admin@imel.id';
                $to = $imelEmail;
                $subject = 'Selamat Datang di imel.id!';
                $body = "Halo $googleName,\n\n";
                $body .= "Selamat datang di imel.id!\n\n";
                $body .= "Akun email Anda telah berhasil dibuat:\n";
                $body .= "Email: $imelEmail\n";
                $body .= "Nama: $googleName\n";
                $body .= "Secondary Email: $googleEmail\n\n";
                $body .= "Anda dapat login menggunakan akun Google Anda ($googleEmail) kapan saja.\n\n";
                $body .= "Fitur yang tersedia:\n";
                $body .= "- Kirim dan terima email\n";
                $body .= "- Storage: 100 MB\n";
                $body .= "- Webmail interface\n";
                $body .= "- Mobile app support\n\n";
                $body .= "Terima kasih telah menggunakan layanan kami!\n\n";
                $body .= "Salam,\n";
                $body .= "Tim imel.id";
                
                $boundary = md5(time());
                $headers = "From: Admin imel.id <$from>\r\n";
                $headers .= "Reply-To: $from\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
                $headers .= "X-Mailer: imel.id OAuth System\r\n";
                
                $message = "--$boundary\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                $message .= "$body\r\n";
                $message .= "--$boundary\r\n";
                $message .= "Content-Type: text/html; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                $message .= "<html><body style='font-family: Arial, sans-serif;'>";
                $message .= "<h2 style='color: #dc143c;'>Selamat Datang di imel.id!</h2>";
                $message .= "<p>Halo <strong>$googleName</strong>,</p>";
                $message .= "<p>Selamat datang di <strong>imel.id</strong>!</p>";
                $message .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
                $message .= "<h3>Akun Email Anda:</h3>";
                $message .= "<p>📧 Email: <strong>$imelEmail</strong><br>";
                $message .= "👤 Nama: <strong>$googleName</strong><br>";
                $message .= "🔗 Secondary Email: <strong>$googleEmail</strong></p>";
                $message .= "</div>";
                $message .= "<p>Anda dapat login menggunakan akun Google Anda (<strong>$googleEmail</strong>) kapan saja.</p>";
                $message .= "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>";
                $message .= "<h4>Fitur yang tersedia:</h4>";
                $message .= "<ul>";
                $message .= "<li>✉️ Kirim dan terima email</li>";
                $message .= "<li>💾 Storage: 100 MB</li>";
                $message .= "<li>🌐 Webmail interface</li>";
                $message .= "<li>📱 Mobile app support</li>";
                $message .= "</ul></div>";
                $message .= "<p>Terima kasih telah menggunakan layanan kami!</p>";
                $message .= "<p style='margin-top: 30px;'>Salam,<br><strong>Tim imel.id</strong></p>";
                $message .= "</body></html>\r\n";
                $message .= "--$boundary--";
                
                $socket = fsockopen($mailHost, $mailPort, $errno, $errstr, 10);
                if ($socket) {
                    fgets($socket);
                    fputs($socket, "HELO imel.id\r\n");
                    fgets($socket);
                    fputs($socket, "MAIL FROM: <$from>\r\n");
                    fgets($socket);
                    fputs($socket, "RCPT TO: <$to>\r\n");
                    fgets($socket);
                    fputs($socket, "DATA\r\n");
                    fgets($socket);
                    fputs($socket, "Subject: $subject\r\n");
                    fputs($socket, $headers . "\r\n");
                    fputs($socket, $message . "\r\n.\r\n");
                    fgets($socket);
                    fputs($socket, "QUIT\r\n");
                    fclose($socket);
                    
                    error_log('[OAUTH_CALLBACK] Welcome email sent successfully');
                } else {
                    error_log('[OAUTH_CALLBACK] Failed to connect to mail server: ' . $errstr);
                }
            } catch (Exception $e) {
                error_log('[OAUTH_CALLBACK] Failed to send welcome email: ' . $e->getMessage());
            }
            
            $_SESSION['success'] = 'Akun berhasil dibuat! Email: ' . $imelEmail;
        } else {
            error_log('[OAUTH_CALLBACK] User already exists');
            $userId = $existingUser['id'];
            
            // Update secondary email if not set
            $stmt = $db->prepare("UPDATE users SET secondary_email = ? WHERE id = ? AND secondary_email IS NULL");
            $stmt->execute([$googleEmail, $userId]);
        }
        
        // Login user
        $_SESSION['user_id'] = $userId;
        
        error_log('[OAUTH_CALLBACK] User logged in. Session ID: ' . session_id());
        
        // Update last login
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        
        error_log('[OAUTH_CALLBACK] Redirecting to inbox...');
        header('Location: /?page=inbox');
        exit;
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
        error_log('[OAUTH_CALLBACK] ERROR: ' . $e->getMessage());
        error_log('[OAUTH_CALLBACK] Stack trace: ' . $e->getTraceAsString());
    }
}

// If error, redirect to login with error message
if ($error) {
    error_log('[OAUTH_CALLBACK] Redirecting to login with error: ' . $error);
    $_SESSION['error'] = $error;
    header('Location: /?page=login');
    exit;
}
