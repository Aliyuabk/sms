<?php
/**
 * Fee Statement Generator
 * Generates comprehensive fee statement for a student
 */

 

 require_once '../config/db.php';

// Validate student is logged in
$student_id = (int)($_GET['student_id'] ?? 0);
$session_year = $_GET['session'] ?? '';

// If no student_id in URL, try session
if (!$student_id && isset($_SESSION['student_id'])) {
    $student_id = (int)$_SESSION['student_id'];
}

if (!$student_id) {
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;">
        <h2>Access Denied</h2>
        <p>Please log in to view your statement.</p>
        <a href="login.php">Login</a>
    </body></html>');
}

if (empty($session_year)) {
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;">
        <h2>Missing Information</h2>
        <p>Session year is required.</p>
        <a href="fees.php">Back to Fees</a>
    </body></html>');
}

// Fetch student details
$student_query = "SELECT s.*, d.department_name, p.program_name, p.duration_years,
                         f.faculty_name
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
if (!$stmt) {
    die('Database error: ' . $conn->error);
}
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;">
        <h2>Student Not Found</h2>
        <p>No student record found for ID: ' . $student_id . '</p>
        <a href="fees.php">Back to Fees</a>
    </body></html>');
}

// Fetch all fees for the session
$fees_query = "SELECT * FROM student_fees 
               WHERE student_id = ? 
               AND session_year = ?
               ORDER BY semester, fee_id";
$stmt = $conn->prepare($fees_query);
if (!$stmt) {
    die('Database error: ' . $conn->error);
}
$stmt->bind_param("is", $student_id, $session_year);
$stmt->execute();
$fees_result = $stmt->get_result();
$stmt->close();

// Fetch all payments
$payments_query = "SELECT p.*, sf.fee_type, sf.description as fee_description
                   FROM payments p
                   LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                   WHERE p.student_id = ? 
                   AND p.status = 'Verified'
                   ORDER BY p.payment_date DESC";
