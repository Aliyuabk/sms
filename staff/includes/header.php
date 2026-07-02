<?php
/**
 * Staff Header Include
 * Include at the top of every staff page after session_start()
 * Usage: require_once 'includes/header.php';
 */

// Prevent double output
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Staff Portal'); ?> | SMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon">
    <style>
        :root {
            --primary-color: #3f749c;
            --primary-dark: #2a5a7a;
            --primary-light: #5a9bc4;
            --primary-soft: #e8f2f8;
            --secondary-color: #c5ea4f;
            --accent-color: #d4f07a;
            --danger-color: #f44336;
            --warning-color: #ff9800;
            --success-color: #7cb342;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
            --sidebar-width: 280px;
            --sidebar-collapsed: 80px;
            --header-height: 70px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--gray-100);
            color: var(--text-dark);
            overflow-x: hidden;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            position: fixed;
            top: 0;
            right: 0;
            left: var(--sidebar-width);
            height: var(--header-height);
            background: var(--white);
            box-shadow: var(--shadow-sm);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            transition: var(--transition);
            animation: slideInDown 0.5s ease;
        }
        .sidebar.collapsed ~ .main-header,
        .sidebar.collapsed ~ .main-content .main-header {
            left: var(--sidebar-collapsed);
        }
        .header-left { display: flex; align-items: center; gap: 20px; }
        .page-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title i { color: var(--primary-color); font-size: 1.2rem; }
        .breadcrumb {
            margin: 0;
            padding: 0;
            background: none;
            font-size: 0.85rem;
        }
        .breadcrumb-item a {
            color: var(--text-light);
            text-decoration: none;
            transition: var(--transition);
        }
        .breadcrumb-item a:hover { color: var(--primary-color); }
        .breadcrumb-item.active {
            color: var(--primary-color);
            font-weight: 600;
        }
        .header-right { display: flex; align-items: center; gap: 15px; }
        .header-btn {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            background: var(--white);
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }
        .header-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-color);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        .header-btn .badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger-color);
            color: var(--white);
            font-size: 0.65rem;
            padding: 2px 5px;
            border-radius: 8px;
            border: 2px solid var(--white);
            animation: pulse 2s infinite;
        }
        .header-search { position: relative; }
        .header-search input {
            width: 280px;
            padding: 10px 15px 10px 42px;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            background: var(--gray-100);
            font-size: 0.9rem;
            transition: var(--transition);
        }
        .header-search input:focus {
            outline: none;
            border-color: var(--primary-light);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
            width: 320px;
        }
        .header-search i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }
        .header-user {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 16px 6px 6px;
            border-radius: 14px;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid transparent;
        }
        .header-user:hover {
            background: var(--gray-100);
            border-color: var(--gray-200);
        }
        .header-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            font-size: 0.9rem;
            overflow: hidden;
        }
        .header-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .header-user-info { text-align: left; }
        .header-user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .header-user-role {
            font-size: 0.75rem;
            color: var(--text-light);
        }
        .main-content {
            margin-left: var(--sidebar-width);
            padding-top: var(--header-height);
            min-height: 100vh;
            transition: var(--transition);
        }
        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed);
        }
        .content-wrapper {
            padding: 30px;
            animation: fadeInUp 0.6s ease;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-100%); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--gray-500); }
        
        @media (max-width: 991px) {
            .main-header { margin-left: 0 !important; left: 0 !important; }
            .main-content { margin-left: 0 !important; }
            .header-search input { width: 200px; }
            .header-search input:focus { width: 240px; }
        }
        @media (max-width: 768px) {
            .header-search { display: none; }
            .header-user-info { display: none; }
            .content-wrapper { padding: 20px 15px; }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <header class="main-header">
        <div class="header-left">
            <button class="header-btn d-lg-none" id="mobileMenuBtn" title="Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div>
                <div class="page-title">
                    <i class="<?php echo htmlspecialchars($page_icon ?? 'fas fa-home'); ?>"></i>
                    <?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?>
                </div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <?php if (!empty($breadcrumbs)): ?>
                            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                                <?php if ($index < count($breadcrumbs) - 1): ?>
                                    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($crumb['url'] ?? '#'); ?>"><?php echo htmlspecialchars($crumb['title']); ?></a></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($crumb['title']); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></li>
                        <?php endif; ?>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="header-right">
            <div class="header-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search courses, students..." id="globalSearch">
            </div>

            <button class="header-btn" title="Notifications" id="notifBtn">
                <i class="fas fa-bell"></i>
                <span class="badge">3</span>
            </button>

            <button class="header-btn" title="Messages" id="msgBtn">
                <i class="fas fa-envelope"></i>
            </button>

            <div class="header-user dropdown">
                <div class="header-user-avatar" data-bs-toggle="dropdown">
                    <?php 
                    $name = $_SESSION['staff_name'] ?? 'User';
                    $initial = strtoupper(substr($name, 0, 1));
                    $profile_image = $_SESSION['staff_image'] ?? null;
                    
                    if (!empty($profile_image) && file_exists('../' . $profile_image)) {
                        echo '<img src="../' . htmlspecialchars($profile_image) . '" alt="Profile">';
                    } else {
                        echo $initial;
                    }
                    ?>
                </div>
                <div class="header-user-info d-none d-lg-block" data-bs-toggle="dropdown">
                    <div class="header-user-name"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></div>
                    <div class="header-user-role"><?php echo htmlspecialchars($_SESSION['staff_role'] ?? 'Staff'); ?></div>
                </div>
                <i class="fas fa-chevron-down d-none d-lg-block" style="font-size: 0.7rem; color: var(--gray-500);" data-bs-toggle="dropdown"></i>

                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg" style="border-radius: 16px; padding: 10px;">
                    <li><a class="dropdown-item" href="profile.php" style="border-radius: 10px; padding: 10px 15px;"><i class="fas fa-user me-2 text-primary"></i>My Profile</a></li> 
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php" style="border-radius: 10px; padding: 10px 15px;"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- MAIN CONTENT START -->
    <div class="main-content">
        <div class="content-wrapper">