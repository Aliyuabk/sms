<?php
ob_start();
require_once 'includes/header.php';
$page_title = "Admin Users Management";

// Only super admin can access this page
if ($admin_role !== 'Super Admin') {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header("Location: dashboard.php");
    exit();
}

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role = isset($_GET['role']) ? $_GET['role'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(au.username LIKE ? OR au.email LIKE ? OR au.full_name LIKE ? OR d.department_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if ($role !== '') {
    $conditions[] = "au.role = ?";
    $params[] = $role;
}

if ($status !== '') {
    $conditions[] = "au.status = ?";
    $params[] = $status;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records
$count_sql = "
    SELECT COUNT(*) as total 
    FROM admin_users au
    LEFT JOIN departments d ON au.department_id = d.department_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get admin users
$sql = "
    SELECT 
        au.*,
        d.department_name,
        d.department_code,
        DATE_FORMAT(au.last_login, '%Y-%m-%d %H:%i') as last_login_formatted
    FROM admin_users au
    LEFT JOIN departments d ON au.department_id = d.department_id
    {$where_clause}
    ORDER BY au.created_at DESC
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$admin_users = $stmt->fetchAll();

// Get roles and statuses
$roles_stmt = $pdo->query("SELECT DISTINCT role FROM admin_users ORDER BY role");
$roles = $roles_stmt->fetchAll(PDO::FETCH_COLUMN);
$statuses = ['Active', 'Inactive', 'Suspended', 'Pending'];
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();

// Form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_users'])) {
        $selected_ids = $_POST['selected_users'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE admin_users SET status = 'Active' WHERE admin_id IN ($placeholders) AND admin_id != 1");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " user(s) activated!";
                    break;
                    
                case 'suspend':
                    $stmt = $pdo->prepare("UPDATE admin_users SET status = 'Suspended' WHERE admin_id IN ($placeholders) AND admin_id != 1");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " user(s) suspended!";
                    break;
                    
                case 'reset_password':
                    $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE admin_id IN ($placeholders) AND admin_id != 1");
                    $password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
                    $stmt->execute(array_merge([$password_hash], $selected_ids));
                    $_SESSION['success_message'] = count($selected_ids) . " user password(s) reset to 'password'!";
                    break;
                    
                case 'delete':
                    // Cannot delete super admin (id 1)
                    $filtered_ids = array_filter($selected_ids, function($id) {
                        return $id != 1;
                    });
                    
                    if (!empty($filtered_ids)) {
                        $filtered_placeholders = implode(',', array_fill(0, count($filtered_ids), '?'));
                        $delete_stmt = $pdo->prepare("DELETE FROM admin_users WHERE admin_id IN ($filtered_placeholders)");
                        $delete_stmt->execute($filtered_ids);
                        $deleted_count = count($filtered_ids);
                    } else {
                        $deleted_count = 0;
                    }
                    
                    $message = "Deleted {$deleted_count} user(s).";
                    if (count($filtered_ids) != count($selected_ids)) {
                        $message .= " Cannot delete Super Admin account.";
                    }
                    
                    $_SESSION['success_message'] = $message;
                    break;
            }
        }
        
        header("Location: admin_users.php");
        exit();
    }
    
    // Add new admin user
    if (isset($_POST['add_admin'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $role = $_POST['role'];
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
        $status = $_POST['status'];
        
        try {
            // Check for existing username or email
            $check_sql = "SELECT admin_id FROM admin_users WHERE username = ? OR email = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$username, $email]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Username or Email already exists!";
            } else {
                // Hash password (default: 'password')
                $password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
                
                $insert_sql = "INSERT INTO admin_users (
                    username, email, password_hash, full_name, phone,
                    role, department_id, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $username, $email, $password_hash, $full_name, $phone,
                    $role, $department_id, $status, $admin_id
                ]);
                
                $_SESSION['success_message'] = "Admin user added successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding admin user: " . $e->getMessage();
        }
        
        header("Location: admin_users.php");
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
    <h1 class="app-page-title mb-0">
        <i class="fas fa-user-shield me-2"></i>Admin Users
    </h1>
    <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
        <i class="fas fa-plus-circle me-2"></i>Add Admin
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
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Name, email, username..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Role</label>
                <select class="form-select" name="role">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role_opt): 
                    // Get user count for this role
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = ?");
                    $stmt->execute([$role_opt]);
                    $role_count = $stmt->fetchColumn();
                    ?>
                    <option value="<?php echo $role_opt; ?>"
                        <?php echo ($role == $role_opt) ? 'selected' : ''; ?>>
                        <?php echo $role_opt; ?> (<?php echo $role_count; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $status_opt): ?>
                    <option value="<?php echo $status_opt; ?>"
                        <?php echo ($status == $status_opt) ? 'selected' : ''; ?>>
                        <?php echo $status_opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="admin_users.php" class="btn btn-outline-secondary">
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
                <div class="stats-type">Total Admins</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-user-shield text-primary"></i> All Users
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Users</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE status = 'Active'");
                    $active_count = $stmt->fetchColumn();
                    echo number_format($active_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently Active
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Super Admins</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'Super Admin'");
                    $super_admin_count = $stmt->fetchColumn();
                    echo number_format($super_admin_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-crown"></i> System Admins
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Online Today</div>
                <div class="stats-figure">
                    <?php 
                    $today = date('Y-m-d');
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE DATE(last_login) = ?");
                    $stmt->execute([$today]);
                    $online_count = $stmt->fetchColumn();
                    echo number_format($online_count);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-signal"></i> Active Today
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Admin Users Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Admin Users List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> users
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
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
                            <th class="cell">User</th>
                            <th class="cell">Role & Department</th>
                            <th class="cell">Contact</th>
                            <th class="cell">Last Login</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($admin_users)): ?>
                            <?php foreach ($admin_users as $user): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_users[]" 
                                               value="<?php echo $user['admin_id']; ?>"
                                               <?php echo ($user['admin_id'] == 1) ? 'disabled' : ''; ?>>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                        <?php if ($user['admin_id'] == 1): ?>
                                        <i class="fas fa-crown text-warning ms-1" title="Super Admin"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($user['username']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $role_colors = [
                                        'Super Admin' => 'danger',
                                        'Admin' => 'primary',
                                        'Registrar' => 'info',
                                        'Bursar' => 'success',
                                        'Academic' => 'warning',
                                        'Hostel' => 'secondary'
                                    ];
                                    $role_color = $role_colors[$user['role']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $role_color; ?> mb-1">
                                        <?php echo $user['role']; ?>
                                    </span>
                                    <div class="small text-muted">
                                        <?php if ($user['department_name']): ?>
                                        <i class="fas fa-building me-1"></i><?php echo htmlspecialchars($user['department_name']); ?>
                                        <?php else: ?>
                                        <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <?php if ($user['phone']): ?>
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($user['phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($user['last_login']): ?>
                                    <div class="small fw-bold"><?php echo $user['last_login_formatted']; ?></div>
                                    <div class="small text-muted">
                                        <?php echo timeAgo($user['last_login']); ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Active' => 'success',
                                        'Inactive' => 'secondary',
                                        'Suspended' => 'danger',
                                        'Pending' => 'warning'
                                    ][$user['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $user['status']; ?>
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
                                                <a class="dropdown-item" href="view_admin.php?id=<?php echo $user['admin_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_admin.php?id=<?php echo $user['admin_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit User
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick="resetPassword(<?php echo $user['admin_id']; ?>)">
                                                    <i class="fas fa-key me-2"></i>Reset Password
                                                </a>
                                            </li>
                                            <?php if ($user['admin_id'] != 1): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $user['admin_id']; ?>)">
                                                    <i class="fas fa-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-user-shield fa-3x text-muted mb-3"></i>
                                        <h5>No admin users found</h5>
                                        <p class="text-muted">No admin users match your search criteria.</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                                            <i class="fas fa-plus-circle me-1"></i>Add New Admin
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
                        <li><a class="dropdown-item text-warning" href="#" onclick="submitBulkAction('suspend')">
                            <i class="fas fa-ban me-2"></i>Suspend Selected
                        </a></li>
                        <li><a class="dropdown-item text-primary" href="#" onclick="submitBulkAction('reset_password')">
                            <i class="fas fa-key me-2"></i>Reset Passwords
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

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addAdminModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Add Admin User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addAdminForm">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Default password will be set to <strong>password</strong>. User should change it on first login.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" class="form-control" name="username" required
                                   placeholder="e.g., john.doe" maxlength="50">
                            <div class="form-text">Unique username for login</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required
                                   placeholder="e.g., john.doe@university.edu">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="full_name" required
                                   placeholder="e.g., John Doe">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone"
                                   placeholder="e.g., 08012345678">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="role" required>
                                <option value="">Select Role</option>
                                <option value="Admin">Admin</option>
                                <option value="Registrar">Registrar</option>
                                <option value="Bursar">Bursar</option>
                                <option value="Academic">Academic</option>
                                <option value="Hostel">Hostel</option>
                            </select>
                            <div class="form-text">Note: Only one "Super Admin" can exist.</div>
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
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addAdminForm" name="add_admin" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Add Admin
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all-bottom').checked = this.checked;
});

document.getElementById('select-all-bottom').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one user.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected user(s)?`;
            break;
        case 'suspend':
            confirmMessage = `Suspend ${selectedIds.length} selected user(s)?`;
            break;
        case 'reset_password':
            confirmMessage = `Reset password for ${selectedIds.length} selected user(s) to 'password'?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected user(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected user IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked:not(:disabled)');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Single delete
function confirmDelete(userId) {
    if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_users[]';
        input.value = userId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Reset password
function resetPassword(userId) {
    if (confirm('Reset this user\'s password to "password"?')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_users[]';
        input.value = userId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'reset_password';
        form.submit();
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>