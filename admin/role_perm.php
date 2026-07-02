<?php
ob_start();
require_once 'includes/header.php';
$page_title = "Roles & Permissions";

// Only super admin can access this page
if ($admin_role !== 'Super Admin') {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header("Location: dashboard.php");
    exit();
}

// Define available permissions
$permission_categories = [
    'Dashboard' => [
        'dashboard_view' => 'View Dashboard',
        'dashboard_stats' => 'View Statistics',
    ],
    'Student Management' => [
        'students_view' => 'View Students',
        'students_create' => 'Add Students',
        'students_edit' => 'Edit Students',
        'students_delete' => 'Delete Students',
        'students_export' => 'Export Students',
    ],
    'Academic Management' => [
        'courses_view' => 'View Courses',
        'courses_manage' => 'Manage Courses',
        'results_view' => 'View Results',
        'results_upload' => 'Upload Results',
        'results_approve' => 'Approve Results',
        'attendance_view' => 'View Attendance',
        'attendance_manage' => 'Manage Attendance',
        'transcripts_view' => 'View Transcripts',
        'transcripts_generate' => 'Generate Transcripts',
    ],
    'Finance Management' => [
        'fees_view' => 'View Fees',
        'fees_manage' => 'Manage Fees',
        'payments_view' => 'View Payments',
        'payments_process' => 'Process Payments',
        'invoices_generate' => 'Generate Invoices',
    ],
    'Hostel Management' => [
        'hostels_view' => 'View Hostels',
        'hostels_manage' => 'Manage Hostels',
        'allocations_view' => 'View Allocations',
        'allocations_manage' => 'Manage Allocations',
        'maintenance_view' => 'View Maintenance',
        'maintenance_manage' => 'Manage Maintenance',
    ],
    'Staff Management' => [
        'staff_view' => 'View Staff',
        'staff_manage' => 'Manage Staff',
        'advisors_view' => 'View Advisors',
        'advisors_manage' => 'Manage Advisors',
    ],
    'System Management' => [
        'settings_view' => 'View Settings',
        'settings_manage' => 'Manage Settings',
        'users_view' => 'View Users',
        'users_manage' => 'Manage Users',
        'roles_view' => 'View Roles',
        'roles_manage' => 'Manage Roles',
        'backup_view' => 'View Backup',
        'backup_manage' => 'Manage Backup',
    ],
    'Reports' => [
        'reports_view' => 'View Reports',
        'reports_generate' => 'Generate Reports',
        'reports_export' => 'Export Reports',
    ],
    'Notifications' => [
        'notifications_view' => 'View Notifications',
        'notifications_send' => 'Send Notifications',
        'notifications_manage' => 'Manage Templates',
    ],
];

// Get existing admin roles
$roles = $pdo->query("SELECT DISTINCT role FROM admin_users ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);

// Get permissions for each role
$role_permissions = [];
foreach ($roles as $role) {
    $stmt = $pdo->prepare("SELECT permissions FROM admin_users WHERE role = ? LIMIT 1");
    $stmt->execute([$role]);
    $permissions_json = $stmt->fetchColumn();
    
    if ($permissions_json) {
        $permissions = json_decode($permissions_json, true);
        $role_permissions[$role] = $permissions;
    } else {
        $role_permissions[$role] = [];
    }
}

// Form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_permissions'])) {
        $role = $_POST['role'];
        $permissions = $_POST['permissions'] ?? [];
        
        // Convert array of permissions to JSON structure
        $permission_data = [];
        foreach ($permissions as $perm) {
            $permission_data[$perm] = true;
        }
        
        // Add all:true for Super Admin
        if ($role === 'Super Admin') {
            $permission_data['all'] = true;
        }
        
        $permissions_json = json_encode($permission_data);
        
        try {
            // Update all users with this role
            $update_sql = "UPDATE admin_users SET permissions = ? WHERE role = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$permissions_json, $role]);
            
            $_SESSION['success_message'] = "Permissions updated successfully for $role role!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating permissions: " . $e->getMessage();
        }
        
        header("Location: role_perm.php");
        exit();
    }
    
    if (isset($_POST['create_role'])) {
        $role_name = trim($_POST['role_name']);
        $role_description = trim($_POST['role_description']);
        
        if (in_array($role_name, $roles)) {
            $_SESSION['error_message'] = "Role already exists!";
        } else {
            // Create a sample user with this role for testing
            $sample_username = strtolower(str_replace(' ', '_', $role_name)) . '_demo';
            $sample_email = $sample_username . '@university.edu';
            
            try {
                // Check if demo user already exists
                $check_sql = "SELECT admin_id FROM admin_users WHERE username = ? OR email = ?";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$sample_username, $sample_email]);
                
                if ($check_stmt->rowCount() > 0) {
                    $_SESSION['error_message'] = "Demo user for this role already exists!";
                } else {
                    // Insert demo user
                    $insert_sql = "INSERT INTO admin_users (
                        username, email, password_hash, full_name, role, 
                        status, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'Active', ?, NOW())";
                    
                    $password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
                    $full_name = ucfirst(str_replace('_', ' ', $sample_username));
                    
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $sample_username, $sample_email, $password_hash, 
                        $full_name, $role_name, $admin_id
                    ]);
                    
                    $_SESSION['success_message'] = "Role '$role_name' created successfully with demo user!";
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error creating role: " . $e->getMessage();
            }
        }
        
        header("Location: role_perm.php");
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
        <i class="fas fa-user-tag me-2"></i>Roles & Permissions
    </h1>
    <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
        <i class="fas fa-plus-circle me-2"></i>Create Role
    </button>
