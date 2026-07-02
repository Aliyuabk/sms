<?php
// course_registrations.php - PROFESSIONAL ENHANCED VERSION
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Course Registrations";

// Get filter parameters with validation
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$session_year = isset($_GET['session_year']) ? trim($_GET['session_year']) : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query conditions
$conditions = [];
$params = [];

if ($course_id > 0) {
    $conditions[] = "c.course_id = ?";
    $params[] = $course_id;
}

if ($student_id > 0) {
    $conditions[] = "s.student_id = ?";
    $params[] = $student_id;
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

if (!empty($session_year)) {
    $conditions[] = "cr.session_year = ?";
    $params[] = $session_year;
}

if ($semester > 0) {
    $conditions[] = "cr.semester = ?";
    $params[] = $semester;
}

if (!empty($status)) {
    $conditions[] = "cr.registration_status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR c.course_code LIKE ? OR c.course_title LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM course_registrations cr
    INNER JOIN courses c ON cr.course_id = c.course_id
    INNER JOIN students s ON cr.student_id = s.student_id
    {$where_clause}
";

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_records = 0;
}

$total_pages = $total_records > 0 ? ceil($total_records / $records_per_page) : 1;

// Get registrations data with enhanced joins
$sql = "
    SELECT 
        cr.*,
        c.course_code,
        c.course_title,
        c.credit_units,
        s.matric_number,
        s.first_name,
        s.last_name,
        s.middle_name,
        s.current_level,
        s.email as student_email,
        s.phone as student_phone,
        s.status as student_status,
        d.department_name,
        d.department_code,
        p.program_name,
        p.program_code,
        (SELECT grade FROM results r 
         WHERE r.student_id = cr.student_id 
         AND r.course_id = cr.course_id 
         AND r.session_year = cr.session_year 
         AND r.semester = cr.semester 
         LIMIT 1) as final_grade,
        (SELECT total_score FROM results r 
         WHERE r.student_id = cr.student_id 
         AND r.course_id = cr.course_id 
         AND r.session_year = cr.session_year 
         AND r.semester = cr.semester 
         LIMIT 1) as final_score,
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
    FROM course_registrations cr
    INNER JOIN courses c ON cr.course_id = c.course_id
    INNER JOIN students s ON cr.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    {$where_clause}
    ORDER BY cr.registration_date DESC, cr.session_year DESC, cr.semester DESC
    LIMIT {$offset}, {$records_per_page}
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Registrations query error: " . $e->getMessage());
    $registrations = [];
}

// Get filter dropdown data
try {
    $courses = $pdo->query("SELECT course_id, course_code, course_title FROM courses ORDER BY course_code")->fetchAll();
    $sessions = $pdo->query("SELECT DISTINCT session_year FROM course_registrations ORDER BY session_year DESC")->fetchAll();
    $departments = $pdo->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name")->fetchAll();
    $programs = $pdo->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
} catch (PDOException $e) {
    $courses = $sessions = $departments = $programs = [];
}

$status_options = ['Pending', 'Approved', 'Rejected'];

// Get course details if viewing specific course
$course_details = null;
if ($course_id > 0) {
    try {
        $course_stmt = $pdo->prepare("SELECT * FROM courses WHERE course_id = ?");
        $course_stmt->execute([$course_id]);
        $course_details = $course_stmt->fetch();
    } catch (PDOException $e) {
        $course_details = null;
    }
}

// Get student details if viewing specific student
$student_details = null;
if ($student_id > 0) {
    try {
        $student_stmt = $pdo->prepare("
            SELECT s.*, d.department_name, d.department_code, p.program_name, p.program_code 
            FROM students s 
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?
        ");
        $student_stmt->execute([$student_id]);
        $student_details = $student_stmt->fetch();
    } catch (PDOException $e) {
        $student_details = null;
    }
}

// Get enhanced statistics with program breakdown
$stats = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'current' => 0,
    'unique_students' => 0,
    'by_program' => []
];

