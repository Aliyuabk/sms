<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];
$course_id = isset($_GET['course']) ? intval($_GET['course']) : 0;
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

if (!$course_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch course details
$stmt = $pdo->prepare("
    SELECT c.*, ca.session_year, ca.semester, d.department_name
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE c.course_id = ? AND ca.staff_id = ? AND ca.status = 'Active'
    LIMIT 1
");
$stmt->execute([$course_id, $staff_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header('Location: dashboard.php');
    exit;
}

// Fetch students
$stmt2 = $pdo->prepare("
    SELECT s.student_id, s.matric_number, s.first_name, s.middle_name, s.last_name,
           s.email, s.phone, s.gender, s.current_level, s.state_of_origin, s.lga,
           s.date_of_birth, s.status as student_status,
           cr.registration_date, cr.attendance_percentage,
           r.ca_score, r.exam_score, r.total_score, r.grade, r.grade_points
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    LEFT JOIN results r ON s.student_id = r.student_id 
        AND r.course_id = ? AND r.session_year = ? AND r.semester = ?
    WHERE cr.course_id = ? AND cr.session_year = ? AND cr.semester = ? 
        AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$stmt2->execute([
    $course_id, $course['session_year'], $course['semester'],
    $course_id, $course['session_year'], $course['semester']
]);
$students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$filename = $course['course_code'] . '_' . $course['session_year'] . '_Sem' . $course['semester'];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');

    // BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Headers
    fputcsv($output, [
        'S/N', 'Matric Number', 'Surname', 'First Name', 'Middle Name', 'Gender', 'Email', 'Phone',
        'Level', 'State', 'LGA', 'Date of Birth', 'Registration Date', 'Attendance %',
        'CA Score', 'Exam Score', 'Total Score', 'Grade', 'Grade Points', 'Status'
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
            $student['state_of_origin'] ?? '',
            $student['lga'] ?? '',
            $student['date_of_birth'] ?? '',
            $student['registration_date'] ?? '',
            $student['attendance_percentage'] ?? 0,
            $student['ca_score'] ?? '',
            $student['exam_score'] ?? '',
            $student['total_score'] ?? '',
            $student['grade'] ?? 'N/A',
            $student['grade_points'] ?? '',
            $student['student_status']
        ]);
    }

    fclose($output);
    exit;
}

// PDF Export (simple HTML-based)
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?php echo $filename; ?></title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #3f749c; padding-bottom: 15px; }
            .header h1 { color: #3f749c; margin: 0; font-size: 18px; }
            .header h2 { color: #666; margin: 5px 0; font-size: 14px; }
            .meta { margin: 10px 0; font-size: 10px; color: #666; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { background: #3f749c; color: white; padding: 8px; text-align: left; font-size: 10px; }
            td { padding: 6px 8px; border-bottom: 1px solid #ddd; font-size: 10px; }
            tr:nth-child(even) { background: #f8f9fa; }
            .grade-a { color: #2e7d32; font-weight: bold; }
            .grade-b { color: #1565c0; font-weight: bold; }
            .grade-c { color: #ef6c00; font-weight: bold; }
            .grade-d, .grade-e, .grade-f { color: #c62828; font-weight: bold; }
            .footer { margin-top: 20px; font-size: 9px; color: #999; text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>STUDENT MANAGEMENT SYSTEM</h1>
            <h2>Class List Export</h2>
            <div class="meta">
                <strong>Course:</strong> <?php echo $course['course_code'] . ' - ' . $course['course_title']; ?> | 
                <strong>Session:</strong> <?php echo $course['session_year']; ?> | 
                <strong>Semester:</strong> <?php echo $course['semester']; ?> | 
                <strong>Level:</strong> <?php echo $course['level']; ?> | 
                <strong>Students:</strong> <?php echo count($students); ?> | 
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
                    <th>Email</th>
                    <th>Phone</th>
                    <th>CA</th>
                    <th>Exam</th>
                    <th>Total</th>
                    <th>Grade</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $index => $student): 
                    $grade_class = 'grade-' . strtolower($student['grade'] ?? 'null');
                ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo $student['matric_number']; ?></td>
                    <td><?php echo $student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''); ?></td>
                    <td><?php echo $student['gender'] ?? ''; ?></td>
                    <td><?php echo $student['current_level']; ?></td>
                    <td><?php echo $student['email']; ?></td>
                    <td><?php echo $student['phone'] ?? ''; ?></td>
                    <td><?php echo $student['ca_score'] ?? '-'; ?></td>
                    <td><?php echo $student['exam_score'] ?? '-'; ?></td>
                    <td><strong><?php echo $student['total_score'] ?? '-'; ?></strong></td>
                    <td class="<?php echo $grade_class; ?>"><?php echo $student['grade'] ?? 'N/A'; ?></td>
                    <td><?php echo $student['student_status']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            Generated by SMS Portal on <?php echo date('F d, Y 	 h:i A'); ?> | 
            Staff: <?php echo $_SESSION['staff_name'] ?? 'Staff'; ?>
        </div>

        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

header('Location: view_class.php?course=' . $course_id);
exit;