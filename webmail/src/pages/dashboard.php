<?php
$user = getCurrentUser();

// Check if user is admin
if ($user['email'] !== 'admin@imel.id') {
    header('Location: ?page=inbox');
    exit;
}

$db = getDB();

// Handle Rate Limit Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_rate_limit'])) {
    $targetEmail = trim($_POST['target_email'] ?? '');
    
    if (empty($targetEmail)) {
        $_SESSION['error'] = 'Email harus diisi';
    } elseif (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Format email tidak valid';
    } else {
        try {
            // Get user ID
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$targetEmail]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                $_SESSION['error'] = 'User tidak ditemukan: ' . htmlspecialchars($targetEmail);
            } else {
                // Reset external email rate limit
                $stmt = $db->prepare("DELETE FROM external_email_log WHERE user_id = ?");
                $stmt->execute([$targetUser['id']]);
                $deletedExternal = $stmt->rowCount();
                
                // Reset forgot password rate limit
                $stmt = $db->prepare("DELETE FROM forgot_password_attempts WHERE email = ?");
                $stmt->execute([$targetEmail]);
                $deletedForgot = $stmt->rowCount();
                
                $_SESSION['success'] = "Rate limit berhasil direset untuk {$targetEmail} (Email: {$deletedExternal}, Forgot Password: {$deletedForgot})";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        
        header('Location: ?page=dashboard');
        exit;
    }
}

