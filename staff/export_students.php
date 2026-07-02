<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Fetch all students enrolled in staff's courses
$stmt = $pdo->prepare("
    SELECT DISTINCT s.student_id, s.matric_number, s.first_name, s.middle_name, s.last_name,
           s.email, s.phone, s.gender, s.current_level, s.status as student_status,
           s.date_of_birth, s.state_of_origin, s.lga, s.admission_year,
           d.department_name, p.program_name,
           GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as courses
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id AND cr.session_year = ca.session_year AND cr.semester = ca.semester
    JOIN courses c ON cr.course_id = c.course_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE ca.staff_id = ? AND ca.status = 'Active' AND cr.registration_status = 'Approved'
    GROUP BY s.student_id
    ORDER BY s.last_name, s.first_name
");
$stmt->execute([$staff_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$format = $_GET['format'] ?? 'csv';
$staff_name = $_SESSION['staff_name'] ?? 'Staff';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my_students_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'S/N', 'Matric Number', 'Surname', 'First Name', 'Middle Name', 'Gender', 'Email', 'Phone',
        'Level', 'Department', 'Program', 'State', 'LGA', 'Admission Year', 'Date of Birth', 'Status', 'Courses'
    ]);

    foreach ($students as $index => $student) {
        fputcsv($output, [
            $index + 1,
            $student['matric_number'],
            $student['last_name'],
            $student['first_name'],
            $student['middle_name'] ?? '',
            $student['gender'] ?? '',
            $student['email'],
            $student['phone'] ?? '',
            $student['current_level'],
            $student['department_name'] ?? '',
            $student['program_name'] ?? '',
            $student['state_of_origin'] ?? '',
            $student['lga'] ?? '',
            $student['admission_year'] ?? '',
            $student['date_of_birth'] ?? '',
            $student['student_status'],
            $student['courses'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

// PDF
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>My Students</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 10px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #3f749c; padding-bottom: 15px; }
            .header h1 { color: #3f749c; margin: 0; font-size: 18px; }
            .header h2 { color: #666; margin: 5px 0; font-size: 14px; }
            .meta { margin: 10px 0; font-size: 10px; color: #666; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { background: #3f749c; color: white; padding: 8px; text-align: left; font-size: 9px; }
            td { padding: 6px 8px; border-bottom: 1px solid #ddd; font-size: 9px; }
            tr:nth-child(even) { background: #f8f9fa; }
            .footer { margin-top: 20px; font-size: 9px; color: #999; text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>STUDENT MANAGEMENT SYSTEM</h1>
            <h2>My Students List</h2>
            <div class="meta">
                <strong>Staff:</strong> <?php echo $staff_name; ?> | 
                <strong>Total Students:</strong> <?php echo count($students); ?> | 
                <strong>Generated:</strong> <?php echo date('Y-m-d H:i'); ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Matric No</th>
                    <th>Full Name</th>
                    <th>Gender</th>
                    <th>Level</th>
                    <th>Department</th>
                    <th>Program</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $index => $student): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo $student['matric_number']; ?></td>
                    <td><?php echo $student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''); ?></td>
                    <td><?php echo $student['gender'] ?? ''; ?></td>
                    <td><?php echo $student['current_level']; ?></td>
                    <td><?php echo $student['department_name'] ?? ''; ?></td>
                    <td><?php echo $student['program_name'] ?? ''; ?></td>
                    <td><?php echo $student['email']; ?></td>
                    <td><?php echo $student['phone'] ?? ''; ?></td>
                    <td><?php echo $student['student_status']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            Generated by SMS Portal on <?php echo date('F d, Y 	 h:i A'); ?>
        </div>

        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

header('Location: students.php');
exit;