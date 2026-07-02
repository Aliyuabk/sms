<?php
ob_start();
require_once 'includes/header.php';
$page_title = "Staff Management";

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];

// ============================================
// PAGINATION SETTINGS
// ============================================
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// ============================================
// SEARCH AND FILTERS
// ============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$employment_type = isset($_GET['employment_type']) ? $_GET['employment_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.staff_number LIKE ? OR s.designation LIKE ? OR d.department_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($employment_type !== '') {
    $conditions[] = "s.employment_type = ?";
    $params[] = $employment_type;
}

if ($status !== '') {
    $conditions[] = "s.status = ?";
    $params[] = $status;
}

if ($role_filter !== '') {
    $conditions[] = "s.role = ?";
    $params[] = $role_filter;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ============================================
// GET FILTER DATA
// ============================================
$departments = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name")->fetchAll();
$employment_types = ['Full-time', 'Part-time', 'Contract', 'Visiting'];
$status_options = ['Active', 'Inactive', 'On Leave', 'Retired', 'Terminated'];
$role_options = ['Lecturer', 'HOD', 'Dean', 'Bursar', 'Registrar', 'Admin', 'Supervisor'];

// Get current academic session
$current_session = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();
$current_session_year = $current_session ? $current_session['session_year'] : date('Y') . '/' . (date('Y') + 1);
$current_semester = $current_session ? $current_session['semester'] : 1;

// Get all courses for assignment
$courses = $pdo->query("
    SELECT c.course_id, c.course_code, c.course_title, c.credit_units, c.level, 
           d.department_name, p.program_name
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_programs cp ON c.course_id = cp.course_id
    LEFT JOIN programs p ON cp.program_id = p.program_id
    ORDER BY c.course_code
")->fetchAll();

// Get programs for assignment
$programs = $pdo->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();

// Get all staff roles
$staff_roles = $pdo->query("SELECT role_id, role_name, role_slug FROM staff_roles ORDER BY role_id")->fetchAll();

// ============================================
// FORM PROCESSING - ADD STAFF
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- ADD NEW STAFF ----
    if (isset($_POST['add_staff'])) {
        $staff_number = trim($_POST['staff_number']);
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? null;
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $department_id_new = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $designation = trim($_POST['designation']);
        $staff_role_id = !empty($_POST['staff_role_id']) ? (int)$_POST['staff_role_id'] : null;
        $employment_type_new = $_POST['employment_type'];
        $employment_date = !empty($_POST['employment_date']) ? $_POST['employment_date'] : null;
        $qualification = trim($_POST['qualification'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $office_location = trim($_POST['office_location'] ?? '');
        $office_hours = trim($_POST['office_hours'] ?? '');
        $status_new = $_POST['status'];
        $contract_status = $_POST['contract_status'] ?? 'Active';
        $contract_start = !empty($_POST['contract_start']) ? $_POST['contract_start'] : null;
        $contract_end = !empty($_POST['contract_end']) ? $_POST['contract_end'] : null;
        $can_login = isset($_POST['can_login']) ? 1 : 0;
        $login_username = trim($_POST['login_username'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $role = $_POST['role'] ?? 'Lecturer';

        $login_password_hash = null;
        if ($can_login && !empty($_POST['login_password'])) {
            $login_password_hash = password_hash($_POST['login_password'], PASSWORD_DEFAULT);
        }

        try {
            $check_sql = "SELECT staff_id FROM staff WHERE staff_number = ? OR email = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$staff_number, $email]);

            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Staff number or Email already exists!";
            } else {
                $insert_sql = "INSERT INTO staff (
                    staff_number, first_name, middle_name, last_name, email, phone, gender,
                    date_of_birth, department_id, designation, staff_role_id, role, employment_type,
                    employment_date, qualification, specialization, office_location,
                    office_hours, status, contract_status, contract_start, contract_end,
                    can_login, login_username, login_password_hash, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $staff_number, $first_name, $middle_name, $last_name, $email, $phone, $gender,
                    $date_of_birth, $department_id_new, $designation, $staff_role_id, $role, $employment_type_new,
                    $employment_date, $qualification, $specialization, $office_location,
                    $office_hours, $status_new, $contract_status, $contract_start, $contract_end,
                    $can_login, $login_username, $login_password_hash, $notes, $admin_id
                ]);

                $new_staff_id = $pdo->lastInsertId();

                $log_sql = "INSERT INTO staff_activity_log (staff_id, activity_type, description, ip_address) VALUES (?, 'Account Created', 'Staff account created by admin', ?)";
                $pdo->prepare($log_sql)->execute([$new_staff_id, $_SERVER['REMOTE_ADDR'] ?? null]);

                $_SESSION['success_message'] = "Staff member added successfully! Staff ID: " . $new_staff_id;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding staff: " . $e->getMessage();
        }

        header("Location: manage_staffs.php");
        exit();
    }

    // ---- EDIT STAFF ----
    if (isset($_POST['edit_staff'])) {
        $staff_id = (int)$_POST['staff_id'];
        $staff_number = trim($_POST['staff_number']);
        $first_name = trim($_POST['first_name']);
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? null;
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $department_id_edit = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $designation = trim($_POST['designation']);
        $staff_role_id = !empty($_POST['staff_role_id']) ? (int)$_POST['staff_role_id'] : null;
        $role = $_POST['role'] ?? 'Lecturer';
        $employment_type_edit = $_POST['employment_type'];
        $employment_date = !empty($_POST['employment_date']) ? $_POST['employment_date'] : null;
        $qualification = trim($_POST['qualification'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $office_location = trim($_POST['office_location'] ?? '');
        $office_hours = trim($_POST['office_hours'] ?? '');
        $status_edit = $_POST['status'];
        $contract_status = $_POST['contract_status'] ?? 'Active';
        $contract_start = !empty($_POST['contract_start']) ? $_POST['contract_start'] : null;
        $contract_end = !empty($_POST['contract_end']) ? $_POST['contract_end'] : null;
        $can_login = isset($_POST['can_login']) ? 1 : 0;
        $login_username = trim($_POST['login_username'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        try {
            $check_sql = "SELECT staff_id FROM staff WHERE (staff_number = ? OR email = ?) AND staff_id != ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$staff_number, $email, $staff_id]);

            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Staff number or Email already exists for another staff!";
            } else {
                $update_sql = "UPDATE staff SET
                    staff_number = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, gender = ?,
                    date_of_birth = ?, department_id = ?, designation = ?, staff_role_id = ?, role = ?, employment_type = ?,
                    employment_date = ?, qualification = ?, specialization = ?, office_location = ?,
                    office_hours = ?, status = ?, contract_status = ?, contract_start = ?, contract_end = ?,
                    can_login = ?, login_username = ?, notes = ?, updated_at = NOW()
                    WHERE staff_id = ?";

                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $staff_number, $first_name, $middle_name, $last_name, $email, $phone, $gender,
                    $date_of_birth, $department_id_edit, $designation, $staff_role_id, $role, $employment_type_edit,
                    $employment_date, $qualification, $specialization, $office_location,
                    $office_hours, $status_edit, $contract_status, $contract_start, $contract_end,
                    $can_login, $login_username, $notes, $staff_id
                ]);

                if (!empty($_POST['login_password'])) {
                    $new_hash = password_hash($_POST['login_password'], PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE staff SET login_password_hash = ? WHERE staff_id = ?")
                        ->execute([$new_hash, $staff_id]);
                }

                $log_sql = "INSERT INTO staff_activity_log (staff_id, activity_type, description, ip_address) VALUES (?, 'Profile Updated', 'Staff profile updated by admin', ?)";
                $pdo->prepare($log_sql)->execute([$staff_id, $_SERVER['REMOTE_ADDR'] ?? null]);

                $_SESSION['success_message'] = "Staff member updated successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating staff: " . $e->getMessage();
        }

        header("Location: manage_staffs.php");
        exit();
    }

    // ---- ASSIGN COURSE TO STAFF ----
    if (isset($_POST['assign_course'])) {
        $staff_id = (int)$_POST['staff_id'];
        $course_id = (int)$_POST['course_id'];
        $session_year = $_POST['session_year'];
        $semester = (int)$_POST['semester'];
        $level = !empty($_POST['level']) ? (int)$_POST['level'] : null;
        $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
        $is_primary = isset($_POST['is_primary_instructor']) ? 1 : 0;

        try {
            $check_sql = "SELECT assignment_id FROM course_assignments 
                          WHERE staff_id = ? AND course_id = ? AND session_year = ? AND semester = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$staff_id, $course_id, $session_year, $semester]);

            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Course already assigned to this staff for selected session/semester!";
            } else {
                $assign_sql = "INSERT INTO course_assignments (
                    staff_id, course_id, session_year, semester, assigned_by, is_primary_instructor, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'Active')";
                $pdo->prepare($assign_sql)->execute([$staff_id, $course_id, $session_year, $semester, $admin_id, $is_primary]);

                $class_sql = "INSERT INTO staff_classes (
                    staff_id, course_id, session_year, semester, level, program_id, assigned_date, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Active', ?)";
                $pdo->prepare($class_sql)->execute([$staff_id, $course_id, $session_year, $semester, $level, $program_id, $admin_id]);

                $_SESSION['success_message'] = "Course assigned to staff successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error assigning course: " . $e->getMessage();
        }

        header("Location: manage_staffs.php?view_courses=" . $staff_id);
        exit();
    }

    // ---- REMOVE COURSE ASSIGNMENT ----
    if (isset($_POST['remove_course'])) {
        $assignment_id = (int)$_POST['assignment_id'];
        $staff_id = (int)$_POST['staff_id'];

        try {
            $pdo->prepare("UPDATE course_assignments SET status = 'Cancelled' WHERE assignment_id = ?")
                ->execute([$assignment_id]);

            $_SESSION['success_message'] = "Course assignment removed successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error removing course: " . $e->getMessage();
        }

        header("Location: manage_staffs.php?view_courses=" . $staff_id);
        exit();
    }

    // ---- BULK ACTIONS ----
    if (isset($_POST['bulk_action']) && isset($_POST['selected_staff'])) {
        $selected_ids = $_POST['selected_staff'];

        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

            switch ($_POST['bulk_action']) {
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'Active' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) activated!";
                    break;

                case 'deactivate':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'Inactive' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) deactivated!";
                    break;

                case 'leave':
                    $stmt = $pdo->prepare("UPDATE staff SET status = 'On Leave' WHERE staff_id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $_SESSION['success_message'] = count($selected_ids) . " staff member(s) marked as on leave!";
                    break;

                case 'delete':
                    $check_sql = "SELECT s.staff_id, s.staff_number,
                        (SELECT COUNT(*) FROM course_assignments WHERE staff_id = s.staff_id AND status = 'Active') as course_count,
                        (SELECT COUNT(*) FROM academic_advisors WHERE staff_id = s.staff_number) as advisor_count
                        FROM staff s WHERE s.staff_id IN ($placeholders)";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute($selected_ids);
                    $staff_check = $check_stmt->fetchAll();

                    $deletable = [];
                    $non_deletable = [];

                    foreach ($staff_check as $staff_member) {
                        if ($staff_member['course_count'] > 0 || $staff_member['advisor_count'] > 0) {
                            $non_deletable[] = $staff_member['staff_number'] . " (has " . 
                                ($staff_member['course_count'] > 0 ? "courses" : "advisors") . ")";
                        } else {
                            $deletable[] = $staff_member['staff_id'];
                        }
                    }

                    if (!empty($deletable)) {
                        $deletable_placeholders = implode(',', array_fill(0, count($deletable), '?'));
                        $pdo->prepare("DELETE FROM staff WHERE staff_id IN ($deletable_placeholders)")
                            ->execute($deletable);
                        $deleted_count = count($deletable);
                    } else {
                        $deleted_count = 0;
                    }

                    $message = "Deleted {$deleted_count} staff member(s).";
                    if (!empty($non_deletable)) {
                        $message .= " Could not delete: " . implode(', ', $non_deletable);
                    }

                    $_SESSION['success_message'] = $message;
                    break;
            }
        }

        header("Location: manage_staffs.php");
        exit();
    }
}

// ============================================
// GET STAFF DATA WITH COUNTS
// ============================================
$count_sql = "
    SELECT COUNT(*) as total 
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Main staff query with course and student counts
$sql = "
    SELECT 
        s.*,
        d.department_name,
        d.department_code,
        sr.role_name as staff_role_name,
        TIMESTAMPDIFF(YEAR, s.employment_date, CURDATE()) as years_of_service,
        (SELECT COUNT(*) FROM course_assignments ca 
         WHERE ca.staff_id = s.staff_id AND ca.status = 'Active' 
         AND ca.session_year = ?) as active_courses,
        (SELECT COUNT(DISTINCT cr.student_id) 
         FROM course_assignments ca2
         JOIN course_registrations cr ON ca2.course_id = cr.course_id 
             AND ca2.session_year = cr.session_year 
             AND ca2.semester = cr.semester
         WHERE ca2.staff_id = s.staff_id AND ca2.status = 'Active'
         AND cr.registration_status = 'Approved' AND ca2.session_year = ?) as total_students
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN staff_roles sr ON s.staff_role_id = sr.role_id
    {$where_clause}
    ORDER BY s.last_name, s.first_name
    LIMIT {$offset}, {$records_per_page}
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$current_session_year, $current_session_year], $params));
$staff = $stmt->fetchAll();

