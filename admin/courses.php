<?php
// courses.php
ob_start();

require_once 'includes/header.php';

$page_title = "Course Management";

function getExportQueryString() {
    $params = [];
    if (!empty($_GET['search'])) $params['search'] = $_GET['search'];
    if (!empty($_GET['faculty_id'])) $params['faculty_id'] = $_GET['faculty_id'];
    if (!empty($_GET['department_id'])) $params['department_id'] = $_GET['department_id'];
    if (!empty($_GET['program_id'])) $params['program_id'] = $_GET['program_id'];
    if (!empty($_GET['level'])) $params['level'] = $_GET['level'];
    if (!empty($_GET['semester'])) $params['semester'] = $_GET['semester'];
    if (!empty($_GET['course_type'])) $params['course_type'] = $_GET['course_type'];
    if (!empty($_GET['session_year'])) $params['session_year'] = $_GET['session_year'];
    return !empty($params) ? '&' . http_build_query($params) : '';
}

$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$session_year = isset($_GET['session_year']) ? trim($_GET['session_year']) : '';
$course_type = isset($_GET['course_type']) ? $_GET['course_type'] : '';

$conditions = [];
$query_params = [];

if (!empty($search)) {
    $conditions[] = "(c.course_code LIKE ? OR c.course_title LIKE ? OR c.course_description LIKE ?)";
    $search_term = "%{$search}%";
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}
if ($faculty_id > 0) { $conditions[] = "d.faculty_id = ?"; $query_params[] = $faculty_id; }
if ($department_id > 0) { $conditions[] = "c.department_id = ?"; $query_params[] = $department_id; }
if ($program_id > 0) { $conditions[] = "cp.program_id = ?"; $query_params[] = $program_id; }
if ($level > 0) { $conditions[] = "c.level = ?"; $query_params[] = $level; }
if ($semester !== '') { $conditions[] = "c.semester = ?"; $query_params[] = $semester; }
if (!empty($session_year)) { $conditions[] = "cso.session_year = ?"; $query_params[] = $session_year; }
if ($course_type !== '') {
    if ($course_type === 'core') $conditions[] = "c.is_core = 1";
    elseif ($course_type === 'elective') $conditions[] = "c.is_elective = 1";
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$count_sql = "SELECT COUNT(DISTINCT c.course_id) as total FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN course_programs cp ON c.course_id = cp.course_id
    LEFT JOIN course_session_offerings cso ON c.course_id = cso.course_id {$where_clause}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

$sql = "SELECT c.*, d.department_name, d.department_code, d.faculty_id, f.faculty_name, f.faculty_code as faculty_code,
    pre.course_code as prerequisite_code, pre.course_title as prerequisite_title,
    (SELECT COUNT(*) FROM course_registrations cr WHERE cr.course_id = c.course_id) as registration_count,
    (SELECT COUNT(*) FROM results r WHERE r.course_id = c.course_id AND r.is_published = 1) as result_count,
    (SELECT GROUP_CONCAT(DISTINCT p.program_name SEPARATOR ', ') FROM course_programs cp JOIN programs p ON cp.program_id = p.program_id WHERE cp.course_id = c.course_id) as program_names,
    (SELECT GROUP_CONCAT(DISTINCT cso.session_year SEPARATOR ', ') FROM course_session_offerings cso WHERE cso.course_id = c.course_id) as session_years
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
    LEFT JOIN course_programs cp ON c.course_id = cp.course_id
    LEFT JOIN course_session_offerings cso ON c.course_id = cso.course_id
    {$where_clause}
    GROUP BY c.course_id
    ORDER BY f.faculty_name, d.department_name, c.level, c.semester, c.course_code
    LIMIT {$offset}, {$records_per_page}";

$stmt = $pdo->prepare($sql);
$stmt->execute($query_params);
$courses = $stmt->fetchAll();

$faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'Active' ORDER BY faculty_name")->fetchAll();
$departments = $pdo->query("SELECT d.*, f.faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.faculty_id ORDER BY d.department_name")->fetchAll();
$programs = $pdo->query("SELECT p.*, d.department_name, f.faculty_name, f.faculty_id FROM programs p LEFT JOIN departments d ON p.department_id = d.department_id LEFT JOIN faculties f ON d.faculty_id = f.faculty_id WHERE p.is_active = 1 ORDER BY f.faculty_name, d.department_name, p.program_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$semester_options = ['1' => 'First Semester', '2' => 'Second Semester'];
$course_type_options = ['all' => 'All Courses', 'core' => 'Core Courses', 'elective' => 'Elective Courses'];

$academic_sessions = $pdo->query("SELECT session_year, semester, session_name, is_current, status FROM academic_sessions WHERE status IN ('Active', 'Completed') ORDER BY session_year DESC, semester DESC")->fetchAll();
$valid_elective_types = ['University', 'Faculty', 'Department'];

// ====== PROCESS IMPORT ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_courses'])) {

    $import_faculty_id = isset($_POST['import_faculty_id']) ? (int)$_POST['import_faculty_id'] : 0;
    $import_department_id = isset($_POST['import_department_id']) ? (int)$_POST['import_department_id'] : 0;
    $import_program_id = isset($_POST['import_program_id']) ? (int)$_POST['import_program_id'] : 0;
    $import_level = isset($_POST['import_level']) ? (int)$_POST['import_level'] : 0;
    $import_session_year = isset($_POST['import_session_year']) ? trim($_POST['import_session_year']) : '';
    $import_semester_raw = isset($_POST['import_semester']) ? $_POST['import_semester'] : '';
    $import_semester = (int)$import_semester_raw;

    if ($import_faculty_id == 0 || $import_department_id == 0 || $import_program_id == 0 || $import_level == 0) {
        $_SESSION['error_message'] = "Please select Faculty, Department, Program, and Level before importing.";
        header("Location: courses.php"); exit();
    }

    if (empty($import_session_year)) {
        $_SESSION['error_message'] = "Please select an Academic Session before importing. Session is required.";
        header("Location: courses.php"); exit();
    }

    if ($import_semester_raw === '' || !in_array($import_semester, [1, 2])) {
        $_SESSION['error_message'] = "Please select a valid Semester (First or Second) before importing. Got value: [" . htmlspecialchars($import_semester_raw) . "]";
        header("Location: courses.php"); exit();
    }

    // Validate against academic_sessions table
    $session_check = $pdo->prepare("SELECT session_id, session_name, status, is_current FROM academic_sessions WHERE session_year = ? AND semester = ? AND status IN ('Active', 'Completed')");
    $session_check->execute([$import_session_year, $import_semester]);
    $session_data = $session_check->fetch();

    if (!$session_data) {
        $_SESSION['error_message'] = "The selected Session '{$import_session_year}' and Semester {$import_semester} combination does not exist or is not active/completed in the academic sessions table.";
        header("Location: courses.php"); exit();
    }

    $validated_session_id = $session_data['session_id'];

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = "Please upload a valid CSV file. Error code: " . ($_FILES['csv_file']['error'] ?? 'No file');
        header("Location: courses.php"); exit();
    }

    $file_tmp_path = $_FILES['csv_file']['tmp_name'];
    $file_name = $_FILES['csv_file']['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if ($file_ext !== 'csv') {
        $_SESSION['error_message'] = "Please upload a CSV file (.csv extension).";
        header("Location: courses.php"); exit();
    }

    $imported_count = 0;
    $updated_count = 0;
    $failed_count = 0;
    $warnings = [];
    $errors = [];

    if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
        $headers = fgetcsv($handle);
        if ($headers === FALSE) {
            $_SESSION['error_message'] = "CSV file is empty or invalid.";
            header("Location: courses.php"); exit();
        }

        $header_map = [];
        foreach ($headers as $index => $header) {
            $clean_header = strtolower(trim(preg_replace('/[-\x80-\xFF]/', '', $header)));
            if (!empty($clean_header)) $header_map[$clean_header] = $index;
        }

        $required_columns = ['course_code', 'course_title', 'credit_units'];
        $missing_columns = array_filter($required_columns, fn($col) => !isset($header_map[$col]));
        if (!empty($missing_columns)) {
            $_SESSION['error_message'] = "Missing required column(s): " . implode(', ', $missing_columns);
            fclose($handle);
            header("Location: courses.php"); exit();
        }

        $pdo->beginTransaction();
        $row_num = 1;

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_num++;
            try {
                $course_code = isset($data[$header_map['course_code']]) ? trim($data[$header_map['course_code']]) : '';
                $course_title = isset($data[$header_map['course_title']]) ? trim($data[$header_map['course_title']]) : '';
                $credit_units = isset($data[$header_map['credit_units']]) ? (int)trim($data[$header_map['credit_units']]) : 3;

                if (empty($course_code)) { $failed_count++; $errors[] = "Row $row_num: Course code is empty"; continue; }
                if (empty($course_title)) { $failed_count++; $errors[] = "Row $row_num: Course title is empty"; continue; }
                if ($credit_units < 1 || $credit_units > 6) { $failed_count++; $errors[] = "Row $row_num: Credit units must be between 1 and 6"; continue; }

                // CSV semester override
                $csv_semester = null;
                if (isset($header_map['semester']) && isset($data[$header_map['semester']])) {
                    $csv_raw = trim($data[$header_map['semester']]);
                    if ($csv_raw !== '' && in_array((int)$csv_raw, [1, 2])) $csv_semester = (int)$csv_raw;
                }
                $semester_val = ($csv_semester !== null) ? $csv_semester : $import_semester;

                $prerequisite_code = isset($header_map['prerequisite_code']) && isset($data[$header_map['prerequisite_code']]) ? trim($data[$header_map['prerequisite_code']]) : '';
                $is_core = isset($header_map['is_core']) && isset($data[$header_map['is_core']]) && trim($data[$header_map['is_core']]) !== '' ? (int)trim($data[$header_map['is_core']]) : 1;
                $is_elective = isset($header_map['is_elective']) && isset($data[$header_map['is_elective']]) && trim($data[$header_map['is_elective']]) !== '' ? (int)trim($data[$header_map['is_elective']]) : 0;

                $elective_type = null;
                if (isset($header_map['elective_type']) && isset($data[$header_map['elective_type']]) && trim($data[$header_map['elective_type']]) !== '') {
                    $raw = trim($data[$header_map['elective_type']]);
                    if (in_array($raw, $valid_elective_types)) $elective_type = $raw;
                    else $warnings[] = "Row $row_num: Invalid elective_type '$raw'. Using NULL.";
                }

                $course_description = isset($header_map['course_description']) && isset($data[$header_map['course_description']]) ? trim($data[$header_map['course_description']]) : '';

                // Prerequisite
                $prerequisite_course_id = null;
                if (!empty($prerequisite_code)) {
                    $pre_stmt = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ?");
                    $pre_stmt->execute([$prerequisite_code]);
                    $pre = $pre_stmt->fetch();
                    if ($pre) $prerequisite_course_id = $pre['course_id'];
                    else $warnings[] = "Row $row_num: Prerequisite '$prerequisite_code' not found.";
                }

                // Insert or Update
                $check = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ?");
                $check->execute([$course_code]);

                if ($check->rowCount() > 0) {
                    $pdo->prepare("UPDATE courses SET course_title=?, credit_units=?, department_id=?, level=?, semester=?, prerequisite_course_id=?, is_core=?, is_elective=?, elective_type=?, course_description=? WHERE course_code=?")
                        ->execute([$course_title, $credit_units, $import_department_id, $import_level, $semester_val, $prerequisite_course_id, $is_core, $is_elective, $elective_type, $course_description, $course_code]);
                    $updated_count++;
                } else {
                    $pdo->prepare("INSERT INTO courses (course_code, course_title, credit_units, department_id, level, semester, prerequisite_course_id, is_core, is_elective, elective_type, course_description, created_by, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())")
                        ->execute([$course_code, $course_title, $credit_units, $import_department_id, $import_level, $semester_val, $prerequisite_course_id, $is_core, $is_elective, $elective_type, $course_description, $_SESSION['admin_id'] ?? 1]);
                    $imported_count++;
                }

                // Get course_id
                $course_id = $pdo->query("SELECT course_id FROM courses WHERE course_code = " . $pdo->quote($course_code))->fetchColumn();

                // Link to program
                $check_link = $pdo->prepare("SELECT cp_id FROM course_programs WHERE course_id = ? AND program_id = ?");
                $check_link->execute([$course_id, $import_program_id]);
                if ($check_link->rowCount() == 0) {
                    $pdo->prepare("INSERT INTO course_programs (course_id, program_id) VALUES (?, ?)")->execute([$course_id, $import_program_id]);
                }

                // Link to session (course_session_offerings)
                $check_offering = $pdo->prepare("SELECT offering_id FROM course_session_offerings WHERE course_id = ? AND session_year = ? AND semester = ? AND program_id = ?");
                $check_offering->execute([$course_id, $import_session_year, $import_semester, $import_program_id]);
                if ($check_offering->rowCount() == 0) {
                    $pdo->prepare("INSERT INTO course_session_offerings (course_id, session_year, semester, program_id, level, session_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())")
                        ->execute([$course_id, $import_session_year, $import_semester, $import_program_id, $import_level, $validated_session_id]);
                }

            } catch (Exception $e) {
                $failed_count++;
                $errors[] = "Row $row_num: " . $e->getMessage();
            }
        }

        $pdo->commit();
        fclose($handle);

        $total_processed = $imported_count + $updated_count;
        if ($total_processed > 0) {
            $semester_text = $import_semester == 1 ? 'First' : 'Second';
            $message = "Import completed for <strong>Session: $import_session_year</strong> | <strong>Semester: $semester_text</strong>!";
            if ($imported_count > 0) $message .= " $imported_count new course(s) added.";
            if ($updated_count > 0) $message .= " $updated_count existing course(s) updated.";
            if ($failed_count > 0) $message .= " $failed_count row(s) failed.";
            $_SESSION['success_message'] = $message;
        } else {
            $_SESSION['error_message'] = "No courses imported for $import_session_year - Semester $import_semester. $failed_count rows failed.";
        }

        if (!empty($warnings)) $_SESSION['import_warnings'] = array_slice($warnings, 0, 20);
        if (!empty($errors)) $_SESSION['import_errors'] = array_slice($errors, 0, 20);

    } else {
        $_SESSION['error_message'] = "Unable to read CSV file.";
    }

    header("Location: courses.php");
    exit();
}

