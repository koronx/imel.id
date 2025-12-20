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
    $stmt = $db->prepare("SELECT id, email, full_name, quota_bytes, quota_used FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Generate session token for API calls
function getSessionToken() {
    if (!isLoggedIn()) {
        return null;
    }
    
    if (!isset($_SESSION['api_token'])) {
        $_SESSION['api_token'] = base64_encode(random_bytes(32) . '|' . $_SESSION['user_id'] . '|' . time());
    }
    
    return $_SESSION['api_token'];
}

// Routing
$page = $_GET['page'] ?? 'login';

if (!isLoggedIn() && !in_array($page, ['login', 'register', 'forgot-password', 'oauth-google', 'oauth-callback'])) {
    $page = 'login';
}

// Handle OAuth pages separately (no HTML template)
if (in_array($page, ['oauth-google', 'oauth-callback'])) {
    include "pages/$page.php";
    exit;
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
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/assets/logo.png">
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Quill Editor CSS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <style>
        /* Red-White Theme */
        :root {
            --red-primary: #dc143c;
            --red-dark: #b71c1c;
            --red-light: #ff5252;
        }
        
        /* Navbar merah */
        .navbar-white {
            background-color: var(--red-primary) !important;
            color: white !important;
        }
        .navbar-white .nav-link {
            color: white !important;
        }
        .navbar-white .nav-link:hover {
            color: #ffebee !important;
        }
        
        /* Sidebar merah gelap */
        .sidebar-dark-primary {
            background-color: var(--red-dark) !important;
        }
        .sidebar-dark-primary .brand-link {
            background-color: var(--red-dark) !important;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Sidebar menu items */
        .sidebar-dark-primary .nav-sidebar > .nav-item > .nav-link.active {
            background-color: var(--red-primary) !important;
            color: white !important;
        }
        .sidebar-dark-primary .nav-sidebar > .nav-item > .nav-link:hover {
            background-color: rgba(220, 20, 60, 0.3) !important;
            color: white !important;
        }
        
        /* Buttons dan links */
        .btn-primary {
            background-color: var(--red-primary) !important;
            border-color: var(--red-primary) !important;
        }
        .btn-primary:hover {
            background-color: var(--red-dark) !important;
            border-color: var(--red-dark) !important;
        }
        
        /* Cards */
        .card-primary:not(.card-outline) > .card-header {
            background-color: var(--red-primary) !important;
        }
        .card-primary.card-outline {
            border-top: 3px solid var(--red-primary) !important;
        }
        .card-primary.card-outline .btn-tool {
            color: var(--red-primary) !important;
        }
        
        /* Links */
        a {
            color: white !important;
        }
        a:hover {
            color: var(--red-dark) !important;
        }
        
        /* Fix text visibility in tables and content */
        .table td, .table th {
            color: #212529 !important;
        }
        .card-body, .content, .content-wrapper {
            color: #212529 !important;
        }
        .text-muted {
            color: #6c757d !important;
        }
        
        /* Reset link colors in specific contexts */
        .nav-link, .dropdown-item, .page-link {
            color: inherit !important;
        }
        .navbar-white .nav-link {
            color: white !important;
        }
        .sidebar a {
            color: white !important;
        }
        
        /* Fix table full width */
        .table-responsive {
            width: 100%;
        }
        .mailbox-messages table {
            width: 100%;
        }
        
        /* Make content area full width */
        .content-wrapper {
            margin-left: 250px !important;
            margin-right: 0 !important;
            padding: 0 !important;
        }
        .content-header, .content {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .content-header {
            padding: 15px !important;
        }
        .content {
            padding: 0 15px 15px 15px !important;
        }
        .content-header .container-fluid,
        .content .container-fluid {
            width: 100% !important;
            max-width: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .content .row {
            margin: 0 !important;
            width: 100% !important;
        }
        .content .col-md-12, 
        .content .col-sm-6,
        .content-header .col-sm-6 {
            padding: 0 !important;
            max-width: 100% !important;
            flex: 0 0 100% !important;
        }
        .card {
            width: 100% !important;
            margin: 0 !important;
            border-radius: 0 !important;
        }
        
        /* Fix login-page dan wrapper untuk full width */
        body.login-page {
            display: block !important;
            height: auto !important;
        }
        
        .wrapper {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
        }
        
        body, .wrapper {
            min-height: 100vh !important;
        }
        
        /* Brand */
        .brand-link {
            font-size: 1.25rem;
            font-weight: bold;
        }
        
        /* Email items */
        .email-item {
            cursor: pointer;
            transition: all 0.3s;
        }
        .email-item:hover {
            background-color: #ffebee;
        }
        .email-item.unread {
            background-color: #ffcdd2;
            font-weight: 600;
            border-left: 3px solid var(--red-primary);
        }
        
        /* Editor */
        #editor-container {
            min-height: 300px;
            background: white;
        }
        
        /* Info boxes dan badges */
        .info-box .info-box-icon {
            background-color: var(--red-primary) !important;
        }
        .badge-primary {
            background-color: var(--red-primary) !important;
        }
        
        /* Pagination */
        .pagination .page-item.active .page-link {
            background-color: var(--red-primary) !important;
            border-color: var(--red-primary) !important;
        }
        .pagination .page-link {
            color: var(--red-primary) !important;
        }
        .pagination .page-link:hover {
            color: var(--red-dark) !important;
        }
        
        /* Login page card */
        .login-box .card-primary.card-outline {
            border-top-color: var(--red-primary) !important;
        }
        
        /* Login page background putih */
        body.login-page, body.login-page::before {
            background: white !important;
            background-color: white !important;
            background-image: none !important;
        }
        .login-page .login-box {
            background: white !important;
        }
        
        /* Additional color classes for small-box */
        .bg-purple {
            background-color: #6f42c1 !important;
            color: white !important;
        }
        .bg-teal {
            background-color: #20c997 !important;
            color: white !important;
        }
        
        /* ========== RESPONSIVE DESIGN FOR MOBILE ========== */
        @media (max-width: 768px) {
            /* Hide sidebar by default on mobile */
            .main-sidebar {
                transform: translateX(-250px);
                transition: transform 0.3s ease;
            }
            
            .main-sidebar.sidebar-open {
                transform: translateX(0);
            }
            
            /* Adjust content wrapper */
            .content-wrapper {
                margin-left: 0 !important;
            }
            
            /* Mobile navbar */
            .navbar {
                padding: 0.5rem 1rem;
            }
            
            .navbar-nav {
                flex-direction: row;
            }
            
            /* Hide text on mobile, show only icons */
            .nav-item .d-none.d-sm-inline-block {
                display: none !important;
            }
            
            .nav-item .d-none.d-sm-inline {
                display: none !important;
            }
            
            /* Storage quota card in sidebar - make it compact */
            .sidebar .card {
                margin: 0.5rem !important;
            }
            
            .sidebar .card-body {
                padding: 0.5rem !important;
            }
            
            /* Email list - stack on mobile */
            .mailbox-messages table {
                font-size: 0.875rem;
            }
            
            .mailbox-messages td {
                padding: 0.5rem !important;
            }
            
            /* Hide some columns on mobile */
            .mailbox-messages .mailbox-date {
                font-size: 0.75rem;
            }
            
            /* Compose form adjustments */
            .form-group label {
                font-size: 0.875rem;
            }
            
            .form-control {
                font-size: 0.875rem;
            }
            
            /* Card adjustments */
            .card {
                margin-bottom: 1rem !important;
            }
            
            .card-header {
                padding: 0.75rem;
            }
            
            .card-body {
                padding: 0.75rem;
            }
            
            /* Button groups */
            .btn-group .btn {
                font-size: 0.875rem;
                padding: 0.375rem 0.75rem;
            }
            
            /* Pagination */
            .pagination {
                font-size: 0.875rem;
            }
            
            .page-item {
                margin: 0 2px;
            }
            
            /* Dashboard - stack info boxes */
            .small-box {
                margin-bottom: 1rem;
            }
            
            /* Login box */
            .login-box {
                width: 95% !important;
                margin: 1rem auto !important;
            }
            
            /* Table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Forms */
            .form-inline {
                flex-direction: column;
                align-items: stretch !important;
            }
            
            .form-inline .form-group {
                margin-bottom: 0.5rem;
                width: 100%;
            }
            
            .form-inline input,
            .form-inline select,
            .form-inline button {
                width: 100% !important;
            }
            
            /* Dropdown menus */
            .dropdown-menu {
                position: absolute;
                left: auto !important;
                right: 0;
            }
        }
        
        @media (max-width: 576px) {
            /* Extra small devices */
            .navbar-brand {
                font-size: 1rem;
            }
            
            .brand-image {
                width: 30px !important;
                height: 30px !important;
            }
            
            /* Hide even more on very small screens */
            .mailbox-messages .mailbox-name {
                max-width: 120px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            /* Stack all columns */
            .col-lg-3, .col-lg-6, .col-md-6, .col-md-12 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            
            /* Smaller text */
            body {
                font-size: 0.875rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            h2 {
                font-size: 1.25rem;
            }
            
            h3 {
                font-size: 1.125rem;
            }
        }
        
        /* Sidebar overlay for mobile */
        @media (max-width: 768px) {
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1030;
                display: none;
            }
            
            .sidebar-overlay.active {
                display: block;
            }
            
            .main-sidebar {
                position: fixed;
                z-index: 1031;
            }
            
            /* Ensure menu items are clickable */
            .main-sidebar .nav-link {
                position: relative;
                z-index: 1032;
            }
        }
    </style>
    <script>
        // Mobile sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth <= 768) {
                const pushmenuBtn = document.querySelector('[data-widget="pushmenu"]');
                const sidebar = document.querySelector('.main-sidebar');
                const body = document.body;
                
                if (pushmenuBtn && sidebar) {
                    // Create overlay
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    body.appendChild(overlay);
                    
                    // Disable AdminLTE default pushmenu
                    pushmenuBtn.removeAttribute('data-widget');
                    
                    // Custom toggle sidebar
                    pushmenuBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const isOpen = sidebar.classList.contains('sidebar-open');
                        if (isOpen) {
                            sidebar.classList.remove('sidebar-open');
                            overlay.classList.remove('active');
                            body.classList.remove('sidebar-open');
                        } else {
                            sidebar.classList.add('sidebar-open');
                            overlay.classList.add('active');
                            body.classList.add('sidebar-open');
                        }
                    });
                    
                    // Close sidebar when overlay clicked
                    overlay.addEventListener('click', function(e) {
                        e.preventDefault();
                        sidebar.classList.remove('sidebar-open');
                        overlay.classList.remove('active');
                        body.classList.remove('sidebar-open');
                    });
                    
                    // Close sidebar when menu item clicked - use capture phase
                    const menuLinks = sidebar.querySelectorAll('.nav-link');
                    menuLinks.forEach(function(link) {
                        link.addEventListener('click', function(e) {
                            const href = this.getAttribute('href');
                            // Only close for actual navigation links
                            if (href && href !== '#' && href !== 'javascript:void(0)' && !href.startsWith('javascript:')) {
                                // Don't prevent default, let navigation happen
                                sidebar.classList.remove('sidebar-open');
                                overlay.classList.remove('active');
                                body.classList.remove('sidebar-open');
                            }
                        }, false);
                    });
                }
            }
        });
    </script>
