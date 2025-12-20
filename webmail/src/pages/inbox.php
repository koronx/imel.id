<?php
$user = getCurrentUser();
$folder = $_GET['folder'] ?? 'inbox';
$search = trim($_GET['search'] ?? '');

// Pagination
$currentPage = max(1, intval($_GET['p'] ?? 1));
$perPage = max(10, min(100, intval($_GET['per_page'] ?? 25))); // Min 10, Max 100, Default 25
$offset = ($currentPage - 1) * $perPage;

// Build query with search
$db = getDB();
$params = [$user['id'], $folder];
$searchCondition = '';

if (!empty($search)) {
    $searchCondition = " AND (from_email ILIKE ? OR to_email ILIKE ? OR subject ILIKE ? OR body ILIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

// Get total count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM emails WHERE user_id = ? AND folder = ?" . $searchCondition);
$stmt->execute($params);
$totalEmails = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalEmails / $perPage);

// Get emails with pagination
$stmt = $db->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM attachments WHERE email_id = e.id) as attachment_count
    FROM emails e
    WHERE user_id = ? AND folder = ?" . $searchCondition . "
    ORDER BY received_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count (only for inbox folder)
$stmt = $db->prepare("SELECT COUNT(*) as count FROM emails WHERE user_id = ? AND folder = 'inbox' AND is_read = false");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-inbox"></i> <?php echo ucfirst($folder); ?></h1>
            </div>
        </div>
    </div>
