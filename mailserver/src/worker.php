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
        $db = Database::getInstance()->getConnection();
        
        debugLog("[WORKER] Processing email", [
            'from' => $emailJob['from'],
            'to' => $emailJob['to'],
            'subject' => $emailJob['subject']
        ]);
        
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
            debugLog("[WORKER] User not found", $emailJob['to']);
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
