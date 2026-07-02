<?php
// settings.php
ob_start();

require_once 'includes/header.php';

$page_title = "System Settings";

// Get admin details
$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_role = $_SESSION['admin_role'] ?? '';

// Check if user has admin or super admin role
if (!in_array($admin_role, ['Super Admin', 'Admin'])) {
    $_SESSION['error_message'] = "You don't have permission to access system settings.";
    header("Location: dashboard.php");
    exit();
}

// Fetch current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid security token.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // General Settings
            if (isset($_POST['update_general'])) {
                $site_name = trim($_POST['site_name']);
                $site_description = trim($_POST['site_description']);
                $admin_email = trim($_POST['admin_email']);
                $timezone = $_POST['timezone'];
                $date_format = $_POST['date_format'];
                
                $updates = [
                    'site_name' => $site_name,
                    'site_description' => $site_description,
                    'admin_email' => $admin_email,
                    'timezone' => $timezone,
                    'date_format' => $date_format
                ];
                
                foreach ($updates as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
                
                // Set timezone
                date_default_timezone_set($timezone);
                $_SESSION['success_message'] = "General settings updated successfully!";
            }
            
            // Academic Settings
            if (isset($_POST['update_academic'])) {
                $current_session = $_POST['current_session'];
                $current_semester = $_POST['current_semester'];
                $registration_deadline = $_POST['registration_deadline'];
                $add_drop_deadline = $_POST['add_drop_deadline'];
                $result_upload_deadline = $_POST['result_upload_deadline'];
                
                $updates = [
                    'current_session' => $current_session,
                    'current_semester' => $current_semester,
                    'registration_deadline' => $registration_deadline,
                    'add_drop_deadline' => $add_drop_deadline,
                    'result_upload_deadline' => $result_upload_deadline
                ];
                
                foreach ($updates as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
                
                // Update academic_sessions table
                $session_stmt = $pdo->prepare("UPDATE academic_sessions SET is_current = 0 WHERE is_current = 1");
                $session_stmt->execute();
                
                $session_stmt = $pdo->prepare("UPDATE academic_sessions SET is_current = 1 WHERE session_year = ?");
                $session_stmt->execute([$current_session]);
                
                $_SESSION['success_message'] = "Academic settings updated successfully!";
            }
            
            // Notification Settings
            if (isset($_POST['update_notifications'])) {
                $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
                $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
                $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
                $notify_on_registration = isset($_POST['notify_on_registration']) ? 1 : 0;
                $notify_on_payment = isset($_POST['notify_on_payment']) ? 1 : 0;
                $notify_on_result = isset($_POST['notify_on_result']) ? 1 : 0;
                
                $updates = [
                    'email_notifications' => $email_notifications,
                    'push_notifications' => $push_notifications,
                    'sms_notifications' => $sms_notifications,
                    'notify_on_registration' => $notify_on_registration,
                    'notify_on_payment' => $notify_on_payment,
                    'notify_on_result' => $notify_on_result
                ];
                
                foreach ($updates as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
                
                $_SESSION['success_message'] = "Notification settings updated successfully!";
            }
            
            // Security Settings
            if (isset($_POST['update_security'])) {
                $session_timeout = (int)$_POST['session_timeout'];
                $max_login_attempts = (int)$_POST['max_login_attempts'];
                $require_2fa = isset($_POST['require_2fa']) ? 1 : 0;
                $password_expiry_days = (int)$_POST['password_expiry_days'];
                
                $updates = [
                    'session_timeout' => $session_timeout,
                    'max_login_attempts' => $max_login_attempts,
                    'require_2fa' => $require_2fa,
                    'password_expiry_days' => $password_expiry_days
                ];
                
                foreach ($updates as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
                
                $_SESSION['success_message'] = "Security settings updated successfully!";
            }
            
            // Maintenance Settings
            if (isset($_POST['update_maintenance'])) {
                $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
                $maintenance_message = trim($_POST['maintenance_message']);
                $allow_ips = trim($_POST['allow_ips']);
                $backup_frequency = $_POST['backup_frequency'];
                
                $updates = [
                    'maintenance_mode' => $maintenance_mode,
                    'maintenance_message' => $maintenance_message,
                    'allow_ips' => $allow_ips,
                    'backup_frequency' => $backup_frequency
                ];
                
                foreach ($updates as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
                
                $_SESSION['success_message'] = "Maintenance settings updated successfully!";
            }
            
            $pdo->commit();
            
            // Refresh settings
            $settings = [];
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error updating settings: " . $e->getMessage();
        }
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header("Location: settings.php");
    exit();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get academic sessions for dropdown
$academic_sessions = $pdo->query("SELECT session_year FROM academic_sessions ORDER BY session_year DESC")->fetchAll();

// Get system status
$system_status = [
    'php_version' => PHP_VERSION,
    'mysql_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin Portal</title>
    <style>
        .settings-nav {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .settings-nav .nav-link {
            color: #6c757d;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .settings-nav .nav-link:hover {
            background: #f8f9fa;
        }
        .settings-nav .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .settings-nav .nav-link i {
            width: 24px;
            margin-right: 10px;
        }
        .settings-section {
            display: none;
        }
        .settings-section.active {
            display: block;
        }
        .setting-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-good { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-critical { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="app-page-title mb-0">
            <i class="fas fa-cog me-2"></i>System Settings
        </h1>
        <div>
            <button class="btn btn-outline-secondary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
        </div>
    </div>

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

    <div class="row">
        <!-- Settings Navigation -->
        <div class="col-md-3">
            <div class="settings-nav">
                <nav class="nav flex-column">
                    <a class="nav-link active" href="#general" data-section="general">
                        <i class="fas fa-globe"></i>General Settings
                    </a>
                    <a class="nav-link" href="#academic" data-section="academic">
                        <i class="fas fa-graduation-cap"></i>Academic Settings
                    </a>
                    <a class="nav-link" href="#notifications" data-section="notifications">
                        <i class="fas fa-bell"></i>Notifications
                    </a>
                    <a class="nav-link" href="#security" data-section="security">
                        <i class="fas fa-shield-alt"></i>Security
                    </a>
                    <a class="nav-link" href="#maintenance" data-section="maintenance">
                        <i class="fas fa-tools"></i>Maintenance
                    </a>
                    <a class="nav-link" href="#system" data-section="system">
                        <i class="fas fa-server"></i>System Info
                    </a>
                </nav>
            </div>
        </div>

        <!-- Settings Content -->
        <div class="col-md-9">
            <!-- General Settings -->
            <div class="settings-section active" id="section-general">
                <div class="setting-card">
                    <h5><i class="fas fa-globe me-2"></i>General Settings</h5>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Site Name</label>
                                <input type="text" class="form-control" name="site_name" 
                                       value="<?php echo htmlspecialchars($settings['site_name'] ?? 'Al-Qalam University Portal'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Admin Email</label>
                                <input type="email" class="form-control" name="admin_email" 
                                       value="<?php echo htmlspecialchars($settings['admin_email'] ?? 'admin@university.edu'); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Site Description</label>
                                <textarea class="form-control" name="site_description" rows="3"><?php echo htmlspecialchars($settings['site_description'] ?? 'Al-Qalam University Student Management System'); ?></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Timezone</label>
                                <select class="form-select" name="timezone">
                                    <?php
                                    $timezones = [
                                        'Africa/Lagos' => 'West Africa Time (WAT)',
                                        'Africa/Cairo' => 'Eastern European Time (EET)',
                                        'Africa/Johannesburg' => 'South Africa Standard Time',
                                        'America/New_York' => 'Eastern Time (ET)',
                                        'America/Los_Angeles' => 'Pacific Time (PT)',
                                        'Europe/London' => 'Greenwich Mean Time (GMT)',
                                        'Europe/Paris' => 'Central European Time (CET)',
                                        'Asia/Dubai' => 'Gulf Standard Time (GST)',
                                        'Asia/Tokyo' => 'Japan Standard Time (JST)',
                                    ];
                                    $current_tz = $settings['timezone'] ?? 'Africa/Lagos';
                                    foreach ($timezones as $tz => $label) {
                                        echo "<option value=\"$tz\" " . ($current_tz == $tz ? 'selected' : '') . ">$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date Format</label>
                                <select class="form-select" name="date_format">
                                    <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (2024-01-15)</option>
                                    <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (15/01/2024)</option>
                                    <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (01/15/2024)</option>
                                    <option value="F d, Y" <?php echo ($settings['date_format'] ?? '') == 'F d, Y' ? 'selected' : ''; ?>>January 15, 2024</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="update_general" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Academic Settings -->
            <div class="settings-section" id="section-academic">
                <div class="setting-card">
                    <h5><i class="fas fa-graduation-cap me-2"></i>Academic Settings</h5>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Academic Session</label>
                                <select class="form-select" name="current_session">
                                    <?php foreach ($academic_sessions as $session): ?>
                                    <option value="<?php echo $session['session_year']; ?>" 
                                        <?php echo ($settings['current_session'] ?? '') == $session['session_year'] ? 'selected' : ''; ?>>
                                        <?php echo $session['session_year']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Semester</label>
                                <select class="form-select" name="current_semester">
                                    <option value="1" <?php echo ($settings['current_semester'] ?? '1') == '1' ? 'selected' : ''; ?>>First Semester</option>
                                    <option value="2" <?php echo ($settings['current_semester'] ?? '') == '2' ? 'selected' : ''; ?>>Second Semester</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Registration Deadline</label>
                                <input type="date" class="form-control" name="registration_deadline" 
                                       value="<?php echo htmlspecialchars($settings['registration_deadline'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Add/Drop Deadline</label>
                                <input type="date" class="form-control" name="add_drop_deadline" 
                                       value="<?php echo htmlspecialchars($settings['add_drop_deadline'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Result Upload Deadline</label>
                                <input type="date" class="form-control" name="result_upload_deadline" 
                                       value="<?php echo htmlspecialchars($settings['result_upload_deadline'] ?? ''); ?>">
                            </div>
                        </div>
                        <button type="submit" name="update_academic" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Notification Settings -->
            <div class="settings-section" id="section-notifications">
                <div class="setting-card">
                    <h5><i class="fas fa-bell me-2"></i>Notification Settings</h5>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="email_notifications" id="email_notifications" 
                                       <?php echo ($settings['email_notifications'] ?? '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_notifications">Enable Email Notifications</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="push_notifications" id="push_notifications" 
                                       <?php echo ($settings['push_notifications'] ?? '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="push_notifications">Enable Push Notifications</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="sms_notifications" id="sms_notifications" 
                                       <?php echo ($settings['sms_notifications'] ?? '0') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="sms_notifications">Enable SMS Notifications</label>
                            </div>
                        </div>
                        <hr>
                        <h6>Notify On:</h6>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="notify_on_registration" id="notify_on_registration" 
                                       <?php echo ($settings['notify_on_registration'] ?? '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="notify_on_registration">Course Registration</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="notify_on_payment" id="notify_on_payment" 
                                       <?php echo ($settings['notify_on_payment'] ?? '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="notify_on_payment">Payment Confirmation</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="notify_on_result" id="notify_on_result" 
                                       <?php echo ($settings['notify_on_result'] ?? '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="notify_on_result">Result Publication</label>
                            </div>
                        </div>
                        <button type="submit" name="update_notifications" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="settings-section" id="section-security">
                <div class="setting-card">
                    <h5><i class="fas fa-shield-alt me-2"></i>Security Settings</h5>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Session Timeout (minutes)</label>
                                <input type="number" class="form-control" name="session_timeout" 
                                       value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>" min="5" max="480">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Max Login Attempts</label>
                                <input type="number" class="form-control" name="max_login_attempts" 
                                       value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? '5'); ?>" min="3" max="10">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password Expiry (days)</label>
                                <input type="number" class="form-control" name="password_expiry_days" 
                                       value="<?php echo htmlspecialchars($settings['password_expiry_days'] ?? '90'); ?>" min="0" max="365">
                                <small class="text-muted">0 = never expire</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" name="require_2fa" id="require_2fa" 
                                           <?php echo ($settings['require_2fa'] ?? '0') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="require_2fa">Require Two-Factor Authentication for Admins</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="update_security" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Maintenance Settings -->
            <div class="settings-section" id="section-maintenance">
                <div class="setting-card">
                    <h5><i class="fas fa-tools me-2"></i>Maintenance Settings</h5>
                    <hr>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" 
                                       <?php echo ($settings['maintenance_mode'] ?? '0') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="maintenance_mode">Enable Maintenance Mode</label>
                            </div>
                            <small class="text-muted">When enabled, only admins can access the system</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Maintenance Message</label>
                            <textarea class="form-control" name="maintenance_message" rows="3"><?php echo htmlspecialchars($settings['maintenance_message'] ?? 'System is currently under maintenance. Please check back later.'); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allowed IP Addresses (one per line)</label>
                            <textarea class="form-control" name="allow_ips" rows="3" placeholder="192.168.1.1&#10;10.0.0.1"><?php echo htmlspecialchars($settings['allow_ips'] ?? ''); ?></textarea>
                            <small class="text-muted">IP addresses that can access during maintenance (leave empty for admin only)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Automatic Backup Frequency</label>
                            <select class="form-select" name="backup_frequency">
                                <option value="daily" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo ($settings['backup_frequency'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo ($settings['backup_frequency'] ?? '') == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="disabled" <?php echo ($settings['backup_frequency'] ?? '') == 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <button type="submit" name="update_maintenance" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- System Information -->
            <div class="settings-section" id="section-system">
                <div class="setting-card">
                    <h5><i class="fas fa-server me-2"></i>System Information</h5>
                    <hr>
                    <div class="table-responsive">
                        <table class="table">
                            <tr>
                                <th width="200">PHP Version</th>
                                <td>
                                    <?php echo $system_status['php_version']; ?>
                                    <?php if (version_compare(PHP_VERSION, '7.4.0', '>=')): ?>
                                        <span class="status-badge status-good ms-2">Good</span>
                                    <?php else: ?>
                                        <span class="status-badge status-critical ms-2">Update Required</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>MySQL Version</th>
                                <td><?php echo $system_status['mysql_version']; ?></td>
                            </tr>
                            <tr>
                                <th>Upload Max File Size</th>
                                <td><?php echo $system_status['upload_max_filesize']; ?></td>
                            </tr>
                            <tr>
                                <th>POST Max Size</th>
                                <td><?php echo $system_status['post_max_size']; ?></td>
                            </tr>
                            <tr>
                                <th>Memory Limit</th>
                                <td><?php echo $system_status['memory_limit']; ?></td>
                            </tr>
                            <tr>
                                <th>Max Execution Time</th>
                                <td><?php echo $system_status['max_execution_time']; ?> seconds</td>
                            </tr>
                        </table>
                    </div>
                    
                    <hr>
                    <h6>Database Tables</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Table Name</th>
                                    <th>Records</th>
                                    <th>Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $tables = $pdo->query("SHOW TABLE STATUS")->fetchAll();
                                foreach ($tables as $table):
                                    $size = ($table['Data_length'] + $table['Index_length']) / 1024;
                                ?>
                                <tr>
                                    <td><?php echo $table['Name']; ?></td>
                                    <td><?php echo number_format($table['Rows']); ?></td>
                                    <td><?php echo number_format($size, 2); ?> KB</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Section navigation
document.querySelectorAll('.settings-nav .nav-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Update active state in nav
        document.querySelectorAll('.settings-nav .nav-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
        
        // Show corresponding section
        const sectionId = this.dataset.section;
        document.querySelectorAll('.settings-section').forEach(section => {
            section.classList.remove('active');
        });
        document.getElementById(`section-${sectionId}`).classList.add('active');
        
        // Update URL hash
        window.location.hash = sectionId;
    });
});

// Check URL hash on load
if (window.location.hash) {
    const section = window.location.hash.substring(1);
    const targetLink = document.querySelector(`.settings-nav .nav-link[data-section="${section}"]`);
    if (targetLink) {
        targetLink.click();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>