<?php
// academic_reports.php - Academic Performance Reports
ob_start();
require_once 'includes/header.php';

$page_title = "Academic Reports";

// Database connection
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'sms';
    $username = 'root';
    $password = '';
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// Get filter data
$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
$departments = $pdo->query("SELECT d.*, f.faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.faculty_id ORDER BY d.department_name")->fetchAll();
$programs = $pdo->query("SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.department_id WHERE p.is_active = 1 ORDER BY p.program_name")->fetchAll();
$courses = $pdo->query("SELECT c.*, d.department_name FROM courses c LEFT JOIN departments d ON c.department_id = d.department_id ORDER BY c.course_code")->fetchAll();
$sessions = $pdo->query("SELECT DISTINCT session_year FROM academic_sessions WHERE status IN ('Active', 'Completed') ORDER BY session_year DESC")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];

// Get filter parameters
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$session_year = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'gpa_summary';

// Build filters - applied to students table for accurate counts
$filters = [];
$params = [];

if ($faculty_id > 0) {
    $filters[] = "f.faculty_id = ?";
    $params[] = $faculty_id;
}
if ($department_id > 0) {
    $filters[] = "d.department_id = ?";
    $params[] = $department_id;
}
if ($program_id > 0) {
    $filters[] = "s.program_id = ?";
    $params[] = $program_id;
}
if ($course_id > 0) {
    $filters[] = "c.course_id = ?";
    $params[] = $course_id;
}
if (!empty($session_year)) {
    $filters[] = "ar.session_year = ?";
    $params[] = $session_year;
}
if ($semester > 0) {
    $filters[] = "ar.semester = ?";
    $params[] = $semester;
}
if ($level > 0) {
    $filters[] = "s.current_level = ?";
    $params[] = $level;
}

$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

// Get report data
$reports = [];
$summary = [];

