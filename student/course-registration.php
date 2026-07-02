<?php
ob_start(); // Start output buffering to prevent header issues
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];
$error = '';
$success = '';

// Get student info with program details
$student_query = "SELECT s.*, d.department_id, d.department_name, p.program_name, p.program_id 
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_data = $stmt->get_result()->fetch_assoc();

$current_level = $student_data['current_level'] ?: 100;
$student_dept_id = $student_data['department_id'];
$student_program_id = $student_data['program_id'];

// Determine current session - prioritize student's assigned session
$student_session = $student_data['current_session'] ?: '2025/2026';

$session_query = "SELECT session_year, semester, registration_start, registration_end, status
                  FROM academic_sessions 
                  WHERE session_year = ? AND is_current = 1 AND status = 'Active'
                  LIMIT 1";
$stmt = $conn->prepare($session_query);
$stmt->bind_param("s", $student_session);
$stmt->execute();
$session_result = $stmt->get_result();

if ($session_result && $session_result->num_rows > 0) {
    $session_data = $session_result->fetch_assoc();
    $current_session = $session_data['session_year'];
    $current_semester = $session_data['semester'];
} else {
    // Fallback: get any active current session
    $session_query = "SELECT session_year, semester, registration_start, registration_end, status
                      FROM academic_sessions 
                      WHERE is_current = 1 AND status = 'Active'
                      ORDER BY session_year DESC, semester DESC
                      LIMIT 1";
    $session_result = $conn->query($session_query);
    if ($session_result && $session_result->num_rows > 0) {
        $session_data = $session_result->fetch_assoc();
        $current_session = $session_data['session_year'];
        $current_semester = $session_data['semester'];
    } else {
        // Ultimate fallback
        $current_session = $student_session;
        $current_semester = 1;
        $session_data = ['registration_start' => null, 'registration_end' => null];
    }
}

// Check registration period
$registration_closed = false;
$registration_message = '';
if (!empty($session_data['registration_start']) && !empty($session_data['registration_end'])) {
    $today = date('Y-m-d');
    if ($today < $session_data['registration_start']) {
        $registration_closed = true;
        $registration_message = "Registration opens on " . date('F j, Y', strtotime($session_data['registration_start']));
    } elseif ($today > $session_data['registration_end']) {
        $registration_closed = true;
        $registration_message = "Registration closed on " . date('F j, Y', strtotime($session_data['registration_end']));
    }
}

// Check if fees are paid for the CURRENT session (ANY semester)
$fee_query = "SELECT * FROM student_fees 
              WHERE student_id = ? 
              AND session_year = ? 
              AND status IN ('Paid', 'Partial')
              ORDER BY amount_paid DESC LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$fee_result = $stmt->get_result();
$fees_paid = $fee_result->num_rows > 0;

// Get fee details for display
$fee_data = $fee_result->fetch_assoc();
$fee_balance = $fee_data ? $fee_data['balance'] : 0;

// Get carry-over (failed) courses from previous sessions - exclude already passed/registered
$carryover_query = "SELECT r.*, c.course_code, c.course_title, c.credit_units, c.level as course_level, c.semester as course_semester
                    FROM results r
                    JOIN courses c ON r.course_id = c.course_id
                    WHERE r.student_id = ? 
                    AND r.grade = 'F' 
                    AND r.is_published = 1
                    AND c.course_id NOT IN (
                        SELECT course_id FROM course_registrations 
                        WHERE student_id = ? 
                        AND registration_status = 'Approved'
                        AND grade IS NOT NULL 
                        AND grade != 'F'
                    )";
$stmt = $conn->prepare($carryover_query);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$carryover_courses = $stmt->get_result();

// Get already registered courses for current session and semester
$registered_query = "SELECT cr.*, c.course_code, c.course_title, c.credit_units, c.is_core
                     FROM course_registrations cr
                     JOIN courses c ON cr.course_id = c.course_id
                     WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?";
