 <?php
/**
 * Staff Sidebar Include
 * Include after header.php
 * Usage: require_once 'includes/sidebar.php';
 */

// Prevent double inclusion
if (!defined('SIDEBAR_INCLUDED')) {
    define('SIDEBAR_INCLUDED', true);
}

// Define BASE_URL if not already defined
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Detect if we're in /staff/ subdirectory
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if (strpos($scriptDir, '/staff') !== false) {
        define('BASE_URL', '/staff/');
    } else {
        define('BASE_URL', '/');
    }
}
?>
<style>
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 50%, #4a8ab8 100%);
        color: var(--white);
        z-index: 1000;
        transition: var(--transition);
        overflow-y: auto;
        overflow-x: hidden;
        box-shadow: 4px 0 20px rgba(0,0,0,0.15);
    }
    .sidebar.collapsed {
        width: var(--sidebar-collapsed);
    }
    .sidebar-header {
        padding: 25px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        animation: fadeInDown 0.6s ease;
    }
    .logo-container {
        width: 60px;
        height: 60px;
        background: var(--white);
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        transition: var(--transition);
    }
    .logo-container:hover {
        transform: scale(1.05) rotate(5deg);
    }
    .logo-container i {
        font-size: 28px;
        color: var(--primary-color);
    }
    .sidebar-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 2px;
        white-space: nowrap;
        transition: var(--transition);
    }
    .sidebar-subtitle {
        font-size: 0.75rem;
        opacity: 0.7;
        white-space: nowrap;
    }
    .sidebar.collapsed .sidebar-title,
    .sidebar.collapsed .sidebar-subtitle {
        opacity: 0;
        transform: translateX(-20px);
    }
    .nav-menu { padding: 15px 0; }
    .nav-section {
        padding: 0 20px;
        margin-bottom: 8px;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        opacity: 0.5;
        font-weight: 600;
        transition: var(--transition);
    }
    .sidebar.collapsed .nav-section {
        opacity: 0;
        height: 0;
        overflow: hidden;
    }
    .nav-item {
        position: relative;
        margin: 4px 12px;
        border-radius: 12px;
        transition: var(--transition);
        animation: fadeInLeft 0.5s ease backwards;
    }
    .nav-item:nth-child(1) { animation-delay: 0.1s; }
    .nav-item:nth-child(2) { animation-delay: 0.15s; }
    .nav-item:nth-child(3) { animation-delay: 0.2s; }
    .nav-item:nth-child(4) { animation-delay: 0.25s; }
    .nav-item:nth-child(5) { animation-delay: 0.3s; }
    .nav-item:nth-child(6) { animation-delay: 0.35s; }
    .nav-link {
        display: flex;
        align-items: center;
        padding: 14px 18px;
        color: rgba(255,255,255,0.85);
        text-decoration: none;
        border-radius: 12px;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }
    .nav-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 4px;
        height: 100%;
        background: var(--secondary-color);
        border-radius: 0 4px 4px 0;
        transform: scaleY(0);
        transition: var(--transition);
    }
    .nav-link:hover,
    .nav-link.active {
        background: rgba(255,255,255,0.12);
        color: var(--white);
        transform: translateX(5px);
    }
    .nav-link.active::before {
        transform: scaleY(1);
    }
    .nav-link:hover {
        background: rgba(255,255,255,0.08);
    }
    .nav-icon {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: rgba(255,255,255,0.1);
        margin-right: 14px;
        font-size: 1rem;
        transition: var(--transition);
        flex-shrink: 0;
    }
    .nav-link:hover .nav-icon,
    .nav-link.active .nav-icon {
        background: var(--secondary-color);
        color: var(--primary-dark);
        transform: scale(1.1);
    }
    .nav-text {
        font-size: 0.9rem;
        font-weight: 500;
        white-space: nowrap;
        transition: var(--transition);
    }
    .sidebar.collapsed .nav-text {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }
    .nav-badge {
        margin-left: auto;
        background: var(--secondary-color);
        color: var(--primary-dark);
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 700;
        transition: var(--transition);
    }
    .sidebar.collapsed .nav-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        padding: 3px 6px;
        font-size: 0.6rem;
    }
    .sidebar-footer {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 20px;
        border-top: 1px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.1);
    }
    .user-mini {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .user-mini-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: var(--primary-dark);
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .user-mini-info {
        overflow: hidden;
        transition: var(--transition);
    }
    .sidebar.collapsed .user-mini-info {
        opacity: 0;
        width: 0;
    }
    .user-mini-name {
        font-size: 0.85rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .user-mini-role {
        font-size: 0.75rem;
        opacity: 0.7;
    }
    .sidebar-toggle {
        position: absolute;
        top: 20px;
        right: -15px;
        width: 30px;
        height: 30px;
        background: var(--white);
        border: none;
        border-radius: 50%;
        box-shadow: var(--shadow);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary-color);
        font-size: 0.8rem;
        z-index: 1001;
        transition: var(--transition);
    }
    .sidebar-toggle:hover {
        background: var(--secondary-color);
        transform: scale(1.1);
    }
    .sidebar.collapsed .sidebar-toggle {
        transform: rotate(180deg);
    }
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInLeft {
        from { opacity: 0; transform: translateX(-20px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @media (max-width: 991px) {
        .sidebar {
            transform: translateX(-100%);
        }
        .sidebar.show {
            transform: translateX(0);
        }
    }
</style>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
        <i class="fas fa-chevron-left"></i>
    </button>

    <div class="sidebar-header">
        <div class="logo-container">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <div class="sidebar-title">SMS Portal</div>
        <div class="sidebar-subtitle">Staff Module</div>
    </div>

    <nav class="nav-menu">
        <div class="nav-section">Main Menu</div>

        <div class="nav-item">
            <a class="nav-link <?php echo ($active_page ?? '') === 'dashboard' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>dashboard.php">
                <span class="nav-icon"><i class="fas fa-home"></i></span>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link <?php echo ($active_page ?? '') === 'courses' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>courses.php">
                <span class="nav-icon"><i class="fas fa-book"></i></span>
                <span class="nav-text">My Courses</span>
                <span class="nav-badge">3</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link <?php echo ($active_page ?? '') === 'students' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>students.php">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span class="nav-text">Students</span>
            </a>
        </div>

        <div class="nav-section">Account</div>

        <div class="nav-item">
            <a class="nav-link <?php echo ($active_page ?? '') === 'profile' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>profile.php">
                <span class="nav-icon"><i class="fas fa-user"></i></span>
                <span class="nav-text">Profile</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link" href="<?php echo BASE_URL; ?>logout.php">
                <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                <span class="nav-text">Logout</span>
            </a>
        </div>
    </nav>
</aside> 