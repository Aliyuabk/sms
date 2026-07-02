<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Fetch staff data from the actual staff table
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        d.department_name,
        r.role_name as role_name
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN staff_roles r ON s.staff_role_id = r.role_id
    WHERE s.staff_id = ?
");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// If staff not found, logout
if (!$staff) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Alias staff_name for header display
$staff['staff_name'] = $staff['first_name'] . ' ' . $staff['last_name'];

// Fetch courses taught by this staff member
$stmt2 = $pdo->prepare("
    SELECT 
        c.course_id,
        c.course_code,
        c.course_title,
        c.credit_units,
        ca.session_year,
        ca.semester,
        ca.assigned_date,
        ca.status as assignment_status,
        COALESCE(cr.student_count, 0) as number_of_students,
        ca.level
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    LEFT JOIN (
        SELECT course_id, session_year, semester, COUNT(*) as student_count
        FROM course_registrations
        GROUP BY course_id, session_year, semester
    ) cr ON ca.course_id = cr.course_id AND ca.session_year = cr.session_year AND ca.semester = cr.semester
    WHERE ca.staff_id = ?
    AND ca.status = 'Active'
    ORDER BY ca.session_year DESC, ca.semester DESC
");
$stmt2->execute([$staff_id]);
$courses = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get current session
$current_session = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Calculate total students from courses
$total_students = 0;
foreach ($courses as $course) {
    $total_students += $course['number_of_students'];
}

// Add data to staff array for display
$staff['total_courses'] = count($courses);
$staff['total_students'] = $total_students;

// Page variables for header
$page_title = 'Dashboard';
$page_icon = 'fas fa-home';
$active_page = 'dashboard';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Dashboard']
];

// Include header
require_once 'includes/header.php';

