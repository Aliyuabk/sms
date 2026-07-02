<?php
// notification_logs.php
ob_start();

require_once 'includes/header.php';

$page_title = "Notification Logs";

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query conditions
$conditions = [];
$params = [];

// For notifications table
if (!empty($search)) {
    $conditions[] = "(n.title LIKE ? OR n.message LIKE ? OR s.matric_number LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if (!empty($type)) {
    $conditions[] = "n.notification_type = ?";
    $params[] = $type;
}

if (!empty($status)) {
    if ($status === 'read') {
        $conditions[] = "n.is_read = 1";
    } elseif ($status === 'unread') {
        $conditions[] = "n.is_read = 0";
    }
}

if (!empty($date_from)) {
    $conditions[] = "DATE(n.sent_date) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(n.sent_date) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count for notifications
$count_sql = "
    SELECT COUNT(*) as total 
    FROM notifications n
    LEFT JOIN students s ON n.student_id = s.student_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Fetch notifications
$sql = "
    SELECT 
        n.*,
        s.matric_number,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.email as student_email,
        DATEDIFF(n.expires_date, CURDATE()) as days_until_expiry,
        CASE 
            WHEN n.expires_date IS NULL THEN 'No Expiry'
            WHEN n.expires_date < CURDATE() THEN 'Expired'
            WHEN DATEDIFF(n.expires_date, CURDATE()) <= 7 THEN 'Expiring Soon'
            ELSE 'Active'
        END as expiry_status
    FROM notifications n
    LEFT JOIN students s ON n.student_id = s.student_id
    {$where_clause}
    ORDER BY n.sent_date DESC
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get email queue stats
$email_stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'Sent' THEN 1 END) as sent,
        COUNT(CASE WHEN status = 'Failed' THEN 1 END) as failed
    FROM email_queue
")->fetch();

// Notification types for filter
$notification_types = ['Academic', 'Financial', 'Hostel', 'General', 'Urgent'];

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selected_ids = $_POST['selected_notifications'] ?? [];
    
    if (empty($selected_ids)) {
        $_SESSION['error_message'] = "No notifications selected";
    } else {
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'mark_read':
                    $update_sql = "UPDATE notifications SET is_read = 1, read_date = NOW() WHERE notification_id IN ($placeholders)";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute($selected_ids);
                    $_SESSION['success_message'] = "Marked " . count($selected_ids) . " notifications as read";
                    break;
                    
                case 'mark_unread':
                    $update_sql = "UPDATE notifications SET is_read = 0, read_date = NULL WHERE notification_id IN ($placeholders)";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute($selected_ids);
                    $_SESSION['success_message'] = "Marked " . count($selected_ids) . " notifications as unread";
                    break;
                    
                case 'delete':
                    $delete_sql = "DELETE FROM notifications WHERE notification_id IN ($placeholders)";
                    $delete_stmt = $pdo->prepare($delete_sql);
                    $delete_stmt->execute($selected_ids);
                    
                    // Log deletion
                    $log_stmt = $pdo->prepare("
                        INSERT INTO admin_logs (admin_id, action, description, table_name) 
                        VALUES (?, 'Delete', ?, 'notifications')
                    ");
                    $log_stmt->execute([
                        $_SESSION['admin_id'],
                        "Deleted " . count($selected_ids) . " notifications"
                    ]);
                    
                    $_SESSION['success_message'] = "Deleted " . count($selected_ids) . " notifications";
                    break;
                    
                case 'resend':
                    // Queue for resending
                    $select_sql = "SELECT n.*, s.email FROM notifications n JOIN students s ON n.student_id = s.student_id WHERE n.notification_id IN ($placeholders)";
                    $select_stmt = $pdo->prepare($select_sql);
                    $select_stmt->execute($selected_ids);
                    $to_resend = $select_stmt->fetchAll();
                    
                    $queue_sql = "INSERT INTO email_queue (student_id, subject, message, priority, status) VALUES (?, ?, ?, ?, 'Pending')";
                    $queue_stmt = $pdo->prepare($queue_sql);
                    
                    foreach ($to_resend as $notif) {
                        $queue_stmt->execute([
                            $notif['student_id'],
                            $notif['title'],
                            $notif['message'],
                            $notif['priority']
                        ]);
                    }
                    
                    $_SESSION['success_message'] = "Queued " . count($to_resend) . " notifications for resending";
                    break;
            }
            
            $pdo->commit();
            header("Location: notification_logs.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Bulk action error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error processing bulk action";
        }
    }
}

