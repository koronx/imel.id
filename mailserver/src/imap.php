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

// IMAP Server
$imap_worker = new Worker("tcp://0.0.0.0:143");
$imap_worker->name = 'IMAP Server';
$imap_worker->count = 4;

$imap_worker->onConnect = function($connection) {
    $connection->send("* OK IMAP4rev1 Service Ready\r\n");
    $connection->imap_state = 'NOT_AUTHENTICATED';
    $connection->imap_user = null;
    debugLog("[IMAP] New connection from", $connection->getRemoteIp());
};

$imap_worker->onMessage = function($connection, $data) {
    $data = trim($data);
    debugLog("[IMAP] Received", $data);
    
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
            $connection->send("* CAPABILITY IMAP4rev1 IDLE NAMESPACE ID AUTH=PLAIN\r\n");
            $connection->send("$tag OK CAPABILITY completed\r\n");
            debugLog("[IMAP] CAPABILITY sent");
            break;
        
        case 'STARTTLS':
            // TLS not implemented, but respond to prevent connection drop
            $connection->send("$tag NO STARTTLS not available\r\n");
            debugLog("[IMAP] STARTTLS rejected");
            break;
        
        case 'AUTHENTICATE':
            $connection->send("$tag NO AUTHENTICATE not supported, use LOGIN\r\n");
            debugLog("[IMAP] AUTHENTICATE rejected");
            break;
            
        case 'LOGIN':
            handleImapLogin($connection, $tag, $args);
            break;
        
        case 'NAMESPACE':
            $connection->send('* NAMESPACE (("" "/")) NIL NIL' . "\r\n");
            $connection->send("$tag OK NAMESPACE completed\r\n");
            debugLog("[IMAP] NAMESPACE sent");
            break;
        
        case 'ID':
            // Outlook sends ID command, respond with server ID
            $connection->send('* ID ("name" "imel.id" "version" "1.0")' . "\r\n");
            $connection->send("$tag OK ID completed\r\n");
            debugLog("[IMAP] ID sent");
            break;
            
        case 'SELECT':
        case 'EXAMINE':
            handleImapSelect($connection, $tag, $args);
            break;
        
        case 'STATUS':
            handleImapStatus($connection, $tag, $args);
            break;
            
        case 'LIST':
            handleImapList($connection, $tag, $args);
            break;
        
        case 'LSUB':
            handleImapLsub($connection, $tag, $args);
            break;
            
        case 'FETCH':
            handleImapFetch($connection, $tag, $args);
            break;
        
        case 'SEARCH':
            handleImapSearch($connection, $tag, $args);
            break;
        
        case 'UID':
            // UID command variants (UID FETCH, UID SEARCH, etc)
            handleImapUid($connection, $tag, $args);
            break;
        
        case 'IDLE':
            $connection->send("+ idling\r\n");
            debugLog("[IMAP] IDLE started");
            break;
        
        case 'DONE':
            $connection->send("$tag OK IDLE terminated\r\n");
            debugLog("[IMAP] IDLE terminated");
            break;
        
        case 'NOOP':
            $connection->send("$tag OK NOOP completed\r\n");
            debugLog("[IMAP] NOOP");
            break;
        
        case 'CHECK':
            $connection->send("$tag OK CHECK completed\r\n");
            debugLog("[IMAP] CHECK");
            break;
            
        case 'LOGOUT':
            $connection->send("* BYE IMAP4rev1 Server logging out\r\n");
            $connection->send("$tag OK LOGOUT completed\r\n");
            debugLog("[IMAP] LOGOUT");
            $connection->close();
            break;
            
        default:
            $connection->send("$tag BAD Command not implemented: $command\r\n");
            debugLog("[IMAP] Unknown command", $command);
    }
};

$imap_worker->onClose = function($connection) {
    debugLog("[IMAP] Connection closed from", $connection->getRemoteIp());
};

