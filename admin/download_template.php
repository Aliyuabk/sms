<?php
// download_template.php
ob_start();
session_start();

require_once 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get template type from URL
$type = isset($_GET['type']) ? $_GET['type'] : 'students';

// Set filename based on type
$filename = '';
$headers = [];
$sample_data = [];

switch ($type) {
    case 'students':
        $filename = 'student_import_template.csv';
        $headers = [
            'matric_number',
            'first_name',
            'last_name',
            'middle_name',
            'email',
            'date_of_birth',
            'gender',
            'phone',
            'jamb_reg_number',
            'state_of_origin',
            'lga',
            'address',
            'nationality',
            'admission_year'
        ];
        $sample_data = [
            'CSC2024001',
            'John',
            'Doe',
            'Smith',
            'john.doe@student.edu',
            '2000-05-15',
            'Male',
            '08012345678',
            '12345678AB',
            'Kano',
            'Kano Municipal',
            'No. 1 University Road, Kano',
            'Nigerian',
            '2024'
        ];
        break;
        
    case 'courses':
        $filename = 'course_import_template.csv';
        $headers = [
            'course_code',
            'course_title',
            'credit_units',
            'department_id',
            'level',
            'semester',
            'is_core',
            'is_elective'
        ];
        $sample_data = [
            'CSC101',
            'Introduction to Computer Science',
            '3',
            '1',
            '100',
            '1',
            '1',
            '0'
        ];
        break;
        
    case 'fees':
        $filename = 'fee_structure_template.csv';
        $headers = [
            'session_year',
            'level',
            'program_id',
            'fee_type',
            'description',
            'amount',
            'due_date',
            'is_mandatory',
            'applicable_to'
        ];
        $sample_data = [
            '2024/2025',
            '100',
            '1',
            'Tuition',
            'Tuition fee for first year',
            '100000',
            '2024-10-31',
            '1',
            'New Students'
        ];
        break;
        
    case 'results':
        $filename = 'results_import_template.csv';
        $headers = [
            'matric_number',
            'course_code',
            'session_year',
            'semester',
            'ca_score',
            'exam_score'
        ];
        $sample_data = [
            'CSC2024001',
            'CSC101',
            '2024/2025',
            '1',
            '30',
            '60'
        ];
        break;
        
    default:
        $filename = 'import_template.csv';
        $headers = ['column1', 'column2', 'column3'];
        $sample_data = ['data1', 'data2', 'data3'];
}

// Clear any output buffers
ob_clean();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
fputcsv($output, $headers);

// Write sample data
fputcsv($output, $sample_data);

// Add a second sample row for better example
if ($type == 'students') {
    $sample_data2 = [
        'CSC2024002',
        'Jane',
        'Smith',
        'Mary',
        'jane.smith@student.edu',
        '2001-08-22',
        'Female',
        '08098765432',
        '87654321AB',
        'Lagos',
        'Ikeja',
        'No. 2 Victoria Island, Lagos',
        'Nigerian',
        '2024'
    ];
    fputcsv($output, $sample_data2);
    
    // Add a third sample row
    $sample_data3 = [
        'BCH2024001',
        'Ahmed',
        'Musa',
        'Ibrahim',
        'ahmed.musa@student.edu',
        '1999-12-10',
        'Male',
        '07012345678',
        '98765432CD',
        'Kaduna',
        'Kaduna North',
        'No. 3 Ahmadu Bello Way, Kaduna',
        'Nigerian',
        '2024'
    ];
    fputcsv($output, $sample_data3);
}

fclose($output);
exit();
?>