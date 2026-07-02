<?php
// view_department.php
ob_start();
require_once 'includes/header.php';

// Set page title
$page_title = "View Department";

// Get department ID from URL
$department_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($department_id <= 0) {
    header("Location: departments.php");
    exit();
}

// Get department details
$department_stmt = $pdo->prepare("
    SELECT d.*,
           (SELECT COUNT(*) FROM students WHERE department_id = d.department_id) as student_count,
           (SELECT COUNT(*) FROM programs WHERE department_id = d.department_id) as program_count,
           (SELECT COUNT(*) FROM courses WHERE department_id = d.department_id) as course_count
    FROM departments d 
    WHERE d.department_id = ?
");

$department_stmt->execute([$department_id]);
$department = $department_stmt->fetch();

if (!$department) {
    $_SESSION['error_message'] = "Department not found!";
    header("Location: departments.php");
    exit();
}

// Get programs under this department
$programs_stmt = $pdo->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM students WHERE program_id = p.program_id) as student_count
    FROM programs p 
    WHERE p.department_id = ? 
    ORDER BY p.program_name
");

$programs_stmt->execute([$department_id]);
$programs = $programs_stmt->fetchAll();

// Get courses under this department
$courses_stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(DISTINCT student_id) FROM course_registrations WHERE course_id = c.course_id) as enrollment_count
    FROM courses c 
    WHERE c.department_id = ? 
    ORDER BY c.level, c.semester, c.course_code
");

$courses_stmt->execute([$department_id]);
$courses = $courses_stmt->fetchAll();

