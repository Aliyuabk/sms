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
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name,
        (SELECT COUNT(*) FROM results r WHERE r.student_id = s.student_id) as course_count,
        (SELECT SUM(sf.amount - sf.amount_paid) 
         FROM student_fees sf 
         WHERE sf.student_id = s.student_id AND sf.status IN ('Pending', 'Partial')) as fee_balance
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
$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
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
                    $stmt = $pdo->prepare("UPDATE students SET status = 'Inactive' WHERE student_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " student(s) deactivated successfully!";
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
                $success_rows = [];
                
                // Check file size
                if (filesize($file_tmp_path) > 10 * 1024 * 1024) {
                    $_SESSION['error_message'] = "File size exceeds 10MB limit.";
                    header("Location: manage_students.php");
                    exit();
                }
                
                if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
                    // Read and validate headers
                    $headers = fgetcsv($handle);
                    if ($headers === FALSE) {
                        $_SESSION['error_message'] = "CSV file is empty or invalid.";
                        header("Location: manage_students.php");
                        exit();
                    }
                    
                    // Clean headers
                    $headers = array_map(function($header) {
                        $header = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header);
                        return strtolower(trim($header));
                    }, $headers);
                    
                    // Define required columns
                    $required_columns = ['matric_number', 'first_name', 'last_name', 'email'];
                    $missing_columns = [];
                    
                    foreach ($required_columns as $col) {
                        if (!in_array($col, $headers)) {
                            $missing_columns[] = $col;
                        }
                    }
                    
                    if (!empty($missing_columns)) {
                        $_SESSION['error_message'] = "Missing required column(s): " . implode(', ', $missing_columns);
                        header("Location: manage_students.php");
                        exit();
                    }
                    
                    // Create header map
                    $header_map = array_flip($headers);
                    
                    // Process each row
                    $row_num = 1;
                    $max_rows = 1000;
                    
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_num++;
                        
                        if (count($data) < 2 || (count($data) == 1 && empty($data[0]))) {
                            continue;
                        }
                        
                        if ($row_num > $max_rows + 1) {
                            $errors[] = "Row $row_num: Maximum of 1000 rows exceeded. Stopped at row 1000.";
                            break;
                        }
                        
                        try {
                            while (count($data) < count($headers)) {
                                $data[] = '';
                            }
                            
                            $matric_number = isset($header_map['matric_number']) && isset($data[$header_map['matric_number']]) 
                                ? trim($data[$header_map['matric_number']]) : '';
                            
                            $first_name = isset($header_map['first_name']) && isset($data[$header_map['first_name']]) 
                                ? trim($data[$header_map['first_name']]) : '';
                            
                            $last_name = isset($header_map['last_name']) && isset($data[$header_map['last_name']]) 
                                ? trim($data[$header_map['last_name']]) : '';
                            
                            $email = isset($header_map['email']) && isset($data[$header_map['email']]) 
                                ? trim($data[$header_map['email']]) : '';
                            
                            $middle_name = isset($header_map['middle_name']) && isset($data[$header_map['middle_name']]) 
                                ? trim($data[$header_map['middle_name']]) : '';
                            
                            $date_of_birth = isset($header_map['date_of_birth']) && isset($data[$header_map['date_of_birth']]) 
                                ? trim($data[$header_map['date_of_birth']]) : '';
                            
                            $gender = isset($header_map['gender']) && isset($data[$header_map['gender']]) 
                                ? trim($data[$header_map['gender']]) : '';
                            
                            $phone = isset($header_map['phone']) && isset($data[$header_map['phone']]) 
                                ? trim($data[$header_map['phone']]) : '';
                            
                            $jamb_reg_number = isset($header_map['jamb_reg_number']) && isset($data[$header_map['jamb_reg_number']]) 
                                ? trim($data[$header_map['jamb_reg_number']]) : '';
                            
                            $state_of_origin = isset($header_map['state_of_origin']) && isset($data[$header_map['state_of_origin']]) 
                                ? trim($data[$header_map['state_of_origin']]) : '';
                            
                            $lga = isset($header_map['lga']) && isset($data[$header_map['lga']]) 
                                ? trim($data[$header_map['lga']]) : '';
                            
                            $address = isset($header_map['address']) && isset($data[$header_map['address']]) 
                                ? trim($data[$header_map['address']]) : '';
                            
                            $nationality = isset($header_map['nationality']) && isset($data[$header_map['nationality']]) 
                                ? trim($data[$header_map['nationality']]) : 'Nigerian';
                            
                            $admission_year = isset($header_map['admission_year']) && isset($data[$header_map['admission_year']]) 
                                ? trim($data[$header_map['admission_year']]) : date('Y');
                            
                            // Validate required fields
                            if (empty($matric_number)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Matric number is required";
                                continue;
                            }
                            
                            if (empty($first_name)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: First name is required (Matric: $matric_number)";
                                continue;
                            }
                            
                            if (empty($last_name)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Last name is required (Matric: $matric_number)";
                                continue;
                            }
                            
                            if (empty($email)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Email is required (Matric: $matric_number)";
                                continue;
                            }
                            
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Invalid email format for $email (Matric: $matric_number)";
                                continue;
                            }
                            
                            if (!empty($gender) && !in_array($gender, ['Male', 'Female'])) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Gender must be 'Male' or 'Female' (Matric: $matric_number)";
                                continue;
                            }
                            
                            // Check for existing student
                            $check_sql = "SELECT student_id FROM students WHERE matric_number = ? OR email = ?";
                            $check_stmt = $pdo->prepare($check_sql);
                            $check_stmt->execute([$matric_number, $email]);
                            
                            if ($check_stmt->rowCount() > 0) {
                                $failed_count++;
                                $errors[] = "Row $row_num: Student already exists with matric: $matric_number or email: $email";
                                continue;
                            }
                            
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
                                matric_number, first_name, middle_name, last_name, email, password_hash,
                                date_of_birth, gender, phone, jamb_reg_number,
                                department_id, program_id, admission_year, current_level,
                                current_session, state_of_origin, lga, address, nationality,
                                status, registration_date
                            ) VALUES (?, ?, ?, ?, ?, 'password', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())";
                            
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
                            $success_rows[] = "Row $row_num: $first_name $last_name ($matric_number)";
                            
                        } catch (PDOException $e) {
                            $failed_count++;
                            $errors[] = "Row $row_num: Database error - " . $e->getMessage();
                        } catch (Exception $e) {
                            $failed_count++;
                            $errors[] = "Row $row_num: " . $e->getMessage();
                        }
                    }
                    fclose($handle);
                    
                    if ($imported_count > 0) {
                        $_SESSION['success_message'] = "Successfully imported $imported_count student(s)!";
                        if ($failed_count > 0) {
                            $_SESSION['success_message'] .= " ($failed_count rows failed)";
                        }
                    } else {
                        $_SESSION['error_message'] = "No students imported. $failed_count rows failed.";
                    }
                    
                    if ($failed_count > 0 && !empty($errors)) {
                        $_SESSION['import_errors'] = array_slice($errors, 0, 20);
                    }
                    
                } else {
                    $_SESSION['error_message'] = "Unable to read CSV file. Please check file permissions.";
                }
            } else {
                $_SESSION['error_message'] = "Please upload a CSV file (.csv extension).";
            }
        } else {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (max ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
                UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
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
                    $fee_count = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM student_fees WHERE amount > amount_paid AND status IN ('Pending', 'Partial')")->fetchColumn();
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
                <div class="stats-type">Results Published</div>
                <div class="stats-figure">
                    <?php 
                    $results_count = $pdo->query("SELECT COUNT(*) FROM results WHERE is_published = 1")->fetchColumn();
                    echo number_format($results_count);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-file-alt"></i> Published Results
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
                            <th class="cell">Results</th>
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
                                    <span class="badge bg-secondary"><?php echo $student['course_count']; ?> results</span>
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
                                        'Withdrawn' => 'dark'
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
                            <i class="fas fa-trash me-2"></i>Deactivate Selected
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

<!-- Import Modal with Faculty and Department Selection -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">
                    <i class="fas fa-file-import me-2"></i>Import Students
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left Column - Upload Form -->
                    <div class="col-md-5">
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Upload CSV File *</label>
                                <div class="input-group">
                                    <input type="file" 
                                           class="form-control" 
                                           name="csv_file" 
                                           accept=".csv" 
                                           required
                                           id="csvFileInput">
                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('csvFileInput').value = ''">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle text-info"></i> 
                                    Max file size: 10MB
                                </div>
                            </div>
                            
                            <!-- Faculty Selection -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Faculty *</label>
                                <select class="form-select" id="import_faculty" required>
                                    <option value="">-- Select Faculty First --</option>
                                    <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?php echo $faculty['faculty_id']; ?>">
                                        <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select faculty to filter departments</div>
                            </div>
                            
                            <!-- Department Selection (populated by AJAX) -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Department *</label>
                                <select class="form-select" name="import_department" id="import_department" required>
                                    <option value="">-- First Select Faculty --</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Program *</label>
                                <select class="form-select" name="import_program" id="import_program" required>
                                    <option value="">-- First Select Department --</option>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Level *</label>
                                    <select class="form-select" name="import_level" required>
                                        <option value="">-- Select Level --</option>
                                        <?php foreach ($levels as $lvl): ?>
                                        <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?> Level</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Academic Session</label>
                                    <input type="text" class="form-control" name="import_session" 
                                           value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>" 
                                           placeholder="e.g., 2024/2025" required>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <h6 class="alert-heading fw-bold"><i class="fas fa-lightbulb me-2"></i>Quick Tips:</h6>
                                <ul class="mb-0 small">
                                    <li>Download the template first using the button on the right</li>
                                    <li>Keep the header row exactly as provided</li>
                                    <li>Delete the sample rows before uploading</li>
                                    <li>Required fields: matric_number, first_name, last_name, email</li>
                                    <li>Default password will be 'password' for all imported students</li>
                                </ul>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Right Column - Template Info & Download -->
                    <div class="col-md-7 border-start">
                        <div class="ps-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0">
                                    <i class="fas fa-download me-2 text-primary"></i>Download Template
                                </h5>
                                <a href="download_template.php?type=students" class="btn btn-primary">
                                    <i class="fas fa-file-csv me-2"></i>Download CSV Template
                                </a>
                            </div>
                            
                            <div class="mb-4">
                                <h6 class="fw-bold text-primary">CSV Format Requirements:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Column</th>
                                                <th>Required</th>
                                                <th>Format/Example</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>matric_number</td>
                                                <td><span class="badge bg-danger">Required</span></td>
                                                <td>Unique student ID (e.g., CSC2024001)</td>
                                            </tr>
                                            <tr>
                                                <td>first_name</td>
                                                <td><span class="badge bg-danger">Required</span></td>
                                                <td>John</td>
                                            </tr>
                                            <tr>
                                                <td>last_name</td>
                                                <td><span class="badge bg-danger">Required</span></td>
                                                <td>Doe</td>
                                            </tr>
                                            <tr>
                                                <td>middle_name</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Smith</td>
                                            </tr>
                                            <tr>
                                                <td>email</td>
                                                <td><span class="badge bg-danger">Required</span></td>
                                                <td>john.doe@student.edu</td>
                                            </tr>
                                            <tr>
                                                <td>date_of_birth</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>YYYY-MM-DD (2000-05-15)</td>
                                            </tr>
                                            <tr>
                                                <td>gender</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Male / Female</td>
                                            </tr>
                                            <tr>
                                                <td>phone</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>08012345678</td>
                                            </tr>
                                            <tr>
                                                <td>jamb_reg_number</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>12345678AB</td>
                                            </tr>
                                            <tr>
                                                <td>state_of_origin</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Kano, Lagos, etc.</td>
                                            </tr>
                                            <tr>
                                                <td>lga</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Kano Municipal</td>
                                            </tr>
                                            <tr>
                                                <td>address</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Full address</td>
                                            </tr>
                                            <tr>
                                                <td>nationality</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>Nigerian (default)</td>
                                            </tr>
                                            <tr>
                                                <td>admission_year</td>
                                                <td><span class="badge bg-secondary">Optional</span></td>
                                                <td>2024 (defaults to current year)</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mb-0">
                                <h6 class="alert-heading fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Important Notes:</h6>
                                <ul class="mb-0 small">
                                    <li>The CSV file must have a header row matching the column names above</li>
                                    <li>All text fields should not contain commas (this breaks CSV format)</li>
                                    <li>If a field contains commas, enclose the entire field in double quotes</li>
                                    <li>Duplicate matric numbers or emails will be skipped with an error message</li>
                                    <li>Maximum of 1000 rows can be imported at once for performance reasons</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="submit" form="importForm" name="import_students" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload & Import Students
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Faculty -> Department -> Program cascading dropdowns
// Faculty -> Department -> Program cascading dropdowns with better error handling
document.getElementById('import_faculty').addEventListener('change', function() {
    var facultyId = this.value;
    var departmentSelect = document.getElementById('import_department');
    var programSelect = document.getElementById('import_program');
    
    // Reset department and program dropdowns
    departmentSelect.innerHTML = '<option value="">-- Loading departments... --</option>';
    programSelect.innerHTML = '<option value="">-- First select department --</option>';
    
    if (facultyId) {
        // Fetch departments for selected faculty
        fetch('ajax/get_departments_by_faculty.php?faculty_id=' + facultyId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    departmentSelect.innerHTML = '<option value="">-- Select Department --</option>';
                    if (data.data && data.data.length > 0) {
                        data.data.forEach(function(dept) {
                            var option = document.createElement('option');
                            option.value = dept.department_id;
                            option.textContent = dept.department_name + ' (' + dept.department_code + ')';
                            departmentSelect.appendChild(option);
                        });
                    } else {
                        departmentSelect.innerHTML = '<option value="">-- No departments found for this faculty --</option>';
                    }
                } else {
                    console.error('Server error:', data.message);
                    departmentSelect.innerHTML = '<option value="">-- Error: ' + (data.message || 'Unknown error') + ' --</option>';
                    // Show user-friendly error
                    alert('Error loading departments: ' + (data.message || 'Please try again'));
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                departmentSelect.innerHTML = '<option value="">-- Error loading departments --</option>';
                alert('Network error loading departments. Please check your connection and try again.');
            });
    } else {
        departmentSelect.innerHTML = '<option value="">-- First Select Faculty --</option>';
    }
});

// Department -> Program cascade
document.getElementById('import_department').addEventListener('change', function() {
    var departmentId = this.value;
    var programSelect = document.getElementById('import_program');
    
    programSelect.innerHTML = '<option value="">-- Loading programs... --</option>';
    
    if (departmentId) {
        // Fetch programs for selected department
        fetch('ajax/get_programs_by_department.php?department_id=' + departmentId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    programSelect.innerHTML = '<option value="">-- Select Program --</option>';
                    if (data.data && data.data.length > 0) {
                        data.data.forEach(function(prog) {
                            var option = document.createElement('option');
                            option.value = prog.program_id;
                            option.textContent = prog.program_name + ' (' + prog.program_code + ')';
                            programSelect.appendChild(option);
                        });
                    } else {
                        programSelect.innerHTML = '<option value="">-- No programs found for this department --</option>';
                    }
                } else {
                    console.error('Server error:', data.message);
                    programSelect.innerHTML = '<option value="">-- Error: ' + (data.message || 'Unknown error') + ' --</option>';
                    alert('Error loading programs: ' + (data.message || 'Please try again'));
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                programSelect.innerHTML = '<option value="">-- Error loading programs --</option>';
                alert('Network error loading programs. Please check your connection and try again.');
            });
    } else {
        programSelect.innerHTML = '<option value="">-- First select department --</option>';
    }
});

