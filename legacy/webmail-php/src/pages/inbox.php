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

<div class="container">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php 
            echo htmlspecialchars($_SESSION['success']); 
            unset($_SESSION['success']);
            ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <?php 
            echo htmlspecialchars($_SESSION['error']); 
            unset($_SESSION['error']);
            ?>
        </div>
    <?php endif; ?>
    
    <div class="mail-layout">
        <div class="sidebar">
            <a href="?page=compose" class="btn btn-success" style="margin-bottom: 1rem; text-align: center;">✉️ Tulis Email</a>
            
            <a href="?page=inbox&folder=inbox" class="<?php echo $folder === 'inbox' ? 'active' : ''; ?>">
                📥 Inbox <?php if ($unreadCount > 0) echo "($unreadCount)"; ?>
            </a>
            <a href="?page=inbox&folder=sent" class="<?php echo $folder === 'sent' ? 'active' : ''; ?>">
                📤 Terkirim
            </a>
            <a href="?page=inbox&folder=drafts" class="<?php echo $folder === 'drafts' ? 'active' : ''; ?>">
                📝 Draft
            </a>
            <a href="?page=inbox&folder=trash" class="<?php echo $folder === 'trash' ? 'active' : ''; ?>">
                🗑️ Sampah
            </a>
        </div>
        
        <div class="mail-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding: 1rem; background: white; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 1rem; flex: 1;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" style="width: 18px; height: 18px; cursor: pointer;">
                    </label>
                    
                    <button onclick="refreshEmails()" class="btn" style="padding: 0.5rem 1rem;" title="Refresh">
                        🔄 Refresh
                    </button>
                    
                    <div id="bulk-actions" style="display: none; gap: 0.5rem;">
                        <button onclick="deleteSelected()" class="btn btn-danger" style="padding: 0.5rem 1rem;">
                            🗑️ Hapus (<span id="selected-count">0</span>)
                        </button>
                    </div>
                    
                    <form method="GET" style="display: flex; gap: 0.5rem; margin-left: auto; flex: 1; max-width: 400px;">
                        <input type="hidden" name="page" value="inbox">
                        <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
                        <input type="text" 
                               name="search" 
                               placeholder="🔍 Cari email..." 
                               value="<?php echo htmlspecialchars($search); ?>"
                               style="flex: 1; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                        <button type="submit" class="btn" style="padding: 0.5rem 1rem;">Cari</button>
                        <?php if (!empty($search)): ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>" class="btn" style="padding: 0.5rem 1rem;">✕</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <span style="font-size: 0.9rem; color: #7f8c8d;">
                        <?php 
                        $start = $totalEmails > 0 ? $offset + 1 : 0;
                        $end = min($offset + $perPage, $totalEmails);
                        echo "$start-$end dari $totalEmails";
                        ?>
                    </span>
                    
                    <?php 
                    $searchParam = !empty($search) ? '&search=' . urlencode($search) : '';
                    ?>
                    <?php if ($currentPage > 1): ?>
                        <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage - 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                           class="btn" style="padding: 0.5rem;" title="Sebelumnya">◀</a>
                 div>
                    <h2><?php echo ucfirst($folder); ?></h2>
                    <?php if (!empty($search)): ?>
                        <p style="font-size: 0.9rem; color: #7f8c8d; margin-top: 0.5rem;">
                            Hasil pencarian untuk: <strong><?php echo htmlspecialchars($search); ?></strong>
                        </p>
                    <?php endif; ?>
                </div
                        <button class="btn" style="padding: 0.5rem; opacity: 0.5; cursor: not-allowed;" disabled>◀</button>
                    <?php endif; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage + 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                           class="btn" style="padding: 0.5rem;" title="Selanjutnya">▶</a>
                    <?php else: ?>
                        <button class="btn" style="padding: 0.5rem; opacity: 0.5; cursor: not-allowed;" disabled>▶</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2><?php echo ucfirst($folder); ?></h2>
                
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <label for="per-page" style="font-size: 0.9rem;">Tampilkan:</label>
                    <select id="per-page" onchange="changePerPage(this.value)" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                    <?php if (!empty($search)): ?>
                        Tidak ditemukan email dengan kata kunci "<?php echo htmlspecialchars($search); ?>"
                    <?php else: ?>
                        Tidak ada email di folder ini
                    <?php endif; ?> echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </div>
            </div>
            
            <?php if (empty($emails)): ?>
                <p style="text-align: center; color: #7f8c8d; margin-top: 2rem;">
                    Tidak ada email di folder ini
                </p>
            <?php else: ?>
                <ul class="email-list" style="list-style: none; padding: 0;">
                    <?php foreach ($emails as $email): ?>
                        <li class="email-item <?php echo !$email['is_read'] ? 'unread' : ''; ?>" 
                            style="border-bottom: 1px solid #eee; padding: 1rem; display: flex; align-items: center; gap: 1rem; background: white; cursor: pointer;"
                            onmouseover="this.style.backgroundColor='#f9f9f9'" 
                            onmouseout="this.style.backgroundColor='white'">
                            
                            <input type="checkbox" 
                                   class="email-checkbox" 
                                   value="<?php echo $email['id']; ?>" 
                                   onclick="event.stopPropagation(); updateBulkActions();"
                                   style="width: 18px; height: 18px; cursor: pointer; flex-shrink: 0;">
                            
                            <div onclick="window.location='?page=view&id=<?php echo $email['id']; ?>'" style="flex: 1; display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1;">
                                    <div class="email-from" style="font-weight: <?php echo !$email['is_read'] ? 'bold' : 'normal'; ?>; margin-bottom: 0.25rem;">
                                        <?php echo htmlspecialchars($email['from_email']); ?>
                                    </div>
                                    <div class="email-subject" style="font-weight: <?php echo !$email['is_read'] ? '600' : 'normal'; ?>;">
                                        <?php 
                                        echo htmlspecialchars($email['subject'] ?: '(Tanpa Subjek)'); 
                                        if ($email['attachment_count'] > 0) {
                                            echo ' 📎';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="email-date" style="color: #7f8c8d; font-size: 0.9rem; white-space: nowrap; margin-left: 1rem;">
                                    <?php echo date('d M Y H:i', strtotime($email['received_at'])); ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if ($totalPages > 1): ?>
                    <div style="margin-top: 2rem; display: flex; justify-content: center; align-items: center; gap: 0.5rem;">
                        <?php if ($currentPage > 1): ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage - 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                               class="btn" style="padding: 0.5rem 1rem;">← Sebelumnya</a>
                        <?php endif; ?>
                        
                        <?php
                        // Show max 7 page numbers
                        $startPage = max(1, $currentPage - 3);
                        $endPage = min($totalPages, $currentPage + 3);
                        
                        if ($startPage > 1): ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>&p=1&per_page=<?php echo $perPage . $searchParam; ?>" 
                               class="btn" style="padding: 0.5rem 1rem;">1</a>
                            <?php if ($startPage > 2): ?>
                                <span style="padding: 0.5rem;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $i; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                               class="btn <?php echo $i == $currentPage ? 'btn-success' : ''; ?>" 
                               style="padding: 0.5rem 1rem; <?php echo $i == $currentPage ? 'pointer-events: none;' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span style="padding: 0.5rem;">...</span>
                            <?php endif; ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $totalPages; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                               class="btn" style="padding: 0.5rem 1rem;"><?php echo $totalPages; ?></a>
                        <?php endif; ?>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="?page=inbox&folder=<?php echo $folder; ?>&p=<?php echo $currentPage + 1; ?>&per_page=<?php echo $perPage . $searchParam; ?>" 
                               class="btn" style="padding: 0.5rem 1rem;">Selanjutnya →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

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
