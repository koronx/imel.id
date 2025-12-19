<?php
// Start output buffering to prevent header issues
ob_start();
session_start();

// Load Composer autoload - try multiple paths
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

// Mail configuration
$MAIL_HOST = getenv('MAIL_HOST') ?: 'mailserver';
$MAIL_PORT = getenv('MAIL_PORT') ?: '25';

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
        die("Database connection failed: " . $e->getMessage());
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get current user
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Routing
$page = $_GET['page'] ?? 'login';

if (!isLoggedIn() && !in_array($page, ['login', 'register'])) {
    $page = 'login';
}

// Handle download separately (no HTML template)
if ($page === 'download' && isLoggedIn()) {
    include 'pages/download.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>imel.id - Layanan Email Custom</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
        }
        
        .header {
            background: #2c3e50;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
        }
        
        .header .user-info {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .login-container, .register-container {
            max-width: 400px;
            margin: 4rem auto;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .btn {
            background: #3498db;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .mail-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1rem;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .sidebar {
            background: #34495e;
            color: white;
            padding: 1rem;
        }
        
        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 0.5rem;
        }
        
        .sidebar a:hover, .sidebar a.active {
            background: #2c3e50;
        }
        
        .mail-content {
            padding: 2rem;
        }
        
        .email-list {
            list-style: none;
        }
        
        .email-item {
            border-bottom: 1px solid #eee;
            padding: 1rem;
            cursor: pointer;
        }
        
        .email-item:hover {
            background: #f9f9f9;
        }
        
        .email-item.unread {
            font-weight: bold;
            background: #ecf0f1;
        }
        
        .email-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .email-subject {
            font-weight: 500;
        }
        
        .email-date {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .email-from {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .attachment-list {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .attachment-item {
            padding: 0.5rem;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .compose-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
        }
        
        .email-detail {
            background: white;
            padding: 2rem;
            border-radius: 8px;
        }
        
        .email-detail-header {
            border-bottom: 2px solid #eee;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        
        .email-detail-body {
            line-height: 1.6;
        }
        
        /* Quill Editor Styles */
        #editor-container {
            height: 400px;
            background: white;
        }
        
        .ql-editor {
            min-height: 350px;
        }
    </style>
    
    <!-- Quill Editor CSS & JS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <div class="header">
            <h1>📧 imel.id</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars(getCurrentUser()['email']); ?></span>
                <a href="?page=settings" class="btn">⚙️ Pengaturan</a>
                <a href="?page=logout" class="btn btn-danger">Keluar</a>
            </div>
        </div>
    <?php endif; ?>
    
    <?php
    // Handle different pages
    switch ($page) {
        case 'login':
            include 'pages/login.php';
            break;
        case 'register':
            include 'pages/register.php';
            break;
        case 'logout':
            include 'pages/logout.php';
            break;
        case 'inbox':
            include 'pages/inbox.php';
            break;
        case 'compose':
            include 'pages/compose.php';
            break;
        case 'view':
            include 'pages/view.php';
            break;
        case 'send':
            include 'pages/send.php';
            break;
        case 'download':
            include 'pages/download.php';
            break;
        case 'settings':
            include 'pages/settings.php';
            break;
        case 'change-password':
            include 'pages/change-password.php';
            break;
        case 'delete-bulk':
            include 'pages/delete-bulk.php';
            break;
        default:
            if (isLoggedIn()) {
                include 'pages/inbox.php';
            } else {
                include 'pages/login.php';
            }
    }
    
    // Flush output buffer
    ob_end_flush();
    ?>
</body>
</html>
