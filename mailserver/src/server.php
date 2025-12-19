<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;

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
                $connection->smtp_to[] = $matches[1];
                debugLog("[SMTP] RCPT TO", $matches[1]);
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
        $db = Database::getInstance()->getConnection();
        
        // Parse email data
        $emailData = parseEmail($connection->smtp_data);
        debugLog("[SMTP] Parsed email", [
            'subject' => $emailData['subject'],
            'body_length' => strlen($emailData['body'] ?? '')
        ]);
        
        // Save email for each recipient
        foreach ($connection->smtp_to as $recipient) {
            debugLog("[SMTP] Looking up recipient", $recipient);
            // Find user by email
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$recipient]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                debugLog("[SMTP] User found", ['user_id' => $user['id'], 'email' => $recipient]);
                $messageId = generateMessageId();
                
                $stmt = $db->prepare("
                    INSERT INTO emails (message_id, user_id, from_email, to_email, subject, body, html_body, folder, received_at, size)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'inbox', NOW(), ?)
                ");
                
                $stmt->execute([
                    $messageId,
                    $user['id'],
                    $connection->smtp_from,
                    $recipient,
                    $emailData['subject'] ?? '',
                    $emailData['body'] ?? '',
                    $emailData['html_body'] ?? '',
                    strlen($connection->smtp_data)
                ]);
                
                $emailId = $db->lastInsertId();
                
                // Save attachments if any
                if (!empty($emailData['attachments'])) {
                    saveAttachments($db, $emailId, $emailData['attachments']);
                }
                
                debugLog("[SMTP] Email saved successfully", ['email_id' => $emailId, 'recipient' => $recipient]);
            } else {
                debugLog("[SMTP] User not found", $recipient);
            }
        }
    } catch (Exception $e) {
        debugLog("[SMTP] Error saving email", $e->getMessage());
        echo "Error saving email: " . $e->getMessage() . "\n";
    }
}

function parseEmail($rawData) {
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
    
    foreach ($lines as $line) {
        if (!$inBody) {
            if (empty(trim($line))) {
                $inBody = true;
                continue;
            }
            
            if (preg_match('/^Subject:\s*(.+)$/i', $line, $matches)) {
                $result['subject'] = trim($matches[1]);
            }
        } else {
            $body .= $line . "\n";
        }
    }
    
    $result['body'] = trim($body);
    
    return $result;
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

// IMAP Server
$imap_worker = new Worker("tcp://0.0.0.0:143");
$imap_worker->name = 'IMAP Server';
$imap_worker->count = 4;

$imap_worker->onConnect = function($connection) {
    $connection->send("* OK IMAP4rev1 Service Ready\r\n");
    $connection->imap_state = 'NOT_AUTHENTICATED';
    $connection->imap_user = null;
};

$imap_worker->onMessage = function($connection, $data) {
    $data = trim($data);
    
    // Parse IMAP command: TAG COMMAND ARGS
    if (!preg_match('/^(\S+)\s+(\S+)(.*)$/', $data, $matches)) {
        $connection->send("* BAD Invalid command\r\n");
        return;
    }
    
    $tag = $matches[1];
    $command = strtoupper($matches[2]);
    $args = trim($matches[3] ?? '');
    
    switch ($command) {
        case 'CAPABILITY':
            $connection->send("* CAPABILITY IMAP4rev1\r\n");
            $connection->send("$tag OK CAPABILITY completed\r\n");
            break;
            
        case 'LOGIN':
            handleImapLogin($connection, $tag, $args);
            break;
            
        case 'SELECT':
        case 'EXAMINE':
            handleImapSelect($connection, $tag, $args);
            break;
            
        case 'LIST':
            handleImapList($connection, $tag, $args);
            break;
            
        case 'FETCH':
            handleImapFetch($connection, $tag, $args);
            break;
            
        case 'LOGOUT':
            $connection->send("* BYE IMAP4rev1 Server logging out\r\n");
            $connection->send("$tag OK LOGOUT completed\r\n");
            $connection->close();
            break;
            
        default:
            $connection->send("$tag BAD Command not implemented\r\n");
    }
};

function handleImapLogin($connection, $tag, $args) {
    // Parse: LOGIN "username" "password"
    if (preg_match('/"([^"]+)"\s+"([^"]+)"/', $args, $matches)) {
        $email = $matches[1];
        $password = $matches[2];
        
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, password FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                $connection->imap_state = 'AUTHENTICATED';
                $connection->imap_user = $user;
                $connection->send("$tag OK LOGIN completed\r\n");
            } else {
                $connection->send("$tag NO LOGIN failed\r\n");
            }
        } catch (Exception $e) {
            $connection->send("$tag NO LOGIN failed\r\n");
        }
    } else {
        $connection->send("$tag BAD LOGIN syntax error\r\n");
    }
}

function handleImapSelect($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    $folder = trim($args, '"');
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM emails WHERE user_id = ? AND folder = ?");
        $stmt->execute([$connection->imap_user['id'], strtolower($folder)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $connection->send("* {$result['count']} EXISTS\r\n");
        $connection->send("* 0 RECENT\r\n");
        $connection->send("$tag OK SELECT completed\r\n");
        $connection->imap_selected_folder = strtolower($folder);
    } catch (Exception $e) {
        $connection->send("$tag NO SELECT failed\r\n");
    }
}

function handleImapList($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    $connection->send('* LIST () "/" "INBOX"' . "\r\n");
    $connection->send('* LIST () "/" "Sent"' . "\r\n");
    $connection->send('* LIST () "/" "Drafts"' . "\r\n");
    $connection->send('* LIST () "/" "Trash"' . "\r\n");
    $connection->send("$tag OK LIST completed\r\n");
}

function handleImapFetch($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    // Simple FETCH implementation
    // Parse: FETCH sequence items
    // Example: FETCH 1:* (FLAGS BODY[HEADER.FIELDS (FROM SUBJECT DATE)])
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id, from_email, subject, received_at, body, is_read
            FROM emails 
            WHERE user_id = ? AND folder = ?
            ORDER BY received_at DESC
            LIMIT 100
        ");
        
        $folder = $connection->imap_selected_folder ?? 'inbox';
        $stmt->execute([$connection->imap_user['id'], $folder]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $seq = 1;
        foreach ($emails as $email) {
            $flags = $email['is_read'] ? '\Seen' : '';
            $connection->send("* $seq FETCH (FLAGS ($flags) BODY[HEADER] {" . strlen($email['body']) . "}\r\n");
            $connection->send("From: {$email['from_email']}\r\n");
            $connection->send("Subject: {$email['subject']}\r\n");
            $connection->send("Date: {$email['received_at']}\r\n");
            $connection->send("\r\n)\r\n");
            $seq++;
        }
        
        $connection->send("$tag OK FETCH completed\r\n");
    } catch (Exception $e) {
        $connection->send("$tag NO FETCH failed\r\n");
    }
}

echo "Starting Mail Server...\n";
echo "SMTP listening on port 25\n";
echo "IMAP listening on port 143\n";

Worker::runAll();
