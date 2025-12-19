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

function getDomainFromEmail($email) {
    $parts = explode('@', $email);
    return isset($parts[1]) ? strtolower($parts[1]) : '';
}

function isLocalDomain($domain) {
    $localDomains = ['imel.id'];
    return in_array(strtolower($domain), $localDomains);
}

function getMXRecords($domain) {
    $mxRecords = [];
    if (getmxrr($domain, $mxHosts, $mxWeights)) {
        array_multisort($mxWeights, $mxHosts);
        foreach ($mxHosts as $index => $host) {
            $mxRecords[] = [
                'host' => $host,
                'priority' => $mxWeights[$index]
            ];
        }
    }
    return $mxRecords;
}

function sendExternalEmail($emailJob) {
    try {
        $domain = getDomainFromEmail($emailJob['to']);
        debugLog("[WORKER] Sending to external domain", $domain);
        
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
            fwrite($socket, $emailContent);
            fwrite($socket, "\r\n.\r\n");
            
            $response = readSmtpResponse($socket);
            debugLog("[WORKER] Email sent", trim($response));
            
            // QUIT
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            
            if (preg_match('/^250/', $response)) {
                debugLog("[WORKER] Email delivered successfully", $emailJob['to']);
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
    $content .= "Subject: {$emailJob['subject']}\r\n";
    $content .= "Date: " . date('r') . "\r\n";
    $content .= "Message-ID: " . generateMessageId() . "\r\n";
    $content .= "MIME-Version: 1.0\r\n";
    
    // Check if we have both plain and HTML or attachments
    $hasAttachments = !empty($emailJob['attachments']);
    $hasHtml = !empty($emailJob['html_body']);
    $hasPlain = !empty($emailJob['body']);
    
    if ($hasAttachments) {
        // Multipart/mixed for attachments
        $boundary = uniqid('boundary_');
        $content .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        
        // Text part
        if ($hasPlain && $hasHtml) {
            $altBoundary = uniqid('alt_boundary_');
            $content .= "--{$boundary}\r\n";
            $content .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
            
            $content .= "--{$altBoundary}\r\n";
            $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $content .= $emailJob['body'] . "\r\n\r\n";
            
            $content .= "--{$altBoundary}\r\n";
            $content .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
            $content .= $emailJob['html_body'] . "\r\n\r\n";
            
            $content .= "--{$altBoundary}--\r\n";
        } else {
            $content .= "--{$boundary}\r\n";
            $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $content .= ($hasPlain ? $emailJob['body'] : strip_tags($emailJob['html_body'])) . "\r\n\r\n";
        }
        
        // Attachments
        foreach ($emailJob['attachments'] as $attachment) {
            $content .= "--{$boundary}\r\n";
            $content .= "Content-Type: {$attachment['content_type']}; name=\"{$attachment['filename']}\"\r\n";
            $content .= "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n";
            $content .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $content .= chunk_split(base64_encode($attachment['content'])) . "\r\n";
        }
        
        $content .= "--{$boundary}--\r\n";
    } elseif ($hasPlain && $hasHtml) {
        // Multipart/alternative
        $boundary = uniqid('boundary_');
        $content .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        
        $content .= "--{$boundary}\r\n";
        $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $content .= $emailJob['body'] . "\r\n\r\n";
        
        $content .= "--{$boundary}\r\n";
        $content .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
        $content .= $emailJob['html_body'] . "\r\n\r\n";
        
        $content .= "--{$boundary}--\r\n";
    } else {
        // Simple text or HTML
        if ($hasHtml) {
            $content .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
            $content .= $emailJob['html_body'] . "\r\n";
        } else {
            $content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $content .= $emailJob['body'] . "\r\n";
        }
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
            'subject' => $emailJob['subject']
        ]);
        
        // Check if recipient is local domain
        $domain = getDomainFromEmail($emailJob['to']);
        
        if (!isLocalDomain($domain)) {
            // External domain - relay via SMTP
            debugLog("[WORKER] External domain detected", ['domain' => $domain]);
            return sendExternalEmail($emailJob);
        }
        
        // Local domain - save to database
        $db = Database::getInstance()->getConnection();
        
        // Find user by email
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$emailJob['to']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            debugLog("[WORKER] User found", ['user_id' => $user['id'], 'email' => $emailJob['to']]);
            $messageId = generateMessageId();
            
            $stmt = $db->prepare("
                INSERT INTO emails (message_id, user_id, from_email, to_email, subject, body, html_body, folder, received_at, size)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'inbox', NOW(), ?)
            ");
            
            $stmt->execute([
                $messageId,
                $user['id'],
                $emailJob['from'],
                $emailJob['to'],
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
            
            debugLog("[WORKER] Email saved successfully", ['email_id' => $emailId, 'recipient' => $emailJob['to']]);
            return true;
        } else {
            debugLog("[WORKER] Local user not found", $emailJob['to']);
            return false;
        }
    } catch (Exception $e) {
        debugLog("[WORKER] Error processing email", $e->getMessage());
        echo "Error processing email: " . $e->getMessage() . "\n";
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
