<?php
require_once __DIR__ . '/vendor/autoload.php';

use Predis\Client as RedisClient;

// Debug logging function
function debugLog($message, $data = null) {
    $debug = getenv('DEBUG') === 'true';
    if ($debug) {
        $timestamp = date('Y-m-d H:i:s');
        $workerId = getenv('HOSTNAME') ?: 'worker-' . getmypid();
        echo "[$timestamp][$workerId] $message";
        if ($data !== null) {
            echo ": " . (is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT));
        }
        echo "\n";
    }
}

// Redis connection
class RedisQueue {
    private static $instance = null;
    private $redis;

    private function __construct() {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = getenv('REDIS_PORT') ?: 6379;

        try {
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
            ]);
            debugLog("[REDIS] Connected to Redis", "$host:$port");
        } catch (Exception $e) {
            echo "Redis connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pop($queue, $timeout = 0) {
        $result = $this->redis->blpop($queue, $timeout);
        if ($result) {
            return json_decode($result[1], true);
        }
        return null;
    }
}

// Database connection
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $host = getenv('DB_HOST') ?: 'database';
        $port = getenv('DB_PORT') ?: '5432';
        $dbname = getenv('DB_NAME') ?: 'maildb';
        $user = getenv('DB_USER') ?: 'mailuser';
        $password = getenv('DB_PASSWORD') ?: 'mailpassword';

        try {
            $this->pdo = new PDO(
                "pgsql:host=$host;port=$port;dbname=$dbname",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            echo "Database connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}

function generateMessageId() {
    return '<' . uniqid() . '@imel.id>';
}

function is_base64($string) {
    // Check if string is valid base64
    if (!is_string($string)) {
        return false;
    }
    // Base64 encoded strings only contain these characters
    if (preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) {
        $decoded = base64_decode($string, true);
        if ($decoded !== false && base64_encode($decoded) === $string) {
            return true;
        }
    }
    return false;
}

function getDomainFromEmail($email) {
    $parts = explode('@', $email);
    return isset($parts[1]) ? strtolower($parts[1]) : '';
}

function isLocalDomain($domain) {
    $localDomains = ['imel.id'];
    return in_array(strtolower($domain), $localDomains);
}

function checkExternalEmailRateLimit($fromEmail, $db) {
    // Get user_id from email
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$fromEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Not a local user, allow (external sender)
        return ['allowed' => true, 'current' => 0, 'limit' => 10, 'remaining' => 10];
    }
    
    // Check how many external emails sent in the last hour
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM external_email_log 
        WHERE user_id = ? 
        AND sent_at > NOW() - INTERVAL '1 hour'
    ");
    $stmt->execute([$user['id']]);
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

function logExternalEmailFromWorker($fromEmail, $toEmail, $db) {
    // Get user_id from email
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$fromEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $stmt = $db->prepare("
            INSERT INTO external_email_log (user_id, to_email, sent_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$user['id'], $toEmail]);
        debugLog("[WORKER] External email logged for rate limiting", ['from' => $fromEmail, 'to' => $toEmail]);
    }
}

function getMXRecords($domain) {
    $mxRecords = [];
    $mxHosts = [];
    $mxWeights = [];
    
    if (getmxrr($domain, $mxHosts, $mxWeights)) {
        array_multisort($mxWeights, $mxHosts);
        foreach ($mxHosts as $index => $host) {
            if (!empty($host)) {
                $mxRecords[] = [
                    'host' => $host,
                    'priority' => $mxWeights[$index]
                ];
            }
        }
    }
    
    // If no MX records found or all empty, try A record (direct domain)
    if (empty($mxRecords)) {
        // Try to resolve domain directly
        $ip = gethostbyname($domain);
        if ($ip !== $domain) {
            $mxRecords[] = [
                'host' => $domain,
                'priority' => 10
            ];
        }
    }
    
    return $mxRecords;
}

function sendExternalEmail($emailJob) {
    try {
        $domain = getDomainFromEmail($emailJob['to']);
        debugLog("[WORKER] Sending to external domain", $domain);
        
        // Check rate limit for local senders
        $db = Database::getInstance()->getConnection();
        $rateLimit = checkExternalEmailRateLimit($emailJob['from'], $db);
        
        if (!$rateLimit['allowed']) {
            debugLog("[WORKER] Rate limit exceeded", [
                'from' => $emailJob['from'],
                'current' => $rateLimit['current'],
                'limit' => $rateLimit['limit']
            ]);
            echo "[WORKER] Rate limit exceeded for {$emailJob['from']}: {$rateLimit['current']}/{$rateLimit['limit']} emails per hour\n";
            return false;
        }
        
        debugLog("[WORKER] Rate limit check passed", [
            'from' => $emailJob['from'],
            'remaining' => $rateLimit['remaining']
        ]);
        
        // Get MX records
        $mxRecords = getMXRecords($domain);
        
        if (empty($mxRecords)) {
            // No MX records, try direct connection to domain
            $mxRecords = [['host' => $domain, 'priority' => 10]];
        }
        
        debugLog("[WORKER] MX records found", $mxRecords);
        
        // Try each MX server
        foreach ($mxRecords as $mx) {
            $socket = @fsockopen($mx['host'], 25, $errno, $errstr, 10);
            
            if (!$socket) {
                debugLog("[WORKER] Failed to connect to MX", ['host' => $mx['host'], 'error' => $errstr]);
                continue;
            }
            
            debugLog("[WORKER] Connected to MX", $mx['host']);
            
            // Read greeting
            $response = fgets($socket);
            debugLog("[WORKER] Server greeting", trim($response));
            
            // HELO
            fwrite($socket, "EHLO imel.id\r\n");
            $response = readSmtpResponse($socket);
            debugLog("[WORKER] EHLO response", trim($response));
            
            // MAIL FROM
            fwrite($socket, "MAIL FROM:<{$emailJob['from']}>\r\n");
            $response = readSmtpResponse($socket);
            if (!preg_match('/^250/', $response)) {
                debugLog("[WORKER] MAIL FROM rejected", trim($response));
                fclose($socket);
                continue;
            }
            
            // RCPT TO
            fwrite($socket, "RCPT TO:<{$emailJob['to']}>\r\n");
            $response = readSmtpResponse($socket);
            if (!preg_match('/^250/', $response)) {
                debugLog("[WORKER] RCPT TO rejected", trim($response));
                fclose($socket);
                continue;
            }
            
            // DATA
            fwrite($socket, "DATA\r\n");
            $response = readSmtpResponse($socket);
            if (!preg_match('/^354/', $response)) {
                debugLog("[WORKER] DATA rejected", trim($response));
                fclose($socket);
                continue;
            }
            
            // Build email content
            $emailContent = buildEmailContent($emailJob);
            
            // Debug: Print first 800 chars
            if (!empty($emailJob['attachments'])) {
                echo "[DEBUG EMAIL START]\n";
                echo substr($emailContent, 0, 800);
                echo "\n[DEBUG EMAIL END]\n";
            }
            
            fwrite($socket, $emailContent);
            fwrite($socket, "\r\n.\r\n");
            
            $response = readSmtpResponse($socket);
            debugLog("[WORKER] Email sent", trim($response));
            
            // QUIT
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            
            if (preg_match('/^250/', $response)) {
                debugLog("[WORKER] Email delivered successfully", $emailJob['to']);
                
                // Log external email for rate limiting
                logExternalEmailFromWorker($emailJob['from'], $emailJob['to'], $db);
                
                return true;
            }
        }
        
        debugLog("[WORKER] Failed to deliver email to all MX servers");
        return false;
        
    } catch (Exception $e) {
        debugLog("[WORKER] Error sending external email", $e->getMessage());
        return false;
    }
}

function readSmtpResponse($socket) {
    $response = '';
    while ($line = fgets($socket)) {
        $response .= $line;
        // SMTP response ends with code followed by space (not dash)
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    return $response;
}

function buildEmailContent($emailJob) {
    $content = '';
    
    // Headers
    $content .= "From: {$emailJob['from']}\r\n";
    $content .= "To: {$emailJob['to']}\r\n";
    
    // Add CC header if present
    if (!empty($emailJob['cc'])) {
        $content .= "Cc: {$emailJob['cc']}\r\n";
    }
    
    $content .= "Subject: {$emailJob['subject']}\r\n";
    $content .= "Date: " . date('r') . "\r\n";
    $content .= "Message-ID: " . generateMessageId() . "\r\n";
    $content .= "MIME-Version: 1.0\r\n";
    
    // Check if we have both plain and HTML or attachments
    $hasAttachments = !empty($emailJob['attachments']);
    $hasHtml = !empty($emailJob['html_body']);
    $hasPlain = !empty($emailJob['body']);
    
    // Debug log actual content
    debugLog("[WORKER] Email content debug", [
        'body_preview' => substr($emailJob['body'] ?? '', 0, 100),
        'html_preview' => substr($emailJob['html_body'] ?? '', 0, 100),
        'has_plain' => $hasPlain,
        'has_html' => $hasHtml
    ]);
    
    // Detect if body contains HTML tags
    $bodyHasHtml = $hasPlain && (strpos($emailJob['body'], '<') !== false && strpos($emailJob['body'], '>') !== false);
    
    // Ensure we always have clean plain text
    if ($bodyHasHtml && !$hasHtml) {
        // Body contains HTML but html_body is empty - move to html_body and create plain text
        $emailJob['html_body'] = $emailJob['body'];
        $emailJob['body'] = strip_tags($emailJob['body']);
        $hasHtml = true;
        debugLog("[WORKER] Detected HTML in body field, separated to html_body");
    }
    
    $plainText = $hasPlain ? $emailJob['body'] : ($hasHtml ? strip_tags($emailJob['html_body']) : 'No content');
    
    // Debug: Check attachments
    if ($hasAttachments) {
        echo "[ATTACH DEBUG] Has " . count($emailJob['attachments']) . " attachments\n";
        echo "[ATTACH DEBUG] First attachment: " . json_encode($emailJob['attachments'][0]) . "\n";
    }
    
    if ($hasAttachments) {
        // Multipart/mixed for attachments
        $boundary = uniqid('boundary_');
        $content .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        
        // Text part
        if ($hasHtml) {
            $altBoundary = uniqid('alt_boundary_');
            $content .= "--{$boundary}\r\n";
            $content .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
            
            $content .= "--{$altBoundary}\r\n";
            $content .= "Content-Type: text/plain; charset=utf-8\r\n";
            $content .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $content .= $plainText . "\r\n";
            
            $content .= "--{$altBoundary}\r\n";
            $content .= "Content-Type: text/html; charset=utf-8\r\n";
            $content .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $content .= $emailJob['html_body'] . "\r\n";
            
            $content .= "--{$altBoundary}--\r\n";
        } else {
            $content .= "--{$boundary}\r\n";
            $content .= "Content-Type: text/plain; charset=utf-8\r\n";
            $content .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $content .= $plainText . "\r\n";
        }
        
        // Attachments
        foreach ($emailJob['attachments'] as $attachment) {
            $content .= "--{$boundary}\r\n";
            
            // Set appropriate Content-Type with name parameter
            $contentType = $attachment['content_type'];
            $filename = $attachment['filename'];
            
            $content .= "Content-Type: {$contentType};\r\n";
            $content .= " name=\"{$filename}\"\r\n";
            $content .= "Content-Disposition: attachment;\r\n";
            $content .= " filename=\"{$filename}\"\r\n";
            $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
            
            // Check if content is already base64 encoded (check for 'encoded' flag)
            $attachmentContent = $attachment['content'];
            if (empty($attachment['encoded'])) {
                // Not encoded yet, encode it
                $attachmentContent = base64_encode($attachmentContent);
            }
            
            // Chunk the base64 content into 76 character lines
            $chunked = chunk_split($attachmentContent, 76, "\r\n");
            // Remove trailing CRLF that chunk_split adds
            $chunked = rtrim($chunked);
            $content .= $chunked . "\r\n";
        }
        
        $content .= "--{$boundary}--\r\n";
    } elseif ($hasHtml) {
        // Always use multipart/alternative if we have HTML
        $boundary = uniqid('boundary_');
        $content .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        
        $content .= "--{$boundary}\r\n";
        $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $content .= $plainText . "\r\n\r\n";
        
        $content .= "--{$boundary}\r\n";
        $content .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
        $content .= $emailJob['html_body'] . "\r\n\r\n";
        
        $content .= "--{$boundary}--\r\n";
    } else {
        // Simple plain text only
        $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $content .= $plainText . "\r\n";
    }
    
    debugLog("[WORKER] Email content built", [
        'has_plain' => $hasPlain,
        'has_html' => $hasHtml,
        'has_attachments' => $hasAttachments,
        'plain_length' => strlen($plainText)
    ]);
    
    // Debug: Log a sample of the email content
    if ($hasAttachments) {
        $contentPreview = substr($content, 0, 500);
        debugLog("[WORKER] Email content preview (first 500 chars)", $contentPreview);
    }
    
    return $content;
}

function saveAttachments($db, $emailId, $attachments) {
    $storageDir = '/storage/attachments';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    foreach ($attachments as $attachment) {
        $filename = basename($attachment['filename']);
        $storagePath = $storageDir . '/' . uniqid() . '_' . $filename;
        
        file_put_contents($storagePath, $attachment['content']);
        
        $stmt = $db->prepare("
            INSERT INTO attachments (email_id, filename, content_type, size, storage_path)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $emailId,
            $filename,
            $attachment['content_type'] ?? 'application/octet-stream',
            strlen($attachment['content']),
            $storagePath
        ]);
        
        debugLog("[WORKER] Attachment saved", ['filename' => $filename, 'size' => strlen($attachment['content'])]);
    }
}

function processEmail($emailJob) {
    try {
        debugLog("[WORKER] Processing email", [
            'from' => $emailJob['from'],
            'to' => $emailJob['to'],
            'cc' => $emailJob['cc'] ?? '',
            'subject' => $emailJob['subject']
        ]);
        
        // Debug: Check if attachments exist in job
        if (isset($emailJob['attachments'])) {
            echo "[DEBUG] Email has 'attachments' key with " . count($emailJob['attachments']) . " items\n";
            if (!empty($emailJob['attachments'])) {
                echo "[DEBUG] First attachment keys: " . implode(', ', array_keys($emailJob['attachments'][0])) . "\n";
            }
        } else {
            echo "[DEBUG] Email job does NOT have 'attachments' key\n";
        }
        
        // Build list of all recipients (to, cc, bcc)
        $allRecipients = [];
        
        // Add TO recipients
        if (!empty($emailJob['to'])) {
            $toAddresses = array_map('trim', explode(',', $emailJob['to']));
            $allRecipients = array_merge($allRecipients, $toAddresses);
        }
        
        // Add CC recipients
        if (!empty($emailJob['cc'])) {
            $ccAddresses = array_map('trim', explode(',', $emailJob['cc']));
            $allRecipients = array_merge($allRecipients, $ccAddresses);
        }
        
        // Add BCC recipients
        if (!empty($emailJob['bcc'])) {
            $bccAddresses = array_map('trim', explode(',', $emailJob['bcc']));
            $allRecipients = array_merge($allRecipients, $bccAddresses);
        }
        
        debugLog("[WORKER] Total recipients", ['count' => count($allRecipients), 'recipients' => $allRecipients]);
        
        // Process each recipient
        $successCount = 0;
        foreach ($allRecipients as $recipient) {
            if (empty($recipient)) continue;
            
            // Check if recipient is local domain
            $domain = getDomainFromEmail($recipient);
            
            if (!isLocalDomain($domain)) {
                // External domain - relay via SMTP
                debugLog("[WORKER] External domain detected", ['domain' => $domain, 'recipient' => $recipient]);
                
                // Create email job for this recipient
                $recipientJob = $emailJob;
                $recipientJob['to'] = $recipient;
                
                if (sendExternalEmail($recipientJob)) {
                    $successCount++;
                }
            } else {
                // Local domain - save to database
                if (saveLocalEmail($recipient, $emailJob)) {
                    $successCount++;
                }
            }
        }
        
        debugLog("[WORKER] Email processing completed", ['total' => count($allRecipients), 'success' => $successCount]);
        return $successCount > 0;
        
    } catch (Exception $e) {
        debugLog("[WORKER] Error processing email", $e->getMessage());
        echo "Error processing email: " . $e->getMessage() . "\n";
        return false;
    }
}

function saveLocalEmail($recipient, $emailJob) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Find user by email
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$recipient]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            debugLog("[WORKER] User found", ['user_id' => $user['id'], 'email' => $recipient]);
            
            $messageId = generateMessageId();
            
            $stmt = $db->prepare("
                INSERT INTO emails (message_id, user_id, from_email, to_email, subject, body, html_body, folder, received_at, size)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'inbox', NOW(), ?)
            ");
            
            $stmt->execute([
                $messageId,
                $user['id'],
                $emailJob['from'],
                $recipient,
                $emailJob['subject'],
                $emailJob['body'],
                $emailJob['html_body'],
                $emailJob['size']
            ]);
            
            $emailId = $db->lastInsertId();
            
            // Save attachments if any
            if (!empty($emailJob['attachments'])) {
                saveAttachments($db, $emailId, $emailJob['attachments']);
            }
            
            debugLog("[WORKER] Email saved successfully", ['email_id' => $emailId, 'recipient' => $recipient]);
            return true;
        } else {
            debugLog("[WORKER] Local user not found", $recipient);
            return false;
        }
    } catch (Exception $e) {
        debugLog("[WORKER] Error saving local email", $e->getMessage());
        return false;
    }
}

