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
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Quill Editor CSS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <style>
        .brand-link {
            font-size: 1.25rem;
            font-weight: bold;
        }
        .email-item {
            cursor: pointer;
            transition: all 0.3s;
        }
        .email-item:hover {
            background-color: #f4f6f9;
        }
        .email-item.unread {
            background-color: #e3f2fd;
            font-weight: 600;
        }
        #editor-container {
            min-height: 300px;
            background: white;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
    <?php if (isLoggedIn()): ?>
    <div class="wrapper">
        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <!-- Left navbar links -->
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
                </li>
                <li class="nav-item d-none d-sm-inline-block">
                    <a href="?page=inbox" class="nav-link">Home</a>
                </li>
            </ul>

            <!-- Right navbar links -->
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="?page=compose">
                        <i class="fas fa-envelope"></i> Tulis Email
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#">
                        <i class="far fa-user"></i>
                        <span class="d-none d-sm-inline ml-1"><?php echo htmlspecialchars(getCurrentUser()['full_name']); ?></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="?page=settings" class="dropdown-item">
                            <i class="fas fa-cog mr-2"></i> Pengaturan
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="?page=logout" class="dropdown-item">
                            <i class="fas fa-sign-out-alt mr-2"></i> Keluar
                        </a>
                    </div>
                </li>
            </ul>
        </nav>

        <!-- Main Sidebar Container -->
        <aside class="main-sidebar sidebar-dark-primary elevation-4">
            <!-- Brand Logo -->
            <a href="?page=inbox" class="brand-link">
                <i class="fas fa-envelope-open-text ml-3"></i>
                <span class="brand-text font-weight-light ml-2">imel.id</span>
            </a>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Sidebar user panel -->
                <div class="user-panel mt-3 pb-3 mb-3 d-flex">
                    <div class="image">
                        <i class="fas fa-user-circle fa-2x text-white"></i>
                    </div>
                    <div class="info">
                        <a href="#" class="d-block"><?php echo htmlspecialchars(getCurrentUser()['email']); ?></a>
                    </div>
                </div>

                <!-- Sidebar Menu -->
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                        <li class="nav-item">
                            <a href="?page=compose" class="nav-link <?php echo $page === 'compose' ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-edit"></i>
                                <p>Tulis Email</p>
                            </a>
                        </li>
                        <li class="nav-header">FOLDER</li>
                        <li class="nav-item">
                            <a href="?page=inbox&folder=inbox" class="nav-link <?php echo ($page === 'inbox' && ($_GET['folder'] ?? 'inbox') === 'inbox') ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-inbox"></i>
                                <p>Kotak Masuk</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="?page=inbox&folder=sent" class="nav-link <?php echo ($page === 'inbox' && ($_GET['folder'] ?? '') === 'sent') ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-paper-plane"></i>
                                <p>Terkirim</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="?page=inbox&folder=drafts" class="nav-link <?php echo ($page === 'inbox' && ($_GET['folder'] ?? '') === 'drafts') ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-file-alt"></i>
                                <p>Draft</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="?page=inbox&folder=trash" class="nav-link <?php echo ($page === 'inbox' && ($_GET['folder'] ?? '') === 'trash') ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-trash"></i>
                                <p>Sampah</p>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
    <?php else: ?>
        <!-- Login/Register page without sidebar -->
        <div class="login-page" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh;">
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
    
    <?php if (isLoggedIn()): ?>
        </div><!-- /.content-wrapper -->
        
        <!-- Footer -->
        <footer class="main-footer">
            <strong>Copyright &copy; 2025 <a href="https://imel.id">imel.id</a>.</strong>
            All rights reserved.
            <div class="float-right d-none d-sm-inline-block">
                <b>Version</b> 1.0.0
            </div>
        </footer>
    </div><!-- /.wrapper -->
    <?php else: ?>
        </div><!-- /.login-page -->
    <?php endif; ?>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap 4 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE App -->
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
    <!-- Quill Editor -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <script>
    // Initialize Quill editor for compose page
    $(document).ready(function() {
        if ($('#editor-container').length && typeof Quill !== 'undefined') {
            var quill = new Quill('#editor-container', {
                theme: 'snow',
                placeholder: 'Tulis pesan email di sini...',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['link'],
                        ['clean']
                    ]
                }
            });
            
            // On form submit, copy Quill content to hidden textarea
            $('#submit-btn').on('click', function(e) {
                $('#body-field').val(quill.root.innerHTML);
            });
        }
        
        // Update custom file input label
        $('.custom-file-input').on('change', function(e) {
            var fileName = Array.from(e.target.files).map(f => f.name).join(', ');
            $(this).next('.custom-file-label').text(fileName || 'Pilih file...');
        });
    });
    </script>
</body>
</html>
