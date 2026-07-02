<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['student_id']) || !isset($_SESSION['student_name']) || !isset($_SESSION['matric_number'])) {
    header('Location: ../');
    exit();
}

// Get parameters
$selected_session = isset($_GET['session']) ? $_GET['session'] : '';
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

if (empty($selected_session) || $selected_semester == 0) {
    die("Invalid parameters");
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'student_portal_db';

try {
    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed.");
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8");
    
    // Get student ID from session
    $student_id = $_SESSION['student_id'];
    
    // Get student details
    $student_sql = "SELECT 
                        s.student_id, s.matric_number, 
                        CONCAT(s.first_name, ' ', s.last_name) as student_name,
                        s.current_level,
                        d.department_name, d.faculty,
                        p.program_name as programme
                    FROM students s
                    LEFT JOIN departments d ON s.department_id = d.department_id
                    LEFT JOIN programs p ON s.program_id = p.program_id
                    WHERE s.student_id = ?";
    
    $student_stmt = $conn->prepare($student_sql);
    $student_stmt->bind_param("i", $student_id);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    
    if ($student_result->num_rows > 0) {
        $student_data = $student_result->fetch_assoc();
        $current_level = $student_data['current_level'];
        $student_name = $student_data['student_name'];
        $matric_number = $student_data['matric_number'];
        $department = $student_data['department_name'];
        $faculty = $student_data['faculty'];
        $programme = $student_data['programme'];
    } else {
        throw new Exception("Student not found.");
    }
    $student_stmt->close();
    
    // Get semester results
    $results_sql = "SELECT 
                        r.*,
                        c.course_code,
                        c.course_title,
                        c.credit_units,
                        g.grade,
                        g.grade_points,
                        g.remark as grade_remark
                    FROM results r
                    JOIN courses c ON r.course_id = c.course_id
                    JOIN grade_scale g ON r.grade = g.grade
                    WHERE r.student_id = ? 
                      AND r.session_year = ? 
                      AND r.semester = ?
                      AND r.is_published = TRUE
                    ORDER BY c.course_code";
    
    $results_stmt = $conn->prepare($results_sql);
    $results_stmt->bind_param("isi", $student_id, $selected_session, $selected_semester);
    $results_stmt->execute();
    $results_result = $results_stmt->get_result();
    
    $results = [];
    $total_credits = 0;
    $total_weighted_gp = 0;
    
    while ($result = $results_result->fetch_assoc()) {
        $results[] = $result;
        $total_credits += $result['credit_units'];
        $total_weighted_gp += $result['credit_units'] * $result['grade_points'];
    }
    $results_stmt->close();
    
    // Calculate semester GPA
    $cumulative_gpa = 0.00;
    if ($total_credits > 0) {
        $cumulative_gpa = round($total_weighted_gp / $total_credits, 2);
    }
    
    // Get overall CGPA
    $cgpa_sql = "SELECT 
                    SUM(c.credit_units) as total_credits_all,
                    SUM(c.credit_units * g.grade_points) as total_weighted_gp_all,
                    ROUND(SUM(c.credit_units * g.grade_points) / SUM(c.credit_units), 2) as cgpa
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                JOIN grade_scale g ON r.grade = g.grade
                WHERE r.student_id = ? AND r.is_published = TRUE";
    
    $cgpa_stmt = $conn->prepare($cgpa_sql);
    $cgpa_stmt->bind_param("i", $student_id);
    $cgpa_stmt->execute();
    $cgpa_result = $cgpa_stmt->get_result();
    
    $overall_credits = 0;
    $overall_weighted_gp = 0;
    $overall_cgpa = 0.00;
    
    if ($cgpa_row = $cgpa_result->fetch_assoc()) {
        $overall_credits = $cgpa_row['total_credits_all'] ?? 0;
        $overall_weighted_gp = $cgpa_row['total_weighted_gp_all'] ?? 0;
        $overall_cgpa = $cgpa_row['cgpa'] ?? 0.00;
    }
    $cgpa_stmt->close();
    
    $conn->close();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Function to get semester name
function getSemesterName($semester) {
    switch ($semester) {
        case 1: return 'FIRST SEMESTER';
        case 2: return 'SECOND SEMESTER';
        default: return 'SEMESTER ' . $semester;
    }
}

// Function to get academic standing
function getAcademicStanding($gpa) {
    if ($gpa >= 4.50) return 'FIRST CLASS';
    if ($gpa >= 3.50) return 'SECOND CLASS UPPER';
    if ($gpa >= 2.50) return 'SECOND CLASS LOWER';
    if ($gpa >= 1.50) return 'THIRD CLASS';
    return 'PASS';
}

// Generate HTML for PDF
$html = '<!DOCTYPE html>
<html>
<head>
    <title>Academic Result - ' . $selected_session . '</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.4; 
            font-size: 12px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            padding-bottom: 10px; 
            border-bottom: 3px solid #000; 
        }
        .university { 
            font-size: 18px; 
            font-weight: bold; 
            text-transform: uppercase; 
            margin-bottom: 5px;
        }
        .faculty { 
            font-size: 14px; 
            font-weight: bold; 
            margin-bottom: 5px;
        }
        .department { 
            font-size: 13px; 
            font-weight: bold; 
            margin-bottom: 5px;
        }
        .title { 
            font-size: 14px; 
            margin-top: 10px; 
            font-weight: bold; 
        }
        .session { 
            font-size: 13px; 
            margin-top: 5px; 
        }
        .student-info { 
            margin: 20px 0; 
        }
        .info-row { 
            margin: 5px 0; 
            font-size: 12px; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0; 
            border: 1px solid #000; 
            font-size: 11px;
        }
        th { 
            background-color: #f2f2f2; 
            font-weight: bold; 
            padding: 8px; 
            border: 1px solid #000; 
            text-align: left; 
        }
        td { 
            padding: 8px; 
            border: 1px solid #000; 
        }
        .summary { 
            margin-top: 30px; 
        }
        .summary-row { 
            display: flex; 
            justify-content: space-between; 
            margin: 10px 0; 
        }
        .summary-col { 
            width: 32%; 
            padding: 10px; 
            border: 1px solid #000; 
            font-size: 11px;
        }
        .summary-title { 
            font-weight: bold; 
            margin-bottom: 5px; 
        }
        .footer { 
            margin-top: 40px; 
            text-align: center; 
            font-size: 10px; 
            color: #666; 
        }
        @media print {
            body { margin: 0; padding: 10px; }
            .header { margin-bottom: 20px; }
            table { margin: 15px 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="university">AL-QALAM UNIVERSITY, KATSINA</div>
        <div class="faculty">' . htmlspecialchars($faculty) . '</div>
        <div class="department">DEPARTMENT OF ' . strtoupper(htmlspecialchars($department)) . '</div>
        <div class="title">STUDENT SEMESTER RESULT</div>
        <div class="session">' . getSemesterName($selected_semester) . ' ' . htmlspecialchars($selected_session) . ' SESSION</div>
    </div>
    
    <div class="student-info">
        <div class="info-row"><strong>Mat. Number:</strong> ' . htmlspecialchars($matric_number) . '</div>
        <div class="info-row"><strong>Name:</strong> ' . htmlspecialchars($student_name) . '</div>
        <div class="info-row"><strong>Level:</strong> ' . $current_level . '</div>
        <div class="info-row"><strong>Programme:</strong> ' . htmlspecialchars($programme) . '</div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">S/N</th>
                <th width="15%">Course Code</th>
                <th width="35%">Course Title</th>
                <th width="8%">Credit</th>
                <th width="8%">Grade</th>
                <th width="8%">GP</th>
                <th width="21%">Remarks</th>
            </tr>
        </thead>
        <tbody>';

$sn = 1;
foreach ($results as $course) {
    $html .= '<tr>
        <td>' . $sn++ . '</td>
        <td>' . htmlspecialchars($course['course_code']) . '</td>
        <td>' . htmlspecialchars($course['course_title']) . '</td>
        <td>' . $course['credit_units'] . '</td>
        <td>' . htmlspecialchars($course['grade']) . '</td>
        <td>' . number_format($course['grade_points'], 1) . '</td>
        <td>' . htmlspecialchars($course['grade_remark']) . '</td>
    </tr>';
}

$html .= '</tbody>
    </table>
    
    <div class="summary">
        <div style="margin-bottom: 10px; font-weight: bold; font-size: 13px;">
            Remarks: ' . getAcademicStanding($cumulative_gpa) . '
        </div>
        
        <div class="summary-row">
            <div class="summary-col">
                <div class="summary-title">Current</div>
                <div>
                    CUR: ' . $total_credits . '<br>
                    CUE: ' . $total_credits . '<br>
                    WGP: ' . number_format($total_weighted_gp, 1) . '<br>
                    GPA: ' . number_format($cumulative_gpa, 2) . '
                </div>
            </div>
            
            <div class="summary-col">
                <div class="summary-title">Previous</div>
                <div>
                    TCUR: -<br>
                    TCUE: -<br>
                    TWGP: -<br>
                    CGPA: -
                </div>
            </div>
            
            <div class="summary-col">
                <div class="summary-title">Cumulative</div>
                <div>
                    TCUR: ' . $overall_credits . '<br>
                    TCUE: ' . $overall_credits . '<br>
                    TWGP: ' . number_format($overall_weighted_gp, 1) . '<br>
                    CGPA: ' . number_format($overall_cgpa, 2) . '
                </div>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <div>No alteration on this document</div>
        <div>Student\'s copy</div>
        <div>Printed on: ' . date('d/m/Y H:i:s') . '</div>
    </div>
</body>
</html>';

// Output HTML for browser to save as PDF
header('Content-Type: text/html');
header('Content-Disposition: attachment; filename="Result_' . $selected_session . '_Sem' . $selected_semester . '.html"');

echo $html;
?>