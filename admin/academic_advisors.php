<?php
// academic_advisors.php - Fixed version
ob_start();
require_once 'includes/header.php';
$page_title = "Academic Advisors Management";

// Helper function to safely format numbers
function safeNumberFormat($num, $decimals = 0) {
    if ($num === null || $num === '') {
        return '0';
    }
    return number_format((float)$num, $decimals);
}

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

// Build query
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ? OR a.staff_id LIKE ? OR d.department_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

if ($status !== '') {
    $conditions[] = "a.status = ?";
    $params[] = $status;
}

if ($department_id > 0) {
    $conditions[] = "a.department_id = ?";
    $params[] = $department_id;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records
$count_sql = "
    SELECT COUNT(*) as total 
    FROM academic_advisors a
    LEFT JOIN departments d ON a.department_id = d.department_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_records = $total_records !== false ? (int)$total_records : 0;
$total_pages = $total_records > 0 ? ceil($total_records / $records_per_page) : 1;

// Get advisors
$sql = "
    SELECT 
        a.*,
        d.department_name,
        d.department_code,
        COALESCE((SELECT COUNT(*) FROM student_advisors WHERE advisor_id = a.advisor_id AND status = 'Active'), 0) as current_students_count
    FROM academic_advisors a
    LEFT JOIN departments d ON a.department_id = d.department_id
    {$where_clause}
    ORDER BY a.last_name, a.first_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$advisors = $stmt->fetchAll();

// Get departments for filter
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$status_options = ['Active', 'Inactive'];

// Get statistics with COALESCE to handle NULL values
$total_advisors = (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM academic_advisors")->fetchColumn());
$active_count = (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM academic_advisors WHERE status = 'Active'")->fetchColumn());
$total_capacity = (int)($pdo->query("SELECT COALESCE(SUM(max_students), 0) FROM academic_advisors WHERE status = 'Active'")->fetchColumn());
$total_assigned = (int)($pdo->query("SELECT COALESCE(SUM(current_students), 0) FROM academic_advisors WHERE status = 'Active'")->fetchColumn());

// Form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid security token";
        header("Location: academic_advisors.php");
        exit();
    }
    
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_advisors'])) {
        $selected_ids = $_POST['selected_advisors'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE academic_advisors SET status = 'Active' WHERE advisor_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " advisor(s) activated!";
                    break;
                    
                case 'deactivate':
                    $stmt = $pdo->prepare("UPDATE academic_advisors SET status = 'Inactive' WHERE advisor_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " advisor(s) deactivated!";
                    break;
                    
                case 'delete':
                    // Check if advisors have assigned students
                    $check_sql = "SELECT staff_id, 
                                 (SELECT COUNT(*) FROM student_advisors WHERE advisor_id IN ($placeholders)) as student_count
                          FROM academic_advisors 
                          WHERE advisor_id IN ($placeholders)";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute(array_merge($selected_ids, $selected_ids));
                    $advisors_check = $check_stmt->fetchAll();
                    
                    $deletable = [];
                    $non_deletable = [];
                    
                    foreach ($advisors_check as $advisor) {
                        if ($advisor['student_count'] > 0) {
                            $non_deletable[] = $advisor['staff_id'];
                        } else {
                            $deletable[] = $advisor['advisor_id'];
                        }
                    }
                    
                    if (!empty($deletable)) {
                        $deletable_placeholders = implode(',', array_fill(0, count($deletable), '?'));
                        $delete_stmt = $pdo->prepare("DELETE FROM academic_advisors WHERE advisor_id IN ($deletable_placeholders)");
                        $delete_stmt->execute($deletable);
                        $deleted_count = count($deletable);
                    } else {
                        $deleted_count = 0;
                    }
                    
                    $message = "Deleted {$deleted_count} advisor(s).";
                    if (!empty($non_deletable)) {
                        $message .= " Could not delete: " . implode(', ', $non_deletable) . " (has assigned students)";
                    }
                    
                    $_SESSION['success_message'] = $message;
                    break;
            }
        }
        
        header("Location: academic_advisors.php");
        exit();
    }
    
    // Add new advisor
    if (isset($_POST['add_advisor'])) {
        $staff_id = trim($_POST['staff_id']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $max_students = (int)$_POST['max_students'];
        $status = $_POST['status'];
        
        try {
            // Check for existing email or staff ID
            $check_sql = "SELECT advisor_id FROM academic_advisors WHERE email = ? OR staff_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$email, $staff_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Email or Staff ID already exists!";
            } else {
                $insert_sql = "INSERT INTO academic_advisors (
                    staff_id, first_name, last_name, email, phone, 
                    department_id, max_students, current_students, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)";
                
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $staff_id, $first_name, $last_name, $email, $phone,
                    $department_id, $max_students, $status
                ]);
                
                $_SESSION['success_message'] = "Academic advisor added successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding advisor: " . $e->getMessage();
        }
        
        header("Location: academic_advisors.php");
        exit();
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-chalkboard-user me-2"></i>Academic Advisors
    </h1>
    <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addAdvisorModal">
        <i class="fas fa-plus-circle me-2"></i>Add Advisor
    </button>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Name, email, staff ID..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        <?php echo ($department_id == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $opt): ?>
                    <option value="<?php echo $opt; ?>"
                        <?php echo ($status == $opt) ? 'selected' : ''; ?>>
                        <?php echo $opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="academic_advisors.php" class="btn btn-outline-secondary">
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
                <div class="stats-type">Total Advisors</div>
                <div class="stats-figure"><?php echo safeNumberFormat($total_advisors); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-chalkboard-user text-primary"></i> All Advisors
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Advisors</div>
                <div class="stats-figure"><?php echo safeNumberFormat($active_count); ?></div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently Active
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Student Capacity</div>
                <div class="stats-figure"><?php echo safeNumberFormat($total_capacity); ?></div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-users"></i> Max Students
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Assigned Students</div>
                <div class="stats-figure"><?php echo safeNumberFormat($total_assigned); ?></div>
                <div class="stats-meta text-info">
                    <i class="fas fa-user-check"></i> Current Students
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Advisors Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Academic Advisors List</h5>
                <div class="text-muted small">
                    Showing <?php echo $total_records > 0 ? number_format(min($offset + 1, $total_records)) : '0'; ?> - 
                    <?php echo $total_records > 0 ? number_format(min($offset + $records_per_page, $total_records)) : '0'; ?> 
                    of <?php echo number_format($total_records); ?> advisors
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="cell" width="30">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="select-all">
                                </div>
                            </th>
                            <th class="cell">Advisor</th>
                            <th class="cell">Department</th>
                            <th class="cell">Contact</th>
                            <th class="cell">Students</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($advisors)): ?>
                            <?php foreach ($advisors as $advisor): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_advisors[]" 
                                               value="<?php echo $advisor['advisor_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($advisor['staff_id']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($advisor['email']); ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($advisor['department_name']): ?>
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($advisor['department_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($advisor['department_code'] ?? ''); ?></div>
                                    <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <?php if ($advisor['phone']): ?>
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($advisor['phone']); ?>
                                        <?php else: ?>
                                        <span class="text-muted">No phone</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <?php 
                                            $max_students = (int)$advisor['max_students'];
                                            $current = (int)$advisor['current_students_count'];
                                            $percentage = $max_students > 0 ? ($current / $max_students) * 100 : 0;
                                            $progress_class = $percentage >= 90 ? 'bg-danger' : ($percentage >= 70 ? 'bg-warning' : 'bg-success');
                                            ?>
                                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo min($percentage, 100); ?>%">
                                            </div>
                                        </div>
                                        <div class="ms-2 small text-muted">
                                            <?php echo $current; ?>/<?php echo $max_students; ?>
                                        </div>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <?php echo $max_students - $current; ?> slots available
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = $advisor['status'] == 'Active' ? 'success' : 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $advisor['status']; ?>
                                    </span>
                                </td>
                                <td class="cell text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="view_advisor.php?id=<?php echo $advisor['advisor_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="advisor_students.php?id=<?php echo $advisor['advisor_id']; ?>">
                                                    <i class="fas fa-users me-2"></i>View Students
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="edit_advisor.php?id=<?php echo $advisor['advisor_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $advisor['advisor_id']; ?>)">
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
                                <td colspan="7" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-chalkboard-user fa-3x text-muted mb-3"></i>
                                        <h5>No advisors found</h5>
                                        <p class="text-muted">No academic advisors match your search criteria.</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdvisorModal">
                                            <i class="fas fa-plus-circle me-1"></i>Add New Advisor
                                        </button>
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
                        <li><a class="dropdown-item text-success" href="#" onclick="submitBulkAction('activate')">
                            <i class="fas fa-check me-2"></i>Activate Selected
                        </a></li>
                        <li><a class="dropdown-item text-secondary" href="#" onclick="submitBulkAction('deactivate')">
                            <i class="fas fa-times me-2"></i>Deactivate Selected
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
            </div>
        </div>
    </div>
</div>

<!-- Add Advisor Modal -->
<div class="modal fade" id="addAdvisorModal" tabindex="-1" aria-labelledby="addAdvisorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addAdvisorModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Add Academic Advisor
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addAdvisorForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff ID *</label>
                            <input type="text" class="form-control" name="staff_id" required
                                   placeholder="e.g., STAFF001" maxlength="20">
                            <div class="form-text">Unique staff identification number</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required
                                   placeholder="advisor@university.edu">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required
                                   placeholder="e.g., John">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required
                                   placeholder="e.g., Doe">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone"
                                   placeholder="e.g., 08012345678">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Students *</label>
                            <input type="number" class="form-control" name="max_students" required
                                   min="1" max="50" value="20">
                            <div class="form-text">Maximum number of students this advisor can supervise</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addAdvisorForm" name="add_advisor" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Add Advisor
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
        alert('Please select at least one advisor.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected advisor(s)?`;
            break;
        case 'deactivate':
            confirmMessage = `Deactivate ${selectedIds.length} selected advisor(s)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected advisor(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected advisor IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Single delete
function confirmDelete(advisorId) {
    if (confirm('Are you sure you want to delete this advisor? This action cannot be undone.')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_advisors[]';
        input.value = advisorId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>