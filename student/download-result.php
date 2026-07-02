<?php
/**
 * download-result.php
 * Server-side PDF generation for student results
 * Requires: TCPDF or FPDF library
 */

session_start();

// ─── CHECK LOGIN ───
if (!isset($_SESSION['student_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$student_id = (int)$_SESSION['student_id'];

// ─── GET PARAMETERS ───
$session_year = $_GET['session'] ?? '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;

if (empty($session_year) || $semester === 0) {
    die("Missing required parameters: session and semester");
}

// ─── DATABASE CONNECTION ───
require_once '../config/db.php'; // Adjust path as needed

// ─── FETCH STUDENT INFO ───
$student_query = "SELECT s.*, d.department_name, p.program_name 
                 FROM students s 
                 LEFT JOIN departments d ON s.department_id = d.department_id 
                 LEFT JOIN programs p ON s.program_id = p.program_id 
                 WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student not found.");
}

// ─── FETCH RESULTS ───
$results_query = "SELECT r.*, c.course_code, c.course_title, c.credit_units,
                  gs.grade_points as scale_gp, gs.remark as grade_remark_text
                  FROM results r 
                  JOIN courses c ON r.course_id = c.course_id 
                  LEFT JOIN grade_scale gs ON r.grade = gs.grade
                  WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1
                  ORDER BY c.course_code";
$stmt = $conn->prepare($results_query);
$stmt->bind_param("isi", $student_id, $session_year, $semester);
$stmt->execute();
$results = $stmt->get_result();

$courses = [];
$total_units = 0;
$total_weighted_points = 0;

while ($row = $results->fetch_assoc()) {
    if (is_null($row['grade_points']) && !is_null($row['scale_gp'])) {
        $row['grade_points'] = $row['scale_gp'];
    }
    $courses[] = $row;
    $total_units += (float)$row['credit_units'];
    $total_weighted_points += ((float)$row['grade_points'] * (float)$row['credit_units']);
}

$total_courses = count($courses);
$gpa = $total_units > 0 ? round($total_weighted_points / $total_units, 2) : 0;

// ─── CALCULATE CGPA ───
$cgpa_query = "SELECT SUM(r.grade_points * c.credit_units) as total_wp, SUM(c.credit_units) as total_u
               FROM results r 
               JOIN courses c ON r.course_id = c.course_id 
               WHERE r.student_id = ? AND r.is_published = 1";
$stmt = $conn->prepare($cgpa_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$cgpa_data = $stmt->get_result()->fetch_assoc();
$tcue = (float)($cgpa_data['total_u'] ?? 0);
$tcgp = (float)($cgpa_data['total_wp'] ?? 0);
$cgpa = ($tcue > 0) ? round($tcgp / $tcue, 2) : 0;

// ─── HELPERS ───
function getRemark($gpa) {
    if ($gpa >= 4.50) return "VC'S LIST";
    if ($gpa >= 3.50) return "DEAN'S LIST";
    if ($gpa >= 3.00) return "GOOD STANDING";
    if ($gpa >= 2.00) return "PASS";
    return "PROBATION";
}

function getLevelName($level) {
    $levels = [100 => 'LEVEL ONE', 200 => 'LEVEL TWO', 300 => 'LEVEL THREE', 400 => 'LEVEL FOUR', 500 => 'LEVEL FIVE'];
    return $levels[$level] ?? "LEVEL $level";
}

$remark = getRemark($gpa);
$student_name = strtoupper(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$dept_name = $student['department_name'] ?? 'Department';
$matric = $student['matric_number'] ?? '';
$semester_name = ($semester == 1) ? 'First' : 'Second';
$level_name = getLevelName($level);

// ─── PDF GENERATION ───
// Check if TCPDF is available
$tcpdf_paths = [
    'vendor/tecnickcom/tcpdf/tcpdf.php',
    'tcpdf/tcpdf.php',
    'lib/tcpdf/tcpdf.php',
    '../tcpdf/tcpdf.php',
    '../../tcpdf/tcpdf.php'
];

$tcpdf_found = false;
foreach ($tcpdf_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $tcpdf_found = true;
        break;
    }
}

if (!$tcpdf_found) {
    // Fallback: Check for FPDF
    $fpdf_paths = [
        'vendor/setasign/fpdf/fpdf.php',
        'fpdf/fpdf.php',
        'lib/fpdf/fpdf.php',
        '../fpdf/fpdf.php'
    ];
    
    $fpdf_found = false;
    foreach ($fpdf_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $fpdf_found = true;
            break;
        }
    }
    
    if (!$fpdf_found) {
        // No PDF library found - generate HTML and use browser print
        generateHTMLFallback();
        exit();
    }
    
    // Use FPDF
    generateFPDF();
    exit();
}

// Use TCPDF
generateTCPDF();

// ─── TCPDF GENERATOR ───
function generateTCPDF() {
    global $student_name, $dept_name, $matric, $session_year, $semester_name, 
           $level_name, $total_courses, $total_units, $total_weighted_points,
           $gpa, $cgpa, $tcue, $tcgp, $remark, $courses;
    
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    
    // Add page
    $pdf->AddPage();
    
    // School Header
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(63, 116, 156); // #3f749c
    $pdf->Cell(0, 10, '5G E-GURU SCHOOL', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->Cell(0, 8, 'STUDENT ACADEMIC RESULT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(102, 102, 102);
    $pdf->Cell(0, 6, 'Department of ' . $dept_name, 0, 1, 'C');
    
    $pdf->SetDrawColor(63, 116, 156);
    $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
    $pdf->Ln(8);
    
    // Student Info
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 6, 'Department of ' . $dept_name, 0, 1);
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(51, 51, 51);
    
    $info_data = [
        'Session: ' . $semester_name . ' Semester, ' . $session_year . ' Session',
        'Level: ' . $level_name,
        'Mat. Number: ' . $matric,
        'Student: ' . $student_name
    ];
    
    foreach ($info_data as $line) {
        $pdf->Cell(0, 6, $line, 0, 1);
    }
    $pdf->Ln(4);
    
    // Summary Bar
    $pdf->SetFillColor(241, 245, 249); // #f1f5f9
    $pdf->SetDrawColor(226, 232, 240); // #e2e8f0
    $pdf->Rect(15, $pdf->GetY(), 180, 20, 'DF');
    
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetXY(20, $pdf->GetY() + 4);
    $pdf->Cell(50, 12, $total_courses, 0, 0, 'L');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(20, $pdf->GetY() - 2);
    $pdf->Cell(50, 6, 'Courses offered', 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColor(63, 116, 156);
    $pdf->SetXY(145, $pdf->GetY() - 2);
    $pdf->Cell(45, 12, number_format($gpa, 2), 0, 0, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetXY(145, $pdf->GetY() - 2);
    $pdf->Cell(45, 6, 'G.P.A', 0, 0, 'R');
    
    $pdf->Ln(24);
    
    // Results Table
    if ($total_courses > 0) {
        // Table Header
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(51, 65, 85);
        
        $col_widths = [85, 20, 20, 20, 35];
        $headers = ['Course Code & Title', 'Credit', 'Grade', 'GP', 'Remark'];
        $aligns = ['L', 'C', 'C', 'C', 'R'];
        
        $start_y = $pdf->GetY();
        foreach ($headers as $i => $header) {
            $pdf->Cell($col_widths[$i], 10, $header, 1, 0, $aligns[$i], true);
        }
        $pdf->Ln();
        
        // Table Body
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($courses as $course) {
            $grade = strtoupper($course['grade'] ?? '');
            $gp = is_numeric($course['grade_points']) ? number_format((float)$course['grade_points'], 1) : '0.0';
            $is_pass = ($grade !== 'F');
            
            // Grade color
            $grade_colors = [
                'A' => [22, 101, 52], 'B' => [30, 64, 175], 'C' => [3, 105, 161],
                'D' => [161, 98, 7], 'E' => [194, 65, 12], 'F' => [220, 38, 38]
            ];
            $color = $grade_colors[$grade] ?? [100, 116, 139];
            
            // Course code & title
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($col_widths[0], 8, $course['course_code'] ?? '', 'LR', 0, 'L');
            $pdf->Ln(4);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($col_widths[0], 6, '  ' . ($course['course_title'] ?? ''), 'LR', 0, 'L');
            $pdf->SetXY($pdf->GetX() - $col_widths[0], $pdf->GetY() - 4);
            
            // Credit
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell($col_widths[1], 10, $course['credit_units'] ?? '0', 'LR', 0, 'C');
            
            // Grade
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($col_widths[2], 10, $grade, 'LR', 0, 'C');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(30, 41, 59);
            
            // GP
            $pdf->Cell($col_widths[3], 10, $gp, 'LR', 0, 'C');
            
            // Remark
            $pdf->SetTextColor($is_pass ? 22 : 220, $is_pass ? 101 : 38, $is_pass ? 52 : 38);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($col_widths[4], 10, $is_pass ? 'Pass' : 'Fail', 'LR', 0, 'R');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(30, 41, 59);
            
            $pdf->Ln();
        }
        
        // Close table bottom border
        $pdf->Cell(array_sum($col_widths), 0, '', 'T');
        $pdf->Ln(6);
        
        // Remarks
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Rect(15, $pdf->GetY(), 180, 12, 'DF');
        $pdf->SetXY(20, $pdf->GetY() + 3);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(30, 6, 'Remarks:', 0, 0);
        $pdf->SetTextColor(63, 116, 156);
        $pdf->Cell(0, 6, $remark, 0, 1);
        $pdf->Ln(4);
        
        // GPA Grid
        $box_width = 85;
        $box_height = 55;
        
        // Current Semester Box
        $pdf->SetFillColor(250, 250, 250);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Rect(15, $pdf->GetY(), $box_width, $box_height, 'DF');
        
        $pdf->SetXY(20, $pdf->GetY() + 3);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($box_width - 10, 6, 'CURRENT SEMESTER', 0, 1);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Line(20, $pdf->GetY(), 95, $pdf->GetY());
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        
        $sem_data = [
            ['CUR (Units Registered):', $total_units],
            ['CUE (Units Earned):', $total_units],
            ['WGP (Weighted GP):', round($total_weighted_points)],
            ['GPA:', number_format($gpa, 2)]
        ];
        
        foreach ($sem_data as $item) {
            $pdf->SetX(20);
            $pdf->Cell(60, 7, $item[0], 0, 0);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(25, 7, $item[1], 0, 1, 'R');
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('helvetica', '', 9);
        }
        
        // Cumulative Box
        $pdf->SetXY(110, $pdf->GetY() - 55);
        $pdf->SetFillColor(250, 250, 250);
        $pdf->Rect(110, $pdf->GetY(), $box_width, $box_height, 'DF');
        
        $pdf->SetXY(115, $pdf->GetY() + 3);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($box_width - 10, 6, 'CUMULATIVE', 0, 1);
        $pdf->Line(115, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(2);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        
        $cum_data = [
            ['TCUR (Total Units Reg):', round($tcue)],
            ['TCUE (Total Units Earned):', round($tcue)],
            ['TWGP (Total Weighted GP):', round($tcgp)],
            ['CGPA:', number_format($cgpa, 2)]
        ];
        
        foreach ($cum_data as $item) {
            $pdf->SetX(115);
            $pdf->Cell(60, 7, $item[0], 0, 0);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(25, 7, $item[1], 0, 1, 'R');
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('helvetica', '', 9);
        }
    }
    
    // Footer
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(0, 5, 'Generated on ' . date('d F Y') . ' from 5G E-GURU School Student Portal', 0, 1, 'C');
    $pdf->Cell(0, 5, 'This is a computer-generated document and does not require a physical signature.', 0, 1, 'C');
    
    // Output PDF
    $filename = 'Result_' . $matric . '_' . str_replace('/', '_', $session_year) . '_Sem' . $semester . '.pdf';
    $pdf->Output($filename, 'D'); // 'D' = Download
    exit();
}

// ─── FPDF GENERATOR (Fallback) ───
function generateFPDF() {
    global $student_name, $dept_name, $matric, $session_year, $semester_name, 
           $level_name, $total_courses, $total_units, $total_weighted_points,
           $gpa, $cgpa, $tcue, $tcgp, $remark, $courses;
    
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);
    
    // Header
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->SetTextColor(63, 116, 156);
    $pdf->Cell(0, 10, '5G E-GURU SCHOOL', 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->Cell(0, 8, 'STUDENT ACADEMIC RESULT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(102, 102, 102);
    $pdf->Cell(0, 6, 'Department of ' . $dept_name, 0, 1, 'C');
    
    $pdf->SetDrawColor(63, 116, 156);
    $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
    $pdf->Ln(8);
    
    // Info
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 6, 'Department of ' . $dept_name, 0, 1);
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Session: ' . $semester_name . ' Semester, ' . $session_year . ' Session', 0, 1);
    $pdf->Cell(0, 6, 'Level: ' . $level_name, 0, 1);
    $pdf->Cell(0, 6, 'Mat. Number: ' . $matric, 0, 1);
    $pdf->Cell(0, 6, 'Student: ' . $student_name, 0, 1);
    $pdf->Ln(4);
    
    // Summary
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Rect(15, $pdf->GetY(), 180, 20, 'F');
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetXY(20, $pdf->GetY() + 4);
    $pdf->Cell(50, 12, $total_courses, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(20, $pdf->GetY() - 2);
    $pdf->Cell(50, 6, 'Courses offered', 0, 0);
    
    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetTextColor(63, 116, 156);
    $pdf->SetXY(145, $pdf->GetY() - 2);
    $pdf->Cell(45, 12, number_format($gpa, 2), 0, 0, 'R');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetXY(145, $pdf->GetY() - 2);
    $pdf->Cell(45, 6, 'G.P.A', 0, 0, 'R');
    $pdf->Ln(24);
    
    // Table
    if ($total_courses > 0) {
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->Cell(85, 10, 'Course Code & Title', 1, 0, 'L', true);
        $pdf->Cell(20, 10, 'Credit', 1, 0, 'C', true);
        $pdf->Cell(20, 10, 'Grade', 1, 0, 'C', true);
        $pdf->Cell(20, 10, 'GP', 1, 0, 'C', true);
        $pdf->Cell(35, 10, 'Remark', 1, 1, 'R', true);
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(30, 41, 59);
        
        foreach ($courses as $course) {
            $grade = strtoupper($course['grade'] ?? '');
            $gp = is_numeric($course['grade_points']) ? number_format((float)$course['grade_points'], 1) : '0.0';
            $is_pass = ($grade !== 'F');
            
            $y = $pdf->GetY();
            $pdf->Cell(85, 8, $course['course_code'] ?? '', 'LR', 0);
            $pdf->Cell(20, 8, $course['credit_units'] ?? '0', 'LR', 0, 'C');
            $pdf->Cell(20, 8, $grade, 'LR', 0, 'C');
            $pdf->Cell(20, 8, $gp, 'LR', 0, 'C');
            $pdf->Cell(35, 8, $is_pass ? 'Pass' : 'Fail', 'LR', 1, 'R');
            
            // Course title on next line
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(85, 6, '  ' . ($course['course_title'] ?? ''), 'LR', 0);
            $pdf->Cell(20, 6, '', 'LR', 0);
            $pdf->Cell(20, 6, '', 'LR', 0);
            $pdf->Cell(20, 6, '', 'LR', 0);
            $pdf->Cell(35, 6, '', 'LR', 1);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(30, 41, 59);
        }
        
        $pdf->Cell(180, 0, '', 'T');
        $pdf->Ln(6);
        
        // Remarks
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Rect(15, $pdf->GetY(), 180, 12, 'F');
        $pdf->SetXY(20, $pdf->GetY() + 3);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 6, 'Remarks:', 0, 0);
        $pdf->SetTextColor(63, 116, 156);
        $pdf->Cell(0, 6, $remark, 0, 1);
        $pdf->Ln(4);
        
        // GPA Boxes
        $box_y = $pdf->GetY();
        $box_w = 85;
        $box_h = 50;
        
        $pdf->SetFillColor(250, 250, 250);
        $pdf->Rect(15, $box_y, $box_w, $box_h, 'F');
        $pdf->SetXY(20, $box_y + 3);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($box_w - 10, 6, 'CURRENT SEMESTER', 0, 1);
        $pdf->Line(20, $pdf->GetY(), 95, $pdf->GetY());
        $pdf->Ln(2);
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        $sem_data = [
            ['CUR:', $total_units],
            ['CUE:', $total_units],
            ['WGP:', round($total_weighted_points)],
            ['GPA:', number_format($gpa, 2)]
        ];
        foreach ($sem_data as $item) {
            $pdf->SetX(20);
            $pdf->Cell(50, 7, $item[0], 0, 0);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(25, 7, $item[1], 0, 1, 'R');
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('Arial', '', 9);
        }
        
        $pdf->Rect(110, $box_y, $box_w, $box_h, 'F');
        $pdf->SetXY(115, $box_y + 3);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($box_w - 10, 6, 'CUMULATIVE', 0, 1);
        $pdf->Line(115, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(2);
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        $cum_data = [
            ['TCUR:', round($tcue)],
            ['TCUE:', round($tcue)],
            ['TWGP:', round($tcgp)],
            ['CGPA:', number_format($cgpa, 2)]
        ];
        foreach ($cum_data as $item) {
            $pdf->SetX(115);
            $pdf->Cell(50, 7, $item[0], 0, 0);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(25, 7, $item[1], 0, 1, 'R');
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('Arial', '', 9);
        }
    }
    
    // Footer
    $pdf->SetY(-20);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(0, 5, 'Generated on ' . date('d F Y') . ' from 5G E-GURU School Student Portal', 0, 1, 'C');
    $pdf->Cell(0, 5, 'This is a computer-generated document and does not require a physical signature.', 0, 1, 'C');
    
    $filename = 'Result_' . $matric . '_' . str_replace('/', '_', $session_year) . '_Sem' . $semester . '.pdf';
    $pdf->Output('D', $filename);
    exit();
}

// ─── HTML FALLBACK (if no PDF library) ───
function generateHTMLFallback() {
    global $student_name, $dept_name, $matric, $session_year, $semester_name, 
           $level_name, $total_courses, $total_units, $total_weighted_points,
           $gpa, $cgpa, $tcue, $tcgp, $remark, $courses;
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Result - <?php echo htmlspecialchars($matric) . ' - ' . htmlspecialchars($student_name); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
        <style>
            @media print {
                body { margin: 0; padding: 15mm; font-family: Arial, sans-serif; }
                .no-print { display: none; }
            }
            body { 
                max-width: 210mm; 
                margin: 0 auto; 
                padding: 20px; 
                font-family: Arial, sans-serif;
                color: #333;
            }
            .header { 
                text-align: center; 
                border-bottom: 2px solid #3f749c; 
                padding-bottom: 15px; 
                margin-bottom: 20px; 
            }
            .header h1 { color: #3f749c; margin: 0; font-size: 22px; }
            .header h2 { margin: 8px 0 0 0; font-size: 16px; color: #333; }
            .header p { margin: 5px 0 0 0; color: #666; font-size: 12px; }
            .info { margin-bottom: 20px; font-size: 12px; line-height: 1.8; }
            .summary { 
                display: flex; 
                justify-content: space-between; 
                padding: 12px 15px; 
                background: #f1f5f9; 
                border: 1px solid #e2e8f0; 
                margin-bottom: 15px; 
                border-radius: 4px;
            }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 11px; }
            th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-weight: bold; }
            td { border: 1px solid #e2e8f0; padding: 8px; }
            .remarks { 
                padding: 10px 15px; 
                background: #f8fafc; 
                border: 1px solid #e2e8f0; 
                margin-bottom: 15px; 
                font-size: 12px; 
            }
            .gpa-grid { display: flex; gap: 20px; margin-bottom: 15px; }
            .gpa-box { 
                flex: 1; 
                border: 1px solid #e2e8f0; 
                padding: 12px 15px; 
                background: #fafafa; 
            }
            .footer { 
                margin-top: 30px; 
                text-align: center; 
                font-size: 9px; 
                color: #94a3b8; 
                border-top: 1px solid #e2e8f0; 
                padding-top: 10px; 
            }
            .btn-print {
                display: block;
                width: 200px;
                margin: 20px auto;
                padding: 12px 24px;
                background: #3f749c;
                color: white;
                text-align: center;
                text-decoration: none;
                border-radius: 8px;
                font-weight: bold;
                cursor: pointer;
                border: none;
            }
        </style>
    </head>
    <body>
        <button class="btn-print no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
        
        <div class="header">
            <h1>5G E-GURU SCHOOL</h1>
            <h2>STUDENT ACADEMIC RESULT</h2>
            <p>Department of <?php echo htmlspecialchars($dept_name); ?></p>
        </div>

        <div class="info">
            <h3>Department of <?php echo htmlspecialchars($dept_name); ?></h3>
            <strong>Session:</strong> <?php echo $semester_name; ?> Semester, <?php echo htmlspecialchars($session_year); ?> Session<br>
            <strong>Level:</strong> <?php echo $level_name; ?><br>
            <strong>Mat. Number:</strong> <?php echo htmlspecialchars($matric); ?><br>
            <strong>Student:</strong> <?php echo htmlspecialchars($student_name); ?>
        </div>

        <div class="summary">
            <div>
                <div style="font-size:11px;color:#64748b;">Courses offered</div>
                <div style="font-size:20px;font-weight:bold;"><?php echo $total_courses; ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:10px;color:#94a3b8;">G.P.A</div>
                <div style="font-size:24px;font-weight:bold;color:#3f749c;"><?php echo number_format($gpa, 2); ?></div>
            </div>
        </div>

        <?php if ($total_courses > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Course Code & Title</th>
                    <th>Credit</th>
                    <th>Grade</th>
                    <th>GP</th>
                    <th style="text-align:right;">Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $course): 
                    $grade = strtoupper($course['grade'] ?? '');
                    $gp = is_numeric($course['grade_points']) ? number_format((float)$course['grade_points'], 1) : '0.0';
                    $is_pass = ($grade !== 'F');
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($course['course_code'] ?? ''); ?></strong><br>
                        <small style="color:#64748b;"><?php echo htmlspecialchars($course['course_title'] ?? ''); ?></small>
                    </td>
                    <td style="text-align:center;"><?php echo $course['credit_units'] ?? '0'; ?></td>
                    <td style="text-align:center;"><strong><?php echo $grade; ?></strong></td>
                    <td style="text-align:center;"><?php echo $gp; ?></td>
                    <td style="text-align:right;">
                        <strong style="color:<?php echo $is_pass ? '#166534' : '#dc2626'; ?>"><?php echo $is_pass ? 'Pass' : 'Fail'; ?></strong>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="remarks">
            <strong>Remarks:</strong> <span style="color:#3f749c;font-weight:bold;"><?php echo htmlspecialchars($remark); ?></span>
        </div>

        <div class="gpa-grid">
            <div class="gpa-box">
                <h4 style="margin:0 0 8px 0;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:5px;">Current Semester</h4>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CUR:</span><span><?php echo $total_units; ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CUE:</span><span><?php echo $total_units; ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>WGP:</span><span><?php echo round($total_weighted_points); ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>GPA:</span><span style="color:#3f749c;font-weight:bold;"><?php echo number_format($gpa, 2); ?></span></div>
            </div>
            <div class="gpa-box">
                <h4 style="margin:0 0 8px 0;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:5px;">Cumulative</h4>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TCUR:</span><span><?php echo round($tcue); ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TCUE:</span><span><?php echo round($tcue); ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>TWGP:</span><span><?php echo round($tcgp); ?></span></div>
                <div style="display:flex;justify-content:space-between;font-size:10px;padding:3px 0;"><span>CGPA:</span><span style="color:#3f749c;font-weight:bold;"><?php echo number_format($cgpa, 2); ?></span></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>Generated on <?php echo date('d F Y'); ?> from 5G E-GURU School Student Portal</p>
            <p>This is a computer-generated document and does not require a physical signature.</p>
        </div>
    </body>
    </html>
    <?php
    exit();
}