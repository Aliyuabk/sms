<?php
// Start output buffering at the VERY BEGINNING
ob_start();

// includes/header.php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'utgoohwm_sms';
$username = 'utgoohwm_kowaguru';
$password = 'Jiddaahh@1';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper functions
function getQuickStat($pdo, $sql) {
    try {
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_NUM);
        return $result[0] ?? 0;
    } catch (Exception $e) {
        error_log("Quick stat error: " . $e->getMessage());
        return 0;
    }
}

function timeAgo($datetime) {
    if (!$datetime) return 'Never';
    
    $time = strtotime($datetime);
    $time = time() - $time;
    
    $units = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    
    foreach ($units as $unit => $val) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return ($val == 'second') ? 'just now' : 
               (($numberOfUnits > 1) ? $numberOfUnits.' '.$val.'s ago' : '1 '.$val.' ago');
    }
    return 'just now';
}

// Authentication check
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isAdminLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        // Clear any output buffer before redirect
        if (ob_get_length()) {
            ob_clean();
        }
        header('Location: login.php');
        exit();
    }
}

// Check login for all pages except login.php
$current_file = basename($_SERVER['PHP_SELF']);
$excluded_files = ['login.php', 'logout.php', 'forgot-password.php', 'reset-password.php'];

if (!in_array($current_file, $excluded_files)) {
    requireLogin();
}

// Set admin info from session
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'Administrator';
$admin_email = $_SESSION['admin_email'] ?? 'admin@university.edu';
$admin_id = $_SESSION['admin_id'] ?? 0;

// Get notification count
$notification_count = 0;
if ($admin_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0");
        $stmt->execute();
        $notification_count = $stmt->fetch()['count'] ?? 0;
    } catch (Exception $e) {
        // Silent fail
    }
}

// Clear the output buffer before starting HTML output
ob_clean();
?>

