<?php
/**
 * Staff Courses Page
 * View all assigned courses with details
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

// Fetch staff data
$stmt = $pdo->prepare("SELECT s.*, d.department_name FROM staff s LEFT JOIN departments d ON s.department_id = d.department_id WHERE s.staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all courses assigned to this staff
$stmt2 = $pdo->prepare("
    SELECT 
        c.course_id,
        c.course_code,
        c.course_title,
        c.credit_units,
        c.course_description,
        ca.session_year,
        ca.semester,
        ca.assigned_date,
        ca.status as assignment_status,
        ca.level,
        COUNT(DISTINCT cr.student_id) as number_of_students,
        COUNT(DISTINCT CASE WHEN cr.registration_status = 'Approved' THEN cr.student_id END) as approved_students
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    LEFT JOIN course_registrations cr ON ca.course_id = cr.course_id 
        AND ca.session_year = cr.session_year 
        AND ca.semester = cr.semester
    WHERE ca.staff_id = ?
    GROUP BY c.course_id, c.course_code, c.course_title, c.credit_units, c.course_description,
             ca.session_year, ca.semester, ca.assigned_date, ca.status, ca.level
    ORDER BY ca.session_year DESC, ca.semester DESC, c.course_code
");
$stmt2->execute([$staff_id]);
$courses = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get current session
$current_session = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

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
    .course-detail-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        height: 100%;
        border: 1px solid var(--gray-200);
    }
    .course-detail-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-light);
    }
    .course-detail-header {
        padding: 20px 25px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .course-detail-header .course-code-badge {
        background: rgba(255,255,255,0.2);
        padding: 6px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
    }
    .course-detail-body {
        padding: 25px;
    }
    .course-detail-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 12px;
    }
    .course-detail-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin: 15px 0;
        padding: 15px 0;
        border-top: 1px solid var(--gray-200);
        border-bottom: 1px solid var(--gray-200);
    }
    .course-detail-meta-item {
        display: flex;
        flex-direction: column;
    }
    .course-detail-meta-item .label {
        font-size: 0.75rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .course-detail-meta-item .value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-top: 2px;
    }
    .course-detail-description {
        color: var(--text-light);
        font-size: 0.95rem;
        line-height: 1.6;
        margin: 15px 0;
    }
    .course-detail-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    .course-detail-actions .btn {
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.85rem;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-primary-custom {
        background: var(--primary-color);
        color: var(--white);
        border: none;
    }
    .btn-primary-custom:hover {
        background: var(--primary-dark);
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(63, 116, 156, 0.3);
    }
    .btn-success-custom {
        background: var(--success-color);
        color: var(--white);
        border: none;
    }
    .btn-success-custom:hover {
        background: #558b2f;
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(124, 179, 66, 0.3);
    }
    .btn-outline-custom {
        background: var(--white);
        color: var(--primary-color);
        border: 1.5px solid var(--primary-soft);
    }
    .btn-outline-custom:hover {
        background: var(--primary-soft);
        color: var(--primary-dark);
        border-color: var(--primary-light);
    }
    .student-count-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 4px 12px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .empty-state-courses {
        text-align: center;
        padding: 80px 20px;
    }
    .empty-state-courses .empty-icon {
        width: 120px;
        height: 120px;
        background: var(--primary-soft);
        border-radius: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 25px;
    }
    .empty-state-courses .empty-icon i {
        font-size: 3rem;
        color: var(--primary-color);
    }
    .empty-state-courses h4 {
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 10px;
    }
    .empty-state-courses p {
        color: var(--text-light);
        max-width: 400px;
        margin: 0 auto;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">My Assigned Courses</h4>
    <span class="badge bg-primary rounded-pill px-3 py-2">
        <i class="fas fa-book me-1"></i> <?php echo count($courses); ?> Courses
    </span>
</div>

<?php if (count($courses) > 0): ?>
    <div class="row g-4">
        <?php foreach ($courses as $course): ?>
        <div class="col-lg-6 col-xl-4">
            <div class="course-detail-card">
                <div class="course-detail-header">
                    <span class="course-code-badge">
                        <i class="fas fa-hashtag me-1"></i> <?php echo htmlspecialchars($course['course_code']); ?>
                    </span>
                    <span class="student-count-badge">
                        <i class="fas fa-users"></i>
                        <?php echo $course['approved_students'] ?? 0; ?> / <?php echo $course['number_of_students'] ?? 0; ?>
                    </span>
                </div>
                <div class="course-detail-body">
                    <h5 class="course-detail-title"><?php echo htmlspecialchars($course['course_title']); ?></h5>
                    
                    <div class="course-detail-meta">
                        <div class="course-detail-meta-item">
                            <span class="label">Level</span>
                            <span class="value">Level <?php echo htmlspecialchars($course['level'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="course-detail-meta-item">
                            <span class="label">Credits</span>
                            <span class="value"><?php echo htmlspecialchars($course['credit_units'] ?? 0); ?> Units</span>
                        </div>
                        <div class="course-detail-meta-item">
                            <span class="label">Semester</span>
                            <span class="value">Semester <?php echo htmlspecialchars($course['semester'] ?? 1); ?></span>
                        </div>
                        <div class="course-detail-meta-item">
                            <span class="label">Session</span>
                            <span class="value"><?php echo htmlspecialchars($course['session_year'] ?? 'N/A'); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($course['course_description'])): ?>
                    <div class="course-detail-description">
                        <?php echo nl2br(htmlspecialchars($course['course_description'])); ?>
                    </div>
                    <?php endif; ?>

                    <div class="course-detail-actions">
                        <a href="view_class.php?course=<?php echo $course['course_id']; ?>" class="btn btn-primary-custom">
                            <i class="fas fa-users"></i> View Class
                        </a>
                        <a href="take_attendance.php?course=<?php echo $course['course_id']; ?>" class="btn btn-success-custom">
                            <i class="fas fa-clipboard-check"></i> Attendance
                        </a>
                        <a href="results.php?course=<?php echo $course['course_id']; ?>" class="btn btn-outline-custom">
                            <i class="fas fa-graduation-cap"></i> Results
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state-courses">
        <div class="empty-icon">
            <i class="fas fa-book-open"></i>
        </div>
        <h4>No Courses Assigned</h4>
        <p>You haven't been assigned to any courses yet. Contact your department head or administrator for course assignments.</p>
        <div class="mt-3 text-muted" style="font-size: 0.85rem;">
            <i class="fas fa-info-circle me-1"></i>
            Course assignments are managed by the academic department.
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>