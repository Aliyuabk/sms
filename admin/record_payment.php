<?php
require_once 'includes/header.php';

// Check if fee ID is provided
$fee_id = $_GET['fee_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;

// Redirect if no ID provided
if (!$fee_id && !$student_id) {
    header('Location: payments.php');
    exit();
}

// Get fee details if fee_id is provided
$fee = null;
if ($fee_id) {
    $stmt = $pdo->prepare("
        SELECT sf.*, 
               s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
               d.department_name,
               p.program_name
        FROM student_fees sf
        JOIN students s ON sf.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE sf.fee_id = ?
    ");
    $stmt->execute([$fee_id]);
    $fee = $stmt->fetch();
    
    if (!$fee) {
        $_SESSION['error_message'] = "Fee record not found!";
        header('Location: payments.php');
        exit();
    }
    
    $student_id = $fee['student_id'];
}

// Get student details
$stmt = $pdo->prepare("
    SELECT * FROM students 
    WHERE student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $_SESSION['error_message'] = "Student not found!";
    header('Location: payments.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Generate receipt number
        $receipt_prefix = 'RCPT-' . date('Ymd');
        $last_receipt = $pdo->query("SELECT receipt_number FROM payments WHERE receipt_number LIKE '$receipt_prefix%' ORDER BY payment_id DESC LIMIT 1")->fetch();
        
        if ($last_receipt) {
            $last_number = intval(substr($last_receipt['receipt_number'], -4));
            $receipt_number = $receipt_prefix . '-' . str_pad($last_number + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $receipt_number = $receipt_prefix . '-0001';
        }
        
        // Insert payment
        $stmt = $pdo->prepare("INSERT INTO payments 
            (student_id, fee_id, invoice_number, amount, payment_method, transaction_id, 
             bank_name, account_number, payer_name, receipt_number, verified_by, status, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $student_id,
            $_POST['fee_id'] ?: null,
            $_POST['invoice_number'] ?? null,
            $_POST['amount'],
            $_POST['payment_method'],
            $_POST['transaction_id'] ?? null,
            $_POST['bank_name'] ?? null,
            $_POST['account_number'] ?? null,
            $_POST['payer_name'] ?? null,
            $receipt_number,
            $admin_id,
            'Verified',
            $_POST['remarks'] ?? null
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Update student_fees table if a specific fee was paid
        if ($fee_id) {
            // Get current payment status
            $fee_stmt = $pdo->prepare("SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?");
            $fee_stmt->execute([$fee_id]);
            $fee_data = $fee_stmt->fetch();
            
            if ($fee_data) {
                $new_amount_paid = $fee_data['amount_paid'] + $_POST['amount'];
                $balance = $fee_data['amount'] - $new_amount_paid;
                
                // Determine new status
                if ($balance <= 0) {
                    $status = 'Paid';
                } elseif ($new_amount_paid > 0) {
                    $status = 'Partial';
                } else {
                    $status = 'Pending';
                }
                
                $update_stmt = $pdo->prepare("UPDATE student_fees SET 
                    amount_paid = ?, 
                    balance = ?,
                    status = ?,
                    updated_date = CURRENT_TIMESTAMP
                    WHERE fee_id = ?");
                
                $update_stmt->execute([$new_amount_paid, $balance, $status, $fee_id]);
            }
        }
        
        // Add notification for student
        $notification_sql = "INSERT INTO notifications 
            (student_id, title, message, notification_type, priority, action_url)
            VALUES (?, ?, ?, 'Financial', 'Normal', ?)";
        
        $notification_stmt = $pdo->prepare($notification_sql);
        $notification_stmt->execute([
            $student_id,
            "Payment Received",
            "Your payment of ₦" . number_format($_POST['amount'], 2) . " has been received and verified. Receipt No: " . $receipt_number,
            "student_payments.php"
        ]);
        
        $_SESSION['success_message'] = "Payment recorded successfully! Receipt Number: " . $receipt_number;
        
        // Redirect based on user choice
        if (isset($_POST['print_receipt'])) {
            header("Location: print_receipt.php?id=$payment_id");
            exit();
        } elseif ($fee_id) {
            header("Location: view_invoice.php?id=$fee_id&payment=success");
            exit();
        } else {
            header("Location: student_fees.php?id=$student_id&payment=success");
            exit();
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error recording payment: " . $e->getMessage();
    }
}

// Get student's pending fees
$pending_fees_stmt = $pdo->prepare("
    SELECT * FROM student_fees 
    WHERE student_id = ? AND status IN ('Pending', 'Partial')
    ORDER BY due_date ASC
");
$pending_fees_stmt->execute([$student_id]);
$pending_fees = $pending_fees_stmt->fetchAll();

$page_title = "Record Payment - " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Record Payment</h1>
            <p class="text-muted">
                Student: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> | 
                Matric: <?php echo htmlspecialchars($student['matric_number']); ?>
            </p>
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
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Payment Details</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    
                    <!-- Student Info -->
                    <div class="alert alert-info mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($student['matric_number']); ?></small><br>
                                <small>Level: <?php echo $student['current_level']; ?> | Session: <?php echo htmlspecialchars($student['current_session']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <small>Email: <?php echo htmlspecialchars($student['email']); ?></small><br>
                                <small>Phone: <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></small><br>
                                <small>Status: 
                                    <span class="badge bg-<?php echo $student['status'] === 'Active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $student['status']; ?>
                                    </span>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fee Selection -->
                    <div class="mb-4">
                        <label class="form-label">Select Fee to Pay (Optional)</label>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Invoice No.</th>
                                        <th>Fee Type</th>
                                        <th>Amount</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_fees as $pending_fee): ?>
                                    <tr>
                                        <td>
                                            <input type="radio" name="fee_id" value="<?php echo $pending_fee['fee_id']; ?>" 
                                                   data-invoice="<?php echo htmlspecialchars($pending_fee['invoice_number']); ?>"
                                                   data-balance="<?php echo $pending_fee['balance']; ?>"
                                                   onchange="updatePaymentDetails(this)"
                                                   <?php echo $fee_id == $pending_fee['fee_id'] ? 'checked' : ''; ?>>
                                        </td>
                                        <td><?php echo htmlspecialchars($pending_fee['invoice_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pending_fee['fee_type']); ?></td>
                                        <td>₦<?php echo number_format($pending_fee['amount'], 2); ?></td>
                                        <td>₦<?php echo number_format($pending_fee['amount_paid'], 2); ?></td>
                                        <td class="<?php echo $pending_fee['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                            ₦<?php echo number_format($pending_fee['balance'], 2); ?>
                                        </td>
                                        <td>
                                            <?php echo $pending_fee['due_date'] ? date('M d, Y', strtotime($pending_fee['due_date'])) : 'N/A'; ?>
                                            <?php if ($pending_fee['due_date'] && strtotime($pending_fee['due_date']) < time()): ?>
                                                <br><small class="text-danger">Overdue</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pending_fee['status'] === 'Paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php elseif ($pending_fee['status'] === 'Partial'): ?>
                                                <span class="badge bg-warning">Partial</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($pending_fees)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-3">
                                            No pending fees found for this student.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">
                            Select a specific fee to apply payment, or leave unselected for miscellaneous payment.
                            <?php if ($fee_id): ?>
                                <br><strong>Currently viewing payment for: <?php echo htmlspecialchars($fee['fee_type']); ?></strong>
                            <?php endif; ?>
                        </small>
                    </div>
                    
                    <!-- Payment Amount -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="amount" class="form-label">Payment Amount (₦) *</label>
                            <input type="number" class="form-control" id="amount" name="amount" 
                                   step="0.01" min="0" required 
                                   value="<?php echo $fee ? $fee['balance'] : ''; ?>">
                            <small class="text-muted">Enter the amount being paid</small>
                        </div>
                        <div class="col-md-6">
                            <label for="payment_method" class="form-label">Payment Method *</label>
                            <select class="form-select" id="payment_method" name="payment_method" required 
                                    onchange="togglePaymentDetails()">
                                <option value="">Select Method</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Online">Online Payment</option>
                                <option value="Card">Card Payment</option>
                                <option value="Bank Draft">Bank Draft</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Payment Details (Conditional) -->
                    <div class="mb-4" id="paymentDetails" style="display: none;">
                        <h6 class="mb-3">Payment Details</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction ID/Reference</label>
                                <input type="text" class="form-control" name="transaction_id" 
                                       placeholder="e.g., TRX123456789">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payer Name</label>
                                <input type="text" class="form-control" name="payer_name" 
                                       value="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name</label>
                                <input type="text" class="form-control" name="bank_name" 
                                       placeholder="e.g., First Bank Nigeria">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-control" name="account_number" 
                                       placeholder="Account number used">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Remarks -->
                    <div class="mb-4">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2" 
                                  placeholder="Optional notes about this payment"></textarea>
                    </div>
                    
                    <!-- Actions -->
                    <div class="d-flex justify-content-between">
                        <?php if ($fee_id): ?>
                            <a href="view_invoice.php?id=<?php echo $fee_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Invoice
                            </a>
                        <?php else: ?>
                            <a href="student_fees.php?id=<?php echo $student_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Student
                            </a>
                        <?php endif; ?>
                        
                        <div>
                            <button type="submit" name="print_receipt" value="1" class="btn btn-success me-2">
                                <i class="fas fa-print me-2"></i>Save & Print Receipt
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Record Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Student Summary -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Student Financial Summary</h6>
                <?php
                // Get student's financial summary
                $summary_stmt = $pdo->prepare("
                    SELECT 
                        SUM(amount) as total_fees,
                        SUM(amount_paid) as total_paid,
                        SUM(balance) as total_balance,
                        COUNT(*) as total_invoices
                    FROM student_fees 
                    WHERE student_id = ?
                ");
                $summary_stmt->execute([$student_id]);
                $summary = $summary_stmt->fetch();
                ?>
                
                <div class="stats-figure">₦<?php echo number_format($summary['total_balance'], 2); ?></div>
                <p class="stats-detail mb-3">Total balance due</p>
                
                <dl class="row mb-0">
                    <dt class="col-6">Total Invoices:</dt>
                    <dd class="col-6"><?php echo $summary['total_invoices']; ?></dd>
                    
                    <dt class="col-6">Total Fees:</dt>
                    <dd class="col-6">₦<?php echo number_format($summary['total_fees'], 2); ?></dd>
                    
                    <dt class="col-6">Total Paid:</dt>
                    <dd class="col-6 text-success">₦<?php echo number_format($summary['total_paid'], 2); ?></dd>
                </dl>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="app-card app-card-details shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Recent Payments</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php
                            $recent_stmt = $pdo->prepare("
                                SELECT p.*, sf.fee_type 
                                FROM payments p
                                LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                                WHERE p.student_id = ? 
                                ORDER BY p.payment_date DESC 
                                LIMIT 5
                            ");
                            $recent_stmt->execute([$student_id]);
                            $recent_payments = $recent_stmt->fetchAll();
                            ?>
                            
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr>
                                <td>
                                    <small class="text-muted"><?php echo date('M d', strtotime($payment['payment_date'])); ?></small><br>
                                    <small><?php echo htmlspecialchars($payment['fee_type'] ?? 'Misc'); ?></small>
                                </td>
                                <td class="text-end">
                                    <small class="text-success">₦<?php echo number_format($payment['amount'], 2); ?></small><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($payment['payment_method']); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($recent_payments)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-3">
                                    No recent payments found
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
                <h6 class="app-card-title">Quick Actions</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="generate_invoices.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-file-invoice me-2"></i>Generate Invoice
                    </a>
                    <a href="student_fees.php?id=<?php echo $student_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-list me-2"></i>View All Fees
                    </a>
                    <a href="payments.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-money-bill-wave me-2"></i>Record Another Payment
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update payment amount when fee is selected
function updatePaymentDetails(radio) {
    if (radio.checked) {
        const balance = radio.dataset.balance;
        const invoice = radio.dataset.invoice;
        
        document.getElementById('amount').value = balance;
        document.getElementById('amount').setAttribute('max', balance);
    }
}

// Show/hide payment details based on method
function togglePaymentDetails() {
    const detailsDiv = document.getElementById('paymentDetails');
    const method = document.getElementById('payment_method').value;
    
    if (method === 'Bank Transfer' || method === 'Online' || method === 'Card') {
        detailsDiv.style.display = 'block';
    } else {
        detailsDiv.style.display = 'none';
    }
}

// Form validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value);
    const method = document.getElementById('payment_method').value;
    const selectedFee = document.querySelector('input[name="fee_id"]:checked');
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount greater than 0.');
        document.getElementById('amount').focus();
        return false;
    }
    
    if (!method) {
        e.preventDefault();
        alert('Please select a payment method.');
        document.getElementById('payment_method').focus();
        return false;
    }
    
    if (selectedFee) {
        const maxAmount = parseFloat(selectedFee.dataset.balance);
        if (amount > maxAmount) {
            if (!confirm('Payment amount exceeds the fee balance. This will result in an overpayment. Continue?')) {
                e.preventDefault();
                return false;
            }
        }
    }
    
    return confirm('Confirm recording this payment?');
});

// Initialize
togglePaymentDetails();
</script>

<?php
require_once 'includes/footer.php';
?>