<!DOCTYPE html>
<html lang="en"> 
<head>
    <title><?php echo isset($page_title) ? $page_title . ' | ' : ''; ?>5G E-GURUSCHOOL Portal</title>
    
    <!-- Meta -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="5G E-GURUSCHOOL Student Portal Admin Dashboard">
    <meta name="author" content="5G E-GURUSCHOOL">    
    <link rel="shortcut icon" href="../assets/images/logo.jpeg"> 
    
    <!-- FontAwesome JS-->
    <script defer src="../assets/plugins/fontawesome/js/all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- App CSS -->  
    <link id="theme-style" rel="stylesheet" href="../assets/css/portal.css">
    <link id="theme-style" rel="stylesheet" href="../assets/css/modals.css">
    
    <style>
        /* Dashboard specific styles */
        .app-wrapper {
            padding-top: 70px;
        }
        
        .app-card {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-bottom: 1.5rem;
        }
        
        .app-card-stat {
            position: relative;
            overflow: hidden;
        }
        
        .app-card-stat::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, #4361ee, #7209b7);
        }
        
        .stats-type {
            font-size: 14px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stats-figure {
            font-size: 2.5rem;
            font-weight: 700;
            color: #212529;
            line-height: 1;
            margin: 10px 0;
        }
        
        .stats-meta {
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .stats-detail {
            font-size: 13px;
            color: #6c757d;
        }
        
        .app-card-link-mask {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
        }
        
        .border-left-decoration {
            border-left: 4px solid #4361ee;
        }
        
        .app-btn-primary {
            background: linear-gradient(to right, #4361ee, #3a0ca3);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .app-btn-primary:hover {
            background: linear-gradient(to right, #3a0ca3, #7209b7);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .app-btn-outline-primary {
            border: 2px solid #4361ee;
            color: #4361ee;
            background: transparent;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .app-btn-outline-primary:hover {
            background: #4361ee;
            color: white;
        }
        
        .app-card-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1.25rem;
        }
        
        .app-card-title {
            font-weight: 600;
            color: #212529;
            margin: 0;
        }
        
        .app-card-body {
            padding: 1.25rem;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .progress-bar {
            border-radius: 4px;
        }
        
        .app-icon-holder {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .icon-holder-sm {
            width: 40px;
            height: 40px;
        }
        
        .icon-holder-lg {
            width: 60px;
            height: 60px;
        }
        
        .table.app-table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .cell {
            padding: 1rem;
            vertical-align: middle;
        }
        
        /* Quick stats table */
        .app-card-stats-table .table-borderless td {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .app-card-stats-table .table-borderless tr:last-child td {
            border-bottom: none;
        }
        
        /* Activity list */
        .app-card-activity .list-group-item {
            border-left: none;
            border-right: none;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .app-card-activity .list-group-item:last-child {
            border-bottom: none;
        }
		.nested-item{
			margin-left:60px; 
		}
		.nested-link{
			color: #6c757d;
			padding: 8px 16px;
		}
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .app-content {
                padding: 1rem;
            }
            
            .stats-figure {
                font-size: 2rem;
            }
            
            .app-card-header,
            .app-card-body {
                padding: 1rem;
            }
        }
    </style>
</head> 
<?php include 'preloader.php'; ?>
<body class="app">   
<header class="app-header fixed-top">	   
    <div class="app-header-inner">  
        <div class="container-fluid py-2">
            <div class="app-header-content"> 
                <div class="row justify-content-between align-items-center">
                    
                    <div class="col-auto">
                        <a id="sidepanel-toggler" class="sidepanel-toggler d-inline-block d-xl-none" href="#">
                            <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" role="img">
                                <title>Menu</title>
                                <path stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2" d="M4 7h22M4 15h22M4 23h22"></path>
                            </svg>
                        </a>
                    </div><!--//col-->
                    
                    <div class="search-mobile-trigger d-sm-none col">
                        <i class="search-mobile-trigger-icon fa-solid fa-magnifying-glass"></i>
                    </div><!--//col-->
                    
                    <div class="app-search-box col">
                        <form class="app-search-form" action="search.php" method="GET">   
                            <input type="text" placeholder="Search students, courses..." name="q" class="form-control search-input">
                            <button type="submit" class="btn search-btn btn-primary" value="Search">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button> 
                        </form>
                    </div><!--//app-search-box-->
                    
                    <div class="app-utilities col-auto">
                        <div class="app-utility-item app-notifications-dropdown dropdown">    
                            <a class="dropdown-toggle no-toggle-arrow" id="notifications-dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false" title="Notifications">
                                <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-bell icon" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2z"/>
                                    <path fill-rule="evenodd" d="M8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/>
                                </svg>
                                <?php if ($notification_count > 0): ?>
                                <span class="icon-badge"><?php echo $notification_count; ?></span>
                                <?php endif; ?>
                            </a>
                            
                            <div class="dropdown-menu p-0" aria-labelledby="notifications-dropdown-toggle" style="width: 350px; max-height: 400px; overflow-y: auto;">
                                <div class="dropdown-menu-header p-3">
                                    <h5 class="dropdown-menu-title mb-0">Notifications</h5>
                                </div>
                                <div class="dropdown-menu-content">
                                    <?php
                                    try {
                                        $stmt = $pdo->query("SELECT * FROM notifications ORDER BY sent_date DESC LIMIT 5");
                                        $notifications = $stmt->fetchAll();
                                        
                                        if (empty($notifications)): ?>
                                        <div class="item p-3 text-center text-muted">
                                            <i class="fas fa-bell-slash me-2"></i>
                                            No notifications
                                        </div>
                                        <?php else: 
                                            foreach ($notifications as $notification):
                                                $time_ago = timeAgo($notification['sent_date']);
                                        ?>
                                        <div class="item p-3">
                                            <div class="row gx-2">
                                                <div class="col">
                                                    <div class="info"> 
                                                        <div class="desc"><?php echo htmlspecialchars($notification['title']); ?></div>
                                                        <div class="meta"><?php echo $time_ago; ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; 
                                        endif;
                                    } catch (Exception $e) {
                                        echo '<div class="item p-3 text-center text-danger">Error loading notifications</div>';
                                    }
                                    ?>
                                </div>
                                <div class="dropdown-menu-footer p-2 text-center">
                                    <a href="notifications.php">View all notifications</a>
                                </div>
                            </div>
                        </div><!--//app-utility-item-->
                        
                        <div class="app-utility-item">
                            <a href="settings.php" title="Settings">
                                <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-gear icon" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M8.837 1.626c-.246-.835-1.428-.835-1.674 0l-.094.319A1.873 1.873 0 0 1 4.377 3.06l-.292-.16c-.764-.415-1.6.42-1.184 1.185l.159.292a1.873 1.873 0 0 1-1.115 2.692l-.319.094c-.835.246-.835 1.428 0 1.674l.319.094a1.873 1.873 0 0 1 1.115 2.693l-.16.291c-.415.764.42 1.6 1.185 1.184l.292-.159a1.873 1.873 0 0 1 2.692 1.116l.094.318c.246.835 1.428.835 1.674 0l.094-.319a1.873 1.873 0 0 1 2.693-1.115l.291.16c.764.415 1.6-.42 1.184-1.185l-.159-.291a1.873 1.873 0 0 1 1.116-2.693l.318-.094c.835-.246.835-1.428 0-1.674l-.319-.094a1.873 1.873 0 0 1-1.115-2.692l.16-.292c.415-.764-.42-1.6-1.185-1.184l-.291.159A1.873 1.873 0 0 1 8.93 1.945l-.094-.319zm-2.633-.283c.527-1.79 3.065-1.79 3.592 0l.094.319a.873.873 0 0 0 1.255.52l.292-.16c1.64-.892 3.434.901 2.54 2.541l-.159.292a.873.873 0 0 0 .52 1.255l.319.094c1.79.527 1.79 3.065 0 3.592l-.319.094a.873.873 0 0 0-.52 1.255l.16.292c.893 1.64-.902 3.434-2.541 2.54l-.292-.159a.873.873 0 0 0-1.255.52l-.094.319c-.527 1.79-3.065 1.79-3.592 0l-.094-.319a.873.873 0 0 0-1.255-.52l-.292.16c-1.64.893-3.433-.902-2.54-2.541l.159-.292a.873.873 0 0 0-.52-1.255l-.319-.094c-1.79-.527-1.79-3.065 0-3.592l.319-.094a.873.873 0 0 0 .52-1.255l-.16-.292c-.892-1.64.902-3.433 2.541-2.54l.292.159a.873.873 0 0 0 1.255-.52l.094-.319z"/>
                                    <path fill-rule="evenodd" d="M8 5.754a2.246 2.246 0 1 0 0 4.492 2.246 2.246 0 0 0 0-4.492zM4.754 8a3.246 3.246 0 1 1 6.492 0 3.246 3.246 0 0 1-6.492 0z"/>
                                </svg>
                            </a>
                        </div><!--//app-utility-item-->
                        
                        <div class="app-utility-item app-user-dropdown dropdown">
                            <a class="dropdown-toggle" id="user-dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                                <img src="../assets/images/profiles/profile-4.png" alt="<?php echo htmlspecialchars($admin_name); ?>">
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="user-dropdown-toggle">
                                <li><a class="dropdown-item" href="profile.php">
                                    <i class="fas fa-user me-2"></i>Profile
                                </a></li>
                                <li><a class="dropdown-item" href="settings.php">
                                    <i class="fas fa-cog me-2"></i>Settings
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Log Out
                                </a></li>
                            </ul>
                        </div><!--//app-user-dropdown--> 
                    </div><!--//app-utilities-->
                </div><!--//row-->
            </div><!--//app-header-content-->
        </div><!--//container-fluid-->
    </div><!--//app-header-inner-->
    
    <div id="app-sidepanel" class="app-sidepanel"> 
        <div id="sidepanel-drop" class="sidepanel-drop"></div>
        <div class="sidepanel-inner d-flex flex-column">
            <a href="#" id="sidepanel-close" class="sidepanel-close d-xl-none">&times;</a>
            <div class="app-branding">
                <a class="app-logo" href="dashboard.php">
                    <img class="logo-icon me-2" src="../assets/images/logo.jpeg" alt="logo">
                    <span class="logo-text">5G E-GURU</span>
                </a>
            </div><!--//app-branding-->  
            
            <?php include 'sidebar.php'; ?>
            
        </div><!--//sidepanel-inner-->
    </div><!--//app-sidepanel-->
</header><!--//app-header-->

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">