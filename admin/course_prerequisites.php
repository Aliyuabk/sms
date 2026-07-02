<?php
// course_prerequisites.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Course Prerequisites";

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$level_filter = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$has_prereq_filter = isset($_GET['has_prereq']) ? $_GET['has_prereq'] : '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];

if (!empty($search)) {
    $filter_conditions[] = "(c.course_code LIKE ? OR c.course_title LIKE ?)";
    $search_term = "%{$search}%";
    $filter_params[] = $search_term;
    $filter_params[] = $search_term;
}

if ($department_filter > 0) {
    $filter_conditions[] = "c.department_id = ?";
    $filter_params[] = $department_filter;
}

if ($level_filter > 0) {
    $filter_conditions[] = "c.level = ?";
    $filter_params[] = $level_filter;
}

if ($has_prereq_filter === 'yes') {
    $filter_conditions[] = "c.prerequisite_course_id IS NOT NULL";
} elseif ($has_prereq_filter === 'no') {
    $filter_conditions[] = "c.prerequisite_course_id IS NULL";
}

$filter_sql = !empty($filter_conditions) ? 'WHERE ' . implode(' AND ', $filter_conditions) : '';

// Get courses with prerequisites (filtered)
$sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_title,
        c.credit_units,
        d.department_name,
        d.department_id,
        c.level,
        c.semester,
        pre.course_id as prerequisite_id,
        pre.course_code as prerequisite_code,
        pre.course_title as prerequisite_title,
        (SELECT COUNT(*) FROM course_registrations cr WHERE cr.course_id = c.course_id) as registration_count
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
    {$filter_sql}
    ORDER BY c.course_code, d.department_name, c.level, c.semester
";

$stmt = $pdo->prepare($sql);
$stmt->execute($filter_params);
$filtered_courses = $stmt->fetchAll();

// Get all courses for dropdown (unfiltered)
$all_courses = $pdo->query("
    SELECT c.course_id, c.course_code, c.course_title, d.department_name
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    ORDER BY c.course_code
")->fetchAll();

// Get departments for filter
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

// Get levels for filter
$levels = $pdo->query("SELECT DISTINCT level FROM courses ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set prerequisite
    if (isset($_POST['set_prerequisite'])) {
        $course_id = (int)$_POST['course_id'];
        $prerequisite_id = !empty($_POST['prerequisite_id']) ? (int)$_POST['prerequisite_id'] : null;
        
        try {
            // Check for circular dependency
            if ($prerequisite_id) {
                $check_sql = "SELECT prerequisite_course_id FROM courses WHERE course_id = ?";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$prerequisite_id]);
                $prereq_prereq = $check_stmt->fetchColumn();
                
                if ($prereq_prereq == $course_id) {
                    $_SESSION['error_message'] = "Circular dependency detected: Courses cannot be prerequisites for each other.";
                    header("Location: course_prerequisites.php" . getFilterQueryString());
                    exit();
                }
            }
            
            $update_sql = "UPDATE courses SET prerequisite_course_id = ? WHERE course_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$prerequisite_id, $course_id]);
            
            // Log the action
            $log_sql = "INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Update Prerequisite', ?, ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $_SESSION['admin_id'] ?? 0,
                "Updated prerequisite for course ID: $course_id",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            $_SESSION['success_message'] = "Prerequisite updated successfully!";
            header("Location: course_prerequisites.php" . getFilterQueryString());
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating prerequisite: " . $e->getMessage();
            header("Location: course_prerequisites.php" . getFilterQueryString());
            exit();
        }
    }
    
    // Remove prerequisite
    if (isset($_POST['remove_prerequisite'])) {
        $course_id = (int)$_POST['course_id'];
        
        try {
            $update_sql = "UPDATE courses SET prerequisite_course_id = NULL WHERE course_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$course_id]);
            
            // Log the action
            $log_sql = "INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Remove Prerequisite', ?, ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $_SESSION['admin_id'] ?? 0,
                "Removed prerequisite for course ID: $course_id",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            $_SESSION['success_message'] = "Prerequisite removed successfully!";
            header("Location: course_prerequisites.php" . getFilterQueryString());
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error removing prerequisite: " . $e->getMessage();
            header("Location: course_prerequisites.php" . getFilterQueryString());
            exit();
        }
    }
    
    // Bulk update prerequisites
    if (isset($_POST['bulk_update'])) {
        $selected_courses_input = $_POST['selected_courses'] ?? '';
        $course_ids = !empty($selected_courses_input) ? json_decode($selected_courses_input, true) : [];
        $prerequisite_id = !empty($_POST['bulk_prerequisite_id']) ? (int)$_POST['bulk_prerequisite_id'] : null;
        
        if (!empty($course_ids) && is_array($course_ids)) {
            $updated = 0;
            $failed = 0;
            $errors = [];
            
            foreach ($course_ids as $course_id) {
                try {
                    // Skip if course is trying to be its own prerequisite
                    if ($course_id == $prerequisite_id) {
                        $failed++;
                        $errors[] = "Course ID $course_id: Cannot be its own prerequisite";
                        continue;
                    }
                    
                    // Check for circular dependency
                    if ($prerequisite_id) {
                        $check_sql = "SELECT prerequisite_course_id FROM courses WHERE course_id = ?";
                        $check_stmt = $pdo->prepare($check_sql);
                        $check_stmt->execute([$prerequisite_id]);
                        $prereq_prereq = $check_stmt->fetchColumn();
                        
                        if ($prereq_prereq == $course_id) {
                            $failed++;
                            $errors[] = "Course ID $course_id: Circular dependency detected";
                            continue;
                        }
                    }
                    
                    $update_sql = "UPDATE courses SET prerequisite_course_id = ? WHERE course_id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([$prerequisite_id, $course_id]);
                    $updated++;
                    
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Course ID $course_id: " . $e->getMessage();
                }
            }
            
            // Log the bulk action
            $log_sql = "INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Bulk Update Prerequisites', ?, ?, NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $_SESSION['admin_id'] ?? 0,
                "Bulk updated $updated courses, $failed failed",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            if ($failed > 0) {
                $_SESSION['warning_message'] = "Updated $updated course(s). $failed failed.";
                $_SESSION['bulk_errors'] = $errors;
            } else {
                $_SESSION['success_message'] = "Successfully updated $updated course(s).";
            }
            
            header("Location: course_prerequisites.php" . getFilterQueryString());
            exit();
        }
    }
}