// Get recent students
$students_stmt = $pdo->prepare("
    SELECT s.*, p.program_name
    FROM students s
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE s.department_id = ?
    ORDER BY s.registration_date DESC 
    LIMIT 10
");

$students_stmt->execute([$department_id]);
$students = $students_stmt->fetchAll();

// Get staff (academic advisors) for this department
$staff_stmt = $pdo->prepare("
    SELECT a.*
    FROM academic_advisors a 
    WHERE a.department_id = ?
    ORDER BY a.first_name, a.last_name
");

$staff_stmt->execute([$department_id]);
$staff = $staff_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0"><?php echo htmlspecialchars($department['department_name']); ?></h1>
    <div class="app-actions">
        <a href="departments.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Departments
        </a>
        <a href="edit_department.php?id=<?php echo $department_id; ?>" class="btn btn-primary ms-2">
            <i class="fas fa-edit me-2"></i>Edit Department
        </a>
    </div>
</div>

<!-- Department Overview -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-building me-2"></i>Department Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="text-muted small">Department Code</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($department['department_code']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Department Name</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($department['department_name']); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Faculty</label>
                            <p class="fw-bold">
                                <?php if ($department['faculty']): ?>
                                <a href="faculties.php?faculty=<?php echo urlencode($department['faculty']); ?>">
                                    <?php echo htmlspecialchars($department['faculty']); ?>
                                </a>
                                <?php else: ?>
                                N/A
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="text-muted small">Head of Department</label>
                            <p class="fw-bold"><?php echo htmlspecialchars($department['hod_name'] ?? 'N/A'); ?></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Email</label>
                            <p class="fw-bold">
                                <?php if ($department['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($department['email']); ?>">
                                    <?php echo htmlspecialchars($department['email']); ?>
                                </a>
                                <?php else: ?>
                                N/A
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="text-muted small">Phone</label>
                            <p class="fw-bold">
                                <?php if ($department['phone']): ?>
                                <a href="tel:<?php echo htmlspecialchars($department['phone']); ?>">
                                    <?php echo htmlspecialchars($department['phone']); ?>
                                </a>
                                <?php else: ?>
                                N/A
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small">Established</label>
                    <p class="fw-bold"><?php echo date('F j, Y', strtotime($department['created_date'])); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Statistics Card -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h5 class="app-card-title mb-3">
                    <i class="fas fa-chart-bar me-2"></i>Department Statistics
                </h5>
                
                <div class="row text-center">
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $department['program_count']; ?></div>
                        <div class="stats-type">Programs</div>
                    </div>
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $department['course_count']; ?></div>
                        <div class="stats-type">Courses</div>
                    </div>
                    <div class="col-4">
                        <div class="stats-figure"><?php echo $department['student_count']; ?></div>
                        <div class="stats-type">Students</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Academic Staff -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-user-tie me-2"></i>Academic Staff
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php if (!empty($staff)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($staff as $advisor): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="app-icon-holder icon-holder-sm">
                                        <i class="fas fa-user text-primary"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-user-graduate me-1"></i>
                                        <?php echo $advisor['current_students']; ?> students
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No academic staff assigned.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Programs Section -->
<div class="app-card app-card-table shadow-sm mb-4">
    <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-book me-2"></i>Programs
        </h5>
        <a href="programs.php?department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary">
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
                        <th class="cell">Degree Type</th>
                        <th class="cell">Duration</th>
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
                                <strong><?php echo htmlspecialchars($program['program_code']); ?></strong>
                            </td>
                            <td class="cell">
                                <div class="fw-bold"><?php echo htmlspecialchars($program['program_name']); ?></div>
                                <small class="text-muted"><?php echo $program['total_credits']; ?> credits</small>
                            </td>
                            <td class="cell">
                                <span class="badge bg-info"><?php echo $program['degree_type']; ?></span>
                            </td>
                            <td class="cell">
                                <?php echo $program['duration_years']; ?> years
                            </td>
                            <td class="cell">
                                <span class="badge bg-warning"><?php echo $program['student_count']; ?></span>
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
                            <td colspan="7" class="text-center py-3">
                                <div class="py-2">
                                    <i class="fas fa-book fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No programs found for this department.</p>
                                    <a href="programs.php?action=add&department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary mt-2">
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

<!-- Courses Section -->
<div class="app-card app-card-table shadow-sm mb-4">
    <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-book-open me-2"></i>Courses
        </h5>
        <a href="courses.php?department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-eye me-1"></i>View All
        </a>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell">Course Code</th>
                        <th class="cell">Course Title</th>
                        <th class="cell">Level</th>
                        <th class="cell">Semester</th>
                        <th class="cell">Credits</th>
                        <th class="cell">Enrollment</th>
                        <th class="cell">Type</th>
                        <th class="cell text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($courses)): ?>
                        <?php foreach ($courses as $course): ?>
                        <tr>
                            <td class="cell">
                                <strong><?php echo htmlspecialchars($course['course_code']); ?></strong>
                            </td>
                            <td class="cell">
                                <div class="fw-bold"><?php echo htmlspecialchars($course['course_title']); ?></div>
                            </td>
                            <td class="cell">
                                <span class="badge bg-info"><?php echo $course['level']; ?> Level</span>
                            </td>
                            <td class="cell">
                                <span class="badge bg-secondary">Semester <?php echo $course['semester']; ?></span>
                            </td>
                            <td class="cell">
                                <?php echo $course['credit_units']; ?> units
                            </td>
                            <td class="cell">
                                <span class="badge bg-warning"><?php echo $course['enrollment_count']; ?></span>
                            </td>
                            <td class="cell">
                                <?php 
                                $type_class = $course['is_core'] ? 'success' : ($course['is_elective'] ? 'primary' : 'secondary');
                                $type_text = $course['is_core'] ? 'Core' : ($course['is_elective'] ? 'Elective' : 'General');
                                ?>
                                <span class="badge bg-<?php echo $type_class; ?>"><?php echo $type_text; ?></span>
                            </td>
                            <td class="cell text-end">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="view_course.php?id=<?php echo $course['course_id']; ?>">
                                                <i class="fas fa-eye me-2"></i>View
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="edit_course.php?id=<?php echo $course['course_id']; ?>">
                                                <i class="fas fa-edit me-2"></i>Edit
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="course_registrations.php?course_id=<?php echo $course['course_id']; ?>">
                                                <i class="fas fa-users me-2"></i>View Enrollments
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-3">
                                <div class="py-2">
                                    <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No courses found for this department.</p>
                                    <a href="courses.php?action=add&department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary mt-2">
                                        <i class="fas fa-plus me-1"></i>Add Course
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

<!-- Recent Students -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
        <h5 class="app-card-title mb-0">
            <i class="fas fa-user-graduate me-2"></i>Recent Students
        </h5>
        <a href="students.php?department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-eye me-1"></i>View All
        </a>
    </div>
    
    <div class="app-card-body p-0">
        <div class="table-responsive">
            <table class="table app-table-hover mb-0">
                <thead>
                    <tr>
                        <th class="cell">Matric Number</th>
                        <th class="cell">Student Name</th>
                        <th class="cell">Program</th>
                        <th class="cell">Level</th>
                        <th class="cell">Status</th>
                        <th class="cell text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="cell">
                                <strong><?php echo htmlspecialchars($student['matric_number']); ?></strong>
                            </td>
                            <td class="cell">
                                <div class="fw-bold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                            </td>
                            <td class="cell">
                                <?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?>
                            </td>
                            <td class="cell">
                                <span class="badge bg-info"><?php echo $student['current_level']; ?> Level</span>
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
                            <td class="cell text-end">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="view_student.php?id=<?php echo $student['student_id']; ?>">
                                                <i class="fas fa-eye me-2"></i>View
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="edit_student.php?id=<?php echo $student['student_id']; ?>">
                                                <i class="fas fa-edit me-2"></i>Edit
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
                                    <i class="fas fa-user-graduate fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No students found in this department.</p>
                                    <a href="add_student.php?department_id=<?php echo $department_id; ?>" class="btn btn-sm btn-primary mt-2">
                                        <i class="fas fa-plus me-1"></i>Add Student
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