</head>
<body class="hold-transition sidebar-mini layout-fixed login-page">
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
        <aside class="main-sidebar sidebar-dark-primary elevation-4" style="background-color: #b71c1c;">
            <!-- Brand Logo -->
            <a href="?page=inbox" class="brand-link">
                <img src="/assets/logo.png" alt="imel.id Logo" class="brand-image" style="width: 40px; height: 40px; object-fit: contain; margin-left: 10px; opacity: .8">
                <span class="brand-text font-weight-light ml-2" style="color: white !important;">imel.id</span>
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
                        <?php if (getCurrentUser()['email'] === 'admin@imel.id'): ?>
                        <li class="nav-item">
                            <a href="?page=dashboard" class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <li class="nav-header">EMAIL</li>
                        <?php endif; ?>
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
                
                <!-- Storage Quota Info -->
                <?php 
                $user = getCurrentUser();
                $quotaBytes = $user['quota_bytes'] ?? 1073741824;
                $quotaUsed = $user['quota_used'] ?? 0;
                $quotaPercent = $quotaBytes > 0 ? round(($quotaUsed / $quotaBytes) * 100, 1) : 0;
                $quotaUsedMB = round($quotaUsed / (1024 * 1024), 1);
                $quotaTotalMB = round($quotaBytes / (1024 * 1024), 0);
                ?>
                <div class="mt-4 mb-3 px-3">
                    <div class="card" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
                        <div class="card-body p-2">
                            <h6 class="text-white mb-2">
                                <i class="fas fa-hdd"></i> Storage
                            </h6>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar <?php echo $quotaPercent > 90 ? 'bg-danger' : 'bg-info'; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($quotaPercent, 100); ?>%"
                                     aria-valuenow="<?php echo $quotaPercent; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo $quotaPercent; ?>%
                                </div>
                            </div>
                            <small class="text-white">
                                <?php echo $quotaUsedMB; ?> MB / <?php echo $quotaTotalMB; ?> MB
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
    <?php else: ?>
        <!-- Login/Register page without sidebar -->
        <div class="login-page" style="background: white; min-height: 100vh;">
    <?php endif; ?>
    
    <?php
    // Handle different pages
    switch ($page) {
        case 'dashboard':
            include 'pages/dashboard.php';
            break;
        case 'login':
            include 'pages/login.php';
            break;
        case 'register':
            include 'pages/register.php';
            break;
        case 'forgot-password':
            include 'pages/forgot-password.php';
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
            <strong>Copyright &copy; 2025 <a href="https://imel.id" style="color: black !important;">imel.id</a>.</strong>
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
