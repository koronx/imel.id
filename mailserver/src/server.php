<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Predis\Client as RedisClient;

// Debug logging function
function debugLog($message, $data = null) {
    $debug = getenv('DEBUG') === 'true';
    if ($debug) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] $message";
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

    public function push($queue, $data) {
        return $this->redis->rpush($queue, json_encode($data));
    }

    public function pop($queue, $timeout = 0) {
        $result = $this->redis->blpop($queue, $timeout);
        if ($result) {
            return json_decode($result[1], true);
        }
        return null;
    }

    public function queueSize($queue) {
        return $this->redis->llen($queue);
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

// Helper function to get domain from email
function getDomainFromEmail($email) {
    $parts = explode('@', $email);
    return isset($parts[1]) ? strtolower($parts[1]) : '';
}

// Helper function to check if domain is local
function isLocalDomain($domain) {
    $localDomains = ['imel.id'];
    return in_array(strtolower($domain), $localDomains);
}

// Helper function to check if user exists
function userExists($email) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return !empty($user);
    } catch (Exception $e) {
        debugLog("[SMTP] Error checking user existence", $e->getMessage());
        return false;
    }
}

// SMTP Server
$smtp_worker = new Worker("tcp://0.0.0.0:25");
$smtp_worker->name = 'SMTP Server';
$smtp_worker->count = 4;

$smtp_worker->onConnect = function($connection) {
    debugLog("[SMTP] New connection from: " . $connection->getRemoteIp());
    $connection->send("220 imel.id SMTP Server Ready\r\n");
    $connection->smtp_state = 'INIT';
    $connection->smtp_from = '';
    $connection->smtp_to = [];
    $connection->smtp_data = '';
};

$smtp_worker->onMessage = function($connection, $data) {
    // If in DATA mode, process line by line
    if (isset($connection->smtp_state) && $connection->smtp_state === 'DATA') {
        // Split by lines if multiple lines received at once
        $lines = explode("\r\n", $data);
        
        foreach ($lines as $line) {
            if ($line === '.') {
                // End of DATA
                debugLog("[SMTP] End of DATA marker received, saving email", [
                    'from' => $connection->smtp_from,
                    'to' => $connection->smtp_to,
                    'size' => strlen($connection->smtp_data)
                ]);
                saveEmail($connection);
                $connection->send("250 OK: Message accepted\r\n");
                
                // Reset connection state
                $connection->smtp_from = '';
                $connection->smtp_to = [];
                $connection->smtp_data = '';
                $connection->smtp_state = 'HELO';
                return;
            } else {
                // Accumulate data
                $connection->smtp_data .= $line . "\r\n";
            }
        }
        debugLog("[SMTP] Receiving data in DATA mode", "Total lines: " . count($lines) . ", Total size: " . strlen($connection->smtp_data));
        return;
    }
    
    // Normal command processing
    $data = trim($data);
    $command = strtoupper(substr($data, 0, 4));
    
    debugLog("[SMTP] Received command", $data);
    
    switch ($command) {
        case 'HELO':
        case 'EHLO':
            debugLog("[SMTP] HELO/EHLO received");
            $connection->send("250 Hello\r\n");
            $connection->smtp_state = 'HELO';
            break;
            
        case 'MAIL':
            if (preg_match('/FROM:<(.+?)>/i', $data, $matches)) {
                $connection->smtp_from = $matches[1];
                
                // Block system-reserved email addresses
                $reservedAddresses = ['noreply@imel.id', 'system@imel.id'];
                if (in_array(strtolower($connection->smtp_from), $reservedAddresses)) {
                    debugLog("[SMTP] MAIL FROM rejected - reserved system address", $connection->smtp_from);
                    $connection->send("553 Requested action not taken: This address is reserved for system use only\r\n");
                    break;
                }
                
                debugLog("[SMTP] MAIL FROM", $connection->smtp_from);
                $connection->send("250 OK\r\n");
                $connection->smtp_state = 'MAIL';
            } else {
                debugLog("[SMTP] MAIL FROM syntax error");
                $connection->send("501 Syntax error\r\n");
            }
            break;
            
        case 'RCPT':
            if (preg_match('/TO:<(.+?)>/i', $data, $matches)) {
                $recipient = $matches[1];
                $domain = getDomainFromEmail($recipient);
                
                // Check if domain is local (imel.id)
                if (!isLocalDomain($domain)) {
                    debugLog("[SMTP] RCPT TO rejected - domain not served here", ['recipient' => $recipient, 'domain' => $domain]);
                    $connection->send("550 Relay not permitted: This server only accepts mail for imel.id\r\n");
                    break;
                }
                
                // Check if user exists in database
                if (!userExists($recipient)) {
                    debugLog("[SMTP] RCPT TO rejected - user not found", ['recipient' => $recipient]);
                    $connection->send("550 User not found: Account does not exist on this server\r\n");
                    break;
                }
                
                $connection->smtp_to[] = $recipient;
                debugLog("[SMTP] RCPT TO accepted", $recipient);
                $connection->send("250 OK\r\n");
                $connection->smtp_state = 'RCPT';
            } else {
                debugLog("[SMTP] RCPT TO syntax error");
                $connection->send("501 Syntax error\r\n");
            }
            break;
            
        case 'DATA':
            if ($connection->smtp_state === 'RCPT') {
                debugLog("[SMTP] DATA command received, entering DATA mode");
                $connection->send("354 Start mail input; end with <CRLF>.<CRLF>\r\n");
                $connection->smtp_state = 'DATA';
            } else {
                debugLog("[SMTP] DATA command in wrong state", $connection->smtp_state);
                $connection->send("503 Bad sequence of commands\r\n");
            }
            break;
            
        case 'QUIT':
            debugLog("[SMTP] QUIT received", [
                'state' => $connection->smtp_state,
                'from' => $connection->smtp_from,
                'to' => $connection->smtp_to,
                'data_size' => strlen($connection->smtp_data ?? '')
            ]);
            $connection->send("221 Bye\r\n");
            $connection->close();
            break;
            
        default:
            debugLog("[SMTP] Unrecognized command", ['command' => $data, 'state' => $connection->smtp_state]);
            $connection->send("500 Command not recognized\r\n");
    }
};