function handleImapLogin($connection, $tag, $args) {
    debugLog("[IMAP] Processing LOGIN command", ['args' => $args]);
    
    // Parse: LOGIN "username" "password" or LOGIN username password
    if (preg_match('/"([^"]+)"\s+"([^"]+)"/', $args, $matches)) {
        $email = $matches[1];
        $password = $matches[2];
    } elseif (preg_match('/(\S+)\s+"([^"]+)"/', $args, $matches)) {
        // Handle: LOGIN email "password"
        $email = $matches[1];
        $password = $matches[2];
    } elseif (preg_match('/"([^"]+)"\s+(\S+)/', $args, $matches)) {
        // Handle: LOGIN "email" password
        $email = $matches[1];
        $password = $matches[2];
    } elseif (preg_match('/(\S+)\s+(\S+)/', $args, $matches)) {
        // Handle: LOGIN email password (no quotes)
        $email = $matches[1];
        $password = $matches[2];
    } else {
        $connection->send("$tag BAD LOGIN syntax error\r\n");
        debugLog("[IMAP] Login syntax error", $args);
        return;
    }
    
    debugLog("[IMAP] Attempting login", ['email' => $email]);
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, email, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $connection->imap_state = 'AUTHENTICATED';
            $connection->imap_user = $user;
            $connection->send("$tag OK [CAPABILITY IMAP4rev1] LOGIN completed\r\n");
            debugLog("[IMAP] Login successful", ['email' => $email, 'user_id' => $user['id']]);
        } else {
            $connection->send("$tag NO [AUTHENTICATIONFAILED] LOGIN failed\r\n");
            debugLog("[IMAP] Login failed - invalid credentials", $email);
        }
    } catch (Exception $e) {
        $connection->send("$tag NO LOGIN failed\r\n");
        debugLog("[IMAP] Login error", $e->getMessage());
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
        
        // Get count and max ID (for UIDNEXT)
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as count,
                COALESCE(MAX(id), 0) as max_id,
                COUNT(CASE WHEN is_read = false THEN 1 END) as unseen
            FROM emails 
            WHERE user_id = ? AND folder = ?
        ");
        $stmt->execute([$connection->imap_user['id'], strtolower($folder)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $count = $result['count'];
        $uidnext = $result['max_id'] + 1;
        $unseen = $result['unseen'];
        
        // Send EXISTS and RECENT
        $connection->send("* $count EXISTS\r\n");
        $connection->send("* 0 RECENT\r\n");
        
        // Send FLAGS
        $connection->send("* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\r\n");
        $connection->send("* OK [PERMANENTFLAGS (\\Deleted \\Seen \\*)] Limited\r\n");
        
        // Send UNSEEN (first unseen message sequence number)
        if ($unseen > 0) {
            $stmt = $db->prepare("
                SELECT id FROM emails 
                WHERE user_id = ? AND folder = ? AND is_read = false 
                ORDER BY id ASC LIMIT 1
            ");
            $stmt->execute([$connection->imap_user['id'], strtolower($folder)]);
            $firstUnseen = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($firstUnseen) {
                // Find sequence number
                $stmt = $db->prepare("
                    SELECT COUNT(*) as seq FROM emails 
                    WHERE user_id = ? AND folder = ? AND id <= ?
                ");
                $stmt->execute([$connection->imap_user['id'], strtolower($folder), $firstUnseen['id']]);
                $seqResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $connection->send("* OK [UNSEEN {$seqResult['seq']}] First unseen\r\n");
            }
        }
        
        // Send UIDVALIDITY and UIDNEXT
        $connection->send("* OK [UIDVALIDITY 1] UIDs valid\r\n");
        $connection->send("* OK [UIDNEXT $uidnext] Predicted next UID\r\n");
        
        // Complete
        $connection->send("$tag OK [READ-WRITE] SELECT completed\r\n");
        $connection->imap_selected_folder = strtolower($folder);
        
        debugLog("[IMAP] Folder selected", [
            'folder' => $folder, 
            'count' => $count, 
            'unseen' => $unseen,
            'uidnext' => $uidnext
        ]);
    } catch (Exception $e) {
        $connection->send("$tag NO SELECT failed\r\n");
        debugLog("[IMAP] Select error", $e->getMessage());
    }
}

function handleImapStatus($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    // Parse: STATUS "INBOX" (MESSAGES UNSEEN)
    if (preg_match('/"?([^"]+)"?\s+\(([^)]+)\)/', $args, $matches)) {
        $folder = strtolower(trim($matches[1], '"'));
        $items = $matches[2];
        
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as messages,
                    COUNT(CASE WHEN is_read = false THEN 1 END) as unseen
                FROM emails 
                WHERE user_id = ? AND folder = ?
            ");
            $stmt->execute([$connection->imap_user['id'], $folder]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $response = [];
            if (stripos($items, 'MESSAGES') !== false) {
                $response[] = 'MESSAGES ' . $result['messages'];
            }
            if (stripos($items, 'UNSEEN') !== false) {
                $response[] = 'UNSEEN ' . $result['unseen'];
            }
            if (stripos($items, 'RECENT') !== false) {
                $response[] = 'RECENT 0';
            }
            if (stripos($items, 'UIDNEXT') !== false) {
                $response[] = 'UIDNEXT ' . ($result['messages'] + 1);
            }
            
            $connection->send('* STATUS "' . strtoupper($folder) . '" (' . implode(' ', $response) . ')' . "\r\n");
            $connection->send("$tag OK STATUS completed\r\n");
            debugLog("[IMAP] STATUS", ['folder' => $folder, 'messages' => $result['messages'], 'unseen' => $result['unseen']]);
        } catch (Exception $e) {
            $connection->send("$tag NO STATUS failed\r\n");
            debugLog("[IMAP] STATUS error", $e->getMessage());
        }
    } else {
        $connection->send("$tag BAD STATUS syntax error\r\n");
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
    debugLog("[IMAP] List completed");
}

function handleImapLsub($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    // LSUB - List subscribed mailboxes (same as LIST for now)
    $connection->send('* LSUB () "/" "INBOX"' . "\r\n");
    $connection->send('* LSUB () "/" "Sent"' . "\r\n");
    $connection->send('* LSUB () "/" "Drafts"' . "\r\n");
    $connection->send('* LSUB () "/" "Trash"' . "\r\n");
    $connection->send("$tag OK LSUB completed\r\n");
    debugLog("[IMAP] LSUB completed");
}

function handleImapFetch($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    debugLog("[IMAP] FETCH command", $args);
    
    // Parse FETCH command: sequence-set items
    // Example: FETCH 1:* (FLAGS)
    // Example: FETCH 1 (BODY[HEADER.FIELDS (FROM SUBJECT DATE)])
    // Example: FETCH 1:* (UID FLAGS BODY.PEEK[HEADER.FIELDS (DATE FROM TO SUBJECT)])
    
    if (!preg_match('/^([0-9:*,]+)\s+(.+)$/i', $args, $matches)) {
        $connection->send("$tag BAD Invalid FETCH syntax\r\n");
        return;
    }
    
    $sequenceSet = $matches[1];
    $items = $matches[2];
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id, from_email, to_email, subject, received_at, body, html_body, is_read
            FROM emails 
            WHERE user_id = ? AND folder = ?
            ORDER BY id ASC
        ");
        
        $folder = $connection->imap_selected_folder ?? 'inbox';
        $stmt->execute([$connection->imap_user['id'], $folder]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($emails)) {
            $connection->send("$tag OK FETCH completed\r\n");
            debugLog("[IMAP] FETCH completed - no emails");
            return;
        }
        
        // Parse sequence set (1:*, 1:5, 1,3,5, etc)
        $sequences = parseSequenceSet($sequenceSet, count($emails));
        
        foreach ($sequences as $seq) {
            if ($seq < 1 || $seq > count($emails)) continue;
            
            $email = $emails[$seq - 1];
            $flags = $email['is_read'] ? '\\Seen' : '';
            
            // Build FETCH response based on requested items
            $response = "* $seq FETCH (";
            $parts = [];
            
            // Check what items are requested
            $itemsUpper = strtoupper($items);
            
            if (strpos($itemsUpper, 'UID') !== false) {
                $parts[] = "UID {$email['id']}";
            }
            
            if (strpos($itemsUpper, 'FLAGS') !== false) {
                $parts[] = "FLAGS ($flags)";
            }
            
            if (strpos($itemsUpper, 'INTERNALDATE') !== false) {
                $date = date('d-M-Y H:i:s O', strtotime($email['received_at']));
                $parts[] = "INTERNALDATE \"$date\"";
            }
            
            if (strpos($itemsUpper, 'RFC822.SIZE') !== false || strpos($itemsUpper, 'SIZE') !== false) {
                $size = strlen($email['body']);
                $parts[] = "RFC822.SIZE $size";
            }
            
            // Handle BODY requests
            if (preg_match('/BODY\.PEEK\[HEADER\.FIELDS\s+\(([^)]+)\)\]/i', $items, $headerMatches) ||
                preg_match('/BODY\[HEADER\.FIELDS\s+\(([^)]+)\)\]/i', $items, $headerMatches)) {
                
                $fields = strtoupper($headerMatches[1]);
                $header = buildHeaderFields($email, explode(' ', $fields));
                $parts[] = "BODY[HEADER.FIELDS (" . $headerMatches[1] . ")] {" . strlen($header) . "}\r\n$header";
            } elseif (strpos($itemsUpper, 'BODY[HEADER]') !== false || strpos($itemsUpper, 'BODY.PEEK[HEADER]') !== false) {
                $header = buildFullHeader($email);
                $parts[] = "BODY[HEADER] {" . strlen($header) . "}\r\n$header";
            } elseif (strpos($itemsUpper, 'BODY[]') !== false || strpos($itemsUpper, 'RFC822') !== false) {
                $fullMessage = buildFullMessage($email);
                $parts[] = "BODY[] {" . strlen($fullMessage) . "}\r\n$fullMessage";
            }
            
            $response .= implode(' ', $parts);
            $response .= ")\r\n";
            
            $connection->send($response);
        }
        
        $connection->send("$tag OK FETCH completed\r\n");
        debugLog("[IMAP] FETCH completed", ['sequences' => count($sequences), 'total_emails' => count($emails)]);
    } catch (Exception $e) {
        $connection->send("$tag NO FETCH failed\r\n");
        debugLog("[IMAP] FETCH error", $e->getMessage());
    }
}

function parseSequenceSet($set, $maxSeq) {
    $sequences = [];
    
    if ($set === '*') {
        return range(1, $maxSeq);
    }
    
    $parts = explode(',', $set);
    foreach ($parts as $part) {
        if (strpos($part, ':') !== false) {
            list($start, $end) = explode(':', $part);
            $start = ($start === '*') ? $maxSeq : (int)$start;
            $end = ($end === '*') ? $maxSeq : (int)$end;
            $sequences = array_merge($sequences, range($start, $end));
        } else {
            $sequences[] = ($part === '*') ? $maxSeq : (int)$part;
        }
    }
    
    return array_unique($sequences);
}

function parseUidSet($set) {
    $uids = [];
    
    $parts = explode(',', $set);
    foreach ($parts as $part) {
        if (strpos($part, ':') !== false) {
            list($start, $end) = explode(':', $part);
            // For UID ranges, we can't predict max, so just use large number
            $start = ($start === '*') ? 999999 : (int)$start;
            $end = ($end === '*') ? 999999 : (int)$end;
            // Don't generate range for UIDs, just mark as range
            // For simplicity, we'll query database with BETWEEN
            $uids[] = ['range' => true, 'start' => $start, 'end' => $end];
        } else {
            $uids[] = ($part === '*') ? 999999 : (int)$part;
        }
    }
    
    return $uids;
}

function buildHeaderFields($email, $fields) {
    $header = "";
    foreach ($fields as $field) {
        $field = strtoupper(trim($field));
        switch ($field) {
            case 'FROM':
                $header .= "From: {$email['from_email']}\r\n";
                break;
            case 'TO':
                $header .= "To: {$email['to_email']}\r\n";
                break;
            case 'SUBJECT':
                $header .= "Subject: {$email['subject']}\r\n";
                break;
            case 'DATE':
                $date = date('r', strtotime($email['received_at']));
                $header .= "Date: $date\r\n";
                break;
            case 'SENDER':
                $header .= "Sender: {$email['from_email']}\r\n";
                break;
            case 'REPLY-TO':
                $header .= "Reply-To: {$email['from_email']}\r\n";
                break;
            case 'REFERENCES':
            case 'THREAD-TOPIC':
            case 'IN-REPLY-TO':
                // Optional headers, leave empty
                break;
        }
    }
    $header .= "\r\n";
    return $header;
}

function buildFullHeader($email) {
    $header = "From: {$email['from_email']}\r\n";
    $header .= "To: {$email['to_email']}\r\n";
    $header .= "Subject: {$email['subject']}\r\n";
    $date = date('r', strtotime($email['received_at']));
    $header .= "Date: $date\r\n";
    $header .= "Message-ID: <{$email['id']}@imel.id>\r\n";
    $header .= "\r\n";
    return $header;
}

function buildFullMessage($email) {
    $message = buildFullHeader($email);
    $message .= $email['body'];
    return $message;
}

function handleImapSearch($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    if (!$connection->imap_selected_folder) {
        $connection->send("$tag NO No folder selected\r\n");
        return;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Simple implementation: return all message sequence numbers
        // Parse basic SEARCH criteria (ALL, SINCE date, etc.)
        $args = strtoupper(trim($args));
        
        $stmt = $db->prepare("
            SELECT id FROM emails 
            WHERE user_id = ? AND folder = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$connection->imap_user['id'], $connection->imap_selected_folder]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build sequence numbers
        $sequences = [];
        foreach ($results as $idx => $row) {
            $sequences[] = ($idx + 1); // sequence number is 1-based
        }
        
        $connection->send("* SEARCH " . implode(' ', $sequences) . "\r\n");
        $connection->send("$tag OK SEARCH completed\r\n");
        debugLog("[IMAP] SEARCH", ['folder' => $connection->imap_selected_folder, 'results' => count($sequences)]);
    } catch (Exception $e) {
        $connection->send("$tag NO SEARCH failed\r\n");
        debugLog("[IMAP] SEARCH error", $e->getMessage());
    }
}

function handleImapUid($connection, $tag, $args) {
    if ($connection->imap_state !== 'AUTHENTICATED') {
        $connection->send("$tag NO Not authenticated\r\n");
        return;
    }
    
    // Parse UID subcommand
    $parts = explode(' ', trim($args), 2);
    $subcommand = strtoupper($parts[0]);
    $subargs = isset($parts[1]) ? $parts[1] : '';
    
    switch ($subcommand) {
        case 'SEARCH':
            handleImapUidSearch($connection, $tag, $subargs);
            break;
        
        case 'FETCH':
            handleImapUidFetch($connection, $tag, $subargs);
            break;
        
        default:
            $connection->send("$tag BAD Unknown UID command\r\n");
            debugLog("[IMAP] Unknown UID command", $subcommand);
    }
}

function handleImapUidSearch($connection, $tag, $args) {
    if (!$connection->imap_selected_folder) {
        $connection->send("$tag NO No folder selected\r\n");
        return;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Simple implementation: return all UIDs
        // Parse basic SEARCH criteria (1:*, SINCE date, etc.)
        $stmt = $db->prepare("
            SELECT id FROM emails 
            WHERE user_id = ? AND folder = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$connection->imap_user['id'], $connection->imap_selected_folder]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // UIDs are email IDs
        $uids = array_column($results, 'id');
        
        $connection->send("* SEARCH " . implode(' ', $uids) . "\r\n");
        $connection->send("$tag OK UID SEARCH completed\r\n");
        debugLog("[IMAP] UID SEARCH", ['folder' => $connection->imap_selected_folder, 'results' => count($uids)]);
    } catch (Exception $e) {
        $connection->send("$tag NO UID SEARCH failed\r\n");
        debugLog("[IMAP] UID SEARCH error", $e->getMessage());
    }
}

function handleImapUidFetch($connection, $tag, $args) {
    if (!$connection->imap_selected_folder) {
        $connection->send("$tag NO No folder selected\r\n");
        return;
    }
    
    try {
        // Parse: <uid-set> <data-items>
        // Example: "40,45 (FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)])"
        $parts = preg_split('/\s+/', $args, 2);
        $uidSet = $parts[0];
        $items = isset($parts[1]) ? $parts[1] : '';
        
        // Parse UID set (simple: just split by comma)
        $uids = array_map('intval', explode(',', str_replace(':', ',', $uidSet)));
        
        $db = Database::getInstance()->getConnection();
        
        // Get sequence number mapping for this folder
        $stmt = $db->prepare("
            SELECT id FROM emails 
            WHERE user_id = ? AND folder = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$connection->imap_user['id'], $connection->imap_selected_folder]);
        $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($uids as $uid) {
            $stmt = $db->prepare("
                SELECT * FROM emails 
                WHERE id = ? AND user_id = ? AND folder = ?
            ");
            $stmt->execute([$uid, $connection->imap_user['id'], $connection->imap_selected_folder]);
            $email = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$email) continue;
            
            // Find sequence number for this UID
            $seqNum = array_search($uid, $allIds);
            if ($seqNum === false) continue;
            $seqNum = $seqNum + 1; // 1-based
            
            // Build FETCH response (use sequence number in untagged response)
            $response = "* $seqNum FETCH (UID $uid";
            
            if (stripos($items, 'FLAGS') !== false) {
                $flags = $email['is_read'] ? '\\Seen' : '';
                $response .= " FLAGS ($flags)";
            }
            
            if (stripos($items, 'RFC822.SIZE') !== false) {
                $size = strlen($email['body']);
                $response .= " RFC822.SIZE $size";
            }
            
            if (stripos($items, 'INTERNALDATE') !== false) {
                $date = date('d-M-Y H:i:s O', strtotime($email['received_at']));
                $response .= " INTERNALDATE \"$date\"";
            }
            
            if (stripos($items, 'BODYSTRUCTURE') !== false) {
                // Simple BODYSTRUCTURE for text/plain or text/html
                $bodyType = !empty($email['html_body']) ? 'HTML' : 'PLAIN';
                $bodySize = strlen($email['body']);
                $response .= " BODYSTRUCTURE (\"TEXT\" \"$bodyType\" NIL NIL NIL \"7BIT\" $bodySize 0)";
            }
            
            if (stripos($items, 'ENVELOPE') !== false) {
                // ENVELOPE: date, subject, from, sender, reply-to, to, cc, bcc, in-reply-to, message-id
                $date = date('r', strtotime($email['received_at']));
                $subject = $email['subject'] ?: 'NIL';
                $from = '"' . $email['from_email'] . '" NIL "' . explode('@', $email['from_email'])[0] . '" "' . explode('@', $email['from_email'])[1] . '"';
                $to = '"' . $email['to_email'] . '" NIL "' . explode('@', $email['to_email'])[0] . '" "' . explode('@', $email['to_email'])[1] . '"';
                
                $response .= " ENVELOPE (\"$date\" \"$subject\" (($from)) (($from)) (($from)) (($to)) NIL NIL NIL NIL)";
            }
            
            if (stripos($items, 'BODY.PEEK[TEXT]') !== false || stripos($items, 'BODY[TEXT]') !== false) {
                $body = !empty($email['html_body']) ? $email['html_body'] : $email['body'];
                $response .= " BODY[TEXT] {" . strlen($body) . "}\r\n";
                $response .= $body;
            } elseif (stripos($items, 'BODY.PEEK[HEADER.FIELDS') !== false || 
                stripos($items, 'BODY[HEADER.FIELDS') !== false) {
                preg_match('/HEADER\.FIELDS\s*\(([^)]+)\)/i', $items, $matches);
                $fields = isset($matches[1]) ? explode(' ', $matches[1]) : [];
                $header = buildHeaderFields($email, $fields);
                $response .= " BODY[HEADER.FIELDS (" . implode(' ', $fields) . ")] {" . strlen($header) . "}\r\n";
                $response .= $header;
            } elseif (stripos($items, 'BODY.PEEK[HEADER]') !== false || 
                      stripos($items, 'BODY[HEADER]') !== false) {
                $header = buildFullHeader($email);
                $response .= " BODY[HEADER] {" . strlen($header) . "}\r\n";
                $response .= $header;
            } elseif (stripos($items, 'BODY.PEEK[]') !== false || 
                      stripos($items, 'BODY[]') !== false) {
                $fullMsg = buildFullMessage($email);
                $response .= " BODY[] {" . strlen($fullMsg) . "}\r\n";
                $response .= $fullMsg;
            }
            
            $response .= ")\r\n";
            $connection->send($response);
        }
        
        $connection->send("$tag OK UID FETCH completed\r\n");
        debugLog("[IMAP] UID FETCH", ['uids' => $uidSet, 'count' => count($uids)]);
    } catch (Exception $e) {
        $connection->send("$tag NO UID FETCH failed\r\n");
        debugLog("[IMAP] UID FETCH error", $e->getMessage());
    }
}

echo "Starting IMAP Server...\n";
echo "IMAP listening on port 143\n";

Worker::runAll();
