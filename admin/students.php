<?php
// manage_students.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Manage Students";

// Helper function for export URLs
function getExportQueryString() {
    $params = [];
    
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['department_id'])) {
        $params['department_id'] = $_GET['department_id'];
    }
    if (!empty($_GET['program_id'])) {
        $params['program_id'] = $_GET['program_id'];
    }
    if (!empty($_GET['level'])) {
        $params['level'] = $_GET['level'];
    }
    if (!empty($_GET['status'])) {
        $params['status'] = $_GET['status'];
    }
    
    return !empty($params) ? '&' . http_build_query($params) : '';
}

// Pagination settings
$records_per_page = 15;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.jamb_reg_number LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

if ($level > 0) {
    $conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if ($status !== '') {
    $conditions[] = "s.status = ?";
    $params[] = $status;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get students data
$sql = "
    SELECT 
        s.*,
        d.department_name,
        p.program_name,
        CONCAT(s.first_name, ' ', s.last_name) as full_name,
        (SELECT COUNT(*) FROM course_registrations cr WHERE cr.student_id = s.student_id) as course_count,
        (SELECT SUM(balance) FROM student_fees sf WHERE sf.student_id = s.student_id AND sf.status IN ('Pending', 'Partial')) as fee_balance
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    {$where_clause}
    ORDER BY s.registration_date DESC
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get data for filters
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$status_options = ['Active', 'Inactive', 'Graduated', 'Suspended', 'Withdrawn'];

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_students'])) {
        $selected_ids = $_POST['selected_students'];
        
        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            switch ($_POST['bulk_action']) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE students SET status = 'Active' WHERE student_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " student(s) activated successfully!";
                    break;
                    
                case 'deactivate':
                    $stmt = $pdo->prepare("UPDATE students SET status = 'Inactive' WHERE student_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " student(s) deactivated successfully!";
                    break;
                    
                case 'delete':
                    // Soft delete
                    $stmt = $pdo->prepare("UPDATE students SET status = 'Deleted' WHERE student_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " student(s) deleted successfully!";
                    break;
            }
        }
        
        header("Location: manage_students.php");
        exit();
    }
    
    // Import students from CSV
    if (isset($_POST['import_students']) && isset($_FILES['csv_file'])) {
        if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            $file_name = $_FILES['csv_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Get import settings from form
            $import_department = $_POST['import_department'] ?? 0;
            $import_program = $_POST['import_program'] ?? 0;
            $import_level = $_POST['import_level'] ?? 100;
            $import_session = $_POST['import_session'] ?? date('Y') . '/' . (date('Y') + 1);
            
            if ($file_ext === 'csv') {
                $imported_count = 0;
                $failed_count = 0;
                $errors = [];
                
                if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
                    // Read and validate headers
                    $headers = fgetcsv($handle);
                    if ($headers === FALSE) {
                        $_SESSION['error_message'] = "CSV file is empty or invalid.";
                        header("Location: manage_students.php");
                        exit();
                    }
                    
                    // Convert headers to lowercase for case-insensitive matching
                    $header_map = [];
                    foreach ($headers as $index => $header) {
                        $header_map[strtolower(trim($header))] = $index;
                    }
                    
                    // Required columns
                    $required_columns = ['matric_number', 'first_name', 'last_name', 'email'];
                    foreach ($required_columns as $col) {
                        if (!isset($header_map[$col])) {
                            $_SESSION['error_message'] = "Missing required column: $col";
                            header("Location: manage_students.php");
                            exit();
                        }
                    }
                    
                    // Process each row
                    $row_num = 1;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_num++;
                        try {
                            // Map data using headers
                            $matric_number = isset($header_map['matric_number']) ? trim($data[$header_map['matric_number']]) : '';
                            $first_name = isset($header_map['first_name']) ? trim($data[$header_map['first_name']]) : '';
                            $last_name = isset($header_map['last_name']) ? trim($data[$header_map['last_name']]) : '';
                            $email = isset($header_map['email']) ? trim($data[$header_map['email']]) : '';
                            
                            // Optional fields
                            $middle_name = isset($header_map['middle_name']) ? trim($data[$header_map['middle_name']]) : '';
                            $date_of_birth = isset($header_map['date_of_birth']) ? trim($data[$header_map['date_of_birth']]) : '';
                            $gender = isset($header_map['gender']) ? trim($data[$header_map['gender']]) : '';
                            $phone = isset($header_map['phone']) ? trim($data[$header_map['phone']]) : '';
                            $jamb_reg_number = isset($header_map['jamb_reg_number']) ? trim($data[$header_map['jamb_reg_number']]) : '';
                            $state_of_origin = isset($header_map['state_of_origin']) ? trim($data[$header_map['state_of_origin']]) : '';
                            $lga = isset($header_map['lga']) ? trim($data[$header_map['lga']]) : '';
                            $address = isset($header_map['address']) ? trim($data[$header_map['address']]) : '';
                            $admission_year = isset($header_map['admission_year']) ? trim($data[$header_map['admission_year']]) : date('Y');
                            $nationality = isset($header_map['nationality']) ? trim($data[$header_map['nationality']]) : 'Nigerian';
                            
                            // Validate required fields
                            if (empty($matric_number) || empty($first_name) || empty($last_name) || empty($email)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Missing required fields";
                                continue;
                            }
                            
                            // Validate email
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Invalid email format";
                                continue;
                            }
                            
                            // Check for existing student
                            $check_sql = "SELECT student_id FROM students WHERE matric_number = ? OR email = ?";
                            $check_stmt = $pdo->prepare($check_sql);
                            $check_stmt->execute([$matric_number, $email]);
                            
                            if ($check_stmt->rowCount() > 0) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Student already exists (Matric: $matric_number)";
                                continue;
                            }
                            
                            //  
                            
                            // Format date of birth
                            $dob_formatted = null;
                            if (!empty($date_of_birth)) {
                                $timestamp = strtotime($date_of_birth);
                                if ($timestamp !== false) {
                                    $dob_formatted = date('Y-m-d', $timestamp);
                                }
                            }
                            
                            // Insert student
                            $insert_sql = "INSERT INTO students (
                                matric_number, first_name, middle_name, last_name, email,
                                date_of_birth, gender, phone, jamb_reg_number,
                                department_id, program_id, admission_year, current_level,
                                current_session, state_of_origin, lga, address, nationality,
                                status, registration_date
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())";
                            
                            $insert_stmt = $pdo->prepare($insert_sql);
                            $insert_stmt->execute([
                                $matric_number,
                                $first_name,
                                $middle_name,
                                $last_name,
                                $email,
                                $dob_formatted,
                                $gender,
                                $phone,
                                $jamb_reg_number,
                                $import_department,
                                $import_program,
                                $admission_year,
                                $import_level,
                                $import_session,
                                $state_of_origin,
                                $lga,
                                $address,
                                $nationality
                            ]);
                            
                            $imported_count++;
                            
                        } catch (Exception $e) {
                            $failed_count++;
                            $errors[] = "Row $row_num: " . $e->getMessage();
                        }
                    }
                    fclose($handle);
                    
                    // Set result messages
                    if ($imported_count > 0) {
                        $_SESSION['success_message'] = "Successfully imported $imported_count student(s)!";
                        if ($failed_count > 0) {
                            $_SESSION['success_message'] .= " ($failed_count failed)";
                        }
                    } else {
                        $_SESSION['error_message'] = "No students imported. $failed_count rows failed.";
                    }
                    
                    // Store detailed errors in session for debugging
                    if ($failed_count > 0 && !empty($errors)) {
                        $_SESSION['import_errors'] = array_slice($errors, 0, 10);
                    }
                    
                } else {
                    $_SESSION['error_message'] = "Unable to read CSV file.";
                }
            } else {
                $_SESSION['error_message'] = "Please upload a CSV file (.csv extension).";
            }
        } else {
            // Handle upload errors
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (max 10MB)',
                UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
                UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped'
            ];
            $_SESSION['error_message'] = "Upload error: " . ($upload_errors[$_FILES['csv_file']['error']] ?? 'Unknown error');
        }
        
        header("Location: manage_students.php");
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

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <h6><i class="fas fa-exclamation-triangle me-2"></i>Import Errors:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Manage Students</h1>
    <div class="app-actions">
        <a href="add_student.php" class="btn app-btn-primary">
            <i class="fas fa-user-plus me-2"></i>Add New Student
        </a>
        <button class="btn app-btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import me-2"></i>Import Students
        </button>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters & Search
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Name, matric, email, JAMB..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        <?php echo ($department_id == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
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
                        <?php echo ($program_id == $prog['program_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($prog['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="0">All Levels</option>
                    <?php foreach ($levels as $lvl): ?>
                    <option value="<?php echo $lvl; ?>" 
                        <?php echo ($level == $lvl) ? 'selected' : ''; ?>>
                        <?php echo $lvl; ?> Level
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $stat): ?>
                    <option value="<?php echo $stat; ?>"
                        <?php echo ($status == $stat) ? 'selected' : ''; ?>>
                        <?php echo $stat; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="manage_students.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Students</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-user-graduate text-primary"></i> All Students
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Students</div>
                <div class="stats-figure">
                    <?php 
                    $active_count = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Active'")->fetchColumn();
                    echo number_format($active_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently Active
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">With Fees Balance</div>
                <div class="stats-figure">
                    <?php 
                    $fee_count = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM student_fees WHERE balance > 0 AND status IN ('Pending', 'Partial')")->fetchColumn();
                    echo number_format($fee_count);
                    ?>
                </div>
                <div class="stats-meta text-danger">
                    <i class="fas fa-money-bill-wave"></i> Outstanding Fees
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Registered Courses</div>
                <div class="stats-figure">
                    <?php 
                    $course_count = $pdo->query("SELECT COUNT(*) FROM course_registrations WHERE registration_status = 'Approved'")->fetchColumn();
                    echo number_format($course_count);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-book"></i> Course Registrations
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Students Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Students List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> students
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_students.php?format=excel<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_students.php?format=pdf<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_students.php?format=csv<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-csv me-2"></i>CSV
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
                            <th class="cell">Matric No</th>
                            <th class="cell">Student Name</th>
                            <th class="cell">Department</th>
                            <th class="cell">Level</th>
                            <th class="cell">Courses</th>
                            <th class="cell">Fees Balance</th>
                            <th class="cell">Status</th>
                            <th class="cell">Registered</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_students[]" 
                                               value="<?php echo $student['student_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <strong><?php echo htmlspecialchars($student['matric_number']); ?></strong>
                                    <?php if (!empty($student['jamb_reg_number'])): ?>
                                    <br><small class="text-muted">JAMB: <?php echo htmlspecialchars($student['jamb_reg_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="app-icon-holder icon-holder-sm me-2">
                                            <i class="fas fa-user text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></small>
                                </td>
                                <td class="cell">
                                    <span class="badge bg-info"><?php echo $student['current_level']; ?> Level</span>
                                </td>
                                <td class="cell">
                                    <span class="badge bg-secondary"><?php echo $student['course_count']; ?> courses</span>
                                </td>
                                <td class="cell">
                                    <?php if ($student['fee_balance'] > 0): ?>
                                    <span class="badge bg-danger">₦<?php echo number_format($student['fee_balance'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-success">Paid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Active' => 'success',
                                        'Inactive' => 'secondary',
                                        'Graduated' => 'warning',
                                        'Suspended' => 'danger',
                                        'Withdrawn' => 'dark',
                                        'Deleted' => 'dark'
                                    ][$student['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($student['status']); ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <?php echo date('M d, Y', strtotime($student['registration_date'])); ?>
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
                                                <a class="dropdown-item" href="view_student.php?id=<?php echo $student['student_id']; ?>">
                                                    <i class="fas fa-eye me-2"></i>View Profile
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_student.php?id=<?php echo $student['student_id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="student_fees.php?student_id=<?php echo $student['student_id']; ?>">
                                                    <i class="fas fa-money-bill-wave me-2"></i>Manage Fees
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="student_results.php?student_id=<?php echo $student['student_id']; ?>">
                                                    <i class="fas fa-chart-bar me-2"></i>View Results
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="course_registrations.php?student_id=<?php echo $student['student_id']; ?>">
                                                    <i class="fas fa-book me-2"></i>Course Registration
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $student['student_id']; ?>)">
                                                    <i class="fas fa-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                                        <h5>No students found</h5>
                                        <p class="text-muted">No students match your search criteria.</p>
                                        <?php if ($search || $department_id || $program_id || $level || $status): ?>
                                        <a href="manage_students.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <a href="add_student.php" class="btn btn-primary">
                                            <i class="fas fa-user-plus me-1"></i>Add New Student
                                        </a>
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
                        <li><a class="dropdown-item" href="#" onclick="submitBulkAction('activate')">
                            <i class="fas fa-check-circle me-2 text-success"></i>Activate Selected
                        </a></li>
                        <li><a class="dropdown-item" href="#" onclick="submitBulkAction('deactivate')">
                            <i class="fas fa-ban me-2 text-danger"></i>Deactivate Selected
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

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">
                    <i class="fas fa-file-import me-2"></i>Import Students
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Upload CSV File *</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                            <div class="form-text">
                                <a href="download_template.php?type=students" class="text-primary" target="_blank">
                                    <i class="fas fa-download me-1"></i>Download CSV Template
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="import_department" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Program *</label>
                            <select class="form-select" name="import_program" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>">
                                    <?php echo htmlspecialchars($prog['program_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="import_level" required>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Academic Session</label>
                            <input type="text" class="form-control" name="import_session" 
                                   value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>" required>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements:</h6>
                        <ul class="mb-2 small">
                            <li><strong>Required columns:</strong> matric_number, first_name, last_name, email</li>
                            <li><strong>Optional columns:</strong> middle_name, date_of_birth, gender, phone, jamb_reg_number, 
                                state_of_origin, lga, address, admission_year, nationality</li>
                            <li><strong>Date format:</strong> YYYY-MM-DD (e.g., 2000-05-15)</li>
                            <li><strong>Gender:</strong> Male or Female</li>
                            <li>CSV must have a header row</li>
                            <li>Max file size: 10MB</li>
                        </ul>
                        <p class="mb-0 small"><strong>Note:</strong> Default password will be password.</p>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="importForm" name="import_students" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload & Import
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
        alert('Please select at least one student.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected student(s)?`;
            break;
        case 'deactivate':
            confirmMessage = `Deactivate ${selectedIds.length} selected student(s)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected student(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected student IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Single student delete
function confirmDelete(studentId) {
    if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
        // Add to bulk form and submit
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_students[]';
        input.value = studentId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Validate import form
document.querySelector('input[name="csv_file"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const maxSize = 10 * 1024 * 1024; // 10MB
    
    if (file) {
        if (file.size > maxSize) {
            alert('File size exceeds 10MB limit.');
            e.target.value = '';
        } else if (!file.name.toLowerCase().endsWith('.csv')) {
            alert('Please select a CSV file.');
            e.target.value = '';
        }
    }
});

// Import form submission
document.getElementById('importForm').addEventListener('submit', function(e) {
    const fileInput = this.querySelector('input[name="csv_file"]');
    const departmentSelect = this.querySelector('select[name="import_department"]');
    const programSelect = this.querySelector('select[name="import_program"]');
    const levelSelect = this.querySelector('select[name="import_level"]');
    
    if (!fileInput.files.length) {
        e.preventDefault();
        alert('Please select a CSV file to upload.');
        return false;
    }
    
    if (!departmentSelect.value || !programSelect.value || !levelSelect.value) {
        e.preventDefault();
        alert('Please select department, program, and level.');
        return false;
    }
    
    if (!confirm('Are you sure you want to import students? This action may take several minutes.')) {
        e.preventDefault();
        return false;
    }
    
    // Show loading
    const submitBtn = this.querySelector('button[name="import_students"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    submitBtn.disabled = true;
});
</script>

<?php
require_once 'includes/footer.php';
?>