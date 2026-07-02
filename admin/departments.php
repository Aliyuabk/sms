<?php
// departments.php
ob_start();

// Helper function for export URLs
function getExportQueryString() {
    $params = [];
    
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['faculty'])) {
        $params['faculty'] = $_GET['faculty'];
    }
    
    return !empty($params) ? '&' . http_build_query($params) : '';
}

require_once 'includes/header.php';

// Set page title
$page_title = "Manage Departments";

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$faculty_filter = isset($_GET['faculty']) ? (int)$_GET['faculty'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(d.department_code LIKE ? OR d.department_name LIKE ? OR d.hod_name LIKE ? OR d.email LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if (!empty($faculty_filter)) {
    $conditions[] = "d.faculty_id = ?";
    $params[] = $faculty_filter;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "SELECT COUNT(*) as total FROM departments d {$where_clause}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get departments data with faculty information
$sql = "
    SELECT d.*,
           f.faculty_id,
           f.faculty_name,
           f.faculty_code,
           f.status as faculty_status,
           (SELECT COUNT(*) FROM students WHERE department_id = d.department_id) as student_count,
           (SELECT COUNT(*) FROM programs WHERE department_id = d.department_id) as program_count,
           (SELECT COUNT(*) FROM courses WHERE department_id = d.department_id) as course_count
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    {$where_clause}
    ORDER BY d.department_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$departments = $stmt->fetchAll();

// Get all active faculties from faculties table for filter
$faculties_sql = "SELECT faculty_id, faculty_name, faculty_code FROM faculties WHERE status = 'Active' ORDER BY faculty_name";
$faculties = $pdo->query($faculties_sql)->fetchAll();

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_departments'])) {
        $selected_ids = $_POST['selected_departments'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        switch ($_POST['bulk_action']) {
            case 'delete':
                // Check if departments have students, programs, or courses
                $check_sql = "
                    SELECT d.department_name, 
                           (SELECT COUNT(*) FROM students WHERE department_id = d.department_id) as student_count,
                           (SELECT COUNT(*) FROM programs WHERE department_id = d.department_id) as program_count,
                           (SELECT COUNT(*) FROM courses WHERE department_id = d.department_id) as course_count
                    FROM departments d 
                    WHERE d.department_id IN ($placeholders)
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute($selected_ids);
                $departments_info = $check_stmt->fetchAll();
                
                $has_dependencies = false;
                $dept_names = [];
                
                foreach ($departments_info as $dept) {
                    if ($dept['student_count'] > 0 || $dept['program_count'] > 0 || $dept['course_count'] > 0) {
                        $has_dependencies = true;
                        $dept_names[] = $dept['department_name'];
                    }
                }
                
                if ($has_dependencies) {
                    $_SESSION['error_message'] = "Cannot delete department(s) with students, programs, or courses: " . implode(', ', $dept_names) . ". Please reassign or delete dependent records first.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " department(s) deleted successfully!";
                }
                break;
        }
        
        header("Location: departments.php");
        exit();
    }
    
    // Add new department
    if (isset($_POST['add_department'])) {
        $department_code = strtoupper(trim($_POST['department_code'] ?? ''));
        $department_name = trim($_POST['department_name'] ?? '');
        $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
        $hod_name = trim($_POST['hod_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Validate
        if (empty($department_code) || empty($department_name)) {
            $_SESSION['error_message'] = "Department code and name are required!";
        } else {
            try {
                // Check if department code or name already exists
                $check_stmt = $pdo->prepare("SELECT department_id FROM departments WHERE department_code = ? OR department_name = ?");
                $check_stmt->execute([$department_code, $department_name]);
                
                if ($check_stmt->rowCount() > 0) {
                    $_SESSION['error_message'] = "Department with this code or name already exists!";
                } else {
                    $insert_sql = "INSERT INTO departments 
                        (department_code, department_name, faculty_id, hod_name, email, phone, created_date) 
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE())";
                    
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $department_code, $department_name, $faculty_id, $hod_name, $email, $phone
                    ]);
                    
                    $_SESSION['success_message'] = "Department added successfully!";
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error adding department: " . $e->getMessage();
            }
        }
        
        header("Location: departments.php");
        exit();
    }
    
    // Edit department (if you have this functionality)
    if (isset($_POST['edit_department'])) {
        $department_id = (int)$_POST['department_id'];
        $department_code = strtoupper(trim($_POST['department_code'] ?? ''));
        $department_name = trim($_POST['department_name'] ?? '');
        $faculty_id = !empty($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
        $hod_name = trim($_POST['hod_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        try {
            // Check if department code or name already exists (excluding current)
            $check_stmt = $pdo->prepare("SELECT department_id FROM departments WHERE (department_code = ? OR department_name = ?) AND department_id != ?");
            $check_stmt->execute([$department_code, $department_name, $department_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Another department with this code or name already exists!";
            } else {
                $update_sql = "UPDATE departments SET 
                    department_code = ?, department_name = ?, faculty_id = ?, 
                    hod_name = ?, email = ?, phone = ?
                    WHERE department_id = ?";
                
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $department_code, $department_name, $faculty_id, 
                    $hod_name, $email, $phone, $department_id
                ]);
                
                $_SESSION['success_message'] = "Department updated successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating department: " . $e->getMessage();
        }
        
        header("Location: departments.php");
        exit();
    }
    
    // Delete single department
    if (isset($_POST['delete_department'])) {
        $department_id = (int)$_POST['department_id'];
        
        // Check if department has students, programs, or courses
        $check_sql = "
            SELECT department_name,
                   (SELECT COUNT(*) FROM students WHERE department_id = ?) as student_count,
                   (SELECT COUNT(*) FROM programs WHERE department_id = ?) as program_count,
                   (SELECT COUNT(*) FROM courses WHERE department_id = ?) as course_count
            FROM departments WHERE department_id = ?
        ";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$department_id, $department_id, $department_id, $department_id]);
        $dept_info = $check_stmt->fetch();
        
        if ($dept_info && ($dept_info['student_count'] > 0 || $dept_info['program_count'] > 0 || $dept_info['course_count'] > 0)) {
            $_SESSION['error_message'] = "Cannot delete department '{$dept_info['department_name']}' because it has {$dept_info['student_count']} students, {$dept_info['program_count']} programs, and {$dept_info['course_count']} courses assigned.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id = ?");
            $stmt->execute([$department_id]);
            $_SESSION['success_message'] = "Department deleted successfully!";
        }
        
        header("Location: departments.php");
        exit();
    }
}

// Display success/error messages
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
    <h1 class="app-page-title mb-0">Manage Departments</h1>
    <div class="app-actions">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
            <i class="fas fa-plus me-2"></i>Add New Department
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
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Search by code, name, HOD..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-5">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty">
                    <option value="">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                    <option value="<?php echo $faculty['faculty_id']; ?>" 
                        <?php echo ($faculty_filter == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($faculty['faculty_name']); ?> 
                        (<?php echo htmlspecialchars($faculty['faculty_code']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                    <a href="departments.php" class="btn btn-outline-secondary">
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
                <div class="stats-type">Total Departments</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-building text-primary"></i> All Departments
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Students</div>
                <div class="stats-figure">
                    <?php 
                    $total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
                    echo number_format($total_students);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-user-graduate"></i> Enrolled Students
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Programs</div>
                <div class="stats-figure">
                    <?php 
                    $total_programs = $pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn();
                    echo number_format($total_programs);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-book"></i> Academic Programs
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Faculties</div>
                <div class="stats-figure">
                    <?php 
                    $faculty_count = $pdo->query("SELECT COUNT(*) FROM faculties WHERE status = 'Active'")->fetchColumn();
                    echo number_format($faculty_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-university"></i> Active Faculties
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Departments Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Departments List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> departments
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_departments.php?format=excel<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_departments.php?format=pdf<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_departments.php?format=csv<?php echo getExportQueryString(); ?>">
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
                            <th class="cell">Department Code</th>
                            <th class="cell">Department Name</th>
                            <th class="cell">Faculty</th>
                            <th class="cell">HOD</th>
                            <th class="cell">Courses</th>
                            <th class="cell">Programs</th>
                            <th class="cell">Students</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($departments)): ?>
                            <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_departments[]" 
                                               value="<?php echo $dept['department_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <strong><?php echo htmlspecialchars($dept['department_code']); ?></strong>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="app-icon-holder icon-holder-sm me-2">
                                            <i class="fas fa-building text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($dept['department_name']); ?></div>
                                            <?php if ($dept['email']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($dept['email']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($dept['faculty_name']): ?>
                                    <span class="badge bg-info" data-bs-toggle="tooltip" title="Code: <?php echo htmlspecialchars($dept['faculty_code']); ?>">
                                        <?php echo htmlspecialchars($dept['faculty_name']); ?>
                                    </span>
                                    <?php if ($dept['faculty_status'] != 'Active'): ?>
                                    <small class="d-block text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> Faculty Inactive
                                    </small>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php echo htmlspecialchars($dept['hod_name'] ?? 'N/A'); ?>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold <?php echo ($dept['course_count'] > 0) ? 'text-primary' : 'text-muted'; ?>">
                                            <?php echo $dept['course_count']; ?>
                                        </div>
                                        <small class="text-muted">Courses</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $dept['program_count']; ?></div>
                                        <small class="text-muted">Programs</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $dept['student_count']; ?></div>
                                        <small class="text-muted">Students</small>
                                    </div>
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
                                                <a class="dropdown-item" href="#" 
                                                   onclick='viewDepartment(<?php echo json_encode($dept); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#viewDepartmentModal">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick='editDepartment(<?php echo json_encode($dept); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#editDepartmentModal">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="courses.php?department_id=<?php echo $dept['department_id']; ?>">
                                                    <i class="fas fa-book me-2"></i>View Courses
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="programs.php?department_id=<?php echo $dept['department_id']; ?>">
                                                    <i class="fas fa-graduation-cap me-2"></i>View Programs
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="students.php?department_id=<?php echo $dept['department_id']; ?>">
                                                    <i class="fas fa-user-graduate me-2"></i>View Students
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $dept['department_id']; ?>, '<?php echo htmlspecialchars(addslashes($dept['department_name'])); ?>')">
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
                                <td colspan="9" class="text-center py-4">
                                    <div class="py-3">
                                        <i class="fas fa-building fa-3x text-muted mb-3"></i>
                                        <h5>No departments found</h5>
                                        <p class="text-muted">No departments match your search criteria.</p>
                                        <?php if ($search || $faculty_filter): ?>
                                        <a href="departments.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                                            <i class="fas fa-plus me-1"></i>Add New Department
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

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDepartmentModalLabel">
                    <i class="fas fa-plus me-2"></i>Add New Department
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department_code" required 
                                   placeholder="e.g., CSC, MTH, BUS" maxlength="10" style="text-transform:uppercase">
                            <div class="form-text">Unique code for the department (max 10 characters)</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department_name" required 
                                   placeholder="e.g., Computer Science">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty</label>
                            <select class="form-select" name="faculty_id">
                                <option value="">Select Faculty</option>
                                <?php 
                                // Get all active faculties for dropdown
                                $faculty_options = $pdo->query("SELECT faculty_id, faculty_name, faculty_code FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
                                foreach ($faculty_options as $faculty): 
                                ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?> 
                                    (<?php echo htmlspecialchars($faculty['faculty_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Head of Department (HOD)</label>
                            <input type="text" class="form-control" name="hod_name" 
                                   placeholder="e.g., Prof. James Anderson">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   placeholder="e.g., department@university.edu">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" 
                                   placeholder="e.g., 08011112222">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_department" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-labelledby="editDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editDepartmentModalLabel">
                    <i class="fas fa-edit me-2"></i>Edit Department
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="editDepartmentForm">
                <input type="hidden" name="department_id" id="edit_department_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department_code" id="edit_department_code" required 
                                   style="text-transform:uppercase">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department_name" id="edit_department_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty</label>
                            <select class="form-select" name="faculty_id" id="edit_faculty_id">
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculty_options as $faculty): ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?> 
                                    (<?php echo htmlspecialchars($faculty['faculty_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Head of Department (HOD)</label>
                            <input type="text" class="form-control" name="hod_name" id="edit_hod_name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_department" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Department Modal -->
<div class="modal fade" id="viewDepartmentModal" tabindex="-1" aria-labelledby="viewDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewDepartmentModalLabel">
                    <i class="fas fa-building me-2"></i>Department Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4 id="view_dept_name" class="mb-1"></h4>
                        <p class="text-muted mb-0" id="view_dept_code"></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-university me-2"></i>Faculty Information</h6>
                            <p class="mb-1" id="view_faculty"></p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-user-tie me-2"></i>Head of Department</h6>
                            <p class="mb-0" id="view_hod"></p>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-envelope me-2"></i>Contact Information</h6>
                            <p class="mb-1" id="view_email"></p>
                            <p class="mb-0" id="view_phone"></p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
                            <div class="row">
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_courses"></div>
                                        <small class="text-muted">Courses</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_programs_count"></div>
                                        <small class="text-muted">Programs</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_students_count"></div>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-calendar-alt me-2"></i>Created Date</h6>
                    <p class="mb-0" id="view_created_date"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteConfirmModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="department_id" id="delete_department_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the department: <strong id="delete_department_name"></strong>?</p>
                    <p class="text-danger"><small>This action cannot be undone. Make sure there are no courses, students, or programs assigned to this department.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_department" class="btn btn-danger">Delete Department</button>
                </div>
            </form>
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
        alert('Please select at least one department.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected department(s)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected department IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Edit department function
function editDepartment(dept) {
    document.getElementById('edit_department_id').value = dept.department_id;
    document.getElementById('edit_department_code').value = dept.department_code;
    document.getElementById('edit_department_name').value = dept.department_name;
    document.getElementById('edit_faculty_id').value = dept.faculty_id || '';
    document.getElementById('edit_hod_name').value = dept.hod_name || '';
    document.getElementById('edit_email').value = dept.email || '';
    document.getElementById('edit_phone').value = dept.phone || '';
    
    const editModal = new bootstrap.Modal(document.getElementById('editDepartmentModal'));
    editModal.show();
}

// View department function
function viewDepartment(dept) {
    document.getElementById('view_dept_name').textContent = dept.department_name;
    document.getElementById('view_dept_code').textContent = `Code: ${dept.department_code}`;
    document.getElementById('view_faculty').textContent = dept.faculty_name || 'Not assigned';
    document.getElementById('view_hod').textContent = dept.hod_name || 'Not specified';
    document.getElementById('view_email').textContent = dept.email || 'Not specified';
    document.getElementById('view_phone').textContent = dept.phone || 'Not specified';
    document.getElementById('view_courses').textContent = dept.course_count || 0;
    document.getElementById('view_programs_count').textContent = dept.program_count || 0;
    document.getElementById('view_students_count').textContent = dept.student_count || 0;
    document.getElementById('view_created_date').textContent = dept.created_date ? new Date(dept.created_date).toLocaleDateString() : 'N/A';
    
    const viewModal = new bootstrap.Modal(document.getElementById('viewDepartmentModal'));
    viewModal.show();
}

// Delete confirmation
function confirmDelete(departmentId, departmentName) {
    document.getElementById('delete_department_id').value = departmentId;
    document.getElementById('delete_department_name').textContent = departmentName;
    
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});
</script>

<?php
require_once 'includes/footer.php';
?>