// Main worker loop
echo "Starting Mail Worker...\n";
$redis = RedisQueue::getInstance();
$db = Database::getInstance(); // Initialize database connection

$processedCount = 0;
$errorCount = 0;

debugLog("[WORKER] Worker ready, waiting for emails...");

while (true) {
    try {
        // Block and wait for email from queue (30 second timeout)
        $emailJob = $redis->pop('email_queue', 30);
        
        if ($emailJob) {
            // Debug: Print raw JSON structure
            echo "[DEBUG RAW] Email job keys: " . implode(', ', array_keys($emailJob)) . "\n";
            if (isset($emailJob['attachments'])) {
                echo "[DEBUG RAW] Attachments is: " . gettype($emailJob['attachments']) . "\n";
                if (is_array($emailJob['attachments'])) {
                    echo "[DEBUG RAW] Attachments count: " . count($emailJob['attachments']) . "\n";
                }
            }
            
            $success = processEmail($emailJob);
            
            if ($success) {
                $processedCount++;
            } else {
                $errorCount++;
            }
            
            debugLog("[WORKER] Stats", [
                'processed' => $processedCount,
                'errors' => $errorCount
            ]);
        }
    } catch (Exception $e) {
        debugLog("[WORKER] Worker error", $e->getMessage());
        echo "Worker error: " . $e->getMessage() . "\n";
        sleep(5); // Wait before retrying
    }
}
