<?php
/**
 * Staff Students Page
 * View all students across assigned courses
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
$search = $_GET['search'] ?? '';
$course_filter = $_GET['course'] ?? '';

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all courses for filter
$courseStmt = $pdo->prepare("
    SELECT DISTINCT c.course_id, c.course_code, c.course_title
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE ca.staff_id = ?
");
$courseStmt->execute([$staff_id]);
$staff_courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for students
$sql = "
    SELECT DISTINCT 
        s.student_id,
        s.matric_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email,
        s.phone,
        s.gender,
        s.current_level,
        s.status,
        s.profile_image,
        s.registration_date,
        p.program_name,
        d.department_name,
        GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as courses
    FROM students s
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN course_registrations cr ON s.student_id = cr.student_id
    LEFT JOIN courses c ON cr.course_id = c.course_id
    LEFT JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE ca.staff_id = ?
    AND cr.registration_status = 'Approved'
";

$params = [$staff_id];

if (!empty($search)) {
    $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.matric_number LIKE ? OR s.email LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if (!empty($course_filter)) {
    $sql .= " AND c.course_id = ?";
    $params[] = $course_filter;
}

$sql .= " GROUP BY s.student_id ORDER BY s.last_name, s.first_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Students';
$page_icon = 'fas fa-users';
$active_page = 'students';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Students']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .student-card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        border: 1px solid var(--gray-200);
        display: flex;
        flex-direction: column;
    }
    .student-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-light);
    }
    .student-card-header {
        padding: 20px 20px 0;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .student-avatar {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        background: linear-gradient(135deg, var(--primary-soft), var(--primary-light));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-color);
        flex-shrink: 0;
        overflow: hidden;
    }
    .student-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .student-name {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 2px;
    }
    .student-matric {
        font-size: 0.8rem;
        color: var(--text-light);
    }
    .student-card-body {
        padding: 15px 20px 20px;
        flex: 1;
    }
    .student-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 15px;
        margin: 10px 0;
    }
    .student-info-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .student-info-item i {
        color: var(--primary-light);
        width: 16px;
        font-size: 0.8rem;
    }
    .student-courses {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--gray-200);
    }
    .student-courses-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-light);
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }
    .course-tag {
        display: inline-block;
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 2px 10px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        margin: 2px 4px 2px 0;
    }
    .student-card-footer {
        padding: 12px 20px;
        background: var(--gray-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.8rem;
        color: var(--text-light);
        border-top: 1px solid var(--gray-200);
    }
    .student-status {
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-active { background: #e8f5e9; color: var(--success-color); }
    .status-inactive { background: #ffebee; color: var(--danger-color); }
    .status-suspended { background: #fff3e0; color: var(--warning-color); }
    .status-graduated { background: #e3f2fd; color: var(--primary-color); }
    
    .filter-section {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 30px;
    }
    .filter-section .form-control,
    .filter-section .form-select {
        border-radius: 12px;
        border: 1.5px solid var(--gray-200);
        padding: 10px 15px;
        font-size: 0.9rem;
    }
    .filter-section .form-control:focus,
    .filter-section .form-select:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .btn-filter {
        padding: 10px 25px;
        border-radius: 12px;
        font-weight: 600;
    }
    .empty-students {
        text-align: center;
        padding: 60px 20px;
    }
    .empty-students .empty-icon {
        width: 100px;
        height: 100px;
        background: var(--primary-soft);
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }
    .empty-students .empty-icon i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }
</style>

<div class="filter-section">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Search Students</label>
            <input type="text" name="search" class="form-control" placeholder="Search by name, matric, email..." 
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Filter by Course</label>
            <select name="course" class="form-select">
                <option value="">All Courses</option>
                <?php foreach ($staff_courses as $c): ?>
                <option value="<?php echo $c['course_id']; ?>" <?php echo $course_filter == $c['course_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary-custom btn-filter w-100">
                <i class="fas fa-search me-2"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Students (<?php echo count($students); ?>)</h5>
    <div>
        <button class="btn btn-outline-custom btn-sm" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<?php if (count($students) > 0): ?>
    <div class="row g-4">
        <?php foreach ($students as $student): ?>
        <div class="col-lg-6 col-xl-4">
            <div class="student-card">
                <div class="student-card-header">
                    <div class="student-avatar">
                        <?php 
                        $name = $student['first_name'] . ' ' . $student['last_name'];
                        $initial = strtoupper(substr($student['first_name'], 0, 1));
                        if (!empty($student['profile_image']) && file_exists('../' . $student['profile_image'])): 
                        ?>
                            <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Student">
                        <?php else: ?>
                            <?php echo $initial; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="student-name"><?php echo htmlspecialchars($name); ?></div>
                        <div class="student-matric">
                            <i class="fas fa-id-card me-1"></i>
                            <?php echo htmlspecialchars($student['matric_number']); ?>
                        </div>
                    </div>
                </div>
                <div class="student-card-body">
                    <div class="student-info-grid">
                        <div class="student-info-item">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($student['email']); ?>
                        </div>
                        <div class="student-info-item">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?>
                        </div>
                        <div class="student-info-item">
                            <i class="fas fa-venus-mars"></i>
                            <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?>
                        </div>
                        <div class="student-info-item">
                            <i class="fas fa-layer-group"></i>
                            Level <?php echo htmlspecialchars($student['current_level'] ?? 'N/A'); ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($student['courses'])): ?>
                    <div class="student-courses">
                        <div class="student-courses-label">Enrolled Courses</div>
                        <?php 
                        $courses_list = explode(', ', $student['courses']);
                        foreach ($courses_list as $code): 
                        ?>
                            <span class="course-tag"><?php echo htmlspecialchars($code); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="student-card-footer">
                    <span>
                        <i class="fas fa-calendar-alt me-1"></i>
                        <?php echo date('M d, Y', strtotime($student['registration_date'])); ?>
                    </span>
                    <span class="student-status status-<?php echo strtolower($student['status'] ?? 'active'); ?>">
                        <?php echo htmlspecialchars($student['status'] ?? 'Active'); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-students">
        <div class="empty-icon">
            <i class="fas fa-user-graduate"></i>
        </div>
        <h4>No Students Found</h4>
        <p class="text-muted">
            <?php if (!empty($search) || !empty($course_filter)): ?>
                No students match your search criteria. Try adjusting your filters.
            <?php else: ?>
                You don't have any students enrolled in your courses yet.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>