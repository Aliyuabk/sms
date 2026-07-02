<?php
require_once 'includes/header.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// ==================== GET ALL AVAILABLE SESSIONS FOR THIS STUDENT ====================
$sessions_query = "SELECT DISTINCT cr.session_year, cr.semester 
                   FROM course_registrations cr 
                   WHERE cr.student_id = ? 
                   ORDER BY cr.session_year DESC, cr.semester ASC";
$stmt = $conn->prepare($sessions_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$sessions_result = $stmt->get_result();

$available_sessions = [];
while ($row = $sessions_result->fetch_assoc()) {
    $available_sessions[] = $row;
}

// ==================== DETERMINE WHICH SESSION/SEMESTER TO DISPLAY ====================
// Check URL parameters first (for viewing history)
$selected_session = $_GET['session'] ?? null;
$selected_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;

// If no selection, try to use current active session from DB
if (!$selected_session || !$selected_semester) {
    $current_session_query = "SELECT session_id, session_year, semester, session_name, 
                              registration_start, registration_end, exams_start, exams_end,
                              lectures_start, lectures_end
                              FROM academic_sessions 
                              WHERE is_current = 1 AND status = 'Active' 
                              LIMIT 1";
    $session_result = $conn->query($current_session_query);
    $current_session = $session_result->fetch_assoc();

    if ($current_session) {
        $session_year = $current_session['session_year'];
        $semester = (int)$current_session['semester'];
        $session_name = $current_session['session_name'];
        $session_id = $current_session['session_id'];
        $registration_start = $current_session['registration_start'];
        $registration_end = $current_session['registration_end'];
    } else {
        // Ultimate fallback
        $session_year = date('Y') . '/' . (date('Y') + 1);
        $semester = 1;
        $session_name = "$session_year First Semester";
        $session_id = 0;
        $registration_start = null;
        $registration_end = null;
    }
} else {
    // Use selected session from URL
    $session_year = $selected_session;
    $semester = $selected_semester;
    
    // Get session details for the selected one
    $session_detail_query = "SELECT session_id, session_name, registration_start, registration_end 
                             FROM academic_sessions 
                             WHERE session_year = ? AND semester = ? 
                             LIMIT 1";
    $stmt = $conn->prepare($session_detail_query);
    $stmt->bind_param("si", $session_year, $semester);
    $stmt->execute();
    $session_detail = $stmt->get_result()->fetch_assoc();
    
    $session_name = $session_detail['session_name'] ?? "$session_year " . ($semester == 1 ? 'First' : 'Second') . " Semester";
    $session_id = $session_detail['session_id'] ?? 0;
    $registration_start = $session_detail['registration_start'] ?? null;
    $registration_end = $session_detail['registration_end'] ?? null;
}

// ==================== GET STUDENT DETAILS ====================
$student_info_query = "SELECT s.*, d.department_name, p.program_name 
                       FROM students s 
                       LEFT JOIN departments d ON s.department_id = d.department_id 
                       LEFT JOIN programs p ON s.program_id = p.program_id 
                       WHERE s.student_id = ?";
$stmt = $conn->prepare($student_info_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();

// ==================== GET REGISTERED COURSES FOR SELECTED SESSION ====================
$courses_query = "SELECT c.*, cr.registration_status, cr.registration_date, cr.approval_date,
                  cr.attendance_percentage, cr.grade, cr.score
                  FROM courses c 
                  JOIN course_registrations cr ON c.course_id = cr.course_id 
                  WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?
                  ORDER BY c.course_code";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("isi", $student_id, $session_year, $semester);
$stmt->execute();
$courses_result = $stmt->get_result();

$courses_array = [];
$total_units = 0;
while ($course = $courses_result->fetch_assoc()) {
    $courses_array[] = $course;
    $total_units += $course['credit_units'];
}

$total_courses = count($courses_array);

// ==================== CHECK IF REGISTRATION IS OPEN (only for current session) ====================
$registration_open = false;
if ($registration_start && $registration_end) {
    $today = date('Y-m-d');
    $registration_open = ($today >= $registration_start && $today <= $registration_end);
}

$student_name = strtoupper(($student_info['first_name'] ?? '') . ' ' . ($student_info['last_name'] ?? ''));
$initials = strtoupper(substr($student_info['first_name'] ?? 'S', 0, 1) . substr($student_info['last_name'] ?? 'T', 0, 1));
?>

<!-- Page-specific styles -->
<style>
/* ========== COURSES PAGE STYLES ========== */
.courses-page { padding: 0; }

/* Top Bar */
.courses-topbar {
    background: #3f749c;
    border-radius: 12px;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}
.courses-topbar-title {
    display: flex;
    align-items: center;
    gap: 14px;
}
.courses-topbar-title h2 {
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}
.courses-topbar-badges {
    display: flex;
    align-items: center;
    gap: 10px;
}
.badge-try {
    background: #c5ea4f;
    color: #2c3e50;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.badge-plus {
    background: #fff;
    color: #3f749c;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    border: none;
}

/* Page Title */
.courses-page-title {
    font-size: 26px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 24px;
}

/* Content Grid */
.courses-content-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 24px;
    align-items: start;
}

/* Control Panel (Left) */
.courses-control-panel {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.courses-select-group {
    margin-bottom: 16px;
}
.courses-select-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.courses-select {
    width: 100%;
    padding: 12px 16px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    color: #334155;
    background: #fff;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 40px;
    transition: all 0.2s ease;
}
.courses-select:focus {
    outline: none;
    border-color: #3f749c;
    box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
}
.courses-btn {
    width: 100%;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.2s ease;
    border: none;
    margin-bottom: 12px;
    text-decoration: none;
}
.courses-btn:last-child {
    margin-bottom: 0;
}
.courses-btn-primary {
    background: #3f749c;
    color: #fff;
}
.courses-btn-primary:hover {
    background: #2a5a7a;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(63, 116, 156, 0.25);
}
.courses-btn-outline {
    background: transparent;
    color: #3f749c;
    border: 1.5px solid #3f749c;
}
.courses-btn-outline:hover {
    background: #e8f2f8;
}
.courses-btn i {
    font-size: 15px;
}
.courses-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}

/* Session History Note */
.session-history-note {
    background: #e8f2f8;
    border-left: 4px solid #3f749c;
    padding: 12px 16px;
    border-radius: 0 8px 8px 0;
    margin-bottom: 16px;
    font-size: 13px;
    color: #2c3e50;
}
.session-history-note i {
    color: #3f749c;
    margin-right: 6px;
}

/* Courses Panel (Right) */
.courses-list-panel {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
}
.courses-list-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.courses-list-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}
.courses-count-badge {
    font-size: 13px;
    color: #64748b;
    background: #f1f5f9;
    padding: 5px 14px;
    border-radius: 20px;
    font-weight: 500;
}