$stmt = $conn->prepare($payments_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$payments_result = $stmt->get_result();
$stmt->close();

// Calculate totals
$total_fees = 0;
$total_paid = 0;
$fees = [];
$payments = [];

while ($f = $fees_result->fetch_assoc()) {
    $total_fees += (float)$f['amount'];
    $fees[] = $f;
}

while ($p = $payments_result->fetch_assoc()) {
    $total_paid += (float)$p['amount'];
    $payments[] = $p;
}

$balance = max(0, $total_fees - $total_paid);
$student_name = htmlspecialchars($student['first_name'] . ' ' . 
    ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . 
    $student['last_name']);

// Output the statement HTML
echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fee Statement - ' . htmlspecialchars($student['matric_number']) . '</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
            color: #333;
        }
        .statement-container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 12px;
            overflow: hidden;
        }
        .statement-header {
            background: linear-gradient(135deg, #1e5631, #2d7a4a);
            color: #fff;
            padding: 40px;
            text-align: center;
        }
        .statement-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            letter-spacing: 2px;
        }
        .statement-header .subtitle {
            opacity: 0.9;
            font-size: 16px;
        }
        .statement-header .session-badge {
            display: inline-block;
            margin-top: 15px;
            padding: 8px 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 14px;
        }
        .student-info {
            padding: 30px 40px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .info-item .label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .info-item .value {
            font-size: 15px;
            font-weight: 600;
            color: #1a1a1a;
        }
        .statement-body {
            padding: 40px;
        }
        .section {
            margin-bottom: 40px;
        }
        .section-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #1e5631;
            margin-bottom: 20px;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th {
            background: rgba(30, 86, 49, 0.08);
            color: #1e5631;
            font-weight: 600;
            padding: 12px 15px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }
        tr:hover td {
            background: #f8f9fa;
        }
        .amount {
            font-weight: 600;
            color: #1e5631;
        }
        .summary-box {
            background: linear-gradient(135deg, rgba(30, 86, 49, 0.05), rgba(30, 86, 49, 0.1));
            border: 2px solid #1e5631;
            border-radius: 12px;
            padding: 30px;
            margin-top: 30px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            text-align: center;
        }
        .summary-item h3 {
            font-size: 24px;
            color: #1e5631;
            margin-bottom: 5px;
        }
        .summary-item p {
            font-size: 13px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .summary-item.balance h3 {
            color: #dc3545;
        }
        .summary-item.balance.cleared h3 {
            color: #28a745;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-paid {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        .status-pending {
            background: rgba(255, 193, 7, 0.15);
            color: #d97706;
        }
        .statement-footer {
            background: #f8f9fa;
            padding: 30px 40px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .statement-footer p {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .actions {
            text-align: center;
            padding: 20px;
            background: #fff;
            border-top: 1px solid #e9ecef;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-print {
            background: #1e5631;
            color: #fff;
        }
        .btn-print:hover {
            background: #164026;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 40px;
            margin-bottom: 15px;
            display: block;
            color: #ccc;
        }
    </style>
</head>
<body>
    <div class="statement-container">
        <div class="statement-header">
            <h1><i class="fas fa-file-invoice-dollar"></i> FEE STATEMENT</h1>
            <p class="subtitle">5G EGURU SCHOOL</p>
            <span class="session-badge"><i class="fas fa-calendar"></i> ' . htmlspecialchars($session_year) . '</span>
        </div>

        <div class="student-info">
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">Student Name</span>
                    <span class="value">' . $student_name . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Matric Number</span>
                    <span class="value">' . htmlspecialchars($student['matric_number']) . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Department</span>
                    <span class="value">' . htmlspecialchars($student['department_name'] ?? 'N/A') . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Program</span>
                    <span class="value">' . htmlspecialchars($student['program_name'] ?? 'N/A') . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Level</span>
                    <span class="value">' . $student['current_level'] . '</span>
                </div>
                <div class="info-item">
                    <span class="label">Date Generated</span>
                    <span class="value">' . date('F d, Y') . '</span>
                </div>
            </div>
        </div>

        <div class="statement-body">
            <!-- Fees Section -->
            <div class="section">
                <div class="section-title"><i class="fas fa-list-alt"></i> Fee Breakdown</div>';

                if (count($fees) > 0) {
                    echo '<table>
                        <thead>
                            <tr>
                                <th>Semester</th>
                                <th>Fee Type</th>
                                <th>Description</th>
                                <th>Invoice</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th style="text-align:right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>';

                    foreach ($fees as $fee) {
                        $status_class = strtolower($fee['status']);
                        $status_label = 'status-pending';
                        if ($status_class === 'paid') $status_label = 'status-paid';
                        elseif ($status_class === 'partial') $status_label = 'status-pending';
                        elseif ($status_class === 'overdue') $status_label = 'status-pending';

                        echo '<tr>
                            <td>Semester ' . $fee['semester'] . '</td>
                            <td>' . htmlspecialchars($fee['fee_type'] ?? 'Tuition') . '</td>
                            <td>' . htmlspecialchars($fee['description'] ?? 'Session Fees') . '</td>
                            <td>' . htmlspecialchars($fee['invoice_number'] ?? 'N/A') . '</td>
                            <td>' . ($fee['due_date'] ? date('M d, Y', strtotime($fee['due_date'])) : 'N/A') . '</td>
                            <td><span class="status-badge ' . $status_label . '">' . $fee['status'] . '</span></td>
                            <td class="amount" style="text-align:right">&#8358;' . number_format($fee['amount'], 2) . '</td>
                        </tr>';
                    }

                    echo '</tbody></table>';
                } else {
                    echo '<div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <p>No fee records found for this session.</p>
                    </div>';
                }

                echo '
            </div>

            <!-- Payments Section -->
            <div class="section">
                <div class="section-title"><i class="fas fa-history"></i> Payment History</div>';

                if (count($payments) > 0) {
                    echo '<table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction ID</th>
                                <th>Receipt</th>
                                <th>Method</th>
                                <th style="text-align:right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>';

                    foreach ($payments as $payment) {
                        echo '<tr>
                            <td>' . date('M d, Y', strtotime($payment['payment_date'])) . '</td>
                            <td>' . htmlspecialchars($payment['transaction_id'] ?? 'N/A') . '</td>
                            <td>' . htmlspecialchars($payment['receipt_number'] ?? 'N/A') . '</td>
                            <td>' . htmlspecialchars($payment['payment_method'] ?? 'Online') . '</td>
                            <td class="amount" style="text-align:right">&#8358;' . number_format($payment['amount'], 2) . '</td>
                        </tr>';
                    }

                    echo '</tbody></table>';
                } else {
                    echo '<div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <p>No payment records found.</p>
                    </div>';
                }

                echo '
            </div>

            <!-- Summary -->
            <div class="summary-box">
                <div class="summary-grid">
                    <div class="summary-item">
                        <h3>&#8358;' . number_format($total_fees, 2) . '</h3>
                        <p>Total Fees</p>
                    </div>
                    <div class="summary-item">
                        <h3>&#8358;' . number_format($total_paid, 2) . '</h3>
                        <p>Total Paid</p>
                    </div>
                    <div class="summary-item balance ' . ($balance <= 0 ? 'cleared' : '') . '">
                        <h3>' . ($balance <= 0 ? 'CLEARED' : '&#8358;' . number_format($balance, 2)) . '</h3>
                        <p>' . ($balance <= 0 ? 'All Fees Paid' : 'Balance Due') . '</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="statement-footer">
            <p><i class="fas fa-info-circle"></i> This statement is computer generated and valid for official use.</p>
            <p>For inquiries, contact the Bursary Department.</p>
            <p style="margin-top: 10px; font-size: 11px; color: #999;">Generated on ' . date('F d, Y h:i A') . '</p>
        </div>

        <div class="actions no-print">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Statement
            </button>
            <a href="fees.php" class="btn btn-print" style="background:#6c757d; margin-left:10px;">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</body>
</html>';
?>