try {
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM course_registrations")->fetchColumn();
    $stats['approved'] = $pdo->query("SELECT COUNT(*) FROM course_registrations WHERE registration_status = 'Approved'")->fetchColumn();
    $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM course_registrations WHERE registration_status = 'Pending'")->fetchColumn();
    $stats['rejected'] = $pdo->query("SELECT COUNT(*) FROM course_registrations WHERE registration_status = 'Rejected'")->fetchColumn();
    
    $current_session = date('Y') . '/' . (date('Y') + 1);
    $current_stmt = $pdo->prepare("SELECT COUNT(*) FROM course_registrations WHERE session_year = ?");
    $current_stmt->execute([$current_session]);
    $stats['current'] = $current_stmt->fetchColumn();
    
    $stats['unique_students'] = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM course_registrations")->fetchColumn();
    
    // Get registrations by program
    $prog_stmt = $pdo->query("
        SELECT p.program_name, COUNT(cr.registration_id) as count
        FROM course_registrations cr
        JOIN students s ON cr.student_id = s.student_id
        JOIN programs p ON s.program_id = p.program_id
        GROUP BY p.program_id
        ORDER BY count DESC
        LIMIT 5
    ");
    $stats['by_program'] = $prog_stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Stats query error: " . $e->getMessage());
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Single action
    if (isset($_POST['single_action']) && isset($_POST['registration_id'])) {
        $reg_id = (int)$_POST['registration_id'];
        $action = $_POST['single_action'];
        
        try {
            if ($action === 'approve') {
                $sql = "UPDATE course_registrations SET registration_status = 'Approved', approval_date = CURDATE() WHERE registration_id = ?";
            } elseif ($action === 'reject') {
                $sql = "UPDATE course_registrations SET registration_status = 'Rejected' WHERE registration_id = ?";
            } elseif ($action === 'delete') {
                $sql = "DELETE FROM course_registrations WHERE registration_id = ?";
            } else {
                throw new Exception("Invalid action");
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$reg_id]);
            
            $_SESSION['success_message'] = "Registration " . $action . "ed successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    }
    
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_registrations'])) {
        $selected_ids = $_POST['selected_registrations'];
        $bulk_action = $_POST['bulk_action'];
        
        if (!empty($selected_ids) && is_array($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            try {
                if ($bulk_action === 'approve') {
                    $sql = "UPDATE course_registrations SET registration_status = 'Approved', approval_date = CURDATE() WHERE registration_id IN ($placeholders)";
                } elseif ($bulk_action === 'reject') {
                    $sql = "UPDATE course_registrations SET registration_status = 'Rejected' WHERE registration_id IN ($placeholders)";
                } elseif ($bulk_action === 'delete') {
                    $sql = "DELETE FROM course_registrations WHERE registration_id IN ($placeholders)";
                } else {
                    throw new Exception("Invalid bulk action");
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($selected_ids);
                
                $_SESSION['success_message'] = count($selected_ids) . " registration(s) " . $bulk_action . "ed successfully!";
                
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error processing bulk action: " . $e->getMessage();
            }
        }
        
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    }
    
    // Add registration
    if (isset($_POST['add_registration'])) {
        $student_id_reg = (int)$_POST['student_id'];
        $course_id_reg = (int)$_POST['course_id'];
        $session_year_reg = trim($_POST['session_year']);
        $semester_reg = (int)$_POST['semester'];
        $level_reg = (int)$_POST['level'];
        
        try {
            // Validate inputs
            if (!$student_id_reg || !$course_id_reg || !$session_year_reg || !$semester_reg || !$level_reg) {
                throw new Exception("All fields are required");
            }
            
            // Check for existing registration
            $check_sql = "SELECT registration_id FROM course_registrations 
                         WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$student_id_reg, $course_id_reg, $session_year_reg, $semester_reg]);
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Student is already registered for this course in the selected session/semester.");
            }
            
            // Insert registration
            $insert_sql = "INSERT INTO course_registrations (
                student_id, course_id, session_year, semester, level,
                registration_date, registration_status
            ) VALUES (?, ?, ?, ?, ?, CURDATE(), 'Approved')";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $student_id_reg, $course_id_reg, $session_year_reg,
                $semester_reg, $level_reg
            ]);
            
            $_SESSION['success_message'] = "Registration added successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding registration: " . $e->getMessage();
        }
        
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit();
    }
}


// Helper function to format date safely
function safeDate($date_string) {
    if (empty($date_string) || $date_string === '0000-00-00') {
        return 'N/A';
    }
    try {
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return 'N/A';
        }
        return date('d/m/Y', $timestamp);
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Helper function to build query string
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'Approved': return 'success';
        case 'Pending': return 'warning';
        case 'Rejected': return 'danger';
        default: return 'secondary';
    }
}

// Helper function to get status icon
function getStatusIcon($status) {
    switch($status) {
        case 'Approved': return '<i class="fas fa-check-circle me-1"></i>';
        case 'Pending': return '<i class="fas fa-clock me-1"></i>';
        case 'Rejected': return '<i class="fas fa-times-circle me-1"></i>';
        default: return '<i class="fas fa-question-circle me-1"></i>';
    }
}
?>