/* Course Items */
.courses-list-body {
    padding: 8px;
}
.course-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-radius: 12px;
    transition: all 0.2s ease;
    margin-bottom: 4px;
}
.course-row:hover {
    background: #f8fafc;
}
.course-row:last-child {
    margin-bottom: 0;
}
.course-info-left {
    flex: 1;
}
.course-code-text {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 4px;
}
.course-title-text {
    font-size: 13px;
    color: #64748b;
    font-weight: 400;
}
.course-meta {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}
.course-status-badge {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 600;
}
.status-approved {
    background: #dcfce7;
    color: #166534;
}
.status-pending {
    background: #fef3c7;
    color: #92400e;
}
.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}
.course-units-right {
    text-align: right;
    min-width: 70px;
}
.course-units-label {
    font-size: 11px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.course-units-value {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
}

/* Summary Bar */
.courses-summary-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    margin-top: 8px;
}
.courses-summary-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.courses-summary-label {
    font-size: 11px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.courses-summary-value {
    font-size: 20px;
    font-weight: 700;
    color: #2c3e50;
}
.courses-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.courses-status-registered {
    background: #dcfce7;
    color: #166534;
}
.courses-status-pending {
    background: #fef3c7;
    color: #92400e;
}

/* Empty State */
.courses-empty {
    text-align: center;
    padding: 60px 20px;
}
.courses-empty-icon {
    width: 64px;
    height: 64px;
    background: #f1f5f9;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    color: #94a3b8;
    font-size: 24px;
}
.courses-empty h4 {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}
.courses-empty p {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 20px;
}

/* Responsive */
@media (max-width: 1024px) {
    .courses-content-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .courses-topbar {
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }
    .courses-summary-bar {
        flex-direction: column;
        gap: 16px;
        text-align: center;
    }
}
</style>

<div class="courses-page">
    <!-- Top Bar -->
    <div class="courses-topbar">
        <div class="courses-topbar-title">
            <h2><i class="fas fa-graduation-cap"></i> Academic Courses</h2>
        </div>
        <div class="courses-topbar-badges">
            <span class="badge-try"><?php echo htmlspecialchars($session_name); ?></span>
        </div>
    </div>

    <!-- Page Title -->
    <h1 class="courses-page-title">My Courses</h1>

    <!-- Main Content Grid -->
    <div class="courses-content-grid">
        <!-- Left: Controls Panel -->
        <div class="courses-control-panel">
            
            <?php if (count($available_sessions) > 1): ?>
            <div class="session-history-note">
                <i class="fas fa-history"></i>
                You have course records in <?php echo count($available_sessions); ?> session(s). Use the selects below to view previous registrations.
            </div>
            <?php endif; ?>

            <form method="GET" action="" id="sessionForm">
                <div class="courses-select-group">
                    <label>Academic Session</label>
                    <select class="courses-select" name="session" onchange="document.getElementById('sessionForm').submit();">
                        <?php 
                        // Build unique session years
                        $session_years = array_unique(array_column($available_sessions, 'session_year'));
                        foreach ($session_years as $year): 
                            $selected = ($year === $session_year) ? 'selected' : '';
                        ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($year); ?> Session
                        </option>
                        <?php endforeach; ?>
                        <?php if (empty($session_years)): ?>
                        <option><?php echo htmlspecialchars($session_year); ?> Session</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="courses-select-group">
                    <label>Semester</label>
                    <select class="courses-select" name="semester" onchange="document.getElementById('sessionForm').submit();">
                        <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>First Semester</option>
                        <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>Second Semester</option>
                    </select>
                </div>
            </form>

            <button class="courses-btn courses-btn-primary" onclick="downloadPDF('course_form')" <?php echo $total_courses == 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-download"></i>
                Download Course Form
            </button>

            <button class="courses-btn courses-btn-outline" onclick="downloadPDF('exam_card')" <?php echo $total_courses == 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-id-card"></i>
                Generate Exam Card
            </button>
            
            <?php if ($registration_open && $semester == (int)($current_session['semester'] ?? 1) && $session_year === ($current_session['session_year'] ?? '')): ?>
            <a href="course-registration.php" class="courses-btn courses-btn-primary" style="margin-top: 8px;">
                <i class="fas fa-plus"></i>
                Register New Courses
            </a>
            <?php endif; ?>
        </div>

        <!-- Right: Courses List Panel -->
        <div class="courses-list-panel">
            <div class="courses-list-header">
                <h3>Registered Courses</h3>
                <span class="courses-count-badge"><?php echo $total_courses; ?> Course<?php echo $total_courses !== 1 ? 's' : ''; ?></span>
            </div>

            <?php if ($total_courses > 0): ?>
            <div class="courses-list-body">
                <?php foreach ($courses_array as $course): ?>
                <div class="course-row">
                    <div class="course-info-left">
                        <div class="course-code-text"><?php echo htmlspecialchars($course['course_code']); ?></div>
                        <div class="course-title-text"><?php echo htmlspecialchars($course['course_title']); ?></div>
                        <div class="course-meta">
                            <span class="course-status-badge status-<?php echo strtolower($course['registration_status']); ?>">
                                <?php echo htmlspecialchars($course['registration_status']); ?>
                            </span>
                            <?php if ($course['grade']): ?>
                            <span class="course-status-badge" style="background: #e8f2f8; color: #3f749c;">
                                Grade: <?php echo htmlspecialchars($course['grade']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="course-units-right">
                        <div class="course-units-label">Unit Load</div>
                        <div class="course-units-value"><?php echo $course['credit_units']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="courses-summary-bar">
                <div class="courses-summary-item">
                    <span class="courses-summary-label">Total Courses</span>
                    <span class="courses-summary-value"><?php echo $total_courses; ?></span>
                </div>
                <div class="courses-summary-item">
                    <span class="courses-summary-label">Total Units</span>
                    <span class="courses-summary-value"><?php echo $total_units; ?></span>
                </div>
                <div class="courses-summary-item">
                    <span class="courses-summary-label">Status</span>
                    <span class="courses-summary-value" style="font-size: 14px;">
                        <?php 
                        $all_approved = true;
                        foreach ($courses_array as $c) {
                            if ($c['registration_status'] !== 'Approved') {
                                $all_approved = false;
                                break;
                            }
                        }
                        if ($all_approved && $total_courses > 0):
                        ?>
                        <span class="courses-status-pill courses-status-registered">
                            <i class="fas fa-check-circle" style="font-size: 10px;"></i>
                            Fully Registered
                        </span>
                        <?php else: ?>
                        <span class="courses-status-pill courses-status-pending">
                            <i class="fas fa-clock" style="font-size: 10px;"></i>
                            Pending Approval
                        </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php else: ?>
            <div class="courses-empty">
                <div class="courses-empty-icon">
                    <i class="fas fa-book-open"></i>
                </div>
                <h4>No Courses Found</h4>
                <p>No registered courses for <?php echo htmlspecialchars($session_year); ?> <?php echo $semester == 1 ? 'First' : 'Second'; ?> Semester.</p>
                <?php if ($registration_open && $session_year === ($current_session['session_year'] ?? '') && $semester == (int)($current_session['semester'] ?? 1)): ?>
                <a href="course-registration.php" class="courses-btn courses-btn-primary" style="width: auto; display: inline-flex;">
                    <i class="fas fa-plus"></i> Register Now
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
const studentData = <?php echo json_encode($student_info); ?>;
const coursesData = <?php echo json_encode($courses_array); ?>;
const sessionYear = '<?php echo $session_year; ?>';
const semesterNum = '<?php echo $semester; ?>';
const totalUnits = '<?php echo $total_units; ?>';

function downloadPDF(type) {
    if (coursesData.length === 0) {
        alert('No courses available to generate PDF.');
        return;
    }
    if (type === 'course_form') {
        generateCourseFormPDF();
    } else if (type === 'exam_card') {
        generateExamCardPDF();
    }
}

function generateCourseFormPDF() {
    const pdfContent = document.createElement('div');
    pdfContent.style.padding = '40px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.backgroundColor = 'white';

    const courses = coursesData;
    const student = studentData;

    let coursesRows = '';
    courses.forEach((course, index) => {
        coursesRows += `
            <tr>
                <td style="border: 1px solid #ddd; padding: 10px;">${index + 1}</td>
                <td style="border: 1px solid #ddd; padding: 10px;">${course.course_code || ''}</td>
                <td style="border: 1px solid #ddd; padding: 10px;">${course.course_title || ''}</td>
                <td style="border: 1px solid #ddd; padding: 10px; text-align: center;">${course.credit_units || 0}</td>
            </tr>
        `;
    });

    pdfContent.innerHTML = `
        <div style="text-align: center; margin-bottom: 30px; border-bottom: 3px solid #3f749c; padding-bottom: 20px;">
            <h1 style="color: #3f749c; margin: 0; font-size: 24px;">COURSE REGISTRATION FORM</h1>
            <p style="color: #666; margin: 8px 0 0 0; font-size: 14px;">Academic Session: ${sessionYear} | Semester: ${semesterNum}</p>
        </div>

        <div style="margin-bottom: 25px; background: #f8fafc; padding: 20px; border-radius: 8px;">
            <div style="display: flex; margin-bottom: 10px;">
                <div style="width: 150px; font-weight: 600; color: #475569;">Student Name:</div>
                <div>${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}</div>
            </div>
            <div style="display: flex; margin-bottom: 10px;">
                <div style="width: 150px; font-weight: 600; color: #475569;">Matric Number:</div>
                <div>${student.matric_number || ''}</div>
            </div>
            <div style="display: flex; margin-bottom: 10px;">
                <div style="width: 150px; font-weight: 600; color: #475569;">Department:</div>
                <div>${student.department_name || ''}</div>
            </div>
            <div style="display: flex;">
                <div style="width: 150px; font-weight: 600; color: #475569;">Program:</div>
                <div>${student.program_name || ''}</div>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
            <thead>
                <tr style="background: #3f749c; color: white;">
                    <th style="padding: 12px; text-align: left; font-weight: 600;">S/N</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600;">Course Code</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600;">Course Title</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600;">Units</th>
                </tr>
            </thead>
            <tbody>
                ${coursesRows}
            </tbody>
            <tfoot>
                <tr style="background: #f1f5f9; font-weight: 700;">
                    <td colspan="3" style="border: 1px solid #ddd; padding: 12px;">Total Credit Units</td>
                    <td style="border: 1px solid #ddd; padding: 12px; text-align: center;">${totalUnits}</td>
                </tr>
            </tfoot>
        </table>

        <div style="margin-top: 50px; display: flex; justify-content: space-between;">
            <div style="text-align: center;">
                <div style="width: 180px; border-top: 1px solid #333; padding-top: 8px; font-size: 12px;">Student Signature</div>
            </div>
            <div style="text-align: center;">
                <div style="width: 180px; border-top: 1px solid #333; padding-top: 8px; font-size: 12px;">Advisor Signature</div>
            </div>
            <div style="text-align: center;">
                <div style="width: 180px; border-top: 1px solid #333; padding-top: 8px; font-size: 12px;">HOD Signature</div>
            </div>
        </div>

        <div style="margin-top: 40px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 15px;">
            Generated on ${new Date().toLocaleDateString()} | This is a computer-generated document.
        </div>
    `;

    document.body.appendChild(pdfContent);

    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `Course_Form_${student.matric_number || 'student'}_${sessionYear}_Sem${semesterNum}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    });
}

function generateExamCardPDF() {
    const approvedCourses = coursesData.filter(course => course.registration_status === 'Approved');
    const student = studentData;

    if (approvedCourses.length === 0) {
        alert('No approved courses found for exam card generation.');
        return;
    }

    const pdfContent = document.createElement('div');
    pdfContent.style.padding = '40px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.backgroundColor = 'white';

    let coursesRows = '';
    approvedCourses.forEach((course, index) => {
        coursesRows += `
            <tr>
                <td style="border: 1px solid #ddd; padding: 10px; text-align: center;">${index + 1}</td>
                <td style="border: 1px solid #ddd; padding: 10px;">${course.course_code || ''}</td>
                <td style="border: 1px solid #ddd; padding: 10px;">${(course.course_title || '').substring(0, 40)}</td>
                <td style="border: 1px solid #ddd; padding: 10px; text-align: center;">${course.credit_units || 0}</td>
            </tr>
        `;
    });

    pdfContent.innerHTML = `
        <div style="text-align: center; margin-bottom: 25px; border-bottom: 3px solid #3f749c; padding-bottom: 20px;">
            <h1 style="color: #3f749c; margin: 0; font-size: 28px; letter-spacing: 2px;">EXAMINATION CARD</h1>
            <h2 style="margin: 8px 0; font-size: 18px; color: #333;">${student.department_name || 'University'}</h2>
            <p style="color: #666; font-size: 12px; margin: 0;">Session: ${sessionYear} | Semester: ${semesterNum}</p>
        </div>

        <div style="display: flex; gap: 20px; margin-bottom: 20px; background: #f8fafc; padding: 20px; border-radius: 8px;">
            <div style="width: 100px; height: 120px; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; background: white; color: #94a3b8; font-size: 11px; text-align: center;">
                PASSPORT<br>PHOTO
            </div>
            <div style="flex: 1;">
                <div style="display: flex; margin-bottom: 8px;">
                    <div style="width: 140px; font-weight: 600; color: #475569;">Full Name:</div>
                    <div>${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}</div>
                </div>
                <div style="display: flex; margin-bottom: 8px;">
                    <div style="width: 140px; font-weight: 600; color: #475569;">Matric No:</div>
                    <div>${student.matric_number || ''}</div>
                </div>
                <div style="display: flex; margin-bottom: 8px;">
                    <div style="width: 140px; font-weight: 600; color: #475569;">Department:</div>
                    <div>${student.department_name || ''}</div>
                </div>
                <div style="display: flex;">
                    <div style="width: 140px; font-weight: 600; color: #475569;">Level:</div>
                    <div>${student.current_level || ''}</div>
                </div>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #3f749c; color: white;">
                    <th style="padding: 10px; font-weight: 600;">S/N</th>
                    <th style="padding: 10px; font-weight: 600; text-align: left;">Course Code</th>
                    <th style="padding: 10px; font-weight: 600; text-align: left;">Course Title</th>
                    <th style="padding: 10px; font-weight: 600;">Units</th>
                </tr>
            </thead>
            <tbody>
                ${coursesRows}
            </tbody>
        </table>

        <div style="margin-top: 40px; display: flex; justify-content: space-between;">
            <div style="text-align: center;">
                <div style="width: 160px; border-top: 1px solid #333; padding-top: 8px; font-size: 11px;">Student Signature</div>
            </div>
            <div style="text-align: center;">
                <div style="width: 160px; border-top: 1px solid #333; padding-top: 8px; font-size: 11px;">Exam Officer</div>
            </div>
            <div style="text-align: center;">
                <div style="width: 160px; border-top: 1px solid #333; padding-top: 8px; font-size: 11px;">Registrar</div>
            </div>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #fef3c7; border-radius: 8px; font-size: 11px; color: #92400e;">
            <strong>IMPORTANT:</strong> This card must be presented at each examination. Impersonation is a serious offense.
        </div>
    `;

    document.body.appendChild(pdfContent);

    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `Exam_Card_${student.matric_number || 'student'}_${sessionYear}_Sem${semesterNum}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>