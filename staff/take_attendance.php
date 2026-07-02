<?php
/**
 * Take Attendance Page
 * Quick attendance taking for a specific course
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
    SELECT c.*, ca.session_year, ca.semester
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE c.course_id = ?
");
$courseStmt->execute([$course_id]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

// Get students enrolled
$studentStmt = $pdo->prepare("
    SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.profile_image
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$studentStmt->execute([$course_id]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's date
$today = date('Y-m-d');

// Check if attendance already taken today
$checkAttStmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM attendance 
    WHERE course_id = ? AND class_date = ?
");
$checkAttStmt->execute([$course_id, $today]);
$attendance_taken = $checkAttStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['take_attendance'])) {
    $date = $_POST['date'];
    $attendance_data = $_POST['attendance'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($attendance_data as $student_id => $status) {
            $remark = $remarks[$student_id] ?? '';
            
            $insertStmt = $pdo->prepare("
                INSERT INTO attendance (
                    student_id, course_id, session_year, semester, class_date, 
                    status, hours_attended, recorded_by, remarks, recorded_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $hours = $status == 'Present' ? 2.00 : ($status == 'Late' ? 1.00 : 0.00);
            
            $insertStmt->execute([
                $student_id,
                $course_id,
                $course['session_year'],
                $course['semester'],
                $date,
                $status,
                $hours,
                $staff_id,
                $remark
            ]);
        }
        
        $pdo->commit();
        $success = "Attendance recorded successfully!";
        $attendance_taken = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving attendance: " . $e->getMessage();
        error_log("Attendance Error: " . $e->getMessage());
    }
}

$page_title = 'Take Attendance';
$page_icon = 'fas fa-clipboard-check';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses', 'url' => 'courses.php'],
    ['title' => 'Take Attendance']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .attendance-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 20px;
        padding: 25px 30px;
        color: var(--white);
        margin-bottom: 30px;
    }
    .attendance-header h4 {
        font-weight: 700;
        margin-bottom: 5px;
    }
    .attendance-header .sub-info {
        opacity: 0.85;
        font-size: 0.9rem;
    }
    .attendance-header .sub-info i { margin-right: 5px; }
    
    .attendance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }
    .student-attendance-card {
        background: var(--white);
        border-radius: 16px;
        padding: 20px 15px;
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        border: 2px solid var(--gray-200);
        cursor: pointer;
    }
    .student-attendance-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }
    .student-attendance-card.selected-present {
        border-color: var(--success-color);
        background: #f1f8e9;
    }
    .student-attendance-card.selected-absent {
        border-color: var(--danger-color);
        background: #ffebee;
    }
    .student-attendance-card.selected-late {
        border-color: var(--warning-color);
        background: #fff8e1;
    }
    .student-attendance-card.selected-excused {
        border-color: var(--primary-color);
        background: #e3f2fd;
    }
    .student-attendance-card .avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--primary-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 10px;
        overflow: hidden;
    }
    .student-attendance-card .avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .student-attendance-card .name {
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 2px;
    }
    .student-attendance-card .matric {
        font-size: 0.75rem;
        color: var(--text-light);
    }
    .student-attendance-card .status-badge {
        margin-top: 8px;
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }
    
    .status-options {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    .status-options .btn-option {
        padding: 4px 12px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        font-size: 0.7rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }
    .status-options .btn-option:hover { transform: scale(1.05); }
    .status-options .btn-option.present:hover { background: #e8f5e9; border-color: var(--success-color); }
    .status-options .btn-option.absent:hover { background: #ffebee; border-color: var(--danger-color); }
    .status-options .btn-option.late:hover { background: #fff3e0; border-color: var(--warning-color); }
    .status-options .btn-option.excused:hover { background: #e3f2fd; border-color: var(--primary-color); }
    
    .btn-submit-attendance {
        background: linear-gradient(135deg, var(--success-color), #558b2f);
        border: none;
        padding: 14px 40px;
        border-radius: 14px;
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-submit-attendance:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(124, 179, 66, 0.4);
        color: var(--white);
    }
    .btn-submit-attendance:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    
    .quick-actions-bar {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .quick-actions-bar .btn {
        padding: 8px 20px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    
    .empty-state-attendance {
        text-align: center;
        padding: 60px 20px;
    }
    .empty-state-attendance .empty-icon {
        width: 100px;
        height: 100px;
        background: var(--primary-soft);
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }
    .empty-state-attendance .empty-icon i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }
</style>

<div class="attendance-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h4>
                <i class="fas fa-clipboard-check me-2"></i>
                Take Attendance
            </h4>
            <div class="sub-info">
                <i class="fas fa-book"></i> <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                <span class="mx-2">|</span>
                <i class="fas fa-users"></i> <?php echo count($students); ?> Students
                <span class="mx-2">|</span>
                <i class="fas fa-calendar"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        <?php if ($attendance_taken): ?>
            <span class="badge bg-success p-3">
                <i class="fas fa-check-circle me-2"></i> Attendance Recorded Today
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($students)): ?>

    <?php if ($attendance_taken && !isset($success)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Attendance has already been recorded for today. You can still update it below.
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="date" value="<?php echo $today; ?>">
        <input type="hidden" name="take_attendance" value="1">
        
        <!-- Quick Actions -->
        <div class="quick-actions-bar">
            <button type="button" class="btn btn-success" onclick="markAll('Present')">
                <i class="fas fa-check me-1"></i> All Present
            </button>
            <button type="button" class="btn btn-danger" onclick="markAll('Absent')">
                <i class="fas fa-times me-1"></i> All Absent
            </button>
            <button type="button" class="btn btn-warning" onclick="markAll('Late')">
                <i class="fas fa-clock me-1"></i> All Late
            </button>
            <button type="button" class="btn btn-primary" onclick="markAll('Excused')">
                <i class="fas fa-check-circle me-1"></i> All Excused
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="clearAll()">
                <i class="fas fa-undo me-1"></i> Clear All
            </button>
        </div>
        
        <!-- Student Grid -->
        <div class="attendance-grid">
            <?php foreach ($students as $student): 
                $name = $student['first_name'] . ' ' . $student['last_name'];
                $initial = strtoupper(substr($student['first_name'], 0, 1));
                $has_image = !empty($student['profile_image']) && file_exists('../' . $student['profile_image']);
            ?>
            <div class="student-attendance-card" id="card_<?php echo $student['student_id']; ?>"
                 onclick="toggleStatus(<?php echo $student['student_id']; ?>)">
                <div class="avatar">
                    <?php if ($has_image): ?>
                        <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Student">
                    <?php else: ?>
                        <?php echo $initial; ?>
                    <?php endif; ?>
                </div>
                <div class="name"><?php echo htmlspecialchars($name); ?></div>
                <div class="matric"><?php echo htmlspecialchars($student['matric_number']); ?></div>
                
                <div class="status-options" onclick="event.stopPropagation();">
                    <button type="button" class="btn-option present" onclick="setStatus(<?php echo $student['student_id']; ?>, 'Present')">Present</button>
                    <button type="button" class="btn-option absent" onclick="setStatus(<?php echo $student['student_id']; ?>, 'Absent')">Absent</button>
                    <button type="button" class="btn-option late" onclick="setStatus(<?php echo $student['student_id']; ?>, 'Late')">Late</button>
                    <button type="button" class="btn-option excused" onclick="setStatus(<?php echo $student['student_id']; ?>, 'Excused')">Excused</button>
                </div>
                
                <div class="status-badge" id="badge_<?php echo $student['student_id']; ?>">
                    Not Marked
                </div>
                
                <input type="hidden" name="attendance[<?php echo $student['student_id']; ?>]" 
                       id="status_<?php echo $student['student_id']; ?>" value="">
                <input type="hidden" name="remarks[<?php echo $student['student_id']; ?>]" value="">
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="text-center mt-4">
            <button type="submit" class="btn-submit-attendance" id="submitBtn">
                <i class="fas fa-save me-2"></i> Save Attendance
            </button>
        </div>
    </form>

<?php else: ?>
    <div class="empty-state-attendance">
        <div class="empty-icon">
            <i class="fas fa-user-graduate"></i>
        </div>
        <h5>No Students Enrolled</h5>
        <p class="text-muted">There are no students enrolled in this course to take attendance for.</p>
    </div>
<?php endif; ?>

<script>
    function setStatus(studentId, status) {
        const card = document.getElementById('card_' + studentId);
        const badge = document.getElementById('badge_' + studentId);
        const hiddenInput = document.getElementById('status_' + studentId);
        
        // Remove all selection classes
        card.classList.remove('selected-present', 'selected-absent', 'selected-late', 'selected-excused');
        
        // Set new status
        hiddenInput.value = status;
        
        // Update UI
        const statusColors = {
            'Present': { class: 'selected-present', label: 'Present', color: 'success' },
            'Absent': { class: 'selected-absent', label: 'Absent', color: 'danger' },
            'Late': { class: 'selected-late', label: 'Late', color: 'warning' },
            'Excused': { class: 'selected-excused', label: 'Excused', color: 'primary' }
        };
        
        if (status in statusColors) {
            card.classList.add(statusColors[status].class);
            badge.textContent = statusColors[status].label;
            badge.className = 'status-badge bg-' + statusColors[status].color + ' text-white';
        }
    }
    
    function toggleStatus(studentId) {
        const currentStatus = document.getElementById('status_' + studentId).value;
        const statuses = ['Present', 'Absent', 'Late', 'Excused'];
        let nextStatus = '';
        
        // Cycle through statuses
        const currentIndex = statuses.indexOf(currentStatus);
        if (currentIndex === -1 || currentIndex === statuses.length - 1) {
            nextStatus = statuses[0];
        } else {
            nextStatus = statuses[currentIndex + 1];
        }
        
        setStatus(studentId, nextStatus);
    }
    
    function markAll(status) {
        const cards = document.querySelectorAll('.student-attendance-card');
        cards.forEach(card => {
            const studentId = card.id.replace('card_', '');
            setStatus(studentId, status);
        });
    }
    
    function clearAll() {
        const cards = document.querySelectorAll('.student-attendance-card');
        cards.forEach(card => {
            const studentId = card.id.replace('card_', '');
            const badge = document.getElementById('badge_' + studentId);
            const hiddenInput = document.getElementById('status_' + studentId);
            
            card.classList.remove('selected-present', 'selected-absent', 'selected-late', 'selected-excused');
            hiddenInput.value = '';
            badge.textContent = 'Not Marked';
            badge.className = 'status-badge';
        });
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>