<?php
// programs.php
ob_start();

// Helper function for export URLs
function getExportQueryString() {
    $params = [];
    
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['department_id'])) {
        $params['department_id'] = $_GET['department_id'];
    }
    if (!empty($_GET['faculty'])) {
        $params['faculty'] = $_GET['faculty'];
    }
    if (!empty($_GET['degree_type'])) {
        $params['degree_type'] = $_GET['degree_type'];
    }
    
    return !empty($params) ? '&' . http_build_query($params) : '';
}

require_once 'includes/header.php';

// Set page title
$page_title = "Manage Programs";

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$faculty_filter = isset($_GET['faculty']) ? (int)$_GET['faculty'] : 0;
$degree_type = isset($_GET['degree_type']) ? $_GET['degree_type'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(p.program_code LIKE ? OR p.program_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term]);
}

if ($department_id > 0) {
    $conditions[] = "p.department_id = ?";
    $params[] = $department_id;
}

if ($faculty_filter > 0) {
    $conditions[] = "d.faculty_id = ?";
    $params[] = $faculty_filter;
}

if ($degree_type !== '') {
    $conditions[] = "p.degree_type = ?";
    $params[] = $degree_type;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM programs p
    LEFT JOIN departments d ON p.department_id = d.department_id
    {$where_clause}
";

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get programs data
$sql = "
    SELECT p.*, 
           d.department_name,
           d.faculty_id,
           f.faculty_name,
           (SELECT COUNT(*) FROM students WHERE program_id = p.program_id) as student_count
    FROM programs p
    LEFT JOIN departments d ON p.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    {$where_clause}
    ORDER BY p.program_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$programs = $stmt->fetchAll();

// Get departments for filter
$departments = $pdo->query("SELECT d.*, f.faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.faculty_id ORDER BY d.department_name")->fetchAll();

// Get faculties for filter
$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();

// Degree types
$degree_types = ['Undergraduate', 'Postgraduate', 'Diploma', 'Certificate'];

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_programs'])) {
        $selected_ids = $_POST['selected_programs'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        switch ($_POST['bulk_action']) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE programs SET is_active = 1 WHERE program_id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $_SESSION['success_message'] = count($selected_ids) . " program(s) activated successfully!";
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE programs SET is_active = 0 WHERE program_id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $_SESSION['success_message'] = count($selected_ids) . " program(s) deactivated successfully!";
                break;
                
            case 'delete':
                // Check if programs have students
                $check_sql = "
                    SELECT p.program_name, 
                           (SELECT COUNT(*) FROM students WHERE program_id = p.program_id) as student_count
                    FROM programs p 
                    WHERE p.program_id IN ($placeholders)
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute($selected_ids);
                $programs_info = $check_stmt->fetchAll();
                
                $has_students = false;
                $program_names = [];
                
                foreach ($programs_info as $program) {
                    if ($program['student_count'] > 0) {
                        $has_students = true;
                        $program_names[] = $program['program_name'];
                    }
                }
                
                if ($has_students) {
                    $_SESSION['error_message'] = "Cannot delete program(s) with enrolled students: " . implode(', ', $program_names) . ". Please reassign students first.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " program(s) deleted successfully!";
                }
                break;
        }
        
        header("Location: programs.php");
        exit();
    }
    
    // Add new program
    if (isset($_POST['add_program'])) {
        $program_code = strtoupper(trim($_POST['program_code'] ?? ''));
        $program_name = trim($_POST['program_name'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $duration_years = (int)($_POST['duration_years'] ?? 4);
        $total_credits = (int)($_POST['total_credits'] ?? 120);
        $degree_type = $_POST['degree_type'] ?? 'Undergraduate';
        
        // Validate
        if (empty($program_code) || empty($program_name) || $department_id <= 0) {
            $_SESSION['error_message'] = "Program code, name, and department are required!";
        } else {
            try {
                // Check if program code already exists
                $check_stmt = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ?");
                $check_stmt->execute([$program_code]);
                
                if ($check_stmt->rowCount() > 0) {
                    $_SESSION['error_message'] = "Program with this code already exists!";
                } else {
                    $insert_sql = "INSERT INTO programs 
                        (program_code, program_name, department_id, duration_years, total_credits, degree_type, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, 1)";
                    
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $program_code, $program_name, $department_id, $duration_years, $total_credits, $degree_type
                    ]);
                    
                    $_SESSION['success_message'] = "Program added successfully!";
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error adding program: " . $e->getMessage();
            }
        }
        
        header("Location: programs.php");
        exit();
    }
    
    // Edit program
    if (isset($_POST['edit_program'])) {
        $program_id = (int)$_POST['program_id'];
        $program_code = strtoupper(trim($_POST['program_code'] ?? ''));
        $program_name = trim($_POST['program_name'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $duration_years = (int)($_POST['duration_years'] ?? 4);
        $total_credits = (int)($_POST['total_credits'] ?? 120);
        $degree_type = $_POST['degree_type'] ?? 'Undergraduate';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            // Check if program code already exists (excluding current)
            $check_stmt = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ? AND program_id != ?");
            $check_stmt->execute([$program_code, $program_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Another program with this code already exists!";
            } else {
                $update_sql = "UPDATE programs SET 
                    program_code = ?, program_name = ?, department_id = ?, 
                    duration_years = ?, total_credits = ?, degree_type = ?, is_active = ?
                    WHERE program_id = ?";
                
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $program_code, $program_name, $department_id, 
                    $duration_years, $total_credits, $degree_type, $is_active, $program_id
                ]);
                
                $_SESSION['success_message'] = "Program updated successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating program: " . $e->getMessage();
        }
        
        header("Location: programs.php");
        exit();
    }
    
    // Delete single program
    if (isset($_POST['delete_program'])) {
        $program_id = (int)$_POST['program_id'];
        
        // Check if program has students
        $check_sql = "
            SELECT program_name, 
                   (SELECT COUNT(*) FROM students WHERE program_id = ?) as student_count
            FROM programs WHERE program_id = ?
        ";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$program_id, $program_id]);
        $program_info = $check_stmt->fetch();
        
        if ($program_info && $program_info['student_count'] > 0) {
            $_SESSION['error_message'] = "Cannot delete program '{$program_info['program_name']}' because it has {$program_info['student_count']} enrolled students.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $_SESSION['success_message'] = "Program deleted successfully!";
        }
        
        header("Location: programs.php");
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
    <h1 class="app-page-title mb-0">Manage Programs</h1>
    <div class="app-actions">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
            <i class="fas fa-plus me-2"></i>Add New Program
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
                           placeholder="Search by code, name..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty">
                    <option value="0">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                    <option value="<?php echo $faculty['faculty_id']; ?>" 
                        <?php echo ($faculty_filter == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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
                <label class="form-label">Degree Type</label>
                <select class="form-select" name="degree_type">
                    <option value="">All Types</option>
                    <?php foreach ($degree_types as $type): ?>
                    <option value="<?php echo $type; ?>" 
                        <?php echo ($degree_type == $type) ? 'selected' : ''; ?>>
                        <?php echo $type; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                    <a href="programs.php" class="btn btn-outline-secondary">
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
                <div class="stats-type">Total Programs</div>
                <div class="stats-figure"><?php echo number_format($total_records); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-book text-primary"></i> All Programs
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Undergraduate</div>
                <div class="stats-figure">
                    <?php 
                    $ug_count = $pdo->query("SELECT COUNT(*) FROM programs WHERE degree_type = 'Undergraduate'")->fetchColumn();
                    echo number_format($ug_count);
                    ?>
                </div>
                <div class="stats-meta text-success">
                    <i class="fas fa-graduation-cap"></i> Bachelor's
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Postgraduate</div>
                <div class="stats-figure">
                    <?php 
                    $pg_count = $pdo->query("SELECT COUNT(*) FROM programs WHERE degree_type = 'Postgraduate'")->fetchColumn();
                    echo number_format($pg_count);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-user-graduate"></i> Master's/PhD
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Programs</div>
                <div class="stats-figure">
                    <?php 
                    $active_count = $pdo->query("SELECT COUNT(*) FROM programs WHERE is_active = 1")->fetchColumn();
                    echo number_format($active_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-check-circle"></i> Currently Active
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Programs Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Programs List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> programs
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_programs.php?format=excel<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_programs.php?format=pdf<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_programs.php?format=csv<?php echo getExportQueryString(); ?>">
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
                            <th class="cell">Program Code</th>
                            <th class="cell">Program Name</th>
                            <th class="cell">Faculty</th>
                            <th class="cell">Department</th>
                            <th class="cell">Duration</th>
                            <th class="cell">Degree Type</th>
                            <th class="cell">Students</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($programs)): ?>
                            <?php foreach ($programs as $program): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_programs[]" 
                                               value="<?php echo $program['program_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="app-icon-holder icon-holder-sm me-2">
                                            <i class="fas fa-book text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($program['program_name']); ?></div>
                                            <small class="text-muted">Credits: <?php echo $program['total_credits']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($program['faculty_name']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($program['faculty_name']); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php echo htmlspecialchars($program['department_name'] ?? 'N/A'); ?>
                                </td>
                                <td class="cell">
                                    <?php echo $program['duration_years']; ?> years
                                </td>
                                <td class="cell">
                                    <?php 
                                    $type_class = [
                                        'Undergraduate' => 'success',
                                        'Postgraduate' => 'info',
                                        'Diploma' => 'warning',
                                        'Certificate' => 'secondary'
                                    ][$program['degree_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $type_class; ?>">
                                        <?php echo $program['degree_type']; ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $program['student_count']; ?></div>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = $program['is_active'] ? 'success' : 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $program['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
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
                                                   onclick='viewProgram(<?php echo json_encode($program); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#viewProgramModal">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick='editProgram(<?php echo json_encode($program); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#editProgramModal">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="students.php?program_id=<?php echo $program['program_id']; ?>">
                                                    <i class="fas fa-user-graduate me-2"></i>View Students
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="courses.php?program_id=<?php echo $program['program_id']; ?>">
                                                    <i class="fas fa-book-open me-2"></i>View Courses
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $program['program_id']; ?>, '<?php echo htmlspecialchars(addslashes($program['program_name'])); ?>')">
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
                                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                                        <h5>No programs found</h5>
                                        <p class="text-muted">No programs match your search criteria.</p>
                                        <?php if ($search || $department_id || $faculty_filter || $degree_type): ?>
                                        <a href="programs.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                                            <i class="fas fa-plus me-1"></i>Add New Program
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

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addProgramModalLabel">
                    <i class="fas fa-plus me-2"></i>Add New Program
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="program_code" required 
                                   placeholder="e.g., BSC.CSC, BSC.BUS" style="text-transform:uppercase">
                            <div class="form-text">Unique code for the program</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="program_name" required 
                                   placeholder="e.g., B.Sc. Computer Science">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty <span class="text-danger">*</span></label>
                            <select class="form-select" id="faculty_select" required>
                                <option value="">-- Select Faculty --</option>
                                <?php foreach ($faculties as $faculty): ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select faculty to filter departments</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="department_id" id="department_select" required>
                                <option value="">-- First Select Faculty --</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Degree Type</label>
                            <select class="form-select" name="degree_type">
                                <?php foreach ($degree_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration (Years)</label>
                            <select class="form-select" name="duration_years">
                                <option value="1">1 Year</option>
                                <option value="2">2 Years</option>
                                <option value="3">3 Years</option>
                                <option value="4" selected>4 Years</option>
                                <option value="5">5 Years</option>
                                <option value="6">6 Years</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Total Credits</label>
                        <input type="number" class="form-control" name="total_credits" 
                               value="120" min="60" max="240">
                        <div class="form-text">Total credit units required for graduation</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_program" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Program
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1" aria-labelledby="editProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProgramModalLabel">
                    <i class="fas fa-edit me-2"></i>Edit Program
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="editProgramForm">
                <input type="hidden" name="program_id" id="edit_program_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="program_code" id="edit_program_code" required 
                                   style="text-transform:uppercase">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="program_name" id="edit_program_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty</label>
                            <select class="form-select" id="edit_faculty_select">
                                <option value="">-- Select Faculty --</option>
                                <?php foreach ($faculties as $faculty): ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="department_id" id="edit_department_select" required>
                                <option value="">-- Select Department --</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Degree Type</label>
                            <select class="form-select" name="degree_type" id="edit_degree_type">
                                <?php foreach ($degree_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration (Years)</label>
                            <select class="form-select" name="duration_years" id="edit_duration_years">
                                <option value="1">1 Year</option>
                                <option value="2">2 Years</option>
                                <option value="3">3 Years</option>
                                <option value="4">4 Years</option>
                                <option value="5">5 Years</option>
                                <option value="6">6 Years</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Credits</label>
                            <input type="number" class="form-control" name="total_credits" id="edit_total_credits" min="60" max="240">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_program" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Program
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Program Modal -->
<div class="modal fade" id="viewProgramModal" tabindex="-1" aria-labelledby="viewProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewProgramModalLabel">
                    <i class="fas fa-book me-2"></i>Program Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4 id="view_program_name" class="mb-1"></h4>
                        <p class="text-muted mb-0" id="view_program_code"></p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-success" id="view_status"></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-building me-2"></i>Department Information</h6>
                            <p class="mb-1" id="view_department"></p>
                            <p class="mb-0" id="view_faculty"></p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-graduation-cap me-2"></i>Degree Information</h6>
                            <p class="mb-1" id="view_degree_type"></p>
                            <p class="mb-0" id="view_duration"></p>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_credits"></div>
                                        <small class="text-muted">Total Credits</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_students_count"></div>
                                        <small class="text-muted">Enrolled Students</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
                <input type="hidden" name="program_id" id="delete_program_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the program: <strong id="delete_program_name"></strong>?</p>
                    <p class="text-danger"><small>This action cannot be undone. Make sure there are no students enrolled in this program.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_program" class="btn btn-danger">Delete Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Faculty -> Department cascade for Add Program modal
document.getElementById('faculty_select').addEventListener('change', function() {
    var facultyId = this.value;
    var departmentSelect = document.getElementById('department_select');
    
    departmentSelect.innerHTML = '<option value="">-- Loading departments... --</option>';
    
    if (facultyId) {
        fetch('ajax/get_departments_by_faculty.php?faculty_id=' + facultyId)
            .then(response => response.json())
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
                        departmentSelect.innerHTML = '<option value="">-- No departments found --</option>';
                    }
                } else {
                    departmentSelect.innerHTML = '<option value="">-- Error loading departments --</option>';
                    alert('Error: ' + (data.message || 'Could not load departments'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                departmentSelect.innerHTML = '<option value="">-- Error loading departments --</option>';
            });
    } else {
        departmentSelect.innerHTML = '<option value="">-- First Select Faculty --</option>';
    }
});

// Store departments data for edit modal
let allDepartments = <?php echo json_encode($departments); ?>;

// Edit program function
function editProgram(program) {
    document.getElementById('edit_program_id').value = program.program_id;
    document.getElementById('edit_program_code').value = program.program_code;
    document.getElementById('edit_program_name').value = program.program_name;
    document.getElementById('edit_degree_type').value = program.degree_type;
    document.getElementById('edit_duration_years').value = program.duration_years;
    document.getElementById('edit_total_credits').value = program.total_credits;
    document.getElementById('edit_is_active').checked = program.is_active == 1;
    
    // Set faculty
    if (program.faculty_id) {
        document.getElementById('edit_faculty_select').value = program.faculty_id;
        // Filter departments by faculty
        var filteredDepts = allDepartments.filter(dept => dept.faculty_id == program.faculty_id);
        var deptSelect = document.getElementById('edit_department_select');
        deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
        filteredDepts.forEach(function(dept) {
            var option = document.createElement('option');
            option.value = dept.department_id;
            option.textContent = dept.department_name + ' (' + dept.department_code + ')';
            if (dept.department_id == program.department_id) {
                option.selected = true;
            }
            deptSelect.appendChild(option);
        });
    }
}

// Faculty cascade for edit modal
document.getElementById('edit_faculty_select').addEventListener('change', function() {
    var facultyId = this.value;
    var departmentSelect = document.getElementById('edit_department_select');
    
    departmentSelect.innerHTML = '<option value="">-- Loading departments... --</option>';
    
    if (facultyId) {
        var filteredDepts = allDepartments.filter(dept => dept.faculty_id == facultyId);
        departmentSelect.innerHTML = '<option value="">-- Select Department --</option>';
        if (filteredDepts.length > 0) {
            filteredDepts.forEach(function(dept) {
                var option = document.createElement('option');
                option.value = dept.department_id;
                option.textContent = dept.department_name + ' (' + dept.department_code + ')';
                departmentSelect.appendChild(option);
            });
        } else {
            departmentSelect.innerHTML = '<option value="">-- No departments found --</option>';
        }
    } else {
        departmentSelect.innerHTML = '<option value="">-- First Select Faculty --</option>';
    }
});

// View program function
function viewProgram(program) {
    document.getElementById('view_program_name').textContent = program.program_name;
    document.getElementById('view_program_code').textContent = 'Code: ' + program.program_code;
    document.getElementById('view_status').textContent = program.is_active ? 'Active' : 'Inactive';
    document.getElementById('view_status').className = program.is_active ? 'badge bg-success' : 'badge bg-secondary';
    document.getElementById('view_department').textContent = program.department_name || 'Not specified';
    document.getElementById('view_faculty').textContent = program.faculty_name || 'Not specified';
    document.getElementById('view_degree_type').textContent = program.degree_type;
    document.getElementById('view_duration').textContent = program.duration_years + ' years';
    document.getElementById('view_credits').textContent = program.total_credits;
    document.getElementById('view_students_count').textContent = program.student_count || 0;
}

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
        alert('Please select at least one program.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected program(s)?`;
            break;
        case 'deactivate':
            confirmMessage = `Deactivate ${selectedIds.length} selected program(s)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected program(s)? This action cannot be undone.`;
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

function confirmDelete(programId, programName) {
    document.getElementById('delete_program_id').value = programId;
    document.getElementById('delete_program_name').textContent = programName;
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