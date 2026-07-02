<?php
/**
 * Payment Receipt Generator
 * Generates and downloads payment receipts as PDF or HTML
 */
require_once '../config/db.php';

$receipt_number = $_GET['receipt'] ?? '';

if (empty($receipt_number)) {
    die('Receipt number required.');
}

// Fetch payment with student and fee details
$query = "SELECT p.*, s.first_name, s.last_name, s.middle_name, s.matric_number, 
                 s.email, s.phone, d.department_name, pr.program_name,
                 sf.fee_type, sf.description as fee_description, sf.session_year, sf.semester,
                 au.full_name as verified_by_name
          FROM payments p
          JOIN students s ON p.student_id = s.student_id
          LEFT JOIN departments d ON s.department_id = d.department_id
          LEFT JOIN programs pr ON s.program_id = pr.program_id
          LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
          LEFT JOIN admin_users au ON p.verified_by = au.admin_id
          WHERE p.receipt_number = ? 
          AND p.status = 'Verified'
          LIMIT 1";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $receipt_number);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();
$stmt->close();

if (!$payment) {
    die('Receipt not found or payment not yet verified.');
}

// Generate receipt HTML
$student_name = htmlspecialchars($payment['first_name'] . ' ' . 
    ($payment['middle_name'] ? $payment['middle_name'] . ' ' : '') . 
    $payment['last_name']);
$receipt_num = htmlspecialchars($payment['receipt_number']);
$txn_id = htmlspecialchars($payment['transaction_id']);
$invoice = htmlspecialchars($payment['invoice_number'] ?? 'N/A');
$pay_date = date('F d, Y \a\t h:i A', strtotime($payment['payment_date']));
$pay_method = htmlspecialchars($payment['payment_method'] ?? 'Online');
$matric = htmlspecialchars($payment['matric_number']);
$email = htmlspecialchars($payment['email']);
$dept = htmlspecialchars($payment['department_name'] ?? 'N/A');
$prog = htmlspecialchars($payment['program_name'] ?? 'N/A');
$session_yr = htmlspecialchars($payment['session_year'] ?? 'N/A');
$semester = $payment['semester'] ?? 'N/A';
$fee_type = htmlspecialchars($payment['fee_type'] ?? 'Tuition');
$fee_desc = htmlspecialchars($payment['fee_description'] ?? 'Session Fees');
$amount = number_format($payment['amount'], 2);
$verifier = htmlspecialchars($payment['verified_by_name'] ?? 'System');
$ver_date = $payment['verification_date'] ? date('F d, Y', strtotime($payment['verification_date'])) : 'Pending';
$status = strtoupper($payment['status']);
$generated = date('F d, Y h:i A');

echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - ' . $receipt_num . '</title>
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
        .receipt-container {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 12px;
            overflow: hidden;
        }
        .receipt-header {
            background: linear-gradient(135deg, #1e5631, #2d7a4a);
            color: #fff;
            padding: 40px;
            text-align: center;
        }
        .receipt-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            letter-spacing: 2px;
        }
        .receipt-header .subtitle {
            opacity: 0.9;
            font-size: 14px;
        }
        .receipt-header .paid-stamp {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 30px;
            border: 3px solid #fff;
            border-radius: 8px;
            font-weight: bold;
            font-size: 18px;
            letter-spacing: 3px;
            transform: rotate(-5deg);
            opacity: 0.9;
        }
        .receipt-body {
            padding: 40px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #1e5631;
            margin-bottom: 15px;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #6c757d;
            font-size: 14px;
        }
        .detail-value {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 14px;
        }
        .amount-box {
            background: linear-gradient(135deg, rgba(30, 86, 49, 0.05), rgba(30, 86, 49, 0.1));
            border: 2px solid #1e5631;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            margin: 30px 0;
        }
        .amount-box .label {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 10px;
            display: block;
        }
        .amount-box .amount {
            font-size: 36px;
            font-weight: 800;
            color: #1e5631;
        }
        .receipt-footer {
            background: #f8f9fa;
            padding: 30px 40px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .receipt-footer p {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .barcode {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            font-family: "Courier New", monospace;
            font-size: 14px;
            letter-spacing: 3px;
            border: 1px dashed #ccc;
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
        .btn-download {
            background: transparent;
            color: #1e5631;
            border: 2px solid #1e5631;
            margin-left: 10px;
        }
        .btn-download:hover {
            background: #1e5631;
            color: #fff;
        }
        .qr-placeholder {
            width: 100px;
            height: 100px;
            background: #f0f0f0;
            margin: 15px auto;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #ccc;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1><i class="fas fa-university"></i> PAYMENT RECEIPT</h1>
            <p class="subtitle">Student Management System</p>
            <div class="paid-stamp">PAID</div>
        </div>

        <div class="receipt-body">
            <div class="section">
                <div class="section-title">Receipt Information</div>
                <div class="detail-row">
                    <span class="detail-label">Receipt Number</span>
                    <span class="detail-value">' . $receipt_num . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Transaction ID</span>
                    <span class="detail-value">' . $txn_id . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Invoice Number</span>
                    <span class="detail-value">' . $invoice . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Date</span>
                    <span class="detail-value">' . $pay_date . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <span class="detail-value">' . $pay_method . '</span>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Student Information</div>
                <div class="detail-row">
                    <span class="detail-label">Full Name</span>
                    <span class="detail-value">' . $student_name . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Matric Number</span>
                    <span class="detail-value">' . $matric . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <span class="detail-value">' . $email . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Department</span>
                    <span class="detail-value">' . $dept . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Program</span>
                    <span class="detail-value">' . $prog . '</span>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Fee Details</div>
                <div class="detail-row">
                    <span class="detail-label">Academic Session</span>
                    <span class="detail-value">' . $session_yr . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Semester</span>
                    <span class="detail-value">' . $semester . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Fee Type</span>
                    <span class="detail-value">' . $fee_type . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Description</span>
                    <span class="detail-value">' . $fee_desc . '</span>
                </div>
            </div>

            <div class="amount-box">
                <span class="label">Amount Paid</span>
                <span class="amount">&#8358;' . $amount . '</span>
            </div>

            <div class="section">
                <div class="section-title">Verification Details</div>
                <div class="detail-row">
                    <span class="detail-label">Verified By</span>
                    <span class="detail-value">' . $verifier . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Verification Date</span>
                    <span class="detail-value">' . $ver_date . '</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="detail-value" style="color: #28a745; font-weight: 700;">' . $status . '</span>
                </div>
            </div>

            <div class="qr-placeholder">
                <i class="fas fa-qrcode"></i>
            </div>
            <p style="text-align: center; color: #999; font-size: 12px;">Scan to verify authenticity</p>
        </div>

        <div class="receipt-footer">
            <div class="barcode">' . $receipt_num . '</div>
            <p>This receipt is computer generated and valid without signature.</p>
            <p>For inquiries, contact: bursary@university.edu | +234 000 000 0000</p>
            <p style="margin-top: 10px; font-size: 11px; color: #999;">Generated on ' . $generated . '</p>
        </div>

        <div class="actions no-print">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <a href="fees.php" class="btn btn-download">
                <i class="fas fa-arrow-left"></i> Back to Fees
            </a>
        </div>
    </div>
</body>
</html>';
?>