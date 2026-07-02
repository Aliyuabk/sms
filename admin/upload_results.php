<?php
// upload_results.php - WITH ADVANCED PUBLISHING OPTIONS
ob_start();

require_once 'includes/header.php';

// ==================== DATABASE CONNECTION ====================
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'sms';
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

// ==================== CSRF PROTECTION ====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = "Upload & Publish Results";

// Get current academic session
$current_session_stmt = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 AND status = 'Active' LIMIT 1");
$current_session = $current_session_stmt->fetchColumn();
if (!$current_session) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
}

// Get filter data
$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
$departments = $pdo->query("SELECT d.*, f.faculty_name FROM departments d 
                            LEFT JOIN faculties f ON d.faculty_id = f.faculty_id 
                            ORDER BY d.department_name")->fetchAll();
$programs = $pdo->query("SELECT p.*, d.department_name FROM programs p 
                         LEFT JOIN departments d ON p.department_id = d.department_id 
                         WHERE p.is_active = 1 ORDER BY p.program_name")->fetchAll();
$courses = $pdo->query("SELECT c.*, d.department_name FROM courses c 
                        LEFT JOIN departments d ON c.department_id = d.department_id 
                        ORDER BY c.course_code")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];

// ==================== GRADE CALCULATION FUNCTION ====================
function calculateGrade($score, $pdo) {
    static $grade_scale = null;

    if ($grade_scale === null) {
        $stmt = $pdo->query("SELECT grade, min_score, max_score, grade_points, remark 
                             FROM grade_scale ORDER BY min_score DESC");
        $grade_scale = $stmt->fetchAll();
    }

    foreach ($grade_scale as $grade) {
        if ($score >= $grade['min_score'] && $score <= $grade['max_score']) {
            return [
                'grade' => $grade['grade'],
                'points' => $grade['grade_points'],
                'remark' => $grade['remark']
            ];
        }
    }

    return ['grade' => 'F', 'points' => 0.00, 'remark' => 'Fail'];
}

// ==================== HANDLE CSV UPLOAD ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['results_file'])) {
    try {
        // CSRF Validation
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }

        // Get filter parameters
        $session_year = $_POST['session_year'];
        $semester = (int)$_POST['semester'];
        $faculty_id = (int)$_POST['faculty_id'];
        $department_id = (int)$_POST['department_id'];
        $program_id = (int)$_POST['program_id'];
        $level = (int)$_POST['level'];

        // File validation
        $file = $_FILES['results_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_ext !== 'csv') {
            throw new Exception('Invalid file type. Please upload a CSV file (.csv)');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (php.ini limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            ];
            throw new Exception('File upload error: ' . ($upload_errors[$file['error']] ?? 'Unknown error'));
        }

        // Read CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Could not read uploaded file.');
        }

        $results = [];
        $header = fgetcsv($handle);

        if (!$header || count($header) < 4) {
            throw new Exception('CSV file must have at least 4 columns: matric_number, course_code, ca_score, exam_score');
        }

        $row_number = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;

            if (count($row) < 4) {
                continue;
            }

            if (empty(trim($row[0])) || empty(trim($row[1]))) {
                continue;
            }

            $results[] = [
                'matric_number' => trim($row[0]),
                'course_code' => trim($row[1]),
                'ca_score' => floatval($row[2]),
                'exam_score' => floatval($row[3]),
                'row_number' => $row_number
            ];
        }
        fclose($handle);

        if (empty($results)) {
            throw new Exception('No valid data found in the CSV file.');
        }

        // Begin transaction
        $pdo->beginTransaction();

        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $processed_courses = [];
        $processed_students = [];

        foreach ($results as $result_data) {
            try {
                // Validate CA score (0-40)
                if ($result_data['ca_score'] < 0 || $result_data['ca_score'] > 40) {
                    throw new Exception("CA score must be between 0 and 40 (got " . $result_data['ca_score'] . ")");
                }

                // Validate Exam score (0-60)
                if ($result_data['exam_score'] < 0 || $result_data['exam_score'] > 60) {
                    throw new Exception("Exam score must be between 0 and 60 (got " . $result_data['exam_score'] . ")");
                }

                // Get student details
                $student_stmt = $pdo->prepare("
                    SELECT s.*, d.department_name, d.faculty_id, p.program_name 
                    FROM students s
                    LEFT JOIN departments d ON s.department_id = d.department_id
                    LEFT JOIN programs p ON s.program_id = p.program_id
                    WHERE s.matric_number = ? AND s.status = 'Active'
                ");
                $student_stmt->execute([$result_data['matric_number']]);
                $student = $student_stmt->fetch();

                if (!$student) {
                    throw new Exception("Student not found or inactive: " . $result_data['matric_number']);
                }

                // Validate student matches filters
                if ($faculty_id > 0 && $student['faculty_id'] != $faculty_id) {
                    throw new Exception("Student does not belong to selected faculty");
                }

                if ($department_id > 0 && $student['department_id'] != $department_id) {
                    throw new Exception("Student does not belong to selected department");
                }

                if ($program_id > 0 && $student['program_id'] != $program_id) {
                    throw new Exception("Student does not belong to selected program");
                }

                if ($student['current_level'] != $level) {
                    throw new Exception("Student level ({$student['current_level']}) does not match selected level ($level)");
                }

                // Get course details
                $course_stmt = $pdo->prepare("
                    SELECT c.* FROM courses c
                    WHERE c.course_code = ?
                ");
                $course_stmt->execute([$result_data['course_code']]);
                $course = $course_stmt->fetch();

                if (!$course) {
                    throw new Exception("Course '{$result_data['course_code']}' not found");
                }

                $processed_courses[$course['course_code']] = $course['course_title'];
                $processed_students[$student['matric_number']] = $student['first_name'] . ' ' . $student['last_name'];

                // Calculate total and grade
                $total_score = $result_data['ca_score'] + $result_data['exam_score'];
                $grade = calculateGrade($total_score, $pdo);

                // Check if result already exists
                $check_stmt = $pdo->prepare("
                    SELECT result_id FROM results 
                    WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?
                ");
                $check_stmt->execute([$student['student_id'], $course['course_id'], $session_year, $semester]);
                $existing = $check_stmt->fetch();

                if ($existing) {
                    // Update existing result
                    $update_stmt = $pdo->prepare("
                        UPDATE results SET 
                            ca_score = ?, exam_score = ?, total_score = ?,
                            grade = ?, grade_points = ?, grade_remark = ?,
                            is_published = 0, published_date = NULL, published_by = NULL,
                            rejection_reason = NULL, remarks = NULL
                        WHERE result_id = ?
                    ");
                    $update_stmt->execute([
                        $result_data['ca_score'], $result_data['exam_score'], $total_score,
                        $grade['grade'], $grade['points'], $grade['remark'],
                        $existing['result_id']
                    ]);
                } else {
                    // Insert new result
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO results 
                        (student_id, course_id, session_year, semester, level,
                         ca_score, exam_score, total_score, grade, grade_points, grade_remark, 
                         created_at, is_published)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
                    ");
                    $insert_stmt->execute([
                        $student['student_id'], $course['course_id'], $session_year, $semester, $level,
                        $result_data['ca_score'], $result_data['exam_score'], $total_score,
                        $grade['grade'], $grade['points'], $grade['remark']
                    ]);
                }

                $success_count++;

            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Row {$result_data['row_number']} ({$result_data['matric_number']} - {$result_data['course_code']}): " . $e->getMessage();
            }
        }

        if ($error_count > 0) {
            $pdo->rollBack();
            $_SESSION['warning_message'] = "Upload completed with $error_count errors. $success_count successful.";
            $_SESSION['upload_errors'] = $errors;
            $_SESSION['upload_success'] = [
                'count' => $success_count,
                'courses' => $processed_courses,
                'students' => $processed_students
            ];
        } else {
            $pdo->commit();
            $courses_list = implode(', ', array_keys($processed_courses));
            $students_count = count($processed_students);
            $_SESSION['success_message'] = "✅ Successfully uploaded $success_count results for $students_count students. Courses: $courses_list";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Upload failed: " . $e->getMessage();
    }

    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header("Location: upload_results.php");
    exit();
}

// ==================== HANDLE PUBLISH RESULTS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_action'])) {
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid security token.');
        }

        $publish_action = $_POST['publish_action'];
        $publish_type = $_POST['publish_type'];
        
        if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
            throw new Exception('You must be logged in as admin to publish results.');
        }

        $update_params = [];
        $where_clause = "";
        
        // Build WHERE clause based on publish type
        switch($publish_type) {
            case 'faculty':
                $faculty_id = (int)$_POST['faculty_id'];
                $session_year = $_POST['session_year'];
                $semester = (int)$_POST['semester'];
                $level = (int)$_POST['level'];
                
                if ($faculty_id <= 0) throw new Exception('Please select a faculty.');
                
                $where_clause = "r.session_year = ? AND r.semester = ? AND r.level = ? 
                                AND s.faculty_id = ?";
                $update_params = [$session_year, $semester, $level, $faculty_id];
                break;
                
            case 'department':
                $department_id = (int)$_POST['department_id'];
                $session_year = $_POST['session_year'];
                $semester = (int)$_POST['semester'];
                $level = (int)$_POST['level'];
                
                if ($department_id <= 0) throw new Exception('Please select a department.');
                
                $where_clause = "r.session_year = ? AND r.semester = ? AND r.level = ? 
                                AND s.department_id = ?";
                $update_params = [$session_year, $semester, $level, $department_id];
                break;
                
            case 'program':
                $program_id = (int)$_POST['program_id'];
                $session_year = $_POST['session_year'];
                $semester = (int)$_POST['semester'];
                $level = (int)$_POST['level'];
                
                if ($program_id <= 0) throw new Exception('Please select a program.');
                
                $where_clause = "r.session_year = ? AND r.semester = ? AND r.level = ? 
                                AND s.program_id = ?";
                $update_params = [$session_year, $semester, $level, $program_id];
                break;
                
            case 'course':
                $course_id = (int)$_POST['course_id'];
                $session_year = $_POST['session_year'];
                $semester = (int)$_POST['semester'];
                $level = (int)$_POST['level'];
                
                if ($course_id <= 0) throw new Exception('Please select a course.');
                
                $where_clause = "r.session_year = ? AND r.semester = ? AND r.level = ? 
                                AND r.course_id = ?";
                $update_params = [$session_year, $semester, $level, $course_id];
                break;
                
            case 'student':
                $student_id = (int)$_POST['student_id'];
                $session_year = $_POST['session_year'];
                $semester = (int)$_POST['semester'];
                
                if ($student_id <= 0) throw new Exception('Please select a student.');
                
                $where_clause = "r.session_year = ? AND r.semester = ? AND r.student_id = ?";
                $update_params = [$session_year, $semester, $student_id];
                break;
                
            case 'single':
                $result_id = (int)$_POST['result_id'];
                
                if ($result_id <= 0) throw new Exception('Invalid result ID.');
                
                $where_clause = "r.result_id = ?";
                $update_params = [$result_id];
                break;
                
            case 'batch':
                $result_ids = $_POST['result_ids'] ?? [];
                if (empty($result_ids)) throw new Exception('Please select at least one result to publish.');
                
                $placeholders = implode(',', array_fill(0, count($result_ids), '?'));
                $where_clause = "r.result_id IN ($placeholders)";
                $update_params = $result_ids;
                break;
                
            default:
                throw new Exception('Invalid publish type.');
        }
        
        // Execute update
        $update_sql = "
            UPDATE results r
            JOIN students s ON r.student_id = s.student_id
            SET r.is_published = 1, r.published_date = NOW(), r.published_by = ?
            WHERE $where_clause AND r.is_published = 0
        ";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_params = array_merge([$_SESSION['admin_id']], $update_params);
        $update_stmt->execute($update_params);
        
        $affected = $update_stmt->rowCount();
        
        if ($affected > 0) {
            $_SESSION['success_message'] = "✅ Successfully published $affected result(s)!";
        } else {
            $_SESSION['warning_message'] = "No pending results found to publish with the selected criteria.";
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error publishing results: " . $e->getMessage();
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header("Location: upload_results.php");
    exit();
}

// ==================== GET RESULTS FOR DISPLAY ====================
$display_results = [];
$display_query = "
    SELECT 
        r.*,
        s.matric_number,
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
        s.current_level,
        c.course_code,
        c.course_title,
        c.credit_units,
        d.department_name,
        f.faculty_name,
        p.program_name
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE r.is_published = 0
    ORDER BY r.created_at DESC
    LIMIT 100
";
$display_results = $pdo->query($display_query)->fetchAll();

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_results,
        SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published_count,
        SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) as pending_count
    FROM results
";
$stats = $pdo->query($stats_sql)->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload & Publish Results - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .modal-header.bg-primary .btn-close-white {
            filter: brightness(0) invert(1);
        }
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        .table td {
            vertical-align: middle;
        }
        .publish-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .publish-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 2px solid #0d6efd;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        .upload-area:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .select-all-row {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid px-4 py-3">
        <!-- Display Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

       <?php if (isset($_SESSION['warning_message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo htmlspecialchars($_SESSION['warning_message']); unset($_SESSION['warning_message']); ?>
        
        <?php if (!empty($_SESSION['upload_errors'])): ?>
            <hr>
            <strong>Error Details:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($_SESSION['upload_errors'] as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php unset($_SESSION['upload_errors']); ?>
        <?php endif; ?>
        
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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 class="app-page-title mb-1">
                    <i class="fas fa-upload me-2 text-primary"></i>Upload & Publish Results
                </h1>
                <p class="text-muted mb-0">Upload results via CSV and publish to students by faculty, department, program, course, or individually</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                    <i class="fas fa-cloud-upload-alt me-2"></i>Upload CSV
                </button>
                <a href="view_results.php" class="btn btn-info ms-2">
                    <i class="fas fa-eye me-2"></i>View All Results
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card bg-primary text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Results</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['total_results'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-database fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Published</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['published_count'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-check-circle fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Pending</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['pending_count'] ?? 0); ?></h2>
                            </div>
                            <i class="fas fa-clock fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Publish Options Tabs -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-transparent">
                <ul class="nav nav-tabs card-header-tabs" id="publishTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="faculty-tab" data-bs-toggle="tab" data-bs-target="#faculty-publish" type="button" role="tab">
                            <i class="fas fa-building me-2"></i>By Faculty
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="department-tab" data-bs-toggle="tab" data-bs-target="#department-publish" type="button" role="tab">
                            <i class="fas fa-school me-2"></i>By Department
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="program-tab" data-bs-toggle="tab" data-bs-target="#program-publish" type="button" role="tab">
                            <i class="fas fa-graduation-cap me-2"></i>By Program
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="course-tab" data-bs-toggle="tab" data-bs-target="#course-publish" type="button" role="tab">
                            <i class="fas fa-book me-2"></i>By Course
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="student-tab" data-bs-toggle="tab" data-bs-target="#student-publish" type="button" role="tab">
                            <i class="fas fa-user-graduate me-2"></i>By Student
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="publishTabsContent">
                    <!-- Publish by Faculty -->
                    <div class="tab-pane fade show active" id="faculty-publish" role="tabpanel">
                        <form method="POST" class="row g-3" onsubmit="return confirm('Are you sure you want to publish ALL pending results for the selected faculty?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="publish_action" value="1">
                            <input type="hidden" name="publish_type" value="faculty">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Select Faculty *</label>
                                <select class="form-select" name="faculty_id" required>
                                    <option value="">-- Select Faculty --</option>
                                    <?php foreach ($faculties as $fac): ?>
                                    <option value="<?php echo $fac['faculty_id']; ?>">
                                        <?php echo htmlspecialchars($fac['faculty_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Session</label>
                                <select class="form-select" name="session_year">
                                    <option value="">All Sessions</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Semester</label>
                                <select class="form-select" name="semester">
                                    <option value="0">All</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Level</label>
                                <select class="form-select" name="level">
                                    <option value="0">All Levels</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-globe me-2"></i>Publish All Results for Selected Faculty
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Publish by Department -->
                    <div class="tab-pane fade" id="department-publish" role="tabpanel">
                        <form method="POST" class="row g-3" onsubmit="return confirm('Are you sure you want to publish ALL pending results for the selected department?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="publish_action" value="1">
                            <input type="hidden" name="publish_type" value="department">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Select Department *</label>
                                <select class="form-select" name="department_id" required>
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Session</label>
                                <select class="form-select" name="session_year">
                                    <option value="">All Sessions</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Semester</label>
                                <select class="form-select" name="semester">
                                    <option value="0">All</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Level</label>
                                <select class="form-select" name="level">
                                    <option value="0">All Levels</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-building me-2"></i>Publish All Results for Selected Department
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Publish by Program -->
                    <div class="tab-pane fade" id="program-publish" role="tabpanel">
                        <form method="POST" class="row g-3" onsubmit="return confirm('Are you sure you want to publish ALL pending results for the selected program?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="publish_action" value="1">
                            <input type="hidden" name="publish_type" value="program">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Select Program *</label>
                                <select class="form-select" name="program_id" required>
                                    <option value="">-- Select Program --</option>
                                    <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['program_id']; ?>">
                                        <?php echo htmlspecialchars($prog['program_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Session</label>
                                <select class="form-select" name="session_year">
                                    <option value="">All Sessions</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Semester</label>
                                <select class="form-select" name="semester">
                                    <option value="0">All</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Level</label>
                                <select class="form-select" name="level">
                                    <option value="0">All Levels</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-graduation-cap me-2"></i>Publish All Results for Selected Program
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Publish by Course -->
                    <div class="tab-pane fade" id="course-publish" role="tabpanel">
                        <form method="POST" class="row g-3" onsubmit="return confirm('Are you sure you want to publish ALL pending results for the selected course?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="publish_action" value="1">
                            <input type="hidden" name="publish_type" value="course">
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Select Course *</label>
                                <select class="form-select" name="course_id" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['course_id']; ?>">
                                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Session</label>
                                <select class="form-select" name="session_year">
                                    <option value="">All Sessions</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Semester</label>
                                <select class="form-select" name="semester">
                                    <option value="0">All</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Level</label>
                                <select class="form-select" name="level">
                                    <option value="0">All Levels</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-book me-2"></i>Publish All Results for Selected Course
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Publish by Student -->
                    <div class="tab-pane fade" id="student-publish" role="tabpanel">
                        <form method="POST" class="row g-3" onsubmit="return confirm('Are you sure you want to publish ALL pending results for the selected student?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="publish_action" value="1">
                            <input type="hidden" name="publish_type" value="student">
                            
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Search Student *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="studentSearch" placeholder="Enter Matric Number or Name">
                                    <button type="button" class="btn btn-outline-primary" onclick="searchStudentForPublish()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div id="studentSearchResults" class="mt-2" style="display: none;">
                                    <select class="form-select" name="student_id" id="studentSelect">
                                        <option value="">-- Select Student --</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Session</label>
                                <select class="form-select" name="session_year">
                                    <option value="">All Sessions</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Semester</label>
                                <select class="form-select" name="semester">
                                    <option value="0">All</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-user-graduate me-2"></i>Publish All Results for Selected Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Results Table with Batch Publish -->
        <div class="card shadow-sm">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="fas fa-clock me-2 text-warning"></i>Pending Results</h5>
                <div>
                    <button class="btn btn-sm btn-success" id="batchPublishBtn" onclick="batchPublish()">
                        <i class="fas fa-check-double me-1"></i>Publish Selected
                    </button>
                    <button class="btn btn-sm btn-primary" id="selectAllBtn" onclick="selectAllPending()">
                        <i class="fas fa-check-square me-1"></i>Select All
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <form method="POST" id="batchPublishForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="publish_action" value="1">
                    <input type="hidden" name="publish_type" value="batch">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="30">
                                        <input type="checkbox" id="selectAllCheckbox">
                                    </th>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Program/Dept</th>
                                    <th>Session</th>
                                    <th>Scores</th>
                                    <th>Grade</th>
                                    <th>Upload Date</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($display_results)): ?>
                                    <?php foreach ($display_results as $result): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="result-checkbox" name="result_ids[]" value="<?php echo $result['result_id']; ?>">
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($result['student_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($result['matric_number']); ?></small>
                                            <br><small>Level <?php echo $result['current_level']; ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($result['course_code']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($result['course_title'], 0, 35)); ?></small>
                                            <br><small><?php echo $result['credit_units']; ?> units</small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($result['program_name'] ?? 'N/A'); ?></small>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($result['department_name'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($result['session_year']); ?>
                                            <br><small>Semester <?php echo $result['semester']; ?></small>
                                        </td>
                                        <td>
                                            <small>CA: <?php echo $result['ca_score']; ?></small><br>
                                            <small>Exam: <?php echo $result['exam_score']; ?></small><br>
                                            <strong>Total: <?php echo $result['total_score']; ?>%</strong>
                                        </td>
                                        <td>
                                            <?php 
                                            $grade_class = match($result['grade']) {
                                                'A' => 'success', 'B' => 'primary', 'C' => 'info',
                                                'D', 'E' => 'warning', 'F' => 'danger',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?php echo $grade_class; ?>">
                                                <?php echo $result['grade']; ?> (<?php echo $result['grade_points']; ?>)
                                            </span>
                                            <br><small><?php echo $result['grade_remark']; ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo date('d/m/Y H:i', strtotime($result['created_at'])); ?></small>
                                            <br>
                                            <button class="btn btn-sm btn-outline-success mt-1" onclick="publishSingle(<?php echo $result['result_id']; ?>)">
                                                <i class="fas fa-check-circle"></i> Publish
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                            <h5>No pending results</h5>
                                            <p class="text-muted">All results have been published or no results uploaded yet.</p>
                                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                                <i class="fas fa-upload me-2"></i>Upload Results
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-cloud-upload-alt me-2"></i>Upload Results CSV
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>CSV Format Requirements:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>File must be in CSV format (.csv extension)</li>
                                        <li>Columns: matric_number, course_code, ca_score, exam_score</li>
                                        <li>CA score must be between 0 and 40</li>
                                        <li>Exam score must be between 0 and 60</li>
                                        <li>Example: CSC2024001, CSC101, 30, 58</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Academic Session *</label>
                                <select class="form-select" name="session_year" required>
                                    <option value="">Select Session</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 3; $y <= $year + 1; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Semester *</label>
                                <select class="form-select" name="semester" required>
                                    <option value="">Select Semester</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Level *</label>
                                <select class="form-select" name="level" required>
                                    <option value="">Select Level</option>
                                    <?php foreach ($levels as $lvl): ?>
                                    <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Faculty (Filter)</label>
                                <select class="form-select" name="faculty_id">
                                    <option value="0">All Faculties</option>
                                    <?php foreach ($faculties as $fac): ?>
                                    <option value="<?php echo $fac['faculty_id']; ?>">
                                        <?php echo htmlspecialchars($fac['faculty_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Department (Filter)</label>
                                <select class="form-select" name="department_id" id="deptFilter">
                                    <option value="0">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">Program (Filter)</label>
                                <select class="form-select" name="program_id" id="progFilter">
                                    <option value="0">All Programs</option>
                                    <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['program_id']; ?>">
                                        <?php echo htmlspecialchars($prog['program_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <div class="upload-area">
                                    <i class="fas fa-file-csv fa-3x text-muted mb-3"></i>
                                    <label class="form-label fw-bold d-block">CSV File *</label>
                                    <input type="file" class="form-control" name="results_file" accept=".csv" required style="max-width: 400px; margin: 0 auto;">
                                    <div class="form-text mt-2">
                                        <button type="button" class="btn btn-sm btn-primary mt-2" onclick="downloadSampleCSV()">
                                            <i class="fas fa-download me-1"></i>Download Sample CSV
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary ms-2">
                                <i class="fas fa-upload me-2"></i>Upload Results
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Download sample CSV
    function downloadSampleCSV() {
        const content = 'matric_number,course_code,ca_score,exam_score\nCSC2024001,CSC101,30,58\nCSC2024002,CSC102,28,58\nCSC2024003,GST101,25,50';
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'results_template.csv');
        link.click();
        URL.revokeObjectURL(url);
    }

    // Select all checkboxes
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }

    function selectAllPending() {
        const checkboxes = document.querySelectorAll('.result-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        checkboxes.forEach(cb => cb.checked = !allChecked);
        if (selectAllCheckbox) selectAllCheckbox.checked = !allChecked;
    }

    function batchPublish() {
        const selected = document.querySelectorAll('.result-checkbox:checked');
        if (selected.length === 0) {
            alert('Please select at least one result to publish.');
            return;
        }
        
        if (confirm(`Publish ${selected.length} selected result(s)? Students will be able to see them.`)) {
            document.getElementById('batchPublishForm').submit();
        }
    }

    function publishSingle(resultId) {
        if (confirm('Publish this result? The student will be able to see it.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="publish_action" value="1">
                <input type="hidden" name="publish_type" value="single">
                <input type="hidden" name="result_id" value="${resultId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function searchStudentForPublish() {
        const searchTerm = document.getElementById('studentSearch').value.trim();
        if (!searchTerm) {
            alert('Please enter a matric number or student name.');
            return;
        }

        fetch(`ajax_handler.php?action=search_students&q=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                const resultsDiv = document.getElementById('studentSearchResults');
                const select = document.getElementById('studentSelect');
                
                if (data.success && data.students && data.students.length > 0) {
                    select.innerHTML = '<option value="">-- Select Student --</option>';
                    data.students.forEach(student => {
                        select.innerHTML += `<option value="${student.student_id}">${student.matric_number} - ${student.first_name} ${student.last_name}</option>`;
                    });
                    resultsDiv.style.display = 'block';
                } else {
                    alert('No students found matching your search.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error searching for students.');
            });
    }

    // Auto-select semester based on current month
    document.addEventListener('DOMContentLoaded', function() {
        const currentMonth = new Date().getMonth();
        const semesterSelect = document.querySelector('#uploadModal select[name="semester"]');
        if (semesterSelect) {
            if (currentMonth >= 1 && currentMonth <= 6) {
                semesterSelect.value = '2';
            } else {
                semesterSelect.value = '1';
            }
        }
    });
    </script>
</body>
</html>

<?php
require_once 'includes/footer.php';
?>