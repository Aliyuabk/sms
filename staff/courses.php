<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Fetch all courses assigned to this staff
$stmt = $pdo->prepare("
    SELECT c.course_id, c.course_code, c.course_title, c.credit_units, c.level, c.semester,
           ca.session_year, ca.semester as assigned_semester, ca.assigned_date, ca.status as assignment_status,
           d.department_name,
           COUNT(cr.student_id) as student_count
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_registrations cr ON c.course_id = cr.course_id 
        AND cr.session_year = ca.session_year 
        AND cr.semester = ca.semester 
        AND cr.registration_status = 'Approved'
    WHERE ca.staff_id = ?
    GROUP BY c.course_id, ca.session_year, ca.semester
    ORDER BY ca.session_year DESC, ca.semester DESC, c.course_code
");
$stmt->execute([$staff_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff data
$stmt2 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt2->execute([$staff_id]);
$staff = $stmt2->fetch(PDO::FETCH_ASSOC);

// Get current session
$current_session = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Filter variables
$filter_session = $_GET['session'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Page variables
$page_title = 'My Courses';
$page_icon = 'fas fa-book';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .courses-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 35px;
        color: var(--white);
        margin-bottom: 30px;
        animation: fadeInUp 0.6s ease;
    }
    .courses-hero h1 {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 10px;
    }
    .courses-hero p { opacity: 0.9; font-size: 1rem; }

    .filter-bar-courses {
        background: var(--white);
        border-radius: 16px;
        padding: 20px 25px;
        margin-bottom: 25px;
        box-shadow: var(--shadow-sm);
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
        animation: fadeInUp 0.5s ease;
    }

    .course-card-pro {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        animation: fadeInUp 0.6s ease backwards;
        border: 1px solid transparent;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .course-card-pro:hover {
        transform: translateY(-8px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-soft);
    }
    .course-card-pro:nth-child(1) { animation-delay: 0.1s; }
    .course-card-pro:nth-child(2) { animation-delay: 0.2s; }
    .course-card-pro:nth-child(3) { animation-delay: 0.3s; }
    .course-card-pro:nth-child(4) { animation-delay: 0.4s; }
    .course-card-pro:nth-child(5) { animation-delay: 0.5s; }
    .course-card-pro:nth-child(6) { animation-delay: 0.6s; }

    .course-pro-header {
        padding: 25px 25px 0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .course-pro-code {
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 800;
        letter-spacing: 0.5px;
    }
    .course-pro-status {
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-active-pro { background: #e8f5e9; color: var(--success-color); }
    .status-completed-pro { background: #e3f2fd; color: var(--primary-color); }
    .status-cancelled-pro { background: #ffebee; color: var(--danger-color); }

    .course-pro-body {
        padding: 20px 25px;
        flex: 1;
    }
    .course-pro-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 15px;
        line-height: 1.4;
    }
    .course-pro-meta {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 20px;
    }
    .course-pro-meta-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .course-pro-meta-item i { color: var(--primary-light); width: 16px; }

    .course-pro-stats {
        display: flex;
        gap: 15px;
        padding: 15px 0;
        border-top: 1px solid var(--gray-200);
        border-bottom: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }
    .course-pro-stat {
        text-align: center;
        flex: 1;
    }
    .course-pro-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--primary-color);
    }
    .course-pro-stat-label {
        font-size: 0.75rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .course-pro-actions {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .btn-pro {
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
    .btn-pro-primary {
        background: var(--primary-color);
        color: var(--white);
    }
    .btn-pro-primary:hover {
        background: var(--primary-dark);
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(63, 116, 156, 0.3);
    }
    .btn-pro-outline {
        background: var(--white);
        color: var(--primary-color);
        border: 1.5px solid var(--primary-soft);
    }
    .btn-pro-outline:hover {
        background: var(--primary-soft);
        color: var(--primary-dark);
    }

    .course-pro-footer {
        padding: 15px 25px;
        background: var(--gray-100);
        font-size: 0.8rem;
        color: var(--text-light);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .course-pro-footer i { color: var(--primary-light); }

    .empty-courses {
        text-align: center;
        padding: 80px 20px;
        animation: fadeInUp 0.6s ease;
    }
    .empty-courses-icon {
        width: 120px;
        height: 120px;
        background: var(--primary-soft);
        border-radius: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 25px;
        animation: float 3s ease-in-out infinite;
    }
    .empty-courses-icon i { font-size: 3rem; color: var(--primary-color); }
    .empty-courses h3 { font-size: 1.4rem; font-weight: 700; margin-bottom: 10px; }
    .empty-courses p { color: var(--text-light); font-size: 1rem; }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
</style>

<!-- Hero -->
<div class="courses-hero">
    <h1><i class="fas fa-book-open me-2"></i>My Courses</h1>
    <p>Manage your assigned courses, view students, take attendance, and upload results.</p>
</div>

<!-- Filters -->
<div class="filter-bar-courses">
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-calendar me-1"></i>Session:</span>
        <select class="filter-select" id="filterSession" onchange="applyCourseFilters()" class="filter-select">
            <option value="">All Sessions</option>
            <?php 
            $sessions = array_unique(array_column($courses, 'session_year'));
            foreach ($sessions as $sess): 
            ?>
            <option value="<?php echo $sess; ?>" <?php echo $filter_session === $sess ? 'selected' : ''; ?>>
                <?php echo $sess; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-clock me-1"></i>Semester:</span>
        <select class="filter-select" id="filterSemester" onchange="applyCourseFilters()" class="filter-select">
            <option value="">All Semesters</option>
            <option value="1" <?php echo $filter_semester === '1' ? 'selected' : ''; ?>>First Semester</option>
            <option value="2" <?php echo $filter_semester === '2' ? 'selected' : ''; ?>>Second Semester</option>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-filter me-1"></i>Status:</span>
        <select class="filter-select" id="filterStatus" onchange="applyCourseFilters()" class="filter-select">
            <option value="">All Status</option>
            <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
            <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="Cancelled" <?php echo $filter_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    <a href="export_courses.php" class="btn-export">
        <i class="fas fa-file-export"></i> Export All
    </a>
</div>

<!-- Courses Grid -->
<div class="row g-4" id="coursesGrid">
    <?php if (count($courses) > 0): ?>
        <?php foreach ($courses as $course): 
            $status_class = 'status-active-pro';
            if ($course['assignment_status'] === 'Completed') $status_class = 'status-completed-pro';
            if ($course['assignment_status'] === 'Cancelled') $status_class = 'status-cancelled-pro';
        ?>
        <div class="col-md-6 col-lg-4 course-pro-item" 
             data-session="<?php echo $course['session_year']; ?>"
             data-semester="<?php echo $course['assigned_semester']; ?>"
             data-status="<?php echo $course['assignment_status']; ?>">
            <div class="course-card-pro">
                <div class="course-pro-header">
                    <span class="course-pro-code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                    <span class="course-pro-status <?php echo $status_class; ?>">
                        <i class="fas fa-circle" style="font-size: 6px; vertical-align: middle; margin-right: 4px;"></i>
                        <?php echo $course['assignment_status']; ?>
                    </span>
                </div>

                <div class="course-pro-body">
                    <h5 class="course-pro-title"><?php echo htmlspecialchars($course['course_title']); ?></h5>

                    <div class="course-pro-meta">
                        <div class="course-pro-meta-item">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($course['department_name'] ?? 'N/A'); ?>
                        </div>
                        <div class="course-pro-meta-item">
                            <i class="fas fa-layer-group"></i>
                            Level <?php echo $course['level']; ?>
                        </div>
                        <div class="course-pro-meta-item">
                            <i class="fas fa-star"></i>
                            <?php echo $course['credit_units']; ?> Units
                        </div>
                        <div class="course-pro-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo $course['session_year']; ?>
                        </div>
                    </div>

                    <div class="course-pro-stats">
                        <div class="course-pro-stat">
                            <div class="course-pro-stat-value"><?php echo $course['student_count']; ?></div>
                            <div class="course-pro-stat-label">Students</div>
                        </div>
                        <div class="course-pro-stat">
                            <div class="course-pro-stat-value"><?php echo $course['assigned_semester']; ?></div>
                            <div class="course-pro-stat-label">Semester</div>
                        </div>
                    </div>

                    <div class="course-pro-actions">
                        <a href="view_class.php?course=<?php echo $course['course_id']; ?>" class="btn-pro btn-pro-primary">
                            <i class="fas fa-users"></i> View Class
                        </a>
                        <a href="take_attendance.php?course=<?php echo $course['course_id']; ?>" class="btn-pro btn-pro-outline">
                            <i class="fas fa-clipboard-check"></i> Attendance
                        </a>
                    </div>
                </div>

                <div class="course-pro-footer">
                    <i class="fas fa-calendar-check"></i>
                    Assigned on <?php echo date('M d, Y', strtotime($course['assigned_date'])); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="empty-courses">
                <div class="empty-courses-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <h3>No Courses Assigned</h3>
                <p>You don't have any course assignments yet. Contact your administrator.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function applyCourseFilters() {
        const session = document.getElementById('filterSession').value;
        const semester = document.getElementById('filterSemester').value;
        const status = document.getElementById('filterStatus').value;

        document.querySelectorAll('.course-pro-item').forEach(item => {
            let show = true;
            if (session && item.getAttribute('data-session') !== session) show = false;
            if (semester && item.getAttribute('data-semester') !== semester) show = false;
            if (status && item.getAttribute('data-status') !== status) show = false;
            item.style.display = show ? '' : 'none';
        });
    }
</script>
 