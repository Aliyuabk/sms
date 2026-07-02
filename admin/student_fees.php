<?php
require_once 'includes/header.php';

// Check if student ID is provided
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0);

if ($student_id === 0) {
    header('Location: manage_students.php');
    exit();
}

// Get student details
$student_sql = "SELECT s.*, d.department_name, p.program_name, 
                       CONCAT(s.first_name, ' ', s.last_name) as full_name 
                FROM students s
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN programs p ON s.program_id = p.program_id
                WHERE s.student_id = ?";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

if (!$student) {
    $_SESSION['error_message'] = "Student not found!";
    header('Location: manage_students.php');
    exit();
}

// Get student fees
$fees_sql = "SELECT sf.*, fs.description as fee_description 
             FROM student_fees sf
             LEFT JOIN fee_structure fs ON sf.fee_structure_id = fs.fee_structure_id
             WHERE sf.student_id = ?
             ORDER BY sf.due_date DESC, sf.created_date DESC";
$fees_stmt = $pdo->prepare($fees_sql);
$fees_stmt->execute([$student_id]);
$fees = $fees_stmt->fetchAll();

// Get payments
$payments_sql = "SELECT * FROM payments 
                 WHERE student_id = ? 
                 ORDER BY payment_date DESC";
$payments_stmt = $pdo->prepare($payments_sql);
$payments_stmt->execute([$student_id]);
$payments = $payments_stmt->fetchAll();

// Calculate totals
$total_fees = 0;
$total_paid = 0;
$total_balance = 0;

foreach ($fees as $fee) {
    $total_fees += $fee['amount'];
    $total_paid += $fee['amount_paid'];
    $total_balance += $fee['balance'];
}

// Add fee if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fee'])) {
    try {
        $fee_data = [
            'student_id' => $student_id,
            'fee_structure_id' => $_POST['fee_structure_id'] ?: null,
            'session_year' => $_POST['session_year'],
            'semester' => $_POST['semester'],
            'fee_type' => $_POST['fee_type'],
            'description' => $_POST['description'],
            'amount' => $_POST['amount'],
            'amount_paid' => 0,
            'due_date' => $_POST['due_date'] ?: null,
            'status' => 'Pending',
            'invoice_number' => 'INV-' . date('Ymd') . '-' . strtoupper(uniqid())
        ];
        
        $insert_sql = "INSERT INTO student_fees 
            (student_id, fee_structure_id, session_year, semester, fee_type, 
             description, amount, amount_paid, due_date, status, invoice_number, created_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $fee_data['student_id'],
            $fee_data['fee_structure_id'],
            $fee_data['session_year'],
            $fee_data['semester'],
            $fee_data['fee_type'],
            $fee_data['description'],
            $fee_data['amount'],
            $fee_data['amount_paid'],
            $fee_data['due_date'],
            $fee_data['status'],
            $fee_data['invoice_number']
        ]);
        
        $_SESSION['success_message'] = "Fee added successfully!";
        header("Location: student_fees.php?id=$student_id");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error adding fee: " . $e->getMessage();
    }
}

// Record payment if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    try {
        $payment_data = [
            'student_id' => $student_id,
            'fee_id' => $_POST['fee_id'] ?: null,
            'amount' => $_POST['amount'],
            'payment_method' => $_POST['payment_method'],
            'transaction_id' => $_POST['transaction_id'] ?: null,
            'bank_name' => $_POST['bank_name'] ?: null,
            'account_number' => $_POST['account_number'] ?: null,
            'payer_name' => $_POST['payer_name'] ?: null,
            'receipt_number' => 'REC-' . date('Ymd') . '-' . strtoupper(uniqid()),
            'status' => 'Verified'
        ];
        
        $payment_sql = "INSERT INTO payments 
            (student_id, fee_id, amount, payment_method, transaction_id, 
             bank_name, account_number, payer_name, receipt_number, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $payment_stmt = $pdo->prepare($payment_sql);
        $payment_stmt->execute([
            $payment_data['student_id'],
            $payment_data['fee_id'],
            $payment_data['amount'],
            $payment_data['payment_method'],
            $payment_data['transaction_id'],
            $payment_data['bank_name'],
            $payment_data['account_number'],
            $payment_data['payer_name'],
            $payment_data['receipt_number'],
            $payment_data['status']
        ]);
        
        // Update fee record
        if ($_POST['fee_id']) {
            $update_fee_sql = "UPDATE student_fees 
                              SET amount_paid = amount_paid + ?, 
                                  status = CASE WHEN (amount_paid + ?) >= amount THEN 'Paid' ELSE 'Partial' END
                              WHERE fee_id = ?";
            $update_fee_stmt = $pdo->prepare($update_fee_sql);
            $update_fee_stmt->execute([$_POST['amount'], $_POST['amount'], $_POST['fee_id']]);
        }
        
        $_SESSION['success_message'] = "Payment recorded successfully!";
        header("Location: student_fees.php?id=$student_id");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error recording payment: " . $e->getMessage();
    }
}