<!-- Display Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle fa-2x me-3"></i>
            <div>
                <strong>Success!</strong> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
            <div>
                <strong>Error!</strong> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Stats Bar -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="app-page-title mb-1">
            <?php 
            if ($course_details) {
                echo '<i class="fas fa-book-open me-2 text-primary"></i>Registrations: ' . htmlspecialchars($course_details['course_code'] ?? '');
            } elseif ($student_details) {
                $student_name = ($student_details['first_name'] ?? '') . ' ' . ($student_details['last_name'] ?? '');
                echo '<i class="fas fa-user-graduate me-2 text-primary"></i>Registrations: ' . htmlspecialchars(trim($student_name));
            } else {
                echo '<i class="fas fa-calendar-alt me-2 text-primary"></i>Course Registrations';
            }
            ?>
        </h1>
        <p class="text-muted mb-0">Manage and track student course registrations across all programs</p>
    </div>
    <div>
        <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addRegistrationModal">
            <i class="fas fa-plus-circle me-2"></i>Manual Registration
        </button>
        <?php if ($course_id || $student_id): ?>
            <a href="course_registrations.php" class="btn btn-outline-secondary ms-2 shadow-sm">
                <i class="fas fa-times me-2"></i>Clear Filters
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Enhanced Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Total</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['total']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-database text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50">All registrations</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Approved</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['approved']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-check-circle text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50"><?php echo $stats['total'] > 0 ? round(($stats['approved']/$stats['total'])*100, 1) : 0; ?>% of total</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Pending</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['pending']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-clock text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50">Awaiting approval</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Rejected</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['rejected']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-times-circle text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50">Declined registrations</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-info h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Current</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['current']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-calendar-week text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50"><?php echo date('Y'); ?>/<?php echo date('Y')+1; ?> session</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm bg-gradient-secondary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="text-white-50 mb-1">Students</h6>
                        <h3 class="text-white mb-0"><?php echo number_format($stats['unique_students']); ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-25 rounded p-2">
                        <i class="fas fa-users text-white fa-lg"></i>
                    </div>
                </div>
                <small class="text-white-50">Unique registrants</small>
            </div>
        </div>
    </div>
</div>

