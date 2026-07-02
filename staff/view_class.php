<?php
/**
 * View Class Page
 * View detailed information about a specific class
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
$course_id = $_GET['course'] ?? 0;

if (!$course_id) {
    header('Location: courses.php');
    exit;
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Verify staff teaches this course
$checkStmt = $pdo->prepare("
    SELECT * FROM course_assignments 
    WHERE staff_id = ? AND course_id = ? AND status = 'Active'
");
$checkStmt->execute([$staff_id, $course_id]);
if (!$checkStmt->fetch()) {
    header('Location: courses.php?error=unauthorized');
    exit;
}

// Get course details
$courseStmt = $pdo->prepare("
    SELECT c.*, ca.session_year, ca.semester, ca.assigned_date
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE c.course_id = ?
");
$courseStmt->execute([$course_id]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

// Get students enrolled
$studentStmt = $pdo->prepare("
    SELECT 
        s.student_id,
        s.matric_number,
        s.first_name,
        s.last_name,
        s.email,
        s.phone,
        s.gender,
        s.profile_image,
        s.registration_date,
        cr.registration_date as enrolled_date,
        cr.registration_status
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$studentStmt->execute([$course_id]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance summary
$attStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_classes,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) as excused_count
    FROM attendance
    WHERE course_id = ?
");
$attStmt->execute([$course_id]);
$attendance_summary = $attStmt->fetch(PDO::FETCH_ASSOC);

// Get results summary
$resultStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_results,
        AVG(total_score) as avg_score,
        MIN(total_score) as min_score,
        MAX(total_score) as max_score
    FROM results
    WHERE course_id = ? AND is_published = 1
");
$resultStmt->execute([$course_id]);
$result_summary = $resultStmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'View Class';
$page_icon = 'fas fa-users';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses', 'url' => 'courses.php'],
    ['title' => htmlspecialchars($course['course_code'] ?? 'Class')]
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .class-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 20px;
        padding: 30px 35px;
        color: var(--white);
        margin-bottom: 30px;
    }
    .class-header h3 {
        font-weight: 800;
        margin-bottom: 5px;
    }
    .class-header .class-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-top: 10px;
        opacity: 0.9;
        font-size: 0.9rem;
    }
    .class-header .class-meta i { margin-right: 5px; }
    
    .info-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        height: 100%;
        transition: var(--transition);
        border: 1px solid var(--gray-200);
    }
    .info-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }
    .info-card .number {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
    }
    .info-card .label {
        font-size: 0.85rem;
        color: var(--text-light);
        font-weight: 500;
    }
    .info-card .icon {
        float: right;
        font-size: 2rem;
        opacity: 0.2;
        color: var(--primary-color);
    }
    
    .student-list-table {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .student-list-table table { margin-bottom: 0; }
    .student-list-table th {
        background: var(--gray-100);
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 15px;
        border-bottom: 2px solid var(--gray-200);
    }
    .student-list-table td {
        padding: 10px 15px;
        vertical-align: middle;
        border-bottom: 1px solid var(--gray-200);
    }
    .student-list-table tr:hover td { background: var(--primary-soft); }
    
    .student-avatar-sm {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--primary-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.75rem;
        color: var(--primary-color);
        overflow: hidden;
    }
    .student-avatar-sm img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .action-btn-group {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    .action-btn-group .btn {
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .quick-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    .quick-stat {
        background: var(--white);
        border-radius: 12px;
        padding: 15px 20px;
        box-shadow: var(--shadow-sm);
        border-left: 4px solid var(--primary-color);
    }
    .quick-stat .stat-number {
        font-size: 1.5rem;
        font-weight: 800;
    }
    .quick-stat .stat-label {
        font-size: 0.75rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .quick-stat.attendance { border-left-color: var(--success-color); }
    .quick-stat.attendance .stat-number { color: var(--success-color); }
    .quick-stat.results { border-left-color: var(--primary-color); }
    .quick-stat.results .stat-number { color: var(--primary-color); }
</style>

<!-- Class Header -->
<div class="class-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h3><?php echo htmlspecialchars($course['course_title'] ?? 'Class'); ?></h3>
            <div class="class-meta">
                <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($course['course_code'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-star"></i> <?php echo htmlspecialchars($course['credit_units'] ?? 0); ?> Credits</span>
                <span><i class="fas fa-layer-group"></i> Level <?php echo htmlspecialchars($course['level'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($course['session_year'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-clock"></i> Semester <?php echo htmlspecialchars($course['semester'] ?? 1); ?></span>
            </div>
        </div>
        <div>
            <a href="take_attendance.php?course=<?php echo $course_id; ?>" class="btn btn-light">
                <i class="fas fa-clipboard-check me-1"></i> Take Attendance
            </a>
            <a href="results.php?course=<?php echo $course_id; ?>" class="btn btn-light">
                <i class="fas fa-graduation-cap me-1"></i> Manage Results
            </a>
        </div>
    </div>
</div>

<!-- Quick Stats -->
<div class="quick-stats">
    <div class="quick-stat attendance">
        <div class="stat-number"><?php echo $attendance_summary['total_classes'] ?? 0; ?></div>
        <div class="stat-label">Total Classes</div>
    </div>
    <div class="quick-stat attendance">
        <div class="stat-number"><?php echo $attendance_summary['present_count'] ?? 0; ?></div>
        <div class="stat-label">Present</div>
    </div>
    <div class="quick-stat" style="border-left-color: var(--danger-color);">
        <div class="stat-number" style="color: var(--danger-color);"><?php echo $attendance_summary['absent_count'] ?? 0; ?></div>
        <div class="stat-label">Absent</div>
    </div>
    <div class="quick-stat results">
        <div class="stat-number"><?php echo $result_summary['total_results'] ?? 0; ?></div>
        <div class="stat-label">Results Entered</div>
    </div>
    <div class="quick-stat results">
        <div class="stat-number"><?php echo number_format($result_summary['avg_score'] ?? 0, 1); ?></div>
        <div class="stat-label">Average Score</div>
    </div>
</div>

<!-- Info Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="info-card">
            <i class="fas fa-users icon"></i>
            <div class="number"><?php echo count($students); ?></div>
            <div class="label">Total Students</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-card">
            <i class="fas fa-male icon"></i>
            <div class="number">
                <?php 
                $male = 0;
                foreach ($students as $s) {
                    if (($s['gender'] ?? '') == 'Male') $male++;
                }
                echo $male;
                ?>
            </div>
            <div class="label">Male Students</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-card">
            <i class="fas fa-female icon"></i>
            <div class="number">
                <?php 
                $female = 0;
                foreach ($students as $s) {
                    if (($s['gender'] ?? '') == 'Female') $female++;
                }
                echo $female;
                ?>
            </div>
            <div class="label">Female Students</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="info-card">
            <i class="fas fa-calendar-check icon"></i>
            <div class="number">
                <?php 
                $active = 0;
                foreach ($students as $s) {
                    if (($s['registration_status'] ?? '') == 'Approved') $active++;
                }
                echo $active;
                ?>
            </div>
            <div class="label">Active Enrollments</div>
        </div>
    </div>
</div>

<!-- Student List -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5><i class="fas fa-user-graduate me-2 text-primary"></i> Student List</h5>
    <div>
        <button class="btn btn-outline-custom btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print
        </button>
        <button class="btn btn-outline-custom btn-sm" onclick="exportClassList()">
            <i class="fas fa-file-export me-1"></i> Export
        </button>
    </div>
</div>

<div class="student-list-table">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Student</th>
                <th>Matric No.</th>
                <th>Email</th>
                <th>Gender</th>
                <th>Enrolled</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($students) > 0): ?>
                <?php $counter = 0; foreach ($students as $student): $counter++; ?>
                <tr>
                    <td><?php echo $counter; ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="student-avatar-sm">
                                <?php if (!empty($student['profile_image']) && file_exists('../' . $student['profile_image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Student">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;">ID: <?php echo $student['student_id']; ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                    <td><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></td>
                    <td style="font-size: 0.8rem;">
                        <?php echo date('M d, Y', strtotime($student['enrolled_date'] ?? $student['registration_date'])); ?>
                    </td>
                    <td>
                        <div class="action-btn-group">
                            <a href="student_profile.php?id=<?php echo $student['student_id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="take_attendance.php?course=<?php echo $course_id; ?>&student=<?php echo $student['student_id']; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-clipboard-check"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-users fa-2x mb-2 d-block"></i>
                            No students enrolled in this course yet.
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    function exportClassList() {
        alert('Export functionality will be implemented here.');
        // You can implement CSV/Excel export
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>