// ====== BULK ACTIONS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_courses'])) {
    $selected_ids = $_POST['selected_courses'];
    if (!empty($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        switch ($_POST['bulk_action']) {
            case 'delete':
                $deletable = [];
                foreach ($selected_ids as $cid) {
                    $check = $pdo->prepare("SELECT course_id, course_code, (SELECT COUNT(*) FROM course_registrations WHERE course_id = ?) as reg_count, (SELECT COUNT(*) FROM results WHERE course_id = ?) as res_count FROM courses WHERE course_id = ?");
                    $check->execute([$cid, $cid, $cid]);
                    $c = $check->fetch();
                    if ($c && $c['reg_count'] == 0 && $c['res_count'] == 0) $deletable[] = $cid;
                }
                if (!empty($deletable)) {
                    $ph = implode(',', array_fill(0, count($deletable), '?'));
                    $pdo->prepare("DELETE FROM course_session_offerings WHERE course_id IN ($ph)")->execute($deletable);
                    $pdo->prepare("DELETE FROM course_programs WHERE course_id IN ($ph)")->execute($deletable);
                    $pdo->prepare("DELETE FROM courses WHERE course_id IN ($ph)")->execute($deletable);
                    $_SESSION['success_message'] = "Deleted " . count($deletable) . " course(s).";
                } else {
                    $_SESSION['error_message'] = "No courses could be deleted (they have registrations or results).";
                }
                break;
        }
    }
    header("Location: courses.php");
    exit();
}

