<?php
// manage_faculties.php
ob_start();

// Helper function for export URLs
function getExportQueryString() {
    $params = [];
    
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['status'])) {
        $params['status'] = $_GET['status'];
    }
    
    return !empty($params) ? '&' . http_build_query($params) : '';
}

require_once 'includes/header.php';

// Set page title
$page_title = "Manage Faculties";

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(faculty_name LIKE ? OR faculty_code LIKE ? OR dean_name LIKE ? OR email LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if ($status !== '' && in_array($status, ['Active', 'Inactive'])) {
    $conditions[] = "status = ?";
    $params[] = $status;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "SELECT COUNT(*) as total FROM faculties {$where_clause}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get faculties data with department counts
$sql = "
    SELECT 
        f.*,
        (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.faculty_id) as department_count,
        (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.faculty_id) as actual_departments
    FROM faculties f
    {$where_clause}
    ORDER BY f.faculty_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faculties = $stmt->fetchAll();

// Get student and program counts separately for each faculty
foreach ($faculties as &$faculty) {
    // Get student count
    $student_sql = "
        SELECT COUNT(DISTINCT s.student_id) as student_count 
        FROM students s 
        JOIN departments d ON s.department_id = d.department_id 
        WHERE d.faculty_id = ?
    ";
    $student_stmt = $pdo->prepare($student_sql);
    $student_stmt->execute([$faculty['faculty_id']]);
    $faculty['actual_students'] = $student_stmt->fetch()['student_count'] ?? 0;
    
    // Get program count
    $program_sql = "
        SELECT COUNT(DISTINCT p.program_id) as program_count 
        FROM programs p 
        JOIN departments d ON p.department_id = d.department_id 
        WHERE d.faculty_id = ?
    ";
    $program_stmt = $pdo->prepare($program_sql);
    $program_stmt->execute([$faculty['faculty_id']]);
    $faculty['actual_programs'] = $program_stmt->fetch()['program_count'] ?? 0;
}
unset($faculty); // Unset reference

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_faculties'])) {
        $selected_ids = $_POST['selected_faculties'];
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        switch ($_POST['bulk_action']) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE faculties SET status = 'Active' WHERE faculty_id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $_SESSION['success_message'] = count($selected_ids) . " faculty(ies) activated successfully!";
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE faculties SET status = 'Inactive' WHERE faculty_id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $_SESSION['success_message'] = count($selected_ids) . " faculty(ies) deactivated successfully!";
                break;
                
            case 'delete':
                // Check if faculty has departments
                $check_sql = "
                    SELECT f.faculty_name, COUNT(d.department_id) as dept_count 
                    FROM faculties f 
                    LEFT JOIN departments d ON d.faculty_id = f.faculty_id 
                    WHERE f.faculty_id IN ($placeholders)
                    GROUP BY f.faculty_id
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute($selected_ids);
                $faculties_with_depts = $check_stmt->fetchAll();
                
                $has_departments = false;
                $faculty_names = [];
                
                foreach ($faculties_with_depts as $faculty) {
                    if ($faculty['dept_count'] > 0) {
                        $has_departments = true;
                        $faculty_names[] = $faculty['faculty_name'];
                    }
                }
                
                if ($has_departments) {
                    $_SESSION['error_message'] = "Cannot delete faculty(ies) with departments: " . implode(', ', $faculty_names) . ". Please reassign departments first.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM faculties WHERE faculty_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " faculty(ies) deleted successfully!";
                }
                break;
        }
        
        header("Location: manage_faculties.php");
        exit();
    }
    
    // Add new faculty
    if (isset($_POST['add_faculty'])) {
        $faculty_code = strtoupper(trim($_POST['faculty_code'] ?? ''));
        $faculty_name = trim($_POST['faculty_name'] ?? '');
        $dean_name = trim($_POST['dean_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $office_location = trim($_POST['office_location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $established_year = $_POST['established_year'] ?? date('Y');
        $status = $_POST['status'] ?? 'Active';
        
        // Validate
        if (empty($faculty_code) || empty($faculty_name)) {
            $_SESSION['error_message'] = "Faculty code and name are required!";
        } else {
            try {
                // Check if faculty code or name already exists
                $check_stmt = $pdo->prepare("SELECT faculty_id FROM faculties WHERE faculty_code = ? OR faculty_name = ?");
                $check_stmt->execute([$faculty_code, $faculty_name]);
                
                if ($check_stmt->rowCount() > 0) {
                    $_SESSION['error_message'] = "Faculty with this code or name already exists!";
                } else {
                    // FIXED: Use created_date instead of created_at
                    $insert_sql = "INSERT INTO faculties 
                        (faculty_code, faculty_name, dean_name, email, phone, office_location, description, established_year, status, created_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        $faculty_code, $faculty_name, $dean_name, $email, $phone, 
                        $office_location, $description, $established_year, $status
                    ]);
                    
                    $_SESSION['success_message'] = "Faculty added successfully!";
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error adding faculty: " . $e->getMessage();
            }
        }
        
        header("Location: manage_faculties.php");
        exit();
    }
    
    // Edit faculty
    if (isset($_POST['edit_faculty'])) {
        $faculty_id = (int)$_POST['faculty_id'];
        $faculty_code = strtoupper(trim($_POST['faculty_code'] ?? ''));
        $faculty_name = trim($_POST['faculty_name'] ?? '');
        $dean_name = trim($_POST['dean_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $office_location = trim($_POST['office_location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $established_year = $_POST['established_year'] ?? '';
        $status = $_POST['status'] ?? 'Active';
        
        try {
            // Get old faculty info
            $old_stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
            $old_stmt->execute([$faculty_id]);
            $old_faculty = $old_stmt->fetch();
            
            if (!$old_faculty) {
                $_SESSION['error_message'] = "Faculty not found!";
                header("Location: manage_faculties.php");
                exit();
            }
            
            $old_faculty_name = $old_faculty['faculty_name'];
            
            // Check if faculty code or name already exists (excluding current faculty)
            $check_stmt = $pdo->prepare("SELECT faculty_id FROM faculties WHERE (faculty_code = ? OR faculty_name = ?) AND faculty_id != ?");
            $check_stmt->execute([$faculty_code, $faculty_name, $faculty_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Another faculty with this code or name already exists!";
            } else {
                // Note: faculties table doesn't have updated_at, so we'll just update without it
                $update_sql = "UPDATE faculties SET 
                    faculty_code = ?, faculty_name = ?, dean_name = ?, email = ?, phone = ?, 
                    office_location = ?, description = ?, established_year = ?, status = ?
                    WHERE faculty_id = ?";
                
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $faculty_code, $faculty_name, $dean_name, $email, $phone, 
                    $office_location, $description, $established_year, $status, $faculty_id
                ]);
                
                $_SESSION['success_message'] = "Faculty updated successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating faculty: " . $e->getMessage();
        }
        
        header("Location: manage_faculties.php");
        exit();
    }
    
    // Delete single faculty
    if (isset($_POST['delete_faculty'])) {
        $faculty_id = (int)$_POST['faculty_id'];
        
        // Check if faculty exists
        $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
        $stmt->execute([$faculty_id]);
        $faculty = $stmt->fetch();
        
        if ($faculty) {
            // Check if faculty has departments
            $check_stmt = $pdo->prepare("SELECT COUNT(*) as dept_count FROM departments WHERE faculty_id = ?");
            $check_stmt->execute([$faculty_id]);
            $dept_count = $check_stmt->fetch()['dept_count'];
            
            if ($dept_count > 0) {
                $_SESSION['error_message'] = "Cannot delete faculty '{$faculty['faculty_name']}' because it has {$dept_count} department(s). Please reassign departments first.";
            } else {
                $delete_stmt = $pdo->prepare("DELETE FROM faculties WHERE faculty_id = ?");
                $delete_stmt->execute([$faculty_id]);
                $_SESSION['success_message'] = "Faculty '{$faculty['faculty_name']}' deleted successfully!";
            }
        } else {
            $_SESSION['error_message'] = "Faculty not found!";
        }
        
        header("Location: manage_faculties.php");
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
    <h1 class="app-page-title mb-0">Manage Faculties</h1>
    <div class="app-actions">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
            <i class="fas fa-plus me-2"></i>Add New Faculty
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
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Search by faculty name, code, dean..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo ($status == 'Active') ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo ($status == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                    <a href="manage_faculties.php" class="btn btn-outline-secondary">
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
                <div class="stats-type">Total Faculties</div>
                <div class="stats-figure">
                    <?php 
                    $total_faculties = $pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn();
                    echo number_format($total_faculties);
                    ?>
                </div>
                <div class="stats-meta">
                    <i class="fas fa-university text-primary"></i> All Faculties
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Faculties</div>
                <div class="stats-figure">
                    <?php 
                    $active_faculties = $pdo->query("SELECT COUNT(*) FROM faculties WHERE status = 'Active'")->fetchColumn();
                    echo number_format($active_faculties);
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
                <div class="stats-type">Total Departments</div>
                <div class="stats-figure">
                    <?php 
                    $total_depts = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
                    echo number_format($total_depts);
                    ?>
                </div>
                <div class="stats-meta text-info">
                    <i class="fas fa-building"></i> Across All Faculties
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
                <div class="stats-meta text-warning">
                    <i class="fas fa-user-graduate"></i> Enrolled Students
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Faculties Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Faculties List</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> faculties
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_faculties.php?format=excel<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_faculties.php?format=pdf<?php echo getExportQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_faculties.php?format=csv<?php echo getExportQueryString(); ?>">
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
                            <th class="cell">Faculty Code</th>
                            <th class="cell">Faculty Name</th>
                            <th class="cell">Dean</th>
                            <th class="cell">Departments</th>
                            <th class="cell">Programs</th>
                            <th class="cell">Students</th>
                            <th class="cell">Established</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($faculties)): ?>
                            <?php foreach ($faculties as $faculty): 
                                $department_count = $faculty['department_count'] ?? 0;
                                $actual_students = $faculty['actual_students'] ?? 0;
                                $actual_programs = $faculty['actual_programs'] ?? 0;
                            ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_faculties[]" 
                                               value="<?php echo $faculty['faculty_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <strong><?php echo htmlspecialchars($faculty['faculty_code']); ?></strong>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="app-icon-holder icon-holder-sm me-2">
                                            <i class="fas fa-university text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($faculty['faculty_name']); ?></div>
                                            <small class="text-muted">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($faculty['office_location'] ?? 'N/A'); ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="small">
                                        <strong><?php echo htmlspecialchars($faculty['dean_name'] ?? 'N/A'); ?></strong><br>
                                        <?php if ($faculty['email']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($faculty['email']); ?></small><br>
                                        <?php endif; ?>
                                        <?php if ($faculty['phone']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($faculty['phone']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $department_count; ?></div>
                                        <small class="text-muted">Departments</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $actual_programs; ?></div>
                                        <small class="text-muted">Programs</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="text-center">
                                        <div class="fw-bold"><?php echo $actual_students; ?></div>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php echo $faculty['established_year'] ?? 'N/A'; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = $faculty['status'] == 'Active' ? 'success' : 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($faculty['status']); ?>
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
                                                   onclick='viewFaculty(<?php echo json_encode($faculty); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#viewFacultyModal">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick='editFaculty(<?php echo json_encode($faculty); ?>)'
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#editFacultyModal">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="departments.php?faculty=<?php echo $faculty['faculty_id']; ?>">
                                                    <i class="fas fa-building me-2"></i>View Departments
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="programs.php?faculty=<?php echo $faculty['faculty_id']; ?>">
                                                    <i class="fas fa-book me-2"></i>View Programs
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" 
                                                   href="#" 
                                                   onclick="confirmDelete(<?php echo $faculty['faculty_id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['faculty_name'])); ?>')">
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
                                        <i class="fas fa-university fa-3x text-muted mb-3"></i>
                                        <h5>No faculties found</h5>
                                        <p class="text-muted">No faculties match your search criteria.</p>
                                        <?php if ($search || $status): ?>
                                        <a href="manage_faculties.php" class="btn btn-primary">
                                            <i class="fas fa-redo me-1"></i>Clear Filters
                                        </a>
                                        <?php else: ?>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                                            <i class="fas fa-plus me-1"></i>Add New Faculty
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
                            <i class="fas fa-ban me-2 text-warning"></i>Deactivate Selected
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

<!-- Add Faculty Modal -->
<div class="modal fade" id="addFacultyModal" tabindex="-1" aria-labelledby="addFacultyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addFacultyModalLabel">
                    <i class="fas fa-plus me-2"></i>Add New Faculty
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_code" required 
                                   placeholder="e.g., SCI, ART, BUS" maxlength="20" style="text-transform:uppercase">
                            <div class="form-text">Unique code for the faculty</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_name" required 
                                   placeholder="e.g., Faculty of Science">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Dean Name</label>
                            <input type="text" class="form-control" name="dean_name" 
                                   placeholder="e.g., Prof. James Anderson">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Established Year</label>
                            <select class="form-select" name="established_year">
                                <option value="">Select Year</option>
                                <?php for ($year = date('Y'); $year >= 1900; $year--): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   placeholder="e.g., faculty@university.edu">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" 
                                   placeholder="e.g., 08011112222">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Office Location</label>
                        <input type="text" class="form-control" name="office_location" 
                               placeholder="e.g., Science Block A, 2nd Floor">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="Brief description of the faculty..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_faculty" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Faculty
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Faculty Modal -->
<div class="modal fade" id="editFacultyModal" tabindex="-1" aria-labelledby="editFacultyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editFacultyModalLabel">
                    <i class="fas fa-edit me-2"></i>Edit Faculty
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="editFacultyForm">
                <input type="hidden" name="faculty_id" id="edit_faculty_id">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_code" id="edit_faculty_code" required 
                                   style="text-transform:uppercase">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="faculty_name" id="edit_faculty_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Dean Name</label>
                            <input type="text" class="form-control" name="dean_name" id="edit_dean_name">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Established Year</label>
                            <select class="form-select" name="established_year" id="edit_established_year">
                                <option value="">Select Year</option>
                                <?php for ($year = date('Y'); $year >= 1900; $year--): ?>
                                <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                <?php endfor; ?>
                            </select>
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
                    
                    <div class="mb-3">
                        <label class="form-label">Office Location</label>
                        <input type="text" class="form-control" name="office_location" id="edit_office_location">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_faculty" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Faculty
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Faculty Modal -->
<div class="modal fade" id="viewFacultyModal" tabindex="-1" aria-labelledby="viewFacultyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewFacultyModalLabel">
                    <i class="fas fa-university me-2"></i>Faculty Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4 id="view_faculty_name" class="mb-1"></h4>
                        <p class="text-muted mb-0" id="view_faculty_code"></p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-success" id="view_status"></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-user-tie me-2"></i>Dean Information</h6>
                            <p class="mb-1" id="view_dean_name"></p>
                            <p class="mb-1 text-muted small" id="view_email"></p>
                            <p class="mb-0 text-muted small" id="view_phone"></p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-map-marker-alt me-2"></i>Office Location</h6>
                            <p class="mb-0" id="view_office_location"></p>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-calendar-alt me-2"></i>Establishment</h6>
                            <p class="mb-0" id="view_established_year"></p>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2"><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
                            <div class="row">
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_departments"></div>
                                        <small class="text-muted">Departments</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_programs"></div>
                                        <small class="text-muted">Programs</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center p-2 border rounded">
                                        <div class="h4 mb-0" id="view_students"></div>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-info-circle me-2"></i>Description</h6>
                    <p class="mb-0" id="view_description"></p>
                </div>
                
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-history me-2"></i>Timestamps</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Created: <span id="view_created_date"></span></small>
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
                <input type="hidden" name="faculty_id" id="delete_faculty_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the faculty: <strong id="delete_faculty_name"></strong>?</p>
                    <p class="text-danger"><small>This action cannot be undone. All departments under this faculty will need to be reassigned.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_faculty" class="btn btn-danger">Delete Faculty</button>
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
        alert('Please select at least one faculty.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'activate':
            confirmMessage = `Activate ${selectedIds.length} selected faculty(ies)?`;
            break;
        case 'deactivate':
            confirmMessage = `Deactivate ${selectedIds.length} selected faculty(ies)?`;
            break;
        case 'delete':
            confirmMessage = `Delete ${selectedIds.length} selected faculty(ies)? This action cannot be undone.`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected faculty IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Edit faculty function
function editFaculty(faculty) {
    document.getElementById('edit_faculty_id').value = faculty.faculty_id;
    document.getElementById('edit_faculty_code').value = faculty.faculty_code;
    document.getElementById('edit_faculty_name').value = faculty.faculty_name;
    document.getElementById('edit_dean_name').value = faculty.dean_name || '';
    document.getElementById('edit_email').value = faculty.email || '';
    document.getElementById('edit_phone').value = faculty.phone || '';
    document.getElementById('edit_office_location').value = faculty.office_location || '';
    document.getElementById('edit_description').value = faculty.description || '';
    document.getElementById('edit_status').value = faculty.status || 'Active';
    
    // Set established year
    if (faculty.established_year) {
        document.getElementById('edit_established_year').value = faculty.established_year;
    }
}

// View faculty function
function viewFaculty(faculty) {
    document.getElementById('view_faculty_name').textContent = faculty.faculty_name;
    document.getElementById('view_faculty_code').textContent = `Code: ${faculty.faculty_code}`;
    document.getElementById('view_status').textContent = faculty.status;
    document.getElementById('view_status').className = faculty.status === 'Active' ? 'badge bg-success' : 'badge bg-secondary';
    document.getElementById('view_dean_name').textContent = faculty.dean_name || 'Not specified';
    document.getElementById('view_email').textContent = faculty.email || 'Not specified';
    document.getElementById('view_phone').textContent = faculty.phone || 'Not specified';
    document.getElementById('view_office_location').textContent = faculty.office_location || 'Not specified';
    document.getElementById('view_established_year').textContent = faculty.established_year || 'Not specified';
    document.getElementById('view_description').textContent = faculty.description || 'No description available';
    document.getElementById('view_departments').textContent = faculty.department_count || 0;
    document.getElementById('view_programs').textContent = faculty.actual_programs || 0;
    document.getElementById('view_students').textContent = faculty.actual_students || 0;
    document.getElementById('view_created_date').textContent = faculty.created_date ? new Date(faculty.created_date).toLocaleString() : 'N/A';
}

// Delete confirmation
function confirmDelete(facultyId, facultyName) {
    document.getElementById('delete_faculty_id').value = facultyId;
    document.getElementById('delete_faculty_name').textContent = facultyName;
    
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