<!-- Program Breakdown Cards -->
<?php if (!empty($stats['by_program'])): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 pt-3">
                <h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Registrations by Program</h6>
            </div>
            <div class="card-body pt-0">
                <div class="row g-2">
                    <?php foreach ($stats['by_program'] as $prog): ?>
                    <div class="col-md-2 col-6">
                        <div class="bg-light rounded p-2 text-center">
                            <small class="text-muted d-block"><?php echo htmlspecialchars(substr($prog['program_name'], 0, 20)); ?></small>
                            <strong class="fs-5"><?php echo number_format($prog['count']); ?></strong>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Enhanced Filters Section -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 pt-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-sliders-h me-2 text-primary"></i>Filters</h5>
            <a href="course_registrations.php" class="text-decoration-none small">
                <i class="fas fa-redo-alt me-1"></i>Reset
            </a>
        </div>
    </div>
    <div class="card-body pt-0">
        <form method="GET" class="row g-3" id="filterForm">
            <?php if ($course_id): ?>
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
            <?php endif; ?>
            <?php if ($student_id): ?>
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
            <?php endif; ?>
            
            <div class="col-md-3">
                <label class="form-label text-muted small fw-bold">SEARCH</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Matric, name, course...">
                </div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label text-muted small fw-bold">DEPARTMENT</label>
                <select class="form-select" name="department_id" id="deptFilter">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" 
                            <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label text-muted small fw-bold">PROGRAM</label>
                <select class="form-select" name="program_id" id="programFilter">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                        <option value="<?php echo $prog['program_id']; ?>" 
                            <?php echo $program_id == $prog['program_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prog['program_code']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label text-muted small fw-bold">SESSION</label>
                <select class="form-select" name="session_year">
                    <option value="">All</option>
                    <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo htmlspecialchars($session['session_year'] ?? ''); ?>" 
                            <?php echo $session_year == ($session['session_year'] ?? '') ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($session['session_year'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1">
                <label class="form-label text-muted small fw-bold">SEM</label>
                <select class="form-select" name="semester">
                    <option value="0">All</option>
                    <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>1st</option>
                    <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>2nd</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label text-muted small fw-bold">STATUS</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <?php foreach ($status_options as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $status == $opt ? 'selected' : ''; ?>>
                            <?php echo $opt; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-search me-2"></i>Apply Filters
                </button>
                <button type="reset" class="btn btn-outline-secondary ms-2" onclick="resetFilters()">
                    <i class="fas fa-undo-alt me-2"></i>Reset
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Active Filters Display -->
<?php if ($department_id || $program_id || $session_year || $semester || $status || $search): ?>
<div class="mb-3">
    <span class="text-muted small me-2">Active filters:</span>
    <?php if ($search): ?>
        <span class="badge bg-secondary me-1 p-2">Search: <?php echo htmlspecialchars($search); ?></span>
    <?php endif; ?>
    <?php if ($department_id): ?>
        <?php 
            $dept_name = '';
            foreach ($departments as $d) {
                if ($d['department_id'] == $department_id) {
                    $dept_name = $d['department_code'];
                    break;
                }
            }
        ?>
        <span class="badge bg-secondary me-1 p-2">Dept: <?php echo htmlspecialchars($dept_name); ?></span>
    <?php endif; ?>
    <?php if ($program_id): ?>
        <?php 
            $prog_name = '';
            foreach ($programs as $p) {
                if ($p['program_id'] == $program_id) {
                    $prog_name = $p['program_code'];
                    break;
                }
            }
        ?>
        <span class="badge bg-secondary me-1 p-2">Program: <?php echo htmlspecialchars($prog_name); ?></span>
    <?php endif; ?>
    <?php if ($session_year): ?>
        <span class="badge bg-secondary me-1 p-2">Session: <?php echo htmlspecialchars($session_year); ?></span>
    <?php endif; ?>
    <?php if ($semester): ?>
        <span class="badge bg-secondary me-1 p-2">Semester: <?php echo $semester == 1 ? '1st' : '2nd'; ?></span>
    <?php endif; ?>
    <?php if ($status): ?>
        <span class="badge bg-secondary me-1 p-2">Status: <?php echo htmlspecialchars($status); ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Registrations Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 pt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-table me-2 text-primary"></i>Registration Records</h5>
            <div class="d-flex gap-2">
                <span class="text-muted small align-self-center">
                    <i class="fas fa-list me-1"></i>Showing <?php echo min($offset + 1, $total_records); ?> - 
                    <?php echo min($offset + $records_per_page, $total_records); ?> 
                    of <?php echo $total_records; ?>
                </span>
               <!-- // Add this to the header section of course_registrations.php where the export button is

                // Export dropdown menu -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                            <a class="dropdown-item" href="export_registrations.php?<?php echo buildQueryString(); ?>&format=csv">
                                <i class="fas fa-file-csv me-2 text-success"></i> Export as CSV
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="export_registrations.php?<?php echo buildQueryString(); ?>&format=excel">
                                <i class="fas fa-file-excel me-2 text-primary"></i> Export as Excel
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="#" onclick="exportWithFilters()">
                                <i class="fas fa-filter me-2 text-info"></i> Export with Current Filters
                            </a>
                        </li>
                    </ul>
                </div>

                <script>
                function exportWithFilters() {
                    // Get current filter values
                    const params = new URLSearchParams(window.location.search);
                    params.set('format', 'excel');
                    window.location.href = 'export_registrations.php?' + params.toString();
                }
                </script>
            </div>
        </div>
    </div>
    
    <div class="card-body p-0">
        <form method="POST" id="bulkForm">
            <input type="hidden" name="bulk_action" id="bulkActionInput">
            
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr class="small text-uppercase text-muted">
                            <th width="30" class="ps-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                </div>
                            </th>
                            <th>Student Details</th>
                            <?php if (!$course_id && !$student_id): ?>
                                <th>Program / Department</th>
                            <?php endif; ?>
                            <?php if (!$course_id): ?>
                                <th>Course</th>
                            <?php endif; ?>
                            <th>Session</th>
                            <th>Status</th>
                            <th>Result</th>
                            <th>Registered</th>
                            <th width="80">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($registrations)): ?>
                            <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_registrations[]" 
                                               value="<?php echo $reg['registration_id'] ?? 0; ?>">
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="avatar-sm rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                <i class="fas fa-user-graduate"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="fw-semibold">
                                                <a href="?student_id=<?php echo $reg['student_id'] ?? 0; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($reg['matric_number'] ?? 'N/A'); ?>
                                                </a>
                                            </div>
                                            <div class="small text-muted">
                                                <?php 
                                                $full_name = trim(($reg['first_name'] ?? '') . ' ' . ($reg['last_name'] ?? ''));
                                                echo htmlspecialchars($full_name ?: 'N/A'); 
                                                ?>
                                            </div>
                                            <div class="small">
                                                <span class="badge bg-info bg-opacity-10 text-info">Level <?php echo $reg['current_level'] ?? 'N/A'; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <?php if (!$course_id && !$student_id): ?>
                                <td>
                                    <div>
                                        <div class="fw-semibold small"><?php echo htmlspecialchars($reg['program_code'] ?? 'N/A'); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($reg['department_code'] ?? 'N/A'); ?></div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                
                                <?php if (!$course_id): ?>
                                <td>
                                    <div>
                                        <div class="fw-semibold">
                                            <a href="?course_id=<?php echo $reg['course_id'] ?? 0; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($reg['course_code'] ?? 'N/A'); ?>
                                            </a>
                                        </div>
                                        <div class="text-muted small"><?php echo htmlspecialchars(substr($reg['course_title'] ?? '', 0, 35)) . (strlen($reg['course_title'] ?? '') > 35 ? '...' : ''); ?></div>
                                        <div><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo $reg['credit_units'] ?? 0; ?> units</span></div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($reg['session_year'] ?? 'N/A'); ?></div>
                                    <div class="text-muted small">Semester <?php echo $reg['semester'] ?? 'N/A'; ?></div>
                                </td>
                                
                                <td>
                                    <?php
                                    $status_val = $reg['registration_status'] ?? 'Pending';
                                    $badge_class = getStatusBadgeClass($status_val);
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class; ?> bg-opacity-10 text-<?php echo $badge_class; ?> p-2">
                                        <?php echo getStatusIcon($status_val); ?>
                                        <?php echo $status_val; ?>
                                    </span>
                                    <?php if (!empty($reg['approval_date']) && $reg['approval_date'] != '0000-00-00'): ?>
                                    <div class="small text-muted mt-1"><?php echo safeDate($reg['approval_date']); ?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if (!empty($reg['final_grade'])): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success p-2"><?php echo htmlspecialchars($reg['final_grade']); ?></span>
                                        <div class="small mt-1"><?php echo $reg['final_score'] ?? 0; ?>%</div>
                                    <?php else: ?>
                                        <span class="text-muted">Not graded</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="small"><?php echo safeDate($reg['registration_date'] ?? ''); ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?php echo timeAgo($reg['registration_date'] ?? ''); ?></div>
                                </td>
                                
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                            <?php if (($reg['registration_status'] ?? '') == 'Pending'): ?>
                                                <li>
                                                    <form method="POST" class="d-inline w-100">
                                                        <input type="hidden" name="registration_id" value="<?php echo $reg['registration_id'] ?? 0; ?>">
                                                        <button type="submit" name="single_action" value="approve" 
                                                                class="dropdown-item text-success">
                                                            <i class="fas fa-check-circle me-2"></i>Approve
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline w-100">
                                                        <input type="hidden" name="registration_id" value="<?php echo $reg['registration_id'] ?? 0; ?>">
                                                        <button type="submit" name="single_action" value="reject" 
                                                                class="dropdown-item text-danger">
                                                            <i class="fas fa-times-circle me-2"></i>Reject
                                                        </button>
                                                    </form>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <a class="dropdown-item" href="view_student.php?id=<?php echo $reg['student_id'] ?? 0; ?>">
                                                    <i class="fas fa-user me-2"></i>Student Profile
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="view_course.php?id=<?php echo $reg['course_id'] ?? 0; ?>">
                                                    <i class="fas fa-book me-2"></i>Course Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="upload_results.php?student_id=<?php echo $reg['student_id'] ?? 0; ?>&course_id=<?php echo $reg['course_id'] ?? 0; ?>">
                                                    <i class="fas fa-upload me-2"></i>Upload Result
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" class="d-inline w-100" onsubmit="return confirm('Delete this registration permanently?')">
                                                    <input type="hidden" name="registration_id" value="<?php echo $reg['registration_id'] ?? 0; ?>">
                                                    <button type="submit" name="single_action" value="delete" 
                                                            class="dropdown-item text-danger">
                                                        <i class="fas fa-trash-alt me-2"></i>Delete
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
                                <td colspan="<?php 
                                    $colspan = 5;
                                    if (!$course_id && !$student_id) $colspan++;
                                    if (!$course_id) $colspan++;
                                    echo $colspan; 
                                ?>" class="text-center py-5">
                                    <div class="py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        <h5 class="text-muted">No registrations found</h5>
                                        <p class="text-muted mb-0">Click "Manual Registration" to create a new registration.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    
    <!-- Table Footer with Bulk Actions -->
    <div class="card-footer bg-transparent border-0 pt-0 pb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllBottom">
                        <label class="form-check-label small" for="selectAllBottom">Select All</label>
                    </div>
                    
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fas fa-tasks me-1"></i>Bulk Actions
                        </button>
                        <ul class="dropdown-menu shadow-sm">
                            <li>
                                <a class="dropdown-item text-success" href="#" onclick="bulkAction('approve')">
                                    <i class="fas fa-check-circle me-2"></i>Approve Selected
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="bulkAction('reject')">
                                    <i class="fas fa-times-circle me-2"></i>Reject Selected
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="bulkAction('delete')">
                                    <i class="fas fa-trash-alt me-2"></i>Delete Selected
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <span class="text-muted small" id="selectedCount">0 selected</span>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav class="float-md-end mt-3 mt-md-0">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $current_page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo buildQueryString(['page']); ?>&page=1">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $total_pages; ?>">
                                    <?php echo $total_pages; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $current_page + 1; ?>">
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

<!-- Enhanced Add Registration Modal -->
<div class="modal fade" id="addRegistrationModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Manual Course Registration
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Step 1: Search Student -->
                <div class="mb-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="step-indicator bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px;">1</div>
                        <h6 class="mb-0">Search Student</h6>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent"><i class="fas fa-search"></i></span>
                                <input type="text" 
                                       class="form-control" 
                                       id="matricSearch" 
                                       placeholder="Enter Matric Number (e.g., CSC2024001)" 
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100" type="button" id="searchBtn">
                                <i class="fas fa-search me-1"></i> Search
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Student Info Card -->
                <div id="studentInfoCard" class="card mb-4 border-primary" style="display: none;">
                    <div class="card-header bg-primary bg-opacity-10">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user-graduate text-primary me-2"></i>
                            <strong>Student Information</strong>
                        </div>
                    </div>
                    <div class="card-body p-3" id="studentInfoContent">
                        <!-- Student info will be loaded here -->
                    </div>
                </div>
                
                <!-- Error Message -->
                <div id="studentError" class="alert alert-danger" style="display: none;"></div>
                
                <!-- Step 2: Registration Form -->
                <div id="registrationForm" style="display: none;">
                    <div class="d-flex align-items-center mb-3">
                        <div class="step-indicator bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px;">2</div>
                        <h6 class="mb-0">Registration Details</h6>
                    </div>
                    
                    <form method="POST" id="addRegistrationForm">
                        <input type="hidden" name="student_id" id="selectedStudentId">
                        <input type="hidden" name="level" id="selectedStudentLevel">
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-semibold">Select Course <span class="text-danger">*</span></label>
                                <select class="form-select" name="course_id" id="courseSelect" required>
                                    <option value="">-- Select a course --</option>
                                </select>
                                <small class="text-muted" id="courseCount"></small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Session Year <span class="text-danger">*</span></label>
                                <select class="form-select" name="session_year" required>
                                    <option value="">Select Session</option>
                                    <?php 
                                    $year = date('Y');
                                    for ($y = $year - 2; $y <= $year + 2; $y++):
                                        $session = $y . '/' . ($y + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" 
                                        <?php echo $session == ($year . '/' . ($year + 1)) ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                                <select class="form-select" name="semester" required>
                                    <option value="">Select Semester</option>
                                    <option value="1">First Semester</option>
                                    <option value="2">Second Semester</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="alert alert-info" id="summaryCard" style="display: none;">
                            <div class="d-flex">
                                <i class="fas fa-info-circle me-2 mt-1"></i>
                                <div>
                                    <strong>Registration Summary</strong>
                                    <ul class="mb-0 small mt-1 ps-3" id="summaryList"></ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i> Cancel
                            </button>
                            <button type="submit" name="add_registration" class="btn btn-primary" id="submitBtn" disabled>
                                <i class="fas fa-save me-1"></i> Add Registration
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom gradient backgrounds */
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.bg-gradient-success {
    background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
}
.bg-gradient-warning {
    background: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
}
.bg-gradient-danger {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}
.bg-gradient-info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}
.bg-gradient-secondary {
    background: linear-gradient(135deg, #868f96 0%, #596164 100%);
}

/* Step indicator */
.step-indicator {
    font-size: 12px;
    font-weight: bold;
}

/* Avatar styling */
.avatar-sm {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

/* Table hover effect */
.table-hover tbody tr:hover {
    background-color: rgba(102, 126, 234, 0.05);
    transition: background-color 0.2s ease;
}

/* Card hover effect */
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1) !important;
}

/* Status badges */
.badge.bg-success.bg-opacity-10 {
    background-color: rgba(40, 167, 69, 0.1) !important;
}
.badge.bg-warning.bg-opacity-10 {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
.badge.bg-danger.bg-opacity-10 {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
.badge.bg-info.bg-opacity-10 {
    background-color: rgba(23, 162, 184, 0.1) !important;
}
</style>
<script>
// Global variables
let currentStudent = null;

// DOM Elements
const searchInput = document.getElementById('matricSearch');
const searchBtn = document.getElementById('searchBtn');
const studentInfoCard = document.getElementById('studentInfoCard');
const studentInfoContent = document.getElementById('studentInfoContent');
const studentError = document.getElementById('studentError');
const registrationForm = document.getElementById('registrationForm');
const courseSelect = document.getElementById('courseSelect');
const courseCount = document.getElementById('courseCount');
const submitBtn = document.getElementById('submitBtn');
const summaryCard = document.getElementById('summaryCard');
const summaryList = document.getElementById('summaryList');

// Update selected count display
function updateSelectedCount() {
    const count = document.querySelectorAll('.select-checkbox:checked').length;
    const selectedCountSpan = document.getElementById('selectedCount');
    if (selectedCountSpan) {
        selectedCountSpan.textContent = count + ' selected';
    }
}

// Select all checkboxes - Top checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    if (document.getElementById('selectAllBottom')) {
        document.getElementById('selectAllBottom').checked = this.checked;
    }
    updateSelectedCount();
});

// Select all checkboxes - Bottom checkbox
document.getElementById('selectAllBottom')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    if (document.getElementById('selectAll')) {
        document.getElementById('selectAll').checked = this.checked;
    }
    updateSelectedCount();
});

