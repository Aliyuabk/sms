<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Fetch all courses assigned to this staff
$stmt = $pdo->prepare("
    SELECT c.course_id, c.course_code, c.course_title, c.credit_units, c.level, c.semester,
           ca.session_year, ca.semester as assigned_semester, ca.assigned_date, ca.status as assignment_status,
           d.department_name,
           COUNT(cr.student_id) as student_count
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_registrations cr ON c.course_id = cr.course_id 
        AND cr.session_year = ca.session_year AND cr.semester = ca.semester AND cr.registration_status = 'Approved'
    WHERE ca.staff_id = ?
    GROUP BY c.course_id, ca.session_year, ca.semester
    ORDER BY ca.session_year DESC, ca.semester DESC, c.course_code
");
$stmt->execute([$staff_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$format = $_GET['format'] ?? 'csv';
$staff_name = $_SESSION['staff_name'] ?? 'Staff';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my_courses_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'S/N', 'Course Code', 'Course Title', 'Credit Units', 'Level', 'Semester',
        'Session Year', 'Department', 'Students', 'Status', 'Assigned Date'
    ]);

    foreach ($courses as $index => $course) {
        fputcsv($output, [
            $index + 1,
            $course['course_code'],
            $course['course_title'],
            $course['credit_units'],
            $course['level'],
            $course['assigned_semester'],
            $course['session_year'],
            $course['department_name'] ?? '',
            $course['student_count'],
            $course['assignment_status'],
            $course['assigned_date']
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
        <title>My Courses</title>
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
            <h2>My Courses List</h2>
            <div class="meta">
                <strong>Staff:</strong> <?php echo $staff_name; ?> | 
                <strong>Total Courses:</strong> <?php echo count($courses); ?> | 
                <strong>Generated:</strong> <?php echo date('Y-m-d H:i'); ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Units</th>
                    <th>Level</th>
                    <th>Semester</th>
                    <th>Session</th>
                    <th>Department</th>
                    <th>Students</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $index => $course): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo $course['course_code']; ?></td>
                    <td><?php echo $course['course_title']; ?></td>
                    <td><?php echo $course['credit_units']; ?></td>
                    <td><?php echo $course['level']; ?></td>
                    <td><?php echo $course['assigned_semester']; ?></td>
                    <td><?php echo $course['session_year']; ?></td>
                    <td><?php echo $course['department_name'] ?? ''; ?></td>
                    <td><?php echo $course['student_count']; ?></td>
                    <td><?php echo $course['assignment_status']; ?></td>
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

header('Location: courses.php');
exit;
?>