function saveEmail($connection) {
    try {
        $redis = RedisQueue::getInstance();
        
        // For large emails, save to temporary file first
        $emailSize = strlen($connection->smtp_data);
        $useTempFile = $emailSize > 1024 * 1024; // > 1MB use temp file
        
        $tempFile = null;
        if ($useTempFile) {
            // Save to temp file
            $tempDir = '/storage/temp_emails';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempFile = $tempDir . '/' . uniqid('email_') . '.eml';
            file_put_contents($tempFile, $connection->smtp_data);
            debugLog("[SMTP] Saved large email to temp file", ['file' => $tempFile, 'size' => $emailSize]);
        }
        
        // Parse email data (without decoding large attachments)
        $emailData = parseEmail($connection->smtp_data, !$useTempFile);
        debugLog("[SMTP] Parsed email", [
            'subject' => $emailData['subject'],
            'body_length' => strlen($emailData['body'] ?? ''),
            'attachments' => count($emailData['attachments'] ?? [])
        ]);
        
        // Push email to Redis queue for each recipient
        foreach ($connection->smtp_to as $recipient) {
            $emailJob = [
                'from' => $connection->smtp_from,
                'to' => $recipient,
                'subject' => $emailData['subject'] ?? '',
                'body' => $emailData['body'] ?? '',
                'html_body' => $emailData['html_body'] ?? '',
                'attachments' => $emailData['attachments'] ?? [],
                'temp_file' => $tempFile,
                'size' => $emailSize,
                'received_at' => date('Y-m-d H:i:s')
            ];
            
            // Only include raw_data if email is small
            if (!$useTempFile) {
                $emailJob['raw_data'] = $connection->smtp_data;
            }
            
            $redis->push('email_queue', $emailJob);
            debugLog("[SMTP] Email pushed to queue", [
                'recipient' => $recipient, 
                'queue_size' => $redis->queueSize('email_queue'),
                'use_temp_file' => $useTempFile
            ]);
        }
    } catch (Exception $e) {
        debugLog("[SMTP] Error queuing email", $e->getMessage());
        echo "Error queuing email: " . $e->getMessage() . "\n";
    }
}

