<?php
ob_start();
require_once 'includes/header.php';
$page_title = "View Admin User";

// Only super admin can access this page
if ($admin_role !== 'Super Admin') {
    $_SESSION['error_message'] = "You don't have permission to access this page.";
    header("Location: dashboard.php");
    exit();
}

$admin_id_view = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($admin_id_view <= 0) {
    $_SESSION['error_message'] = "Invalid admin ID.";
    header("Location: admin_users.php");
    exit();
}

// Get admin user details with department info
$sql = "
    SELECT 
        au.*,
        d.department_name,
        d.department_code,
        d.hod_name,
        DATE_FORMAT(au.created_at, '%M %d, %Y at %h:%i %p') as created_formatted,
        DATE_FORMAT(au.last_login, '%M %d, %Y at %h:%i %p') as last_login_formatted,
        DATE_FORMAT(au.updated_at, '%M %d, %Y at %h:%i %p') as updated_formatted,
        creator.full_name as created_by_name
    FROM admin_users au
    LEFT JOIN departments d ON au.department_id = d.department_id
    LEFT JOIN admin_users creator ON au.created_by = creator.admin_id
    WHERE au.admin_id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$admin_id_view]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error_message'] = "Admin user not found.";
    header("Location: admin_users.php");
    exit();
}

// Get activity logs for this admin
$logs_sql = "
    SELECT 
        log_id,
        action,
        description,
        ip_address,
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as log_date
    FROM admin_logs
    WHERE admin_id = ?
    ORDER BY created_at DESC
    LIMIT 50
";
$logs_stmt = $pdo->prepare($logs_sql);
$logs_stmt->execute([$admin_id_view]);
$activity_logs = $logs_stmt->fetchAll();

// Get login history (last 30 days)
$login_sql = "
    SELECT 
        DATE_FORMAT(last_login, '%Y-%m-%d %H:%i') as login_date,
        last_ip,
        COUNT(*) as login_count
    FROM admin_users
    WHERE admin_id = ? AND last_login IS NOT NULL
    GROUP BY DATE(last_login)
    ORDER BY last_login DESC
    LIMIT 30
";
$login_stmt = $pdo->prepare($login_sql);
$login_stmt->execute([$admin_id_view]);
$login_history = $login_stmt->fetchAll();

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

// Status badge colors
$status_class = [
    'Active' => 'success',
    'Inactive' => 'secondary',
    'Suspended' => 'danger',
    'Pending' => 'warning'
][$user['status']] ?? 'secondary';