</section>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?php 
                echo htmlspecialchars($_SESSION['success']); 
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?php 
                echo htmlspecialchars($_SESSION['error']); 
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <div class="d-flex align-items-center justify-content-between w-100">
                            <!-- Left side: checkbox, refresh, bulk actions -->
                            <div class="d-flex align-items-center">
                                <div class="icheck-primary d-inline mr-2">
                                    <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                                    <label for="select-all"></label>
                                </div>
                                
                                <button onclick="refreshEmails()" class="btn btn-default btn-sm mr-2" title="Refresh">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                
                                <div id="bulk-actions" class="mr-2" style="display: none;">
                                    <button onclick="deleteSelected()" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i> Hapus (<span id="selected-count">0</span>)
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Right side: search, info, paging -->
                            <div class="d-flex align-items-center">
                                <form method="GET" class="form-inline mr-3">
                                    <input type="hidden" name="page" value="inbox">
                                    <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
                                    <div class="input-group input-group-sm">
                                        <input type="text" 
                                               name="search" 
                                               class="form-control"
                                               placeholder="Cari email..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <div class="input-group-append">
                                            <button type="submit" class="btn btn-default">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <?php if (!empty($search)): ?>
                                                <a href="?page=inbox&folder=<?php echo $folder; ?>" class="btn btn-default">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                                
                                <span class="text-muted mr-3">
                                    <?php 
                                    $start = $totalEmails > 0 ? $offset + 1 : 0;
                                    $end = min($offset + $perPage, $totalEmails);
                                    echo "$start-$end dari $totalEmails";
                                    ?>
                                </span>
                                
                                <div class="form-inline mr-2">
                                    <label class="mr-2">Tampilkan:</label>
                                    <select id="per-page" onchange="changePerPage(this.value)" class="form-control form-control-sm">
                                        <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                                    </select>
                                </div>
                                
                                <?php 
                                $searchParam = !empty($search) ? '&search=' . urlencode($search) : '';
                                ?>
                                <div class="btn-group">
                                    <?php if ($currentPage > 1): ?>
                                        <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage - 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                                           class="btn btn-default btn-sm" title="Sebelumnya">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-default btn-sm" disabled>
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($currentPage < $totalPages): ?>
                                        <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage + 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                                           class="btn btn-default btn-sm" title="Selanjutnya">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-default btn-sm" disabled>
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php if (!empty($search)): ?>
                            <div class="alert alert-info m-3">
                                <i class="fas fa-info-circle"></i> Hasil pencarian untuk: <strong><?php echo htmlspecialchars($search); ?></strong>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($emails)): ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>
                                    <?php if (!empty($search)): ?>
                                        Tidak ditemukan email dengan kata kunci "<?php echo htmlspecialchars($search); ?>"
                                    <?php else: ?>
                                        Tidak ada email di folder ini
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive mailbox-messages">
                                <table class="table table-hover table-striped">
                                    <tbody>
                                        <?php foreach ($emails as $email): ?>
                                            <tr class="<?php echo !$email['is_read'] ? 'font-weight-bold' : ''; ?>" style="cursor: pointer;">
                                                <td class="mailbox-star" style="width: 40px;">
                                                    <div class="icheck-primary">
                                                        <input type="checkbox" 
                                                               id="check-<?php echo $email['id']; ?>"
                                                               class="email-checkbox" 
                                                               value="<?php echo $email['id']; ?>" 
                                                               onclick="event.stopPropagation(); updateBulkActions();">
                                                        <label for="check-<?php echo $email['id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td class="mailbox-name" onclick="window.location='?page=view&id=<?php echo $email['id']; ?>'">
                                                    <?php echo htmlspecialchars($email['from_email']); ?>
                                                </td>
                                                <td class="mailbox-subject" onclick="window.location='?page=view&id=<?php echo $email['id']; ?>'">
                                                    <?php 
                                                    echo htmlspecialchars($email['subject'] ?: '(Tanpa Subjek)'); 
                                                    ?>
                                                </td>
                                                <td class="mailbox-attachment" style="width: 30px;" onclick="window.location='?page=view&id=<?php echo $email['id']; ?>'">
                                                    <?php if ($email['attachment_count'] > 0): ?>
                                                        <i class="fas fa-paperclip"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="mailbox-date" style="width: 150px;" onclick="window.location='?page=view&id=<?php echo $email['id']; ?>'">
                                                    <?php echo date('d M Y H:i', strtotime($email['received_at'])); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if ($totalPages > 1): ?>
                                <div class="card-footer">
                                    <nav>
                                        <ul class="pagination pagination-sm m-0 float-right">
                                            <?php if ($currentPage > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage - 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>">&laquo;</a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">&laquo;</span>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Show max 7 page numbers
                                            $startPage = max(1, $currentPage - 3);
                                            $endPage = min($totalPages, $currentPage + 3);
                                            
                                            if ($startPage > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=inbox&folder=<?php echo $folder; ?>&p=1&per_page=<?php echo $perPage . $searchParam; ?>">1</a>
                                                </li>
                                                <?php if ($startPage > 2): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                <li class="page-item <?php echo $i == $currentPage ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $i; ?>&per_page=<?php echo $perPage . $searchParam; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($endPage < $totalPages): ?>
                                                <?php if ($endPage < $totalPages - 1): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $totalPages; ?>&per_page=<?php echo $perPage . $searchParam; ?>"><?php echo $totalPages; ?></a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($currentPage < $totalPages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage + 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>">&raquo;</a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">&raquo;</span>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function changePerPage(value) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('per_page', value);
    urlParams.set('p', '1'); // Reset to page 1
    window.location.search = urlParams.toString();
}

function refreshEmails() {
    location.reload();
}

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.email-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.email-checkbox:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    const selectAll = document.getElementById('select-all');
    
    selectedCount.textContent = checkboxes.length;
    
    if (checkboxes.length > 0) {
        bulkActions.style.display = 'flex';
    } else {
        bulkActions.style.display = 'none';
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.email-checkbox');
    selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
}

function deleteSelected() {
    const checkboxes = document.querySelectorAll('.email-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        alert('Pilih email yang akan dihapus');
        return;
    }
    
    if (!confirm(`Hapus ${ids.length} email?`)) {
        return;
    }
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '?page=delete-bulk';
    
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'email_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    const folderInput = document.createElement('input');
    folderInput.type = 'hidden';
    folderInput.name = 'folder';
    folderInput.value = '<?php echo $folder; ?>';
    form.appendChild(folderInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>