// Display messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['import_warnings'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <h6><i class="fas fa-exclamation-triangle me-2"></i>Import Warnings:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['import_warnings'] as $w): ?><li><?php echo htmlspecialchars($w); ?></li><?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['import_warnings']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['import_errors'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h6><i class="fas fa-times-circle me-2"></i>Import Errors:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['import_errors'] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Course Management</h1>
    <div class="app-actions">
        <a href="add_course.php" class="btn app-btn-primary"><i class="fas fa-plus-circle me-2"></i>Add New Course</a>
        <button class="btn app-btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fas fa-file-import me-2"></i>Import Courses</button>
    </div>
</div>

<!-- Filters -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title"><i class="fas fa-filter me-2"></i>Filters & Search</h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" class="form-control" name="search" placeholder="Course code, title..." value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty_id" id="faculty_filter">
                    <option value="0">All Faculties</option>
                    <?php foreach ($faculties as $f): ?>
                    <option value="<?php echo $f['faculty_id']; ?>" <?php echo ($faculty_id == $f['faculty_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['faculty_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id" id="department_filter">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo $d['department_id']; ?>" data-faculty="<?php echo $d['faculty_id'] ?? ''; ?>" <?php echo ($department_id == $d['department_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?php echo $p['program_id']; ?>" data-department="<?php echo $p['department_id']; ?>" data-faculty="<?php echo $p['faculty_id']; ?>" <?php echo ($program_id == $p['program_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="0">All</option>
                    <?php foreach ($levels as $l): ?><option value="<?php echo $l; ?>" <?php echo ($level == $l) ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester">
                    <option value="">All</option>
                    <?php foreach ($semester_options as $val => $label): ?><option value="<?php echo $val; ?>" <?php echo ($semester == $val) ? 'selected' : ''; ?>><?php echo substr($label, 0, 3); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i></button>
                    <a href="courses.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i></a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="app-card app-card-stat shadow-sm"><div class="app-card-body p-3"><div class="stats-type">Total Courses</div><div class="stats-figure"><?php echo number_format($total_records); ?></div><div class="stats-meta"><i class="fas fa-book text-primary"></i> All Courses</div></div></div></div>
    <div class="col-6 col-md-3"><div class="app-card app-card-stat shadow-sm"><div class="app-card-body p-3"><div class="stats-type">Core Courses</div><div class="stats-figure"><?php echo number_format($pdo->query("SELECT COUNT(*) FROM courses WHERE is_core = 1")->fetchColumn()); ?></div><div class="stats-meta text-info"><i class="fas fa-star"></i> Mandatory</div></div></div></div>
    <div class="col-6 col-md-3"><div class="app-card app-card-stat shadow-sm"><div class="app-card-body p-3"><div class="stats-type">Elective Courses</div><div class="stats-figure"><?php echo number_format($pdo->query("SELECT COUNT(*) FROM courses WHERE is_elective = 1")->fetchColumn()); ?></div><div class="stats-meta text-warning"><i class="fas fa-asterisk"></i> Optional</div></div></div></div>
    <div class="col-6 col-md-3"><div class="app-card app-card-stat shadow-sm"><div class="app-card-body p-3"><div class="stats-type">Active Registrations</div><div class="stats-figure"><?php $cs = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn(); echo number_format($cs ? $pdo->query("SELECT COUNT(DISTINCT course_id) FROM course_registrations WHERE session_year = '$cs'")->fetchColumn() : 0); ?></div><div class="stats-meta text-success"><i class="fas fa-users"></i> Current Session</div></div></div></div>
</div>

<!-- Courses Table -->
<div class="app-card app-card-table shadow-sm">
    <div class="app-card-header p-3">
        <div class="row justify-content-between align-items-center">
            <div class="col-auto">
                <h5 class="app-card-title">Courses List</h5>
                <div class="text-muted small">Showing <?php echo number_format(min($offset + 1, $total_records)); ?> - <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> of <?php echo number_format($total_records); ?> courses</div>
            </div>
            <div class="col-auto">
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-download me-1"></i>Export</button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_courses.php?format=excel<?php echo getExportQueryString(); ?>"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                        <li><a class="dropdown-item" href="export_courses.php?format=pdf<?php echo getExportQueryString(); ?>"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                        <li><a class="dropdown-item" href="export_courses.php?format=csv<?php echo getExportQueryString(); ?>"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
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
                            <th width="30"><div class="form-check"><input class="form-check-input" type="checkbox" id="select-all"></div></th>
                            <th>Course Code</th><th>Course Title</th><th>Faculty</th><th>Department</th><th>Program(s)</th><th>Level/Sem</th><th>Units</th><th>Type</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($courses)): foreach ($courses as $c): ?>
                        <tr>
                            <td><div class="form-check"><input class="form-check-input select-checkbox" type="checkbox" name="selected_courses[]" value="<?php echo $c['course_id']; ?>"></div></td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($c['course_code']); ?></strong></td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($c['course_title']); ?></div>
                                <?php if (!empty($c['course_description'])): ?><small class="text-muted"><?php echo htmlspecialchars(substr($c['course_description'], 0, 50)); ?>...</small><?php endif; ?>
                            </td>
                            <td><span class="badge bg-info"><?php echo htmlspecialchars($c['faculty_code'] ?? 'N/A'); ?></span><br><small><?php echo htmlspecialchars(substr($c['faculty_name'] ?? '', 0, 15)); ?></small></td>
                            <td><?php echo htmlspecialchars($c['department_code'] ?? 'N/A'); ?><br><small class="text-muted"><?php echo htmlspecialchars(substr($c['department_name'] ?? '', 0, 12)); ?></small></td>
                            <td><?php if (!empty($c['program_names'])): ?><span class="badge bg-secondary" title="<?php echo htmlspecialchars($c['program_names']); ?>"><?php echo substr($c['program_names'], 0, 30) . (strlen($c['program_names']) > 30 ? '...' : ''); ?></span><?php else: ?><span class="text-muted">Not assigned</span><?php endif; ?></td>
                            <td><span class="badge bg-info mb-1"><?php echo $c['level'] ?? 'N/A'; ?> Level</span><?php if ($c['semester']): ?><br><span class="badge bg-secondary">Sem <?php echo $c['semester']; ?></span><?php endif; ?></td>
                            <td><span class="badge bg-warning"><?php echo $c['credit_units']; ?> Units</span></td>
                            <td><?php if ($c['is_core']): ?><span class="badge bg-primary">Core</span><?php elseif ($c['is_elective']): ?><span class="badge bg-success">Elective</span><?php if ($c['elective_type']) echo '<br><small>' . $c['elective_type'] . '</small>'; ?><?php else: ?><span class="badge bg-secondary">General</span><?php endif; ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="view_course.php?id=<?php echo $c['course_id']; ?>"><i class="fas fa-eye me-2"></i>View</a></li>
                                        <li><a class="dropdown-item" href="edit_course.php?id=<?php echo $c['course_id']; ?>"><i class="fas fa-edit me-2"></i>Edit</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?php echo $c['course_id']; ?>)"><i class="fas fa-trash me-2"></i>Delete</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="10" class="text-center py-4"><i class="fas fa-book fa-3x text-muted mb-3"></i><h5>No courses found</h5><p class="text-muted">No courses match your criteria.</p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="form-check d-inline-block">
                    <input class="form-check-input" type="checkbox" id="select-all-bottom">
                    <label class="form-check-label" for="select-all-bottom">Select All</label>
                </div>
                <div class="btn-group ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">Bulk Actions</button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('delete')"><i class="fas fa-trash me-2"></i>Delete Selected</a></li>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>"><i class="fas fa-chevron-left"></i></a></li>
                        <?php $start = max(1, $current_page - 2); $end = min($total_pages, $start + 4); if ($start > 1): ?><li class="page-item"><span class="page-link">...</span></li><?php endif; ?>
                        <?php for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a></li><?php endfor; ?>
                        <?php if ($end < $total_pages): ?><li class="page-item"><span class="page-link">...</span></li><?php endif; ?>
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>"><i class="fas fa-chevron-right"></i></a></li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Import Courses from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <!-- FIX: Hidden input ensures import_courses is always in POST data -->
                    <input type="hidden" name="import_courses" value="1">

                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Faculty <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_faculty_id" id="import_faculty_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($faculties as $f): ?>
                                <option value="<?php echo $f['faculty_id']; ?>"><?php echo htmlspecialchars($f['faculty_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_department_id" id="import_department_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['department_id']; ?>" data-faculty="<?php echo $d['faculty_id'] ?? ''; ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Program <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_program_id" id="import_program_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($programs as $p): ?>
                                <option value="<?php echo $p['program_id']; ?>" data-department="<?php echo $p['department_id']; ?>"><?php echo htmlspecialchars($p['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Level <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_level" id="import_level" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($levels as $l): ?><option value="<?php echo $l; ?>"><?php echo $l; ?> Level</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_session_year" id="import_session_year" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($academic_sessions as $s): ?>
                                <option value="<?php echo htmlspecialchars($s['session_year']); ?>"><?php echo htmlspecialchars($s['session_year']); ?><?php if ($s['is_current']) echo ' (Current)'; ?><?php if ($s['status'] == 'Completed') echo ' (Completed)'; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">From academic_sessions</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="import_semester" id="import_semester" required>
                                <option value="">-- Select --</option>
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Upload CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" name="csv_file" id="csv_file" accept=".csv" required>
                            <div class="form-text mt-2">
                                <a href="download_course_template.php" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fas fa-download me-1"></i>Download CSV Template</a>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1 fw-bold">Required:</p>
                                <ul class="small mb-2"><li><strong>course_code</strong>, <strong>course_title</strong>, <strong>credit_units</strong></li></ul>
                                <p class="mb-1 fw-bold text-success">Auto-Applied:</p>
                                <ul class="small text-success mb-0"><li>Faculty, Dept, Program, Level, Session, Semester</li></ul>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1 fw-bold">Optional:</p>
                                <ul class="small mb-0">
                                    <li><strong>semester</strong> - 1 or 2 (overrides form)</li>
                                    <li><strong>prerequisite_code</strong></li>
                                    <li><strong>is_core</strong>, <strong>is_elective</strong></li>
                                    <li><strong>elective_type</strong> - University/Faculty/Department</li>
                                    <li><strong>course_description</strong></li>
                                </ul>
                            </div>
                        </div>
                        <div class="alert alert-warning small mb-0 mt-2">
                            <strong>Note:</strong> Session & Semester validated against <code>academic_sessions</code> table. Existing courses will be updated, new courses added.
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <!-- FIX: Button type="button" with JS trigger to avoid form attribute issues -->
                <button type="button" class="btn btn-primary" id="btnImportSubmit">
                    <i class="fas fa-upload me-2"></i>Upload & Import
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Department/Program filtering
document.getElementById('import_faculty_id').addEventListener('change', function() {
    const fid = this.value;
    const dsel = document.getElementById('import_department_id');
    const psel = document.getElementById('import_program_id');
    dsel.querySelectorAll('option').forEach(opt => {
        if (opt.value === '') { opt.style.display = ''; return; }
        opt.style.display = (!fid || opt.dataset.faculty == fid) ? '' : 'none';
        if (opt.style.display === 'none' && opt.selected) opt.selected = false;
    });
    dsel.value = '';
    psel.innerHTML = '<option value="">-- Select Program --</option>';
});

document.getElementById('import_department_id').addEventListener('change', function() {
    const did = this.value;
    const psel = document.getElementById('import_program_id');
    const allPrograms = <?php echo json_encode($programs); ?>;
    const filtered = allPrograms.filter(p => p.department_id == did);
    psel.innerHTML = '<option value="">-- Select Program --</option>';
    if (filtered.length) {
        filtered.forEach(p => { const o = document.createElement('option'); o.value = p.program_id; o.text = p.program_name; psel.appendChild(o); });
    } else {
        psel.innerHTML = '<option value="">-- No programs --</option>';
    }
});

// Main filter department/program
document.getElementById('faculty_filter')?.addEventListener('change', function() {
    const fid = this.value;
    const dsel = document.getElementById('department_filter');
    const psel = document.querySelector('select[name="program_id"]');
    dsel.querySelectorAll('option').forEach(opt => {
        if (opt.value === '0') { opt.style.display = ''; return; }
        opt.style.display = (fid === '0' || opt.dataset.faculty == fid) ? '' : 'none';
    });
    if (dsel.value !== '0' && dsel.querySelector(`option[value="${dsel.value}"]`)?.style.display === 'none') dsel.value = '0';
    if (psel) {
        psel.querySelectorAll('option').forEach(opt => {
            if (opt.value === '0') { opt.style.display = ''; return; }
            opt.style.display = (fid === '0' || opt.dataset.faculty == fid) ? '' : 'none';
        });
        if (psel.value !== '0' && psel.querySelector(`option[value="${psel.value}"]`)?.style.display === 'none') psel.value = '0';
    }
});

// Select all
const sat = document.getElementById('select-all');
const sab = document.getElementById('select-all-bottom');
if (sat) sat.addEventListener('change', function() { document.querySelectorAll('.select-checkbox').forEach(cb => cb.checked = this.checked); if (sab) sab.checked = this.checked; });
if (sab) sab.addEventListener('change', function() { document.querySelectorAll('.select-checkbox').forEach(cb => cb.checked = this.checked); if (sat) sat.checked = this.checked; });

// Bulk actions
function getSelectedIds() { return Array.from(document.querySelectorAll('.select-checkbox:checked')).map(cb => cb.value); }
function submitBulkAction(action) {
    const ids = getSelectedIds();
    if (!ids.length) { alert('Please select at least one course.'); return; }
    if (!confirm(`Delete ${ids.length} selected course(s)? This cannot be undone.`)) return;
    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkForm').submit();
}
function confirmDelete(id) {
    if (!confirm('Delete this course? This cannot be undone.')) return;
    const form = document.getElementById('bulkForm');
    const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'selected_courses[]'; inp.value = id;
    form.appendChild(inp);
    document.getElementById('bulkActionInput').value = 'delete';
    form.submit();
}

// FIX: Import submission - button outside form, trigger submit via JS
document.getElementById('btnImportSubmit').addEventListener('click', function() {
    const form = document.getElementById('importForm');

    // Validation
    const facultyId = document.getElementById('import_faculty_id').value;
    const departmentId = document.getElementById('import_department_id').value;
    const programId = document.getElementById('import_program_id').value;
    const level = document.getElementById('import_level').value;
    const sessionYear = document.getElementById('import_session_year').value;
    const semester = document.getElementById('import_semester').value;
    const fileInput = document.getElementById('csv_file');

    if (!facultyId) { alert('Please select a faculty.'); return; }
    if (!departmentId) { alert('Please select a department.'); return; }
    if (!programId) { alert('Please select a program.'); return; }
    if (!level) { alert('Please select a level.'); return; }
    if (!sessionYear) { alert('Please select an Academic Session.'); return; }
    if (!semester || (semester !== '1' && semester !== '2')) { alert('Please select a valid Semester (First or Second).'); return; }
    if (!fileInput.files.length) { alert('Please select a CSV file.'); return; }

    const semText = semester === '1' ? 'First Semester' : 'Second Semester';
    if (!confirm(`Import courses for Session: ${sessionYear} - ${semText}?`)) return;

    // Disable button and submit
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    form.submit();
});

// File validation
document.getElementById('csv_file')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert('File exceeds 10MB.'); this.value = ''; }
    else if (!file.name.toLowerCase().endsWith('.csv')) { alert('Please select a CSV file.'); this.value = ''; }
});
</script>

<?php require_once 'includes/footer.php'; ?>