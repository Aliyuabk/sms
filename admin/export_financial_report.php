<?php
// export_financial_report.php - Export financial reports to CSV/Excel
ob_start();
session_start();

// Check admin login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Database connection
$pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get filter parameters
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$session_year = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$fee_type = isset($_GET['fee_type']) ? $_GET['fee_type'] : '';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'fee_summary';

// Build filters
$filters = [];
$params = [];

if ($department_id > 0) {
    $filters[] = "s.department_id = ?";
    $params[] = $department_id;
}
if ($program_id > 0) {
    $filters[] = "s.program_id = ?";
    $params[] = $program_id;
}
if (!empty($session_year)) {
    $filters[] = "sf.session_year = ?";
    $params[] = $session_year;
}
if (!empty($fee_type)) {
    $filters[] = "sf.fee_type = ?";
    $params[] = $fee_type;
}
if (!empty($payment_status)) {
    $filters[] = "sf.status = ?";
    $params[] = $payment_status;
}

$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

// Get data based on report type
if ($report_type == 'fee_summary') {
    $sql = "
        SELECT 
            sf.session_year, 
            sf.fee_type, 
            COUNT(DISTINCT sf.student_id) as student_count, 
            SUM(sf.amount) as total_fees, 
            SUM(sf.amount_paid) as total_paid, 
            SUM(sf.balance) as total_balance,
            ROUND(SUM(sf.amount_paid) * 100.0 / NULLIF(SUM(sf.amount), 0), 2) as collection_rate
        FROM student_fees sf 
        JOIN students s ON sf.student_id = s.student_id 
        $where_clause 
        GROUP BY sf.session_year, sf.fee_type 
        ORDER BY sf.session_year DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fee_summary_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    // Write headers
    fputcsv($output, ['Session Year', 'Fee Type', 'Number of Students', 'Total Fees (₦)', 'Total Paid (₦)', 'Outstanding Balance (₦)', 'Collection Rate (%)']);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['session_year'],
            $row['fee_type'],
            $row['student_count'],
            number_format($row['total_fees'], 2),
            number_format($row['total_paid'], 2),
            number_format($row['total_balance'], 2),
            $row['collection_rate'] . '%'
        ]);
    }
    
} else {
    $sql = "
        SELECT 
            sf.invoice_number,
            sf.session_year,
            sf.fee_type,
            sf.amount,
            sf.amount_paid,
            sf.balance,
            sf.status,
            sf.due_date,
            sf.created_date,
            s.matric_number, 
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name, 
            d.department_name, 
            d.department_code,
            p.program_name, 
            p.program_code
        FROM student_fees sf 
        JOIN students s ON sf.student_id = s.student_id 
        LEFT JOIN departments d ON s.department_id = d.department_id 
        LEFT JOIN programs p ON s.program_id = p.program_id 
        $where_clause 
        ORDER BY sf.due_date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payment_details_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    
    // Write headers
    fputcsv($output, [
        'Invoice Number', 'Matric Number', 'Student Name', 'Department', 'Program',
        'Session', 'Fee Type', 'Amount (₦)', 'Amount Paid (₦)', 'Balance (₦)', 
        'Status', 'Due Date', 'Created Date'
    ]);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['invoice_number'] ?? 'N/A',
            $row['matric_number'],
            $row['student_name'],
            $row['department_name'] ?? 'N/A',
            $row['program_name'] ?? 'N/A',
            $row['session_year'],
            $row['fee_type'],
            number_format($row['amount'], 2),
            number_format($row['amount_paid'] ?? 0, 2),
            number_format($row['balance'] ?? 0, 2),
            $row['status'],
            date('d/m/Y', strtotime($row['due_date'])),
            date('d/m/Y', strtotime($row['created_date']))
        ]);
    }
}

fclose($output);
exit();
?>