<?php
// download_course_template.php
// This file should be in the same directory as courses.php

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=course_import_template.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Headers - exactly as they should appear in the CSV
$headers = [
    'course_code',
    'course_title',
    'credit_units',
    'semester',
    'prerequisite_code',
    'is_core',
    'is_elective',
    'elective_type',
    'course_description'
];

// Write headers
fputcsv($output, $headers);

// Sample data rows
$samples = [
    [
        'CSC101',
        'Introduction to Computer Science',
        '3',
        '1',
        '',
        '1',
        '0',
        '',
        'Basic concepts of computer science and programming'
    ],
    [
        'CSC102',
        'Computer Programming I',
        '3',
        '2',
        'CSC101',
        '1',
        '0',
        '',
        'Introduction to programming using Python'
    ],
    [
        'MTH201',
        'Calculus II',
        '3',
        '1',
        'MTH101',
        '1',
        '0',
        '',
        'Advanced calculus and differential equations'
    ],
    [
        'BUS301',
        'Business Ethics',
        '2',
        '1',
        '',
        '0',
        '1',
        'Faculty',
        'Ethical issues in business and commerce'
    ],
    [
        'GST101',
        'Use of English',
        '2',
        '1',
        '',
        '1',
        '0',
        '',
        'Communication skills in English'
    ]
];

// Write sample data
foreach ($samples as $row) {
    fputcsv($output, $row);
}

// Close the output stream
fclose($output);
exit;
?>