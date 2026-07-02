<?php
/**
 * Staff Settings Page
 * Account and notification settings
 */

ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$staff_id = $_SESSION['staff_id'];

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'email_notifications' => isset($_POST['email_notifications']) ? 1 : 0,
        'sms_notifications' => isset($_POST['sms_notifications']) ? 1 : 0,
        'push_notifications' => isset($_POST['push_notifications']) ? 1 : 0,
        'dark_mode' => isset($_POST['dark_mode']) ? 1 : 0,
    ];
    
    // Store settings in session or database
    $_SESSION['staff_settings'] = $settings;
    
    $success = "Settings updated successfully!";
}

$settings = $_SESSION['staff_settings'] ?? [
    'email_notifications' => 1,
    'sms_notifications' => 0,
    'push_notifications' => 1,
    'dark_mode' => 0,
];

$page_title = 'Settings';
$page_icon = 'fas fa-cog';
$active_page = 'settings';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Settings']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .settings-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .settings-card-header {
        padding: 25px 30px;
        border-bottom: 1px solid var(--gray-200);
        background: var(--gray-100);
    }
    .settings-card-header h5 {
        font-weight: 700;
        margin-bottom: 0;
    }
    .settings-card-body {
        padding: 30px;
    }
    .settings-group {
        margin-bottom: 30px;
    }
    .settings-group:last-child { margin-bottom: 0; }
    .settings-group-title {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-dark);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--gray-200);
    }
    .settings-group-title i {
        color: var(--primary-color);
        margin-right: 10px;
    }
    .settings-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid var(--gray-100);
    }
    .settings-item:last-child { border-bottom: none; }
    .settings-item .info .title {
        font-weight: 600;
        color: var(--text-dark);
    }
    .settings-item .info .description {
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .toggle-switch {
        position: relative;
        width: 50px;
        height: 28px;
        flex-shrink: 0;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: var(--gray-300);
        transition: var(--transition);
        border-radius: 34px;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background-color: var(--white);
        transition: var(--transition);
        border-radius: 50%;
    }
    .toggle-switch input:checked + .toggle-slider {
        background-color: var(--primary-color);
    }
    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(22px);
    }
    .btn-save-settings {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border: none;
        padding: 12px 40px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-save-settings:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(63, 116, 156, 0.4);
        color: var(--white);
    }
    .btn-danger-custom {
        background: var(--danger-color);
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-weight: 600;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-danger-custom:hover {
        background: #c62828;
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(244, 67, 54, 0.3);
        color: var(--white);
    }
</style>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="settings-card">
            <div class="settings-card-header">
                <h5><i class="fas fa-cog me-2 text-primary"></i> Account Settings</h5>
            </div>
            <div class="settings-card-body">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <!-- Notification Settings -->
                    <div class="settings-group">
                        <div class="settings-group-title">
                            <i class="fas fa-bell"></i> Notification Preferences
                        </div>
                        
                        <div class="settings-item">
                            <div class="info">
                                <div class="title">Email Notifications</div>
                                <div class="description">Receive notifications via email</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="settings-item">
                            <div class="info">
                                <div class="title">SMS Notifications</div>
                                <div class="description">Receive notifications via SMS</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="sms_notifications" <?php echo $settings['sms_notifications'] ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="settings-item">
                            <div class="info">
                                <div class="title">Push Notifications</div>
                                <div class="description">Receive push notifications in browser</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="push_notifications" <?php echo $settings['push_notifications'] ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Appearance Settings -->
                    <div class="settings-group">
                        <div class="settings-group-title">
                            <i class="fas fa-palette"></i> Appearance
                        </div>
                        
                        <div class="settings-item">
                            <div class="info">
                                <div class="title">Dark Mode</div>
                                <div class="description">Switch to dark theme</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="dark_mode" <?php echo $settings['dark_mode'] ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                        <button type="submit" class="btn-save-settings">
                            <i class="fas fa-save me-2"></i> Save Settings
                        </button>
                    </div>
                </form>
                
                <!-- Danger Zone -->
                <div class="settings-group mt-4 pt-4 border-top">
                    <div class="settings-group-title" style="color: var(--danger-color); border-bottom-color: var(--danger-color);">
                        <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i> Danger Zone
                    </div>
                    <div class="settings-item">
                        <div class="info">
                            <div class="title" style="color: var(--danger-color);">Logout from all devices</div>
                            <div class="description">This will log you out from all active sessions</div>
                        </div>
                        <a href="logout_all.php" class="btn-danger-custom" onclick="return confirm('Are you sure you want to logout from all devices?')">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout All
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>