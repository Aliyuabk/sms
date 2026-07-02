<?php
// export_registrations.php
// Export course registrations to CSV/Excel format

ob_start();
session_start();

// Database connection
$host = '127.0.0.1';
$dbname = 'student_portal_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check if admin is logged in
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get filter parameters (same as course_registrations.php)
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$session_year = isset($_GET['session_year']) ? trim($_GET['session_year']) : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

// Build query conditions
$conditions = [];
$params = [];

if ($course_id > 0) {
    $conditions[] = "c.course_id = ?";
    $params[] = $course_id;
}

if ($student_id > 0) {
    $conditions[] = "s.student_id = ?";
    $params[] = $student_id;
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

if (!empty($session_year)) {
    $conditions[] = "cr.session_year = ?";
    $params[] = $session_year;
}

if ($semester > 0) {
    $conditions[] = "cr.semester = ?";
    $params[] = $semester;
}

if (!empty($status)) {
    $conditions[] = "cr.registration_status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR c.course_code LIKE ? OR c.course_title LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get export data
$sql = "
    SELECT 
        cr.registration_id,
        cr.session_year,
        cr.semester,
        cr.level as registered_level,
        cr.registration_date,
        cr.approval_date,
        cr.registration_status,
        cr.remarks,
        
        -- Student Information
        s.matric_number,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email as student_email,
        s.phone as student_phone,
        s.current_level,
        s.admission_year,
        s.status as student_status,
        
        -- Department Information
        d.department_code,
        d.department_name,
        
        -- Program Information
        p.program_code,
        p.program_name,
        p.degree_type,
        
        -- Course Information
        c.course_code,
        c.course_title,
        c.credit_units,
        c.level as course_level,
        c.semester as course_semester,
        c.is_core,
        c.is_elective,
        
        -- Result Information (if available)
        r.grade as final_grade,
        r.total_score as final_score,
        r.grade_points,
        r.is_published as result_published,
        
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
        
    FROM course_registrations cr
    INNER JOIN courses c ON cr.course_id = c.course_id
    INNER JOIN students s ON cr.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN results r ON r.student_id = cr.student_id 
        AND r.course_id = cr.course_id 
        AND r.session_year = cr.session_year 
        AND r.semester = cr.semester
    {$where_clause}
    ORDER BY cr.session_year DESC, cr.semester DESC, s.matric_number ASC, c.course_code ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Export query error: " . $e->getMessage());
    $registrations = [];
}

// If no data, show error and redirect
if (empty($registrations)) {
    $_SESSION['error_message'] = "No registration data found to export.";
    header('Location: course_registrations.php');
    exit();
}

// Helper function to clean CSV field
function cleanCsvField($field) {
    if ($field === null) return '';
    // Remove any line breaks and extra spaces
    $field = str_replace(["\r\n", "\n", "\r"], ' ', $field);
    $field = preg_replace('/\s+/', ' ', $field);
    return trim($field);
}

// Generate filename
$timestamp = date('Y-m-d_H-i-s');
$filename = "course_registrations_export_{$timestamp}";

// Prepare data rows
$headers = [
    'Registration ID',
    'Session Year',
    'Semester',
    'Registration Date',
    'Approval Date',
    'Status',
    
    // Student Info
    'Matric Number',
    'Student Name',
    'First Name',
    'Last Name',
    'Student Email',
    'Student Phone',
    'Current Level',
    'Student Status',
    
    // Program Info
    'Program Code',
    'Program Name',
    'Degree Type',
    'Department Code',
    'Department Name',
    
    // Course Info
    'Course Code',
    'Course Title',
    'Credit Units',
    'Course Level',
    'Course Semester',
    'Course Type',
    
    // Result Info
    'Final Grade',
    'Final Score (%)',
    'Grade Points',
    'Result Published'
];

$rows = [];
foreach ($registrations as $reg) {
    $course_type = $reg['is_core'] ? 'Core' : ($reg['is_elective'] ? 'Elective' : 'General');
    
    $rows[] = [
        $reg['registration_id'],
        $reg['session_year'],
        $reg['semester'] == 1 ? 'First Semester' : ($reg['semester'] == 2 ? 'Second Semester' : $reg['semester']),
        $reg['registration_date'],
        $reg['approval_date'] ?? '',
        $reg['registration_status'],
        
        $reg['matric_number'],
        $reg['full_name'],
        $reg['first_name'],
        $reg['last_name'],
        $reg['student_email'],
        $reg['student_phone'],
        $reg['current_level'],
        $reg['student_status'],
        
        $reg['program_code'],
        $reg['program_name'],
        $reg['degree_type'],
        $reg['department_code'],
        $reg['department_name'],
        
        $reg['course_code'],
        cleanCsvField($reg['course_title']),
        $reg['credit_units'],
        $reg['course_level'],
        $reg['course_semester'] == 1 ? 'First' : ($reg['course_semester'] == 2 ? 'Second' : 'N/A'),
        $course_type,
        
        $reg['final_grade'] ?? '',
        $reg['final_score'] ?? '',
        $reg['grade_points'] ?? '',
        $reg['result_published'] ? 'Yes' : 'No'
    ];
}

// Export based on format
if ($format === 'excel' || $format === 'xlsx') {
    // For Excel, we'll output as CSV with .xlsx extension (browser will handle)
    // For proper Excel export, use CSV format which Excel can open
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    
} else {
    // Default CSV format
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
}

// Log the export action
try {
    $log_sql = "INSERT INTO admin_logs (admin_id, action, description, ip_address, table_name, created_at) 
                VALUES (?, 'Export', ?, ?, 'course_registrations', NOW())";
    $log_stmt = $pdo->prepare($log_sql);
    $description = "Exported " . count($registrations) . " course registration records";
    if ($department_id) $description .= " for department ID: $department_id";
    if ($program_id) $description .= " for program ID: $program_id";
    if ($session_year) $description .= " for session: $session_year";
    
    $admin_id = $_SESSION['admin_id'] ?? 0;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $log_stmt->execute([$admin_id, $description, $ip_address]);
} catch (Exception $e) {
    // Silent fail for logging
}

exit();
?>