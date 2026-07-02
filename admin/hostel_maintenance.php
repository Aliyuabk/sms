<?php
// hostel_maintenance.php
ob_start();
require_once 'includes/header.php';

$page_title = "Hostel Maintenance";

// Get filter parameters
$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$category = isset($_GET['category']) ? $_GET['category'] : '';
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query conditions
$conditions = [];
$params = [];

if ($hostel_id > 0) {
    $conditions[] = "hm.hostel_id = ?";
    $params[] = $hostel_id;
}

if ($room_id > 0) {
    $conditions[] = "hm.room_id = ?";
    $params[] = $room_id;
}

if (!empty($category)) {
    $conditions[] = "hm.category = ?";
    $params[] = $category;
}

if (!empty($priority)) {
    $conditions[] = "hm.priority = ?";
    $params[] = $priority;
}

if (!empty($status_filter)) {
    $conditions[] = "hm.status = ?";
    $params[] = $status_filter;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM hostel_maintenance hm
    LEFT JOIN hostels h ON hm.hostel_id = h.hostel_id
    LEFT JOIN hostel_rooms hr ON hm.room_id = hr.room_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get maintenance data - CORRECTED QUERY
$sql = "
    SELECT 
        hm.*,
        h.hostel_name,
        h.hostel_code,
        hr.room_number,
        DATEDIFF(CURDATE(), hm.created_date) as days_open
    FROM hostel_maintenance hm
    LEFT JOIN hostels h ON hm.hostel_id = h.hostel_id
    LEFT JOIN hostel_rooms hr ON hm.room_id = hr.room_id
    {$where_clause}
    ORDER BY 
        CASE hm.priority 
            WHEN 'Emergency' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Medium' THEN 3
            WHEN 'Low' THEN 4
        END,
        hm.created_date DESC
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$maintenance_items = $stmt->fetchAll();

// Get data for filters - CORRECTED QUERIES
$hostels_stmt = $pdo->query("SELECT hostel_id, hostel_name, hostel_code FROM hostels ORDER BY hostel_name");
$all_hostels = $hostels_stmt->fetchAll();

// Get rooms for filter - CORRECTED QUERY
$rooms_stmt = $pdo->query("
    SELECT hr.room_id, hr.room_number, h.hostel_name 
    FROM hostel_rooms hr
    JOIN hostels h ON hr.hostel_id = h.hostel_id
    ORDER BY h.hostel_name, CAST(hr.room_number AS UNSIGNED)
");
$all_rooms = $rooms_stmt->fetchAll();

// Categories and statuses
$categories = ['Plumbing', 'Electrical', 'Carpentry', 'Painting', 'Furniture', 'Sanitary', 'Structural', 'Other'];
$priorities = ['Emergency', 'High', 'Medium', 'Low'];
$status_options = ['Pending', 'In Progress', 'Completed', 'Cancelled'];

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_items'])) {
        $selected_ids = $_POST['selected_items'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'in_progress':
                    $stmt = $pdo->prepare("UPDATE hostel_maintenance SET status = 'In Progress' WHERE maintenance_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " item(s) marked as In Progress!";
                    break;
                    
                case 'complete':
                    $stmt = $pdo->prepare("UPDATE hostel_maintenance SET status = 'Completed', completed_date = CURDATE() WHERE maintenance_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " item(s) marked as Completed!";
                    break;
                    
                case 'cancel':
                    $stmt = $pdo->prepare("UPDATE hostel_maintenance SET status = 'Cancelled' WHERE maintenance_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " item(s) cancelled!";
                    break;
                    
                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM hostel_maintenance WHERE maintenance_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " item(s) deleted!";
                    break;
            }
        }
        
        header("Location: hostel_maintenance.php");
        exit();
    }
    
    // Add new maintenance item
    if (isset($_POST['add_maintenance'])) {
        $hostel_id_add = (int)$_POST['hostel_id'];
        $room_id_add = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $category = $_POST['category'];
        $priority = $_POST['priority'];
        $reported_by = trim($_POST['reported_by']);
        $assigned_to = trim($_POST['assigned_to']);
        $estimated_cost = !empty($_POST['estimated_cost']) ? (float)$_POST['estimated_cost'] : 0;
        $estimated_completion = !empty($_POST['estimated_completion']) ? $_POST['estimated_completion'] : null;
        $notes = trim($_POST['notes']);
        
        try {
            $insert_sql = "INSERT INTO hostel_maintenance (
                hostel_id, room_id, title, description, category, priority,
                reported_by, assigned_to, estimated_cost, estimated_completion,
                notes, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $hostel_id_add, $room_id_add, $title, $description, $category, $priority,
                $reported_by, $assigned_to, $estimated_cost, $estimated_completion,
                $notes
            ]);
            
            // If room is specified, mark it under maintenance
            if ($room_id_add) {
                $update_room = $pdo->prepare("UPDATE hostel_rooms SET status = 'Under Maintenance' WHERE room_id = ?");
                $update_room->execute([$room_id_add]);
            }
            
            $_SESSION['success_message'] = "Maintenance request added successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding maintenance request: " . $e->getMessage();
        }
        
        header("Location: hostel_maintenance.php");
        exit();
    }
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Hostel Maintenance</h1>
    <div class="app-actions">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
            <i class="fas fa-plus-circle me-2"></i>New Request
        </button>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters & Search
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Hostel</label>
                <select class="form-select" name="hostel_id">
                    <option value="">All Hostels</option>
                    <?php foreach ($all_hostels as $h): ?>
                    <option value="<?php echo $h['hostel_id']; ?>"
                        <?php echo ($hostel_id == $h['hostel_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($h['hostel_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Room</label>
                <select class="form-select" name="room_id">
                    <option value="">All Rooms</option>
                    <?php foreach ($all_rooms as $r): ?>
                    <option value="<?php echo $r['room_id']; ?>"
                        <?php echo ($room_id == $r['room_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($r['hostel_name']); ?> - Room <?php echo htmlspecialchars($r['room_number']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat; ?>"
                        <?php echo ($category == $cat) ? 'selected' : ''; ?>>
                        <?php echo $cat; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select class="form-select" name="priority">
                    <option value="">All Priorities</option>
                    <?php foreach ($priorities as $p): ?>
                    <option value="<?php echo $p; ?>"
                        <?php echo ($priority == $p) ? 'selected' : ''; ?>>
                        <?php echo $p; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $opt): ?>
                    <option value="<?php echo $opt; ?>"
                        <?php echo ($status_filter == $opt) ? 'selected' : ''; ?>>
                        <?php echo $opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-12 d-flex align-items-end justify-content-between">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="hostel_maintenance.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Requests</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-tools text-primary"></i> All Requests
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Pending</div>
                <div class="stats-figure">
                    <?php 
                    $pending = $pdo->query("SELECT COUNT(*) FROM hostel_maintenance WHERE status = 'Pending'")->fetchColumn();
                    echo number_format($pending);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-clock"></i> Awaiting Action
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">In Progress</div>
                <div class="stats-figure">
                    <?php 
                    $in_progress = $pdo->query("SELECT COUNT(*) FROM hostel_maintenance WHERE status = 'In Progress'")->fetchColumn();
                    echo number_format($in_progress);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-wrench"></i> Being Fixed
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Emergency</div>
                <div class="stats-figure">
                    <?php 
                    $emergency = $pdo->query("SELECT COUNT(*) FROM hostel_maintenance WHERE priority = 'Emergency' AND status IN ('Pending', 'In Progress')")->fetchColumn();
                    echo number_format($emergency);
                    ?>
                </div>
                <div class="stats-meta text-danger">
                    <i class="fas fa-exclamation-triangle"></i> Urgent
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Maintenance Requests</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> requests
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <!-- Bulk Actions Form -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="cell" width="30">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="select-all">
                                </div>
                            </th>
                            <th class="cell">Request Details</th>
                            <th class="cell">Location</th>
                            <th class="cell">Category & Priority</th>
                            <th class="cell">Reported By</th>
                            <th class="cell">Timeline</th>
                            <th class="cell">Cost</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($maintenance_items)): ?>
                            <?php foreach ($maintenance_items as $item): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_items[]" 
                                               value="<?php echo $item['maintenance_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['title']); ?></div>
                                    <div class="small text-muted mb-1">
                                        <?php echo substr(htmlspecialchars($item['description']), 0, 100); ?>
                                        <?php if (strlen($item['description']) > 100): ?>...<?php endif; ?>
                                    </div>
                                    <?php if ($item['notes']): ?>
                                    <div class="small text-info">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <?php echo substr(htmlspecialchars($item['notes']), 0, 50); ?>
                                        <?php if (strlen($item['notes']) > 50): ?>...<?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php if ($item['hostel_name']): ?>
                                    <div class="fw-bold"><?php echo htmlspecialchars($item['hostel_code']); ?></div>
                                    <div class="small"><?php echo htmlspecialchars($item['hostel_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($item['room_number']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-door-closed me-1"></i>Room <?php echo $item['room_number']; ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="small text-muted">Common Area</div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="mb-1">
                                        <span class="badge bg-secondary">
                                            <?php echo $item['category']; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <?php 
                                        $priority_class = [
                                            'Emergency' => 'danger',
                                            'High' => 'warning',
                                            'Medium' => 'info',
                                            'Low' => 'secondary'
                                        ][$item['priority']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $priority_class; ?>">
                                            <i class="fas fa-flag me-1"></i><?php echo $item['priority']; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="small fw-bold"><?php echo htmlspecialchars($item['reported_by']); ?></div>
                                    <div class="small text-muted">
                                        <?php echo date('d M Y', strtotime($item['created_date'])); ?>
                                    </div>
                                    <?php if ($item['assigned_to']): ?>
                                    <div class="small text-success mt-1">
                                        <i class="fas fa-user-check me-1"></i>
                                        <?php echo htmlspecialchars($item['assigned_to']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <i class="fas fa-calendar-plus me-1"></i>
                                        <?php echo date('d M Y', strtotime($item['created_date'])); ?>
                                    </div>
                                    <?php if ($item['estimated_completion']): ?>
                                    <div class="small">
                                        <i class="fas fa-calendar-check me-1"></i>
                                        <?php echo date('d M Y', strtotime($item['estimated_completion'])); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?php 
                                        if ($item['estimated_completion']) {
                                            $days_to_complete = ceil((strtotime($item['estimated_completion']) - time()) / (60 * 60 * 24));
                                            if ($days_to_complete > 0) {
                                                echo $days_to_complete . ' days remaining';
                                            } elseif ($days_to_complete < 0) {
                                                echo abs($days_to_complete) . ' days overdue';
                                            } else {
                                                echo 'Due today';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($item['days_open'] > 0): ?>
                                    <div class="small text-warning">
                                        Open for <?php echo $item['days_open']; ?> days
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php if ($item['estimated_cost'] > 0): ?>
                                    <div class="fw-bold">
                                        ₦<?php echo number_format($item['estimated_cost'], 2); ?>
                                    </div>
                                    <div class="small text-muted">Estimated</div>
                                    <?php if ($item['actual_cost']): ?>
                                    <div class="small">
                                        Actual: ₦<?php echo number_format($item['actual_cost'], 2); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">Not specified</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Pending' => 'warning',
                                        'In Progress' => 'info',
                                        'Completed' => 'success',
                                        'Cancelled' => 'secondary'
                                    ][$item['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $item['status']; ?>
                                    </span>
                                    <?php if ($item['completed_date']): ?>
                                    <div class="small text-muted mt-1">
                                        <?php echo date('d M Y', strtotime($item['completed_date'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="dropdown" 
                                                data-bs-auto-close="outside">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="view_maintenance.php?id=<?php echo $item['maintenance_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <?php if ($item['status'] == 'Pending'): ?>
                                            <li>
                                                <a class="dropdown-item text-info" 
                                                   href="#" 
                                                   onclick="markInProgress(<?php echo $item['maintenance_id']; ?>)">
                                                    <i class="fas fa-play me-2"></i>Start Work
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($item['status'] == 'In Progress'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" 
                                                   href="#" 
                                                   onclick="markComplete(<?php echo $item['maintenance_id']; ?>)">
                                                    <i class="fas fa-check me-2"></i>Mark Complete
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="edit_maintenance.php?id=<?php echo $item['maintenance_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <?php if ($item['status'] != 'Completed'): ?>
                                            <li>
                                                <a class="dropdown-item text-secondary" 
                                                   href="#" 
                                                   onclick="cancelRequest(<?php echo $item['maintenance_id']; ?>)">
                                                    <i class="fas fa-ban me-2"></i>Cancel
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="deleteRequest(<?php echo $item['maintenance_id']; ?>)">
                                                    <i class="fas fa-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                                        <h5>No maintenance requests found</h5>
                                        <p class="text-muted">No requests match your search criteria.</p>
                                        <?php if ($hostel_id || $room_id || $category || $priority || $status_filter): ?>
                                        <a href="hostel_maintenance.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                                            <i class="fas fa-plus-circle me-1"></i>Create New Request
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <!-- Table Footer -->
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="select-all-bottom">
                    <label class="form-check-label" for="select-all-bottom">
                        Select All
                    </label>
                </div>
                <div class="btn-group ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bulk-actions-btn">
                        Bulk Actions
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" 
                            data-bs-toggle="dropdown">
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item text-info" href="#" onclick="submitBulkAction('in_progress')">
                            <i class="fas fa-play me-2"></i>Mark In Progress
                        </a></li>
                        <li><a class="dropdown-item text-success" href="#" onclick="submitBulkAction('complete')">
                            <i class="fas fa-check me-2"></i>Mark Complete
                        </a></li>
                        <li><a class="dropdown-item text-secondary" href="#" onclick="submitBulkAction('cancel')">
                            <i class="fas fa-ban me-2"></i>Cancel Selected
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('delete')">
                            <i class="fas fa-trash me-2"></i>Delete Selected
                        </a></li>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php 
                                echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); 
                            ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        if ($start_page > 1): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php 
                                echo http_build_query(array_merge($_GET, ['page' => $i])); 
                            ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php 
                                echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); 
                            ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Maintenance Modal -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1" aria-labelledby="addMaintenanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addMaintenanceModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>New Maintenance Request
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addMaintenanceForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hostel *</label>
                            <select class="form-select" name="hostel_id" id="hostelSelect" required>
                                <option value="">Select Hostel</option>
                                <?php foreach ($all_hostels as $h): ?>
                                <option value="<?php echo $h['hostel_id']; ?>">
                                    <?php echo htmlspecialchars($h['hostel_name']); ?> (<?php echo htmlspecialchars($h['hostel_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Room (Optional)</label>
                            <select class="form-select" name="room_id" id="roomSelect">
                                <option value="">Common Area (No specific room)</option>
                                <?php foreach ($all_rooms as $r): ?>
                                <option value="<?php echo $r['room_id']; ?>">
                                    <?php echo htmlspecialchars($r['hostel_name']); ?> - Room <?php echo htmlspecialchars($r['room_number']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Leave as "Common Area" for maintenance in shared spaces</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" required
                                   placeholder="e.g., Leaking faucet, Broken window, AC not working">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Priority *</label>
                            <select class="form-select" name="priority" required>
                                <?php foreach ($priorities as $p): ?>
                                <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select class="form-select" name="category" required>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reported By *</label>
                            <input type="text" class="form-control" name="reported_by" required
                                   placeholder="e.g., Room occupant, Warden, Staff">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" rows="3" required
                                  placeholder="Detailed description of the issue..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assigned To (Optional)</label>
                            <input type="text" class="form-control" name="assigned_to"
                                   placeholder="e.g., Maintenance staff name">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Estimated Cost (₦)</label>
                            <input type="number" class="form-control" name="estimated_cost" 
                                   min="0" step="100" placeholder="0.00">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Estimated Completion (Optional)</label>
                            <input type="date" class="form-control" name="estimated_completion">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Additional information or instructions..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Important Notes:</h6>
                        <ul class="mb-0 small">
                            <li>Requests marked as "Emergency" will be prioritized</li>
                            <li>If a room is specified, it will be marked as "Under Maintenance"</li>
                            <li>Common area requests don't affect room availability</li>
                            <li>You can assign staff and track costs as work progresses</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addMaintenanceForm" name="add_maintenance" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all-bottom').checked = this.checked;
});

document.getElementById('select-all-bottom').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one maintenance request.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'in_progress':
            confirmMessage = `Mark ${selectedIds.length} selected request(s) as In Progress?`;
            break;
        case 'complete':
            confirmMessage = `Mark ${selectedIds.length} selected request(s) as Completed?`;
            break;
        case 'cancel':
            confirmMessage = `Cancel ${selectedIds.length} selected request(s)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected request(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected item IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Individual action functions
function markInProgress(maintenanceId) {
    if (confirm('Are you sure you want to start work on this request?')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_items[]';
        input.value = maintenanceId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'in_progress';
        form.submit();
    }
}

function markComplete(maintenanceId) {
    if (confirm('Are you sure you want to mark this request as completed?')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_items[]';
        input.value = maintenanceId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'complete';
        form.submit();
    }
}

function cancelRequest(maintenanceId) {
    if (confirm('Are you sure you want to cancel this maintenance request?')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_items[]';
        input.value = maintenanceId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'cancel';
        form.submit();
    }
}

function deleteRequest(maintenanceId) {
    if (confirm('Are you sure you want to delete this maintenance request?')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_items[]';
        input.value = maintenanceId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Validate add maintenance form
document.getElementById('addMaintenanceForm').addEventListener('submit', function(e) {
    const title = this.querySelector('input[name="title"]').value.trim();
    const description = this.querySelector('textarea[name="description"]').value.trim();
    const reportedBy = this.querySelector('input[name="reported_by"]').value.trim();
    
    if (!title || !description || !reportedBy) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    if (!confirm('Are you sure you want to submit this maintenance request?')) {
        e.preventDefault();
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>