<?php 
require_once '../config/db.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit();
}

$student = getStudentData($conn, $_SESSION['student_id']);

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as count FROM notifications WHERE student_id = ? AND is_read = 0";
$stmt = $conn->prepare($notif_query);
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$notif_result = $stmt->get_result();
$unread_count = $notif_result->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>5G E-GURUSCHOOL Student Portal</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="../assets/images/logo.jpeg"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

    :root {
    /* Primary: Logo Blue */
    --primary-color: #3f749c;
    --primary-dark: #2a5a7a;
    --primary-light: #5a9bc4;
    --primary-soft: #e8f2f8;
    
    /* Secondary: Logo Lime/Yellow-Green */
    --secondary-color: #c5ea4f;
    --accent-color: #d4f07a;
    
    /* Functional colors */
    --danger-color: #f44336;
    --warning-color: #ff9800;
    --success-color: #7cb342;
    
    /* Text */
    --text-dark: #2c3e50;
    --text-light: #7f8c8d;
    
    /* Neutrals */
    --white: #ffffff;
    --gray-100: #f8f9fa;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    
    /* Shadows & effects */
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
    --shadow: 0 4px 6px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
    --transition: all 0.3s ease;
    
    /* Layout */
    --sidebar-width: 280px;
    --sidebar-collapsed: 80px;
    --header-height: 70px;
}

        body {
            background: var(--gray-100);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body.sidebar-open {
            overflow: hidden;
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 199;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Header Styles */
        .header {
            position: fixed;
            top: 0;
            right: 0;
            left: var(--sidebar-width);
            height: var(--header-height);
            background: var(--white);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            transition: var(--transition);
            z-index: 100;
        }

        body.sidebar-collapsed .header {
            left: var(--sidebar-collapsed);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .menu-toggle {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: var(--gray-100);
            transition: var(--transition);
        }

        .menu-toggle:hover {
            background: var(--primary-soft);
        }

        .menu-toggle svg {
            width: 24px;
            height: 24px;
            fill: var(--text-dark);
            transition: var(--transition);
        }

        .menu-toggle:hover svg {
            fill: var(--primary-color);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .page-title span {
            color: var(--primary-color);
            margin-left: 5px;
            font-weight: 400;
            font-size: 14px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .notification-badge {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .notification-badge:hover {
            background: var(--gray-100);
        }

        .notification-badge svg {
            width: 24px;
            height: 24px;
            fill: var(--text-light);
            transition: var(--transition);
        }

        .notification-badge:hover svg {
            fill: var(--primary-color);
        }

        .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger-color);
            color: var(--white);
            font-size: 11px;
            font-weight: 600;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            animation: pulse 2s infinite;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 5px 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .user-profile:hover {
            background: var(--gray-100);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 16px;
            transition: var(--transition);
        }

        .user-profile:hover .user-avatar {
            transform: scale(1.05);
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            color: var(--text-light);
        }

        .dropdown-icon {
            width: 20px;
            height: 20px;
            fill: var(--text-light);
            transition: var(--transition);
        }

        .user-profile:hover .dropdown-icon {
            fill: var(--primary-color);
            transform: rotate(180deg);
        }

        /* Dropdown Menu */
        .user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 200px;
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 10px 0;
            display: none;
            z-index: 1000;
        }

        .user-dropdown.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        .user-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .user-dropdown a:hover {
            background: var(--gray-100);
            color: var(--primary-color);
        }

        .user-dropdown a svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .user-dropdown .divider {
            height: 1px;
            background: var(--gray-200);
            margin: 8px 0;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: var(--white);
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, width 0.3s ease;
            z-index: 200;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 5px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            border-bottom: 1px solid var(--gray-200);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .logo-icon svg {
            width: 24px;
            height: 24px;
            fill: var(--white);
        }

        .logo-text {
            font-weight: 700;
            font-size: 18px;
            color: var(--text-dark);
            white-space: nowrap;
            transition: var(--transition);
        }

        .logo-text span {
            color: var(--primary-color);
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .close-sidebar {
            display: none;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--gray-100);
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        .close-sidebar svg {
            width: 20px;
            height: 20px;
            fill: var(--text-light);
        }

        .close-sidebar:hover {
            background: var(--danger-color);
        }

        .close-sidebar:hover svg {
            fill: var(--white);
        }

        .student-info {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid var(--gray-200);
        }

        .student-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--white);
            font-size: 32px;
            font-weight: 600;
            transition: var(--transition);
        }

        .sidebar.collapsed .student-avatar {
            width: 50px;
            height: 50px;
            font-size: 20px;
        }

        .student-details {
            transition: var(--transition);
        }

        .sidebar.collapsed .student-details {
            display: none;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .student-meta {
            font-size: 12px;
            color: var(--text-light);
        }

        .student-level {
            background: var(--primary-soft);
            color: var(--primary-dark);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-top: 8px;
        }

        .nav-menu {
            padding: 20px 0;
        }

        .nav-item {
            list-style: none;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: var(--text-light);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary-color);
            transform: translateX(-100%);
            transition: var(--transition);
        }

        .nav-link:hover::before,
        .nav-link.active::before {
            transform: translateX(0);
        }

        .nav-link:hover,
        .nav-link.active {
            background: var(--primary-soft);
            color: var(--primary-color);
        }

        .nav-link svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
            transition: var(--transition);
        }

        .nav-link span {
            white-space: nowrap;
            transition: var(--transition);
        }

        .sidebar.collapsed .nav-link span {
            display: none;
        }

        .nav-badge {
            background: var(--danger-color);
            color: var(--white);
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: auto;
            animation: pulse 2s infinite;
        }

        .sidebar.collapsed .nav-badge {
            display: none;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - var(--header-height));
        }

        body.sidebar-collapsed .main-content {
            margin-left: var(--sidebar-collapsed);
        }

        /* Footer */
        .footer {
            background: var(--white);
            padding: 20px;
            text-align: center;
            color: var(--text-light);
            font-size: 14px;
            border-top: 1px solid var(--gray-200);
            margin-top: 40px;
        }

        /* Animations */
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(244, 67, 54, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(244, 67, 54, 0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in {
            animation: slideIn 0.5s ease;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width) !important;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .header {
                left: 0 !important;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            body.sidebar-collapsed .header,
            body.sidebar-collapsed .main-content {
                margin-left: 0;
            }

            .close-sidebar {
                display: flex;
            }

            .sidebar.collapsed {
                width: var(--sidebar-width) !important;
            }

            .sidebar.collapsed .logo-text,
            .sidebar.collapsed .student-details {
                display: block !important;
            }

            .sidebar.collapsed .student-avatar {
                width: 80px !important;
                height: 80px !important;
                font-size: 32px !important;
            }

            .sidebar.collapsed .nav-link span {
                display: inline !important;
            }

            .sidebar.collapsed .nav-badge {
                display: inline !important;
            }
        }

        @media (max-width: 576px) {
            .user-info {
                display: none;
            }
            
            .header {
                padding: 0 15px;
            }
            
            .main-content {
                padding: 20px 15px;
            }

            .page-title {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <?php include 'preloader.php'; ?>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

    <header class="header">
        <div class="header-left">
            <div class="menu-toggle" onclick="toggleSidebar()">
                <svg viewBox="0 0 24 24">
                    <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                </svg>
            </div>
            <div class="page-title">
                <?php 
                $page_title = basename($_SERVER['PHP_SELF'], '.php');
                echo ucwords(str_replace('-', ' ', $page_title));
                ?>
            </div>
        </div>

        <div class="header-right">
            <div class="notification-badge" onclick="window.location.href='notifications.php'">
                <svg viewBox="0 0 24 24">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
                <?php if($unread_count > 0): ?>
                    <span class="badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </div>

            <div class="user-profile" onclick="toggleUserMenu(event)">
               <div class="user-avatar">
    <?php 
    $profilePic = !empty($student['profile_image']) ? $student['profile_image'] : (!empty($student['photo']) ? $student['photo'] : null);
    if ($profilePic): 
    ?>
        <img src="<?php echo htmlspecialchars($profilePic); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 10px;">
    <?php else: ?>
        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
    <?php endif; ?>
</div>
                <div class="user-info">
                    <span class="user-name"><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></span>
                    <span class="user-role"><?php echo $student['program_name'] . ' - ' . $student['current_level'] . 'L'; ?></span>
                </div>
                <svg class="dropdown-icon" viewBox="0 0 24 24">
                    <path d="M7 10l5 5 5-5z"/>
                </svg>
                
                <!-- Dropdown Menu -->
                <div class="user-dropdown" id="userDropdown">
                    <a href="profile.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        My Profile
                    </a> 
                    <div class="divider"></div>
                    <a href="logout.php">
                        <svg viewBox="0 0 24 24">
                            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.59L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                        </svg>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

   <?php include 'sidebar.php'; ?>

    <main class="main-content">