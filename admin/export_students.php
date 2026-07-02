<?php
// Start output buffering to prevent header errors
ob_start();

// Don't include header.php for exports since we need to send headers
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Database connection directly
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

// Get export format
$format = isset($_GET['format']) ? $_GET['format'] : 'excel';

// Get search parameters from GET or SESSION
$search = isset($_GET['search']) ? $_GET['search'] : (isset($_SESSION['export_search']) ? $_SESSION['export_search'] : '');
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : (isset($_SESSION['export_department_id']) ? $_SESSION['export_department_id'] : 0);
$level = isset($_GET['level']) ? (int)$_GET['level'] : (isset($_SESSION['export_level']) ? $_SESSION['export_level'] : 0);
$status = isset($_GET['status']) ? $_GET['status'] : (isset($_SESSION['export_status']) ? $_SESSION['export_status'] : '');

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if ($department_id > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($level > 0) {
    $conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if ($status !== '') {
    $conditions[] = "s.status = ?";
    $params[] = $status;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get all students data
$sql = "
    SELECT 
        s.*,
        d.department_name,
        p.program_name,
        CONCAT(s.first_name, ' ', s.last_name) as full_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    {$where_clause}
    ORDER BY s.registration_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Export based on format
switch ($format) {
    case 'excel':
        exportToExcel($students);
        break;
    case 'pdf':
        exportToPDF($students);
        break;
    case 'csv':
        exportToCSV($students);
        break;
    default:
        exportToExcel($students);
}

function exportToExcel($students) {
    // Clear any previous output
    ob_clean();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.xls"');
    
    echo "Matric Number\tFirst Name\tMiddle Name\tLast Name\tEmail\tPhone\tGender\tDate of Birth\tDepartment\tProgram\tLevel\tStatus\tAdmission Year\tRegistration Date\n";
    
    foreach ($students as $student) {
        echo $student['matric_number'] . "\t";
        echo $student['first_name'] . "\t";
        echo ($student['middle_name'] ?? '') . "\t";
        echo $student['last_name'] . "\t";
        echo $student['email'] . "\t";
        echo ($student['phone'] ?? '') . "\t";
        echo ($student['gender'] ?? '') . "\t";
        echo ($student['date_of_birth'] ?? '') . "\t";
        echo ($student['department_name'] ?? '') . "\t";
        echo ($student['program_name'] ?? '') . "\t";
        echo $student['current_level'] . "\t";
        echo $student['status'] . "\t";
        echo ($student['admission_year'] ?? '') . "\t";
        echo $student['registration_date'] . "\n";
    }
    exit();
}

function exportToCSV($students) {
    // Clear any previous output
    ob_clean();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, [
        'Matric Number',
        'First Name',
        'Middle Name',
        'Last Name',
        'Email',
        'Phone',
        'Gender',
        'Date of Birth',
        'Department',
        'Program',
        'Level',
        'Status',
        'Admission Year',
        'Registration Date'
    ]);
    
    // Data rows
    foreach ($students as $student) {
        fputcsv($output, [
            $student['matric_number'],
            $student['first_name'],
            $student['middle_name'] ?? '',
            $student['last_name'],
            $student['email'],
            $student['phone'] ?? '',
            $student['gender'] ?? '',
            $student['date_of_birth'] ?? '',
            $student['department_name'] ?? '',
            $student['program_name'] ?? '',
            $student['current_level'],
            $student['status'],
            $student['admission_year'] ?? '',
            $student['registration_date']
        ]);
    }
    
    fclose($output);
    exit();
}

function exportToPDF($students) {
    // For PDF, create a simple text output for now
    // You can install FPDF later
    
    // Clear any previous output
    ob_clean();
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.pdf"');
    
    echo "Student List\n";
    echo "Generated on: " . date('Y-m-d H:i:s') . "\n\n";
    echo str_repeat("=", 80) . "\n";
    
    foreach ($students as $student) {
        echo "Matric: " . $student['matric_number'] . "\n";
        echo "Name: " . $student['first_name'] . " " . $student['last_name'] . "\n";
        echo "Email: " . $student['email'] . "\n";
        echo "Department: " . ($student['department_name'] ?? 'N/A') . "\n";
        echo "Level: " . $student['current_level'] . "\n";
        echo "Status: " . $student['status'] . "\n";
        echo str_repeat("-", 80) . "\n";
    }
    
    exit();
}

// End output buffering
ob_end_flush();
?>