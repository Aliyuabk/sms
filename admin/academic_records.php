<?php
// academic_records.php - FIXED with proper column aliasing
ob_start();

require_once 'includes/header.php';

$page_title = "Academic Records";

// ==================== DATABASE CONNECTION ====================
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'student_portal_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// ==================== FILTER PARAMETERS ====================
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$session_year = isset($_GET['session_year']) ? trim($_GET['session_year']) : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$min_gpa = isset($_GET['min_gpa']) ? (float)$_GET['min_gpa'] : 0;
$max_gpa = isset($_GET['max_gpa']) ? (float)$_GET['max_gpa'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$records_per_page = 30;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// ==================== BUILD QUERY ====================
$where_conditions = [];
$params = [];

if ($student_id > 0) {
    $where_conditions[] = "ar.student_id = ?";
    $params[] = $student_id;
}

if (!empty($session_year)) {
    $where_conditions[] = "ar.session_year = ?";
    $params[] = $session_year;
}

if ($semester > 0) {
    $where_conditions[] = "ar.semester = ?";
    $params[] = $semester;
}

if ($level > 0) {
    $where_conditions[] = "ar.level = ?";
    $params[] = $level;
}

if ($department_id > 0) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $where_conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

if ($min_gpa > 0) {
    $where_conditions[] = "ar.gpa >= ?";
    $params[] = $min_gpa;
}

if ($max_gpa > 0) {
    $where_conditions[] = "ar.gpa <= ?";
    $params[] = $max_gpa;
}

if (!empty($search)) {
    $where_conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// ==================== GET TOTAL COUNT ====================
$count_sql = "
    SELECT COUNT(*) as total
    FROM academic_records ar
    JOIN students s ON ar.student_id = s.student_id
    $where_sql
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// ==================== GET ACADEMIC RECORDS ====================
$records_sql = "
    SELECT 
        ar.record_id,
        ar.student_id,
        ar.session_year,
        ar.semester,
        ar.level,
        ar.total_units,
        ar.total_points,
        ar.gpa,
        ar.calculated_by,
        ar.calculated_at,
        s.matric_number,
        s.first_name,
        s.last_name,
        s.middle_name,
        s.email as student_email,
        s.phone as student_phone,
        s.current_level as student_current_level,
        s.status as student_status,
        d.department_id,
        d.department_name,
        d.department_code,
        p.program_id,
        p.program_name,
        p.program_code,
        p.degree_type,
        a.full_name as calculated_by_name,
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name
    FROM academic_records ar
    JOIN students s ON ar.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN admin_users a ON ar.calculated_by = a.admin_id
    $where_sql
    ORDER BY ar.session_year DESC, ar.semester DESC, ar.calculated_at DESC
    LIMIT $offset, $records_per_page
";

$records_stmt = $pdo->prepare($records_sql);
$records_stmt->execute($params);
$academic_records = $records_stmt->fetchAll();

// ==================== GET FILTER DROPDOWN DATA ====================
$students = $pdo->query("
    SELECT student_id, matric_number, first_name, last_name 
    FROM students WHERE status = 'Active' 
    ORDER BY matric_number
")->fetchAll();

$departments = $pdo->query("
    SELECT department_id, department_name, department_code 
    FROM departments ORDER BY department_name
")->fetchAll();

$programs = $pdo->query("
    SELECT program_id, program_name, program_code 
    FROM programs WHERE is_active = 1 
    ORDER BY program_name
")->fetchAll();

$sessions = $pdo->query("
    SELECT DISTINCT session_year FROM academic_records 
    ORDER BY session_year DESC
")->fetchAll();

$levels = $pdo->query("
    SELECT DISTINCT level FROM academic_records 
    ORDER BY level
")->fetchAll();

// ==================== GET STATISTICS ====================
$stats_sql = "
    SELECT 
        COUNT(*) as total_records,
        COUNT(DISTINCT ar.student_id) as total_students,
        COUNT(DISTINCT ar.session_year) as total_sessions,
        AVG(ar.gpa) as avg_gpa,
        MAX(ar.gpa) as max_gpa,
        MIN(ar.gpa) as min_gpa,
        SUM(ar.total_units) as total_credits,
        SUM(ar.total_points) as total_points_sum
    FROM academic_records ar
    JOIN students s ON ar.student_id = s.student_id
    $where_sql
";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// ==================== GET PERFORMANCE DISTRIBUTION ====================
$distribution_sql = "
    SELECT 
        CASE 
            WHEN ar.gpa >= 4.5 THEN 'First Class (4.50-5.00)'
            WHEN ar.gpa >= 3.5 THEN 'Second Class Upper (3.50-4.49)'
            WHEN ar.gpa >= 2.5 THEN 'Second Class Lower (2.50-3.49)'
            WHEN ar.gpa >= 1.5 THEN 'Third Class (1.50-2.49)'
            ELSE 'Pass (0.00-1.49)'
        END as classification,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM academic_records ar2 $where_sql), 1) as percentage
    FROM academic_records ar
    JOIN students s ON ar.student_id = s.student_id
    $where_sql
    GROUP BY classification
    ORDER BY MIN(ar.gpa) DESC
";
$distribution_stmt = $pdo->prepare($distribution_sql);
$distribution_stmt->execute($params);
$distribution = $distribution_stmt->fetchAll();

// ==================== HELPER FUNCTIONS ====================
function getGpaClass($gpa) {
    if ($gpa >= 4.5) return ['First Class', 'success'];
    if ($gpa >= 3.5) return ['Second Class Upper', 'primary'];
    if ($gpa >= 2.5) return ['Second Class Lower', 'info'];
    if ($gpa >= 1.5) return ['Third Class', 'warning'];
    return ['Pass', 'secondary'];
}

function getSemesterName($semester) {
    return $semester == 1 ? 'First Semester' : 'Second Semester';
}

// ==================== HANDLE DELETE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    try {
        $record_id = (int)$_POST['record_id'];
        
        $delete_stmt = $pdo->prepare("DELETE FROM academic_records WHERE record_id = ?");
        $delete_stmt->execute([$record_id]);
        
        $_SESSION['success_message'] = "Academic record deleted successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error deleting record: " . $e->getMessage();
    }
    
    header("Location: academic_records.php");
    exit();
}

// ==================== HANDLE EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $export_sql = "
        SELECT 
            ar.record_id,
            ar.session_year,
            CASE WHEN ar.semester = 1 THEN 'First Semester' ELSE 'Second Semester' END as semester_name,
            ar.level,
            ar.total_units,
            ar.total_points,
            ar.gpa,
            ar.calculated_at,
            s.matric_number,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
            d.department_name,
            p.program_name,
            CASE 
                WHEN ar.gpa >= 4.5 THEN 'First Class'
                WHEN ar.gpa >= 3.5 THEN 'Second Class Upper'
                WHEN ar.gpa >= 2.5 THEN 'Second Class Lower'
                WHEN ar.gpa >= 1.5 THEN 'Third Class'
                ELSE 'Pass'
            END as classification
        FROM academic_records ar
        JOIN students s ON ar.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        $where_sql
        ORDER BY ar.session_year DESC, ar.semester DESC
    ";
    
    $export_stmt = $pdo->prepare($export_sql);
    $export_stmt->execute($params);
    $export_data = $export_stmt->fetchAll();
    
    if (!empty($export_data)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="academic_records_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'Record ID', 'Session', 'Semester', 'Level', 'Total Credits', 
            'Total Points', 'GPA', 'Classification', 'Student Matric', 
            'Student Name', 'Department', 'Program', 'Calculated On'
        ]);
        
        // Data rows
        foreach ($export_data as $row) {
            fputcsv($output, [
                $row['record_id'],
                $row['session_year'],
                $row['semester_name'],
                $row['level'],
                $row['total_units'],
                $row['total_points'],
                $row['gpa'],
                $row['classification'],
                $row['matric_number'],
                $row['student_name'],
                $row['department_name'] ?? 'N/A',
                $row['program_name'] ?? 'N/A',
                date('Y-m-d H:i', strtotime($row['calculated_at']))
            ]);
        }
        
        fclose($output);
        exit();
    }
}