if ($report_type == 'gpa_summary') {
    // FIXED: Count from students table, not academic_records
    $sql = "
        SELECT 
            COALESCE(ar.session_year, s.current_session) as session_year,
            COALESCE(ar.semester, 1) as semester,
            s.current_level as level,
            d.department_name,
            p.program_name,
            COUNT(DISTINCT s.student_id) as student_count,
            COALESCE(AVG(ar.gpa), 0) as avg_gpa,
            COALESCE(MAX(ar.gpa), 0) as max_gpa,
            COALESCE(MIN(ar.gpa), 0) as min_gpa,
            SUM(CASE WHEN ar.gpa >= 4.5 THEN 1 ELSE 0 END) as first_class,
            SUM(CASE WHEN ar.gpa >= 3.5 AND ar.gpa < 4.5 THEN 1 ELSE 0 END) as second_upper,
            SUM(CASE WHEN ar.gpa >= 2.5 AND ar.gpa < 3.5 THEN 1 ELSE 0 END) as second_lower,
            SUM(CASE WHEN ar.gpa >= 1.5 AND ar.gpa < 2.5 THEN 1 ELSE 0 END) as third_class,
            SUM(CASE WHEN ar.gpa < 1.5 AND ar.gpa > 0 THEN 1 ELSE 0 END) as pass,
            SUM(CASE WHEN ar.gpa IS NULL OR ar.gpa = 0 THEN 1 ELSE 0 END) as no_record
        FROM students s
        LEFT JOIN academic_records ar ON s.student_id = ar.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        $where_clause
        GROUP BY COALESCE(ar.session_year, s.current_session), COALESCE(ar.semester, 1), s.current_level, d.department_id, p.program_id
        ORDER BY session_year DESC, semester DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // Summary statistics - count ALL students matching filters
    $summary_sql = "
        SELECT 
            COUNT(DISTINCT s.student_id) as total_students,
            COALESCE(AVG(ar.gpa), 0) as overall_avg_gpa,
            COUNT(DISTINCT ar.session_year) as total_sessions,
            COUNT(DISTINCT s.current_level) as total_levels
        FROM students s
        LEFT JOIN academic_records ar ON s.student_id = ar.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        $where_clause
    ";
    $summary_stmt = $pdo->prepare($summary_sql);
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch();

} elseif ($report_type == 'course_performance') {
    $sql = "
        SELECT 
            c.course_code,
            c.course_title,
            c.credit_units,
            r.session_year,
            r.semester,
            r.level,
            COUNT(r.result_id) as student_count,
            COALESCE(AVG(r.ca_score), 0) as avg_ca,
            COALESCE(AVG(r.exam_score), 0) as avg_exam,
            COALESCE(AVG(r.total_score), 0) as avg_total,
            COALESCE(AVG(r.grade_points), 0) as avg_gp,
            SUM(CASE WHEN r.grade = 'A' THEN 1 ELSE 0 END) as grade_a,
            SUM(CASE WHEN r.grade = 'B' THEN 1 ELSE 0 END) as grade_b,
            SUM(CASE WHEN r.grade = 'C' THEN 1 ELSE 0 END) as grade_c,
            SUM(CASE WHEN r.grade = 'D' THEN 1 ELSE 0 END) as grade_d,
            SUM(CASE WHEN r.grade = 'E' THEN 1 ELSE 0 END) as grade_e,
            SUM(CASE WHEN r.grade = 'F' THEN 1 ELSE 0 END) as grade_f,
            ROUND(COALESCE(SUM(CASE WHEN r.grade != 'F' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(r.result_id), 0), 0), 2) as pass_rate
        FROM results r
        JOIN courses c ON r.course_id = c.course_id
        JOIN students s ON r.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        $where_clause
        GROUP BY c.course_id, r.session_year, r.semester, r.level
        ORDER BY r.session_year DESC, avg_total DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

} elseif ($report_type == 'student_performance') {
    $sql = "
        SELECT 
            s.student_id,
            s.matric_number,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
            d.department_name,
            p.program_name,
            COUNT(DISTINCT ar.record_id) as semesters_completed,
            COALESCE(SUM(ar.total_units), 0) as total_credits,
            COALESCE(SUM(ar.total_points), 0) as total_points,
            ROUND(COALESCE(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 0), 2) as cgpa,
            COALESCE(MAX(ar.gpa), 0) as best_gpa,
            COALESCE(MIN(ar.gpa), 0) as worst_gpa
        FROM students s
        LEFT JOIN academic_records ar ON s.student_id = ar.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        $where_clause
        GROUP BY s.student_id
        ORDER BY cgpa DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
}

// Helper function for grade badge
function getGradeBadge($grade) {
    $badges = ['A' => 'success', 'B' => 'primary', 'C' => 'info', 'D' => 'warning', 'E' => 'secondary', 'F' => 'danger'];
    $class = $badges[$grade] ?? 'secondary';
    return "<span class='badge bg-$class'>$grade</span>";
}