// Handle individual actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $notification_id = (int)$_GET['id'];
    
    try {
        switch ($_GET['action']) {
            case 'view':
                // Mark as read when viewed
                $update_stmt = $pdo->prepare("
                    UPDATE notifications SET is_read = 1, read_date = NOW() 
                    WHERE notification_id = ? AND is_read = 0
                ");
                $update_stmt->execute([$notification_id]);
                header("Location: view_notification.php?id=$notification_id");
                exit();
                break;
                
            case 'delete':
                $delete_stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ?");
                $delete_stmt->execute([$notification_id]);
                $_SESSION['success_message'] = "Notification deleted";
                break;
                
            case 'toggle_read':
                $toggle_stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_read = NOT is_read, 
                        read_date = CASE WHEN is_read = 0 THEN NOW() ELSE NULL END 
                    WHERE notification_id = ?
                ");
                $toggle_stmt->execute([$notification_id]);
                $_SESSION['success_message'] = "Notification status updated";
                break;
        }
        
        header("Location: notification_logs.php");
        exit();
        
    } catch (Exception $e) {
        error_log("Action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing action";
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Notification Logs</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Notification Logs</h1>
    </div>
    <div class="app-actions">
        <a href="send_notifications.php" class="btn btn-primary">
            <i class="fas fa-plus-circle me-2"></i>New Notification
        </a>
        <a href="email_templates.php" class="btn btn-outline-primary ms-2">
            <i class="fas fa-template me-2"></i>Templates
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Total Notifications</div>
                        <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                    </div>
                    <div class="app-icon-holder bg-primary bg-opacity-10">
                        <i class="fas fa-bell text-primary"></i>
                    </div>
                </div>
                <div class="stats-meta">
                    <i class="fas fa-chart-line me-1"></i>All time
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Unread</div>
                        <?php
                        $unread_count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
                        ?>
                        <div class="stats-figure"><?php echo number_format($unread_count); ?></div>
                    </div>
                    <div class="app-icon-holder bg-warning bg-opacity-10">
                        <i class="fas fa-envelope text-warning"></i>
                    </div>
                </div>
                <div class="stats-meta">
                    <span class="text-warning"><?php echo $total_records > 0 ? round(($unread_count / $total_records) * 100, 1) : 0; ?>% unread</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Email Queue</div>
                        <div class="stats-figure"><?php echo number_format($email_stats['pending']); ?></div>
                    </div>
                    <div class="app-icon-holder bg-info bg-opacity-10">
                        <i class="fas fa-envelope-open-text text-info"></i>
                    </div>
                </div>
                <div class="stats-meta">
                    <span class="text-success"><?php echo $email_stats['sent']; ?> sent</span>
                    <?php if ($email_stats['failed'] > 0): ?>
                        <span class="text-danger ms-2"><?php echo $email_stats['failed']; ?> failed</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Urgent</div>
                        <?php
                        $urgent_count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE priority = 'Urgent' AND is_read = 0")->fetchColumn();
                        ?>
                        <div class="stats-figure"><?php echo number_format($urgent_count); ?></div>
                    </div>
                    <div class="app-icon-holder bg-danger bg-opacity-10">
                        <i class="fas fa-exclamation-circle text-danger"></i>
                    </div>
                </div>
                <div class="stats-meta">
                    Require immediate attention
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" 
                       placeholder="Title, message, student..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                    <option value="">All Types</option>
                    <?php foreach ($notification_types as $type_opt): ?>
                    <option value="<?php echo $type_opt; ?>" <?php echo $type == $type_opt ? 'selected' : ''; ?>>
                        <?php echo $type_opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <option value="read" <?php echo $status == 'read' ? 'selected' : ''; ?>>Read</option>
                    <option value="unread" <?php echo $status == 'unread' ? 'selected' : ''; ?>>Unread</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Notifications Table -->
<div class="app-card shadow-sm">
    <div class="app-card-header p-3">
        <div class="row align-items-center">
            <div class="col">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-list me-2"></i>Notification History
                </h5>
            </div>
            <div class="col-auto">
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_notifications.php?format=excel&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_notifications.php?format=pdf&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_notifications.php?format=csv&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <form method="POST" id="bulkForm">
            <div class="table-responsive">
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="40">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                </div>
                            </th>
                            <th>Status</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Recipient</th>
                            <th>Priority</th>
                            <th>Sent Date</th>
                            <th>Read Date</th>
                            <th>Expiry</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notif): ?>
                            <tr class="<?php echo !$notif['is_read'] ? 'table-light' : ''; ?>">
                                <td>
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_notifications[]" 
                                               value="<?php echo $notif['notification_id']; ?>">
                                    </div>
                                </td>
                                <td>
                                    <?php if ($notif['is_read']): ?>
                                        <span class="badge bg-success" title="Read on <?php echo date('M d, Y H:i', strtotime($notif['read_date'])); ?>">
                                            <i class="fas fa-check-circle me-1"></i>Read
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="fas fa-clock me-1"></i>Unread
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $type_class = [
                                        'Academic' => 'primary',
                                        'Financial' => 'success',
                                        'Hostel' => 'info',
                                        'General' => 'secondary',
                                        'Urgent' => 'danger'
                                    ][$notif['notification_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $type_class; ?>">
                                        <?php echo $notif['notification_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <small class="text-muted"><?php echo substr(htmlspecialchars($notif['message']), 0, 50); ?>...</small>
                                </td>
                                <td>
                                    <?php if ($notif['student_id']): ?>
                                        <div><?php echo htmlspecialchars($notif['student_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notif['matric_number']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Broadcast</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $priority_class = [
                                        'Low' => 'secondary',
                                        'Normal' => 'info',
                                        'High' => 'warning',
                                        'Urgent' => 'danger'
                                    ][$notif['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $priority_class; ?>">
                                        <?php echo $notif['priority']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?php echo date('M d, Y', strtotime($notif['sent_date'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($notif['sent_date'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($notif['read_date']): ?>
                                        <div><?php echo date('M d, Y', strtotime($notif['read_date'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($notif['read_date'])); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $expiry_class = [
                                        'Active' => 'success',
                                        'Expiring Soon' => 'warning',
                                        'Expired' => 'danger',
                                        'No Expiry' => 'secondary'
                                    ][$notif['expiry_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $expiry_class; ?>">
                                        <?php echo $notif['expiry_status']; ?>
                                    </span>
                                    <?php if ($notif['expires_date'] && $notif['expiry_status'] != 'No Expiry'): ?>
                                        <div><small><?php echo date('M d, Y', strtotime($notif['expires_date'])); ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="?action=view&id=<?php echo $notif['notification_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?action=toggle_read&id=<?php echo $notif['notification_id']; ?>" 
                                           class="btn btn-sm btn-outline-<?php echo $notif['is_read'] ? 'warning' : 'success'; ?>" 
                                           title="<?php echo $notif['is_read'] ? 'Mark as Unread' : 'Mark as Read'; ?>">
                                            <i class="fas fa-<?php echo $notif['is_read'] ? 'clock' : 'check'; ?>"></i>
                                        </a>
                                        <a href="send_notifications.php?resend=<?php echo $notif['notification_id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="Resend">
                                            <i class="fas fa-paper-plane"></i>
                                        </a>
                                        <a href="?action=delete&id=<?php echo $notif['notification_id']; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Delete this notification?')"
                                           title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                                    <h5>No notifications found</h5>
                                    <p class="text-muted">No notifications match your criteria.</p>
                                    <?php if ($search || $type || $status || $date_from || $date_to): ?>
                                    <a href="notification_logs.php" class="btn btn-primary">
                                        <i class="fas fa-redo me-1"></i>Clear Filters
                                    </a>
                                    <?php else: ?>
                                    <a href="send_notifications.php" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-1"></i>Send First Notification
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    
    <!-- Table Footer -->
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <div class="form-check me-3">
                        <input class="form-check-input" type="checkbox" id="selectAllBottom">
                        <label class="form-check-label" for="selectAllBottom">
                            Select All
                        </label>
                    </div>
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                            Bulk Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="submitBulkAction('mark_read')">
                                <i class="fas fa-check-circle me-2 text-success"></i>Mark as Read
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="submitBulkAction('mark_unread')">
                                <i class="fas fa-clock me-2 text-warning"></i>Mark as Unread
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="submitBulkAction('resend')">
                                <i class="fas fa-paper-plane me-2 text-info"></i>Resend
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('delete')">
                                <i class="fas fa-trash me-2"></i>Delete Selected
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <div class="text-muted small text-end mt-2">
                    Showing <?php echo min($offset + 1, $total_records); ?> - 
                    <?php echo min($offset + $records_per_page, $total_records); ?> 
                    of <?php echo $total_records; ?> notifications
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email Queue Summary -->
<?php if ($email_stats['pending'] > 0 || $email_stats['failed'] > 0): ?>
<div class="app-card shadow-sm mt-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-envelope me-2"></i>Email Queue Status
        </h5>
    </div>
    <div class="app-card-body p-3">
        <div class="row">
            <div class="col-md-8">
                <div class="progress" style="height: 20px;">
                    <?php 
                    $total_emails = $email_stats['total'];
                    $sent_percent = $total_emails > 0 ? ($email_stats['sent'] / $total_emails) * 100 : 0;
                    $pending_percent = $total_emails > 0 ? ($email_stats['pending'] / $total_emails) * 100 : 0;
                    $failed_percent = $total_emails > 0 ? ($email_stats['failed'] / $total_emails) * 100 : 0;
                    ?>
                    <div class="progress-bar bg-success" style="width: <?php echo $sent_percent; ?>%" 
                         title="Sent: <?php echo $email_stats['sent']; ?>">
                        <?php echo $email_stats['sent'] > 0 ? $email_stats['sent'] : ''; ?>
                    </div>
                    <div class="progress-bar bg-warning" style="width: <?php echo $pending_percent; ?>%"
                         title="Pending: <?php echo $email_stats['pending']; ?>">
                        <?php echo $email_stats['pending'] > 0 ? $email_stats['pending'] : ''; ?>
                    </div>
                    <div class="progress-bar bg-danger" style="width: <?php echo $failed_percent; ?>%"
                         title="Failed: <?php echo $email_stats['failed']; ?>">
                        <?php echo $email_stats['failed'] > 0 ? $email_stats['failed'] : ''; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <a href="email_queue.php" class="btn btn-sm btn-outline-primary">
                    Manage Queue <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Select all functionality
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    document.getElementById('selectAllBottom').checked = this.checked;
});

document.getElementById('selectAllBottom')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    document.getElementById('selectAll').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = Array.from(document.querySelectorAll('.select-checkbox:checked')).map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('Please select at least one notification.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'mark_read':
            confirmMessage = `Mark ${selectedIds.length} notification(s) as read?`;
            break;
        case 'mark_unread':
            confirmMessage = `Mark ${selectedIds.length} notification(s) as unread?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} notification(s)? This action cannot be undone.`;
            break;
        case 'resend':
            confirmMessage = `Resend ${selectedIds.length} notification(s)?`;
            break;
    }
    
    if (confirmMessage && !confirm(confirmMessage)) {
        return false;
    }
    
    // Create form and submit
    const form = document.getElementById('bulkForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'bulk_action';
    input.value = action;
    form.appendChild(input);
    form.submit();
}

// Auto-refresh every 30 seconds (optional)
let refreshInterval = setInterval(function() {
    // Only refresh if page is visible and not on a filtered search
    if (!document.hidden && !window.location.search.includes('search')) {
        window.location.reload();
    }
}, 30000);

// Clear interval when navigating away
window.addEventListener('beforeunload', function() {
    clearInterval(refreshInterval);
});
</script>

<?php
require_once 'includes/footer.php';
?>