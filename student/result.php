<?php
require_once 'includes/header.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int)$_SESSION['student_id'];

// ─── Fetch Student Info ───
$student_query = "SELECT s.*, d.department_name, p.program_name 
                 FROM students s 
                 LEFT JOIN departments d ON s.department_id = d.department_id 
                 LEFT JOIN programs p ON s.program_id = p.program_id 
                 WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student not found.");
}

// ─── Fetch All Available Sessions ───
$sessions_query = "SELECT DISTINCT session_year FROM (
    SELECT session_year FROM course_registrations WHERE student_id = ?
    UNION
    SELECT session_year FROM results WHERE student_id = ?
) combined_sessions ORDER BY session_year DESC";
$stmt = $conn->prepare($sessions_query);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$sessions_result = $stmt->get_result();
$available_sessions = [];
while ($row = $sessions_result->fetch_assoc()) {
    $available_sessions[] = $row['session_year'];
}

// ─── Get Selected Session ───
$selected_session = isset($_GET['session']) ? $_GET['session'] : ($available_sessions[0] ?? $student['current_session'] ?? '2025/2026');

// ─── Fetch Semesters ───
$semesters_data = [];
if (!empty($selected_session)) {
    $semesters_query = "SELECT 
                            r.semester, 
                            r.level,
                            ROUND(AVG(r.grade_points), 2) as gpa,
                            COUNT(r.result_id) as total_courses,
                            SUM(c.credit_units) as total_units,
                            SUM(r.grade_points * c.credit_units) as total_weighted_points,
                            MAX(r.is_published) as has_published_results
                        FROM results r 
                        JOIN courses c ON r.course_id = c.course_id 
                        WHERE r.student_id = ? AND r.session_year = ?
                        GROUP BY r.semester, r.level 
                        
                        UNION
                        
                        SELECT 
                            cr.semester,
                            cr.level,
                            NULL as gpa,
                            COUNT(cr.registration_id) as total_courses,
                            SUM(c.credit_units) as total_units,
                            NULL as total_weighted_points,
                            0 as has_published_results
                        FROM course_registrations cr
                        JOIN courses c ON cr.course_id = c.course_id
                        WHERE cr.student_id = ? AND cr.session_year = ?
                        AND NOT EXISTS (
                            SELECT 1 FROM results r2 
                            WHERE r2.student_id = cr.student_id 
                            AND r2.session_year = cr.session_year 
                            AND r2.semester = cr.semester
                        )
                        GROUP BY cr.semester, cr.level
                        
                        ORDER BY semester";
    $stmt = $conn->prepare($semesters_query);
    $stmt->bind_param("isis", $student_id, $selected_session, $student_id, $selected_session);
    $stmt->execute();
    $semesters_result = $stmt->get_result();
    while ($row = $semesters_result->fetch_assoc()) {
        $row['semester_name'] = ($row['semester'] == 1) ? 'First Semester' : 'Second Semester';
        $row['level_name'] = getLevelName($row['level']);
        $row['has_results'] = !is_null($row['gpa']);
        $semesters_data[] = $row;
    }
}

// ─── Get Selected Semester & Level ───
$selected_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$selected_level = isset($_GET['level']) ? (int)$_GET['level'] : null;

if ($selected_semester === null && !empty($semesters_data)) {
    foreach ($semesters_data as $sem) {
        if ($sem['has_results']) {
            $selected_semester = (int)$sem['semester'];
            $selected_level = (int)$sem['level'];
            break;
        }
    }
    if ($selected_semester === null) {
        $selected_semester = (int)$semesters_data[0]['semester'];
        $selected_level = (int)$semesters_data[0]['level'];
    }
}

if ($selected_semester === null) $selected_semester = 1;
if ($selected_level === null) $selected_level = (int)($student['current_level'] ?? 100);

// ─── Fetch Detailed Results ───
$courses_result = [];
$total_units = 0;
$total_weighted_points = 0;
$total_courses = 0;
$gpa = 0;
$has_published_results = false;

$check_published = "SELECT MAX(is_published) as is_published FROM results 
                    WHERE student_id = ? AND session_year = ? AND semester = ?";