// Individual checkbox change listener
document.querySelectorAll('.select-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Bulk actions function
function bulkAction(action) {
    const selected = document.querySelectorAll('.select-checkbox:checked');
    if (selected.length === 0) {
        showAlert('Please select at least one registration.', 'warning');
        return;
    }
    
    let confirmMsg = '';
    switch(action) {
        case 'approve': 
            confirmMsg = 'Are you sure you want to APPROVE ' + selected.length + ' registration(s)?';
            break;
        case 'reject': 
            confirmMsg = 'Are you sure you want to REJECT ' + selected.length + ' registration(s)?';
            break;
        case 'delete': 
            confirmMsg = 'WARNING: You are about to DELETE ' + selected.length + ' registration(s). This action cannot be undone. Are you sure?';
            break;
    }
    
    if (confirm(confirmMsg)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Reset filters function
function resetFilters() {
    window.location.href = 'course_registrations.php';
}

// Search student function
async function searchStudent() {
    const matric = searchInput.value.trim();
    
    if (!matric) {
        showAlert('Please enter a matric number', 'warning');
        return;
    }
    
    studentInfoCard.style.display = 'block';
    studentInfoContent.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Searching for student...</p></div>';
    studentError.style.display = 'none';
    registrationForm.style.display = 'none';
    
    try {
        const response = await fetch('ajax_handler.php?action=search_student&matric=' + encodeURIComponent(matric));
        
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        
        const data = await response.json();
        
        if (data.success) {
            currentStudent = data.student;
            displayStudentInfo(data.student);
            loadCourses(data.student.current_level, data.student.department_id);
            
            document.getElementById('selectedStudentId').value = data.student.student_id;
            document.getElementById('selectedStudentLevel').value = data.student.current_level;
            
            registrationForm.style.display = 'block';
        } else {
            studentInfoCard.style.display = 'none';
            studentError.style.display = 'block';
            studentError.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Student not found. Please check the matric number.');
        }
    } catch (error) {
        console.error('Error details:', error);
        studentInfoCard.style.display = 'none';
        studentError.style.display = 'block';
        studentError.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Connection error. Please try again.';
    }
}

// Display student info function
function displayStudentInfo(student) {
    const statusClass = student.status === 'Active' ? 'success' : 'warning';
    const statusText = student.status === 'Active' ? 'Active' : 'Inactive';
    
    studentInfoContent.innerHTML = 
        '<div class="row g-3">' +
            '<div class="col-md-6">' +
                '<div class="mb-2"><small class="text-muted d-block">Matric Number</small><strong class="text-primary">' + escapeHtml(student.matric_number) + '</strong></div>' +
                '<div class="mb-2"><small class="text-muted d-block">Full Name</small><strong>' + escapeHtml(student.first_name || '') + ' ' + escapeHtml(student.middle_name || '') + ' ' + escapeHtml(student.last_name || '') + '</strong></div>' +
                '<div class="mb-2"><small class="text-muted d-block">Email Address</small><span>' + escapeHtml(student.email || 'N/A') + '</span></div>' +
            '</div>' +
            '<div class="col-md-6">' +
                '<div class="mb-2"><small class="text-muted d-block">Phone Number</small><span>' + escapeHtml(student.phone || 'N/A') + '</span></div>' +
                '<div class="mb-2"><small class="text-muted d-block">Department</small><span>' + escapeHtml(student.department_name || 'N/A') + '</span></div>' +
                '<div class="mb-2"><small class="text-muted d-block">Program</small><span>' + escapeHtml(student.program_name || 'N/A') + '</span></div>' +
                '<div class="mb-2"><small class="text-muted d-block">Current Level</small><span class="badge bg-info">Level ' + (student.current_level || 'N/A') + '</span><span class="badge bg-' + statusClass + ' ms-2">' + statusText + '</span></div>' +
            '</div>' +
        '</div>';
}

// Load courses function
async function loadCourses(level, departmentId) {
    courseSelect.innerHTML = '<option value="">Loading courses...</option>';
    courseCount.textContent = '';
    
    try {
        const response = await fetch('ajax_handler.php?action=get_courses&level=' + level + '&department_id=' + departmentId);
        const data = await response.json();
        
        if (data.success && data.courses && data.courses.length > 0) {
            let options = '<option value="">-- Select a course --</option>';
            
            data.courses.forEach(function(course) {
                const prereq = course.prerequisite_code ? ' [Prerequisite: ' + course.prerequisite_code + ']' : '';
                options += '<option value="' + course.course_id + '" data-code="' + escapeHtml(course.course_code) + '" data-credits="' + course.credit_units + '" data-prereq="' + escapeHtml(course.prerequisite_code || '') + '">' + escapeHtml(course.course_code) + ' - ' + escapeHtml(course.course_title) + ' (' + course.credit_units + ' units)' + prereq + '</option>';
            });
            
            courseSelect.innerHTML = options;
            courseCount.textContent = data.courses.length + ' courses available for Level ' + level;
        } else {
            courseSelect.innerHTML = '<option value="">No courses available for this level</option>';
            courseCount.textContent = '0 courses available';
        }
    } catch (error) {
        console.error('Error:', error);
        courseSelect.innerHTML = '<option value="">Error loading courses</option>';
        courseCount.textContent = 'Error loading courses. Please refresh.';
    }
}

// Check form completion function
function checkFormCompletion() {
    const courseSelected = courseSelect.value;
    const sessionSelected = document.querySelector('select[name="session_year"]').value;
    const semesterSelected = document.querySelector('select[name="semester"]').value;
    
    if (courseSelected && sessionSelected && semesterSelected && currentStudent) {
        submitBtn.disabled = false;
        updateSummary();
    } else {
        submitBtn.disabled = true;
        summaryCard.style.display = 'none';
    }
}

// Update summary function
function updateSummary() {
    const courseOption = courseSelect.options[courseSelect.selectedIndex];
    const courseCode = courseOption.dataset.code;
    const credits = courseOption.dataset.credits;
    const prereq = courseOption.dataset.prereq;
    const session = document.querySelector('select[name="session_year"]').value;
    const semester = document.querySelector('select[name="semester"]').value;
    
    let summaryHtml = 
        '<li><strong>Student:</strong> ' + currentStudent.matric_number + '</li>' +
        '<li><strong>Course:</strong> ' + courseCode + ' (' + credits + ' units)</li>' +
        '<li><strong>Session:</strong> ' + session + ' | Semester ' + semester + '</li>' +
        '<li><strong>Level:</strong> ' + currentStudent.current_level + '</li>';
    
    if (prereq) {
        summaryHtml += '<li class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i><strong>Prerequisite Required:</strong> ' + prereq + '</li>';
    }
    
    summaryList.innerHTML = summaryHtml;
    summaryCard.style.display = 'block';
}

// Show alert function
function showAlert(message, type) {
    type = type || 'info';
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    
    var icon = 'info-circle';
    if (type === 'success') icon = 'check-circle';
    else if (type === 'warning') icon = 'exclamation-triangle';
    
    alertDiv.innerHTML = 
        '<div class="d-flex align-items-center"><i class="fas fa-' + icon + ' me-2"></i><div>' + message + '</div></div>' +
        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    
    document.body.appendChild(alertDiv);
    setTimeout(function() {
        alertDiv.remove();
    }, 3000);
}

// Escape HTML helper
function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    if (searchBtn) {
        searchBtn.addEventListener('click', searchStudent);
    }
    
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchStudent();
            }
        });
    }
    
    if (courseSelect) {
        courseSelect.addEventListener('change', checkFormCompletion);
    }
    
    const sessionSelect = document.querySelector('select[name="session_year"]');
    const semesterSelect = document.querySelector('select[name="semester"]');
    
    if (sessionSelect) {
        sessionSelect.addEventListener('change', checkFormCompletion);
    }
    if (semesterSelect) {
        semesterSelect.addEventListener('change', checkFormCompletion);
    }
    
    updateSelectedCount();
});