// Department -> Program cascade
document.getElementById('import_department').addEventListener('change', function() {
    var departmentId = this.value;
    var programSelect = document.getElementById('import_program');
    
    programSelect.innerHTML = '<option value="">-- Loading programs... --</option>';
    
    if (departmentId) {
        // Fetch programs for selected department
        fetch('ajax/get_programs_by_department.php?department_id=' + departmentId)
            .then(response => response.json())
            .then(data => {
                programSelect.innerHTML = '<option value="">-- Select Program --</option>';
                if (data.success && data.data.length > 0) {
                    data.data.forEach(function(prog) {
                        var option = document.createElement('option');
                        option.value = prog.program_id;
                        option.textContent = prog.program_name + ' (' + prog.program_code + ')';
                        programSelect.appendChild(option);
                    });
                } else {
                    programSelect.innerHTML = '<option value="">-- No programs found --</option>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                programSelect.innerHTML = '<option value="">-- Error loading programs --</option>';
            });
    } else {
        programSelect.innerHTML = '<option value="">-- First select department --</option>';
    }
});

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
            confirmMessage = `Deactivate ${selectedIds.length} selected student(s)? This will mark them as inactive.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function confirmDelete(studentId) {
    if (confirm('Are you sure you want to deactivate this student?')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_students[]';
        input.value = studentId;
        form.appendChild(input);
        
        document.getElementById('bulkActionInput').value = 'deactivate';
        form.submit();
    }
}

// Validate import form
document.querySelector('input[name="csv_file"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const maxSize = 10 * 1024 * 1024;
    
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

// Import form submission validation
document.getElementById('importForm').addEventListener('submit', function(e) {
    const fileInput = this.querySelector('input[name="csv_file"]');
    const departmentSelect = this.querySelector('select[name="import_department"]');
    const programSelect = this.querySelector('select[name="import_program"]');
    const levelSelect = this.querySelector('select[name="import_level"]');
    const facultySelect = document.getElementById('import_faculty');
    
    if (!fileInput.files.length) {
        e.preventDefault();
        alert('Please select a CSV file to upload.');
        return false;
    }
    
    if (!facultySelect.value) {
        e.preventDefault();
        alert('Please select a faculty.');
        return false;
    }
    
    if (!departmentSelect.value) {
        e.preventDefault();
        alert('Please select a department.');
        return false;
    }
    
    if (!programSelect.value) {
        e.preventDefault();
        alert('Please select a program.');
        return false;
    }
    
    if (!levelSelect.value) {
        e.preventDefault();
        alert('Please select a level.');
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

<style>
#importModal .table-sm td {
    padding: 0.3rem;
    font-size: 0.85rem;
}

#importModal .badge {
    font-size: 0.7rem;
}

#importModal .border-start {
    border-left: 1px solid #dee2e6 !important;
}

#importModal .alert-info {
    background-color: #e7f3ff;
    border-color: #b8daff;
}

#importModal .alert-warning {
    background-color: #fff3cd;
    border-color: #ffeeba;
}
</style>

<?php
require_once 'includes/footer.php';
?>