$stmt = $conn->prepare($check_published);
$stmt->bind_param("isi", $student_id, $selected_session, $selected_semester);
$stmt->execute();
$pub_check = $stmt->get_result()->fetch_assoc();
$has_published_results = (bool)($pub_check['is_published'] ?? 0);

$results_query = "SELECT r.*, c.course_code, c.course_title, c.credit_units,
                  gs.grade_points as scale_gp, gs.remark as grade_remark_text
                  FROM results r 
                  JOIN courses c ON r.course_id = c.course_id 
                  LEFT JOIN grade_scale gs ON r.grade = gs.grade
                  WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1
                  ORDER BY c.course_code";
$stmt = $conn->prepare($results_query);
$stmt->bind_param("isi", $student_id, $selected_session, $selected_semester);
$stmt->execute();
$results_detail = $stmt->get_result();

while ($row = $results_detail->fetch_assoc()) {
    if (is_null($row['grade_points']) && !is_null($row['scale_gp'])) {
        $row['grade_points'] = $row['scale_gp'];
    }
    $courses_result[] = $row;
    $total_units += (float)$row['credit_units'];
    $total_weighted_points += ((float)$row['grade_points'] * (float)$row['credit_units']);
    $total_courses++;
}

$gpa = $total_units > 0 ? round($total_weighted_points / $total_units, 2) : 0;

// ─── Calculate CGPA ───
$cgpa = 0;
$tcue = 0;
$tcgp = 0;

$cgpa_query = "SELECT SUM(r.grade_points * c.credit_units) as total_wp, SUM(c.credit_units) as total_u
               FROM results r 
               JOIN courses c ON r.course_id = c.course_id 
               WHERE r.student_id = ? AND r.is_published = 1";
