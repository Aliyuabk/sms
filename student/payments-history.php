<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: ../');
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'sms';

// Initialize variables
$student_data = [];
$payments = [];
$fees_summary = [];
$filtered_payments = [];
$success = '';
$error = '';
$total_paid = 0;
$total_balance = 0;

// Filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';
$filter_year = $_GET['year'] ?? '';

try {
    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Get student ID from session
    $student_id = $_SESSION['student_id'];
    
    // Fetch student information
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $student_data = $result->fetch_assoc();
        $stmt->close();
        
        // Get payment history
        $payments_sql = "SELECT p.*, sf.fee_type, sf.description, sf.session_year, 
                         sf.invoice_number as fee_invoice_number,
                         CONCAT(st.first_name, ' ', st.last_name) as verified_by_name
                         FROM payments p
                         JOIN student_fees sf ON p.fee_id = sf.fee_id
                         LEFT JOIN students st ON p.verified_by = st.student_id
                         WHERE p.student_id = ?
                         ORDER BY p.payment_date DESC";
        
        $payments_stmt = $conn->prepare($payments_sql);
        $payments_stmt->bind_param("i", $student_id);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        
        while ($payment = $payments_result->fetch_assoc()) {
            $payments[] = $payment;
            if ($payment['status'] === 'Verified') {
                $total_paid += $payment['amount'];
            }
        }
        $payments_stmt->close();
        
        // Apply filters
        $filtered_payments = $payments;
        if ($filter_status !== 'all') {
            $filtered_payments = array_filter($filtered_payments, function($payment) use ($filter_status) {
                return $payment['status'] === $filter_status;
            });
        }
        
        if ($filter_type !== 'all') {
            $filtered_payments = array_filter($filtered_payments, function($payment) use ($filter_type) {
                return strpos(strtolower($payment['fee_type']), strtolower($filter_type)) !== false;
            });
        }
        
        if ($filter_start_date && $filter_end_date) {
            $start = strtotime($filter_start_date);
            $end = strtotime($filter_end_date);
            $filtered_payments = array_filter($filtered_payments, function($payment) use ($start, $end) {
                $payment_date = strtotime($payment['payment_date']);
                return $payment_date >= $start && $payment_date <= $end;
            });
        }
        
        if ($filter_year) {
            $filtered_payments = array_filter($filtered_payments, function($payment) use ($filter_year) {
                return date('Y', strtotime($payment['payment_date'])) == $filter_year;
            });
        }
        
        // Get fees summary
        $fees_sql = "SELECT 
                     SUM(amount) as total_fees,
                     SUM(amount_paid) as total_paid,
                     SUM(balance) as total_balance,
                     COUNT(*) as total_invoices,
                     COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid_invoices,
                     COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_invoices,
                     COUNT(CASE WHEN status = 'Partial' THEN 1 END) as partial_invoices,
                     COUNT(CASE WHEN status = 'Overdue' THEN 1 END) as overdue_invoices
                     FROM student_fees
                     WHERE student_id = ?";
        
        $fees_stmt = $conn->prepare($fees_sql);
        $fees_stmt->bind_param("i", $student_id);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        
        if ($fees_result->num_rows > 0) {
            $fees_summary = $fees_result->fetch_assoc();
            $total_balance = $fees_summary['total_balance'] ?? 0;
        }
        $fees_stmt->close();
        
        // Get distinct years for filter
        $years_sql = "SELECT DISTINCT YEAR(payment_date) as year 
                     FROM payments 
                     WHERE student_id = ? 
                     ORDER BY year DESC";
        $years_stmt = $conn->prepare($years_sql);
        $years_stmt->bind_param("i", $student_id);
        $years_stmt->execute();
        $years_result = $years_stmt->get_result();
        $years = [];
        while ($year = $years_result->fetch_assoc()) {
            $years[] = $year['year'];
        }
        $years_stmt->close();
        
        // Get distinct fee types for filter
        $types_sql = "SELECT DISTINCT fee_type 
                     FROM student_fees sf
                     JOIN payments p ON sf.fee_id = p.fee_id
                     WHERE p.student_id = ?
                     ORDER BY fee_type";
        $types_stmt = $conn->prepare($types_sql);
        $types_stmt->bind_param("i", $student_id);
        $types_stmt->execute();
        $types_result = $types_stmt->get_result();
        $fee_types = [];
        while ($type = $types_result->fetch_assoc()) {
            $fee_types[] = $type['fee_type'];
        }
        $types_stmt->close();
        
    } else {
        throw new Exception("Student record not found.");
    }
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("Payments history error: " . $e->getMessage());
    $error = "System error: " . $e->getMessage();
    // Fallback data for demo
    $student_data = [
        'first_name' => $_SESSION['student_name'] ?? 'Student',
        'last_name' => 'User',
        'matric_number' => $_SESSION['matric_number'] ?? 'CSC/2023/001',
        'department_name' => 'Computer Science',
        'program_name' => 'B.Sc. Computer Science'
    ];
    
    // Demo payments data
    $payments = [
        [
            'payment_id' => 1,
            'amount' => 150000,
            'payment_method' => 'Bank Transfer',
            'transaction_id' => 'TRX0012345678',
            'payment_date' => '2023-09-15 10:30:00',
            'receipt_number' => 'REC-2023-001',
            'status' => 'Verified',
            'fee_type' => 'Tuition Fee',
            'description' => 'Undergraduate tuition fee',
            'session_year' => '2023/2024',
            'fee_invoice_number' => 'INV-2023-CSC-001'
        ],
        [
            'payment_id' => 2,
            'amount' => 5000,
            'payment_method' => 'Online',
            'transaction_id' => 'TRX0012345679',
            'payment_date' => '2023-10-05 14:20:00',
            'receipt_number' => 'REC-2023-002',
            'status' => 'Verified',
            'fee_type' => 'Registration Fee',
            'description' => 'Student registration fee',
            'session_year' => '2023/2024',
            'fee_invoice_number' => 'INV-2023-CSC-002'
        ]
    ];
    
    $filtered_payments = $payments;
    $total_paid = 155000;
    $total_balance = 15000;
    
    $years = [2024, 2023, 2022];
    $fee_types = ['Tuition Fee', 'Registration Fee', 'Medical Fee', 'Hostel Fee'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History | Student Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/paymentshostory.css">

</head>
<body>
     <?php include 'includes/preloader.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Payment History</h1>
                <p>View and manage your payment records</p>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="payment.php" class="btn btn-primary">
                    <i class="fas fa-credit-card"></i> Make Payment
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?php echo $success; ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i>
            <div><?php echo $error; ?></div>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="summary-value">₦<?php echo number_format($total_paid, 2); ?></div>
                <div class="summary-label">Total Paid</div>
            </div>
            
            <div class="summary-card info">
                <div class="summary-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="summary-value"><?php echo count($payments); ?></div>
                <div class="summary-label">Total Payments</div>
            </div>
            
            <div class="summary-card <?php echo $total_balance > 0 ? 'warning' : 'success'; ?>">
                <div class="summary-icon">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <div class="summary-value">₦<?php echo number_format($total_balance, 2); ?></div>
                <div class="summary-label">Current Balance</div>
            </div>
            
            <div class="summary-card success">
                <div class="summary-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-value">
                    <?php 
                    $verified_count = count(array_filter($payments, function($p) { return $p['status'] === 'Verified'; }));
                    echo $verified_count . '/' . count($payments);
                    ?>
                </div>
                <div class="summary-label">Verified Payments</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-header">
                <h2>Filter Payments</h2>
                <div style="font-size: 14px; color: var(--gray);">
                    <?php echo count($filtered_payments); ?> payments found
                </div>
            </div>
            
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label for="statusFilter">Payment Status</label>
                    <select name="status" id="statusFilter" class="form-control">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Verified" <?php echo $filter_status === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Failed" <?php echo $filter_status === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="typeFilter">Fee Type</label>
                    <select name="type" id="typeFilter" class="form-control">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($fee_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" 
                            <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="yearFilter">Year</label>
                    <select name="year" id="yearFilter" class="form-control">
                        <option value="" <?php echo empty($filter_year) ? 'selected' : ''; ?>>All Years</option>
                        <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Date Range</label>
                    <div class="date-range">
                        <input type="date" name="start_date" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_start_date); ?>" 
                               placeholder="Start Date">
                        <input type="date" name="end_date" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_end_date); ?>" 
                               placeholder="End Date">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="payments-history.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Payments Section -->
        <div class="payments-section">
            <div class="section-header">
                <h2>Payment History</h2>
                <p>All your payment transactions</p>
            </div>

            <div class="payments-table-container">
                <?php if (empty($filtered_payments)): ?>
                <div class="no-payments">
                    <i class="fas fa-receipt"></i>
                    <h3>No Payments Found</h3>
                    <p><?php echo !empty($filter_status) && $filter_status !== 'all' ? 
                        "No payments found with status: {$filter_status}" : 
                        "No payment records found."; ?></p>
                    <?php if (!empty($filter_status) || !empty($filter_type) || !empty($filter_year)): ?>
                    <a href="payments-history.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-eye"></i> View All Payments
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Transaction Details</th>
                            <th>Fee Type</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filtered_payments as $payment): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: var(--dark);">
                                    <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray);">
                                    <?php echo date('h:i A', strtotime($payment['payment_date'])); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--dark);">
                                    <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray); margin-top: 3px;">
                                    <?php if (!empty($payment['transaction_id'])): ?>
                                    <div>Ref: <?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                                    <?php endif; ?>
                                    <div>Session: <?php echo htmlspecialchars($payment['session_year'] ?? 'N/A'); ?></div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--dark);">
                                    <?php echo htmlspecialchars($payment['fee_type']); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray); margin-top: 3px;">
                                    <?php echo htmlspecialchars(substr($payment['description'] ?? '', 0, 30)); ?>
                                    <?php if (strlen($payment['description'] ?? '') > 30): ?>...<?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="amount">₦<?php echo number_format($payment['amount'], 2); ?></div>
                            </td>
                            <td>
                                <span class="payment-method">
                                    <?php 
                                    $method_icons = [
                                        'Bank Transfer' => 'fas fa-university',
                                        'Online' => 'fas fa-globe',
                                        'Cash' => 'fas fa-money-bill',
                                        'Card' => 'fas fa-credit-card',
                                        'Bank Draft' => 'fas fa-file-contract',
                                        'Cheque' => 'fas fa-money-check'
                                    ];
                                    $icon = $method_icons[$payment['payment_method']] ?? 'fas fa-money-bill-wave';
                                    ?>
                                    <i class="<?php echo $icon; ?>"></i>
                                    <?php echo htmlspecialchars($payment['payment_method']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($payment['status']); ?>">
                                    <?php echo $payment['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <button class="btn btn-sm btn-secondary" 
                                            onclick="viewReceipt(<?php echo htmlspecialchars(json_encode($payment)); ?>)">
                                        <i class="fas fa-receipt"></i> Receipt
                                    </button>
                                    <?php if ($payment['status'] === 'Verified'): ?>
                                    <button class="btn btn-sm btn-success" onclick="printReceipt('<?php echo $payment['receipt_number']; ?>')">
                                        <i class="fas fa-print"></i> Print
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Export Buttons -->
                <div class="export-buttons">
                    <button class="btn btn-secondary" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export to PDF
                    </button>
                    <button class="btn btn-secondary" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export to CSV
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; flex-wrap: wrap;">
            <a href="student-fees.php" class="btn btn-primary">
                <i class="fas fa-file-invoice-dollar"></i> View Invoices
            </a>
            <a href="payments.php" class="btn btn-secondary">
                <i class="fas fa-credit-card"></i> Make New Payment
            </a>
            <a href="financial-statements.php" class="btn btn-secondary">
                <i class="fas fa-chart-bar"></i> Financial Statements
            </a>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="receipt-modal" id="receiptModal">
        <div class="receipt-content">
            <div class="receipt-header">
                <h2 style="color: var(--primary); margin-bottom: 10px;">Payment Receipt</h2>
                <p style="color: var(--gray);">Official payment confirmation</p>
            </div>
            
            <div class="receipt-details" id="receiptDetails">
                <!-- Receipt details will be inserted here by JavaScript -->
            </div>
            
            <div class="receipt-actions">
                <button class="btn btn-primary" onclick="printCurrentReceipt()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <button class="btn btn-secondary" onclick="closeReceiptModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
    // Receipt Modal Functions
    function viewReceipt(payment) {
        const modal = document.getElementById('receiptModal');
        const details = document.getElementById('receiptDetails');
        
        // Format date
        const paymentDate = new Date(payment.payment_date);
        const formattedDate = paymentDate.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        // Build receipt HTML
        details.innerHTML = `
            <div class="receipt-detail">
                <span class="receipt-label">Receipt Number:</span>
                <span class="receipt-value">${payment.receipt_number}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Transaction ID:</span>
                <span class="receipt-value">${payment.transaction_id || 'N/A'}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Date & Time:</span>
                <span class="receipt-value">${formattedDate}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Student:</span>
                <span class="receipt-value"><?php echo htmlspecialchars($student_data['first_name'] ?? '') . ' ' . htmlspecialchars($student_data['last_name'] ?? ''); ?></span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Matric Number:</span>
                <span class="receipt-value"><?php echo htmlspecialchars($student_data['matric_number'] ?? ''); ?></span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Fee Type:</span>
                <span class="receipt-value">${payment.fee_type}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Description:</span>
                <span class="receipt-value">${payment.description || 'N/A'}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Academic Session:</span>
                <span class="receipt-value">${payment.session_year || 'N/A'}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Payment Method:</span>
                <span class="receipt-value">${payment.payment_method}</span>
            </div>
            <div class="receipt-detail">
                <span class="receipt-label">Status:</span>
                <span class="receipt-value" style="color: ${payment.status === 'Verified' ? 'var(--success)' : 
                                                       payment.status === 'Pending' ? 'var(--warning)' : 'var(--danger)'}">
                    ${payment.status}
                </span>
            </div>
            <?php if (!empty($payment['verified_by_name'])): ?>
            <div class="receipt-detail">
                <span class="receipt-label">Verified By:</span>
                <span class="receipt-value"><?php echo htmlspecialchars($payment['verified_by_name']); ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-detail" style="border-top: 2px solid var(--primary); margin-top: 20px; padding-top: 20px;">
                <span class="receipt-label">Amount Paid:</span>
                <span class="receipt-value receipt-amount">₦${parseFloat(payment.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
            </div>
            <div style="margin-top: 30px; padding: 15px; background: var(--light); border-radius: var(--border-radius-sm);">
                <p style="color: var(--gray); font-size: 12px; text-align: center;">
                    <i class="fas fa-shield-alt"></i> This is an official receipt from Al-Qalam University.<br>
                    Please keep this receipt for your records.
                </p>
            </div>
        `;
        
        modal.style.display = 'flex';
        modal.dataset.currentReceipt = payment.receipt_number;
    }
    
    function closeReceiptModal() {
        document.getElementById('receiptModal').style.display = 'none';
    }
    
    function printCurrentReceipt() {
        const receiptNumber = document.getElementById('receiptModal').dataset.currentReceipt;
        printReceipt(receiptNumber);
    }
    
    function printReceipt(receiptNumber) {
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Receipt ${receiptNumber}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .details { margin-bottom: 30px; }
                    .detail { display: flex; justify-content: space-between; margin-bottom: 10px; }
                    .amount { font-size: 24px; font-weight: bold; color: #4361ee; text-align: right; }
                    .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Al-Qalam University</h2>
                    <h3>Payment Receipt</h3>
                    <p>Receipt No: ${receiptNumber}</p>
                </div>
                <div class="details">
                    <p>Payment details will be printed here...</p>
                </div>
                <div class="footer">
                    <p>This is an official receipt. Keep for your records.</p>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
    
    // Export Functions
    function exportToPDF() {
        alert('PDF export functionality would be implemented here.\nIn a real application, this would generate a PDF report.');
        // In real application, you would:
        // 1. Send an AJAX request to generate PDF
        // 2. Use libraries like TCPDF or mPDF on server side
        // 3. Return the PDF file for download
    }
    
    function exportToCSV() {
        // Get table data
        const table = document.querySelector('.payments-table');
        if (!table) {
            alert('No data to export');
            return;
        }
        
        let csv = [];
        const rows = table.querySelectorAll('tr');
        
        for (let i = 0; i < rows.length; i++) {
            const row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length - 1; j++) { // Skip actions column
                // Clean the text content
                let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s)/gm, " ");
                text = text.replace(/"/g, '""'); // Escape double quotes
                row.push('"' + text + '"');
            }
            
            csv.push(row.join(","));
        }
        
        // Download CSV file
        const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "payment_history_<?php echo date('Y-m-d'); ?>.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    // Close modal when clicking outside
    document.getElementById('receiptModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReceiptModal();
        }
    });
    
    // Date range validation
    document.addEventListener('DOMContentLoaded', function() {
        const startDate = document.querySelector('input[name="start_date"]');
        const endDate = document.querySelector('input[name="end_date"]');
        
        if (startDate && endDate) {
            startDate.addEventListener('change', function() {
                if (this.value && endDate.value && this.value > endDate.value) {
                    endDate.value = this.value;
                }
            });
            
            endDate.addEventListener('change', function() {
                if (this.value && startDate.value && this.value < startDate.value) {
                    startDate.value = this.value;
                }
            });
        }
        
        // Set default dates for this month
        const today = new Date();
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        
        if (!startDate.value) {
            startDate.value = firstDay.toISOString().split('T')[0];
        }
        if (!endDate.value) {
            endDate.value = lastDay.toISOString().split('T')[0];
        }
    });
    </script>
</body>
</html>