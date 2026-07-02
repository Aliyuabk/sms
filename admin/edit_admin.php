<?php
ob_start();
require_once 'includes/header.php';
$page_title = "Edit Admin User";

// Only super admin can access this page
if ($admin_role !== 'Super Admin') {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header("Location: dashboard.php");
    exit();
}

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($edit_id <= 0) {
    $_SESSION['error_message'] = "Invalid admin ID.";
    header("Location: admin_users.php");
    exit();
}

// Get admin user details
$sql = "
    SELECT au.*, d.department_name
    FROM admin_users au
    LEFT JOIN departments d ON au.department_id = d.department_id
    WHERE au.admin_id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$edit_id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error_message'] = "Admin user not found.";
    header("Location: admin_users.php");
    exit();
}

// Prevent editing the main super admin's critical fields by non-owners
$is_main_admin = ($edit_id == 1);
$is_self = ($edit_id == $admin_id);

// Get departments and roles
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$roles = ['Super Admin', 'Admin', 'Registrar', 'Bursar', 'Academic', 'Hostel'];
$statuses = ['Active', 'Inactive', 'Suspended', 'Pending'];

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update profile
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;

        // Validate email uniqueness (exclude current user)
        $check_email = $pdo->prepare("SELECT admin_id FROM admin_users WHERE email = ? AND admin_id != ?");
        $check_email->execute([$email, $edit_id]);

        if ($check_email->rowCount() > 0) {
            $error_message = "Email address is already in use by another admin.";
        } elseif (empty($full_name) || empty($email)) {
            $error_message = "Full name and email are required fields.";
        } else {
            $update_sql = "
                UPDATE admin_users 
                SET full_name = ?, email = ?, phone = ?, department_id = ?
                WHERE admin_id = ?
            ";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$full_name, $email, $phone, $department_id, $edit_id]);

            $success_message = "Profile updated successfully!";

            // Refresh user data
            $stmt->execute([$edit_id]);
            $user = $stmt->fetch();
        }
    }

    // Update role and status
    if (isset($_POST['update_role_status']) && !$is_main_admin) {
        $role = $_POST['role'];
        $status = $_POST['status'];

        $update_sql = "UPDATE admin_users SET role = ?, status = ? WHERE admin_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$role, $status, $edit_id]);

        $success_message = "Role and status updated successfully!";

        // Refresh user data
        $stmt->execute([$edit_id]);
        $user = $stmt->fetch();
    }

    // Update permissions
    if (isset($_POST['update_permissions'])) {
        $permissions = [];

        if (isset($_POST['perm_all']) && $_POST['perm_all'] == '1') {
            $permissions = ['all' => true];
        } else {
            $perm_fields = [
                'students', 'staff', 'courses', 'departments', 'programs',
                'results', 'fees', 'payments', 'hostels', 'settings',
                'reports', 'logs', 'notifications', 'bulk_actions'
            ];
            foreach ($perm_fields as $perm) {
                if (isset($_POST['perm_' . $perm])) {
                    $permissions[$perm] = true;
                }
            }
        }

        $permissions_json = json_encode($permissions);
        $update_sql = "UPDATE admin_users SET permissions = ? WHERE admin_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$permissions_json, $edit_id]);

        $success_message = "Permissions updated successfully!";

        // Refresh user data
        $stmt->execute([$edit_id]);
        $user = $stmt->fetch();
    }

    // Change password
    if (isset($_POST['change_password'])) {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (strlen($new_password) < 6) {
            $error_message = "Password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match.";
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE admin_users SET password_hash = ? WHERE admin_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$password_hash, $edit_id]);

            $success_message = "Password changed successfully!";
        }
    }

    // Toggle 2FA
    if (isset($_POST['toggle_2fa'])) {
        $new_2fa = $user['two_factor_enabled'] ? 0 : 1;
        $update_sql = "UPDATE admin_users SET two_factor_enabled = ? WHERE admin_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$new_2fa, $edit_id]);

        $success_message = "Two-factor authentication " . ($new_2fa ? "enabled" : "disabled") . " successfully!";

        // Refresh user data
        $stmt->execute([$edit_id]);
        $user = $stmt->fetch();
    }

    // Upload profile image
    if (isset($_POST['upload_image']) && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($file['type'], $allowed_types)) {
                $error_message = "Only JPG, PNG, and GIF images are allowed.";
            } elseif ($file['size'] > $max_size) {
                $error_message = "Image size must be less than 2MB.";
            } else {
                $upload_dir = 'uploads/admin_profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $filename = 'admin_' . $edit_id . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
                $filepath = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old image if exists
                    if ($user['profile_image'] && file_exists($upload_dir . $user['profile_image'])) {
                        unlink($upload_dir . $user['profile_image']);
                    }

                    $update_sql = "UPDATE admin_users SET profile_image = ? WHERE admin_id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([$filename, $edit_id]);

                    $success_message = "Profile image updated successfully!";

                    // Refresh user data
                    $stmt->execute([$edit_id]);
                    $user = $stmt->fetch();
                } else {
                    $error_message = "Failed to upload image. Please try again.";
                }
            }
        }
    }
}

