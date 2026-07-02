<?php
require_once 'includes/header.php';

// Check if invoice ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_fees.php');
    exit();
}

$fee_id = (int)$_GET['id'];

// Get invoice details
$sql = "
    SELECT sf.*, 
           s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
           s.current_level, s.current_session, s.department_id, s.program_id,
           d.department_name,
           p.program_name,
           a.full_name as recorded_by_name
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN admin_users a ON sf.created_date = a.admin_id
    WHERE sf.fee_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$fee_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error_message'] = "Invoice not found!";
    header('Location: manage_fees.php');
    exit();
}

// Get payment history for this invoice
$payments = $pdo->prepare("
    SELECT * FROM payments 
    WHERE fee_id = ? 
    ORDER BY payment_date DESC
");
$payments->execute([$fee_id]);
$payment_history = $payments->fetchAll();

$page_title = "Invoice #" . htmlspecialchars($invoice['invoice_number'] ?? 'N/A');
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Invoice Details</h1>
            <p class="text-muted">Invoice #<?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?></p>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <!-- Invoice Details -->
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-body p-4">
                <!-- Invoice Header -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h4 class="text-primary mb-1">5G EGURUSCHOOL PORTAL</h4>
                        <p class="text-muted mb-0">Jigawa State, Nigeria</p> 
                    </div>
                    <div class="col-md-6 text-end">
                        <h3 class="mb-1">INVOICE</h3>
                        <p class="text-muted mb-0">#<?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?></p>
                        <p class="text-muted mb-0">Date: <?php echo date('F j, Y', strtotime($invoice['created_date'])); ?></p>
                    </div>
                </div>
                
                <!-- Student Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6>Bill To:</h6>
                        <p class="mb-0">
                            <strong><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></strong><br>
                            <?php echo htmlspecialchars($invoice['matric_number']); ?><br>
                            <?php echo htmlspecialchars($invoice['department_name'] ?? 'N/A'); ?><br>
                            <?php echo htmlspecialchars($invoice['program_name'] ?? 'N/A'); ?><br>
                            Level: <?php echo $invoice['current_level']; ?> | Session: <?php echo htmlspecialchars($invoice['current_session']); ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h6>Contact:</h6>
                        <p class="mb-0">
                            Email: <?php echo htmlspecialchars($invoice['email']); ?><br>
                            Phone: <?php echo htmlspecialchars($invoice['phone'] ?? 'N/A'); ?>
                        </p>
                    </div>
                </div>
                
                <!-- Invoice Items -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th width="60%">Description</th>
                                <th width="20%">Session</th>
                                <th width="20%" class="text-end">Amount (₦)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($invoice['fee_type']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($invoice['description']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($invoice['session_year'] ?? $invoice['current_session']); ?></td>
                                <td class="text-end">₦<?php echo number_format($invoice['amount'], 2); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-end"><strong>Total:</strong></td>
                                <td class="text-end"><strong>₦<?php echo number_format($invoice['amount'], 2); ?></strong></td>
                            </tr>
                            <tr>
                                <td colspan="2" class="text-end"><strong>Amount Paid:</strong></td>
                                <td class="text-end text-success">₦<?php echo number_format($invoice['amount_paid'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="2" class="text-end"><strong>Balance Due:</strong></td>
                                <td class="text-end <?php echo $invoice['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <strong>₦<?php echo number_format($invoice['balance'], 2); ?></strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Payment Terms -->
                <div class="row">
                    <div class="col-md-6">
                        <h6>Payment Terms:</h6>
                        <p class="mb-0">
                            Due Date: <strong><?php echo $invoice['due_date'] ? date('F j, Y', strtotime($invoice['due_date'])) : 'Not specified'; ?></strong><br>
                            Status: 
                            <?php if ($invoice['status'] === 'Paid'): ?>
                                <span class="badge bg-success">Paid in Full</span>
                            <?php elseif ($invoice['status'] === 'Partial'): ?>
                                <span class="badge bg-warning">Partially Paid</span>
                            <?php elseif ($invoice['status'] === 'Overdue'): ?>
                                <span class="badge bg-danger">Overdue</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Pending</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <h6>Payment Instructions:</h6>
                        <p class="mb-0 small text-muted">
                            Make payments to:<br>
                            Bank: First Bank Nigeria<br>
                            Account: 1234567890<br>
                            Account Name: Al-Qalam University<br>
                            Reference: <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment History -->
        <?php if (!empty($payment_history)): ?>
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Payment History</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Receipt No.</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payment_history as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'N/A'); ?></td>
                                <td class="text-success">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payment['transaction_id'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($payment['status'] === 'Verified'): ?>
                                        <span class="badge bg-success">Verified</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><?php echo $payment['status']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Sidebar -->
    <div class="col-md-4">
        <!-- Invoice Actions -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Invoice Actions</h6>
                <div class="d-grid gap-2">
                    <a href="record_payment.php?fee_id=<?php echo $fee_id; ?>" class="btn btn-success">
                        <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                    </a>
                    <a href="edit_fee.php?id=<?php echo $fee_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Invoice
                    </a>
                    <button onclick="printInvoice()" class="btn btn-outline-info">
                        <i class="fas fa-print me-2"></i>Print Invoice
                    </button> 
                    <a href="manage_fees.php" class="btn btn-outline-dark mt-3">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Invoice Status -->
        <div class="app-card app-card-details shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Invoice Status</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Payment Progress</span>
                        <span><?php echo number_format(($invoice['amount_paid'] / $invoice['amount']) * 100, 1); ?>%</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" 
                             style="width: <?php echo ($invoice['amount_paid'] / $invoice['amount']) * 100; ?>%"></div>
                    </div>
                </div>
                
                <dl class="row mb-0">
                    <dt class="col-6">Created:</dt>
                    <dd class="col-6"><?php echo date('M d, Y', strtotime($invoice['created_date'])); ?></dd>
                    
                    <dt class="col-6">Last Updated:</dt>
                    <dd class="col-6"><?php echo $invoice['updated_date'] ? date('M d, Y', strtotime($invoice['updated_date'])) : 'Never'; ?></dd>
                    
                    <?php if ($invoice['due_date']): ?>
                    <dt class="col-6">Days Remaining:</dt>
                    <dd class="col-6">
                        <?php 
                        $due_date = strtotime($invoice['due_date']);
                        $today = time();
                        $days_left = floor(($due_date - $today) / (60 * 60 * 24));
                        
                        if ($days_left > 0) {
                            echo '<span class="text-success">' . $days_left . ' days</span>';
                        } elseif ($days_left == 0) {
                            echo '<span class="text-warning">Due Today</span>';
                        } else {
                            echo '<span class="text-danger">' . abs($days_left) . ' days overdue</span>';
                        }
                        ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        
        <!-- Student Summary -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Student Financial Summary</h6>
            </div>
            <div class="app-card-body p-3">
                <?php
                // Get student's financial summary
                $student_summary = $pdo->prepare("
                    SELECT 
                        SUM(amount) as total_fees,
                        SUM(amount_paid) as total_paid,
                        SUM(balance) as total_balance,
                        COUNT(*) as total_invoices
                    FROM student_fees 
                    WHERE student_id = ?
                ");
                $student_summary->execute([$invoice['student_id']]);
                $summary = $student_summary->fetch();
                ?>
                
                <dl class="row mb-0">
                    <dt class="col-6">Total Invoices:</dt>
                    <dd class="col-6"><?php echo $summary['total_invoices']; ?></dd>
                    
                    <dt class="col-6">Total Fees:</dt>
                    <dd class="col-6">₦<?php echo number_format($summary['total_fees'], 2); ?></dd>
                    
                    <dt class="col-6">Total Paid:</dt>
                    <dd class="col-6 text-success">₦<?php echo number_format($summary['total_paid'], 2); ?></dd>
                    
                    <dt class="col-6">Total Balance:</dt>
                    <dd class="col-6 text-danger">₦<?php echo number_format($summary['total_balance'], 2); ?></dd>
                </dl>
                
                <div class="mt-3">
                    <a href="student_fees.php?id=<?php echo $invoice['student_id']; ?>" class="btn btn-sm btn-outline-primary w-100">
                        View All Student Fees
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function printInvoice() {
    const printContent = `
        <html>
        <head>
            <title>Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                .university { font-size: 24px; font-weight: bold; color: #333; }
                .invoice-title { font-size: 28px; margin: 10px 0; }
                .section { margin-bottom: 20px; }
                .section-title { font-weight: bold; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f5f5f5; }
                .text-end { text-align: right; }
                .total-row { font-weight: bold; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="university">5G EGURUSCHOOL PORTAL</div>
                <div>Jigawa State, Nigeria</div> 
                <div class="invoice-title">INVOICE</div>
                <div>#<?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                <div>Date: <?php echo date('F j, Y', strtotime($invoice['created_date'])); ?></div>
            </div>
            
            <div class="section">
                <div class="section-title">Bill To:</div>
                <strong><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></strong><br>
                Matric No: <?php echo htmlspecialchars($invoice['matric_number']); ?><br>
                Department: <?php echo htmlspecialchars($invoice['department_name'] ?? 'N/A'); ?><br>
                Program: <?php echo htmlspecialchars($invoice['program_name'] ?? 'N/A'); ?><br>
                Level: <?php echo $invoice['current_level']; ?> | Session: <?php echo htmlspecialchars($invoice['current_session']); ?>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Session</th>
                        <th class="text-end">Amount (₦)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($invoice['fee_type']); ?></strong><br>
                            <?php echo htmlspecialchars($invoice['description']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($invoice['session_year'] ?? $invoice['current_session']); ?></td>
                        <td class="text-end">₦<?php echo number_format($invoice['amount'], 2); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2" class="text-end">Total:</td>
                        <td class="text-end">₦<?php echo number_format($invoice['amount'], 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="text-end">Amount Paid:</td>
                        <td class="text-end">₦<?php echo number_format($invoice['amount_paid'], 2); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="2" class="text-end">Balance Due:</td>
                        <td class="text-end">₦<?php echo number_format($invoice['balance'], 2); ?></td>
                    </tr>
                </tfoot>
            </table>
            
            <div class="section">
                <div class="section-title">Payment Terms:</div>
                Due Date: <?php echo $invoice['due_date'] ? date('F j, Y', strtotime($invoice['due_date'])) : 'Not specified'; ?><br>
                Status: <?php echo $invoice['status']; ?>
            </div>
            
           
            
            <div class="section no-print" style="text-align: center; margin-top: 50px;">
                <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;">
                    Print Invoice
                </button>
            </div>
            
            <script>
                window.onload = function() {
                    window.print();
                }
            <\/script>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
}

function downloadPDF() {
    alert('PDF generation feature would be implemented with a PDF library like TCPDF or Dompdf.');
    // In a real implementation, this would make an AJAX call to generate a PDF
}
</script>

<?php
require_once 'includes/footer.php';
?>