// Include sidebar
require_once 'includes/sidebar.php';
?>
<style>
    /* ===== PROFILE HERO ===== */
    .profile-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 50%, #2a5a7a 100%);
        border-radius: 24px;
        padding: 40px;
        color: var(--white);
        position: relative;
        overflow: hidden;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }
    .profile-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(197, 234, 79, 0.15) 0%, transparent 70%);
        border-radius: 50%;
    }
    .profile-hero::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -10%;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
        border-radius: 50%;
    }
    .profile-hero-content {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 30px;
    }
    .profile-avatar {
        width: 100px;
        height: 100px;
        border-radius: 24px;
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        overflow: hidden;
        flex-shrink: 0;
        border: 4px solid rgba(255,255,255,0.2);
        transition: var(--transition);
    }
    .profile-avatar:hover { transform: scale(1.05); }
    .profile-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .profile-avatar i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }
    .profile-info { flex: 1; }
    .profile-name {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 8px;
    }
    .profile-meta {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 15px;
        opacity: 0.9;
    }
    .profile-meta-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
    }
    .profile-badges {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .profile-badge {
        padding: 8px 16px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(10px);
    }
    .badge-role {
        background: rgba(255,255,255,0.2);
        color: var(--white);
    }
    .badge-dept {
        background: rgba(255,255,255,0.15);
        color: var(--white);
    }
    .badge-contract {
        background: var(--secondary-color);
        color: var(--primary-dark);
    }
    .badge-contract.inactive {
        background: rgba(244, 67, 54, 0.9);
        color: var(--white);
    }
    .profile-session {
        text-align: right;
        position: relative;
        z-index: 1;
    }
    .session-label {
        font-size: 0.8rem;
        opacity: 0.7;
        margin-bottom: 5px;
    }
    .session-value {
        font-size: 1.3rem;
        font-weight: 700;
    }
    .session-badge {
        display: inline-block;
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        margin-top: 8px;
    }

    /* ===== STATS CARDS ===== */
    .stats-row { margin-bottom: 30px; }
    .stat-card {
        background: var(--white);
        border-radius: 20px;
        padding: 25px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.6s ease backwards;
    }
    .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stat-card:nth-child(2) { animation-delay: 0.2s; }
    .stat-card:nth-child(3) { animation-delay: 0.3s; }
    .stat-card:nth-child(4) { animation-delay: 0.4s; }
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
    }
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        transform: scaleX(0);
        transition: var(--transition);
        transform-origin: left;
    }
    .stat-card:hover::before { transform: scaleX(1); }
    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    .stat-icon-wrap {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        transition: var(--transition);
    }
    .stat-card:hover .stat-icon-wrap {
        transform: scale(1.1) rotate(5deg);
    }
    .stat-icon-primary {
        background: linear-gradient(135deg, var(--primary-soft), #d4e8f5);
        color: var(--primary-color);
    }
    .stat-icon-success {
        background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
        color: var(--success-color);
    }
    .stat-icon-info {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        color: var(--primary-light);
    }
    .stat-icon-warning {
        background: linear-gradient(135deg, #fff3e0, #ffe0b2);
        color: var(--warning-color);
    }
    .stat-trend {
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 8px;
        font-weight: 600;
    }
    .trend-up {
        background: #e8f5e9;
        color: var(--success-color);
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--text-dark);
        margin-bottom: 5px;
        line-height: 1;
    }
    .stat-label {
        font-size: 0.9rem;
        color: var(--text-light);
        font-weight: 500;
    }
    .stat-footer {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--gray-200);
        font-size: 0.8rem;
        color: var(--text-light);
    }

    /* ===== SECTION HEADER ===== */
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        animation: fadeInUp 0.5s ease;
    }
    .section-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .section-title i {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, var(--primary-soft), #d4e8f5);
        color: var(--primary-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }
    .view-toggle { display: flex; gap: 8px; }
    .view-btn {
        padding: 8px 16px;
        border-radius: 10px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        color: var(--text-light);
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .view-btn:hover,
    .view-btn.active {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
        box-shadow: 0 4px 12px rgba(63, 116, 156, 0.3);
    }

    /* ===== COURSE CARDS ===== */
    .course-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        animation: fadeInUp 0.6s ease backwards;
        border: 1px solid transparent;
    }
    .course-card:nth-child(1) { animation-delay: 0.1s; }
    .course-card:nth-child(2) { animation-delay: 0.2s; }
    .course-card:nth-child(3) { animation-delay: 0.3s; }
    .course-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-soft);
    }
    .course-header {
        padding: 20px 20px 0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .course-code {
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .course-status {
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .status-active {
        background: #e8f5e9;
        color: var(--success-color);
    }
    .status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        animation: pulse 2s infinite;
    }
    .course-body { padding: 20px; }
    .course-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 12px;
        line-height: 1.4;
    }
    .course-meta {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
    }
    .course-meta-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .course-meta-item i { color: var(--primary-light); }
    .student-stack {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
    }
    .student-avatar-stack { display: flex; }
    .student-mini-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        border: 3px solid var(--white);
        margin-left: -10px;
        transition: var(--transition);
    }
    .student-mini-avatar:first-child { margin-left: 0; }
    .course-card:hover .student-mini-avatar { margin-left: 2px; }
    .course-card:hover .student-mini-avatar:first-child { margin-left: 0; }
    .student-count {
        margin-left: 12px;
        font-weight: 700;
        color: var(--primary-color);
        font-size: 0.95rem;
    }
    .progress-wrap { margin-bottom: 20px; }
    .progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        margin-bottom: 8px;
        color: var(--text-light);
    }
    .progress {
        height: 8px;
        background: var(--gray-200);
        border-radius: 10px;
        overflow: hidden;
    }
    .progress-bar {
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        border-radius: 10px;
        transition: width 1s ease;
    }
    .course-actions { display: flex; gap: 10px; }
    .btn-course {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .btn-course-primary {
        background: var(--primary-color);
        color: var(--white);
        border: none;
    }
    .btn-course-primary:hover {
        background: var(--primary-dark);
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(63, 116, 156, 0.3);
    }
    .btn-course-outline {
        background: var(--white);
        color: var(--primary-color);
        border: 1.5px solid var(--primary-soft);
    }
    .btn-course-outline:hover {
        background: var(--primary-soft);
        color: var(--primary-dark);
        border-color: var(--primary-light);
    }
    .course-footer {
        padding: 15px 20px;
        background: var(--gray-100);
        font-size: 0.8rem;
        color: var(--text-light);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .course-footer i { color: var(--primary-light); }

    /* ===== EMPTY STATE ===== */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        animation: fadeInUp 0.6s ease;
    }
    .empty-icon {
        width: 100px;
        height: 100px;
        background: var(--primary-soft);
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
        animation: float 3s ease-in-out infinite;
    }
    .empty-icon i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }
    .empty-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 8px;
    }
    .empty-text {
        color: var(--text-light);
        font-size: 0.95rem;
    }

    /* ===== QUICK ACTIONS ===== */
    .quick-actions {
        background: var(--white);
        border-radius: 20px;
        padding: 30px;
        box-shadow: var(--shadow-sm);
        animation: fadeInUp 0.7s ease;
    }
    .quick-actions-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .quick-actions-title i { color: var(--warning-color); }
    .action-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }
    .action-item {
        padding: 25px 15px;
        border-radius: 16px;
        border: 1.5px solid var(--gray-200);
        background: var(--white);
        text-align: center;
        text-decoration: none;
        color: var(--text-dark);
        transition: var(--transition);
        cursor: pointer;
    }
    .action-item:hover {
        border-color: var(--primary-light);
        background: var(--primary-soft);
        transform: translateY(-5px);
        box-shadow: var(--shadow);
        color: var(--primary-dark);
    }
    .action-icon {
        width: 50px;
        height: 50px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        font-size: 1.3rem;
        transition: var(--transition);
    }
    .action-item:hover .action-icon { transform: scale(1.15); }
    .action-icon-upload {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        color: var(--primary-color);
    }
    .action-icon-export {
        background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
        color: var(--success-color);
    }
    .action-icon-message {
        background: linear-gradient(135deg, #e0f7fa, #b2ebf2);
        color: #00acc1;
    }
    .action-icon-analytics {
        background: linear-gradient(135deg, #fff3e0, #ffe0b2);
        color: var(--warning-color);
    }
    .action-label {
        font-size: 0.9rem;
        font-weight: 600;
    }

    /* ===== FOOTER ===== */
    .main-footer {
        margin-left: var(--sidebar-width);
        padding: 20px 30px;
        background: var(--white);
        border-top: 1px solid var(--gray-200);
        transition: var(--transition);
        animation: fadeInUp 0.5s ease;
    }
    .sidebar.collapsed ~ .main-footer {
        margin-left: var(--sidebar-collapsed);
    }
    .footer-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .footer-text {
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .footer-links { display: flex; gap: 20px; }
    .footer-links a {
        color: var(--text-light);
        text-decoration: none;
        font-size: 0.85rem;
        transition: var(--transition);
    }
    .footer-links a:hover { color: var(--primary-color); }
    .footer-version {
        font-size: 0.8rem;
        color: var(--gray-500);
        background: var(--gray-100);
        padding: 4px 12px;
        border-radius: 8px;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    @media (max-width: 1200px) {
        .action-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 768px) {
        .profile-hero-content { flex-direction: column; text-align: center; }
        .profile-session { text-align: center; margin-top: 20px; }
        .action-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Profile Hero -->
<div class="profile-hero">
    <div class="profile-hero-content">
        <div class="profile-avatar">
            <?php if (!empty($staff['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($staff['profile_image']); ?>" alt="Profile">
            <?php else: ?>
                <i class="fas fa-user-tie"></i>
            <?php endif; ?>
        </div>

        <div class="profile-info">
            <h1 class="profile-name"><?php echo htmlspecialchars($staff['staff_name'] ?? 'Staff Member'); ?></h1>
            <div class="profile-meta">
                <div class="profile-meta-item">
                    <i class="fas fa-id-badge"></i>
                    <?php echo htmlspecialchars($staff['staff_number'] ?? 'N/A'); ?>
                </div>
                <div class="profile-meta-item">
                    <i class="fas fa-envelope"></i>
                    <?php echo htmlspecialchars($staff['email'] ?? 'N/A'); ?>
                </div>
                <?php if (!empty($staff['phone'])): ?>
                <div class="profile-meta-item">
                    <i class="fas fa-phone"></i>
                    <?php echo htmlspecialchars($staff['phone']); ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="profile-badges">
                <span class="profile-badge badge-role">
                    <i class="fas fa-user-tag"></i>
                    <?php echo htmlspecialchars($staff['role'] ?? 'Staff'); ?>
                </span>
                <span class="profile-badge badge-dept">
                    <i class="fas fa-building"></i>
                    <?php echo htmlspecialchars($staff['department_name'] ?? 'N/A'); ?>
                </span>
                <span class="profile-badge badge-contract <?php echo ($staff['contract_status'] ?? '') === 'Active' ? '' : 'inactive'; ?>">
                    <i class="fas fa-circle" style="font-size: 8px;"></i>
                    <?php echo htmlspecialchars($staff['contract_status'] ?? 'Active'); ?> Contract
                </span>
            </div>
        </div>

        <div class="profile-session d-none d-lg-block">
            <div class="session-label">Current Academic Session</div>
            <div class="session-value">
                <?php echo htmlspecialchars($current_session['session_year'] ?? 'N/A'); ?>
            </div>
            <span class="session-badge">
                Semester <?php echo htmlspecialchars($current_session['semester'] ?? 'N/A'); ?>
            </span>
            <div style="margin-top: 10px; font-size: 0.8rem; opacity: 0.7;">
                <i class="fas fa-clock me-1"></i>
                Last Login: <?php echo htmlspecialchars($_SESSION['staff_last_login'] ?? 'Just now'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 stats-row">
    <div class="col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-primary">
                    <i class="fas fa-book-open"></i>
                </div>
                <span class="stat-trend trend-up">
                    <i class="fas fa-arrow-up me-1"></i>Active
                </span>
            </div>
            <div class="stat-value"><?php echo $staff['total_courses'] ?? 0; ?></div>
            <div class="stat-label">Active Courses</div>
            <div class="stat-footer">
                <i class="fas fa-layer-group me-1"></i>
                Current Session
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-success">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <span class="stat-trend trend-up">
                    <i class="fas fa-arrow-up me-1"></i>+12%
                </span>
            </div>
            <div class="stat-value"><?php echo $staff['total_students'] ?? 0; ?></div>
            <div class="stat-label">Total Students</div>
            <div class="stat-footer">
                <i class="fas fa-users me-1"></i>
                Across all courses
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-info">
                    <i class="fas fa-briefcase"></i>
                </div>
            </div>
            <div class="stat-value" style="font-size: 1.3rem; margin-top: 10px;">
                <?php echo htmlspecialchars($staff['employment_type'] ?? 'N/A'); ?>
            </div>
            <div class="stat-label">Employment Type</div>
            <div class="stat-footer">
                <i class="fas fa-clock me-1"></i>
                Since <?php echo htmlspecialchars($staff['employment_date'] ?? 'N/A'); ?>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-warning">
                    <i class="fas fa-calendar-alt"></i>
                </div>
            </div>
            <div class="stat-value" style="font-size: 1.3rem; margin-top: 10px;">
                <?php echo htmlspecialchars($staff['contract_status'] ?? 'Active'); ?>
            </div>
            <div class="stat-label">Contract Status</div>
            <div class="stat-footer">
                <i class="fas fa-shield-alt me-1"></i>
                Employment Status
            </div>
        </div>
    </div>
</div>

<!-- My Classes Section -->
<div class="section-header">
    <div class="section-title">
        <i class="fas fa-chalkboard-teacher"></i>
        My Classes
    </div>
    <div class="view-toggle">
        <button class="view-btn active" onclick="toggleView('grid', this)">
            <i class="fas fa-th-large"></i> Grid
        </button>
        <button class="view-btn" onclick="toggleView('list', this)">
            <i class="fas fa-list"></i> List
        </button>
    </div>
</div>

<div class="row g-4" id="coursesContainer">
    <?php if (count($courses) > 0): ?>
        <?php foreach ($courses as $course): ?>
        <div class="col-md-6 col-lg-4 course-item">
            <div class="course-card h-100">
                <div class="course-header">
                    <span class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                    <span class="course-status status-active">
                        <span class="status-dot"></span>
                        <?php echo htmlspecialchars($course['assignment_status']); ?>
                    </span>
                </div>

                <div class="course-body">
                    <h5 class="course-title"><?php echo htmlspecialchars($course['course_title']); ?></h5>

                    <div class="course-meta">
                        <div class="course-meta-item">
                            <i class="fas fa-layer-group"></i>
                            Level <?php echo htmlspecialchars($course['level']); ?>
                        </div>
                        <div class="course-meta-item">
                            <i class="fas fa-star"></i>
                            <?php echo htmlspecialchars($course['credit_units']); ?> Units
                        </div>
                    </div>

                    <div class="student-stack">
                        <div class="student-avatar-stack">
                            <?php 
                            $student_count = $course['number_of_students'];
                            for ($i = 0; $i < min(4, $student_count); $i++): 
                            ?>
                            <div class="student-mini-avatar"><?php echo chr(65 + $i); ?></div>
                            <?php endfor; ?>
                            <?php if ($student_count > 4): ?>
                            <div class="student-mini-avatar" style="background: var(--gray-500);">+<?php echo $student_count - 4; ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="student-count"><?php echo $student_count; ?> Students</span>
                    </div>

                    <div class="progress-wrap">
                        <div class="progress-label">
                            <span>Class Capacity</span>
                            <span><?php echo min(100, $student_count * 5); ?>%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width: 0%" data-width="<?php echo min(100, $student_count * 5); ?>"></div>
                        </div>
                    </div>

                    <div class="course-actions">
                        <a href="view_class.php?course=<?php echo $course['course_id']; ?>" class="btn-course btn-course-primary">
                            <i class="fas fa-users"></i>View Class
                        </a>
                        <a href="take_attendance.php?course=<?php echo $course['course_id']; ?>" class="btn-course btn-course-outline">
                            <i class="fas fa-clipboard-check"></i>Attendance
                        </a>
                    </div>
                </div>

                <div class="course-footer">
                    <i class="fas fa-calendar"></i>
                    <?php echo htmlspecialchars($course['session_year']); ?> - Semester <?php echo htmlspecialchars($course['semester']); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <h5 class="empty-title">No courses assigned yet</h5>
                <p class="empty-text">You don't have any active course assignments for the current session.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
<div class="quick-actions mt-4">
    <div class="quick-actions-title">
        <i class="fas fa-bolt"></i>
        Quick Actions
    </div>
    <div class="action-grid">
        <a href="upload_results.php" class="action-item">
            <div class="action-icon action-icon-upload">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="action-label">Upload Results</div>
        </a>
        <a href="export_courses.php" class="action-item">
            <div class="action-icon action-icon-export">
                <i class="fas fa-file-export"></i>
            </div>
            <div class="action-label">Export Class List</div>
        </a>
        <a href="message_student.php" class="action-item">
            <div class="action-icon action-icon-message">
                <i class="fas fa-envelope"></i>
            </div>
            <div class="action-label">Message Students</div>
        </a> 
    </div>
</div>

<script>
    // View Toggle
    function toggleView(view, btn) {
        document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const container = document.getElementById('coursesContainer');
        const items = container.querySelectorAll('.course-item');

        if (view === 'list') {
            items.forEach(item => {
                item.classList.remove('col-md-6', 'col-lg-4');
                item.classList.add('col-12');
            });
        } else {
            items.forEach(item => {
                item.classList.remove('col-12');
                item.classList.add('col-md-6', 'col-lg-4');
            });
        }
    }

    // Animate progress bars on load
    window.addEventListener('load', () => {
        document.querySelectorAll('.progress-bar[data-width]').forEach(bar => {
            setTimeout(() => {
                bar.style.width = bar.getAttribute('data-width') + '%';
            }, 500);
        });
    });
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>