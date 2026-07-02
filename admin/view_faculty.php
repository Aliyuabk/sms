<?php
// view_faculty.php
ob_start();
require_once 'includes/header.php';

// Set page title
$page_title = "View Faculty";

// Get faculty ID from URL
$faculty_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($faculty_id <= 0) {
    header("Location: manage_faculties.php");
    exit();
}

// Get faculty details
$faculty_stmt = $pdo->prepare("
    SELECT f.*,
           (SELECT COUNT(*) FROM departments d WHERE d.faculty = f.faculty_name) as department_count,
           (SELECT COUNT(*) FROM students s 
            JOIN departments d ON s.department_id = d.department_id 
            WHERE d.faculty = f.faculty_name) as student_count,
           (SELECT COUNT(*) FROM programs p 
            JOIN departments d ON p.department_id = d.department_id 
            WHERE d.faculty = f.faculty_name) as program_count
    FROM faculties f 
    WHERE f.faculty_id = ?
");

$faculty_stmt->execute([$faculty_id]);
$faculty = $faculty_stmt->fetch();

if (!$faculty) {
    $_SESSION['error_message'] = "Faculty not found!";
    header("Location: manage_faculties.php");
    exit();
}

// Get departments under this faculty
$departments_stmt = $pdo->prepare("
    SELECT d.*, 
           (SELECT COUNT(*) FROM students WHERE department_id = d.department_id) as student_count,
           (SELECT COUNT(*) FROM programs WHERE department_id = d.department_id) as program_count
    FROM departments d 
    WHERE d.faculty = ? 
    ORDER BY d.department_name
");

$departments_stmt->execute([$faculty['faculty_name']]);
$departments = $departments_stmt->fetchAll();

// Get programs under this faculty
$programs_stmt = $pdo->prepare("
    SELECT p.*, d.department_name
    FROM programs p
    JOIN departments d ON p.department_id = d.department_id
    WHERE d.faculty = ?
    ORDER BY p.program_name
");

$programs_stmt->execute([$faculty['faculty_name']]);
$programs = $programs_stmt->fetchAll();

// Get recent activities/logs
$activities_stmt = $pdo->prepare("
    SELECT * FROM admin_logs 
    WHERE table_name = 'faculties' AND record_id = ?
    ORDER BY created_at DESC 
    LIMIT 10
");

$activities_stmt->execute([$faculty_id]);
$activities = $activities_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0"><?php echo htmlspecialchars($faculty['faculty_name']); ?></h1>
    <div class="app-actions">
        <a href="manage_faculties.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Faculties
        </a>
        <a href="edit_faculty.php?id=<?php echo $faculty_id; ?>" class="btn btn-primary ms-2">
            <i class="fas fa-edit me-2"></i>Edit Faculty
        </a>
    </div>
</div>

<!-- Faculty Overview -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-university me-2"></i>Faculty Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="text-muted small">Faculty Code</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($faculty['faculty_code']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Faculty Name</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($faculty['faculty_name']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Established Year</label>
                            <p class="fw-bold"><?php echo $faculty['established_year'] ?? 'N/A'; ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Status</label>
                            <span class="badge bg-<?php echo $faculty['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                <?php echo htmlspecialchars($faculty['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="text-muted small">Dean Name</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($faculty['dean_name'] ?? 'N/A'); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Email</label>
                            <p class="fw-bold">
                                <?php if ($faculty['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($faculty['email']); ?>">
                                    <?php echo htmlspecialchars($faculty['email']); ?>
                                </a>
                                <?php else: ?>
                                N/A
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Phone</label>
                            <p class="fw-bold">
                                <?php if ($faculty['phone']): ?>
                                <a href="tel:<?php echo htmlspecialchars($faculty['phone']); ?>">
                                    <?php echo htmlspecialchars($faculty['phone']); ?>
                                </a>
                                <?php else: ?>
                                N/A
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Office Location</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($faculty['office_location'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small">Description</label>
                    <p><?php echo nl2br(htmlspecialchars($faculty['description'] ?? 'No description available')); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Statistics Card -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h5 class="app-card-title mb-3">
                    <i class="fas fa-chart-bar me-2"></i>Faculty Statistics
                </h5>
                
                <div class="row text-center">
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $faculty['department_count']; ?></div>
                        <div class="stats-type">Departments</div>
                    </div>
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $faculty['program_count']; ?></div>
                        <div class="stats-type">Programs</div>
                    </div>
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $faculty['student_count']; ?></div>
                        <div class="stats-type">Students</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Timestamps -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-body p-3">
                <h5 class="app-card-title mb-3">
                    <i class="fas fa-history me-2"></i>Timestamps
                </h5>
                <div class="mb-2">
                    <small class="text-muted">Created:</small><br>
                    <small><?php echo date('F j, Y, g:i a', strtotime($faculty['created_at'])); ?></small>
                </div>
                <div>
                    <small class="text-muted">Last Updated:</small><br>
                    <small><?php echo date('F j, Y, g:i a', strtotime($faculty['updated_at'])); ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Departments Section -->
<div class="app-card app-card-table shadow-sm mb-4">
    <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-building me-2"></i>Departments
        </h5>
        <a href="departments.php?faculty=<?php echo urlencode($faculty['faculty_name']); ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-eye me-1"></i>View All
        </a>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell">Department Code</th>
                        <th class="cell">Department Name</th>
                        <th class="cell">HOD</th>
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
                                <strong><?php echo htmlspecialchars($dept['department_code']); ?></strong>
                            </td>
                            <td class="cell">
                                <div class="fw-bold"><?php echo htmlspecialchars($dept['department_name']); ?></div>
                            </td>
                            <td class="cell">
                                <?php echo htmlspecialchars($dept['hod_name'] ?? 'N/A'); ?>
                            </td>
                            <td class="cell">
                                <span class="badge bg-info"><?php echo $dept['program_count']; ?></span>
                            </td>
                            <td class="cell">
                                <span class="badge bg-warning"><?php echo $dept['student_count']; ?></span>
                            </td>
                            <td class="cell text-end">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="view_department.php?id=<?php echo $dept['department_id']; ?>">
                                                <i class="fas fa-eye me-2"></i>View
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="edit_department.php?id=<?php echo $dept['department_id']; ?>">
                                                <i class="fas fa-edit me-2"></i>Edit
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="programs.php?department_id=<?php echo $dept['department_id']; ?>">
                                                <i class="fas fa-book me-2"></i>View Programs
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-3">
                                <div class="py-2">
                                    <i class="fas fa-building fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No departments found for this faculty.</p>
                                    <a href="departments.php?action=add&faculty=<?php echo urlencode($faculty['faculty_name']); ?>" class="btn btn-sm btn-primary mt-2">
                                        <i class="fas fa-plus me-1"></i>Add Department
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Programs Section -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-book me-2"></i>Programs
        </h5>
        <a href="programs.php?faculty=<?php echo urlencode($faculty['faculty_name']); ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-eye me-1"></i>View All
        </a>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell">Program Code</th>
                        <th class="cell">Program Name</th>
                        <th class="cell">Department</th>
                        <th class="cell">Duration</th>
                        <th class="cell">Degree Type</th>
                        <th class="cell text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($programs)): ?>
                        <?php foreach ($programs as $program): ?>
                        <tr>
                            <td class="cell">
                                <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                            </td>
                            <td class="cell">
                                <div class="fw-bold"><?php echo htmlspecialchars($program['program_name']); ?></div>
                            </td>
                            <td class="cell">
                                <?php echo htmlspecialchars($program['department_name']); ?>
                            </td>
                            <td class="cell">
                                <?php echo $program['duration_years']; ?> years
                            </td>
                            <td class="cell">
                                <span class="badge bg-info"><?php echo $program['degree_type']; ?></span>
                            </td>
                            <td class="cell text-end">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="view_program.php?id=<?php echo $program['program_id']; ?>">
                                                <i class="fas fa-eye me-2"></i>View
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="edit_program.php?id=<?php echo $program['program_id']; ?>">
                                                <i class="fas fa-edit me-2"></i>Edit
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="students.php?program_id=<?php echo $program['program_id']; ?>">
                                                <i class="fas fa-user-graduate me-2"></i>View Students
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-3">
                                <div class="py-2">
                                    <i class="fas fa-book fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No programs found for this faculty.</p>
                                    <a href="programs.php?action=add" class="btn btn-sm btn-primary mt-2">
                                        <i class="fas fa-plus me-1"></i>Add Program
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>