$stmt = $conn->prepare($registered_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$registered_result = $stmt->get_result();
$registered_courses = [];
$registered_ids = [];
$total_registered_units = 0;
while($reg = $registered_result->fetch_assoc()) {
    $registered_courses[] = $reg;
    $registered_ids[] = $reg['course_id'];
    $total_registered_units += $reg['credit_units'];
}

// Handle registration submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_courses'])) {
    if($registration_closed) {
        $error = "Registration is currently closed. " . $registration_message;
    } elseif(!$fees_paid) {
        $error = "You must pay your school fees for {$current_session} before registering for courses.";
    } else {
        $selected_courses = $_POST['courses'] ?? [];
        $carryover_selected = $_POST['carryover'] ?? [];
        $all_courses = array_merge($selected_courses, $carryover_selected);

        // Calculate total units
        $total_units = 0;
        foreach($all_courses as $course_id) {
            $unit_query = "SELECT credit_units FROM courses WHERE course_id = ?";
            $stmt = $conn->prepare($unit_query);
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $course = $stmt->get_result()->fetch_assoc();
            if($course !== null) {
                $total_units += $course['credit_units'];
            }
        }

        if($total_units < 15) {
            $error = "Minimum credit units required is 15. You selected $total_units units.";
        } elseif($total_units > 24) {
            $error = "Maximum credit units allowed is 24. You selected $total_units units.";
        } else {
            $conn->begin_transaction();
            try {
                // Delete existing registrations for current session and semester
                $delete = "DELETE FROM course_registrations WHERE student_id = ? AND session_year = ? AND semester = ?";
                $stmt = $conn->prepare($delete);
                $stmt->bind_param("isi", $student_id, $current_session, $current_semester);
                $stmt->execute();

                // Insert all courses (auto-approved since fees are paid)
                $insert = "INSERT INTO course_registrations 
                          (student_id, course_id, session_year, semester, level, registration_date, registration_status, approved_by, approval_date) 
                          VALUES (?, ?, ?, ?, ?, NOW(), 'Approved', 1, NOW())";
                $stmt = $conn->prepare($insert);

                foreach($all_courses as $course_id) {
                    $stmt->bind_param("iisii", $student_id, $course_id, $current_session, $current_semester, $current_level);
                    $stmt->execute();
                }

                $conn->commit();
                $success = "Course registration completed successfully!";

                // Use JavaScript redirect since headers already sent by header.php
                echo '<script>window.location.href = "course-registration.php?success=1";</script>';
                exit;

            } catch(Exception $e) {
                $conn->rollback();
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

// Get available courses for current level, department, program and semester
$available_query = "SELECT c.* FROM courses c
                    LEFT JOIN course_programs cp ON c.course_id = cp.course_id
                    WHERE c.department_id = ? 
                    AND (cp.program_id = ? OR cp.program_id IS NULL)
                    AND c.level = ? 
                    AND c.semester = ? 
                    ORDER BY c.is_core DESC, c.course_code";
$stmt = $conn->prepare($available_query);
$stmt->bind_param("iiii", $student_dept_id, $student_program_id, $current_level, $current_semester);
$stmt->execute();
$available_courses = $stmt->get_result();

// Calculate total units from available courses
$available_units = 0;
$available_count = 0;
$available_course_ids = [];
if($available_courses && $available_courses->num_rows > 0) {
    $available_courses->data_seek(0);
    while($c = $available_courses->fetch_assoc()) {
        $available_units += $c['credit_units'];
        $available_count++;
        $available_course_ids[] = $c['course_id'];
    }
    $available_courses->data_seek(0);
}

// Check if any available courses are already registered (for display purposes)
$registered_available_ids = array_intersect($registered_ids, $available_course_ids);
?>

<div class="course-reg-page">

    <!-- Header -->
    <div class="reg-header">
        <h1>Course Registration</h1>
        <div class="session-info">
            <span class="badge session"><?php echo htmlspecialchars($current_session); ?></span>
            <span class="badge level">Level <?php echo $current_level; ?></span>
            <span class="badge semester"><?php echo $current_semester == 1 ? 'First' : 'Second'; ?> Semester</span>
            <?php if($registration_closed): ?>
            <span class="badge closed">CLOSED</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Info -->
    <div class="student-bar">
        <span><strong><?php echo strtoupper(htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name'])); ?></strong></span>
        <span><?php echo htmlspecialchars($student_data['matric_number']); ?></span>
        <span><?php echo htmlspecialchars($student_data['department_name'] ?? 'N/A'); ?></span>
        <span><?php echo htmlspecialchars($student_data['program_name'] ?? 'N/A'); ?></span>
    </div>

    <!-- Registration Period Status -->
    <?php if($registration_closed): ?>
    <div class="fee-status closed">
        <i class="fas fa-calendar-times"></i>
        <span><?php echo $registration_message; ?></span>
    </div>
    <?php endif; ?>

    <!-- Fee Status -->
    <div class="fee-status <?php echo $fees_paid ? 'paid' : 'unpaid'; ?>">
        <i class="fas <?php echo $fees_paid ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
        <span>
            <?php 
            if($fees_paid) {
                echo 'School Fees Paid for ' . htmlspecialchars($current_session) . ($fee_balance > 0 ? ' (Balance: ₦' . number_format($fee_balance, 2) . ')' : '');
            } else {
                echo 'School Fees NOT Paid for ' . htmlspecialchars($current_session) . ' - Registration Disabled';
            }
            ?>
        </span>
        <?php if(!$fees_paid): ?>
        <a href="fees.php" class="pay-link">Pay Now →</a>
        <?php endif; ?>
    </div>

    <?php if($error): ?>
    <div class="alert error"><?php echo $error; ?></div>
    <?php endif; ?>

    <?php if($success || isset($_GET['success'])): ?>
    <div class="alert success">Registration completed successfully!</div>
    <?php endif; ?>

    <?php if($fees_paid && !$registration_closed): ?>

    <form method="POST" action="" id="regForm">

        <!-- Current Level Courses (Auto-selected, cannot unselect) -->
        <div class="section">
            <h2>Level <?php echo $current_level; ?> Courses <span class="count">(<?php echo $available_count; ?> courses, <?php echo $available_units; ?> units)</span></h2>
            <p class="hint">These courses are mandatory and automatically selected for you.</p>

            <div class="course-list">
                <?php if($available_count === 0): ?>
                <div class="course-row empty">
                    <div class="course-info">
                        <span class="title">No courses available for your level and semester.</span>
                    </div>
                </div>
                <?php else: ?>
                <?php while($course = $available_courses->fetch_assoc()): 
                    $is_registered = in_array($course['course_id'], $registered_ids);
                ?>
                <div class="course-row">
                    <div class="course-check">
                        <input type="hidden" name="courses[]" value="<?php echo $course['course_id']; ?>">
                        <input type="checkbox" 
                               checked disabled 
                               data-units="<?php echo $course['credit_units']; ?>">
                        <i class="fas fa-lock lock-icon"></i>
                    </div>
                    <div class="course-info">
                        <span class="code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                        <span class="title"><?php echo htmlspecialchars($course['course_title']); ?></span>
                        <?php if($course['is_core']): ?><span class="tag core">CORE</span><?php endif; ?>
                        <?php if($course['is_elective']): ?><span class="tag elective">ELECTIVE</span><?php endif; ?>
                    </div>
                    <div class="course-meta">
                        <span class="units"><?php echo $course['credit_units']; ?> Units</span>
                        <?php if($is_registered): ?><span class="tag registered">REGISTERED</span><?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Carry Over Courses -->
        <?php if($carryover_courses && $carryover_courses->num_rows > 0): ?>
        <div class="section">
            <h2>Carry Over Courses <span class="count">(<?php echo $carryover_courses->num_rows; ?> failed courses to retake)</span></h2>
            <p class="hint">Select failed courses from previous sessions that you need to retake.</p>

            <div class="course-list">
                <?php 
                $carryover_courses->data_seek(0);
                while($course = $carryover_courses->fetch_assoc()): 
                    $is_registered = in_array($course['course_id'], $registered_ids);
                ?>
                <div class="course-row carryover">
                    <div class="course-check">
                        <input type="checkbox" name="carryover[]" value="<?php echo $course['course_id']; ?>" 
                               <?php echo $is_registered ? 'checked' : ''; ?>
                               data-units="<?php echo $course['credit_units']; ?>">
                    </div>
                    <div class="course-info">
                        <span class="code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                        <span class="title"><?php echo htmlspecialchars($course['course_title']); ?></span>
                        <span class="tag fail">FAILED - <?php echo htmlspecialchars($course['session_year']); ?></span>
                    </div>
                    <div class="course-meta">
                        <span class="units"><?php echo $course['credit_units']; ?> Units</span>
                        <span class="level-tag">Level <?php echo $course['course_level']; ?></span>
                        <?php if($is_registered): ?><span class="tag registered">REGISTERED</span><?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Bar -->
        <div class="summary-bar">
            <div class="summary-item">
                <span class="label">Total Units:</span>
                <span class="value" id="totalUnits"><?php echo $total_registered_units + $available_units; ?></span>
            </div>
            <div class="summary-item">
                <span class="label">Min:</span>
                <span class="value">15</span>
            </div>
            <div class="summary-item">
                <span class="label">Max:</span>
                <span class="value">24</span>
            </div>
            <div class="summary-item">
                <span class="label">Courses:</span>
                <span class="value" id="totalCourses"><?php echo $available_count + count($registered_available_ids); ?></span>
            </div>
        </div>

        <!-- Submit -->
        <div class="submit-bar">
            <button type="submit" name="register_courses" class="btn-submit" onclick="return validateForm()">
                <i class="fas fa-check"></i> Submit Registration
            </button>
        </div>

    </form>

    <?php else: ?>
    <!-- Not Paid or Closed - Show Locked Message -->
    <div class="locked-message">
        <i class="fas fa-lock"></i>
        <h3>Registration Locked</h3>
        <p>
            <?php if($registration_closed): ?>
            <?php echo $registration_message; ?>
            <?php else: ?>
            You need to pay your school fees for <strong><?php echo htmlspecialchars($current_session); ?></strong> before you can register for courses.
            <?php endif; ?>
        </p>
        <?php if(!$fees_paid && !$registration_closed): ?>
        <a href="fees.php" class="btn-pay">Pay School Fees</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<style>
.course-reg-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
}

/* Header */
.reg-header {
    margin-bottom: 20px;
}
.reg-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 10px;
}
.session-info {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.badge.session { background: #1a4db5; color: #fff; }
.badge.level { background: #f59e0b; color: #fff; }
.badge.semester { background: #10b981; color: #fff; }
.badge.closed { background: #ef4444; color: #fff; }

/* Student Bar */
.student-bar {
    background: #f1f5f9;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #475569;
}
.student-bar strong { color: #1e293b; }

/* Fee Status */
.fee-status {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
}
.fee-status.paid { background: #dcfce7; color: #166534; }
.fee-status.unpaid { background: #fee2e2; color: #991b1b; }
.fee-status.closed { background: #fef3c7; color: #92400e; }
.fee-status i { font-size: 18px; }
.pay-link {
    margin-left: auto;
    color: #1a4db5;
    text-decoration: none;
    font-weight: 700;
}
.pay-link:hover { text-decoration: underline; }

/* Alerts */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 14px;
    font-weight: 500;
}
.alert.error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
.alert.success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }

/* Sections */
.section {
    margin-bottom: 24px;
}
.section h2 {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section .count {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
}
.hint {
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 12px;
}

/* Course List */
.course-list {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}
.course-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    background: #fff;
    transition: background 0.2s;
}
.course-row:last-child { border-bottom: none; }
.course-row:hover { background: #f8fafc; }
.course-row.carryover { background: #fffbeb; }
.course-row.carryover:hover { background: #fef3c7; }
.course-row.empty { 
    background: #f8fafc; 
    justify-content: center; 
    padding: 24px;
}

.course-check {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 40px;
}
.course-check input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #1a4db5;
    cursor: pointer;
}
.course-check input[type="checkbox"]:disabled {
    cursor: default;
    opacity: 0.7;
}
.lock-icon {
    color: #94a3b8;
    font-size: 12px;
}

.course-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.code {
    font-weight: 700;
    color: #1e293b;
    font-size: 14px;
}
.title {
    font-size: 12px;
    color: #64748b;
}

.tag {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    width: fit-content;
}
.tag.core { background: #dbeafe; color: #1d4ed8; }
.tag.elective { background: #f3e8ff; color: #7c3aed; }
.tag.fail { background: #fee2e2; color: #991b1b; }
.tag.registered { background: #dcfce7; color: #166534; }

.course-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.units {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    background: #f1f5f9;
    padding: 4px 10px;
    border-radius: 4px;
}
.level-tag {
    font-size: 11px;
    color: #64748b;
}

/* Summary Bar */
.summary-bar {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    gap: 24px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.summary-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}
.summary-item .label { color: #64748b; font-weight: 500; }
.summary-item .value { color: #1e293b; font-weight: 700; }

/* Submit */
.submit-bar {
    text-align: right;
}
.btn-submit {
    background: #1a4db5;
    color: #fff;
    border: none;
    padding: 12px 28px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}
.btn-submit:hover { background: #153d94; }
.btn-submit:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

/* Locked Message */
.locked-message {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}
.locked-message i {
    font-size: 48px;
    color: #cbd5e1;
    margin-bottom: 16px;
}
.locked-message h3 {
    font-size: 18px;
    color: #1e293b;
    margin-bottom: 8px;
}
.locked-message p {
    font-size: 14px;
    margin-bottom: 20px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}
.btn-pay {
    display: inline-block;
    background: #1a4db5;
    color: #fff;
    padding: 12px 28px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
}
.btn-pay:hover { background: #153d94; }

@media (max-width: 600px) {
    .student-bar { flex-direction: column; gap: 4px; }
    .course-row { flex-wrap: wrap; }
    .course-meta { width: 100%; justify-content: flex-start; margin-top: 4px; }
    .summary-bar { flex-wrap: wrap; gap: 12px; }
    .submit-bar { text-align: center; }
    .btn-submit { width: 100%; justify-content: center; }
}
</style>

<script>
function validateForm() {
    // Get all hidden course inputs (auto-selected) and carryover checkboxes
    const courseInputs = document.querySelectorAll('input[type="hidden"][name="courses[]"]');
    const carryoverBoxes = document.querySelectorAll('input[name="carryover[]"]:checked');
    let totalUnits = 0;
    let count = 0;

    // Add units from auto-selected courses (hidden inputs)
    courseInputs.forEach(input => {
        const row = input.closest('.course-row');
        const checkbox = row.querySelector('input[data-units]');
        if(checkbox) {
            totalUnits += parseInt(checkbox.dataset.units);
            count++;
        }
    });

    // Add units from selected carryover courses
    carryoverBoxes.forEach(cb => {
        totalUnits += parseInt(cb.dataset.units);
        count++;
    });

    if(totalUnits < 15) {
        alert('Minimum 15 units required. Current: ' + totalUnits + ' units.');
        return false;
    }
    if(totalUnits > 24) {
        alert('Maximum 24 units allowed. Current: ' + totalUnits + ' units.');
        return false;
    }

    return confirm('Submit registration with ' + count + ' courses (' + totalUnits + ' units)?');
}

// Update total units display when carryover checkboxes change
document.querySelectorAll('input[name="carryover[]"]').forEach(cb => {
    cb.addEventListener('change', function() {
        // Get units from auto-selected courses (via their visible checkbox data-units)
        const courseRows = document.querySelectorAll('.course-list:first-of-type .course-row:not(.empty)');
        let total = 0;
        let courseCount = 0;

        courseRows.forEach(row => {
            const checkbox = row.querySelector('input[data-units]');
            if(checkbox) {
                total += parseInt(checkbox.dataset.units);
                courseCount++;
            }
        });

        // Add selected carryover courses
        document.querySelectorAll('input[name="carryover[]"]:checked').forEach(box => {
            total += parseInt(box.dataset.units);
            courseCount++;
        });

        document.getElementById('totalUnits').textContent = total;
        document.getElementById('totalCourses').textContent = courseCount;
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>