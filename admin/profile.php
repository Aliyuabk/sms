<?php
// profile.php
ob_start();

require_once 'includes/header.php';

$page_title = "Admin Profile";

// Get admin details from session
$admin_id = $_SESSION['admin_id'] ?? 0;

if ($admin_id == 0) {
    header("Location: login.php");
    exit();
}

// Fetch admin details
$stmt = $pdo->prepare("
    SELECT a.*, d.department_name 
    FROM admin_users a
    LEFT JOIN departments d ON a.department_id = d.department_id
    WHERE a.admin_id = ?
");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

if (!$admin) {
    header("Location: logout.php");
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        
        // CSRF validation
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Invalid security token.";
        } else {
            // Check if email already exists for another admin
            $check_stmt = $pdo->prepare("SELECT admin_id FROM admin_users WHERE email = ? AND admin_id != ?");
            $check_stmt->execute([$email, $admin_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Email already exists for another admin user.";
            } else {
                $update_stmt = $pdo->prepare("
                    UPDATE admin_users 
                    SET full_name = ?, phone = ?, email = ?, updated_at = NOW()
                    WHERE admin_id = ?
                ");
                $update_stmt->execute([$full_name, $phone, $email, $admin_id]);
                
                // Update session variables
                $_SESSION['admin_name'] = $full_name;
                $_SESSION['admin_email'] = $email;
                
                $_SESSION['success_message'] = "Profile updated successfully!";
                
                // Refresh admin data
                $stmt = $pdo->prepare("
                    SELECT a.*, d.department_name 
                    FROM admin_users a
                    LEFT JOIN departments d ON a.department_id = d.department_id
                    WHERE a.admin_id = ?
                ");
                $stmt->execute([$admin_id]);
                $admin = $stmt->fetch();
            }
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: profile.php");
        exit();
    }
    
    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // CSRF validation
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Invalid security token.";
        } elseif (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['error_message'] = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error_message'] = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $_SESSION['error_message'] = "New password must be at least 6 characters long.";
        } else {
            // Verify current password
            if (password_verify($current_password, $admin['password_hash'])) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE admin_id = ?");
                $update_stmt->execute([$new_hash, $admin_id]);
                $_SESSION['success_message'] = "Password changed successfully!";
            } else {
                $_SESSION['error_message'] = "Current password is incorrect.";
            }
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: profile.php");
        exit();
    }
    
    // Handle profile image upload
    if (isset($_POST['upload_image']) && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];
        
        // CSRF validation
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Invalid security token.";
        } else {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = [
                    UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
                    UPLOAD_ERR_FORM_SIZE => 'File too large',
                    UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file selected',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                    UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
                ];
                $_SESSION['error_message'] = $upload_errors[$file['error']] ?? 'Unknown upload error';
            } elseif (!in_array($file['type'], $allowed_types)) {
                $_SESSION['error_message'] = "Invalid file type. Please upload JPG, PNG, GIF, or WEBP.";
            } elseif ($file['size'] > $max_size) {
                $_SESSION['error_message'] = "File too large. Maximum size is 2MB.";
            } else {
                // Create uploads directory if it doesn't exist
                $upload_dir = '../uploads/admin/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old profile image if exists
                    if (!empty($admin['profile_image']) && file_exists($upload_dir . $admin['profile_image'])) {
                        unlink($upload_dir . $admin['profile_image']);
                    }
                    
                    $update_stmt = $pdo->prepare("UPDATE admin_users SET profile_image = ?, updated_at = NOW() WHERE admin_id = ?");
                    $update_stmt->execute([$filename, $admin_id]);
                    
                    $_SESSION['success_message'] = "Profile image updated successfully!";
                    
                    // Refresh admin data
                    $stmt = $pdo->prepare("
                        SELECT a.*, d.department_name 
                        FROM admin_users a
                        LEFT JOIN departments d ON a.department_id = d.department_id
                        WHERE a.admin_id = ?
                    ");
                    $stmt->execute([$admin_id]);
                    $admin = $stmt->fetch();
                } else {
                    $_SESSION['error_message'] = "Failed to upload image.";
                }
            }
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: profile.php");
        exit();
    }
    
    // Handle remove profile image
    if (isset($_POST['remove_image'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Invalid security token.";
        } else {
            $upload_dir = '../uploads/admin/';
            if (!empty($admin['profile_image']) && file_exists($upload_dir . $admin['profile_image'])) {
                unlink($upload_dir . $admin['profile_image']);
            }
            
            $update_stmt = $pdo->prepare("UPDATE admin_users SET profile_image = NULL, updated_at = NOW() WHERE admin_id = ?");
            $update_stmt->execute([$admin_id]);
            
            $_SESSION['success_message'] = "Profile image removed successfully!";
            
            // Refresh admin data
            $stmt = $pdo->prepare("
                SELECT a.*, d.department_name 
                FROM admin_users a
                LEFT JOIN departments d ON a.department_id = d.department_id
                WHERE a.admin_id = ?
            ");
            $stmt->execute([$admin_id]);
            $admin = $stmt->fetch();
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: profile.php");
        exit();
    }
}

// Get recent activity logs
$activity_stmt = $pdo->prepare("
    SELECT * FROM admin_logs 
    WHERE admin_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$activity_stmt->execute([$admin_id]);
$recent_activities = $activity_stmt->fetchAll();

// Get login history
$login_stmt = $pdo->prepare("
    SELECT * FROM admin_sessions 
    WHERE admin_id = ? 
    ORDER BY last_activity DESC 
    LIMIT 5
");
$login_stmt->execute([$admin_id]);
$login_history = $login_stmt->fetchAll();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin Portal</title>
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
        }
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .profile-avatar-sm {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .activity-timeline {
            position: relative;
            padding-left: 2rem;
        }
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        .activity-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .activity-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background: #667eea;
        }
        .avatar-upload {
            position: relative;
            display: inline-block;
        }
        .avatar-upload .upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .avatar-upload .upload-btn:hover {
            background: #5a67d8;
            transform: scale(1.05);
        }
        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        .password-strength-bar.weak { background: #dc3545; width: 25%; }
        .password-strength-bar.fair { background: #ffc107; width: 50%; }
        .password-strength-bar.good { background: #17a2b8; width: 75%; }
        .password-strength-bar.strong { background: #28a745; width: 100%; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="row align-items-center">
            <div class="col-md-2 text-center">
                <div class="avatar-upload">
                    <?php 
                    $avatar_path = !empty($admin['profile_image']) ? '../uploads/admin/' . $admin['profile_image'] : '../assets/images/default-avatar.png';
                    ?>
                    <img src="<?php echo $avatar_path; ?>" alt="Profile" class="profile-avatar" id="profileAvatar">
                    <div class="upload-btn" data-bs-toggle="modal" data-bs-target="#uploadImageModal">
                        <i class="fas fa-camera text-white"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-10">
                <h2><?php echo htmlspecialchars($admin['full_name']); ?></h2>
                <p class="mb-1">
                    <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($admin['email']); ?>
                </p>
                <p class="mb-1">
                    <i class="fas fa-user-tag me-2"></i><?php echo htmlspecialchars($admin['role']); ?>
                </p>
                <?php if ($admin['department_name']): ?>
                <p class="mb-0">
                    <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($admin['department_name']); ?>
                </p>
                <?php endif; ?>
                <div class="mt-3">
                    <span class="badge bg-light text-dark">
                        <i class="fas fa-clock me-1"></i>Member since: <?php echo date('F d, Y', strtotime($admin['created_at'])); ?>
                    </span>
                    <span class="badge bg-light text-dark ms-2">
                        <i class="fas fa-sign-in-alt me-1"></i>Last login: <?php echo $admin['last_login'] ? timeAgo($admin['last_login']) : 'Never'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column - Profile Info -->
        <div class="col-md-4">
            <!-- Stats Cards -->
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Logins</h6>
                        <h3 class="mb-0"><?php echo count($login_history); ?></h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 rounded p-3">
                        <i class="fas fa-sign-in-alt fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Account Status</h6>
                        <h3 class="mb-0">
                            <span class="badge bg-<?php echo $admin['status'] == 'Active' ? 'success' : 'danger'; ?>">
                                <?php echo $admin['status']; ?>
                            </span>
                        </h3>
                    </div>
                    <div class="bg-success bg-opacity-10 rounded p-3">
                        <i class="fas fa-shield-alt fa-2x text-success"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">2-Factor Auth</h6>
                        <h3 class="mb-0">
                            <span class="badge bg-<?php echo $admin['two_factor_enabled'] ? 'success' : 'secondary'; ?>">
                                <?php echo $admin['two_factor_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </h3>
                    </div>
                    <div class="bg-info bg-opacity-10 rounded p-3">
                        <i class="fas fa-mobile-alt fa-2x text-info"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Edit Forms -->
        <div class="col-md-8">
            <!-- Edit Profile Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="full_name" 
                                       value="<?php echo htmlspecialchars($admin['full_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" disabled>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="passwordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password" id="new_password" required>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="passwordStrength"></div>
                            </div>
                            <small class="text-muted" id="passwordHint">Minimum 6 characters</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                            <small class="text-muted" id="passwordMatchMsg"></small>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-warning">
                            <i class="fas fa-lock me-2"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity</h5>
                </div>
                <div class="card-body">
                    <div class="activity-timeline">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                        <p class="mb-0 small text-muted"><?php echo htmlspecialchars($activity['description']); ?></p>
                                    </div>
                                    <small class="text-muted"><?php echo timeAgo($activity['created_at']); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No recent activity found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-desktop me-2"></i>Active Sessions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Last Activity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($login_history)): ?>
                                    <?php foreach ($login_history as $session): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($session['ip_address'] ?? 'N/A'); ?></td>
                                        <td><?php echo $session['last_activity'] ? date('M d, H:i', $session['last_activity']) : 'N/A'; ?></td>
                                        <td>
                                            <?php if ($session['last_activity'] > time() - 3600): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Expired</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No login history found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Image Modal -->
<div class="modal fade" id="uploadImageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Profile Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <img src="<?php echo $avatar_path; ?>" alt="Profile Preview" id="imagePreview" style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Image</label>
                        <input type="file" class="form-control" name="profile_image" accept="image/*" required>
                        <small class="text-muted">Max size: 2MB. Allowed: JPG, PNG, GIF, WEBP</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if ($admin['profile_image']): ?>
                    <button type="submit" name="remove_image" class="btn btn-danger" onclick="return confirm('Remove profile image?')">
                        <i class="fas fa-trash me-2"></i>Remove
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_image" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Password strength checker
const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const strengthBar = document.getElementById('passwordStrength');
const passwordHint = document.getElementById('passwordHint');
const matchMsg = document.getElementById('passwordMatchMsg');

newPassword.addEventListener('input', function() {
    const password = this.value;
    let strength = 0;
    
    if (password.length >= 6) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    strengthBar.className = 'password-strength-bar';
    if (strength <= 2) {
        strengthBar.classList.add('weak');
        passwordHint.innerHTML = 'Weak password - add uppercase, numbers, and special characters';
    } else if (strength <= 3) {
        strengthBar.classList.add('fair');
        passwordHint.innerHTML = 'Fair password';
    } else if (strength <= 4) {
        strengthBar.classList.add('good');
        passwordHint.innerHTML = 'Good password';
    } else {
        strengthBar.classList.add('strong');
        passwordHint.innerHTML = 'Strong password!';
    }
});

confirmPassword.addEventListener('input', function() {
    if (this.value === newPassword.value) {
        matchMsg.innerHTML = '<span class="text-success">✓ Passwords match</span>';
    } else {
        matchMsg.innerHTML = '<span class="text-danger">✗ Passwords do not match</span>';
    }
});

// Image preview
document.querySelector('input[name="profile_image"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>