// ============================================
// GET STATS
// ============================================
$stats = [
    'total' => $total_records,
    'active' => $pdo->query("SELECT COUNT(*) FROM staff WHERE status = 'Active'")->fetchColumn(),
    'fulltime' => $pdo->query("SELECT COUNT(*) FROM staff WHERE employment_type = 'Full-time' AND status = 'Active'")->fetchColumn(),
    'avg_experience' => $pdo->query("SELECT AVG(TIMESTAMPDIFF(YEAR, employment_date, CURDATE())) FROM staff WHERE status = 'Active'")->fetchColumn()
];

// ============================================
// VIEW STAFF DETAILS
// ============================================
$view_staff_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$view_staff = null;
$view_staff_courses = [];
$view_staff_students = [];
$view_staff_activity = [];

if ($view_staff_id > 0) {
    $view_stmt = $pdo->prepare("
        SELECT s.*, d.department_name, d.department_code, sr.role_name, sr.permissions
        FROM staff s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN staff_roles sr ON s.staff_role_id = sr.role_id
        WHERE s.staff_id = ?
    ");
    $view_stmt->execute([$view_staff_id]);
    $view_staff = $view_stmt->fetch();

    if ($view_staff) {
        $courses_stmt = $pdo->prepare("
            SELECT ca.*, c.course_code, c.course_title, c.credit_units, c.level,
                   p.program_name, au.full_name as assigned_by_name
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.course_id
            LEFT JOIN programs p ON c.department_id = p.department_id
            LEFT JOIN admin_users au ON ca.assigned_by = au.admin_id
            WHERE ca.staff_id = ? AND ca.status = 'Active'
            ORDER BY ca.session_year DESC, ca.semester DESC
        ");
        $courses_stmt->execute([$view_staff_id]);
        $view_staff_courses = $courses_stmt->fetchAll();

        $students_stmt = $pdo->prepare("
            SELECT DISTINCT s.student_id, s.matric_number, s.first_name, s.last_name, 
                   s.email, s.phone, s.current_level, s.status,
                   c.course_code, c.course_title, cr.registration_status
            FROM course_assignments ca
            JOIN course_registrations cr ON ca.course_id = cr.course_id 
                AND ca.session_year = cr.session_year AND ca.semester = cr.semester
            JOIN students s ON cr.student_id = s.student_id
            JOIN courses c ON ca.course_id = c.course_id
            WHERE ca.staff_id = ? AND ca.status = 'Active' AND cr.registration_status = 'Approved'
            ORDER BY s.last_name, s.first_name
        ");
        $students_stmt->execute([$view_staff_id]);
        $view_staff_students = $students_stmt->fetchAll();

        $activity_stmt = $pdo->prepare("
            SELECT * FROM staff_activity_log 
            WHERE staff_id = ? 
            ORDER BY created_at DESC LIMIT 20
        ");
        $activity_stmt->execute([$view_staff_id]);
        $view_staff_activity = $activity_stmt->fetchAll();
    }
}

// ============================================
// EDIT STAFF
// ============================================
$edit_staff_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_staff = null;
if ($edit_staff_id > 0) {
    $edit_stmt = $pdo->prepare("
        SELECT s.*, d.department_name, sr.role_name
        FROM staff s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN staff_roles sr ON s.staff_role_id = sr.role_id
        WHERE s.staff_id = ?
    ");
    $edit_stmt->execute([$edit_staff_id]);
    $edit_staff = $edit_stmt->fetch();
}

// ============================================
// VIEW COURSES FOR ASSIGNMENT
// ============================================
$view_courses_staff_id = isset($_GET['view_courses']) ? (int)$_GET['view_courses'] : 0;
$view_courses_staff = null;
$staff_assigned_courses = [];

if ($view_courses_staff_id > 0) {
    $vcs_stmt = $pdo->prepare("
        SELECT s.*, d.department_name FROM staff s
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE s.staff_id = ?
    ");
    $vcs_stmt->execute([$view_courses_staff_id]);
    $view_courses_staff = $vcs_stmt->fetch();

    if ($view_courses_staff) {
        $sac_stmt = $pdo->prepare("
            SELECT ca.*, c.course_code, c.course_title, c.credit_units, c.level,
                   p.program_name, au.full_name as assigned_by_name
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.course_id
            LEFT JOIN programs p ON c.department_id = p.department_id
            LEFT JOIN admin_users au ON ca.assigned_by = au.admin_id
            WHERE ca.staff_id = ?
            ORDER BY ca.session_year DESC, ca.semester DESC, c.course_code
        ");
        $sac_stmt->execute([$view_courses_staff_id]);
        $staff_assigned_courses = $sac_stmt->fetchAll();
    }
}
?>

<!-- ============================================ -->
<!-- SUCCESS/ERROR MESSAGES -->
<!-- ============================================ -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<!-- ============================================ -->
<!-- PAGE HEADER -->
<!-- ============================================ -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-chalkboard-teacher me-2"></i>Staff Management
    </h1>
    <div class="btn-group">
        <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
            <i class="fas fa-plus-circle me-2"></i>Add Staff
        </button>
    </div>
</div>

<!-- ============================================ -->
<!-- STATS CARDS -->
<!-- ============================================ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Staff</div>
                <div class="stats-figure"><?php echo number_format($stats['total']); ?></div>
                <div class="stats-meta"><i class="fas fa-users text-primary"></i> All Staff</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Staff</div>
                <div class="stats-figure text-success"><?php echo number_format($stats['active']); ?></div>
                <div class="stats-meta text-success"><i class="fas fa-check-circle"></i> Currently Active</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="stats-type">Full-time</div>
                <div class="stats-figure text-warning"><?php echo number_format($stats['fulltime']); ?></div>
                <div class="stats-meta text-warning"><i class="fas fa-user-tie"></i> Full-time Staff</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="stats-type">Avg Experience</div>
                <div class="stats-figure text-info"><?php echo $stats['avg_experience'] ? number_format($stats['avg_experience'], 1) : '0.0'; ?></div>
                <div class="stats-meta text-info"><i class="fas fa-calendar-alt"></i> Years Average</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- FILTERS CARD -->
<!-- ============================================ -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title"><i class="fas fa-filter me-2"></i>Filters & Search</h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Name, email, designation..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="">All</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>" <?php echo ($department_id == $dept['department_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Role</label>
                <select class="form-select" name="role">
                    <option value="">All</option>
                    <?php foreach ($role_options as $r): ?>
                    <option value="<?php echo $r; ?>" <?php echo ($role_filter == $r) ? 'selected' : ''; ?>><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Employment Type</label>
                <select class="form-select" name="employment_type">
                    <option value="">All</option>
                    <?php foreach ($employment_types as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo ($employment_type == $type) ? 'selected' : ''; ?>><?php echo $type; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <?php foreach ($status_options as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo ($status == $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                    <a href="manage_staffs.php" class="btn btn-outline-secondary"><i class="fas fa-redo me-1"></i>Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- STAFF TABLE -->
<!-- ============================================ -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Staff Directory</h5>
                <div class="text-muted small">
                    Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - 
                    <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?> staff members
                </div>
            </div>
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_staff.php?format=excel<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-excel me-2 text-success"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_staff.php?format=pdf<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-pdf me-2 text-danger"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_staff.php?format=csv<?php echo !empty($_GET) ? '&' . http_build_query($_GET) : ''; ?>">
                            <i class="fas fa-file-csv me-2 text-primary"></i>CSV
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
                            <th class="cell">Staff Member</th>
                            <th class="cell">Department & Role</th>
                            <th class="cell">Employment</th>
                            <th class="cell">Courses / Students</th>
                            <th class="cell">Contact</th>
                            <th class="cell">Status</th>
                            <th class="cell text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($staff)): ?>
                            <?php foreach ($staff as $member): ?>
                            <tr>
                                <td class="cell">
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" type="checkbox" 
                                               name="selected_staff[]" value="<?php echo $member['staff_id']; ?>">
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <?php if ($member['profile_image']): ?>
                                            <img src="<?php echo htmlspecialchars($member['profile_image']); ?>" 
                                                 class="rounded-circle me-2" width="40" height="40" alt="">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" 
                                                 style="width:40px;height:40px;">
                                                <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></div>
                                            <div class="small text-muted">
                                                <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($member['staff_number']); ?>
                                            </div>
                                            <div class="small text-muted">
                                                <i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($member['designation']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($member['department_name']): ?>
                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($member['department_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($member['department_code']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                    <div class="small mt-1">
                                        <span class="badge bg-secondary"><?php echo $member['role']; ?></span>
                                        <?php if ($member['staff_role_name']): ?>
                                            <span class="badge bg-info"><?php echo $member['staff_role_name']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell">
                                    <span class="badge bg-<?php 
                                        echo $member['employment_type'] == 'Full-time' ? 'success' : 
                                            ($member['employment_type'] == 'Part-time' ? 'warning' : 
                                            ($member['employment_type'] == 'Contract' ? 'info' : 'secondary')); 
                                    ?>">
                                        <?php echo $member['employment_type']; ?>
                                    </span>
                                    <?php if ($member['employment_date']): ?>
                                        <div class="small text-muted mt-1">
                                            <i class="fas fa-calendar-day me-1"></i>
                                            <?php echo date('M d, Y', strtotime($member['employment_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($member['years_of_service']): ?>
                                        <div class="small text-muted">
                                            <i class="fas fa-calendar-alt me-1"></i><?php echo $member['years_of_service']; ?> years
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge bg-primary">
                                            <i class="fas fa-book me-1"></i><?php echo $member['active_courses']; ?> Courses
                                        </span>
                                        <span class="badge bg-success">
                                            <i class="fas fa-user-graduate me-1"></i><?php echo $member['total_students']; ?> Students
                                        </span>
                                    </div>
                                </td>
                                <td class="cell">
                                    <div class="small"><i class="fas fa-envelope me-1 text-muted"></i><?php echo htmlspecialchars($member['email']); ?></div>
                                    <?php if ($member['phone']): ?>
                                        <div class="small text-muted"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($member['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($member['office_location']): ?>
                                        <div class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($member['office_location']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Active' => 'success', 'Inactive' => 'secondary', 'On Leave' => 'warning',
                                        'Retired' => 'info', 'Terminated' => 'danger'
                                    ][$member['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo $member['status']; ?></span>
                                    <div class="small mt-1">
                                        <?php 
                                        $contract_class = [
                                            'Active' => 'success', 'Inactive' => 'secondary', 'On Leave' => 'warning',
                                            'Terminated' => 'danger', 'Expired' => 'dark'
                                        ][$member['contract_status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $contract_class; ?> bg-opacity-25 text-<?php echo $contract_class; ?> border border-<?php echo $contract_class; ?>">
                                            Contract: <?php echo $member['contract_status']; ?>
                                        </span>
                                    </div>
                                    <?php if ($member['gender']): ?>
                                    <div class="small mt-1">
                                        <?php 
                                        $gender_icon = $member['gender'] == 'Male' ? 'mars' : 'venus';
                                        $gender_color = $member['gender'] == 'Male' ? 'primary' : 'danger';
                                        ?>
                                        <i class="fas fa-<?php echo $gender_icon; ?> text-<?php echo $gender_color; ?>"></i>
                                        <?php echo $member['gender']; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="?view=<?php echo $member['staff_id']; ?>">
                                                <i class="fas fa-eye me-2 text-primary"></i>View Details
                                            </a></li>
                                            <li><a class="dropdown-item" href="?edit=<?php echo $member['staff_id']; ?>">
                                                <i class="fas fa-edit me-2 text-warning"></i>Edit Staff
                                            </a></li>
                                            <li><a class="dropdown-item" href="?view_courses=<?php echo $member['staff_id']; ?>">
                                                <i class="fas fa-book me-2 text-success"></i>Assign Courses
                                            </a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <?php 
                                                $check_stmt = $pdo->prepare("SELECT advisor_id FROM academic_advisors WHERE staff_id = ?");
                                                $check_stmt->execute([$member['staff_number']]);
                                                $is_advisor = $check_stmt->fetchColumn();
                                                ?> 
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $member['staff_id']; ?>)">
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
                                <td colspan="8" class="text-center py-5">
                                    <div class="py-3">
                                        <i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3"></i>
                                        <h5>No staff members found</h5>
                                        <p class="text-muted">No staff members match your search criteria.</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                                            <i class="fas fa-plus-circle me-1"></i>Add New Staff
                                        </button>
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
                <div class="form-check d-inline-block">
                    <input class="form-check-input" type="checkbox" id="select-all-bottom">
                    <label class="form-check-label" for="select-all-bottom">Select All</label>
                </div>
                <div class="btn-group ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        Bulk Actions
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item text-success" href="#" onclick="submitBulkAction('activate')">
                            <i class="fas fa-check me-2"></i>Activate Selected
                        </a></li>
                        <li><a class="dropdown-item text-secondary" href="#" onclick="submitBulkAction('deactivate')">
                            <i class="fas fa-times me-2"></i>Deactivate Selected
                        </a></li>
                        <li><a class="dropdown-item text-warning" href="#" onclick="submitBulkAction('leave')">
                            <i class="fas fa-umbrella-beach me-2"></i>Mark as On Leave
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
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
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

<!-- ============================================ -->
<!-- VIEW STAFF DETAILS MODAL -->
<!-- ============================================ -->
<?php if ($view_staff): ?>
<div class="modal fade show" id="viewStaffModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i>Staff Details - <?php echo htmlspecialchars($view_staff['first_name'] . ' ' . $view_staff['last_name']); ?></h5>
                <a href="manage_staffs.php" class="btn-close btn-close-white"></a>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Profile Card -->
                    <div class="col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-body text-center">
                                <?php if ($view_staff['profile_image']): ?>
                                    <img src="<?php echo htmlspecialchars($view_staff['profile_image']); ?>" class="rounded-circle mb-3" width="120" height="120" alt="">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width:120px;height:120px;font-size:48px;">
                                        <?php echo strtoupper(substr($view_staff['first_name'], 0, 1) . substr($view_staff['last_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <h5><?php echo htmlspecialchars($view_staff['first_name'] . ' ' . $view_staff['last_name']); ?></h5>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars($view_staff['designation']); ?></p>
                                <span class="badge bg-<?php echo $view_staff['status'] == 'Active' ? 'success' : 'secondary'; ?> mb-2"><?php echo $view_staff['status']; ?></span>
                                <div class="small text-muted">
                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($view_staff['staff_number']); ?>
                                </div>
                                <hr>
                                <div class="d-flex flex-column gap-2 text-start">
                                    <div><i class="fas fa-envelope me-2 text-primary"></i><?php echo htmlspecialchars($view_staff['email']); ?></div>
                                    <?php if ($view_staff['phone']): ?>
                                    <div><i class="fas fa-phone me-2 text-primary"></i><?php echo htmlspecialchars($view_staff['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($view_staff['department_name']): ?>
                                    <div><i class="fas fa-building me-2 text-primary"></i><?php echo htmlspecialchars($view_staff['department_name']); ?></div>
                                    <?php endif; ?>
                                    <div><i class="fas fa-user-tag me-2 text-primary"></i><?php echo $view_staff['role']; ?></div>
                                    <?php if ($view_staff['role_name']): ?>
                                    <div><i class="fas fa-shield-alt me-2 text-primary"></i><?php echo $view_staff['role_name']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Details Tabs -->
                    <div class="col-md-8">
                        <ul class="nav nav-tabs" id="staffTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button">Personal Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="courses-tab" data-bs-toggle="tab" data-bs-target="#courses" type="button">
                                    Courses <span class="badge bg-primary ms-1"><?php echo count($view_staff_courses); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button">
                                    Students <span class="badge bg-success ms-1"><?php echo count($view_staff_students); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button">Activity Log</button>
                            </li>
                        </ul>

                        <div class="tab-content p-3 border border-top-0 rounded-bottom" id="staffTabContent">
                            <!-- Personal Info Tab -->
                            <div class="tab-pane fade show active" id="info" role="tabpanel">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Full Name</label>
                                        <p><?php echo htmlspecialchars($view_staff['first_name'] . ' ' . ($view_staff['middle_name'] ? $view_staff['middle_name'] . ' ' : '') . $view_staff['last_name']); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Gender</label>
                                        <p><?php echo $view_staff['gender'] ?? 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Date of Birth</label>
                                        <p><?php echo $view_staff['date_of_birth'] ? date('F d, Y', strtotime($view_staff['date_of_birth'])) : 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Employment Date</label>
                                        <p><?php echo $view_staff['employment_date'] ? date('F d, Y', strtotime($view_staff['employment_date'])) : 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Qualification</label>
                                        <p><?php echo $view_staff['qualification'] ?: 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Specialization</label>
                                        <p><?php echo $view_staff['specialization'] ?: 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Office Location</label>
                                        <p><?php echo $view_staff['office_location'] ?: 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Office Hours</label>
                                        <p><?php echo $view_staff['office_hours'] ?: 'Not specified'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Contract Status</label>
                                        <p><span class="badge bg-<?php echo $view_staff['contract_status'] == 'Active' ? 'success' : 'danger'; ?>"><?php echo $view_staff['contract_status']; ?></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Can Login</label>
                                        <p><span class="badge bg-<?php echo $view_staff['can_login'] ? 'success' : 'secondary'; ?>"><?php echo $view_staff['can_login'] ? 'Yes' : 'No'; ?></span></p>
                                    </div>
                                    <?php if ($view_staff['contract_start']): ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Contract Start</label>
                                        <p><?php echo date('F d, Y', strtotime($view_staff['contract_start'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($view_staff['contract_end']): ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Contract End</label>
                                        <p><?php echo date('F d, Y', strtotime($view_staff['contract_end'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Notes</label>
                                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($view_staff['notes'] ?: 'No notes available')); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Courses Tab -->
                            <div class="tab-pane fade" id="courses" role="tabpanel">
                                <?php if (!empty($view_staff_courses)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Course</th><th>Session</th><th>Sem</th><th>Type</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($view_staff_courses as $course): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($course['course_title']); ?></div>
                                                </td>
                                                <td><?php echo $course['session_year']; ?></td>
                                                <td><?php echo $course['semester']; ?></td>
                                                <td><?php echo $course['is_primary_instructor'] ? '<span class="badge bg-primary">Primary</span>' : '<span class="badge bg-secondary">Assistant</span>'; ?></td>
                                                <td><span class="badge bg-<?php echo $course['status'] == 'Active' ? 'success' : 'secondary'; ?>"><?php echo $course['status']; ?></span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-book fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No courses assigned yet.</p>
                                    <a href="?view_courses=<?php echo $view_staff_id; ?>" class="btn btn-sm btn-primary">Assign Courses</a>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Students Tab -->
                            <div class="tab-pane fade" id="students" role="tabpanel">
                                <?php if (!empty($view_staff_students)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Student</th><th>Matric</th><th>Level</th><th>Course</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($view_staff_students as $student): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                                                <td><?php echo $student['current_level']; ?></td>
                                                <td><?php echo htmlspecialchars($student['course_code']); ?></td>
                                                <td><span class="badge bg-success"><?php echo $student['registration_status']; ?></span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-user-graduate fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No students assigned to courses yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Activity Log Tab -->
                            <div class="tab-pane fade" id="activity" role="tabpanel">
                                <?php if (!empty($view_staff_activity)): ?>
                                <div class="timeline">
                                    <?php foreach ($view_staff_activity as $activity): ?>
                                    <div class="d-flex mb-3">
                                        <div class="flex-shrink-0">
                                            <div class="bg-light rounded-circle p-2">
                                                <i class="fas fa-history text-primary"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="fw-bold"><?php echo htmlspecialchars($activity['activity_type']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($activity['description']); ?></div>
                                            <div class="small text-muted"><i class="fas fa-clock me-1"></i><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-history fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No activity recorded yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="manage_staffs.php" class="btn btn-secondary">Close</a>
                <a href="?edit=<?php echo $view_staff_id; ?>" class="btn btn-warning"><i class="fas fa-edit me-1"></i>Edit</a>
                <a href="?view_courses=<?php echo $view_staff_id; ?>" class="btn btn-success"><i class="fas fa-book me-1"></i>Assign Courses</a>
            </div>
        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<!-- ============================================ -->
<!-- EDIT STAFF MODAL -->
<!-- ============================================ -->
<?php if ($edit_staff): ?>
<div class="modal fade show" id="editStaffModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Staff - <?php echo htmlspecialchars($edit_staff['first_name'] . ' ' . $edit_staff['last_name']); ?></h5>
                <a href="manage_staffs.php" class="btn-close"></a>
            </div>
            <div class="modal-body">
                <form method="POST" id="editStaffForm">
                    <input type="hidden" name="staff_id" value="<?php echo $edit_staff['staff_id']; ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff Number *</label>
                            <input type="text" class="form-control" name="staff_number" required 
                                   value="<?php echo htmlspecialchars($edit_staff['staff_number']); ?>" maxlength="20">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required 
                                   value="<?php echo htmlspecialchars($edit_staff['email']); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required 
                                   value="<?php echo htmlspecialchars($edit_staff['first_name']); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" 
                                   value="<?php echo htmlspecialchars($edit_staff['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required 
                                   value="<?php echo htmlspecialchars($edit_staff['last_name']); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone" 
                                   value="<?php echo htmlspecialchars($edit_staff['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select</option>
                                <option value="Male" <?php echo ($edit_staff['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($edit_staff['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" 
                                   value="<?php echo $edit_staff['date_of_birth']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">Select</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>" 
                                    <?php echo ($edit_staff['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Designation *</label>
                            <input type="text" class="form-control" name="designation" required 
                                   value="<?php echo htmlspecialchars($edit_staff['designation'] ?? ''); ?>"
                                   placeholder="e.g., Senior Lecturer">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff Role</label>
                            <select class="form-select" name="staff_role_id">
                                <option value="">Select Role</option>
                                <?php foreach ($staff_roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>" 
                                    <?php echo ($edit_staff['staff_role_id'] == $role['role_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">System Role *</label>
                            <select class="form-select" name="role" required>
                                <?php foreach ($role_options as $r): ?>
                                <option value="<?php echo $r; ?>" <?php echo ($edit_staff['role'] == $r) ? 'selected' : ''; ?>><?php echo $r; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" required>
                                <?php foreach ($employment_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo ($edit_staff['employment_type'] == $type) ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Date</label>
                            <input type="date" class="form-control" name="employment_date" 
                                   value="<?php echo $edit_staff['employment_date']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <?php foreach ($status_options as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo ($edit_staff['status'] == $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Status</label>
                            <select class="form-select" name="contract_status">
                                <option value="Active" <?php echo ($edit_staff['contract_status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($edit_staff['contract_status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="On Leave" <?php echo ($edit_staff['contract_status'] == 'On Leave') ? 'selected' : ''; ?>>On Leave</option>
                                <option value="Terminated" <?php echo ($edit_staff['contract_status'] == 'Terminated') ? 'selected' : ''; ?>>Terminated</option>
                                <option value="Expired" <?php echo ($edit_staff['contract_status'] == 'Expired') ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Start</label>
                            <input type="date" class="form-control" name="contract_start" 
                                   value="<?php echo $edit_staff['contract_start']; ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract End</label>
                            <input type="date" class="form-control" name="contract_end" 
                                   value="<?php echo $edit_staff['contract_end']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Qualification</label>
                            <input type="text" class="form-control" name="qualification" 
                                   value="<?php echo htmlspecialchars($edit_staff['qualification'] ?? ''); ?>"
                                   placeholder="e.g., Ph.D. in Computer Science">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Specialization</label>
                            <input type="text" class="form-control" name="specialization" 
                                   value="<?php echo htmlspecialchars($edit_staff['specialization'] ?? ''); ?>"
                                   placeholder="e.g., Artificial Intelligence">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Location</label>
                            <input type="text" class="form-control" name="office_location" 
                                   value="<?php echo htmlspecialchars($edit_staff['office_location'] ?? ''); ?>"
                                   placeholder="e.g., Faculty Building, Room 101">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Hours</label>
                            <input type="text" class="form-control" name="office_hours" 
                                   value="<?php echo htmlspecialchars($edit_staff['office_hours'] ?? ''); ?>"
                                   placeholder="e.g., Mon-Fri, 9am-5pm">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Username</label>
                            <input type="text" class="form-control" name="login_username" 
                                   value="<?php echo htmlspecialchars($edit_staff['login_username'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control"   name="login_password" 
                                   placeholder="Enter new password if changing">
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="can_login" id="edit_can_login" 
                                    <?php echo $edit_staff['can_login'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_can_login">Allow Login Access</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars($edit_staff['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <a href="manage_staffs.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" form="editStaffForm" name="edit_staff" class="btn btn-warning">
                    <i class="fas fa-save me-2"></i>Update Staff
                </button>
            </div>
        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<!-- ============================================ -->
<!-- ASSIGN COURSES MODAL -->
<!-- ============================================ -->
<?php if ($view_courses_staff): ?>
<div class="modal fade show" id="assignCoursesModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-book me-2"></i>Manage Courses - <?php echo htmlspecialchars($view_courses_staff['first_name'] . ' ' . $view_courses_staff['last_name']); ?>
                </h5>
                <a href="manage_staffs.php" class="btn-close btn-close-white"></a>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Current Assignments -->
                    <div class="col-md-7">
                        <h6 class="mb-3"><i class="fas fa-list me-2"></i>Currently Assigned Courses</h6>
                        <?php if (!empty($staff_assigned_courses)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course</th>
                                        <th>Session</th>
                                        <th>Sem</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($staff_assigned_courses as $course): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($course['course_title']); ?></div>
                                        </td>
                                        <td><?php echo $course['session_year']; ?></td>
                                        <td><?php echo $course['semester']; ?></td>
                                        <td><?php echo $course['is_primary_instructor'] ? '<span class="badge bg-primary">Primary</span>' : '<span class="badge bg-secondary">Asst</span>'; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $course['status'] == 'Active' ? 'success' : ($course['status'] == 'Completed' ? 'info' : 'secondary'); ?>">
                                                <?php echo $course['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($course['status'] == 'Active'): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this course assignment?');">
                                                <input type="hidden" name="assignment_id" value="<?php echo $course['assignment_id']; ?>">
                                                <input type="hidden" name="staff_id" value="<?php echo $view_courses_staff_id; ?>">
                                                <button type="submit" name="remove_course" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No courses assigned yet. Use the form to assign courses.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Assign New Course -->
                    <div class="col-md-5">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <i class="fas fa-plus-circle me-2"></i>Assign New Course
                            </div>
                            <div class="card-body">
                                <form method="POST" id="assignCourseForm">
                                    <input type="hidden" name="staff_id" value="<?php echo $view_courses_staff_id; ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Select Course *</label>
                                        <select class="form-select" name="course_id" required>
                                            <option value="">Choose Course...</option>
                                            <?php foreach ($courses as $course): ?>
                                            <option value="<?php echo $course['course_id']; ?>">
                                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title'] . ' (' . $course['credit_units'] . ' units)'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Academic Session *</label>
                                        <select class="form-select" name="session_year" required>
                                            <option value="2025/2026">2025/2026</option>
                                            <?php 
                                            $current_year = date('Y');
                                            for ($i = 0; $i < 3; $i++): 
                                                $year = ($current_year + $i) . '/' . ($current_year + $i + 1);
                                            ?>
                                            <option value="<?php echo $year; ?>" <?php echo ($year == $current_session_year) ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Semester *</label>
                                        <select class="form-select" name="semester" required>
                                            <option value="1" <?php echo ($current_semester == 1) ? 'selected' : ''; ?>>First Semester</option>
                                            <option value="2" <?php echo ($current_semester == 2) ? 'selected' : ''; ?>>Second Semester</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Level</label>
                                        <select class="form-select" name="level">
                                            <option value="">Select Level</option>
                                            <option value="100">100 Level</option>
                                            <option value="200">200 Level</option>
                                            <option value="300">300 Level</option>
                                            <option value="400">400 Level</option>
                                            <option value="500">500 Level</option>
                                            <option value="600">600 Level</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Program</label>
                                        <select class="form-select" name="program_id">
                                            <option value="">Select Program</option>
                                            <?php foreach ($programs as $prog): ?>
                                            <option value="<?php echo $prog['program_id']; ?>">
                                                <?php echo htmlspecialchars($prog['program_name'] . ' (' . $prog['program_code'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="is_primary_instructor" id="primary_instructor" checked>
                                            <label class="form-check-label" for="primary_instructor">Primary Instructor</label>
                                        </div>
                                    </div>

                                    <button type="submit" name="assign_course" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-2"></i>Assign Course
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="manage_staffs.php" class="btn btn-secondary">Close</a>
                <a href="?view=<?php echo $view_courses_staff_id; ?>" class="btn btn-primary">
                    <i class="fas fa-eye me-1"></i>View Staff Details
                </a>
            </div>
        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<!-- ============================================ -->
<!-- ADD STAFF MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Staff Member</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addStaffForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff Number *</label>
                            <input type="text" class="form-control" name="staff_number" required 
                                   placeholder="e.g., STF001" maxlength="20">
                            <div class="form-text">Unique staff identification number</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required 
                                   placeholder="e.g., staff@school.edu">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required placeholder="e.g., John">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" placeholder="e.g., Michael">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required placeholder="e.g., Doe">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone" placeholder="e.g., 08012345678">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Designation *</label>
                            <input type="text" class="form-control" name="designation" required 
                                   placeholder="e.g., Senior Lecturer">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Staff Role</label>
                            <select class="form-select" name="staff_role_id">
                                <option value="">Select Role</option>
                                <?php foreach ($staff_roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">System Role *</label>
                            <select class="form-select" name="role" required>
                                <?php foreach ($role_options as $r): ?>
                                <option value="<?php echo $r; ?>" <?php echo ($r == 'Lecturer') ? 'selected' : ''; ?>><?php echo $r; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" required>
                                <option value="Full-time" selected>Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Visiting">Visiting</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employment Date</label>
                            <input type="date" class="form-control" name="employment_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                                <option value="Retired">Retired</option>
                                <option value="Terminated">Terminated</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Status</label>
                            <select class="form-select" name="contract_status">
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="On Leave">On Leave</option>
                                <option value="Terminated">Terminated</option>
                                <option value="Expired">Expired</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract Start</label>
                            <input type="date" class="form-control" name="contract_start">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contract End</label>
                            <input type="date" class="form-control" name="contract_end">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Qualification</label>
                            <input type="text" class="form-control" name="qualification" 
                                   placeholder="e.g., Ph.D. in Computer Science">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Specialization</label>
                            <input type="text" class="form-control" name="specialization" 
                                   placeholder="e.g., Artificial Intelligence">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Location</label>
                            <input type="text" class="form-control" name="office_location" 
                                   placeholder="e.g., Faculty Building, Room 101">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Office Hours</label>
                            <input type="text" class="form-control" name="office_hours" 
                                   placeholder="e.g., Mon-Fri, 9am-5pm">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Username</label>
                            <input type="text" class="form-control" name="login_username" 
                                   placeholder="e.g., johndoe">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Password</label>
                            <input type="password"  class="form-control" name="login_password" 
                                   placeholder="Min 6 characters">
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="can_login" id="can_login" value="1">
                                <label class="form-check-label" for="can_login">Allow Login Access</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" 
                                      placeholder="Additional notes about this staff member"></textarea>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Important Notes:</h6>
                        <ul class="mb-0 small">
                            <li>Staff number and email must be unique</li>
                            <li>Staff can later be assigned as academic advisors</li>
                            <li>Profile image can be added after creation</li>
                            <li>All required fields are marked with *</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addStaffForm" name="add_staff" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Add Staff
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================ -->
<script>
// Select all checkboxes
document.getElementById('select-all')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    const bottomCheck = document.getElementById('select-all-bottom');
    if (bottomCheck) bottomCheck.checked = this.checked;
});

document.getElementById('select-all-bottom')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    const topCheck = document.getElementById('select-all');
    if (topCheck) topCheck.checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one staff member.');
        return false;
    }

    let confirmMessage = '';
    switch (action) {
        case 'activate': confirmMessage = `Activate ${selectedIds.length} selected staff member(s)?`; break;
        case 'deactivate': confirmMessage = `Deactivate ${selectedIds.length} selected staff member(s)?`; break;
        case 'leave': confirmMessage = `Mark ${selectedIds.length} selected staff member(s) as on leave?`; break;
        case 'delete': confirmMessage = `Delete ${selectedIds.length} selected staff member(s)? This action cannot be undone.`; break;
    }

    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.select-checkbox:checked')).map(cb => cb.value);
}

// Single delete
function confirmDelete(staffId) {
    if (confirm('Are you sure you want to delete this staff member? This action cannot be undone.')) {
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_staff[]';
        input.value = staffId;
        form.appendChild(input);
        document.getElementById('bulkActionInput').value = 'delete';
        form.submit();
    }
}

// Form validation
function validateStaffForm(form) {
    const required = ['staff_number', 'email', 'first_name', 'last_name', 'designation', 'employment_type', 'status'];
    for (let field of required) {
        const el = form.querySelector(`[name="${field}"]`);
        if (!el || !el.value.trim()) {
            alert('Please fill in all required fields.');
            el?.focus();
            return false;
        }
    }

    const email = form.querySelector('[name="email"]').value;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Please enter a valid email address.');
        return false;
    }

    const staffNum = form.querySelector('[name="staff_number"]').value;
    if (!/^[A-Za-z0-9\-]+$/.test(staffNum)) {
        alert('Staff number can only contain letters, numbers, and hyphens');
        return false;
    }

    return confirm('Are you sure you want to save this staff member?');
}

document.getElementById('addStaffForm')?.addEventListener('submit', function(e) {
    if (!validateStaffForm(this)) e.preventDefault();
});

document.getElementById('editStaffForm')?.addEventListener('submit', function(e) {
    if (!validateStaffForm(this)) e.preventDefault();
});
</script>

<?php
require_once 'includes/footer.php';
?>