// Modal clear on close
const addModal = document.getElementById('addRegistrationModal');
if (addModal) {
    addModal.addEventListener('hidden.bs.modal', function() {
        if (searchInput) searchInput.value = '';
        studentInfoCard.style.display = 'none';
        studentError.style.display = 'none';
        registrationForm.style.display = 'none';
        currentStudent = null;
        submitBtn.disabled = true;
        if (courseSelect) courseSelect.innerHTML = '<option value="">-- Select a course --</option>';
    });
}

// Form validation
const addForm = document.getElementById('addRegistrationForm');
if (addForm) {
    addForm.addEventListener('submit', function(e) {
        if (!currentStudent) {
            e.preventDefault();
            showAlert('Please search for a student first.', 'warning');
            return false;
        }
        
        if (!courseSelect.value) {
            e.preventDefault();
            showAlert('Please select a course.', 'warning');
            return false;
        }
        
        const sessionVal = document.querySelector('select[name="session_year"]')?.value;
        const semesterVal = document.querySelector('select[name="semester"]')?.value;
        
        if (!sessionVal || !semesterVal) {
            e.preventDefault();
            showAlert('Please select session and semester.', 'warning');
            return false;
        }
        
        return confirm('Add registration for student ' + currentStudent.matric_number + '?');
    });
}
</script>

<?php
require_once 'includes/footer.php';
?>