// Helper function to preserve filter parameters in redirects
function getFilterQueryString() {
    $params = [];
    if (!empty($_GET['search'])) {
        $params['search'] = $_GET['search'];
    }
    if (!empty($_GET['department_id'])) {
        $params['department_id'] = $_GET['department_id'];
    }
    if (!empty($_GET['level'])) {
        $params['level'] = $_GET['level'];
    }
    if (!empty($_GET['has_prereq'])) {
        $params['has_prereq'] = $_GET['has_prereq'];
    }
    return !empty($params) ? '?' . http_build_query($params) : '';
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['warning_message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo $_SESSION['warning_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php 
    if (isset($_SESSION['bulk_errors'])) {
        echo '<div class="alert alert-secondary small">';
        echo '<strong>Details:</strong><ul>';
        foreach ($_SESSION['bulk_errors'] as $error) {
            echo '<li>' . htmlspecialchars($error) . '</li>';
        }
        echo '</ul></div>';
        unset($_SESSION['bulk_errors']);
    }
    unset($_SESSION['warning_message']); 
    ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Course Prerequisites</h1>
    <div class="app-actions">
        <a href="courses.php" class="btn app-btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Courses
        </a>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filter Courses
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search Course</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Course code or title..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="form-text">
                    <i class="fas fa-info-circle"></i> Search by code (CSC101) or title
                </div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>"
                        <?php echo ($department_filter == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
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
                        <?php echo ($level_filter == $lvl) ? 'selected' : ''; ?>>
                        <?php echo $lvl; ?> Level
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Has Prerequisite</label>
                <select class="form-select" name="has_prereq">
                    <option value="">All Courses</option>
                    <option value="yes" <?php echo ($has_prereq_filter == 'yes') ? 'selected' : ''; ?>>Has Prerequisite</option>
                    <option value="no" <?php echo ($has_prereq_filter == 'no') ? 'selected' : ''; ?>>No Prerequisite</option>
                </select>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Apply Filters
                    </button>
                    <a href="course_prerequisites.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Clear Filters
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
                <div class="stats-type">Filtered Courses</div>
                <div class="stats-figure"><?php echo count($filtered_courses); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-search text-primary"></i> Current View
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">With Prerequisites</div>
                <div class="stats-figure">
                    <?php 
                    $with_prereq_count = $pdo->query("SELECT COUNT(*) FROM courses WHERE prerequisite_course_id IS NOT NULL")->fetchColumn();
                    echo number_format($with_prereq_count);
                    ?>
                </div>
                <div class="stats-meta">
                    <i class="fas fa-link text-success"></i> Total
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Courses</div>
                <div class="stats-figure">
                    <?php 
                    $total_count = $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
                    echo number_format($total_count);
                    ?>
                </div>
                <div class="stats-meta">
                    <i class="fas fa-book"></i> All Courses
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">No Prerequisites</div>
                <div class="stats-figure">
                    <?php 
                    $no_prereq_count = $pdo->query("SELECT COUNT(*) FROM courses WHERE prerequisite_course_id IS NULL")->fetchColumn();
                    echo number_format($no_prereq_count);
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-unlink"></i> Standalone
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Set Prerequisites Section -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="app-card app-card-form shadow-sm h-100">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-plus-circle me-2"></i>Set Prerequisite
                </h5>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" id="setPrerequisiteForm">
                    <div class="mb-3">
                        <label class="form-label">Select Course *</label>
                        <select class="form-select select2" name="course_id" required style="width:100%">
                            <option value="">-- Select Course --</option>
                            <?php foreach ($all_courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>" 
                                    data-code="<?php echo htmlspecialchars($course['course_code']); ?>">
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                                (<?php echo htmlspecialchars($course['department_name']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Type to search by course code or title</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Prerequisite Course</label>
                        <select class="form-select select2" name="prerequisite_id" style="width:100%">
                            <option value="">-- No Prerequisite (Remove) --</option>
                            <?php foreach ($all_courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>"
                                    data-code="<?php echo htmlspecialchars($course['course_code']); ?>">
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Leave empty to remove existing prerequisite</div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" name="set_prerequisite" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Set Prerequisite
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="app-card app-card-info shadow-sm h-100">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-info-circle me-2"></i>About Prerequisites
                </h5>
            </div>
            <div class="app-card-body p-3">
                <h6>What are course prerequisites?</h6>
                <p class="small">Prerequisites are courses that students must complete before they can register for a more advanced course.</p>
                
                <h6 class="mt-3">Best Practices:</h6>
                <ul class="small mb-0">
                    <li>Ensure logical progression of knowledge</li>
                    <li>Avoid circular dependencies</li>
                    <li>Consider departmental requirements</li>
                    <li>Review prerequisite chains regularly</li>
                    <li>Communicate prerequisites clearly to students</li>
                </ul>
                
                <?php if (!empty($search)): ?>
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="fas fa-filter me-1"></i>
                    Currently filtering by: <strong>"<?php echo htmlspecialchars($search); ?>"</strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filtered Courses List -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">
                    <?php if (!empty($search)): ?>
                        Search Results: "<?php echo htmlspecialchars($search); ?>"
                    <?php else: ?>
                        Courses
                    <?php endif; ?>
                </h5>
                <div class="text-muted small">
                    Showing <?php echo count($filtered_courses); ?> courses
                    <?php if (!empty($search)): ?>
                        matching your search
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_prerequisites.php?format=excel<?php echo getFilterQueryString(); ?>">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_prerequisites.php?format=pdf<?php echo getFilterQueryString(); ?>">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell" width="40">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="select-all" 
                                       onclick="selectAllCheckboxes(this)">
                            </div>
                        </th>
                        <th class="cell">Course Code</th>
                        <th class="cell">Course Title</th>
                        <th class="cell">Department</th>
                        <th class="cell">Level/Sem</th>
                        <th class="cell">Prerequisite</th>
                        <th class="cell">Registrations</th>
                        <th class="cell text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($filtered_courses)): ?>
                        <?php foreach ($filtered_courses as $course): ?>
                        <tr>
                            <td class="cell">
                                <div class="form-check">
                                    <input class="form-check-input course-checkbox" 
                                           type="checkbox" 
                                           value="<?php echo $course['course_id']; ?>">
                                </div>
                            </td>
                            <td class="cell">
                                <span class="fw-bold text-primary"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                <span class="badge bg-warning ms-1"><?php echo $course['credit_units']; ?> Units</span>
                            </td>
                            <td class="cell">
                                <?php echo htmlspecialchars($course['course_title']); ?>
                            </td>
                            <td class="cell">
                                <?php echo htmlspecialchars($course['department_name']); ?>
                                <br><small class="text-muted">Dept ID: <?php echo $course['department_id']; ?></small>
                            </td>
                            <td class="cell">
                                <div class="d-flex flex-column">
                                    <span class="badge bg-info mb-1"><?php echo $course['level']; ?> Level</span>
                                    <span class="badge bg-secondary">Sem <?php echo $course['semester']; ?></span>
                                </div>
                            </td>
                            <td class="cell">
                                <?php if ($course['prerequisite_code']): ?>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-arrow-right text-success me-2"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($course['prerequisite_code']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($course['prerequisite_title']); ?></div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">
                                    <i class="fas fa-minus-circle me-1"></i>None
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="cell">
                                <span class="badge bg-secondary"><?php echo $course['registration_count'] ?? 0; ?> students</span>
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
                                            <button type="button" class="dropdown-item" 
                                                    onclick="setPrerequisiteModal(<?php echo $course['course_id']; ?>, '<?php echo htmlspecialchars(addslashes($course['course_code'] . ' - ' . $course['course_title'])); ?>', <?php echo $course['prerequisite_id'] ?? 'null'; ?>)">
                                                <i class="fas fa-edit me-2"></i>Change Prerequisite
                                            </button>
                                        </li>
                                        <li>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                                                <button type="submit" name="remove_prerequisite" class="dropdown-item text-danger" 
                                                        onclick="return confirm('Remove prerequisite from <?php echo htmlspecialchars(addslashes($course['course_code'])); ?>?')">
                                                    <i class="fas fa-unlink me-2"></i>Remove Prerequisite
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="edit_course.php?id=<?php echo $course['course_id']; ?>">
                                                <i class="fas fa-edit me-2"></i>Edit Course
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="course_registrations.php?course_id=<?php echo $course['course_id']; ?>">
                                                <i class="fas fa-users me-2"></i>View Registrations
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="py-3">
                                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                    <h5>No courses found</h5>
                                    <p class="text-muted">
                                        <?php if (!empty($search)): ?>
                                            No courses matching "<?php echo htmlspecialchars($search); ?>" found.
                                        <?php else: ?>
                                            No courses available.
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($search) || $department_filter || $level_filter || $has_prereq_filter): ?>
                                    <a href="course_prerequisites.php" class="btn btn-primary">
                                        <i class="fas fa-redo me-1"></i>Clear Filters
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Table Footer with Bulk Actions -->
    <?php if (!empty($filtered_courses)): ?>
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showBulkUpdateModal()">
                        <i class="fas fa-cogs me-1"></i>Bulk Update
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="showBulkPrerequisiteModal()">
                        <i class="fas fa-link me-1"></i>Set Common Prerequisite
                    </button>
                </div>
                <span class="ms-2 text-muted small">
                    <span id="selectedCount">0</span> courses selected
                </span>
            </div>
            <div class="col-md-6 text-md-end">
                <small class="text-muted">
                    Click on course code to filter by it
                </small>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Bulk Update Modal -->
<div class="modal fade" id="bulkUpdateModal" tabindex="-1" aria-labelledby="bulkUpdateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkUpdateModalLabel">
                    <i class="fas fa-cogs me-2"></i>Bulk Update Prerequisites
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="bulkUpdateForm">
                    <input type="hidden" name="selected_courses" id="selectedCoursesInput" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Set Prerequisite For Selected Courses</label>
                        <select class="form-select select2-modal" name="bulk_prerequisite_id" style="width:100%">
                            <option value="">-- No Prerequisite (Remove) --</option>
                            <?php foreach ($all_courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>"
                                    data-code="<?php echo htmlspecialchars($course['course_code']); ?>">
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select a course to set as prerequisite, or leave empty to remove prerequisites</div>
                    </div>
                    
                    <div class="alert alert-warning small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        This will update <span id="selectedCoursesCount">0</span> selected course(s). 
                        Be careful to avoid circular dependencies.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="bulkUpdateForm" name="bulk_update" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Edit Modal -->
<div class="modal fade" id="quickEditModal" tabindex="-1" aria-labelledby="quickEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickEditModalLabel">
                    <i class="fas fa-edit me-2"></i>Change Prerequisite
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="quickEditForm">
                    <input type="hidden" name="course_id" id="editCourseId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Course</label>
                        <input type="text" class="form-control" id="editCourseDisplay" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Prerequisite Course</label>
                        <select class="form-select select2-modal" name="prerequisite_id" id="editPrerequisiteId" style="width:100%">
                            <option value="">-- No Prerequisite (Remove) --</option>
                            <?php foreach ($all_courses as $course): ?>
                            <option value="<?php echo $course['course_id']; ?>"
                                    data-code="<?php echo htmlspecialchars($course['course_code']); ?>">
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="quickEditForm" name="set_prerequisite" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Select2 CSS and JS for better dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2 for better searchable dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Initialize select2 on regular selects
    $('.select2').select2({
        placeholder: 'Type to search...',
        allowClear: true,
        width: '100%',
        matcher: function(params, data) {
            // Custom matcher to search by course code
            if ($.trim(params.term) === '') {
                return data;
            }
            
            // Check if the term matches course code or title
            var term = params.term.toLowerCase();
            var code = $(data.element).data('code') ? $(data.element).data('code').toLowerCase() : '';
            var text = data.text.toLowerCase();
            
            if (code.indexOf(term) > -1 || text.indexOf(term) > -1) {
                return data;
            }
            
            return null;
        }
    });
    
    // Initialize select2 in modals (needs to be reinitialized when modal opens)
    $('.select2-modal').select2({
        placeholder: 'Type to search...',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#bulkUpdateModal, #quickEditModal'),
        matcher: function(params, data) {
            if ($.trim(params.term) === '') {
                return data;
            }
            
            var term = params.term.toLowerCase();
            var code = $(data.element).data('code') ? $(data.element).data('code').toLowerCase() : '';
            var text = data.text.toLowerCase();
            
            if (code.indexOf(term) > -1 || text.indexOf(term) > -1) {
                return data;
            }
            
            return null;
        }
    });
});

// Select all checkboxes
function selectAllCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.course-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
    updateSelectedCount();
}

// Update selected count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.course-checkbox:checked');
    document.getElementById('selectedCount').textContent = checkboxes.length;
}

// Add event listeners to update count when checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.course-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
});

// Set prerequisite modal
function setPrerequisiteModal(courseId, courseDisplay, currentPrereqId) {
    document.getElementById('editCourseId').value = courseId;
    document.getElementById('editCourseDisplay').value = courseDisplay;
    
    // Set the select2 value
    const select = $('#editPrerequisiteId');
    select.val(currentPrereqId).trigger('change');
    
    const modal = new bootstrap.Modal(document.getElementById('quickEditModal'));
    modal.show();
}

// Validate set prerequisite form
document.getElementById('setPrerequisiteForm').addEventListener('submit', function(e) {
    const courseId = this.querySelector('select[name="course_id"]').value;
    const prerequisiteId = this.querySelector('select[name="prerequisite_id"]').value;
    
    if (!courseId) {
        e.preventDefault();
        alert('Please select a course');
        return false;
    }
    
    if (courseId === prerequisiteId) {
        e.preventDefault();
        alert('A course cannot be a prerequisite for itself');
        return false;
    }
    
    return true;
});

// Quick edit form validation
document.getElementById('quickEditForm').addEventListener('submit', function(e) {
    const courseId = this.querySelector('input[name="course_id"]').value;
    const prerequisiteId = this.querySelector('select[name="prerequisite_id"]').value;
    
    if (courseId === prerequisiteId) {
        e.preventDefault();
        alert('A course cannot be a prerequisite for itself');
        return false;
    }
    
    return true;
});

// Show bulk update modal
function showBulkUpdateModal() {
    const checkboxes = document.querySelectorAll('.course-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one course');
        return;
    }
    
    const selectedIds = Array.from(checkboxes).map(cb => cb.value);
    document.getElementById('selectedCoursesInput').value = JSON.stringify(selectedIds);
    document.getElementById('selectedCoursesCount').textContent = checkboxes.length;
    
    const modal = new bootstrap.Modal(document.getElementById('bulkUpdateModal'));
    modal.show();
}

// Show bulk prerequisite modal (similar to bulk update but with different action)
function showBulkPrerequisiteModal() {
    showBulkUpdateModal(); // Reuse the same modal
}

// Quick filter by course code
function filterByCourseCode(code) {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.value = code;
        searchInput.closest('form').submit();
    }
}

// Add click handlers for course codes
document.addEventListener('DOMContentLoaded', function() {
    const courseCodeElements = document.querySelectorAll('.course-code-clickable');
    courseCodeElements.forEach(el => {
        el.addEventListener('click', function() {
            filterByCourseCode(this.textContent.trim());
        });
        el.style.cursor = 'pointer';
        el.title = 'Click to filter by this code';
    });
});
</script>

<style>
/* Additional styles for better UI */
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

.course-code-clickable:hover {
    text-decoration: underline;
    color: #0a58ca;
}

.table td .badge {
    font-size: 0.75rem;
}

/* Highlight search term */
.highlight {
    background-color: #fff3cd;
    font-weight: bold;
}
</style>

<?php
require_once 'includes/footer.php';
?>