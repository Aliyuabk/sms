<?php
// search.php
ob_start();
require_once 'includes/header.php';

$page_title = "Search Results";

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Pagination settings
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Initialize results arrays
$students = [];
$courses = [];
$staff = [];
$hostels = [];
$programs = [];
$departments = [];

$total_students = 0;
$total_courses = 0;
$total_staff = 0;
$total_hostels = 0;
$total_programs = 0;
$total_departments = 0;

if (!empty($query)) {
    $search_term = "%{$query}%";
    
    // Search Students
    if ($search_type == 'all' || $search_type == 'students') {
        $student_sql = "
            SELECT s.*, d.department_name, p.program_name,
                   CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.matric_number LIKE ? 
               OR s.first_name LIKE ? 
               OR s.last_name LIKE ? 
               OR s.email LIKE ?
               OR s.jamb_reg_number LIKE ?
               OR s.phone LIKE ?
            ORDER BY s.registration_date DESC
            LIMIT {$offset}, {$records_per_page}
        ";
        $student_stmt = $pdo->prepare($student_sql);
        $student_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
        $students = $student_stmt->fetchAll();
        
        // Count total students
        $count_sql = "
            SELECT COUNT(*) FROM students s
            WHERE s.matric_number LIKE ? 
               OR s.first_name LIKE ? 
               OR s.last_name LIKE ? 
               OR s.email LIKE ?
               OR s.jamb_reg_number LIKE ?
               OR s.phone LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
        $total_students = $count_stmt->fetchColumn();
    }
    
    // Search Courses
    if ($search_type == 'all' || $search_type == 'courses') {
        $course_sql = "
            SELECT c.*, d.department_name, d.department_code,
                   (SELECT COUNT(*) FROM course_registrations WHERE course_id = c.course_id) as enrollment_count
            FROM courses c
            LEFT JOIN departments d ON c.department_id = d.department_id
            WHERE c.course_code LIKE ? 
               OR c.course_title LIKE ? 
               OR c.course_description LIKE ?
            ORDER BY c.course_code
            LIMIT {$offset}, {$records_per_page}
        ";
        $course_stmt = $pdo->prepare($course_sql);
        $course_stmt->execute([$search_term, $search_term, $search_term]);
        $courses = $course_stmt->fetchAll();
        
        $count_sql = "
            SELECT COUNT(*) FROM courses c
            WHERE c.course_code LIKE ? 
               OR c.course_title LIKE ? 
               OR c.course_description LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term]);
        $total_courses = $count_stmt->fetchColumn();
    }
    
    // Search Staff
    if ($search_type == 'all' || $search_type == 'staff') {
        $staff_sql = "
            SELECT s.*, d.department_name
            FROM staff s
            LEFT JOIN departments d ON s.department_id = d.department_id
            WHERE s.first_name LIKE ? 
               OR s.last_name LIKE ? 
               OR s.email LIKE ? 
               OR s.staff_number LIKE ?
               OR s.phone LIKE ?
            ORDER BY s.last_name
            LIMIT {$offset}, {$records_per_page}
        ";
        $staff_stmt = $pdo->prepare($staff_sql);
        $staff_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
        $staff = $staff_stmt->fetchAll();
        
        $count_sql = "
            SELECT COUNT(*) FROM staff s
            WHERE s.first_name LIKE ? 
               OR s.last_name LIKE ? 
               OR s.email LIKE ? 
               OR s.staff_number LIKE ?
               OR s.phone LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
        $total_staff = $count_stmt->fetchColumn();
    }
    
    // Search Hostels
    if ($search_type == 'all' || $search_type == 'hostels') {
        $hostel_sql = "
            SELECT h.*,
                   (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = h.hostel_id) as total_rooms,
                   (SELECT SUM(bed_count) FROM hostel_rooms WHERE hostel_id = h.hostel_id) as total_beds,
                   (SELECT COUNT(*) FROM hostel_allocations ha 
                    JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
                    WHERE hr.hostel_id = h.hostel_id AND ha.status = 'Active') as occupied_beds
            FROM hostels h
            WHERE h.hostel_name LIKE ? 
               OR h.hostel_code LIKE ? 
               OR h.warden_name LIKE ?
            ORDER BY h.hostel_name
            LIMIT {$offset}, {$records_per_page}
        ";
        $hostel_stmt = $pdo->prepare($hostel_sql);
        $hostel_stmt->execute([$search_term, $search_term, $search_term]);
        $hostels = $hostel_stmt->fetchAll();
        
        $count_sql = "
            SELECT COUNT(*) FROM hostels h
            WHERE h.hostel_name LIKE ? 
               OR h.hostel_code LIKE ? 
               OR h.warden_name LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term]);
        $total_hostels = $count_stmt->fetchColumn();
    }
    
    // Search Programs
    if ($search_type == 'all' || $search_type == 'programs') {
        $program_sql = "
            SELECT p.*, d.department_name
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.department_id
            WHERE p.program_code LIKE ? 
               OR p.program_name LIKE ?
            ORDER BY p.program_name
            LIMIT {$offset}, {$records_per_page}
        ";
        $program_stmt = $pdo->prepare($program_sql);
        $program_stmt->execute([$search_term, $search_term]);
        $programs = $program_stmt->fetchAll();
        
        $count_sql = "
            SELECT COUNT(*) FROM programs p
            WHERE p.program_code LIKE ? 
               OR p.program_name LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term]);
        $total_programs = $count_stmt->fetchColumn();
    }
    
    // Search Departments
    if ($search_type == 'all' || $search_type == 'departments') {
        $department_sql = "
            SELECT d.*, f.faculty_name
            FROM departments d
            LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
            WHERE d.department_code LIKE ? 
               OR d.department_name LIKE ?
               OR d.hod_name LIKE ?
            ORDER BY d.department_name
            LIMIT {$offset}, {$records_per_page}
        ";
        $department_stmt = $pdo->prepare($department_sql);
        $department_stmt->execute([$search_term, $search_term, $search_term]);
        $departments = $department_stmt->fetchAll();
        
        $count_sql = "
            SELECT COUNT(*) FROM departments d
            WHERE d.department_code LIKE ? 
               OR d.department_name LIKE ?
               OR d.hod_name LIKE ?
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term]);
        $total_departments = $count_stmt->fetchColumn();
    }
}

