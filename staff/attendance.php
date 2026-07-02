<?php
/**
 * Staff Attendance Page
 * View and manage attendance for courses
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
$date = $_GET['date'] ?? date('Y-m-d');

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all courses for this staff
$courseStmt = $pdo->prepare("
    SELECT DISTINCT c.course_id, c.course_code, c.course_title, ca.session_year, ca.semester
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE ca.staff_id = ? AND ca.status = 'Active'
");
$courseStmt->execute([$staff_id]);
$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Get course details if selected
$course = null;
$students = [];
$attendance_records = [];
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0];

if ($course_id > 0) {
    // Get course details
    $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE course_id = ?");
    $courseStmt->execute([$course_id]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get students enrolled in this course
    $studentStmt = $pdo->prepare("
        SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.profile_image
        FROM students s
        JOIN course_registrations cr ON s.student_id = cr.student_id
        WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
        ORDER BY s.last_name, s.first_name
    ");
    $studentStmt->execute([$course_id]);
    $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance for selected date
    $attStmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE course_id = ? AND class_date = ?
    ");
    $attStmt->execute([$course_id, $date]);
    $attendance_records = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    $attendance_by_student = [];
    foreach ($attendance_records as $rec) {
        $attendance_by_student[$rec['student_id']] = $rec;
    }
    
    // Calculate summary
    foreach ($attendance_records as $rec) {
        if ($rec['status'] == 'Present') $summary['present']++;
        elseif ($rec['status'] == 'Absent') $summary['absent']++;
        elseif ($rec['status'] == 'Late') $summary['late']++;
        elseif ($rec['status'] == 'Excused') $summary['excused']++;
    }
    $summary['total'] = count($students);
}

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $course_id = $_POST['course_id'];
    $date = $_POST['date'];
    $attendance_data = $_POST['attendance'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($attendance_data as $student_id => $status) {
            // Check if attendance record exists
            $checkStmt = $pdo->prepare("
                SELECT attendance_id FROM attendance 
                WHERE student_id = ? AND course_id = ? AND class_date = ?
            ");
            $checkStmt->execute([$student_id, $course_id, $date]);
            $existing = $checkStmt->fetch();
            
            $remark = $remarks[$student_id] ?? '';
            
            if ($existing) {
                // Update
                $updateStmt = $pdo->prepare("
                    UPDATE attendance 
                    SET status = ?, remarks = ?, recorded_by = ?, recorded_date = NOW()
                    WHERE attendance_id = ?
                ");
                $updateStmt->execute([$status, $remark, $staff_id, $existing['attendance_id']]);
            } else {
                // Insert
                $insertStmt = $pdo->prepare("
                    INSERT INTO attendance (student_id, course_id, session_year, semester, class_date, status, hours_attended, recorded_by, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                // Get session info
                $sessionStmt = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
                $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
                
                $insertStmt->execute([
                    $student_id,
                    $course_id,
                    $session['session_year'] ?? date('Y'),
                    $session['semester'] ?? 1,
                    $date,
                    $status,
                    $status == 'Present' ? 2.00 : ($status == 'Late' ? 1.00 : 0.00),
                    $staff_id,
                    $remark
                ]);
            }
        }
        
        $pdo->commit();
        $success = "Attendance saved successfully!";
        
        // Refresh attendance records
        $attStmt = $pdo->prepare("
            SELECT * FROM attendance 
            WHERE course_id = ? AND class_date = ?
        ");
        $attStmt->execute([$course_id, $date]);
        $attendance_records = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        $attendance_by_student = [];
        foreach ($attendance_records as $rec) {
            $attendance_by_student[$rec['student_id']] = $rec;
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving attendance: " . $e->getMessage();
        error_log("Attendance Error: " . $e->getMessage());
    }
}

$page_title = 'Attendance';
$page_icon = 'fas fa-clipboard-check';
$active_page = 'attendance';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Attendance']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .attendance-toolbar {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 30px;
    }
    .attendance-toolbar select,
    .attendance-toolbar input[type="date"] {
        border-radius: 12px;
        border: 1.5px solid var(--gray-200);
        padding: 10px 15px;
        font-size: 0.9rem;
    }
    .attendance-toolbar select:focus,
    .attendance-toolbar input[type="date"]:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .attendance-table {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .attendance-table table {
        margin-bottom: 0;
    }
    .attendance-table th {
        background: var(--gray-100);
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 15px;
        border-bottom: 2px solid var(--gray-200);
    }
    .attendance-table td {
        padding: 12px 15px;
        vertical-align: middle;
        border-bottom: 1px solid var(--gray-200);
    }
    .attendance-table tr:hover td {
        background: var(--primary-soft);
    }
    .student-avatar-small {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: var(--primary-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
        color: var(--primary-color);
        overflow: hidden;
    }
    .student-avatar-small img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-present { background: #e8f5e9; color: var(--success-color); }
    .status-absent { background: #ffebee; color: var(--danger-color); }
    .status-late { background: #fff3e0; color: var(--warning-color); }
    .status-excused { background: #e3f2fd; color: var(--primary-color); }
    
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: var(--white);
        border-radius: 12px;
        padding: 15px;
        text-align: center;
        box-shadow: var(--shadow-sm);
        border-left: 4px solid var(--primary-color);
    }
    .summary-card .number {
        font-size: 1.8rem;
        font-weight: 800;
    }
    .summary-card .label {
        font-size: 0.75rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .summary-card.present { border-left-color: var(--success-color); }
    .summary-card.present .number { color: var(--success-color); }
    .summary-card.absent { border-left-color: var(--danger-color); }
    .summary-card.absent .number { color: var(--danger-color); }
    .summary-card.late { border-left-color: var(--warning-color); }
    .summary-card.late .number { color: var(--warning-color); }
    .summary-card.excused { border-left-color: var(--primary-color); }
    .summary-card.excused .number { color: var(--primary-color); }
    
    .quick-status-btn {
        padding: 6px 14px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }
    .quick-status-btn:hover { transform: scale(1.05); }
    .quick-status-btn.present:hover { background: #e8f5e9; border-color: var(--success-color); }
    .quick-status-btn.absent:hover { background: #ffebee; border-color: var(--danger-color); }
    .quick-status-btn.late:hover { background: #fff3e0; border-color: var(--warning-color); }
    .quick-status-btn.excused:hover { background: #e3f2fd; border-color: var(--primary-color); }
    
    .btn-save-attendance {
        background: linear-gradient(135deg, var(--success-color), #558b2f);
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-save-attendance:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(124, 179, 66, 0.4);
        color: var(--white);
    }
    .select-all-btn {
        cursor: pointer;
        padding: 4px 12px;
        border-radius: 6px;
        background: var(--gray-100);
        font-size: 0.8rem;
        transition: var(--transition);
    }
    .select-all-btn:hover { background: var(--primary-soft); }
</style>

<div class="attendance-toolbar">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Select Course</label>
            <select name="course" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select Course --</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['course_id']; ?>" <?php echo $course_id == $c['course_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" name="date" class="form-control" value="<?php echo $date; ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">&nbsp;</label>
            <button type="submit" class="btn btn-primary-custom w-100">
                <i class="fas fa-sync me-2"></i> Refresh
            </button>
        </div>
        <?php if ($course_id > 0 && !empty($students)): ?>
        <div class="col-md-2">
            <label class="form-label fw-semibold">&nbsp;</label>
            <button type="button" class="btn btn-success-custom w-100" onclick="markAllPresent()">
                <i class="fas fa-check-circle me-2"></i> All Present
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($course_id > 0 && $course): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>
            <i class="fas fa-book text-primary me-2"></i>
            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
            <span class="badge bg-secondary ms-2"><?php echo count($students); ?> Students</span>
        </h5>
        <span class="text-muted" style="font-size: 0.85rem;">
            <i class="fas fa-calendar-alt me-1"></i>
            <?php echo date('l, F d, Y', strtotime($date)); ?>
        </span>
    </div>
    
    <?php if (!empty($students)): ?>
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card present">
                <div class="number"><?php echo $summary['present']; ?></div>
                <div class="label">Present</div>
            </div>
            <div class="summary-card absent">
                <div class="number"><?php echo $summary['absent']; ?></div>
                <div class="label">Absent</div>
            </div>
            <div class="summary-card late">
                <div class="number"><?php echo $summary['late']; ?></div>
                <div class="label">Late</div>
            </div>
            <div class="summary-card excused">
                <div class="number"><?php echo $summary['excused']; ?></div>
                <div class="label">Excused</div>
            </div>
        </div>
        
        <form method="POST" action="">
            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
            <input type="hidden" name="date" value="<?php echo $date; ?>">
            <input type="hidden" name="save_attendance" value="1">
            
            <div class="attendance-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student</th>
                            <th>Matric No.</th>
                            <th style="width: 180px;">Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 0;
                        foreach ($students as $student): 
                            $counter++;
                            $record = $attendance_by_student[$student['student_id']] ?? null;
                            $status = $record['status'] ?? '';
                            $remark = $record['remarks'] ?? '';
                            $name = $student['first_name'] . ' ' . $student['last_name'];
                            $initial = strtoupper(substr($student['first_name'], 0, 1));
                        ?>
                        <tr>
                            <td><?php echo $counter; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="student-avatar-small">
                                        <?php if (!empty($student['profile_image']) && file_exists('../' . $student['profile_image'])): ?>
                                            <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Student">
                                        <?php else: ?>
                                            <?php echo $initial; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($name); ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <button type="button" class="quick-status-btn present <?php echo $status == 'Present' ? 'bg-success text-white border-success' : ''; ?>" 
                                            onclick="setStatus(this, 'Present')">Present</button>
                                    <button type="button" class="quick-status-btn absent <?php echo $status == 'Absent' ? 'bg-danger text-white border-danger' : ''; ?>" 
                                            onclick="setStatus(this, 'Absent')">Absent</button>
                                    <button type="button" class="quick-status-btn late <?php echo $status == 'Late' ? 'bg-warning text-white border-warning' : ''; ?>" 
                                            onclick="setStatus(this, 'Late')">Late</button>
                                    <button type="button" class="quick-status-btn excused <?php echo $status == 'Excused' ? 'bg-primary text-white border-primary' : ''; ?>" 
                                            onclick="setStatus(this, 'Excused')">Excused</button>
                                    <input type="hidden" name="attendance[<?php echo $student['student_id']; ?>]" value="<?php echo $status; ?>" 
                                           id="status_<?php echo $student['student_id']; ?>">
                                </div>
                            </td>
                            <td>
                                <input type="text" name="remarks[<?php echo $student['student_id']; ?>]" 
                                       class="form-control form-control-sm" style="min-width: 100px;"
                                       value="<?php echo htmlspecialchars($remark); ?>" 
                                       placeholder="Add remark...">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mt-4">
                <div class="text-muted" style="font-size: 0.85rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Click status buttons to mark attendance. All changes are saved when you click "Save Attendance".
                </div>
                <button type="submit" class="btn-save-attendance">
                    <i class="fas fa-save me-2"></i> Save Attendance
                </button>
            </div>
        </form>
        
        <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-state text-center py-5">
            <div class="empty-icon" style="width: 80px; height: 80px; background: var(--primary-soft); border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                <i class="fas fa-users" style="font-size: 2rem; color: var(--primary-color);"></i>
            </div>
            <h5>No Students Enrolled</h5>
            <p class="text-muted">There are no students enrolled in this course yet.</p>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-state text-center py-5">
        <div class="empty-icon" style="width: 80px; height: 80px; background: var(--primary-soft); border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <i class="fas fa-clipboard-check" style="font-size: 2rem; color: var(--primary-color);"></i>
        </div>
        <h5>Select a Course</h5>
        <p class="text-muted">Please select a course from the dropdown above to manage attendance.</p>
    </div>
<?php endif; ?>

<script>
    function setStatus(btn, status) {
        const row = btn.closest('tr');
        const hiddenInput = row.querySelector('input[name^="attendance"]');
        
        // Update hidden input
        hiddenInput.value = status;
        
        // Update button styles
        const buttons = row.querySelectorAll('.quick-status-btn');
        buttons.forEach(b => {
            b.classList.remove('bg-success', 'text-white', 'border-success');
            b.classList.remove('bg-danger', 'text-white', 'border-danger');
            b.classList.remove('bg-warning', 'text-white', 'border-warning');
            b.classList.remove('bg-primary', 'text-white', 'border-primary');
        });
        
        btn.classList.add('bg-' + getColorClass(status));
        btn.classList.add('text-white');
        btn.classList.add('border-' + getColorClass(status));
    }
    
    function getColorClass(status) {
        const map = {
            'Present': 'success',
            'Absent': 'danger',
            'Late': 'warning',
            'Excused': 'primary'
        };
        return map[status] || 'secondary';
    }
    
    function markAllPresent() {
        const rows = document.querySelectorAll('.attendance-table tbody tr');
        rows.forEach(row => {
            const presentBtn = row.querySelector('.quick-status-btn.present');
            if (presentBtn) {
                setStatus(presentBtn, 'Present');
            }
        });
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>