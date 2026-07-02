<?php
// view_program.php
session_start();
require_once 'includes/database.php';
require_once 'includes/auth.php';

// Check if user is logged in
requireLogin();

// Check if program ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: programs.php');
    exit();
}

$program_id = (int)$_GET['id'];

// Fetch program details
try {
    $sql = "SELECT p.*, 
                   d.department_name,
                   d.department_code,
                   d.faculty,
                   (SELECT COUNT(*) FROM students WHERE program_id = p.program_id AND status != 'Deleted') as student_count,
                   (SELECT COUNT(*) FROM courses WHERE program_id = p.program_id) as course_count
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.department_id
            WHERE p.program_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id]);
    $program = $stmt->fetch();
    
    if (!$program) {
        $_SESSION['error_message'] = "Program not found.";
        header('Location: programs.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: programs.php');
    exit();
}

// Fetch recent students
$recent_students = [];
try {
    $sql = "SELECT student_id, matric_number, 
                   CONCAT(first_name, ' ', last_name) as full_name,
                   email, current_level, status
            FROM students 
            WHERE program_id = ? 
            AND status != 'Deleted'
            ORDER BY registration_date DESC 
            LIMIT 5";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id]);
    $recent_students = $stmt->fetchAll();
} catch (PDOException $e) {
    // Silently fail for optional data
    error_log("Error fetching recent students: " . $e->getMessage());
}

// Fetch program courses
$program_courses = [];
try {
    $sql = "SELECT course_id, course_code, course_name, credits, semester
            FROM courses 
            WHERE program_id = ?
            ORDER BY semester, course_code";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id]);
    $program_courses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching courses: " . $e->getMessage());
}