// Calculate total results
$total_results = $total_students + $total_courses + $total_staff + $total_hostels + $total_programs + $total_departments;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - <?php echo htmlspecialchars($query); ?></title>
    <style>
        .search-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
        }
        .search-tabs .nav-link {
            color: #6c757d;
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        .search-tabs .nav-link:hover {
            background: #f8f9fa;
            border-radius: 10px;
        }
        .search-tabs .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
        }
        .result-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .result-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .highlight {
            background-color: #fff3cd;
            padding: 0 2px;
            border-radius: 3px;
            font-weight: bold;
        }
        .search-stats {
            font-size: 14px;
            color: #6c757d;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Search Header -->
    <div class="search-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2">
                    <i class="fas fa-search me-2"></i>
                    Search Results
                </h2>
                <p class="mb-0">
                    <?php if (!empty($query)): ?>
                        Showing results for: <strong>"<?php echo htmlspecialchars($query); ?>"</strong>
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($total_results); ?> results found</span>
                    <?php else: ?>
                        Enter a search term to find students, courses, staff, and more...
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4">
                <form method="GET" action="">
                    <div class="input-group">
                        <input type="text" class="form-control" name="q" placeholder="Search again..." 
                               value="<?php echo htmlspecialchars($query); ?>">
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($search_type); ?>">
                        <button class="btn btn-light" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($query)): ?>
    
    <!-- Search Tabs -->
    <ul class="nav nav-tabs search-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'all' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('all')" type="button">
                <i class="fas fa-globe me-2"></i>All Results
                <span class="badge bg-secondary ms-1"><?php echo $total_results; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'students' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('students')" type="button">
                <i class="fas fa-user-graduate me-2"></i>Students
                <span class="badge bg-secondary ms-1"><?php echo $total_students; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'courses' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('courses')" type="button">
                <i class="fas fa-book me-2"></i>Courses
                <span class="badge bg-secondary ms-1"><?php echo $total_courses; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'staff' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('staff')" type="button">
                <i class="fas fa-chalkboard-user me-2"></i>Staff
                <span class="badge bg-secondary ms-1"><?php echo $total_staff; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'hostels' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('hostels')" type="button">
                <i class="fas fa-hotel me-2"></i>Hostels
                <span class="badge bg-secondary ms-1"><?php echo $total_hostels; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'programs' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('programs')" type="button">
                <i class="fas fa-graduation-cap me-2"></i>Programs
                <span class="badge bg-secondary ms-1"><?php echo $total_programs; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $search_type == 'departments' ? 'active' : ''; ?>" 
                    onclick="changeSearchType('departments')" type="button">
                <i class="fas fa-building me-2"></i>Departments
                <span class="badge bg-secondary ms-1"><?php echo $total_departments; ?></span>
            </button>
        </li>
    </ul>

    <!-- Results Content -->
    <div class="tab-content">
        <!-- Students Results -->
        <?php if (($search_type == 'all' || $search_type == 'students') && !empty($students)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-user-graduate text-primary me-2"></i>Students
                <small class="text-muted">(<?php echo number_format($total_students); ?> results)</small>
            </h4>
            <?php foreach ($students as $student): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="view_student.php?id=<?php echo $student['student_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($student['full_name'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-id-card me-1"></i> Matric: <?php echo highlightText($student['matric_number'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-envelope me-1"></i> <?php echo highlightText($student['email'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-graduation-cap me-1"></i> Level: <?php echo $student['current_level']; ?> | <?php echo $student['department_name'] ?? 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-<?php echo $student['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                <?php echo $student['status']; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Courses Results -->
        <?php if (($search_type == 'all' || $search_type == 'courses') && !empty($courses)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-book text-success me-2"></i>Courses
                <small class="text-muted">(<?php echo number_format($total_courses); ?> results)</small>
            </h4>
            <?php foreach ($courses as $course): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-success bg-opacity-10 text-success">
                                <i class="fas fa-book"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="view_course.php?id=<?php echo $course['course_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($course['course_code'], $query); ?> - <?php echo highlightText($course['course_title'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-building me-1"></i> <?php echo $course['department_name'] ?? 'N/A'; ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-star me-1"></i> Credits: <?php echo $course['credit_units']; ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-users me-1"></i> Enrolled: <?php echo $course['enrollment_count']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <?php if ($course['is_core']): ?>
                            <span class="badge bg-primary">Core</span>
                            <?php elseif ($course['is_elective']): ?>
                            <span class="badge bg-info">Elective</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Staff Results -->
        <?php if (($search_type == 'all' || $search_type == 'staff') && !empty($staff)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-chalkboard-user text-info me-2"></i>Staff
                <small class="text-muted">(<?php echo number_format($total_staff); ?> results)</small>
            </h4>
            <?php foreach ($staff as $staff_member): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-info bg-opacity-10 text-info">
                                <i class="fas fa-chalkboard-user"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="view_staff.php?id=<?php echo $staff_member['staff_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($staff_member['first_name'] . ' ' . ($staff_member['middle_name'] ?? '') . ' ' . $staff_member['last_name'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-id-card me-1"></i> Staff ID: <?php echo highlightText($staff_member['staff_number'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-envelope me-1"></i> <?php echo highlightText($staff_member['email'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-building me-1"></i> <?php echo $staff_member['department_name'] ?? 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-secondary"><?php echo $staff_member['designation'] ?? 'Staff'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Hostels Results -->
        <?php if (($search_type == 'all' || $search_type == 'hostels') && !empty($hostels)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-hotel text-warning me-2"></i>Hostels
                <small class="text-muted">(<?php echo number_format($total_hostels); ?> results)</small>
            </h4>
            <?php foreach ($hostels as $hostel): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-warning bg-opacity-10 text-warning">
                                <i class="fas fa-hotel"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="hostels.php?hostel_id=<?php echo $hostel['hostel_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($hostel['hostel_name'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-code me-1"></i> Code: <?php echo highlightText($hostel['hostel_code'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-bed me-1"></i> Total Beds: <?php echo $hostel['total_beds']; ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-user-check me-1"></i> Occupied: <?php echo $hostel['occupied_beds']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-<?php echo $hostel['status'] == 'Available' ? 'success' : 'warning'; ?>">
                                <?php echo $hostel['status']; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Programs Results -->
        <?php if (($search_type == 'all' || $search_type == 'programs') && !empty($programs)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-graduation-cap text-danger me-2"></i>Programs
                <small class="text-muted">(<?php echo number_format($total_programs); ?> results)</small>
            </h4>
            <?php foreach ($programs as $program): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-danger bg-opacity-10 text-danger">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="programs.php?program_id=<?php echo $program['program_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($program['program_name'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-code me-1"></i> Code: <?php echo highlightText($program['program_code'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-building me-1"></i> <?php echo $program['department_name'] ?? 'N/A'; ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-clock me-1"></i> Duration: <?php echo $program['duration_years']; ?> years
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Departments Results -->
        <?php if (($search_type == 'all' || $search_type == 'departments') && !empty($departments)): ?>
        <div class="mb-4">
            <h4 class="mb-3">
                <i class="fas fa-building text-secondary me-2"></i>Departments
                <small class="text-muted">(<?php echo number_format($total_departments); ?> results)</small>
            </h4>
            <?php foreach ($departments as $department): ?>
            <div class="card result-card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="result-icon bg-secondary bg-opacity-10 text-secondary">
                                <i class="fas fa-building"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h6 class="mb-1">
                                <a href="departments.php?department_id=<?php echo $department['department_id']; ?>" class="text-decoration-none">
                                    <?php echo highlightText($department['department_name'], $query); ?>
                                </a>
                            </h6>
                            <div class="row small text-muted">
                                <div class="col-md-4">
                                    <i class="fas fa-code me-1"></i> Code: <?php echo highlightText($department['department_code'], $query); ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-university me-1"></i> Faculty: <?php echo $department['faculty_name'] ?? 'N/A'; ?>
                                </div>
                                <div class="col-md-4">
                                    <i class="fas fa-user-tie me-1"></i> HOD: <?php echo highlightText($department['hod_name'] ?? 'Not assigned', $query); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- No Results Found -->
        <?php if ($total_results == 0): ?>
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <h5>No results found for "<?php echo htmlspecialchars($query); ?>"</h5>
            <p class="text-muted">Try checking your spelling or use more general terms.</p>
            <div class="mt-3">
                <button class="btn btn-primary" onclick="history.back()">
                    <i class="fas fa-arrow-left me-2"></i>Go Back
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    
    <!-- Empty Search State -->
    <div class="empty-state">
        <i class="fas fa-search"></i>
        <h5>Enter a search term to begin</h5>
        <p class="text-muted">Search for students, courses, staff, hostels, programs, or departments</p>
        <div class="row justify-content-center mt-4">
            <div class="col-md-6">
                <form method="GET" action="">
                    <div class="input-group">
                        <input type="text" class="form-control form-control-lg" name="q" placeholder="e.g., student name, course code, staff ID..." autofocus>
                        <button class="btn btn-primary btn-lg" type="submit">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="text-center">
                    <i class="fas fa-user-graduate fa-2x text-primary mb-2"></i>
                    <p class="small">Search by matric number, name, email</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center">
                    <i class="fas fa-book fa-2x text-success mb-2"></i>
                    <p class="small">Search by course code, title</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center">
                    <i class="fas fa-hotel fa-2x text-warning mb-2"></i>
                    <p class="small">Search by hostel name, code</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center">
                    <i class="fas fa-building fa-2x text-secondary mb-2"></i>
                    <p class="small">Search by department, program</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<script>
function changeSearchType(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('type', type);
    window.location.href = url.toString();
}

// Highlight search term in results (server-side function for PHP)
function highlightText(text, searchTerm) {
    if (searchTerm && text) {
        const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return text.toString().replace(regex, '<span class="highlight">$1</span>');
    }
    return text;
}
</script>

<?php
// Helper function to highlight search terms in text
function highlightText($text, $search) {
    if (empty($search) || empty($text)) {
        return htmlspecialchars($text);
    }
    
    $search_terms = explode(' ', $search);
    $highlighted = htmlspecialchars($text);
    
    foreach ($search_terms as $term) {
        if (strlen($term) > 2) {
            $pattern = '/' . preg_quote($term, '/') . '/i';
            $highlighted = preg_replace($pattern, '<span class="highlight">$0</span>', $highlighted);
        }
    }
    
    return $highlighted;
}

// Re-apply the helper function for display
foreach ($students as &$student) {
    $student['full_name'] = highlightText($student['full_name'], $query);
    $student['matric_number'] = highlightText($student['matric_number'], $query);
    $student['email'] = highlightText($student['email'], $query);
}
foreach ($courses as &$course) {
    $course['course_code'] = highlightText($course['course_code'], $query);
    $course['course_title'] = highlightText($course['course_title'], $query);
}
foreach ($staff as &$staff_member) {
    $staff_member['first_name'] = highlightText($staff_member['first_name'], $query);
    $staff_member['last_name'] = highlightText($staff_member['last_name'], $query);
    $staff_member['staff_number'] = highlightText($staff_member['staff_number'], $query);
}
foreach ($hostels as &$hostel) {
    $hostel['hostel_name'] = highlightText($hostel['hostel_name'], $query);
    $hostel['hostel_code'] = highlightText($hostel['hostel_code'], $query);
}
foreach ($programs as &$program) {
    $program['program_name'] = highlightText($program['program_name'], $query);
    $program['program_code'] = highlightText($program['program_code'], $query);
}
foreach ($departments as &$department) {
    $department['department_name'] = highlightText($department['department_name'], $query);
    $department['department_code'] = highlightText($department['department_code'], $query);
}
?>

<?php require_once 'includes/footer.php'; ?>