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
            debugLog("[DB] Connected to database", "$host:$port/$dbname");
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

function getDomainFromEmail($email) {
    $parts = explode('@', $email);
    return isset($parts[1]) ? $parts[1] : '';
}

function isLocalDomain($domain) {
    return strtolower($domain) === 'imel.id';
}

function userExists($email) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        debugLog("[DB] Error checking user", $e->getMessage());
        return false;
    }
}

// SMTP Submission Server Port 587 (STARTTLS - optional encryption)
$submission_worker = new Worker("tcp://0.0.0.0:587");
$submission_worker->name = 'SMTP Submission 587';
$submission_worker->count = 2;

$submission_worker->onConnect = function($connection) {
    debugLog("[SUBMISSION:587] New connection from: " . $connection->getRemoteIp());
    $connection->send("220 imel.id ESMTP Submission Ready\r\n");
    $connection->smtp_state = 'INIT';
    $connection->smtp_authenticated = false;
    $connection->smtp_user = null;
    $connection->smtp_from = '';
    $connection->smtp_to = [];
    $connection->smtp_data = '';
    $connection->smtp_tls = false;
};

// SMTPS Server Port 465 (SSL from start)
$smtps_worker = new Worker("tcp://0.0.0.0:465");
$smtps_worker->name = 'SMTPS 465';
$smtps_worker->count = 2;
$smtps_worker->transport = 'ssl';

// SSL context for port 465
$smtps_worker->context = [
    'ssl' => [
        'local_cert' => '/app/ssl/server.crt',
        'local_pk' => '/app/ssl/server.key',
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]
];

$smtps_worker->onConnect = function($connection) {
    debugLog("[SMTPS:465] New connection from: " . $connection->getRemoteIp());
    $connection->send("220 imel.id ESMTP Submission Ready (SSL)\r\n");
    $connection->smtp_state = 'INIT';
    $connection->smtp_authenticated = false;
    $connection->smtp_user = null;
    $connection->smtp_from = '';
    $connection->smtp_to = [];
    $connection->smtp_data = '';
    $connection->smtp_tls = true; // Already using SSL
};