$stmt = $conn->prepare($cgpa_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$cgpa_data = $stmt->get_result()->fetch_assoc();
$tcue = (float)($cgpa_data['total_u'] ?? 0);
$tcgp = (float)($cgpa_data['total_wp'] ?? 0);
$cgpa = ($tcue > 0) ? round($tcgp / $tcue, 2) : 0;

$remark = getRemark($gpa);

function getLevelName($level) {
    $levels = [100 => 'LEVEL ONE', 200 => 'LEVEL TWO', 300 => 'LEVEL THREE', 400 => 'LEVEL FOUR', 500 => 'LEVEL FIVE'];
    return $levels[$level] ?? "LEVEL $level";
}

function getRemark($gpa) {
    if ($gpa >= 4.50) return "VC'S LIST";
    if ($gpa >= 3.50) return "DEAN'S LIST";
    if ($gpa >= 3.00) return "GOOD STANDING";
    if ($gpa >= 2.00) return "PASS";
    return "PROBATION";
}

function getGradeClass($grade) {
    $grade = strtoupper($grade);
    $map = ['A' => 'grade-a', 'B' => 'grade-b', 'C' => 'grade-c', 'D' => 'grade-d', 'E' => 'grade-e', 'F' => 'grade-f'];
    return $map[$grade] ?? '';
}

$student_name = strtoupper(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$dept_name = htmlspecialchars($student['department_name'] ?? 'Computer Science');
$matric = htmlspecialchars($student['matric_number'] ?? '');
$session_display = htmlspecialchars($selected_session);
$semester_name = ($selected_semester == 1) ? 'First' : 'Second';
$level_name = getLevelName($selected_level);
?>

<style>
/* ========== SCREEN STYLES ========== */
.result-page { padding: 0; max-width: 1200px; margin: 0 auto; }

.result-topbar {
    background: #3f749c;
    border-radius: 12px;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}
.result-topbar-title { display: flex; align-items: center; gap: 14px; }
.result-topbar-title h2 { color: #fff; font-size: 18px; font-weight: 600; margin: 0; }
.result-topbar-badges { display: flex; align-items: center; gap: 10px; }
.badge-try { background: #c5ea4f; color: #2a5a7a; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; }

.result-page-title { font-size: 26px; font-weight: 700; color: #1e293b; margin-bottom: 24px; }

.result-content-grid { display: grid; grid-template-columns: 360px 1fr; gap: 24px; align-items: start; }

.result-control-panel {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.result-select-group { margin-bottom: 16px; }
.result-select-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.result-select {
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
.result-select:focus { outline: none; border-color: #3f749c; box-shadow: 0 0 0 3px rgba(63,116,156,0.1); }

.semester-cards-panel { display: flex; flex-direction: column; gap: 16px; }
.semester-card {
    background: #f1f5f9;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
    text-decoration: none;
    color: inherit;
}
.semester-card:hover { background: #e2e8f0; border-color: #3f749c; }
.semester-card.active { background: #e8f2f8; border-color: #3f749c; }
.semester-card.registered-only { opacity: 0.7; }
.semester-card-info { display: flex; flex-direction: column; gap: 4px; }
.semester-card-level { font-size: 12px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.semester-card-name { font-size: 16px; font-weight: 700; color: #3f749c; }
.semester-card-gpa { font-size: 13px; color: #64748b; font-weight: 500; }
.semester-card-status {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 600;
    display: inline-block;
    margin-top: 4px;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-published { background: #dcfce7; color: #166534; }
.semester-card-arrow { color: #94a3b8; font-size: 14px; }

.result-empty { text-align: center; padding: 60px 20px; color: #64748b; font-size: 15px; }

.result-detail-panel {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
}

.result-detail-header { padding: 24px; border-bottom: 1px solid #f1f5f9; }
.result-detail-header h3 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 8px 0; }
.result-detail-meta { font-size: 14px; color: #64748b; line-height: 1.6; }
.result-detail-meta strong { color: #1e293b; }

.result-summary-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
}
.result-summary-left { display: flex; flex-direction: column; gap: 4px; }
.result-summary-label { font-size: 13px; font-weight: 600; color: #1e293b; }
.result-summary-courses { font-size: 24px; font-weight: 700; color: #1e293b; }
.result-summary-right { text-align: right; }
.result-gpa-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
.result-gpa-value { font-size: 28px; font-weight: 700; color: #3f749c; }

.result-table { width: 100%; border-collapse: collapse; }
.result-table thead th {
    padding: 14px 20px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
    background: #fafafa;
}
.result-table thead th:last-child { text-align: right; }
.result-table tbody td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.result-table tbody tr:hover { background: #f8fafc; }

.result-course-code { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
.result-course-title { font-size: 13px; color: #64748b; }
.result-credit, .result-grade, .result-gp { font-size: 15px; font-weight: 600; color: #1e293b; }
.result-remark { font-size: 14px; font-weight: 600; text-align: right; }
.result-remark.pass { color: #166534; }
.result-remark.fail { color: #dc2626; }

.grade-a { color: #166534; font-weight: 700; }
.grade-b { color: #1e40af; font-weight: 700; }
.grade-c { color: #0369a1; font-weight: 700; }
.grade-d { color: #a16207; font-weight: 700; }
.grade-e { color: #c2410c; font-weight: 700; }
.grade-f { color: #dc2626; font-weight: 700; }

.result-remarks-section { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; }
.result-remarks-section strong { color: #1e293b; font-size: 14px; }
.result-remarks-section span { color: #3f749c; font-weight: 700; font-size: 14px; }

.result-gpa-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
}
.result-gpa-column h4 {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 12px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.result-gpa-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
.result-gpa-row .label { color: #64748b; }
.result-gpa-row .value { color: #1e293b; font-weight: 600; }

.result-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    gap: 16px;
}
.result-btn {
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
}
.result-btn-back { background: transparent; color: #3f749c; border: 1.5px solid #3f749c; }
.result-btn-back:hover { background: #e8f2f8; }
.result-btn-download { background: #3f749c; color: #fff; }
.result-btn-download:hover { background: #2a5a7a; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(63,116,156,0.25); }
.result-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }

.unpublished-notice {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 16px 20px;
    margin: 20px 24px;
    border-radius: 0 8px 8px 0;
    color: #92400e;
    font-size: 14px;
}
.unpublished-notice i { margin-right: 8px; }

@media (max-width: 1024px) {
    .result-content-grid { grid-template-columns: 1fr; }
    .result-gpa-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .result-table thead { display: none; }
    .result-table tbody td { display: block; padding: 8px 16px; }
    .result-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        display: block;
        font-size: 12px;
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .result-actions { flex-direction: column; }
    .result-btn { width: 100%; justify-content: center; }
}
</style>

<div class="result-page">
    <h1 class="result-page-title">Academic Result</h1>

    <?php if (empty($available_sessions)): ?>
    <div class="result-content-grid">
        <div class="result-control-panel">
            <div class="result-select-group">
                <label>Academic Session</label>
                <select class="result-select" disabled>
                    <option>No Session Available</option>
                </select>
            </div>
        </div>
        <div class="result-detail-panel">
            <div class="result-empty">
                <i class="fas fa-file-alt" style="font-size: 48px; color: #e2e8f0; margin-bottom: 16px; display: block;"></i>
                No Result Available Yet.
            </div>
        </div>
    </div>
    <?php else: ?>

    <div class="result-content-grid">
        <div>
            <div class="result-control-panel">
                <div class="result-select-group">
                    <label>Academic Session</label>
                    <select class="result-select" onchange="window.location.href='?session='+encodeURIComponent(this.value)">
                        <?php foreach ($available_sessions as $sess): ?>
                        <option value="<?php echo htmlspecialchars($sess); ?>" <?php echo ($sess === $selected_session) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sess); ?> Session
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="semester-cards-panel" style="margin-top: 16px;">
                <?php if (empty($semesters_data)): ?>
                    <div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 14px; background: #f8fafc; border-radius: 12px;">
                        No semesters found for this session.
                    </div>
                <?php else: ?>
                    <?php foreach ($semesters_data as $sem): 
                        $is_active = ($selected_semester == (int)$sem['semester'] && $selected_level == (int)$sem['level']);
                        $has_results = $sem['has_results'];
                    ?>
                    <a href="?session=<?php echo urlencode($selected_session); ?>&semester=<?php echo (int)$sem['semester']; ?>&level=<?php echo (int)$sem['level']; ?>"
                       class="semester-card <?php echo $is_active ? 'active' : ''; ?> <?php echo !$has_results ? 'registered-only' : ''; ?>">
                        <div class="semester-card-info">
                            <div class="semester-card-level"><?php echo htmlspecialchars($sem['level_name']); ?></div>
                            <div class="semester-card-name"><?php echo htmlspecialchars($sem['semester_name']); ?></div>
                            <div class="semester-card-gpa">
                                <?php if ($has_results): ?>
                                    GPA: <?php echo number_format((float)$sem['gpa'], 2); ?> &bull; <?php echo (int)$sem['total_courses']; ?> Courses
                                <?php else: ?>
                                    <?php echo (int)$sem['total_courses']; ?> Courses Registered &bull; Results Pending
                                <?php endif; ?>
                            </div>
                            <?php if (!$has_results): ?>
                            <span class="semester-card-status status-pending">
                                <i class="fas fa-clock"></i> Results Not Published
                            </span>
                            <?php else: ?>
                            <span class="semester-card-status status-published">
                                <i class="fas fa-check-circle"></i> Published
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="semester-card-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="result-detail-panel" id="result-detail-panel">
            <div class="result-detail-header">
                <h3>Department of <?php echo $dept_name; ?></h3>
                <div class="result-detail-meta">
                    <strong>Session:</strong> <?php echo $semester_name; ?> Semester, <?php echo $session_display; ?> Session<br>
                    <strong>Level:</strong> <?php echo $level_name; ?><br>
                    <strong>Mat. Number:</strong> <?php echo $matric; ?><br>
                    <strong>Student:</strong> <?php echo htmlspecialchars($student_name); ?>
                </div>
            </div>

            <?php if (!$has_published_results && empty($courses_result)): ?>
            <div class="unpublished-notice">
                <i class="fas fa-info-circle"></i>
                <strong>Results Not Yet Published</strong><br>
                Results for <?php echo $semester_name; ?> Semester, <?php echo $session_display; ?> Session have not been published yet. Please check back later or contact your department.
            </div>
            <?php endif; ?>

            <?php if ($total_courses > 0): ?>
            <div class="result-summary-bar">
                <div class="result-summary-left">
                    <span class="result-summary-label">Courses offered</span>
                    <span class="result-summary-courses"><?php echo $total_courses; ?></span>
                </div>
                <div class="result-summary-right">
                    <div class="result-gpa-label">G.P.A</div>
                    <div class="result-gpa-value"><?php echo number_format($gpa, 2); ?></div>
                </div>
            </div>

            <table class="result-table">
                <thead>
                    <tr>
                        <th>Course Code & Title</th>
                        <th>Credit</th>
                        <th>Grade</th>
                        <th>GP</th>
                        <th style="text-align: right;">Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses_result as $course): 
                        $grade = strtoupper($course['grade'] ?? '');
                        $grade_class = getGradeClass($grade);
                        $is_pass = ($grade !== 'F');
                        $gp = is_numeric($course['grade_points']) ? (float)$course['grade_points'] : 0;
                    ?>
                    <tr>
                        <td data-label="Course">
                            <div class="result-course-code"><?php echo htmlspecialchars($course['course_code'] ?? ''); ?></div>
                            <div class="result-course-title"><?php echo htmlspecialchars($course['course_title'] ?? ''); ?></div>
                        </td>
                        <td data-label="Credit">
                            <span class="result-credit"><?php echo (int)($course['credit_units'] ?? 0); ?></span>
                        </td>
                        <td data-label="Grade">
                            <span class="result-grade <?php echo $grade_class; ?>"><?php echo htmlspecialchars($grade); ?></span>
                        </td>
                        <td data-label="GP">
                            <span class="result-gp"><?php echo number_format($gp, 1); ?></span>
                        </td>
                        <td data-label="Remark" style="text-align: right;">
                            <span class="result-remark <?php echo $is_pass ? 'pass' : 'fail'; ?>">
                                <?php echo $is_pass ? 'Pass' : 'Fail'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="result-remarks-section">
                <strong>Remarks:</strong> <span><?php echo htmlspecialchars($remark); ?></span>
            </div>

            <div class="result-gpa-grid">
                <div class="result-gpa-column">
                    <h4>Current Semester</h4>
                    <div class="result-gpa-row">
                        <span class="label">CUR (Units Registered):</span>
                        <span class="value"><?php echo $total_units; ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">CUE (Units Earned):</span>
                        <span class="value"><?php echo $total_units; ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">WGP (Weighted GP):</span>
                        <span class="value"><?php echo round($total_weighted_points); ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">GPA:</span>
                        <span class="value" style="color:#3f749c; font-size:16px;"><?php echo number_format($gpa, 2); ?></span>
                    </div>
                </div>
                <div class="result-gpa-column">
                    <h4>Cumulative</h4>
                    <div class="result-gpa-row">
                        <span class="label">TCUR (Total Units Reg):</span>
                        <span class="value"><?php echo round($tcue); ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">TCUE (Total Units Earned):</span>
                        <span class="value"><?php echo round($tcue); ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">TWGP (Total Weighted GP):</span>
                        <span class="value"><?php echo round($tcgp); ?></span>
                    </div>
                    <div class="result-gpa-row">
                        <span class="label">CGPA:</span>
                        <span class="value" style="color:#3f749c; font-size:16px;"><?php echo number_format($cgpa, 2); ?></span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="result-empty" style="padding: 40px;">
                <i class="fas fa-inbox" style="font-size: 48px; color: #e2e8f0; margin-bottom: 16px; display: block;"></i>
                <?php if (!$has_published_results): ?>
                    Results have not been published for this semester yet.
                <?php else: ?>
                    No results found for <?php echo $semester_name; ?> Semester.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="result-actions">
                <a href="dashboard.php" class="result-btn result-btn-back">
                    <i class="fas fa-chevron-left"></i> Back to Dashboard
                </a>
                <?php if ($total_courses > 0): ?>
                <a href="download-result.php?session=<?php echo urlencode($session_display); ?>&semester=<?php echo (int)$selected_semester; ?>&level=<?php echo (int)$selected_level; ?>" 
   class="result-btn result-btn-download">
    <i class="fas fa-download"></i> Download PDF
</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
// ─── DATA FROM PHP ───
const studentData = <?php echo json_encode($student); ?>;
const coursesData = <?php echo json_encode($courses_result); ?>;
const sessionYear = '<?php echo $session_display; ?>';
const semesterNum = '<?php echo $selected_semester; ?>';
const semesterName = '<?php echo $semester_name; ?>';
const levelName = '<?php echo $level_name; ?>';
const totalUnits = <?php echo $total_units; ?>;
const totalWeightedPoints = <?php echo $total_weighted_points; ?>;
const gpaValue = <?php echo $gpa; ?>;
const cgpaValue = <?php echo $cgpa; ?>;
const tcueValue = <?php echo $tcue; ?>;
const tcgpValue = <?php echo $tcgp; ?>;
const remarkText = '<?php echo addslashes($remark); ?>';
const deptName = '<?php echo addslashes($dept_name); ?>';
const matricNum = '<?php echo addslashes($matric); ?>';
const studentName = '<?php echo addslashes($student_name); ?>';

function getGradeColorClass(grade) {
    const map = {
        'A': 'color:#166534;', 'B': 'color:#1e40af;', 'C': 'color:#0369a1;',
        'D': 'color:#a16207;', 'E': 'color:#c2410c;', 'F': 'color:#dc2626;'
    };
    return map[grade] || '';
}

function downloadResultPDF() {
    if (!coursesData || coursesData.length === 0) {
        alert('No results available to download.');
        return;
    }

    // Build PDF content dynamically
    let coursesRows = '';
    coursesData.forEach((course, index) => {
        const grade = (course.grade || '').toUpperCase();
        const gp = parseFloat(course.grade_points || 0).toFixed(1);
        const isPass = grade !== 'F';
        const gradeStyle = getGradeColorClass(grade);
        
        coursesRows += `
            <tr>
                <td style="border:1px solid #e2e8f0;padding:8px 10px;">
                    <div style="font-weight:bold;font-size:11px;color:#1e293b;">${course.course_code || ''}</div>
                    <div style="font-size:10px;color:#64748b;">${course.course_title || ''}</div>
                </td>
                <td style="border:1px solid #e2e8f0;padding:8px 10px;text-align:center;">${course.credit_units || 0}</td>
                <td style="border:1px solid #e2e8f0;padding:8px 10px;text-align:center;font-weight:bold;${gradeStyle}">${grade}</td>
                <td style="border:1px solid #e2e8f0;padding:8px 10px;text-align:center;">${gp}</td>
                <td style="border:1px solid #e2e8f0;padding:8px 10px;text-align:right;">
                    <span style="font-weight:bold;${isPass ? 'color:#166534;' : 'color:#dc2626;'}">${isPass ? 'Pass' : 'Fail'}</span>
                </td>
            </tr>
        `;
    });

    const pdfContent = document.createElement('div');
    pdfContent.style.cssText = 'width:210mm;min-height:297mm;background:white;font-family:Arial,sans-serif;color:#000;padding:15mm;box-sizing:border-box;';

    pdfContent.innerHTML = `
        <div style="text-align:center;border-bottom:2px solid #3f749c;padding-bottom:15px;margin-bottom:20px;">
            <h1 style="color:#3f749c;margin:0;font-size:22px;font-weight:bold;">5G E-GURU SCHOOL</h1>
            <h2 style="margin:8px 0 0 0;font-size:16px;color:#333;font-weight:bold;">STUDENT ACADEMIC RESULT</h2>
            <p style="margin:5px 0 0 0;color:#666;font-size:12px;">Department of ${deptName}</p>
        </div>

        <div style="margin-bottom:20px;">
            <h3 style="font-size:14px;font-weight:bold;color:#1e293b;margin:0 0 8px 0;">Department of ${deptName}</h3>
            <div style="font-size:12px;line-height:1.8;color:#333;">
                <strong>Session:</strong> ${semesterName} Semester, ${sessionYear} Session<br>
                <strong>Level:</strong> ${levelName}<br>
                <strong>Mat. Number:</strong> ${matricNum}<br>
                <strong>Student:</strong> ${studentName}
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 15px;background:#f1f5f9;border:1px solid #e2e8f0;margin-bottom:15px;border-radius:4px;">
            <div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Courses offered</div>
                <div style="font-size:20px;font-weight:bold;color:#1e293b;">${coursesData.length}</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;">G.P.A</div>
                <div style="font-size:24px;font-weight:bold;color:#3f749c;">${gpaValue.toFixed(2)}</div>
            </div>
        </div>

        <table style="width:100%;border-collapse:collapse;margin-bottom:15px;font-size:11px;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="border:1px solid #cbd5e1;padding:8px 10px;text-align:left;font-weight:bold;color:#334155;font-size:10px;text-transform:uppercase;width:45%;">Course Code & Title</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 10px;text-align:center;font-weight:bold;color:#334155;font-size:10px;text-transform:uppercase;width:12%;">Credit</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 10px;text-align:center;font-weight:bold;color:#334155;font-size:10px;text-transform:uppercase;width:12%;">Grade</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 10px;text-align:center;font-weight:bold;color:#334155;font-size:10px;text-transform:uppercase;width:12%;">GP</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 10px;text-align:right;font-weight:bold;color:#334155;font-size:10px;text-transform:uppercase;width:19%;">Remark</th>
                </tr>
            </thead>
            <tbody>
                ${coursesRows}
            </tbody>
        </table>

        <div style="padding:10px 15px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:15px;font-size:12px;">
            <strong style="color:#1e293b;">Remarks:</strong> <span style="color:#3f749c;font-weight:bold;">${remarkText}</span>
        </div>

        <div style="display:flex;gap:20px;margin-bottom:15px;">
            <div style="flex:1;border:1px solid #e2e8f0;padding:12px 15px;background:#fafafa;">
                <h4 style="font-size:11px;font-weight:bold;color:#1e293b;margin:0 0 8px 0;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:5px;">Current Semester</h4>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CUR:</span><span>${totalUnits}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CUE:</span><span>${totalUnits}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>WGP:</span><span>${Math.round(totalWeightedPoints)}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>GPA:</span><span style="color:#3f749c;font-weight:bold;">${gpaValue.toFixed(2)}</span></div>
            </div>
            <div style="flex:1;border:1px solid #e2e8f0;padding:12px 15px;background:#fafafa;">
                <h4 style="font-size:11px;font-weight:bold;color:#1e293b;margin:0 0 8px 0;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:5px;">Cumulative</h4>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TCUR:</span><span>${Math.round(tcueValue)}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TCUE:</span><span>${Math.round(tcueValue)}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TWGP:</span><span>${Math.round(tcgpValue)}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CGPA:</span><span style="color:#3f749c;font-weight:bold;">${cgpaValue.toFixed(2)}</span></div>
            </div>
        </div>

        <div style="margin-top:30px;text-align:center;font-size:9px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:10px;">
            <p>Generated on ${new Date().toLocaleDateString('en-GB', {day:'numeric', month:'long', year:'numeric'})} from 5G E-GURU School Student Portal</p>
            <p>This is a computer-generated document and does not require a physical signature.</p>
        </div>
    `;

    document.body.appendChild(pdfContent);

    const opt = {
        margin: [8, 8, 8, 8],
        filename: `Result_${matricNum}_${sessionYear.replace(/\//g, '_')}_Sem${semesterNum}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { 
            scale: 2, 
            useCORS: true, 
            logging: false,
            allowTaint: true,
            backgroundColor: '#ffffff'
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    }).catch(err => {
        console.error('PDF generation failed:', err);
        alert('PDF generation failed. Please try again.');
        if (pdfContent.parentNode) document.body.removeChild(pdfContent);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>