// Parse current permissions
$current_permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
$has_all_permissions = isset($current_permissions['all']) && $current_permissions['all'] === true;

// Role badge colors
$role_colors = [
    'Super Admin' => 'danger',
    'Admin' => 'primary',
    'Registrar' => 'info',
    'Bursar' => 'success',
    'Academic' => 'warning',
    'Hostel' => 'secondary'
];
$role_color = $role_colors[$user['role']] ?? 'secondary';

$status_class = [
    'Active' => 'success',
    'Inactive' => 'secondary',
    'Suspended' => 'danger',
    'Pending' => 'warning'
][$user['status']] ?? 'secondary';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .edit-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .nav-pills .nav-link {
            color: #6c757d;
            font-weight: 500;
            border-radius: 8px;
            padding: 0.75rem 1.25rem;
            margin-bottom: 0.5rem;
        }
        .nav-pills .nav-link:hover {
            background: #f8f9fa;
            color: #4361ee;
        }
        .nav-pills .nav-link.active {
            background: #4361ee;
            color: white;
        }
        .nav-pills .nav-link i {
            width: 24px;
        }
        .form-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 2rem;
        }
        .profile-image-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e9ecef;
        }
        .permission-checkbox {
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .permission-checkbox:hover {
            background: #f8f9fa;
            border-color: #4361ee;
        }
        .permission-checkbox input:checked + label {
            color: #4361ee;
            font-weight: 600;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #4361ee;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="admin_users.php">Admin Users</a></li>
            <li class="breadcrumb-item"><a href="view_admin.php?id=<?php echo $edit_id; ?>"><?php echo htmlspecialchars($user['full_name']); ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>

    <!-- Messages -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="edit-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1"><i class="fas fa-user-edit me-2"></i>Edit Admin User</h2>
                <p class="mb-0 opacity-75">
                    Editing: <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                    <span class="badge bg-<?php echo $role_color; ?> ms-2"><?php echo $user['role']; ?></span>
                    <span class="badge bg-<?php echo $status_class; ?> ms-1"><?php echo $user['status']; ?></span>
                    <?php if ($is_main_admin): ?>
                    <span class="badge bg-warning ms-1"><i class="fas fa-crown me-1"></i>Main Super Admin</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="view_admin.php?id=<?php echo $edit_id; ?>" class="btn btn-light">
                    <i class="fas fa-eye me-2"></i>View Profile
                </a>
                <a href="admin_users.php" class="btn btn-outline-light">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 mb-4">
            <div class="card shadow-sm">
                <div class="card-body p-3">
                    <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button">
                            <i class="fas fa-user me-2"></i>Profile Info
                        </button>
                        <button class="nav-link" id="role-tab" data-bs-toggle="pill" data-bs-target="#role" type="button">
                            <i class="fas fa-id-badge me-2"></i>Role & Status
                        </button>
                        <button class="nav-link" id="permissions-tab" data-bs-toggle="pill" data-bs-target="#permissions" type="button">
                            <i class="fas fa-key me-2"></i>Permissions
                        </button>
                        <button class="nav-link" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button">
                            <i class="fas fa-shield-alt me-2"></i>Security
                        </button>
                        <button class="nav-link" id="image-tab" data-bs-toggle="pill" data-bs-target="#image" type="button">
                            <i class="fas fa-image me-2"></i>Profile Image
                        </button>
                    </div>
                </div>
            </div>

            <!-- Quick Info Card -->
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="text-muted mb-3">Account Info</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">ID:</span>
                        <strong>#<?php echo $user['admin_id']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Username:</span>
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Created:</span>
                        <strong><?php echo date('M d, Y', strtotime($user['created_at'])); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Last Login:</span>
                        <strong><?php echo $user['last_login'] ? timeAgo($user['last_login']) : 'Never'; ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-lg-9">
            <div class="tab-content" id="v-pills-tabContent">

                <!-- Profile Info Tab -->
                <div class="tab-pane fade show active" id="profile">
                    <div class="form-section">
                        <h4 class="mb-4"><i class="fas fa-user-circle me-2 text-primary"></i>Profile Information</h4>
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" 
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                    <div class="form-text">Username cannot be changed.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department</label>
                                    <select class="form-select" name="department_id">
                                        <option value="">-- No Department --</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['department_id']; ?>" 
                                            <?php echo ($user['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Role & Status Tab -->
                <div class="tab-pane fade" id="role">
                    <div class="form-section">
                        <h4 class="mb-4"><i class="fas fa-id-badge me-2 text-primary"></i>Role & Status</h4>

                        <?php if ($is_main_admin): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Protected Account:</strong> The main Super Admin's role and status cannot be modified for system security.
                        </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Role</label>
                                    <select class="form-select" name="role" <?php echo $is_main_admin ? 'disabled' : ''; ?>>
                                        <?php foreach ($roles as $r): ?>
                                        <option value="<?php echo $r; ?>" 
                                            <?php echo ($user['role'] == $r) ? 'selected' : ''; ?>>
                                            <?php echo $r; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($is_main_admin): ?>
                                    <input type="hidden" name="role" value="<?php echo $user['role']; ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status" <?php echo $is_main_admin ? 'disabled' : ''; ?>>
                                        <?php foreach ($statuses as $s): ?>
                                        <option value="<?php echo $s; ?>" 
                                            <?php echo ($user['status'] == $s) ? 'selected' : ''; ?>>
                                            <?php echo $s; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($is_main_admin): ?>
                                    <input type="hidden" name="status" value="<?php echo $user['status']; ?>">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>Role Descriptions</h6>
                                <ul class="mb-0 small">
                                    <li><strong>Super Admin:</strong> Full system access, can manage other admins</li>
                                    <li><strong>Admin:</strong> General administrative access</li>
                                    <li><strong>Registrar:</strong> Student registration and academic records</li>
                                    <li><strong>Bursar:</strong> Fee management and financial records</li>
                                    <li><strong>Academic:</strong> Course and result management</li>
                                    <li><strong>Hostel:</strong> Hostel allocation and management</li>
                                </ul>
                            </div>

                            <div class="text-end">
                                <button type="submit" name="update_role_status" class="btn btn-primary" 
                                        <?php echo $is_main_admin ? 'disabled' : ''; ?>>
                                    <i class="fas fa-save me-2"></i>Update Role & Status
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Permissions Tab -->
                <div class="tab-pane fade" id="permissions">
                    <div class="form-section">
                        <h4 class="mb-4"><i class="fas fa-key me-2 text-primary"></i>Permissions</h4>
                        <form method="POST">
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                Grant <strong>"All Permissions"</strong> to give complete access, or select individual permissions below.
                            </div>

                            <div class="mb-4 p-3 bg-light rounded">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="perm_all" name="perm_all" value="1"
                                           <?php echo $has_all_permissions ? 'checked' : ''; ?>
                                           onchange="toggleAllPermissions(this)">
                                    <label class="form-check-label fw-bold" for="perm_all">
                                        <i class="fas fa-unlock-alt me-2 text-primary"></i>Grant All Permissions
                                    </label>
                                </div>
                            </div>

                            <div class="row" id="permissions-grid">
                                <?php 
                                $permission_items = [
                                    ['students', 'fas fa-user-graduate', 'Students', 'Manage student records and profiles'],
                                    ['staff', 'fas fa-chalkboard-teacher', 'Staff', 'Manage staff and academic advisors'],
                                    ['courses', 'fas fa-book', 'Courses', 'Manage courses and curriculum'],
                                    ['departments', 'fas fa-building', 'Departments', 'Manage departments and faculties'],
                                    ['programs', 'fas fa-graduation-cap', 'Programs', 'Manage academic programs'],
                                    ['results', 'fas fa-chart-bar', 'Results', 'Manage and publish results'],
                                    ['fees', 'fas fa-money-bill-wave', 'Fees', 'Manage fee structures'],
                                    ['payments', 'fas fa-credit-card', 'Payments', 'Verify and manage payments'],
                                    ['hostels', 'fas fa-bed', 'Hostels', 'Manage hostel allocations'],
                                    ['settings', 'fas fa-cog', 'Settings', 'System configuration'],
                                    ['reports', 'fas fa-file-alt', 'Reports', 'View and generate reports'],
                                    ['logs', 'fas fa-history', 'Logs', 'View system logs'],
                                    ['notifications', 'fas fa-bell', 'Notifications', 'Send notifications'],
                                    ['bulk_actions', 'fas fa-tasks', 'Bulk Actions', 'Perform bulk operations']
                                ];
                                foreach ($permission_items as $perm): 
                                    $is_checked = $has_all_permissions || (isset($current_permissions[$perm[0]]) && $current_permissions[$perm[0]] === true);
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="permission-checkbox d-flex align-items-center">
                                        <input class="form-check-input me-3" type="checkbox" 
                                               id="perm_<?php echo $perm[0]; ?>" 
                                               name="perm_<?php echo $perm[0]; ?>" 
                                               value="1"
                                               <?php echo $is_checked ? 'checked' : ''; ?>
                                               <?php echo $has_all_permissions ? 'disabled' : ''; ?>>
                                        <label class="form-check-label mb-0" for="perm_<?php echo $perm[0]; ?>">
                                            <i class="<?php echo $perm[1]; ?> me-2 text-muted"></i>
                                            <strong><?php echo $perm[2]; ?></strong>
                                            <br><small class="text-muted"><?php echo $perm[3]; ?></small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="text-end mt-3">
                                <button type="submit" name="update_permissions" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Permissions
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-pane fade" id="security">
                    <div class="form-section mb-4">
                        <h4 class="mb-4"><i class="fas fa-shield-alt me-2 text-primary"></i>Security Settings</h4>

                        <!-- 2FA Status -->
                        <div class="card mb-4">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1">Two-Factor Authentication</h5>
                                    <p class="text-muted mb-0">
                                        <?php if ($user['two_factor_enabled']): ?>
                                        <span class="text-success"><i class="fas fa-check-circle me-1"></i>Currently enabled</span>
                                        <?php else: ?>
                                        <span class="text-secondary"><i class="fas fa-times-circle me-1"></i>Currently disabled</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <form method="POST" class="m-0">
                                    <button type="submit" name="toggle_2fa" class="btn btn-<?php echo $user['two_factor_enabled'] ? 'danger' : 'success'; ?>">
                                        <i class="fas fa-<?php echo $user['two_factor_enabled'] ? 'times' : 'check'; ?> me-2"></i>
                                        <?php echo $user['two_factor_enabled'] ? 'Disable 2FA' : 'Enable 2FA'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Change Password -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="new_password" 
                                                       id="new_password" required minlength="6">
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="form-text">Minimum 6 characters</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" name="confirm_password" 
                                                       id="confirm_password" required minlength="6">
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" name="change_password" class="btn btn-warning">
                                            <i class="fas fa-key me-2"></i>Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Image Tab -->
                <div class="tab-pane fade" id="image">
                    <div class="form-section text-center">
                        <h4 class="mb-4"><i class="fas fa-image me-2 text-primary"></i>Profile Image</h4>

                        <div class="mb-4">
                            <?php if ($user['profile_image']): ?>
                            <img src="uploads/admin_profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                 alt="Current Profile" class="profile-image-preview mb-3">
                            <?php else: ?>
                            <div class="profile-image-preview d-inline-flex align-items-center justify-content-center bg-light mb-3">
                                <i class="fas fa-user-shield fa-5x text-secondary"></i>
                            </div>
                            <?php endif; ?>
                            <h5><?php echo htmlspecialchars($user['full_name']); ?></h5>
                            <p class="text-muted"><?php echo $user['role']; ?></p>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload New Image</label>
                                <input type="file" class="form-control" name="profile_image" 
                                       accept="image/jpeg,image/png,image/gif" required>
                                <div class="form-text">Max size: 2MB. Formats: JPG, PNG, GIF</div>
                            </div>
                            <button type="submit" name="upload_image" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i>Upload Image
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === 'password' ? 'text' : 'password';
}

function toggleAllPermissions(checkbox) {
    const permissionCheckboxes = document.querySelectorAll('#permissions-grid input[type="checkbox"]');
    permissionCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        cb.disabled = checkbox.checked;
    });
}

// Initialize permission states on page load
document.addEventListener('DOMContentLoaded', function() {
    const allPermCheckbox = document.getElementById('perm_all');
    if (allPermCheckbox && allPermCheckbox.checked) {
        const permissionCheckboxes = document.querySelectorAll('#permissions-grid input[type="checkbox"]');
        permissionCheckboxes.forEach(cb => {
            cb.disabled = true;
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>