// Build query string helper
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Records - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .stat-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .table-records tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        .gpa-badge {
            font-size: 1rem;
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .classification-first { background-color: #28a745; color: white; }
        .classification-upper { background-color: #17a2b8; color: white; }
        .classification-lower { background-color: #ffc107; color: #212529; }
        .classification-third { background-color: #fd7e14; color: white; }
        .classification-pass { background-color: #6c757d; color: white; }
        .filter-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
        }
        .distribution-item {
            margin-bottom: 10px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid px-4 py-3">
        
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="app-page-title mb-1">
                    <i class="fas fa-chalkboard me-2 text-primary"></i>Academic Records
                </h1>
                <p class="text-muted mb-0">View and manage all student GPA/CGPA records across sessions</p>
            </div>
            <div class="d-flex gap-2 no-print">
                <a href="calculate_gpa.php" class="btn btn-primary">
                    <i class="fas fa-calculator me-2"></i>Calculate GPA
                </a>
                <a href="?export=csv&<?php echo buildQueryString(['export']); ?>" class="btn btn-success">
                    <i class="fas fa-file-excel me-2"></i>Export CSV
                </a>
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Records</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['total_records'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-database fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Students</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['total_students'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-users fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Average GPA</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['avg_gpa'] ?? 0, 2); ?></h2>
                            </div>
                            <i class="fas fa-chart-line fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Credits</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['total_credits'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-book fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Performance Distribution -->
        <?php if (!empty($distribution)): ?>
        <div class="card shadow-sm mb-4 no-print">
            <div class="card-header bg-transparent">
                <h5 class="mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Performance Distribution</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($distribution as $dist): ?>
                    <div class="col-md-<?php echo count($distribution) == 5 ? '2' : (count($distribution) == 4 ? '3' : '4'); ?> distribution-item">
                        <div class="d-flex justify-content-between mb-1">
                            <small><?php echo $dist['classification']; ?></small>
                            <small class="fw-bold"><?php echo $dist['count']; ?> (<?php echo $dist['percentage']; ?>%)</small>
                        </div>
                        <div class="progress progress-bar-custom">
                            <div class="progress-bar bg-<?php 
                                if (strpos($dist['classification'], 'First') !== false) echo 'success';
                                elseif (strpos($dist['classification'], 'Upper') !== false) echo 'info';
                                elseif (strpos($dist['classification'], 'Lower') !== false) echo 'warning';
                                elseif (strpos($dist['classification'], 'Third') !== false) echo 'orange';
                                else echo 'secondary';
                            ?>" style="width: <?php echo $dist['percentage']; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="filter-section no-print">
            <h6 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Records</h6>
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search Student</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Matric, First or Last name">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Session</label>
                    <select class="form-select" name="session_year">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo htmlspecialchars($session['session_year']); ?>" 
                                <?php echo $session_year == $session['session_year'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($session['session_year']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">Sem</label>
                    <select class="form-select" name="semester">
                        <option value="0">All</option>
                        <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>1st</option>
                        <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>2nd</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="0">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" 
                                <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Program</label>
                    <select class="form-select" name="program_id">
                        <option value="0">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                        <option value="<?php echo $prog['program_id']; ?>" 
                                <?php echo $program_id == $prog['program_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prog['program_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">GPA Range</label>
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control" name="min_gpa" 
                               placeholder="Min" value="<?php echo $min_gpa > 0 ? $min_gpa : ''; ?>">
                        <span class="input-group-text">-</span>
                        <input type="number" step="0.01" class="form-control" name="max_gpa" 
                               placeholder="Max" value="<?php echo $max_gpa > 0 ? $max_gpa : ''; ?>">
                    </div>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Apply Filters
                    </button>
                    <a href="academic_records.php" class="btn btn-outline-secondary ms-2">
                        <i class="fas fa-redo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Active Filters Display -->
        <?php if ($student_id > 0 || !empty($session_year) || $semester > 0 || $department_id > 0 || $program_id > 0 || $min_gpa > 0 || $max_gpa > 0 || !empty($search)): ?>
        <div class="mb-3">
            <span class="text-muted small me-2">Active filters:</span>
            <?php if (!empty($search)): ?>
                <span class="badge bg-secondary me-1 p-2">Search: <?php echo htmlspecialchars($search); ?></span>
            <?php endif; ?>
            <?php if (!empty($session_year)): ?>
                <span class="badge bg-secondary me-1 p-2">Session: <?php echo htmlspecialchars($session_year); ?></span>
            <?php endif; ?>
            <?php if ($semester > 0): ?>
                <span class="badge bg-secondary me-1 p-2">Semester: <?php echo $semester == 1 ? '1st' : '2nd'; ?></span>
            <?php endif; ?>
            <?php if ($department_id > 0): ?>
                <span class="badge bg-secondary me-1 p-2">Dept ID: <?php echo $department_id; ?></span>
            <?php endif; ?>
            <?php if ($min_gpa > 0 || $max_gpa > 0): ?>
                <span class="badge bg-secondary me-1 p-2">GPA: <?php echo $min_gpa > 0 ? $min_gpa : '0'; ?> - <?php echo $max_gpa > 0 ? $max_gpa : '5'; ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Academic Records Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="fas fa-table me-2 text-primary"></i>Academic Records</h5>
                <span class="text-muted small">
                    Showing <?php echo count($academic_records); ?> of <?php echo number_format($total_records); ?> records
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-records align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Program/Dept</th>
                                <th>Session</th>
                                <th class="text-center">Sem</th>
                                <th class="text-center">Level</th>
                                <th class="text-center">Credits</th>
                                <th class="text-center">Points</th>
                                <th class="text-center">GPA</th>
                                <th>Classification</th>
                                <th class="text-center">Calculated</th>
                                <th width="80" class="text-center no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($academic_records)): ?>
                                <?php foreach ($academic_records as $record): ?>
                                <?php $classification = getGpaClass($record['gpa']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <a href="view_student_results.php?student_id=<?php echo $record['student_id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($record['student_name']); ?>
                                            </a>
                                        </div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($record['matric_number']); ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?php echo htmlspecialchars($record['program_code'] ?? 'N/A'); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($record['department_code'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['session_year']); ?></td>
                                    <td class="text-center"><?php echo $record['semester'] == 1 ? '1st' : '2nd'; ?>
                                                                        <td class="text-center"><?php echo $record['semester'] == 1 ? '1st' : '2nd'; ?></td>
                                    <td class="text-center">Level <?php echo $record['level']; ?></td>
                                    <td class="text-center"><?php echo $record['total_units']; ?></td>
                                    <td class="text-center"><?php echo number_format($record['total_points'], 2); ?></td>
                                    <td class="text-center">
                                        <span class="gpa-badge classification-<?php 
                                            if ($classification[0] == 'First Class') echo 'first';
                                            elseif ($classification[0] == 'Second Class Upper') echo 'upper';
                                            elseif ($classification[0] == 'Second Class Lower') echo 'lower';
                                            elseif ($classification[0] == 'Third Class') echo 'third';
                                            else echo 'pass';
                                        ?>">
                                            <?php echo number_format($record['gpa'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $classification[1]; ?>">
                                            <?php echo $classification[0]; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <small><?php echo date('d/m/Y', strtotime($record['calculated_at'])); ?></small>
                                        <br><small class="text-muted">by <?php echo htmlspecialchars($record['calculated_by_name'] ?? 'System'); ?></small>
                                    </td>
                                    <td class="text-center no-print">
                                        <div class="btn-group btn-group-sm">
                                            <a href="view_student_results.php?student_id=<?php echo $record['student_id']; ?>" 
                                               class="btn btn-outline-info" title="View Student">
                                                <i class="fas fa-user-graduate"></i>
                                            </a>
                                            <button onclick="deleteRecord(<?php echo $record['record_id']; ?>)" 
                                                    class="btn btn-outline-danger" title="Delete Record">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                <tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        <h5>No academic records found</h5>
                                        <p class="text-muted">No GPA/CGPA records match your filters or no records have been calculated yet.</p>
                                        <a href="calculate_gpa.php" class="btn btn-primary mt-2">
                                            <i class="fas fa-calculator me-2"></i>Calculate GPA Now
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent no-print">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&<?php echo buildQueryString(['page']); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=1&<?php echo buildQueryString(['page']); ?>">1</a></li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo buildQueryString(['page']); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&<?php echo buildQueryString(['page']); ?>"><?php echo $total_pages; ?></a></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&<?php echo buildQueryString(['page']); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Summary Footer -->
        <?php if (!empty($academic_records)): ?>
        <div class="mt-3 text-muted small text-center">
            <i class="fas fa-info-circle me-1"></i>
            Showing academic records from <?php echo count($academic_records); ?> entry(ies). 
            Total credits earned: <?php echo number_format($stats['total_credits'] ?? 0); ?> | 
            Total grade points: <?php echo number_format($stats['total_points_sum'] ?? 0, 2); ?>
        </div>
        <?php endif; ?>
        
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Delete Academic Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this academic record?</p>
                    <p class="text-danger mb-0"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="record_id" id="deleteRecordId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_record" class="btn btn-danger">Delete Record</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteRecord(recordId) {
            document.getElementById('deleteRecordId').value = recordId;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Optional: Auto-submit on filter change for better UX
        // Uncomment if you want auto-submit when changing select dropdowns
        /*
        document.querySelectorAll('.filter-section select').forEach(select => {
            select.addEventListener('change', () => {
                document.querySelector('.filter-section form').submit();
            });
        });
        */
    </script>
</body>
</html>

<?php
require_once 'includes/footer.php';
?>