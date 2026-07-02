<?php
// calculate_gpa.php - COMPLETED WITH DATABASE INTEGRATION
ob_start();

require_once 'includes/header.php';

$page_title = "Calculate GPA/CGPA";

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

// ==================== HELPER FUNCTIONS ====================

function calculateGradePoints($grade, $pdo) {
    static $grade_points = null;
    
    if ($grade_points === null) {
        $stmt = $pdo->query("SELECT grade, grade_points FROM grade_scale");
        $grade_points = [];
        while ($row = $stmt->fetch()) {
            $grade_points[$row['grade']] = $row['grade_points'];
        }
    }
    
    return $grade_points[$grade] ?? 0;
}

function getGradeClassification($gpa) {
    if ($gpa >= 4.5) return ['First Class Honours', 'success'];
    if ($gpa >= 3.5) return ['Second Class Upper', 'primary'];
    if ($gpa >= 2.5) return ['Second Class Lower', 'info'];
    if ($gpa >= 1.5) return ['Third Class', 'warning'];
    return ['Pass', 'secondary'];
}

// Handle GPA calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_gpa'])) {
    try {
        $student_id = (int)$_POST['student_id'];
        $session_year = trim($_POST['session_year']);
        $semester = (int)$_POST['semester'];
        $admin_id = $_SESSION['admin_id'] ?? 1;
        
        if (!$student_id || !$session_year || !$semester) {
            throw new Exception('All fields are required.');
        }
        
        // Get student's results for the session/semester
        $results_stmt = $pdo->prepare("
            SELECT 
                r.*,
                c.course_code,
                c.course_title,
                c.credit_units
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ? 
            AND r.session_year = ?
            AND r.semester = ?
            AND r.is_published = 1
        ");
        $results_stmt->execute([$student_id, $session_year, $semester]);
        $results = $results_stmt->fetchAll();
        
        if (empty($results)) {
            throw new Exception('No published results found for the selected session/semester.');
        }
        
        // Calculate GPA
        $total_points = 0;
        $total_units = 0;
        
        foreach ($results as $result) {
            $total_points += $result['grade_points'] * $result['credit_units'];
            $total_units += $result['credit_units'];
        }
        
        $gpa = $total_units > 0 ? round($total_points / $total_units, 2) : 0;
        $level = $results[0]['level'] ?? 100;
        
        // Check if academic record already exists
        $check_stmt = $pdo->prepare("
            SELECT record_id FROM academic_records 
            WHERE student_id = ? AND session_year = ? AND semester = ?
        ");
        $check_stmt->execute([$student_id, $session_year, $semester]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            // Update existing record
            $update_stmt = $pdo->prepare("
                UPDATE academic_records SET 
                    level = ?,
                    total_units = ?,
                    total_points = ?,
                    gpa = ?,
                    calculated_by = ?,
                    calculated_at = NOW()
                WHERE record_id = ?
            ");
            $update_stmt->execute([
                $level, $total_units, $total_points, $gpa, $admin_id, $existing['record_id']
            ]);
            $record_id = $existing['record_id'];
            $message = "GPA recalculated and updated successfully!";
        } else {
            // Create new record
            $insert_stmt = $pdo->prepare("
                INSERT INTO academic_records 
                (student_id, session_year, semester, level, 
                 total_units, total_points, gpa, calculated_by, calculated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->execute([
                $student_id, $session_year, $semester, $level,
                $total_units, $total_points, $gpa, $admin_id
            ]);
            $record_id = $pdo->lastInsertId();
            $message = "GPA calculated successfully!";
        }
        
        $_SESSION['gpa_result'] = [
            'student_id' => $student_id,
            'session_year' => $session_year,
            'semester' => $semester,
            'total_units' => $total_units,
            'total_points' => $total_points,
            'gpa' => $gpa,
            'level' => $level,
            'results' => $results,
            'record_id' => $record_id
        ];
        
        $_SESSION['success_message'] = $message;
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error calculating GPA: " . $e->getMessage();
    }
    
    header("Location: calculate_gpa.php");
    exit();
}

// Handle CGPA calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_cgpa'])) {
    try {
        $student_id = (int)$_POST['student_id'];
        $admin_id = $_SESSION['admin_id'] ?? 1;
        
        if (!$student_id) {
            throw new Exception('Please select a student.');
        }
        
        // Get all academic records for the student
        $records_stmt = $pdo->prepare("
            SELECT * FROM academic_records 
            WHERE student_id = ?
            ORDER BY session_year, semester
        ");
        $records_stmt->execute([$student_id]);
        $records = $records_stmt->fetchAll();
        
        if (empty($records)) {
            throw new Exception('No academic records found. Please calculate GPA for at least one semester first.');
        }
        
        // Calculate CGPA
        $total_points_all = 0;
        $total_units_all = 0;
        
        foreach ($records as $record) {
            $total_points_all += $record['total_points'];
            $total_units_all += $record['total_units'];
        }
        
        $cgpa = $total_units_all > 0 ? round($total_points_all / $total_units_all, 2) : 0;
        
        // Get student's current CGPA
        $student_stmt = $pdo->prepare("SELECT cgpa FROM students WHERE student_id = ?");
        $student_stmt->execute([$student_id]);
        $previous_cgpa = $student_stmt->fetchColumn();
        
        // Update student's CGPA
        $update_stmt = $pdo->prepare("UPDATE students SET cgpa = ? WHERE student_id = ?");
        $update_stmt->execute([$cgpa, $student_id]);
        
        $_SESSION['cgpa_result'] = [
            'student_id' => $student_id,
            'total_sessions' => count($records),
            'total_units_all' => $total_units_all,
            'total_points_all' => $total_points_all,
            'cgpa' => $cgpa,
            'previous_cgpa' => $previous_cgpa,
            'records' => $records
        ];
        
        $_SESSION['success_message'] = "CGPA calculated and updated successfully! New CGPA: " . number_format($cgpa, 2);
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error calculating CGPA: " . $e->getMessage();
    }
    
    header("Location: calculate_gpa.php");
    exit();
}

// Handle batch calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_calculate'])) {
    try {
        $session_year = trim($_POST['batch_session_year']);
        $semester = (int)$_POST['batch_semester'];
        $department_id = isset($_POST['batch_department']) ? (int)$_POST['batch_department'] : 0;
        $level = isset($_POST['batch_level']) ? (int)$_POST['batch_level'] : 0;
        $admin_id = $_SESSION['admin_id'] ?? 1;
        
        if (!$session_year || !$semester) {
            throw new Exception('Session year and semester are required.');
        }
        
        // Build query to get students with results but no GPA record
        $query = "
            SELECT DISTINCT r.student_id, s.current_level
            FROM results r
            JOIN students s ON r.student_id = s.student_id
            WHERE r.session_year = ? 
            AND r.semester = ?
            AND r.is_published = 1
            AND NOT EXISTS (
                SELECT 1 FROM academic_records ar
                WHERE ar.student_id = r.student_id 
                AND ar.session_year = r.session_year 
                AND ar.semester = r.semester
            )
        ";
        
        $params = [$session_year, $semester];
        
        if ($department_id > 0) {
            $query .= " AND s.department_id = ?";
            $params[] = $department_id;
        }
        
        if ($level > 0) {
            $query .= " AND s.current_level = ?";
            $params[] = $level;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll();
        
        if (empty($students)) {
            throw new Exception('No students found without GPA calculation for the selected criteria.');
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        foreach ($students as $student) {
            try {
                $student_id = $student['student_id'];
                $student_level = $student['current_level'];
                
                // Get student's results
                $results_stmt = $pdo->prepare("
                    SELECT r.*, c.credit_units
                    FROM results r
                    JOIN courses c ON r.course_id = c.course_id
                    WHERE r.student_id = ? 
                    AND r.session_year = ?
                    AND r.semester = ?
                    AND r.is_published = 1
                ");
                $results_stmt->execute([$student_id, $session_year, $semester]);
                $results = $results_stmt->fetchAll();
                
                if (empty($results)) continue;
                
                // Calculate GPA
                $total_points = 0;
                $total_units = 0;
                
                foreach ($results as $result) {
                    $total_points += $result['grade_points'] * $result['credit_units'];
                    $total_units += $result['credit_units'];
                }
                
                $gpa = $total_units > 0 ? round($total_points / $total_units, 2) : 0;
                
                // Create academic record
                $insert_stmt = $pdo->prepare("
                    INSERT INTO academic_records 
                    (student_id, session_year, semester, level, 
                     total_units, total_points, gpa, calculated_by, calculated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $insert_stmt->execute([
                    $student_id, $session_year, $semester, $student_level,
                    $total_units, $total_points, $gpa, $admin_id
                ]);
                
                $success_count++;
                
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Student ID {$student['student_id']}: " . $e->getMessage();
            }
        }
        
        $_SESSION['batch_result'] = [
            'total_students' => count($students),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'session_year' => $session_year,
            'semester' => $semester
        ];
        
        if (!empty($errors)) {
            $_SESSION['batch_errors'] = $errors;
        }
        
        $_SESSION['success_message'] = "Batch calculation completed: $success_count successful, $error_count failed.";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error in batch calculation: " . $e->getMessage();
    }
    
    header("Location: calculate_gpa.php");
    exit();
}

// Handle GPA recalculation for all students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_all'])) {
    try {
        $session_year = trim($_POST['recalc_session_year']);
        $semester = (int)$_POST['recalc_semester'];
        $admin_id = $_SESSION['admin_id'] ?? 1;
        
        if (!$session_year || !$semester) {
            throw new Exception('Session year and semester are required.');
        }
        
        // Get all students with results for this session/semester
        $stmt = $pdo->prepare("
            SELECT DISTINCT r.student_id, s.current_level
            FROM results r
            JOIN students s ON r.student_id = s.student_id
            WHERE r.session_year = ? AND r.semester = ? AND r.is_published = 1
        ");
        $stmt->execute([$session_year, $semester]);
        $students = $stmt->fetchAll();
        
        if (empty($students)) {
            throw new Exception('No published results found for the selected session/semester.');
        }
        
        $updated_count = 0;
        
        foreach ($students as $student) {
            $student_id = $student['student_id'];
            $student_level = $student['current_level'];
            
            // Get results and recalculate
            $results_stmt = $pdo->prepare("
                SELECT r.*, c.credit_units
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1
            ");
            $results_stmt->execute([$student_id, $session_year, $semester]);
            $results = $results_stmt->fetchAll();
            
            if (empty($results)) continue;
            
            $total_points = 0;
            $total_units = 0;
            
            foreach ($results as $result) {
                $total_points += $result['grade_points'] * $result['credit_units'];
                $total_units += $result['credit_units'];
            }
            
            $gpa = $total_units > 0 ? round($total_points / $total_units, 2) : 0;
            
            // Update or insert academic record
            $check_stmt = $pdo->prepare("
                SELECT record_id FROM academic_records 
                WHERE student_id = ? AND session_year = ? AND semester = ?
            ");
            $check_stmt->execute([$student_id, $session_year, $semester]);
            
            if ($check_stmt->fetch()) {
                $update_stmt = $pdo->prepare("
                    UPDATE academic_records SET 
                        total_units = ?, total_points = ?, gpa = ?, 
                        calculated_by = ?, calculated_at = NOW()
                    WHERE student_id = ? AND session_year = ? AND semester = ?
                ");
                $update_stmt->execute([$total_units, $total_points, $gpa, $admin_id, $student_id, $session_year, $semester]);
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO academic_records 
                    (student_id, session_year, semester, level, total_units, total_points, gpa, calculated_by, calculated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->execute([$student_id, $session_year, $semester, $student_level, $total_units, $total_points, $gpa, $admin_id]);
            }
            
            $updated_count++;
            
            // Update student CGPA
            $records_stmt = $pdo->prepare("
                SELECT SUM(total_points) as total_points, SUM(total_units) as total_units 
                FROM academic_records WHERE student_id = ?
            ");
            $records_stmt->execute([$student_id]);
            $totals = $records_stmt->fetch();
            
            if ($totals && $totals['total_units'] > 0) {
                $cgpa = round($totals['total_points'] / $totals['total_units'], 2);
                $update_cgpa = $pdo->prepare("UPDATE students SET cgpa = ? WHERE student_id = ?");
                $update_cgpa->execute([$cgpa, $student_id]);
            }
        }
        
        $_SESSION['success_message'] = "Recalculation completed! Updated $updated_count student records for $session_year Semester $semester.";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error during recalculation: " . $e->getMessage();
    }
    
    header("Location: calculate_gpa.php");
    exit();
}

// Get data for dropdowns
$students = $pdo->query("
    SELECT s.*, d.department_name, d.department_code
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.status = 'Active'
    ORDER BY s.matric_number
")->fetchAll();

$departments = $pdo->query("
    SELECT d.*, f.faculty_name 
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    ORDER BY d.department_name
")->fetchAll();

$academic_sessions = $pdo->query("
    SELECT session_year, session_name, status 
    FROM academic_sessions 
    ORDER BY session_year DESC
")->fetchAll();

$levels = [100, 200, 300, 400, 500, 600];
$current_year = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculate GPA/CGPA - Student Portal</title>
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
        .stats-figure {
            font-size: 2rem;
            font-weight: bold;
        }
        .gpa-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: bold;
            margin: 0 auto;
        }
        .gpa-excellent { background: linear-gradient(135deg, #11998e, #38ef7d); color: white; }
        .gpa-good { background: linear-gradient(135deg, #4facfe, #00f2fe); color: white; }
        .gpa-average { background: linear-gradient(135deg, #f6d365, #fda085); color: white; }
        .gpa-poor { background: linear-gradient(135deg, #f093fb, #f5576c); color: white; }
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 2px solid #0d6efd;
        }
        .table-course:hover {
            background-color: rgba(0,0,0,0.02);
        }
        .badge-grade-A { background-color: #28a745; }
        .badge-grade-B { background-color: #17a2b8; }
        .badge-grade-C { background-color: #ffc107; color: #212529; }
        .badge-grade-D { background-color: #fd7e14; }
        .badge-grade-E { background-color: #6c757d; }
        .badge-grade-F { background-color: #dc3545; }
    </style>
</head>
<body>
    <div class="container-fluid px-4 py-3">
        
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="app-page-title mb-1">
                    <i class="fas fa-calculator me-2 text-primary"></i>GPA / CGPA Calculator
                </h1>
                <p class="text-muted mb-0">Calculate Grade Point Average (GPA) and Cumulative GPA (CGPA) for students</p>
            </div>
            <div>
                <a href="academic_records.php" class="btn btn-outline-primary">
                    <i class="fas fa-history me-2"></i>View Academic Records
                </a>
            </div>
        </div>
        
        <!-- Display Messages -->
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
        
        <?php if (isset($_SESSION['batch_errors'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <h6><i class="fas fa-exclamation-triangle me-2"></i>Batch Calculation Errors:</h6>
                <ul class="mb-0 small" style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($_SESSION['batch_errors'] as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['batch_errors']); ?>
        <?php endif; ?>
        
        <!-- Tabs for different calculation methods -->
        <ul class="nav nav-tabs mb-4" id="calcTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="individual-tab" data-bs-toggle="tab" data-bs-target="#individual" type="button" role="tab">
                    <i class="fas fa-user me-2"></i>Individual Student
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="batch-tab" data-bs-toggle="tab" data-bs-target="#batch" type="button" role="tab">
                    <i class="fas fa-users me-2"></i>Batch Calculation
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="recalc-tab" data-bs-toggle="tab" data-bs-target="#recalc" type="button" role="tab">
                    <i class="fas fa-sync-alt me-2"></i>Recalculate All
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cgpa-tab" data-bs-toggle="tab" data-bs-target="#cgpa" type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i>CGPA Calculation
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="calcTabsContent">
            
            <!-- Individual GPA Calculation -->
            <div class="tab-pane fade show active" id="individual" role="tabpanel">
                <div class="row">
                    <div class="col-md-5 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Calculate GPA</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="gpaForm">
                                    <input type="hidden" name="calculate_gpa" value="1">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Select Student *</label>
                                        <select class="form-select" name="student_id" id="studentSelect" required>
                                            <option value="">-- Select Student --</option>
                                            <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['student_id']; ?>">
                                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                                <?php if ($student['cgpa']): ?> (CGPA: <?php echo number_format($student['cgpa'], 2); ?>)<?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Session Year *</label>
                                            <select class="form-select" name="session_year" required>
                                                <option value="">Select Session</option>
                                                <?php 
                                                for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                                    $session = ($i) . '/' . ($i + 1);
                                                ?>
                                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Semester *</label>
                                            <select class="form-select" name="semester" required>
                                                <option value="1">First Semester</option>
                                                <option value="2">Second Semester</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info small">
                                        <i class="fas fa-info-circle me-2"></i>
                                        This will calculate GPA based on published results for the selected session/semester.
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-calculator me-2"></i>Calculate GPA
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-7 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2 text-info"></i>About GPA Calculation</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Formula:</h6>
                                        <p class="small text-muted">GPA = Σ (Grade Points × Credit Units) / Σ Credit Units</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Grade Scale:</h6>
                                        <table class="table table-sm small">
                                            <thead>
                                                <tr><th>Grade</th><th>Score Range</th><th>Points</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $scale = $pdo->query("SELECT grade, min_score, max_score, grade_points FROM grade_scale ORDER BY min_score DESC")->fetchAll();
                                                foreach ($scale as $g): 
                                                ?>
                                                <tr>
                                                    <td><span class="badge bg-secondary"><?php echo $g['grade']; ?></span></td>
                                                    <td><?php echo $g['min_score']; ?> - <?php echo $g['max_score']; ?></td>
                                                    <td><?php echo $g['grade_points']; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Batch Calculation -->
            <div class="tab-pane fade" id="batch" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent">
                        <h5 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>Batch GPA Calculation</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="batchForm">
                            <input type="hidden" name="batch_calculate" value="1">
                            
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Session Year *</label>
                                    <select class="form-select" name="batch_session_year" required>
                                        <option value="">Select Session</option>
                                        <?php 
                                        for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                            $session = ($i) . '/' . ($i + 1);
                                        ?>
                                        <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label fw-bold">Semester *</label>
                                    <select class="form-select" name="batch_semester" required>
                                        <option value="1">First</option>
                                        <option value="2">Second</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Department (Optional)</label>
                                    <select class="form-select" name="batch_department">
                                        <option value="0">All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['department_id']; ?>">
                                            <?php echo htmlspecialchars($dept['department_code'] . ' - ' . $dept['department_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label fw-bold">Level (Optional)</label>
                                    <select class="form-select" name="batch_level">
                                        <option value="0">All Levels</option>
                                        <?php foreach ($levels as $lvl): ?>
                                        <option value="<?php echo $lvl; ?>">Level <?php echo $lvl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="fas fa-bolt me-2"></i>Batch Calculate
                                    </button>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mt-3 small">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                This will calculate GPA for all students in the selected criteria who have published results but no GPA record yet.
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Recalculate All -->
            <div class="tab-pane fade" id="recalc" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent">
                        <h5 class="mb-0"><i class="fas fa-sync-alt me-2 text-warning"></i>Recalculate All GPA Records</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="recalcForm">
                            <input type="hidden" name="recalculate_all" value="1">
                            
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Session Year *</label>
                                    <select class="form-select" name="recalc_session_year" required>
                                        <option value="">Select Session</option>
                                        <?php 
                                        for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                            $session = ($i) . '/' . ($i + 1);
                                        ?>
                                        <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Semester *</label>
                                    <select class="form-select" name="recalc_semester" required>
                                        <option value="1">First Semester</option>
                                        <option value="2">Second Semester</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('This will recalculate GPA for ALL students in this session/semester. Continue?')">
                                        <i class="fas fa-sync-alt me-2"></i>Recalculate All
                                    </button>
                                </div>
                            </div>
                            
                            <div class="alert alert-danger mt-3 small">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> This will recalculate GPA for all students in the selected session/semester based on their current results.
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- CGPA Calculation -->
            <div class="tab-pane fade" id="cgpa" role="tabpanel">
                <div class="row">
                    <div class="col-md-5 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-success"></i>Calculate CGPA</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="cgpaForm">
                                    <input type="hidden" name="calculate_cgpa" value="1">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Select Student *</label>
                                        <select class="form-select" name="student_id" id="cgpaStudentSelect" required>
                                            <option value="">-- Select Student --</option>
                                            <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['student_id']; ?>">
                                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                                <?php if ($student['cgpa']): ?> (Current: <?php echo number_format($student['cgpa'], 2); ?>)<?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="alert alert-info small">
                                        <i class="fas fa-info-circle me-2"></i>
                                        This will calculate CGPA based on all academic records and update the student's profile.
                                    </div>
                                    
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-chart-line me-2"></i>Calculate & Update CGPA
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-7 mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0"><i class="fas fa-graduation-cap me-2 text-success"></i>Understanding CGPA</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Formula:</h6>
                                        <p class="small text-muted">CGPA = Σ (GPA × Credits per Semester) / Σ Total Credits</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Classification Scale:</h6>
                                        <ul class="small">
                                            <li><span class="badge bg-success">4.50 - 5.00</span> First Class Honours</li>
                                            <li><span class="badge bg-primary">3.50 - 4.49</span> Second Class Upper</li>
                                            <li><span class="badge bg-info">2.50 - 3.49</span> Second Class Lower</li>
                                            <li><span class="badge bg-warning">1.50 - 2.49</span> Third Class</li>
                                            <li><span class="badge bg-secondary">0.00 - 1.49</span> Pass</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GPA Result Display -->
        <?php if (isset($_SESSION['gpa_result'])): 
            $gpa_result = $_SESSION['gpa_result'];
            $classification = getGradeClassification($gpa_result['gpa']);
        ?>
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>GPA Calculation Result</h5>
            </div>
            <div class="card-body">
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-light text-center">
                            <div class="card-body">
                                <div class="stats-type">Total Units</div>
                                <div class="stats-figure text-primary"><?php echo $gpa_result['total_units']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light text-center">
                            <div class="card-body">
                                <div class="stats-type">Total Points</div>
                                <div class="stats-figure text-success"><?php echo number_format($gpa_result['total_points'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light text-center">
                            <div class="card-body">
                                <div class="stats-type">GPA</div>
                                <div class="stats-figure text-danger"><?php echo number_format($gpa_result['gpa'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white <?php echo $classification[1] == 'success' ? 'bg-success' : ($classification[1] == 'primary' ? 'bg-primary' : ($classification[1] == 'info' ? 'bg-info' : ($classification[1] == 'warning' ? 'bg-warning' : 'bg-secondary'))); ?> text-center">
                            <div class="card-body">
                                <div class="stats-type">Classification</div>
                                <div class="stats-figure small"><?php echo $classification[0]; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Detailed Results Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th class="text-center">Credits</th>
                                <th class="text-center">CA</th>
                                <th class="text-center">Exam</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Grade</th>
                                <th class="text-center">Points</th>
                                <th class="text-center">Quality Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gpa_result['results'] as $result): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($result['course_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars(substr($result['course_title'], 0, 40)); ?></td>
                                <td class="text-center"><?php echo $result['credit_units']; ?></td>
                                <td class="text-center"><?php echo $result['ca_score']; ?></td>
                                <td class="text-center"><?php echo $result['exam_score']; ?></td>
                                <td class="text-center"><strong><?php echo $result['total_score']; ?>%</strong></td>
                                <td class="text-center">
                                    <span class="badge badge-grade-<?php echo $result['grade']; ?>"><?php echo $result['grade']; ?></span>
                                </td>
                                <td class="text-center"><?php echo number_format($result['grade_points'], 2); ?></td>
                                <td class="text-center">
                                    <strong><?php echo number_format($result['grade_points'] * $result['credit_units'], 2); ?></strong>
                                    <br><small class="text-muted">(<?php echo $result['grade_points']; ?> × <?php echo $result['credit_units']; ?>)</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2"><strong>Total</strong></td>
                                <td class="text-center"><?php echo $gpa_result['total_units']; ?></td>
                                <td colspan="4"></td>
                                <td class="text-center">
                                    <?php echo number_format($gpa_result['total_points'], 2); ?>
                                </td>
                                <td class="text-center">
                                    <span class="text-primary">GPA: <?php echo number_format($gpa_result['gpa'], 2); ?></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="mt-3 text-end">
                    <a href="view_student_results.php?student_id=<?php echo $gpa_result['student_id']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye me-1"></i>View Full Student Results
                    </a>
                    <button class="btn btn-sm btn-secondary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['gpa_result']); endif; ?>
        
        <!-- CGPA Result Display -->
        <?php if (isset($_SESSION['cgpa_result'])): 
            $cgpa_result = $_SESSION['cgpa_result'];
            $classification = getGradeClassification($cgpa_result['cgpa']);
        ?>
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>CGPA Calculation Result</h5>
            </div>
            <div class="card-body">
                <!-- CGPA Summary -->
                <div class="row mb-4">
                    <div class="col-md-12 text-center">
                        <div class="gpa-circle <?php 
                            if ($cgpa_result['cgpa'] >= 4.5) echo 'gpa-excellent';
                            elseif ($cgpa_result['cgpa'] >= 3.5) echo 'gpa-good';
                            elseif ($cgpa_result['cgpa'] >= 2.5) echo 'gpa-average';
                            else echo 'gpa-poor';
                        ?>">
                            <?php echo number_format($cgpa_result['cgpa'], 2); ?>
                        </div>
                        <div class="mt-3">
                            <h3><?php echo $classification[0]; ?></h3>
                            <?php if ($cgpa_result['previous_cgpa']): ?>
                            <p class="text-muted">
                                Previous CGPA: <strong><?php echo number_format($cgpa_result['previous_cgpa'], 2); ?></strong>
                                → New CGPA: <strong><?php echo number_format($cgpa_result['cgpa'], 2); ?></strong>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Academic History Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Session</th>
                                <th>Semester</th>
                                <th>Level</th>
                                <th class="text-center">Credits</th>
                                <th class="text-center">Points</th>
                                <th class="text-center">GPA</th>
                                <th>Classification</th>
                                <th>Calculated On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cgpa_result['records'] as $record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($record['session_year']); ?></td>
                                <td>Semester <?php echo $record['semester']; ?></td>
                                <td>Level <?php echo $record['level']; ?></td>
                                <td class="text-center"><?php echo $record['total_units']; ?></td>
                                <td class="text-center"><?php echo number_format($record['total_points'], 2); ?></td>
                                <td class="text-center">
                                    <strong><?php echo number_format($record['gpa'], 2); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $rec_class = getGradeClassification($record['gpa']);
                                    echo '<span class="badge bg-' . $rec_class[1] . '">' . $rec_class[0] . '</span>';
                                    ?>
                                </td>
                                <td><small><?php echo date('d/m/Y', strtotime($record['calculated_at'])); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2"><strong>Cumulative Total</strong></td>
                                <td><strong><?php echo $cgpa_result['total_sessions']; ?> Semester(s)</strong></td>
                                <td class="text-center"><?php echo $cgpa_result['total_units_all']; ?></td>
                                <td class="text-center"><?php echo number_format($cgpa_result['total_points_all'], 2); ?></td>
                                <td class="text-center text-success">
                                    CGPA: <?php echo number_format($cgpa_result['cgpa'], 2); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="mt-3 text-end">
                    <a href="view_student_results.php?student_id=<?php echo $cgpa_result['student_id']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye me-1"></i>View Full Student Results
                    </a>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['cgpa_result']); endif; ?>
        
        <!-- Batch Result Display -->
        <?php if (isset($_SESSION['batch_result'])): 
            $batch_result = $_SESSION['batch_result'];
        ?>
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Batch Calculation Summary</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="display-6 fw-bold"><?php echo $batch_result['total_students']; ?></div>
                        <div class="text-muted">Total Students Processed</div>
                    </div>
                    <div class="col-md-4">
                        <div class="display-6 fw-bold text-success"><?php echo $batch_result['success_count']; ?></div>
                        <div class="text-muted">Successfully Calculated</div>
                    </div>
                    <div class="col-md-4">
                        <div class="display-6 fw-bold text-danger"><?php echo $batch_result['error_count']; ?></div>
                        <div class="text-muted">Failed</div>
                    </div>
                </div>
                <div class="mt-3 text-center">
                    <p class="text-muted">Session: <?php echo $batch_result['session_year']; ?> | Semester: <?php echo $batch_result['semester'] == 1 ? 'First' : 'Second'; ?></p>
                    <a href="academic_records.php" class="btn btn-outline-primary">
                        <i class="fas fa-list me-2"></i>View All Academic Records
                    </a>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['batch_result']); endif; ?>
        
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('gpaForm')?.addEventListener('submit', function(e) {
            const studentSelect = this.querySelector('select[name="student_id"]');
            const sessionSelect = this.querySelector('select[name="session_year"]');
            
            if (!studentSelect.value || !sessionSelect.value) {
                e.preventDefault();
                alert('Please select both student and session year.');
                return false;
            }
        });
        
        document.getElementById('cgpaForm')?.addEventListener('submit', function(e) {
            const studentSelect = this.querySelector('select[name="student_id"]');
            
            if (!studentSelect.value) {
                e.preventDefault();
                alert('Please select a student.');
                return false;
            }
            
            return confirm('Calculate and update CGPA for this student?');
        });
        
        document.getElementById('batchForm')?.addEventListener('submit', function(e) {
            const sessionSelect = this.querySelector('select[name="batch_session_year"]');
            
            if (!sessionSelect.value) {
                e.preventDefault();
                alert('Please select a session year.');
                return false;
            }
            
            return confirm('Run batch GPA calculation for the selected criteria? This may take a moment.');
        });
        
        document.getElementById('recalcForm')?.addEventListener('submit', function(e) {
            const sessionSelect = this.querySelector('select[name="recalc_session_year"]');
            
            if (!sessionSelect.value) {
                e.preventDefault();
                alert('Please select a session year.');
                return false;
            }
        });
        
        // Auto-select semester based on current month
        document.addEventListener('DOMContentLoaded', function() {
            const currentMonth = new Date().getMonth();
            const semesterSelects = document.querySelectorAll('select[name="semester"], select[name="batch_semester"], select[name="recalc_semester"]');
            
            semesterSelects.forEach(select => {
                if (!select.value || select.value === '') {
                    if (currentMonth >= 1 && currentMonth <= 6) {
                        select.value = '2';
                    } else {
                        select.value = '1';
                    }
                }
            });
        });
    </script>
</body>
</html>

<?php
require_once 'includes/footer.php';
?>