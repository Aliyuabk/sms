<?php
// course_results.php - FIXED VERSION
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Course Results";

// Check if viewing specific course or all courses
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$session_year = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query conditions
$conditions = ["r.is_published = 1"];
$params = [];

if ($course_id > 0) {
    $conditions[] = "r.course_id = ?";
    $params[] = $course_id;
}

if ($student_id > 0) {
    $conditions[] = "r.student_id = ?";
    $params[] = $student_id;
}

if (!empty($session_year)) {
    $conditions[] = "r.session_year = ?";
    $params[] = $session_year;
}

if ($semester > 0) {
    $conditions[] = "r.semester = ?";
    $params[] = $semester;
}

// Get grade filter
$grade = isset($_GET['grade']) ? $_GET['grade'] : '';
if ($grade !== '') {
    $conditions[] = "r.grade = ?";
    $params[] = $grade;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM results r
    LEFT JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get results data - FIXED: Specify table alias for grade column
$sql = "
    SELECT 
        r.*,
        c.course_code,
        c.course_title,
        c.credit_units,
        d.department_name,
        s.matric_number,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.current_level,
        s.email as student_email,
        pub.full_name as published_by_name,
        g.remark as grade_remark,
        g.is_pass
    FROM results r
    LEFT JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN students s ON r.student_id = s.student_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN admin_users pub ON r.published_by = pub.admin_id
    LEFT JOIN grade_scale g ON r.grade = g.grade
    {$where_clause}
    ORDER BY r.published_date DESC, r.total_score DESC
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Get data for filters
$courses = $pdo->query("SELECT course_id, course_code, course_title FROM courses ORDER BY course_code")->fetchAll();
$sessions = $pdo->query("SELECT DISTINCT session_year FROM results ORDER BY session_year DESC")->fetchAll();
$grades = $pdo->query("SELECT grade, remark FROM grade_scale ORDER BY grade")->fetchAll();

// Get course details if viewing specific course
$course_details = null;
if ($course_id > 0) {
    $course_stmt = $pdo->prepare("
        SELECT c.*, d.department_name 
        FROM courses c 
        LEFT JOIN departments d ON c.department_id = d.department_id 
        WHERE c.course_id = ?
    ");
    $course_stmt->execute([$course_id]);
    $course_details = $course_stmt->fetch();
    
    // Get course statistics - FIXED: Use table alias
    $stats_sql = "
        SELECT 
            COUNT(*) as total_students,
            AVG(r.total_score) as average_score,
            MIN(r.total_score) as min_score,
            MAX(r.total_score) as max_score,
            COUNT(CASE WHEN r.grade = 'F' THEN 1 END) as failed_count,
            COUNT(CASE WHEN g.is_pass = 1 THEN 1 END) as passed_count
        FROM results r
        LEFT JOIN grade_scale g ON r.grade = g.grade
        WHERE r.course_id = ? AND r.is_published = 1
    ";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute([$course_id]);
    $course_stats = $stats_stmt->fetch();
}

// Get student details if viewing specific student
$student_details = null;
if ($student_id > 0) {
    $student_stmt = $pdo->prepare("
        SELECT s.*, d.department_name, p.program_name 
        FROM students s 
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.student_id = ?
    ");
    $student_stmt->execute([$student_id]);
    $student_details = $student_stmt->fetch();
    
    // Get student statistics - FIXED: Use table aliases
    $student_stats_sql = "
        SELECT 
            COUNT(*) as total_courses,
            AVG(r.grade_points) as average_gpa,
            SUM(CASE WHEN r.grade = 'F' THEN 1 ELSE 0 END) as failed_courses,
            SUM(c.credit_units) as total_credits
        FROM results r
        LEFT JOIN courses c ON r.course_id = c.course_id
        WHERE r.student_id = ? AND r.is_published = 1
    ";
    
    $student_stats_stmt = $pdo->prepare($student_stats_sql);
    $student_stats_stmt->execute([$student_id]);
    $student_stats = $student_stats_stmt->fetch();
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_results'])) {
        $selected_ids = $_POST['selected_results'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'publish':
                    $stmt = $pdo->prepare("UPDATE results SET is_published = 1, published_date = CURDATE(), published_by = ? WHERE result_id IN ($placeholders)");
                    $all_params = array_merge([$admin_id], $selected_ids);
                    $stmt->execute($all_params);
                    $_SESSION['success_message'] = count($selected_ids) . " result(s) published!";
                    break;
                    
                case 'unpublish':
                    $stmt = $pdo->prepare("UPDATE results SET is_published = 0 WHERE result_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " result(s) unpublished!";
                    break;
                    
                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM results WHERE result_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " result(s) deleted!";
                    break;
            }
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
    
    // Upload results via CSV
    if (isset($_POST['upload_results']) && isset($_FILES['results_file'])) {
        if ($_FILES['results_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['results_file']['tmp_name'];
            $file_name = $_FILES['results_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            $course_id_upload = (int)$_POST['upload_course_id'];
            $session_year_upload = trim($_POST['upload_session_year']);
            $semester_upload = (int)$_POST['upload_semester'];
            $level_upload = (int)$_POST['upload_level'];
            
            if ($file_ext === 'csv' && $course_id_upload > 0) {
                $imported_count = 0;
                $failed_count = 0;
                $errors = [];
                
                if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
                    // Read headers
                    $headers = fgetcsv($handle);
                    if ($headers === FALSE) {
                        $_SESSION['error_message'] = "CSV file is empty or invalid.";
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit();
                    }
                    
                    // Convert headers to lowercase
                    $header_map = [];
                    foreach ($headers as $index => $header) {
                        $header_map[strtolower(trim($header))] = $index;
                    }
                    
                    // Required columns
                    $required_columns = ['matric_number', 'ca_score', 'exam_score'];
                    foreach ($required_columns as $col) {
                        if (!isset($header_map[$col])) {
                            $_SESSION['error_message'] = "Missing required column: $col";
                            header("Location: " . $_SERVER['REQUEST_URI']);
                            exit();
                        }
                    }
                    
                    // Process each row
                    $row_num = 1;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_num++;
                        try {
                            $matric_number = isset($header_map['matric_number']) ? trim($data[$header_map['matric_number']]) : '';
                            $ca_score = isset($header_map['ca_score']) ? (float)trim($data[$header_map['ca_score']]) : 0;
                            $exam_score = isset($header_map['exam_score']) ? (float)trim($data[$header_map['exam_score']]) : 0;
                            $total_score = $ca_score + $exam_score;
                            
                            // Validate scores
                            if (empty($matric_number) || $ca_score < 0 || $ca_score > 40 || $exam_score < 0 || $exam_score > 60) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Invalid scores or missing matric number";
                                continue;
                            }
                            
                            // Get student ID
                            $student_stmt = $pdo->prepare("SELECT student_id FROM students WHERE matric_number = ?");
                            $student_stmt->execute([$matric_number]);
                            $student = $student_stmt->fetch();
                            
                            if (!$student) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Student not found - $matric_number";
                                continue;
                            }
                            
                            // Get grade based on total score
                            $grade_stmt = $pdo->prepare("SELECT grade, grade_points, remark FROM grade_scale WHERE ? BETWEEN min_score AND max_score");
                            $grade_stmt->execute([$total_score]);
                            $grade_info = $grade_stmt->fetch();
                            
                            if (!$grade_info) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Could not determine grade for score $total_score";
                                continue;
                            }
                            
                            // Check if result already exists
                            $check_sql = "SELECT result_id FROM results WHERE student_id = ? AND course_id = ? AND session_year = ?";
                            $check_stmt = $pdo->prepare($check_sql);
                            $check_stmt->execute([$student['student_id'], $course_id_upload, $session_year_upload]);
                            
                            if ($check_stmt->rowCount() > 0) {
                                // Update existing result
                                $update_sql = "UPDATE results SET 
                                    ca_score = ?, exam_score = ?, total_score = ?,
                                    grade = ?, grade_points = ?, grade_remark = ?,
                                    is_published = 1, published_date = CURDATE(), published_by = ?
                                    WHERE student_id = ? AND course_id = ? AND session_year = ?";
                                
                                $update_stmt = $pdo->prepare($update_sql);
                                $update_stmt->execute([
                                    $ca_score, $exam_score, $total_score,
                                    $grade_info['grade'], $grade_info['grade_points'], $grade_info['remark'],
                                    $admin_id,
                                    $student['student_id'], $course_id_upload, $session_year_upload
                                ]);
                                $imported_count++;
                            } else {
                                // Insert new result
                                $insert_sql = "INSERT INTO results (
                                    student_id, course_id, session_year, semester, level,
                                    ca_score, exam_score, total_score, grade, grade_points,
                                    grade_remark, is_published, published_date, published_by
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURDATE(), ?)";
                                
                                $insert_stmt = $pdo->prepare($insert_sql);
                                $insert_stmt->execute([
                                    $student['student_id'], $course_id_upload, $session_year_upload, 
                                    $semester_upload, $level_upload,
                                    $ca_score, $exam_score, $total_score, 
                                    $grade_info['grade'], $grade_info['grade_points'], $grade_info['remark'],
                                    $admin_id
                                ]);
                                $imported_count++;
                            }
                            
                        } catch (Exception $e) {
                            $failed_count++;
                            $errors[] = "Row $row_num: " . $e->getMessage();
                        }
                    }
                    fclose($handle);
                    
                    // Set result messages
                    if ($imported_count > 0) {
                        $_SESSION['success_message'] = "Successfully imported $imported_count result(s)!";
                        if ($failed_count > 0) {
                            $_SESSION['success_message'] .= " ($failed_count failed)";
                        }
                    } else {
                        $_SESSION['error_message'] = "No results imported. $failed_count rows failed.";
                    }
                    
                    // Store errors in session for debugging
                    if ($failed_count > 0 && !empty($errors)) {
                        $_SESSION['import_errors'] = array_slice($errors, 0, 10);
                    }
                    
                } else {
                    $_SESSION['error_message'] = "Unable to read CSV file.";
                }
            } else {
                $_SESSION['error_message'] = "Please upload a CSV file and select a course.";
            }
        } else {
            $_SESSION['error_message'] = "Error uploading file.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <?php 
        if ($course_details) {
            echo "Results for " . htmlspecialchars($course_details['course_code']);
        } elseif ($student_details) {
            echo "Results for " . htmlspecialchars($student_details['first_name'] . ' ' . $student_details['last_name']);
        } else {
            echo "Course Results";
        }
        ?>
    </h1>
    <div class="app-actions">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#uploadResultsModal">
            <i class="fas fa-upload me-2"></i>Upload Results
        </button>
        <a href="courses.php" class="btn app-btn-secondary ms-2">
            <i class="fas fa-arrow-left me-2"></i>Back to Courses
        </a>
    </div>
</div>

<!-- Header Card for Course/Student -->
<?php if ($course_details): ?>
<div class="app-card app-card-header-info shadow-sm mb-4">
    <div class="app-card-body p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="mb-2"><?php echo htmlspecialchars($course_details['course_code'] . ' - ' . $course_details['course_title']); ?></h4>
                <div class="row g-2">
                    <div class="col-auto">
                        <span class="badge bg-info"><?php echo $course_details['credit_units']; ?> Units</span>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($course_details['department_name']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <?php if ($course_stats): ?>
                <div class="row text-center">
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo number_format($course_stats['average_score'] ?? 0, 1); ?>%</div>
                        <div class="small text-light">Average</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo $course_stats['passed_count'] ?? 0; ?></div>
                        <div class="small text-light">Passed</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo $course_stats['failed_count'] ?? 0; ?></div>
                        <div class="small text-light">Failed</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($student_details): ?>
<div class="app-card app-card-header-info shadow-sm mb-4">
    <div class="app-card-body p-3">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h4 class="mb-2"><?php echo htmlspecialchars($student_details['first_name'] . ' ' . $student_details['last_name']); ?></h4>
                <div class="row g-2">
                    <div class="col-auto">
                        <span class="badge bg-info"><?php echo htmlspecialchars($student_details['matric_number']); ?></span>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-warning"><?php echo $student_details['current_level']; ?> Level</span>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($student_details['department_name']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <?php if ($student_stats): ?>
                <div class="row text-center">
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo number_format($student_stats['average_gpa'] ?? 0, 2); ?></div>
                        <div class="small text-light">GPA</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo $student_stats['total_courses'] ?? 0; ?></div>
                        <div class="small text-light">Courses</div>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold"><?php echo $student_stats['failed_courses'] ?? 0; ?></div>
                        <div class="small text-light">Failed</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <?php if (!$course_id): ?>
            <div class="col-md-3">
                <label class="form-label">Course</label>
                <select class="form-select" name="course_id">
                    <option value="0">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                    <option value="<?php echo $course['course_id']; ?>"
                        <?php echo ($course_id == $course['course_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label class="form-label">Session Year</label>
                <select class="form-select" name="session_year">
                    <option value="">All Sessions</option>
                    <?php foreach ($sessions as $session): ?>
                    <option value="<?php echo htmlspecialchars($session['session_year']); ?>"
                        <?php echo ($session_year == $session['session_year']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($session['session_year']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester">
                    <option value="0">All Semesters</option>
                    <option value="1" <?php echo ($semester == 1) ? 'selected' : ''; ?>>First Semester</option>
                    <option value="2" <?php echo ($semester == 2) ? 'selected' : ''; ?>>Second Semester</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Grade</label>
                <select class="form-select" name="grade">
                    <option value="">All Grades</option>
                    <?php foreach ($grades as $grade_item): ?>
                    <option value="<?php echo $grade_item['grade']; ?>"
                        <?php echo ($grade == $grade_item['grade']) ? 'selected' : ''; ?>>
                        <?php echo $grade_item['grade']; ?> (<?php echo $grade_item['remark']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="course_results.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Published Results</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> results
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_results.php?<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_results.php?format=pdf&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_results.php?format=transcript&<?php echo http_build_query($_GET); ?>">
                            <i class="fas fa-file-alt me-2"></i>Transcript
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <!-- Bulk Actions Form -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="cell" width="30">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="select-all">
                                </div>
                            </th>
                            <th class="cell">Student</th>
                            <?php if (!$course_id): ?>
                            <th class="cell">Course</th>
                            <?php endif; ?>
                            <th class="cell">Session/Semester</th>
                            <th class="cell">Scores</th>
                            <th class="cell">Grade</th>
                            <th class="cell">Status</th>
                            <th class="cell">Published</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results)): ?>
                            <?php foreach ($results as $result): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_results[]" 
                                               value="<?php echo $result['result_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="app-icon-holder icon-holder-sm me-2">
                                            <i class="fas fa-user text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($result['matric_number']); ?></div>
                                            <div class="small"><?php echo htmlspecialchars($result['student_name']); ?></div>
                                            <div class="small text-muted"><?php echo $result['current_level']; ?> Level</div>
                                        </div>
                                    </div>
                                </td>
                                
                                <?php if (!$course_id): ?>
                                <td class="cell">
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($result['course_code']); ?></div>
                                    <div class="small"><?php echo htmlspecialchars($result['course_title']); ?></div>
                                    <div class="small text-muted"><?php echo $result['credit_units']; ?> Units</div>
                                </td>
                                <?php endif; ?>
                                
                                <td class="cell">
                                    <div class="fw-bold"><?php echo htmlspecialchars($result['session_year']); ?></div>
                                    <div class="small text-muted">Semester <?php echo $result['semester']; ?></div>
                                    <div class="small text-muted"><?php echo $result['level']; ?> Level</div>
                                </td>
                                
                                <td class="cell">
                                    <div class="d-flex flex-column">
                                        <div class="fw-bold"><?php echo $result['total_score']; ?>%</div>
                                        <div class="small text-muted">
                                            CA: <?php echo $result['ca_score']; ?>% | 
                                            Exam: <?php echo $result['exam_score']; ?>%
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="cell">
                                    <?php 
                                    $grade_class = [
                                        'A' => 'success',
                                        'B' => 'primary',
                                        'C' => 'info',
                                        'D' => 'warning',
                                        'E' => 'warning',
                                        'F' => 'danger'
                                    ][$result['grade']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $grade_class; ?> fs-6">
                                        <?php echo $result['grade']; ?> (<?php echo $result['grade_points']; ?>)
                                    </span>
                                    <div class="small text-muted"><?php echo $result['grade_remark']; ?></div>
                                </td>
                                
                                <td class="cell">
                                    <?php if ($result['is_pass']): ?>
                                    <span class="badge bg-success">Pass</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Fail</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="cell">
                                    <?php echo date('M d, Y', strtotime($result['published_date'])); ?>
                                    <?php if ($result['published_by_name']): ?>
                                    <div class="small text-muted">By: <?php echo htmlspecialchars($result['published_by_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="cell text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="dropdown" 
                                                data-bs-auto-close="outside">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="edit_result.php?id=<?php echo $result['result_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit Result
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="view_student.php?id=<?php echo $result['student_id']; ?>">
                                                    <i class="fas fa-user me-2"></i>View Student
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="view_course.php?id=<?php echo $result['course_id']; ?>">
                                                    <i class="fas fa-book me-2"></i>View Course
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="selected_results[]" value="<?php echo $result['result_id']; ?>">
                                                    <button type="submit" name="bulk_action" value="unpublish" 
                                                            class="dropdown-item text-warning"
                                                            onclick="return confirm('Unpublish this result?')">
                                                        <i class="fas fa-eye-slash me-2"></i>Unpublish
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="selected_results[]" value="<?php echo $result['result_id']; ?>">
                                                    <button type="submit" name="bulk_action" value="delete" 
                                                            class="dropdown-item text-danger"
                                                            onclick="return confirm('Delete this result? This cannot be undone.')">
                                                        <i class="fas fa-trash me-2"></i>Delete
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $course_id ? 8 : 9; ?>" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                                        <h5>No results found</h5>
                                        <p class="text-muted">No published results match your search criteria.</p>
                                        <?php if ($session_year || $semester || $grade): ?>
                                        <a href="course_results.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadResultsModal">
                                            <i class="fas fa-upload me-1"></i>Upload Results
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <!-- Table Footer -->
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="select-all-bottom">
                    <label class="form-check-label" for="select-all-bottom">
                        Select All
                    </label>
                </div>
                <div class="btn-group ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bulk-actions-btn">
                        Bulk Actions
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" 
                            data-bs-toggle="dropdown">
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item text-success" href="#" onclick="submitBulkAction('publish')">
                            <i class="fas fa-eye me-2"></i>Publish Selected
                        </a></li>
                        <li><a class="dropdown-item text-warning" href="#" onclick="submitBulkAction('unpublish')">
                            <i class="fas fa-eye-slash me-2"></i>Unpublish Selected
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('delete')">
                            <i class="fas fa-trash me-2"></i>Delete Selected
                        </a></li>
                    </ul>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        if ($start_page > 1): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                        <li class="page-item"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Upload Results Modal -->
<div class="modal fade" id="uploadResultsModal" tabindex="-1" aria-labelledby="uploadResultsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadResultsModalLabel">
                    <i class="fas fa-upload me-2"></i>Upload Results via CSV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="uploadResultsForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course *</label>
                            <select class="form-select" name="upload_course_id" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course['course_id']; ?>"
                                    <?php echo ($course_id == $course['course_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Upload CSV File *</label>
                            <input type="file" class="form-control" name="results_file" accept=".csv" required>
                            <div class="form-text">
                                <a href="download_template.php?type=results" class="text-primary" target="_blank">
                                    <i class="fas fa-download me-1"></i>Download CSV Template
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Session Year *</label>
                            <select class="form-select" name="upload_session_year" required>
                                <option value="">Select Session</option>
                                <?php foreach ($sessions as $session): ?>
                                <option value="<?php echo htmlspecialchars($session['session_year']); ?>">
                                    <?php echo htmlspecialchars($session['session_year']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="upload_semester" required>
                                <option value="">Select Semester</option>
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="upload_level" required>
                                <option value="">Select Level</option>
                                <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?> Level</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements:</h6>
                        <ul class="mb-2 small">
                            <li><strong>Required columns:</strong> matric_number, ca_score, exam_score</li>
                            <li><strong>ca_score:</strong> 0-40 (Continuous Assessment)</li>
                            <li><strong>exam_score:</strong> 0-60 (Examination)</li>
                            <li><strong>Total score:</strong> Automatically calculated (CA + Exam)</li>
                            <li><strong>Grades:</strong> Automatically assigned based on grading scale</li>
                            <li>CSV must have a header row</li>
                            <li>Max file size: 10MB</li>
                        </ul>
                        <p class="mb-0 small"><strong>Note:</strong> Existing results for same student/course/session will be updated.</p>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="uploadResultsForm" name="upload_results" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload Results
                </button>
            </div>
</div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all-bottom').checked = this.checked;
});

document.getElementById('select-all-bottom').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    document.getElementById('select-all').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one result.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'publish':
            confirmMessage = `Publish ${selectedIds.length} selected result(s)?`;
            break;
        case 'unpublish':
            confirmMessage = `Unpublish ${selectedIds.length} selected result(s)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected result(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected result IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Validate upload form
document.getElementById('uploadResultsForm').addEventListener('submit', function(e) {
    const courseSelect = this.querySelector('select[name="upload_course_id"]');
    const fileInput = this.querySelector('input[name="results_file"]');
    const sessionSelect = this.querySelector('select[name="upload_session_year"]');
    const semesterSelect = this.querySelector('select[name="upload_semester"]');
    const levelSelect = this.querySelector('select[name="upload_level"]');
    
    if (!courseSelect.value || !fileInput.files.length || !sessionSelect.value || !semesterSelect.value || !levelSelect.value) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    const file = fileInput.files[0];
    const maxSize = 10 * 1024 * 1024; // 10MB
    
    if (file.size > maxSize) {
        e.preventDefault();
        alert('File size exceeds 10MB limit.');
        return false;
    }
    
    if (!file.name.toLowerCase().endsWith('.csv')) {
        e.preventDefault();
        alert('Please select a CSV file.');
        return false;
    }
    
    if (!confirm('Are you sure you want to upload results? This may overwrite existing results.')) {
        e.preventDefault();
        return false;
    }
    
    // Show loading
    const submitBtn = this.querySelector('button[name="upload_results"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
    submitBtn.disabled = true;
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>