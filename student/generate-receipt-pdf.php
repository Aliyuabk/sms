<?php
/**
 * generate-receipt-pdf.php
 * Generates a PDF receipt matching the uploaded receipt format
 */

require_once '../config/db.php';

$student_id = $_SESSION['student_id'] ?? 0;
if (!$student_id) {
    die('Unauthorized');
}

$ref = $_GET['ref'] ?? '';
$session = $_GET['session'] ?? '2025/2026';
$download = isset($_GET['download']) ? true : false;

// Get student info
$student_query = "SELECT s.*, d.department_name, p.program_name, f.faculty_name
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get payment info
$payment_query = "SELECT p.*, sf.fee_type, sf.description 
                  FROM payments p
                  LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                  WHERE p.student_id = ? AND p.transaction_id = ?
                  ORDER BY p.payment_date DESC LIMIT 1";
$stmt = $conn->prepare($payment_query);
$stmt->bind_param("is", $student_id, $ref);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

// If no specific ref, get latest
if (!$payment) {
    $payment_query = "SELECT p.*, sf.fee_type, sf.description 
                      FROM payments p
                      LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                      WHERE p.student_id = ? AND p.status = 'Verified'
                      ORDER BY p.payment_date DESC LIMIT 1";
    $stmt = $conn->prepare($payment_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
}

$amount = $payment ? (float)$payment['amount'] : 0;
$total_fees = $amount;
$balance = 0;

// Set filename
$filename = 'Receipt_' . ($student['matric_number'] ?? 'STUDENT') . '_' . date('Ymd') . '.pdf';

// Check if TCPDF or FPDF is available
if (class_exists('TCPDF')) {
    // Use TCPDF
    generateTCPDF($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download);
} elseif (class_exists('FPDF')) {
    // Use FPDF
    generateFPDF($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download);
} else {
    // Fallback: Generate HTML receipt that can be printed to PDF
    generateHTMLReceipt($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download);
}

/**
 * HTML Receipt Generator (works without PDF libraries)
 */
function generateHTMLReceipt($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download) {
    $student_name = strtoupper($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? 'Student');
    $matric = $student['matric_number'] ?? 'N/A';
    $dept = $student['department_name'] ?? 'Computing';
    $program = $student['program_name'] ?? 'Computer Science';
    $lga = $student['lga'] ?? 'Birnin Kudu';
    $state = $student['state_of_origin'] ?? 'Jigawa';
    $nationality = $student['nationality'] ?? 'Nigerian';
    $ref_no = $payment['transaction_id'] ?? 'N/A';
    $date = $payment ? date('d/m/Y', strtotime($payment['payment_date'])) : date('d/m/Y');
    $fee_desc = $payment['fee_type'] ?? 'RETURNING STUDENTS FEES';

    header('Content-Type: text/html; charset=utf-8');
    if ($download) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Receipt - <?php echo htmlspecialchars($matric); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #fff;
            color: #000;
            line-height: 1.4;
        }
        .receipt-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 40px;
            border: 2px solid #000;
        }
        .header-section {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .scan-text {
            font-size: 11px;
            color: #666;
            margin-bottom: 5px;
        }
        .uni-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .uni-address {
            font-size: 12px;
            margin-bottom: 10px;
        }
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            text-decoration: underline;
            margin: 15px 0;
        }
        .session-text {
            font-size: 14px;
            font-weight: bold;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
            font-size: 13px;
        }
        .info-item {
            display: flex;
            gap: 5px;
        }
        .info-label {
            font-weight: bold;
            min-width: 100px;
        }
        .info-value {
            border-bottom: 1px solid #000;
            flex: 1;
            padding-bottom: 2px;
        }
        .table-section {
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background: #f0f0f0;
            font-weight: bold;
        }
        .amount-col { text-align: right; }
        .total-row {
            font-weight: bold;
            background: #f0f0f0;
        }
        .summary-section {
            margin-top: 20px;
            font-size: 13px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #ccc;
        }
        .summary-row.total {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            margin-top: 5px;
            padding-top: 8px;
        }
        .footer-section {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            font-size: 12px;
        }
        .footer-item {
            text-align: center;
        }
        .footer-line {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
        }
        .qr-section {
            text-align: center;
            margin-bottom: 15px;
        }
        .qr-section img {
            width: 100px;
            height: 100px;
        }
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #1a4db5;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        @media print {
            .print-btn { display: none; }
            body { background: #fff; }
            .receipt-container { border: 2px solid #000; }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print / Save as PDF
    </button>

    <div class="receipt-container">
        <div class="header-section">
            <div class="scan-text">Scan to verify</div>
            <div class="qr-section">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($ref_no); ?>" alt="QR">
            </div>
            <!-- <div class="uni-name">Abubakar Tafawa Balewa University, Bauchi</div>
            <div class="uni-address">P.M.B. 0248, Bauchi</div> -->
            <div class="session-text"><?php echo htmlspecialchars($session); ?> Academic Session</div>
            <div class="receipt-title">Student's Receipt</div>
            <div class="scan-text">Scan to verify</div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Date:</span>
                <span class="info-value"><?php echo htmlspecialchars($date); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Reg. No.:</span>
                <span class="info-value"><?php echo htmlspecialchars($matric); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Name:</span>
                <span class="info-value"><?php echo htmlspecialchars($student_name); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Session of Adm.:</span>
                <span class="info-value"><?php echo htmlspecialchars($student['admission_year'] ?? '2024/2025'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Dept./Course:</span>
                <span class="info-value"><?php echo htmlspecialchars($dept); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">LGA:</span>
                <span class="info-value"><?php echo htmlspecialchars($lga); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">State:</span>
                <span class="info-value"><?php echo htmlspecialchars($state); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">School:</span>
                <span class="info-value"><?php echo htmlspecialchars($student['faculty_name'] ?? 'Computing'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Nationality:</span>
                <span class="info-value"><?php echo htmlspecialchars($nationality); ?></span>
            </div>
        </div>

        <div class="table-section">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Description</th>
                        <th class="amount-col">Amount (N)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td><?php echo htmlspecialchars($fee_desc); ?></td>
                        <td class="amount-col"><?php echo number_format($amount, 2); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="2"><strong>Total (N)</strong></td>
                        <td class="amount-col"><strong><?php echo number_format($amount, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="summary-section">
            <div class="summary-row total">
                <span>Total Amount Paid (N)</span>
                <span><?php echo number_format($amount, 2); ?></span>
            </div>
            <div class="summary-row">
                <span>Balance (N)</span>
                <span><?php echo number_format($balance, 2); ?></span>
            </div>
        </div>

        <div class="footer-section">
            <div class="footer-item">
                <div class="footer-line">Receiving Cashier's Name</div>
            </div>
            <div class="footer-item">
                <div class="footer-line">Signature</div>
            </div>
            <div class="footer-item">
                <div class="footer-line">Official Stamp</div>
            </div>
        </div>
    </div>

    <script>
        // Auto-print if download requested
        <?php if ($download): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>
</body>
</html>
    <?php
    exit;
}

/**
 * TCPDF Generator (if library is installed)
 */
function generateTCPDF($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download) {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SMS');
    $pdf->SetAuthor('University');
    $pdf->SetTitle('Student Receipt');
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    // Content generation...
    $pdf->Output($filename, $download ? 'D' : 'I');
    exit;
}

/**
 * FPDF Generator (if library is installed)
 */
function generateFPDF($student, $payment, $session, $amount, $total_fees, $balance, $filename, $download) {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);

    // Content generation...
    $pdf->Output($download ? 'D' : 'I', $filename);
    exit;
}