// Calculate account age
$created_date = new DateTime($user['created_at']);
$now = new DateTime();
$account_age = $created_date->diff($now);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Admin - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
            background: #f8f9fa;
        }
        .info-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .info-card:hover {
            transform: translateY(-2px);
        }
        .info-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        .info-value {
            font-weight: 600;
            color: #2c3e50;
        }
        .activity-item {
            padding: 1rem;
            border-left: 3px solid #e9ecef;
            margin-bottom: 1rem;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        .activity-item:hover {
            border-left-color: #4361ee;
            background: #f1f3ff;
        }
        .timeline-date {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .stat-box {
            text-align: center;
            padding: 1.5rem;
            border-radius: 12px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #4361ee;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
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
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($user['full_name']); ?></li>
        </ol>
    </nav>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="row align-items-center">
            <div class="col-md-2 text-center">
                <?php if ($user['profile_image']): ?>
                <img src="uploads/admin_profiles/<?php echo htmlspecialchars($user['profile_image']); ?>" 
                     alt="Profile" class="profile-avatar">
                <?php else: ?>
                <div class="profile-avatar d-flex align-items-center justify-content-center bg-light">
                    <i class="fas fa-user-shield fa-4x text-secondary"></i>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <h2 class="mb-1">
                    <?php echo htmlspecialchars($user['full_name']); ?>
                    <?php if ($user['admin_id'] == 1): ?>
                    <i class="fas fa-crown text-warning" title="Super Admin"></i>
                    <?php endif; ?>
                </h2>
                <p class="mb-2 opacity-75">
                    <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($user['username']); ?> 
                    <span class="mx-2">|</span>
                    <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($user['email']); ?>
                </p>
                <div class="mb-2">
                    <span class="badge bg-<?php echo $role_color; ?> me-2 fs-6">
                        <i class="fas fa-id-badge me-1"></i><?php echo $user['role']; ?>
                    </span>
                    <span class="badge bg-<?php echo $status_class; ?> fs-6">
                        <i class="fas fa-circle me-1" style="font-size: 8px;"></i><?php echo $user['status']; ?>
                    </span>
                </div>
                <small class="opacity-75">
                    <i class="fas fa-clock me-1"></i>
                    Account Age: 
                    <?php 
                    if ($account_age->y > 0) echo $account_age->y . ' year(s) ';
                    if ($account_age->m > 0) echo $account_age->m . ' month(s) ';
                    if ($account_age->d > 0) echo $account_age->d . ' day(s)';
                    ?>
                </small>
            </div>
            <div class="col-md-3 text-md-end mt-3 mt-md-0">
                <a href="edit_admin.php?id=<?php echo $user['admin_id']; ?>" class="btn btn-light mb-2">
                    <i class="fas fa-edit me-2"></i>Edit Profile
                </a>
                <a href="admin_users.php" class="btn btn-outline-light mb-2">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($activity_logs); ?></div>
                <div class="stat-label">Total Activities</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($login_history); ?></div>
                <div class="stat-label">Login Days</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number"><?php echo $user['failed_attempts'] ?? 0; ?></div>
                <div class="stat-label">Failed Logins</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number">
                    <?php echo $user['two_factor_enabled'] ? '<i class="fas fa-shield-alt text-success"></i>' : '<i class="fas fa-times text-danger"></i>'; ?>
                </div>
                <div class="stat-label">2FA Status</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Personal Info -->
        <div class="col-lg-4 mb-4">
            <div class="card info-card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-user-circle me-2 text-primary"></i>Personal Information</h5>
                </div>
                <div class="card-body p-0">
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email Address</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo $user['email']; ?>"><?php echo htmlspecialchars($user['email']); ?></a>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone Number</div>
                        <div class="info-value">
                            <?php if ($user['phone']): ?>
                            <a href="tel:<?php echo $user['phone']; ?>"><?php echo htmlspecialchars($user['phone']); ?></a>
                            <?php else: ?>
                            <span class="text-muted">Not provided</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value">
                            <?php if ($user['department_name']): ?>
                            <i class="fas fa-building me-1 text-muted"></i>
                            <?php echo htmlspecialchars($user['department_name']); ?>
                            <?php if ($user['department_code']): ?>
                            <span class="badge bg-light text-dark ms-1"><?php echo $user['department_code']; ?></span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">System-wide access</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card info-card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2 text-primary"></i>Account Security</h5>
                </div>
                <div class="card-body p-0">
                    <div class="info-item">
                        <div class="info-label">Account Status</div>
                        <div class="info-value">
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo $user['status']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Two-Factor Authentication</div>
                        <div class="info-value">
                            <?php if ($user['two_factor_enabled']): ?>
                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Enabled</span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><i class="fas fa-times me-1"></i>Disabled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Failed Login Attempts</div>
                        <div class="info-value">
                            <?php if ($user['failed_attempts'] > 0): ?>
                            <span class="text-danger fw-bold"><?php echo $user['failed_attempts']; ?></span>
                            <?php else: ?>
                            <span class="text-success">0</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Locked Until</div>
                        <div class="info-value">
                            <?php if ($user['locked_until']): ?>
                            <span class="text-danger"><?php echo $user['locked_until']; ?></span>
                            <?php else: ?>
                            <span class="text-success"><i class="fas fa-unlock me-1"></i>Not locked</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Activity & Metadata -->
        <div class="col-lg-8">
            <!-- Account Metadata -->
            <div class="card info-card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2 text-primary"></i>Account Metadata</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Created On</div>
                                <div class="info-value">
                                    <i class="fas fa-calendar-alt me-1 text-muted"></i>
                                    <?php echo $user['created_formatted']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Created By</div>
                                <div class="info-value">
                                    <?php if ($user['created_by_name']): ?>
                                    <i class="fas fa-user me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($user['created_by_name']); ?>
                                    <?php else: ?>
                                    <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value">
                                    <i class="fas fa-clock me-1 text-muted"></i>
                                    <?php echo $user['updated_formatted'] ?? 'Never'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Last Login</div>
                                <div class="info-value">
                                    <?php if ($user['last_login']): ?>
                                    <i class="fas fa-sign-in-alt me-1 text-success"></i>
                                    <?php echo $user['last_login_formatted']; ?>
                                    <br><small class="text-muted">IP: <?php echo htmlspecialchars($user['last_ip'] ?? 'N/A'); ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">Never logged in</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Activity Logs -->
            <div class="card info-card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Recent Activity</h5>
                    <span class="badge bg-primary"><?php echo count($activity_logs); ?> records</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($activity_logs)): ?>
                    <div class="activity-timeline">
                        <?php foreach ($activity_logs as $log): ?>
                        <div class="activity-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong class="text-primary"><?php echo htmlspecialchars($log['action']); ?></strong>
                                    <p class="mb-1 text-muted small"><?php echo htmlspecialchars($log['description'] ?? 'No description'); ?></p>
                                </div>
                                <span class="timeline-date">
                                    <i class="fas fa-clock me-1"></i><?php echo $log['log_date']; ?>
                                </span>
                            </div>
                            <?php if ($log['ip_address']): ?>
                            <small class="text-muted">
                                <i class="fas fa-network-wired me-1"></i>IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h6 class="text-muted">No Activity Records</h6>
                        <p class="text-muted small mb-0">This admin has no logged activities yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>