function parseEmail($rawData, $includeAttachmentContent = true) {
    $result = [
        'subject' => '',
        'body' => '',
        'html_body' => '',
        'attachments' => []
    ];
    
    $lines = explode("\r\n", $rawData);
    $headers = [];
    $body = '';
    $inBody = false;
    $contentType = '';
    $boundary = '';
    
    // Parse headers
    foreach ($lines as $line) {
        if (!$inBody) {
            if (empty(trim($line))) {
                $inBody = true;
                continue;
            }
            
            // Capture Subject
            if (preg_match('/^Subject:\s*(.+)$/i', $line, $matches)) {
                $result['subject'] = trim($matches[1]);
            }
            
            // Capture Content-Type and boundary (handle multiline)
            if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $matches)) {
                $contentType = trim($matches[1]);
            }
            // Check for boundary on separate line
            if (preg_match('/boundary="([^"]+)"/', $line, $boundaryMatch)) {
                $boundary = $boundaryMatch[1];
            } elseif (preg_match('/boundary=([^\s;]+)/', $line, $boundaryMatch)) {
                $boundary = trim($boundaryMatch[1], '"');
            }
        } else {
            $body .= $line . "\r\n";
        }
    }
    
    debugLog("[PARSER] Content-Type", $contentType);
    debugLog("[PARSER] Boundary", $boundary);
    debugLog("[PARSER] Body size", strlen($body));
    
    // If multipart, parse parts
    if (!empty($boundary) && strpos($contentType, 'multipart') !== false) {
        debugLog("[PARSER] Detected multipart email");
        $parts = explode("--" . $boundary, $body);
        debugLog("[PARSER] Number of parts", count($parts));
        
        foreach ($parts as $index => $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') continue;
            
            debugLog("[PARSER] Processing part", $index);
            
            // Split part into headers and content
            $partLines = explode("\r\n\r\n", $part, 2);
            if (count($partLines) < 2) {
                debugLog("[PARSER] Part has no content", $index);
                continue;
            }
            
            $partHeaders = $partLines[0];
            $partContent = $partLines[1];
            
            debugLog("[PARSER] Part headers", substr($partHeaders, 0, 200));
            
            // Check if this part is nested multipart/alternative
            if (preg_match('/Content-Type:\s*multipart\/alternative.*boundary="([^"]+)"/i', $partHeaders, $nestedBoundaryMatch)) {
                debugLog("[PARSER] Found nested multipart/alternative");
                $nestedBoundary = $nestedBoundaryMatch[1];
                $nestedParts = explode("--" . $nestedBoundary, $partContent);
                
                foreach ($nestedParts as $nestedPart) {
                    $nestedPart = trim($nestedPart);
                    if (empty($nestedPart) || $nestedPart === '--') continue;
                    
                    $nestedPartLines = explode("\r\n\r\n", $nestedPart, 2);
                    if (count($nestedPartLines) < 2) continue;
                    
                    $nestedPartHeaders = $nestedPartLines[0];
                    $nestedPartContent = $nestedPartLines[1];
                    
                    if (preg_match('/Content-Type:\s*text\/plain/i', $nestedPartHeaders)) {
                        debugLog("[PARSER] Found text/plain in nested part");
                        $decoded = decodeContent($nestedPartContent, $nestedPartHeaders);
                        if (empty($result['body'])) {
                            $result['body'] = $decoded;
                        }
                    } elseif (preg_match('/Content-Type:\s*text\/html/i', $nestedPartHeaders)) {
                        debugLog("[PARSER] Found text/html in nested part");
                        $decoded = decodeContent($nestedPartContent, $nestedPartHeaders);
                        if (empty($result['html_body'])) {
                            $result['html_body'] = $decoded;
                        }
                    }
                }
            }
            // Check content type of this part
            elseif (preg_match('/Content-Type:\s*text\/plain/i', $partHeaders)) {
                // Plain text part
                debugLog("[PARSER] Found text/plain part");
                $decoded = decodeContent($partContent, $partHeaders);
                if (empty($result['body'])) {
                    $result['body'] = $decoded;
                    debugLog("[PARSER] Set plain text body", substr($decoded, 0, 100));
                }
            } elseif (preg_match('/Content-Type:\s*text\/html/i', $partHeaders)) {
                // HTML part
                debugLog("[PARSER] Found text/html part");
                $decoded = decodeContent($partContent, $partHeaders);
                if (empty($result['html_body'])) {
                    $result['html_body'] = $decoded;
                    debugLog("[PARSER] Set HTML body", substr($decoded, 0, 100));
                }
            }
            // Check if this is an attachment
            elseif (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeaders, $contentTypeMatch)) {
                $attachmentType = trim($contentTypeMatch[1]);
                
                // Check for filename in Content-Disposition or Content-Type
                $filename = '';
                if (preg_match('/filename="([^"]+)"/i', $partHeaders, $filenameMatch)) {
                    $filename = $filenameMatch[1];
                } elseif (preg_match('/name="([^"]+)"/i', $partHeaders, $nameMatch)) {
                    $filename = $nameMatch[1];
                }
                
                if (!empty($filename)) {
                    debugLog("[PARSER] Found attachment", ['filename' => $filename, 'type' => $attachmentType]);
                    $attachment = [
                        'filename' => $filename,
                        'content_type' => $attachmentType
                    ];
                    
                    // Only include content if requested (for small emails)
                    if ($includeAttachmentContent) {
                        $attachment['content'] = decodeContent($partContent, $partHeaders);
                    } else {
                        // Store metadata only, content will be read from temp file later
                        $attachment['size'] = strlen($partContent);
                        $attachment['encoded'] = preg_match('/Content-Transfer-Encoding:\s*base64/i', $partHeaders);
                    }
                    
                    $result['attachments'][] = $attachment;
                }
            }
        }
    } else {
        // Not multipart, just use body as is
        debugLog("[PARSER] Single part email");
        $result['body'] = trim($body);
    }
    
    return $result;
}

function decodeContent($content, $headers) {
    // Check for quoted-printable encoding
    if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headers)) {
        return quoted_printable_decode($content);
    }
    
    // Check for base64 encoding
    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headers)) {
        return base64_decode($content);
    }
    
    return $content;
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
    }
}

echo "Starting SMTP Server...\n";
echo "SMTP listening on port 25\n";

Worker::runAll();