function handleSmtpMessage($connection, $data) {
    // If in DATA mode, process line by line
    if (isset($connection->smtp_state) && $connection->smtp_state === 'DATA') {
        // Accumulate data first
        $connection->smtp_data .= $data;
        
        // Check if we have end-of-data marker (line with only dot)
        // Format: \r\n.\r\n or just contains "\r\n.\r\n" or ends with "\n.\n"
        if (strpos($connection->smtp_data, "\r\n.\r\n") !== false || 
            strpos($connection->smtp_data, "\n.\n") !== false ||
            preg_match('/\r?\n\.\r?\n/', $connection->smtp_data)) {
            
            // Remove end marker and extract email content
            $emailContent = preg_replace('/\r?\n\.\r?\n.*$/s', '', $connection->smtp_data);
            
            // Parse headers and body
            $parts = preg_split('/\r?\n\r?\n/', $emailContent, 2);
            $headers = isset($parts[0]) ? $parts[0] : '';
            $body = isset($parts[1]) ? $parts[1] : '';
            
            // Extract subject from headers
            $subject = '';
            if (preg_match('/^Subject:\s*(.+?)$/im', $headers, $matches)) {
                $subject = trim($matches[1]);
            }
            
            // Extract CC from headers
            $cc = '';
            if (preg_match('/^Cc:\s*(.+?)$/im', $headers, $matches)) {
                $cc = trim($matches[1]);
            }
            
            // Check if body is HTML
            $isHtml = (stripos($headers, 'Content-Type: text/html') !== false);
            
            $emailData = [
                'from' => $connection->smtp_from,
                'to' => implode(', ', $connection->smtp_to),
                'cc' => $cc,
                'subject' => $subject ?: '(No Subject)',
                'body' => $isHtml ? '' : $body,
                'html_body' => $isHtml ? $body : '',
                'attachments' => [],
                'received_at' => date('Y-m-d H:i:s')
            ];
            
            try {
                $redis = RedisQueue::getInstance();
                $redis->push('email_queue', $emailData);
                debugLog("[SUBMISSION] Email queued", ['from' => $connection->smtp_from, 'to' => $connection->smtp_to, 'subject' => $subject]);
                $connection->send("250 OK: Message queued for delivery\r\n");
            } catch (Exception $e) {
                debugLog("[SUBMISSION] Failed to queue email", $e->getMessage());
                $connection->send("451 Temporary failure, please try again later\r\n");
            }
            
            $connection->smtp_state = 'RCPT';
            $connection->smtp_data = '';
        }
        return;
    }
    
    $data = trim($data);
    $command = strtoupper(strtok($data, ' '));
    
    debugLog("[SUBMISSION] Command", $command);
    
    switch ($command) {
        case 'EHLO':
        case 'HELO':
            $connection->send("250-imel.id Hello\r\n");
            if (!isset($connection->smtp_tls) || !$connection->smtp_tls) {
                $connection->send("250-STARTTLS\r\n");
            }
            $connection->send("250-AUTH PLAIN LOGIN\r\n");
            $connection->send("250-SIZE 10240000\r\n");
            $connection->send("250 8BITMIME\r\n");
            $connection->smtp_state = 'HELO';
            break;
        
        case 'STARTTLS':
            if (isset($connection->smtp_tls) && $connection->smtp_tls) {
                $connection->send("503 TLS already active\r\n");
            } else {
                // STARTTLS not fully implemented, suggest using port 465
                $connection->send("454 TLS not available - use port 465 for SSL\r\n");
            }
            break;
            
        case 'AUTH':
            if (preg_match('/AUTH\s+(PLAIN|LOGIN)\s*(.*)$/i', $data, $matches)) {
                $authType = strtoupper($matches[1]);
                $authData = trim($matches[2] ?? '');
                
                if ($authType === 'PLAIN' && !empty($authData)) {
                    // AUTH PLAIN with inline credentials
                    $decoded = base64_decode($authData);
                    $parts = explode("\0", $decoded);
                    $username = $parts[1] ?? '';
                    $password = $parts[2] ?? '';
                    
                    if (authenticateUser($username, $password, $connection)) {
                        $connection->send("235 Authentication successful\r\n");
                        debugLog("[SUBMISSION] Auth successful", $username);
                    } else {
                        $connection->send("535 Authentication failed\r\n");
                        debugLog("[SUBMISSION] Auth failed", $username);
                    }
                } elseif ($authType === 'LOGIN') {
                    // AUTH LOGIN - multi-step
                    $connection->send("334 " . base64_encode('Username:') . "\r\n");
                    $connection->smtp_state = 'AUTH_USERNAME';
                } else {
                    $connection->send("504 Authentication mechanism not supported\r\n");
                }
            } else {
                $connection->send("501 Syntax error in parameters\r\n");
            }
            break;
        
        case 'MAIL':
            if (!$connection->smtp_authenticated) {
                $connection->send("530 Authentication required\r\n");
                break;
            }
            
            if (preg_match('/FROM:<(.+?)>/i', $data, $matches)) {
                $from = $matches[1];
                
                // Validate sender matches authenticated user
                if ($from !== $connection->smtp_user) {
                    $connection->send("550 Sender address must match authenticated user\r\n");
                    debugLog("[SUBMISSION] Sender mismatch", ['from' => $from, 'user' => $connection->smtp_user]);
                    break;
                }
                
                // Block reserved addresses
                $reservedAddresses = ['noreply@imel.id', 'system@imel.id'];
                if (in_array(strtolower($from), $reservedAddresses)) {
                    $connection->send("550 Sender address not allowed\r\n");
                    debugLog("[SUBMISSION] Reserved address blocked", $from);
                    break;
                }
                
                $connection->smtp_from = $from;
                $connection->send("250 OK\r\n");
                $connection->smtp_state = 'MAIL';
            } else {
                $connection->send("501 Syntax error in MAIL command\r\n");
            }
            break;
        
        case 'RCPT':
            if (!$connection->smtp_authenticated) {
                $connection->send("530 Authentication required\r\n");
                break;
            }
            
            if ($connection->smtp_state !== 'MAIL' && $connection->smtp_state !== 'RCPT') {
                $connection->send("503 Bad sequence of commands\r\n");
                break;
            }
            
            if (preg_match('/TO:<(.+?)>/i', $data, $matches)) {
                $to = $matches[1];
                $connection->smtp_to[] = $to;
                $connection->send("250 OK\r\n");
                $connection->smtp_state = 'RCPT';
            } else {
                $connection->send("501 Syntax error in RCPT command\r\n");
            }
            break;
        
        case 'DATA':
            if (!$connection->smtp_authenticated) {
                $connection->send("530 Authentication required\r\n");
                break;
            }
            
            if ($connection->smtp_state !== 'RCPT') {
                $connection->send("503 Bad sequence of commands\r\n");
                break;
            }
            
            $connection->send("354 Start mail input; end with <CRLF>.<CRLF>\r\n");
            $connection->smtp_state = 'DATA';
            $connection->smtp_data = '';
            break;
        
        case 'RSET':
            $connection->smtp_from = '';
            $connection->smtp_to = [];
            $connection->smtp_data = '';
            $connection->smtp_state = 'HELO';
            $connection->send("250 OK\r\n");
            break;
        
        case 'NOOP':
            $connection->send("250 OK\r\n");
            break;
        
        case 'QUIT':
            $connection->send("221 Bye\r\n");
            $connection->close();
            break;
            
        default:
            // Handle AUTH continuation (username/password steps)
            if ($connection->smtp_state === 'AUTH_USERNAME') {
                $connection->smtp_auth_username = base64_decode($data);
                $connection->send("334 " . base64_encode('Password:') . "\r\n");
                $connection->smtp_state = 'AUTH_PASSWORD';
            } elseif ($connection->smtp_state === 'AUTH_PASSWORD') {
                $password = base64_decode($data);
                if (authenticateUser($connection->smtp_auth_username, $password, $connection)) {
                    $connection->send("235 Authentication successful\r\n");
                    debugLog("[SUBMISSION] Auth successful", $connection->smtp_auth_username);
                } else {
                    $connection->send("535 Authentication failed\r\n");
                    debugLog("[SUBMISSION] Auth failed", $connection->smtp_auth_username);
                }
            } else {
                $connection->send("500 Command not recognized\r\n");
            }
    }
}

$submission_worker->onMessage = function($connection, $data) {
    handleSmtpMessage($connection, $data);
};

$smtps_worker->onMessage = function($connection, $data) {
    handleSmtpMessage($connection, $data);
};

function authenticateUser($email, $password, $connection) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, email, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $connection->smtp_authenticated = true;
            $connection->smtp_user = $user['email'];
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        debugLog("[SUBMISSION] Auth error", $e->getMessage());
        return false;
    }
}

echo "Starting SMTP Submission Server...\n";
echo "SMTP Submission listening on port 587 (no SSL)\n";
echo "SMTPS listening on port 465 (SSL/TLS)\n";

Worker::runAll();