// Helper function for classification badge
function getClassificationBadge($cgpa) {
    if ($cgpa >= 4.50) return ['First Class', 'success'];
    if ($cgpa >= 3.50) return ['Second Class Upper', 'primary'];
    if ($cgpa >= 2.50) return ['Second Class Lower', 'info'];
    if ($cgpa >= 1.50) return ['Third Class', 'warning'];
    if ($cgpa > 0) return ['Pass', 'secondary'];
    return ['No Record', 'dark'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Reports - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-section { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .stat-card { transition: transform 0.2s; cursor: pointer; border-radius: 12px; }
        .stat-card:hover { transform: translateY(-3px); }
        .report-table th { background-color: #f8f9fa; font-weight: 600; }
        .btn-filter { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; }
        .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; border: none; }
        .nav-tabs .nav-link.active { color: #4361ee; border-bottom: 2px solid #4361ee; background: transparent; }
        .progress-bar-custom { height: 8px; border-radius: 4px; }
        .classification-badge { font-size: 0.85rem; padding: 0.35rem 0.65rem; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="app-page-title mb-1"><i class="fas fa-chart-line me-2 text-primary"></i>Academic Reports</h1>
            <p class="text-muted mb-0">Comprehensive academic performance analysis and reports</p>
        </div>
        <div>
            <button class="btn btn-success" onclick="exportReport()"><i class="fas fa-file-excel me-2"></i>Export Report</button>
            <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
        </div>
    </div>

    <!-- Report Type Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $report_type == 'gpa_summary' ? 'active' : ''; ?>" href="?report_type=gpa_summary<?php echo $faculty_id ? '&faculty_id='.$faculty_id : ''; ?><?php echo $department_id ? '&department_id='.$department_id : ''; ?><?php echo $program_id ? '&program_id='.$program_id : ''; ?><?php echo $session_year ? '&session_year='.$session_year : ''; ?><?php echo $semester ? '&semester='.$semester : ''; ?><?php echo $level ? '&level='.$level : ''; ?>">
                <i class="fas fa-chart-bar me-2"></i>GPA Summary
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_type == 'course_performance' ? 'active' : ''; ?>" href="?report_type=course_performance<?php echo $faculty_id ? '&faculty_id='.$faculty_id : ''; ?><?php echo $department_id ? '&department_id='.$department_id : ''; ?><?php echo $program_id ? '&program_id='.$program_id : ''; ?><?php echo $session_year ? '&session_year='.$session_year : ''; ?><?php echo $semester ? '&semester='.$semester : ''; ?><?php echo $level ? '&level='.$level : ''; ?>">
                <i class="fas fa-book me-2"></i>Course Performance
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_type == 'student_performance' ? 'active' : ''; ?>" href="?report_type=student_performance<?php echo $faculty_id ? '&faculty_id='.$faculty_id : ''; ?><?php echo $department_id ? '&department_id='.$department_id : ''; ?><?php echo $program_id ? '&program_id='.$program_id : ''; ?><?php echo $session_year ? '&session_year='.$session_year : ''; ?><?php echo $semester ? '&semester='.$semester : ''; ?><?php echo $level ? '&level='.$level : ''; ?>">
                <i class="fas fa-users me-2"></i>Student Performance
            </a>
        </li>
    </ul>

    <!-- Filters -->
    <div class="filter-section">
        <h6 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Reports</h6>
        <form method="GET" class="row g-3" id="filterForm">
            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">

            <div class="col-md-2">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty_id" onchange="this.form.submit()">
                    <option value="0">All Faculties</option>
                    <?php foreach($faculties as $f): ?>
                    <option value="<?php echo $f['faculty_id']; ?>" <?php echo $faculty_id == $f['faculty_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f['faculty_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id" onchange="this.form.submit()">
                    <option value="0">All Departments</option>
                    <?php foreach($departments as $d): ?>
                    <option value="<?php echo $d['department_id']; ?>" <?php echo $department_id == $d['department_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($d['department_code'] . ' - ' . $d['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id" onchange="this.form.submit()">
                    <option value="0">All Programs</option>
                    <?php foreach($programs as $p): ?>
                    <option value="<?php echo $p['program_id']; ?>" <?php echo $program_id == $p['program_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['program_code'] . ' - ' . $p['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Session</label>
                <select class="form-select" name="session_year" onchange="this.form.submit()">
                    <option value="">All Sessions</option>
                    <?php foreach($sessions as $s): ?>
                    <option value="<?php echo $s['session_year']; ?>" <?php echo $session_year == $s['session_year'] ? 'selected' : ''; ?>>
                        <?php echo $s['session_year']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester" onchange="this.form.submit()">
                    <option value="0">All</option>
                    <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>1st</option>
                    <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>2nd</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-select" name="level" onchange="this.form.submit()">
                    <option value="0">All Levels</option>
                    <?php foreach($levels as $l): ?>
                    <option value="<?php echo $l; ?>" <?php echo $level == $l ? 'selected' : ''; ?>>
                        Level <?php echo $l; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <a href="academic_reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <?php if ($report_type == 'gpa_summary' && !empty($summary)): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Students</h6>
                            <h2 class="mb-0"><?php echo number_format($summary['total_students'] ?? 0); ?></h2>
                        </div>
                        <i class="fas fa-users fa-2x opacity-50"></i>
                    </div>
                    <small class="opacity-75">All registered students</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Overall GPA</h6>
                            <h2 class="mb-0"><?php echo number_format($summary['overall_avg_gpa'] ?? 0, 2); ?></h2>
                        </div>
                        <i class="fas fa-graduation-cap fa-2x opacity-50"></i>
                    </div>
                    <small class="opacity-75">Average across all records</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Sessions</h6>
                            <h2 class="mb-0"><?php echo number_format($summary['total_sessions'] ?? 0); ?></h2>
                        </div>
                        <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                    </div>
                    <small class="opacity-75">Active academic sessions</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Levels</h6>
                            <h2 class="mb-0"><?php echo number_format($summary['total_levels'] ?? 0); ?></h2>
                        </div>
                        <i class="fas fa-layer-group fa-2x opacity-50"></i>
                    </div>
                    <small class="opacity-75">Different class levels</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reports Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <?php if($report_type == 'gpa_summary'): ?>
                    <i class="fas fa-chart-pie me-2 text-primary"></i>GPA Summary Report
                <?php elseif($report_type == 'course_performance'): ?>
                    <i class="fas fa-book-open me-2 text-primary"></i>Course Performance Report
                <?php else: ?>
                    <i class="fas fa-user-graduate me-2 text-primary"></i>Student Performance Report
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 report-table">

                    <?php if($report_type == 'gpa_summary'): ?>
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Semester</th>
                            <th>Level</th>
                            <th>Department</th>
                            <th>Program</th>
                            <th class="text-center">Total Students</th>
                            <th class="text-center">Avg GPA</th>
                            <th class="text-center">First Class</th>
                            <th class="text-center">2nd Upper</th>
                            <th class="text-center">2nd Lower</th>
                            <th class="text-center">Third Class</th>
                            <th class="text-center">Pass</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): ?>
                        <tr>
                            <td><strong><?php echo $r['session_year']; ?></strong></td>
                            <td><?php echo $r['semester'] == 1 ? 'First' : 'Second'; ?></td>
                            <td><span class="badge bg-secondary">Level <?php echo $r['level']; ?></span></td>
                            <td><?php echo htmlspecialchars($r['department_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($r['program_name'] ?? 'N/A'); ?></td>
                            <td class="text-center">
                                <span class="badge bg-dark fs-6"><?php echo $r['student_count']; ?></span>
                            </td>
                            <td class="text-center">
                                <strong class="text-primary"><?php echo number_format($r['avg_gpa'], 2); ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success"><?php echo $r['first_class']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?php echo $r['second_upper']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?php echo $r['second_lower']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning"><?php echo $r['third_class']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $r['pass']; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <?php elseif($report_type == 'course_performance'): ?>
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle">Course</th>
                            <th rowspan="2" class="align-middle">Session</th>
                            <th rowspan="2" class="align-middle">Level</th>
                            <th rowspan="2" class="text-center align-middle">Students</th>
                            <th colspan="3" class="text-center">Average Scores</th>
                            <th rowspan="2" class="text-center align-middle">Pass Rate</th>
                            <th colspan="6" class="text-center">Grade Distribution</th>
                        </tr>
                        <tr>
                            <th class="text-center">CA</th>
                            <th class="text-center">Exam</th>
                            <th class="text-center">Total</th>
                            <th class="text-center text-success">A</th>
                            <th class="text-center text-primary">B</th>
                            <th class="text-center text-info">C</th>
                            <th class="text-center text-warning">D</th>
                            <th class="text-center text-secondary">E</th>
                            <th class="text-center text-danger">F</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): ?>
                        <tr>
                            <td>
                                <strong><?php echo $r['course_code']; ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($r['course_title']); ?></small>
                                <br><span class="badge bg-light text-dark"><?php echo $r['credit_units']; ?> units</span>
                            </td>
                            <td>
                                <?php echo $r['session_year']; ?>
                                <br><small class="text-muted"><?php echo $r['semester'] == 1 ? 'First Semester' : 'Second Semester'; ?></small>
                            </td>
                            <td>Level <?php echo $r['level']; ?></td>
                            <td class="text-center">
                                <span class="badge bg-dark"><?php echo $r['student_count']; ?></span>
                            </td>
                            <td class="text-center"><?php echo number_format($r['avg_ca'], 1); ?></td>
                            <td class="text-center"><?php echo number_format($r['avg_exam'], 1); ?></td>
                            <td class="text-center">
                                <strong class="text-primary"><?php echo number_format($r['avg_total'], 1); ?>%</strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $r['pass_rate'] >= 70 ? 'success' : ($r['pass_rate'] >= 50 ? 'warning' : 'danger'); ?>">
                                    <?php echo $r['pass_rate']; ?>%
                                </span>
                            </td>
                            <td class="text-center"><?php echo $r['grade_a']; ?></td>
                            <td class="text-center"><?php echo $r['grade_b']; ?></td>
                            <td class="text-center"><?php echo $r['grade_c']; ?></td>
                            <td class="text-center"><?php echo $r['grade_d']; ?></td>
                            <td class="text-center"><?php echo $r['grade_e']; ?></td>
                            <td class="text-center"><?php echo $r['grade_f']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <?php else: ?>
                    <thead>
                        <tr>
                            <th>Matric No</th>
                            <th>Student Name</th>
                            <th>Department</th>
                            <th>Program</th>
                            <th class="text-center">Semesters</th>
                            <th class="text-center">Credits</th>
                            <th class="text-center">Points</th>
                            <th class="text-center">CGPA</th>
                            <th class="text-center">Best GPA</th>
                            <th class="text-center">Classification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): 
                            list($class_name, $class_color) = getClassificationBadge($r['cgpa']);
                        ?>
                        <tr>
                            <td><code><?php echo $r['matric_number']; ?></code></td>
                            <td>
                                <a href="view_student_results.php?student_id=<?php echo $r['student_id'] ?? 0; ?>" class="text-decoration-none fw-bold">
                                    <?php echo htmlspecialchars(trim($r['student_name'])); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($r['department_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($r['program_name'] ?? 'N/A'); ?></td>
                            <td class="text-center"><?php echo $r['semesters_completed']; ?></td>
                            <td class="text-center"><?php echo $r['total_credits']; ?></td>
                            <td class="text-center"><?php echo number_format($r['total_points'], 2); ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $class_color; ?> fs-6"><?php echo number_format($r['cgpa'], 2); ?></span>
                            </td>
                            <td class="text-center"><?php echo number_format($r['best_gpa'], 2); ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $class_color; ?> classification-badge"><?php echo $class_name; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php if(!empty($reports)): ?>
        <div class="card-footer bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Showing <?php echo count($reports); ?> record(s)</span>
                <span class="text-muted">Generated on <?php echo date('M d, Y H:i'); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if(empty($reports)): ?>
    <div class="alert alert-info text-center py-5 mt-4">
        <i class="fas fa-info-circle fa-3x mb-3 d-block text-info"></i>
        <h5>No Data Found</h5>
        <p class="mb-0">Try adjusting your filters or ensure student records exist in the system.</p>
    </div>
    <?php endif; ?>
</div>

<script>
function exportReport() {
    window.location.href = 'export_academic_report.php?' + new URLSearchParams(window.location.search).toString();
}
</script>

<?php require_once 'includes/footer.php'; ?>