</div>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Roles</div>
                <div class="stats-figure"><?php echo count($roles); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-user-tag text-primary"></i> System Roles
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Users by Role</div>
                <div class="stats-figure">
                    <?php 
                    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users");
                    $user_count = $stmt->fetchColumn();
                    echo number_format($user_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-users"></i> Total Users
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Permission Categories</div>
                <div class="stats-figure"><?php echo count($permission_categories); ?></div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-folder"></i> Categories
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Permissions</div>
                <div class="stats-figure">
                    <?php
                    $total_perms = 0;
                    foreach ($permission_categories as $perms) {
                        $total_perms += count($perms);
                    }
                    echo $total_perms;
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-key"></i> Permissions
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Role Selection Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-cog me-2"></i>Select Role to Manage Permissions
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Role</label>
                <select class="form-select" name="selected_role" id="roleSelect" onchange="this.form.submit()">
                    <option value="">Select a role...</option>
                    <?php 
                    $selected_role = isset($_GET['selected_role']) ? $_GET['selected_role'] : '';
                    foreach ($roles as $role): 
                    // Get user count for this role
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = ?");
                    $stmt->execute([$role]);
                    $role_user_count = $stmt->fetchColumn();
                    ?>
                    <option value="<?php echo $role; ?>"
                        <?php echo ($selected_role == $role) ? 'selected' : ''; ?>>
                        <?php echo $role; ?> (<?php echo $role_user_count; ?> users)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-4 d-flex align-items-end">
                <div class="d-grid w-100">
                    <?php if ($selected_role): ?>
                    <a href="role_perm.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Clear Selection
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled>
                        <i class="fas fa-cog me-1"></i>Manage Permissions
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_role): ?>
<!-- Permissions Management -->
<div class="app-card app-card-table shadow-sm mb-4">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">
                    <i class="fas fa-key me-2"></i>Permissions for: 
                    <span class="text-primary"><?php echo $selected_role; ?></span>
                </h5>
                <div class="text-muted small">
                    <?php 
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role = ?");
                    $stmt->execute([$selected_role]);
                    $role_user_count = $stmt->fetchColumn();
                    echo number_format($role_user_count) . " user(s) have this role";
                    ?>
                </div>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPermissions()">
                    <i class="fas fa-check-square me-1"></i>Select All
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="deselectAllPermissions()">
                    <i class="fas fa-square me-1"></i>Deselect All
                </button>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <form method="POST" id="permissionsForm">
            <input type="hidden" name="role" value="<?php echo $selected_role; ?>">
            
            <div class="p-3">
                <?php 
                $current_permissions = $role_permissions[$selected_role] ?? [];
                $has_all_access = isset($current_permissions['all']) && $current_permissions['all'] === true;
                ?>
                
                <?php if ($selected_role === 'Super Admin'): ?>
                <div class="alert alert-warning mb-4">
                    <h6><i class="fas fa-crown me-2"></i>Super Admin Role</h6>
                    <p class="mb-0">Super Admin has full access to all system features. Individual permissions cannot be modified.</p>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <?php foreach ($permission_categories as $category => $permissions): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100 border">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-folder me-2"></i><?php echo $category; ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($permissions as $key => $label): 
                                $is_checked = $has_all_access || isset($current_permissions[$key]);
                                ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input permission-checkbox" 
                                           type="checkbox" 
                                           name="permissions[]" 
                                           value="<?php echo $key; ?>"
                                           id="perm_<?php echo $key; ?>"
                                           <?php echo ($is_checked) ? 'checked' : ''; ?>
                                           <?php echo ($selected_role === 'Super Admin') ? 'disabled' : ''; ?>>
                                    <label class="form-check-label" for="perm_<?php echo $key; ?>">
                                        <?php echo $label; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="app-card-footer p-3 border-top">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small">
                            Selected: <span id="selectedCount">0</span> permissions
                        </span>
                    </div>
                    <div>
                        <?php if ($selected_role !== 'Super Admin'): ?>
                        <button type="submit" name="save_permissions" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Permissions
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>
                            <i class="fas fa-lock me-2"></i>Cannot Modify Super Admin
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Users with this Role -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-users me-2"></i>Users with <?php echo $selected_role; ?> Role
        </h5>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell">User</th>
                        <th class="cell">Email</th>
                        <th class="cell">Status</th>
                        <th class="cell">Last Login</th>
                        <th class="cell text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $users_sql = "
                        SELECT admin_id, username, email, full_name, status, last_login, created_at
                        FROM admin_users 
                        WHERE role = ? 
                        ORDER BY status DESC, full_name
                    ";
                    $users_stmt = $pdo->prepare($users_sql);
                    $users_stmt->execute([$selected_role]);
                    $role_users = $users_stmt->fetchAll();
                    
                    if (!empty($role_users)): 
                        foreach ($role_users as $user):
                    ?>
                    <tr>
                        <td class="cell">
                            <div class="fw-bold"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="small text-muted">
                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($user['username']); ?>
                            </div>
                        </td>
                        <td class="cell">
                            <?php echo htmlspecialchars($user['email']); ?>
                            <div class="small text-muted">
                                Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                            </div>
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
                        <td class="cell">
                            <?php if ($user['last_login']): ?>
                            <div class="small fw-bold"><?php echo date('Y-m-d H:i', strtotime($user['last_login'])); ?></div>
                            <div class="small text-muted">
                                <?php echo timeAgo($user['last_login']); ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell text-end">
                            <a href="edit_admin.php?id=<?php echo $user['admin_id']; ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            <div class="py-3">
                                <i class="fas fa-users fa-2x text-muted mb-3"></i>
                                <h6>No users found with this role</h6>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<!-- No Role Selected -->
