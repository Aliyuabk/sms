<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];
$course_id = isset($_GET['course']) ? intval($_GET['course']) : 0;
$attendance_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if (!$course_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch course details
$stmt = $pdo->prepare("
    SELECT c.*, ca.session_year, ca.semester, ca.assignment_id
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE c.course_id = ? AND ca.staff_id = ? AND ca.status = 'Active'
    LIMIT 1
");
$stmt->execute([$course_id, $staff_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header('Location: dashboard.php');
    exit;
}

// Handle form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    try {
        $pdo->beginTransaction();

        $class_date = $_POST['class_date'];
        $hours = $_POST['hours_attended'] ?? 2.00;

        foreach ($_POST['attendance'] as $student_id => $status) {
            // Check if record exists
            $check = $pdo->prepare("
                SELECT attendance_id FROM attendance 
                WHERE student_id = ? AND course_id = ? AND class_date = ?
            ");
            $check->execute([$student_id, $course_id, $class_date]);

            if ($check->fetch()) {
                // Update
                $upd = $pdo->prepare("
                    UPDATE attendance 
                    SET status = ?, hours_attended = ?, recorded_by = ?, remarks = ?
                    WHERE student_id = ? AND course_id = ? AND class_date = ?
                ");
                $upd->execute([
                    $status, $hours, $staff_id, 
                    $_POST['remarks'][$student_id] ?? '',
                    $student_id, $course_id, $class_date
                ]);
            } else {
                // Insert
                $ins = $pdo->prepare("
                    INSERT INTO attendance 
                    (student_id, course_id, session_year, semester, class_date, status, hours_attended, recorded_by, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $student_id, $course_id, $course['session_year'], $course['semester'],
                    $class_date, $status, $hours, $staff_id,
                    $_POST['remarks'][$student_id] ?? ''
                ]);
            }
        }

        $pdo->commit();
        $success_msg = 'Attendance saved successfully!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = 'Error saving attendance: ' . $e->getMessage();
    }
}

// Fetch students with their attendance for selected date
$stmt2 = $pdo->prepare("
    SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.middle_name,
           s.gender, s.profile_image,
           a.status as attendance_status, a.hours_attended, a.remarks, a.attendance_id
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    LEFT JOIN attendance a ON s.student_id = a.student_id 
        AND a.course_id = ? AND a.class_date = ?
    WHERE cr.course_id = ? 
        AND cr.session_year = ? 
        AND cr.semester = ? 
        AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$stmt2->execute([$course_id, $attendance_date, $course_id, $course['session_year'], $course['semester']]);
$students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get staff data
$stmt3 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt3->execute([$staff_id]);
$staff = $stmt3->fetch(PDO::FETCH_ASSOC);

// Attendance stats for today
$present_count = count(array_filter($students, fn($s) => ($s['attendance_status'] ?? '') === 'Present'));
$absent_count = count(array_filter($students, fn($s) => ($s['attendance_status'] ?? '') === 'Absent'));
$late_count = count(array_filter($students, fn($s) => ($s['attendance_status'] ?? '') === 'Late'));
$total = count($students);

// Page variables
$page_title = 'Take Attendance';
$page_icon = 'fas fa-clipboard-check';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses', 'url' => 'courses.php'],
    ['title' => $course['course_code'], 'url' => 'view_class.php?course=' . $course_id],
    ['title' => 'Attendance']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .attendance-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 30px;
        color: var(--white);
        margin-bottom: 25px;
        animation: fadeInUp 0.6s ease;
    }
    .attendance-hero h2 {
        font-size: 1.5rem;
        font-weight: 800;
        margin-bottom: 10px;
    }
    .attendance-hero-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        opacity: 0.9;
        font-size: 0.9rem;
    }
    .attendance-hero-meta i { margin-right: 6px; }

    .attendance-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin-bottom: 25px;
    }
    .att-stat {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        animation: fadeInUp 0.5s ease backwards;
    }
    .att-stat:nth-child(1) { animation-delay: 0.1s; }
    .att-stat:nth-child(2) { animation-delay: 0.2s; }
    .att-stat:nth-child(3) { animation-delay: 0.3s; }
    .att-stat:nth-child(4) { animation-delay: 0.4s; }
    .att-stat:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
    .att-stat-value {
        font-size: 2rem;
        font-weight: 800;
        margin-bottom: 5px;
    }
    .att-stat-label {
        font-size: 0.85rem;
        color: var(--text-light);
        font-weight: 500;
    }
    .att-stat-total .att-stat-value { color: var(--primary-color); }
    .att-stat-present .att-stat-value { color: var(--success-color); }
    .att-stat-absent .att-stat-value { color: var(--danger-color); }
    .att-stat-late .att-stat-value { color: var(--warning-color); }

    .attendance-form-wrap {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.6s ease;
    }
    .attendance-form-header {
        padding: 25px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .date-picker-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .date-picker-wrap label {
        font-weight: 600;
        color: var(--text-dark);
    }
    .date-picker-wrap input {
        padding: 10px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 0.9rem;
    }
    .bulk-actions {
        display: flex;
        gap: 8px;
    }
    .btn-bulk {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }
    .btn-bulk:hover { background: var(--gray-100); }
    .btn-bulk-all { color: var(--success-color); border-color: #c8e6c9; }
    .btn-bulk-clear { color: var(--danger-color); border-color: #ffcdd2; }

    .attendance-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .attendance-table thead th {
        background: var(--gray-100);
        padding: 16px 20px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-light);
        border-bottom: 2px solid var(--gray-200);
    }
    .attendance-table tbody td {
        padding: 14px 20px;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
    }
    .attendance-table tbody tr:hover { background: var(--primary-soft); }
    .student-att-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .att-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
    }
    .att-student-name { font-weight: 600; color: var(--text-dark); }
    .att-student-matric { font-size: 0.8rem; color: var(--text-light); }

    .status-options {
        display: flex;
        gap: 8px;
    }
    .status-option {
        position: relative;
    }
    .status-option input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    .status-option label {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 10px;
        border: 2px solid var(--gray-300);
        background: var(--white);
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: var(--transition);
        user-select: none;
    }
    .status-option label:hover { border-color: var(--primary-light); }
    .status-option input:checked + label.status-present {
        background: #e8f5e9;
        border-color: var(--success-color);
        color: var(--success-color);
    }
    .status-option input:checked + label.status-absent {
        background: #ffebee;
        border-color: var(--danger-color);
        color: var(--danger-color);
    }
    .status-option input:checked + label.status-late {
        background: #fff3e0;
        border-color: var(--warning-color);
        color: var(--warning-color);
    }
    .status-option input:checked + label.status-excused {
        background: #e3f2fd;
        border-color: var(--primary-light);
        color: var(--primary-light);
    }

    .remarks-input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 0.85rem;
        transition: var(--transition);
    }
    .remarks-input:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }

    .attendance-form-footer {
        padding: 25px;
        background: var(--gray-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .btn-save {
        padding: 14px 35px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(63, 116, 156, 0.3);
    }

    .alert-custom {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: fadeInUp 0.4s ease;
    }
    .alert-success-custom {
        background: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid var(--success-color);
    }
    .alert-error-custom {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid var(--danger-color);
    }

    @media (max-width: 768px) {
        .attendance-stats { grid-template-columns: repeat(2, 1fr); }
        .attendance-form-header { flex-direction: column; align-items: stretch; }
        .status-options { flex-wrap: wrap; }
        .attendance-table-wrap { overflow-x: auto; }
    }
</style>

<!-- Alerts -->
<?php if ($success_msg): ?>
<div class="alert-custom alert-success-custom">
    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert-custom alert-error-custom">
    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
</div>
<?php endif; ?>

<!-- Attendance Hero -->
<div class="attendance-hero">
    <h2><i class="fas fa-clipboard-check me-2"></i>Take Attendance</h2>
    <p class="mb-3"><?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?></p>
    <div class="attendance-hero-meta">
        <span><i class="fas fa-calendar"></i> <?php echo $course['session_year']; ?></span>
        <span><i class="fas fa-clock"></i> Semester <?php echo $course['semester']; ?></span>
        <span><i class="fas fa-users"></i> <?php echo $total; ?> Students</span>
        <span><i class="fas fa-layer-group"></i> Level <?php echo $course['level']; ?></span>
    </div>
</div>

<!-- Stats -->
<div class="attendance-stats">
    <div class="att-stat att-stat-total">
        <div class="att-stat-value"><?php echo $total; ?></div>
        <div class="att-stat-label">Total Students</div>
    </div>
    <div class="att-stat att-stat-present">
        <div class="att-stat-value" id="countPresent"><?php echo $present_count; ?></div>
        <div class="att-stat-label">Present</div>
    </div>
    <div class="att-stat att-stat-absent">
        <div class="att-stat-value" id="countAbsent"><?php echo $absent_count; ?></div>
        <div class="att-stat-label">Absent</div>
    </div>
    <div class="att-stat att-stat-late">
        <div class="att-stat-value" id="countLate"><?php echo $late_count; ?></div>
        <div class="att-stat-label">Late</div>
    </div>
</div>

<!-- Attendance Form -->
<form method="POST" action="">
    <div class="attendance-form-wrap">
        <div class="attendance-form-header">
            <div class="date-picker-wrap">
                <label><i class="fas fa-calendar-day me-1"></i>Class Date:</label>
                <input type="date" name="class_date" value="<?php echo $attendance_date; ?>" 
                       max="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="date-picker-wrap">
                <label><i class="fas fa-clock me-1"></i>Hours:</label>
                <input type="number" name="hours_attended" value="2.00" step="0.5" min="0.5" max="6" style="width: 80px;">
            </div>
            <div class="bulk-actions">
                <button type="button" class="btn-bulk btn-bulk-all" onclick="markAll('Present')">
                    <i class="fas fa-check"></i> All Present
                </button>
                <button type="button" class="btn-bulk btn-bulk-clear" onclick="markAll('Absent')">
                    <i class="fas fa-times"></i> All Absent
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Student</th>
                        <th>Matric No</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $index => $student): 
                        $current_status = $student['attendance_status'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <div class="student-att-cell">
                                <div class="att-avatar">
                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="att-student-name">
                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                    </div>
                                    <div class="att-student-matric"><?php echo htmlspecialchars($student['matric_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><strong><?php echo htmlspecialchars($student['matric_number']); ?></strong></td>
                        <td>
                            <div class="status-options">
                                <div class="status-option">
                                    <input type="radio" name="attendance[<?php echo $student['student_id']; ?>]" 
                                           id="present_<?php echo $student['student_id']; ?>" value="Present"
                                           <?php echo $current_status === 'Present' ? 'checked' : ''; ?>>
                                    <label for="present_<?php echo $student['student_id']; ?>" class="status-present">
                                        <i class="fas fa-check"></i> Present
                                    </label>
                                </div>
                                <div class="status-option">
                                    <input type="radio" name="attendance[<?php echo $student['student_id']; ?>]" 
                                           id="absent_<?php echo $student['student_id']; ?>" value="Absent"
                                           <?php echo $current_status === 'Absent' ? 'checked' : ''; ?>>
                                    <label for="absent_<?php echo $student['student_id']; ?>" class="status-absent">
                                        <i class="fas fa-times"></i> Absent
                                    </label>
                                </div>
                                <div class="status-option">
                                    <input type="radio" name="attendance[<?php echo $student['student_id']; ?>]" 
                                           id="late_<?php echo $student['student_id']; ?>" value="Late"
                                           <?php echo $current_status === 'Late' ? 'checked' : ''; ?>>
                                    <label for="late_<?php echo $student['student_id']; ?>" class="status-late">
                                        <i class="fas fa-clock"></i> Late
                                    </label>
                                </div>
                                <div class="status-option">
                                    <input type="radio" name="attendance[<?php echo $student['student_id']; ?>]" 
                                           id="excused_<?php echo $student['student_id']; ?>" value="Excused"
                                           <?php echo $current_status === 'Excused' ? 'checked' : ''; ?>>
                                    <label for="excused_<?php echo $student['student_id']; ?>" class="status-excused">
                                        <i class="fas fa-file-medical"></i> Excused
                                    </label>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="text" class="remarks-input" name="remarks[<?php echo $student['student_id']; ?>]" 
                                   value="<?php echo htmlspecialchars($student['remarks'] ?? ''); ?>" 
                                   placeholder="Optional remarks...">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="attendance-form-footer">
            <div class="table-info">
                <strong><?php echo $total; ?></strong> students registered for this course
            </div>
            <button type="submit" name="save_attendance" class="btn-save">
                <i class="fas fa-save"></i> Save Attendance
            </button>
        </div>
    </div>
</form>

<script>
    function markAll(status) {
        document.querySelectorAll('.status-options input[type="radio"]').forEach(radio => {
            if (radio.value === status) {
                radio.checked = true;
            }
        });
        updateCounts();
    }

    function updateCounts() {
        const present = document.querySelectorAll('input[value="Present"]:checked').length;
        const absent = document.querySelectorAll('input[value="Absent"]:checked').length;
        const late = document.querySelectorAll('input[value="Late"]:checked').length;

        document.getElementById('countPresent').textContent = present;
        document.getElementById('countAbsent').textContent = absent;
        document.getElementById('countLate').textContent = late;
    }

    document.querySelectorAll('.status-options input').forEach(radio => {
        radio.addEventListener('change', updateCounts);
    });
</script> 