$page_title = "View Program - " . htmlspecialchars($program['program_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .program-icon {
            font-size: 2.5rem;
            color: #6c757d;
            opacity: 0.8;
        }
        .details-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .details-value {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }
        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>Student Portal
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="programs.php">
                            <i class="fas fa-book"></i> Programs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="programs.php">Programs</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($program['program_name']); ?></li>
            </ol>
        </nav>

        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1">
                    <i class="fas fa-graduation-cap text-primary me-2"></i>
                    <?php echo htmlspecialchars($program['program_name']); ?>
                </h1>
                <p class="text-muted mb-0">
                    <code><?php echo htmlspecialchars($program['program_code']); ?></code>
                    • 
                    <?php if ($program['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="programs.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
                <a href="edit_program.php?id=<?php echo $program_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="program-icon mb-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="mb-1"><?php echo number_format($program['student_count']); ?></h3>
                        <p class="text-muted mb-0">Total Students</p>
                        <a href="students.php?program_id=<?php echo $program_id; ?>" class="btn btn-sm btn-outline-primary mt-3">
                            View Students
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="program-icon mb-3">
                            <i class="fas fa-book"></i>
                        </div>
                        <h3 class="mb-1"><?php echo number_format($program['course_count']); ?></h3>
                        <p class="text-muted mb-0">Total Courses</p>
                        <a href="courses.php?program_id=<?php echo $program_id; ?>" class="btn btn-sm btn-outline-success mt-3">
                            View Courses
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="program-icon mb-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3 class="mb-1"><?php echo $program['duration_years'] ?? 'N/A'; ?></h3>
                        <p class="text-muted mb-0">Duration (Years)</p>
                        <div class="mt-3">
                            <span class="badge bg-info">
                                <?php echo htmlspecialchars($program['degree_type'] ?? 'Not Set'); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="program-icon mb-3">
                            <i class="fas fa-award"></i>
                        </div>
                        <h3 class="mb-1"><?php echo $program['total_credits'] ?? 'N/A'; ?></h3>
                        <p class="text-muted mb-0">Total Credits</p>
                        <div class="mt-3">
                            <small class="text-muted">Program Code: <?php echo htmlspecialchars($program['program_code']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Program Details -->
        <div class="row">
            <div class="col-lg-8">
                <!-- Basic Information -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle text-primary me-2"></i>
                            Program Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="details-label">Program Code</div>
                                <div class="details-value">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($program['program_code']); ?></span>
                                </div>
                                
                                <div class="details-label">Program Name</div>
                                <div class="details-value"><?php echo htmlspecialchars($program['program_name']); ?></div>
                                
                                <div class="details-label">Degree Type</div>
                                <div class="details-value">
                                    <?php if ($program['degree_type']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($program['degree_type']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not specified</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="details-label">Duration</div>
                                <div class="details-value">
                                    <i class="fas fa-calendar-alt text-muted me-1"></i>
                                    <?php echo $program['duration_years'] ?? 'N/A'; ?> Years
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="details-label">Department</div>
                                <div class="details-value">
                                    <i class="fas fa-building text-muted me-1"></i>
                                    <?php echo htmlspecialchars($program['department_name'] ?? 'Not assigned'); ?>
                                    <?php if (!empty($program['department_code'])): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($program['department_code']); ?>)</small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="details-label">Faculty</div>
                                <div class="details-value">
                                    <i class="fas fa-university text-muted me-1"></i>
                                    <?php echo htmlspecialchars($program['faculty'] ?? 'Not assigned'); ?>
                                </div>
                                
                                <div class="details-label">Total Credits</div>
                                <div class="details-value">
                                    <i class="fas fa-award text-muted me-1"></i>
                                    <?php echo $program['total_credits'] ?? 'N/A'; ?> Credits
                                </div>
                                
                                <div class="details-label">Status</div>
                                <div class="details-value">
                                    <?php if ($program['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($program['description'])): ?>
                            <div class="col-12 mt-3">
                                <div class="details-label">Description</div>
                                <div class="details-value">
                                    <div class="card bg-light border-0">
                                        <div class="card-body">
                                            <?php echo nl2br(htmlspecialchars($program['description'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-12 mt-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="details-label">Created</div>
                                        <div class="details-value">
                                            <i class="far fa-calendar-plus text-muted me-1"></i>
                                            <?php echo date('F d, Y', strtotime($program['created_at'] ?? 'now')); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="details-label">Last Updated</div>
                                        <div class="details-value">
                                            <i class="far fa-calendar-check text-muted me-1"></i>
                                            <?php echo timeAgo($program['updated_at'] ?? ''); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Program Courses -->
                <?php if (!empty($program_courses)): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-book text-success me-2"></i>
                                Program Courses (<?php echo count($program_courses); ?>)
                            </h5>
                            <a href="courses.php?program_id=<?php echo $program_id; ?>" class="btn btn-sm btn-outline-success">
                                View All
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Credits</th>
                                        <th>Semester</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($program_courses as $course): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($course['course_code']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $course['credits']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($course['semester']): ?>
                                                <span class="badge bg-secondary">Sem <?php echo $course['semester']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="view_course.php?id=<?php echo $course['course_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Recent Students -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-user-graduate text-primary me-2"></i>
                                Recent Students
                            </h5>
                            <span class="badge bg-primary"><?php echo $program['student_count']; ?> total</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recent_students)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_students as $student): ?>
                                <a href="view_student.php?id=<?php echo $student['student_id']; ?>" 
                                   class="list-group-item list-group-item-action border-0 py-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($student['full_name']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($student['matric_number']); ?>
                                                • Level <?php echo $student['current_level']; ?>
                                            </small>
                                        </div>
                                        <div>
                                            <span class="badge bg-<?php echo $student['status'] == 'Active' ? 'success' : 'warning'; ?>">
                                                <?php echo $student['status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No students enrolled yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-white border-top py-3">
                        <a href="students.php?program_id=<?php echo $program_id; ?>" class="btn btn-outline-primary w-100">
                            <i class="fas fa-users me-1"></i> View All Students
                        </a>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt text-warning me-2"></i>
                            Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="add_student.php?program_id=<?php echo $program_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-user-plus me-1"></i> Add Student
                            </a>
                            <a href="add_course.php?program_id=<?php echo $program_id; ?>" class="btn btn-outline-success">
                                <i class="fas fa-book-medical me-1"></i> Add Course
                            </a>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="fas fa-trash me-1"></i> Delete Program
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Delete Program
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h6 class="alert-heading">Warning!</h6>
                        <p class="mb-0">
                            Are you sure you want to delete the program 
                            <strong>"<?php echo htmlspecialchars($program['program_name']); ?>"</strong>?
                        </p>
                    </div>
                    
                    <?php if ($program['student_count'] > 0 || $program['course_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <h6 class="alert-heading">Note:</h6>
                        <ul class="mb-0 small">
                            <?php if ($program['student_count'] > 0): ?>
                            <li>This program has <?php echo $program['student_count']; ?> enrolled students</li>
                            <?php endif; ?>
                            <?php if ($program['course_count'] > 0): ?>
                            <li>This program has <?php echo $program['course_count']; ?> courses</li>
                            <?php endif; ?>
                            <li>These will need to be reassigned or deleted separately</li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <form id="deleteForm" method="POST" action="delete_program.php">
                        <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                        <input type="hidden" name="confirm" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteForm" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Confirm before deleting
    document.getElementById('deleteForm').addEventListener('submit', function(e) {
        if (!confirm('Are you absolutely sure? This action cannot be undone!')) {
            e.preventDefault();
        }
    });
    </script>
</body>
</html>