<div class="text-center py-5">
    <div class="py-4">
        <i class="fas fa-user-tag fa-4x text-muted mb-4"></i>
        <h3>Select a Role to Manage</h3>
        <p class="text-muted mb-4">Choose a role from the dropdown above to view and manage its permissions.</p>
        <div class="d-flex justify-content-center gap-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                <i class="fas fa-plus-circle me-2"></i>Create New Role
            </button>
            <a href="admin_users.php" class="btn btn-outline-primary">
                <i class="fas fa-users me-2"></i>Manage Users
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Role Modal -->
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createRoleModalLabel">
                    <i class="fas fa-user-tag me-2"></i>Create New Role
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createRoleForm">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        A demo user will be created with this role for testing purposes.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Role Name *</label>
                        <input type="text" class="form-control" name="role_name" required
                               placeholder="e.g., Faculty Head, Department Coordinator" maxlength="50">
                        <div class="form-text">Use descriptive role names</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="role_description" rows="3"
                                  placeholder="Describe the purpose and responsibilities of this role"></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Note:</h6>
                        <ul class="mb-0 small">
                            <li>Default permissions will be minimal</li>
                            <li>You can customize permissions after creation</li>
                            <li>Demo user password will be: <strong>password</strong></li>
                            <li>Demo user can be deleted or modified later</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="createRoleForm" name="create_role" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>Create Role
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Update selected permissions count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.permission-checkbox:checked:not(:disabled)');
    document.getElementById('selectedCount').textContent = checkboxes.length;
}

// Initialize count
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    
    // Add event listeners to checkboxes
    const checkboxes = document.querySelectorAll('.permission-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
});

// Select all permissions
function selectAllPermissions() {
    const checkboxes = document.querySelectorAll('.permission-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateSelectedCount();
}

// Deselect all permissions
function deselectAllPermissions() {
    const checkboxes = document.querySelectorAll('.permission-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateSelectedCount();
}

// Role selection
document.getElementById('roleSelect').addEventListener('change', function() {
    if (this.value) {
        // Show loading state
        const form = this.closest('form');
        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
            submitBtn.disabled = true;
        }
        form.submit();
    }
});

// Validate create role form
document.getElementById('createRoleForm').addEventListener('submit', function(e) {
    const roleName = this.querySelector('input[name="role_name"]').value.trim();
    
    if (!roleName) {
        e.preventDefault();
        alert('Please enter a role name.');
        return false;
    }
    
    if (!confirm('Create new role: ' + roleName + '?')) {
        e.preventDefault();
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>