// Total Users
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Newest Users (last 5)
$stmt = $db->query("SELECT email, full_name, created_at FROM users ORDER BY created_at DESC LIMIT 5");
$newestUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Email Recipients (users who received most emails)
$stmt = $db->query("
    SELECT u.email, u.full_name, COUNT(e.id) as email_count
    FROM users u
    JOIN emails e ON u.id = e.user_id
    WHERE e.folder = 'inbox'
    GROUP BY u.id, u.email, u.full_name
    ORDER BY email_count DESC
    LIMIT 10
");
$topRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Email Senders (users who sent most emails)
$stmt = $db->query("
    SELECT u.email, u.full_name, COUNT(e.id) as email_count
    FROM users u
    JOIN emails e ON u.id = e.user_id
    WHERE e.folder = 'sent'
    GROUP BY u.id, u.email, u.full_name
    ORDER BY email_count DESC
    LIMIT 10
");
$topSenders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Destination Domains (from sent emails)
$stmt = $db->query("
    SELECT 
        SUBSTRING(to_email FROM POSITION('@' IN to_email) + 1) as domain,
        COUNT(*) as email_count
    FROM emails
    WHERE folder = 'sent' AND to_email LIKE '%@%'
    GROUP BY domain
    ORDER BY email_count DESC
    LIMIT 10
");
$topDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Sender Domains (from received emails)
$stmt = $db->query("
    SELECT 
        SUBSTRING(from_email FROM POSITION('@' IN from_email) + 1) as domain,
        COUNT(*) as email_count
    FROM emails
    WHERE folder = 'inbox' AND from_email LIKE '%@%'
    GROUP BY domain
    ORDER BY email_count DESC
    LIMIT 10
");
$topSenderDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total emails statistics
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_emails,
        COUNT(CASE WHEN folder = 'inbox' THEN 1 END) as received_emails,
        COUNT(CASE WHEN folder = 'sent' THEN 1 END) as sent_emails
    FROM emails
");
$emailStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Email per hour for last 24 hours
$stmt = $db->query("
    SELECT 
        EXTRACT(HOUR FROM received_at) as hour,
        COUNT(CASE WHEN folder = 'inbox' THEN 1 END) as received_count,
        COUNT(CASE WHEN folder = 'sent' THEN 1 END) as sent_count
    FROM emails
    WHERE received_at >= NOW() - INTERVAL '24 hours'
    GROUP BY hour
    ORDER BY hour
");
$hourlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create arrays for all 24 hours
$hours = [];
$receivedPerHour = [];
$sentPerHour = [];

for ($i = 0; $i < 24; $i++) {
    $hours[$i] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
    $receivedPerHour[$i] = 0;
    $sentPerHour[$i] = 0;
}

// Fill in the actual data
foreach ($hourlyStats as $stat) {
    $hour = (int)$stat['hour'];
    $receivedPerHour[$hour] = (int)$stat['received_count'];
    $sentPerHour[$hour] = (int)$stat['sent_count'];
}
?>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-tachometer-alt"></i> Dashboard Administrator</h1>
            </div>
        </div>
    </div>
</section>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Info boxes -->
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $totalUsers; ?></h3>
                        <p>Total Pengguna</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo number_format($emailStats['total_emails']); ?></h3>
                        <p>Total Email</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo number_format($emailStats['received_emails']); ?></h3>
                        <p>Email Diterima</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo number_format($emailStats['sent_emails']); ?></h3>
                        <p>Email Terkirim</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Tools -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-warning card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tools"></i> Admin Tools - Reset Rate Limit</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="form-inline">
                            <div class="form-group mr-3">
                                <label class="mr-2">Email User:</label>
                                <input type="email" name="target_email" class="form-control" placeholder="user@imel.id" required style="width: 300px;">
                            </div>
                            <button type="submit" name="reset_rate_limit" class="btn btn-warning">
                                <i class="fas fa-redo"></i> Reset Rate Limit
                            </button>
                        </form>
                        <small class="form-text text-muted mt-2">
                            <i class="fas fa-info-circle"></i> Reset semua rate limit untuk user tertentu (email eksternal & forgot password)
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email Chart -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line"></i> Email Per Jam (24 Jam Terakhir)</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="emailChart" style="height: 300px;"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Newest Users -->
            <div class="col-md-6">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-plus"></i> Pengguna Terbaru</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Nama</th>
                                    <th>Bergabung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($newestUsers as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top Destination Domains -->
            <div class="col-md-6">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-globe"></i> Top Domain Tujuan</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th style="width: 100px;">Jumlah Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topDomains as $domain): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($domain['domain']); ?></td>
                                        <td><span class="badge badge-primary"><?php echo number_format($domain['email_count']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top Sender Domains -->
            <div class="col-md-6">
                <div class="card card-info card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-envelope-open"></i> Top Domain Sumber</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th style="width: 100px;">Jumlah Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSenderDomains as $domain): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($domain['domain']); ?></td>
                                        <td><span class="badge badge-info"><?php echo number_format($domain['email_count']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Top Recipients -->
            <div class="col-md-6">
                <div class="card card-success card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-inbox"></i> Top Penerima Email</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Nama</th>
                                    <th style="width: 100px;">Diterima</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topRecipients as $recipient): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($recipient['email']); ?></td>
                                        <td><?php echo htmlspecialchars($recipient['full_name']); ?></td>
                                        <td><span class="badge badge-success"><?php echo number_format($recipient['email_count']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top Senders -->
            <div class="col-md-6">
                <div class="card card-danger card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-paper-plane"></i> Top Pengirim Email</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Nama</th>
                                    <th style="width: 100px;">Terkirim</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSenders as $sender): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sender['email']); ?></td>
                                        <td><?php echo htmlspecialchars($sender['full_name']); ?></td>
                                        <td><span class="badge badge-danger"><?php echo number_format($sender['email_count']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
    const ctx = document.getElementById('emailChart').getContext('2d');
    const emailChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($hours); ?>,
            datasets: [
                {
                    label: 'Email Diterima',
                    data: <?php echo json_encode(array_values($receivedPerHour)); ?>,
                    borderColor: 'rgb(255, 193, 7)',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Email Terkirim',
                    data: <?php echo json_encode(array_values($sentPerHour)); ?>,
                    borderColor: 'rgb(220, 53, 69)',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
</script>