$page_title = "Student Fees - " . $student['full_name'];
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="app-page-title mb-0">Fee Management</h1>
                <div class="text-muted">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="text-decoration-none">
                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($student['full_name']); ?>
                    </a>
                    • <?php echo htmlspecialchars($student['matric_number']); ?>
                </div>
            </div>
            <div class="app-actions">
                <button class="btn app-btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                    <i class="fas fa-plus me-2"></i>Add Fee
                </button>
                <button class="btn app-btn-secondary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                    <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Fee Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Total Fees</h4>
                <div class="stats-figure">₦<?php echo number_format($total_fees, 2); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-file-invoice-dollar text-primary"></i> All Invoices
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Total Paid</h4>
                <div class="stats-figure text-success">₦<?php echo number_format($total_paid, 2); ?></div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Amount Paid
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Total Balance</h4>
                <div class="stats-figure text-<?php echo ($total_balance > 0) ? 'danger' : 'success'; ?>">
                    ₦<?php echo number_format($total_balance, 2); ?>
                </div>
                <div class="stats-meta">
                    <i class="fas fa-exclamation-circle text-<?php echo ($total_balance > 0) ? 'danger' : 'success'; ?>"></i>
                    <?php echo ($total_balance > 0) ? 'Pending Balance' : 'All Paid'; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Active Invoices</h4>
                <div class="stats-figure"><?php echo count($fees); ?></div>
                <div class="stats-meta text-info">
                    <i class="fas fa-receipt"></i> Total Invoices
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <!-- Fee Invoices -->
        <div class="app-card app-card-table shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">Fee Invoices</h5>
                <div class="text-muted small"><?php echo count($fees); ?> invoices</div>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Invoice No</th>
                                <th>Fee Type</th>
                                <th>Amount</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($fees)): ?>
                                <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fee['invoice_number']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $fee['session_year']; ?> - Sem <?php echo $fee['semester']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($fee['fee_type']); ?>
                                        <?php if ($fee['description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($fee['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">₦<?php echo number_format($fee['amount'], 2); ?></td>
                                    <td class="text-end">₦<?php echo number_format($fee['amount_paid'], 2); ?></td>
                                    <td class="text-end">
                                        <span class="text-<?php echo ($fee['balance'] > 0) ? 'danger' : 'success'; ?>">
                                            ₦<?php echo number_format($fee['balance'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $fee['due_date'] ? date('M d, Y', strtotime($fee['due_date'])) : '-'; ?>
                                        <?php if ($fee['due_date'] && strtotime($fee['due_date']) < time() && $fee['balance'] > 0): ?>
                                        <br><small class="text-danger">Overdue</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_color = [
                                            'Pending' => 'warning',
                                            'Partial' => 'info',
                                            'Paid' => 'success',
                                            'Overdue' => 'danger',
                                            'Waived' => 'secondary'
                                        ][$fee['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $status_color; ?>">
                                            <?php echo $fee['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="recordPaymentForInvoice(<?php echo $fee['fee_id']; ?>, '<?php echo htmlspecialchars($fee['fee_type']); ?>', <?php echo $fee['balance']; ?>)">
                                                        <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#" onclick="printInvoice(<?php echo $fee['fee_id']; ?>)">
                                                        <i class="fas fa-print me-2"></i>Print Invoice
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" onclick="deleteInvoice(<?php echo $fee['fee_id']; ?>)">
                                                        <i class="fas fa-trash me-2"></i>Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-receipt fa-2x text-muted mb-3"></i>
                                        <p class="text-muted">No fee invoices found.</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                                            <i class="fas fa-plus me-2"></i>Add First Fee
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-5">
        <!-- Recent Payments -->
        <div class="app-card app-card-table shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">Recent Payments</h5>
                <div class="text-muted small"><?php echo count($payments); ?> payments</div>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($payment['receipt_number']); ?></strong>
                                        <?php if ($payment['transaction_id']): ?>
                                        <br><small class="text-muted">Ref: <?php echo htmlspecialchars($payment['transaction_id']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">₦<?php echo number_format($payment['amount'], 2); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                        <?php if ($payment['bank_name']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($payment['bank_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $payment['status'] === 'Verified' ? 'success' : 'warning'; ?>">
                                            <?php echo $payment['status']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="fas fa-money-bill-wave fa-2x text-muted mb-3"></i>
                                        <p class="text-muted">No payments recorded yet.</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                                            <i class="fas fa-plus me-2"></i>Record First Payment
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">Quick Actions</h5>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-user me-2"></i>View Student Profile
                    </a>
                    <a href="student_results.php?id=<?php echo $student_id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-chart-bar me-2"></i>View Academic Results
                    </a>
                    <a href="course_registrations.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-book me-2"></i>Course Registration
                    </a>
                    <a href="manage_students.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Students List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Fee Modal -->
<div class="modal fade" id="addFeeModal" tabindex="-1" aria-labelledby="addFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addFeeModalLabel">
                    <i class="fas fa-plus me-2"></i>Add New Fee
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Fee Type *</label>
                        <input type="text" class="form-control" name="fee_type" required 
                               placeholder="e.g., Tuition Fee, Registration Fee">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" 
                                  placeholder="Fee description (optional)"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Academic Session *</label>
                            <input type="text" class="form-control" name="session_year" 
                                   value="<?php echo $student['current_session']; ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="semester" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_fee" class="btn btn-primary">Add Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="recordPaymentModalLabel">
                    <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Invoice (Optional)</label>
                        <select class="form-select" name="fee_id" id="feeSelect">
                            <option value="">General Payment (No specific invoice)</option>
                            <?php foreach ($fees as $fee): ?>
                                <?php if ($fee['balance'] > 0): ?>
                                <option value="<?php echo $fee['fee_id']; ?>" data-balance="<?php echo $fee['balance']; ?>">
                                    <?php echo $fee['invoice_number']; ?> - ₦<?php echo number_format($fee['balance'], 2); ?> balance
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Amount (₦) *</label>
                        <input type="number" class="form-control" name="amount" 
                               step="0.01" min="0" id="paymentAmount" required>
                        <div class="form-text" id="balanceInfo"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method *</label>
                        <select class="form-select" name="payment_method" required>
                            <option value="">Select Method</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Online">Online Payment</option>
                            <option value="Card">Card Payment</option>
                            <option value="Bank Draft">Bank Draft</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Transaction/Reference ID</label>
                        <input type="text" class="form-control" name="transaction_id" 
                               placeholder="e.g., TRX123456789">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bank Name (if applicable)</label>
                            <input type="text" class="form-control" name="bank_name" 
                                   placeholder="e.g., First Bank">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" class="form-control" name="account_number">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payer Name</label>
                        <input type="text" class="form-control" name="payer_name" 
                               placeholder="Name of person who paid">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Update payment amount suggestion based on selected invoice
document.getElementById('feeSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const balance = selectedOption.dataset.balance;
    const amountInput = document.getElementById('paymentAmount');
    const balanceInfo = document.getElementById('balanceInfo');
    
    if (balance) {
        amountInput.value = balance;
        balanceInfo.innerHTML = `Invoice balance: ₦${parseFloat(balance).toFixed(2)}`;
        balanceInfo.className = 'form-text text-info';
    } else {
        amountInput.value = '';
        balanceInfo.innerHTML = 'Enter payment amount';
        balanceInfo.className = 'form-text';
    }
});

// Function to pre-fill payment modal for specific invoice
function recordPaymentForInvoice(feeId, feeType, balance) {
    const modal = new bootstrap.Modal(document.getElementById('recordPaymentModal'));
    document.getElementById('feeSelect').value = feeId;
    document.getElementById('paymentAmount').value = balance;
    document.getElementById('balanceInfo').innerHTML = `${feeType} balance: ₦${balance.toFixed(2)}`;
    document.getElementById('balanceInfo').className = 'form-text text-info';
    modal.show();
}

// Function to print invoice
function printInvoice(feeId) {
    window.open(`print_invoice.php?id=${feeId}`, '_blank');
}

// Function to delete invoice
function deleteInvoice(feeId) {
    if (confirm('Are you sure you want to delete this invoice? This action cannot be undone.')) {
        window.location.href = `delete_fee.php?id=${feeId}&student_id=<?php echo $student_id; ?>`;
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>