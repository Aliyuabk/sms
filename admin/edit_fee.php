<?php
require_once 'includes/header.php';

// Check if fee ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_fees.php');
    exit();
}

$fee_id = (int)$_GET['id'];

// Get fee details
$sql = "
    SELECT sf.*, 
           s.student_id, s.matric_number, s.first_name, s.last_name,
           d.department_name,
           p.program_name
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE sf.fee_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$fee_id]);
$fee = $stmt->fetch();

if (!$fee) {
    $_SESSION['error_message'] = "Fee record not found!";
    header('Location: manage_fees.php');
    exit();
}

// Update fee if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $update_data = [
            'fee_type' => $_POST['fee_type'],
            'description' => $_POST['description'],
            'amount' => $_POST['amount'],
            'amount_paid' => $_POST['amount_paid'],
            'due_date' => $_POST['due_date'] ?: null,
            'payment_deadline' => $_POST['payment_deadline'] ?: null,
            'status' => $_POST['status'],
            'fee_id' => $fee_id
        ];
        
        // Calculate new balance
        $balance = $update_data['amount'] - $update_data['amount_paid'];
        $update_data['balance'] = $balance;
        
        $update_sql = "UPDATE student_fees SET 
            fee_type = :fee_type,
            description = :description,
            amount = :amount,
            amount_paid = :amount_paid,
            balance = :balance,
            due_date = :due_date,
            payment_deadline = :payment_deadline,
            status = :status,
            updated_date = CURRENT_TIMESTAMP
            WHERE fee_id = :fee_id";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute($update_data);
        
        // Update status if needed
        if ($balance <= 0 && $update_data['status'] !== 'Paid') {
            $update_stmt = $pdo->prepare("UPDATE student_fees SET status = 'Paid' WHERE fee_id = ?");
            $update_stmt->execute([$fee_id]);
        }
        
        $_SESSION['success_message'] = "Fee record updated successfully!";
        header("Location: view_invoice.php?id=$fee_id");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating fee: " . $e->getMessage();
    }
}

$page_title = "Edit Invoice - " . htmlspecialchars($fee['fee_type']);
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Edit Invoice</h1>
            <p class="text-muted">Invoice #<?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></p>
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
        <div class="app-card app-card-settings shadow-sm p-4">
            <div class="app-card-header">
                <h3 class="app-card-title">Edit Invoice Details</h3>
                <div class="text-muted">
                    Student: <?php echo htmlspecialchars($fee['first_name'] . ' ' . $fee['last_name']); ?> | 
                    Matric: <?php echo htmlspecialchars($fee['matric_number']); ?>
                </div>
            </div>
            <div class="app-card-body">
                <form method="POST" id="editFeeForm">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Fee Type *</label>
                            <input type="text" class="form-control" name="fee_type" 
                                   value="<?php echo htmlspecialchars($fee['fee_type']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?>" disabled>
                            <small class="text-muted">Invoice number cannot be changed</small>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($fee['description']); ?></textarea>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" 
                                   value="<?php echo $fee['amount']; ?>" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount Paid (₦) *</label>
                            <input type="number" class="form-control" name="amount_paid" 
                                   value="<?php echo $fee['amount_paid']; ?>" step="0.01" min="0" required>
                            <small class="text-muted">Total payments received</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Balance (₦)</label>
                            <input type="text" class="form-control" 
                                   value="₦<?php echo number_format($fee['balance'], 2); ?>" disabled>
                            <small class="text-muted">Auto-calculated</small>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" 
                                   value="<?php echo $fee['due_date'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Deadline</label>
                            <input type="date" class="form-control" name="payment_deadline" 
                                   value="<?php echo $fee['payment_deadline'] ?? ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="Pending" <?php echo $fee['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Partial" <?php echo $fee['status'] === 'Partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="Paid" <?php echo $fee['status'] === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="Overdue" <?php echo $fee['status'] === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="Waived" <?php echo $fee['status'] === 'Waived' ? 'selected' : ''; ?>>Waived</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Session Year</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($fee['session_year']); ?>" disabled>
                        </div>
                    </div>
                    
                    <!-- Student Info (Readonly) -->
                    <div class="alert alert-secondary mb-4">
                        <h6>Student Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <small>Name: <?php echo htmlspecialchars($fee['first_name'] . ' ' . $fee['last_name']); ?></small><br>
                                <small>Matric: <?php echo htmlspecialchars($fee['matric_number']); ?></small><br>
                                <small>Session: <?php echo htmlspecialchars($fee['current_session']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <small>Department: <?php echo htmlspecialchars($fee['department_name'] ?? 'N/A'); ?></small><br>
                                <small>Program: <?php echo htmlspecialchars($fee['program_name'] ?? 'N/A'); ?></small><br>
                                <small>Level: <?php echo $fee['current_level']; ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="view_invoice.php?id=<?php echo $fee_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Payment History -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Payment Summary</h6>
                <div class="stats-figure">₦<?php echo number_format($fee['amount_paid'], 2); ?></div>
                <p class="stats-detail mb-3">Total paid of ₦<?php echo number_format($fee['amount'], 2); ?></p>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Payment Progress</span>
                        <span><?php echo number_format(($fee['amount_paid'] / $fee['amount']) * 100, 1); ?>%</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" 
                             style="width: <?php echo ($fee['amount_paid'] / $fee['amount']) * 100; ?>%"></div>
                    </div>
                </div>
                
                <dl class="row mb-0">
                    <dt class="col-6">Created:</dt>
                    <dd class="col-6"><?php echo date('M d, Y', strtotime($fee['created_date'])); ?></dd>
                    
                    <dt class="col-6">Last Updated:</dt>
                    <dd class="col-6"><?php echo $fee['updated_date'] ? date('M d, Y', strtotime($fee['updated_date'])) : 'Never'; ?></dd>
                </dl>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Quick Actions</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="record_payment.php?fee_id=<?php echo $fee_id; ?>" class="btn btn-success">
                        <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                    </a>
                    <a href="view_invoice.php?id=<?php echo $fee_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-eye me-2"></i>View Invoice
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-secondary">
                        <i class="fas fa-print me-2"></i>Print Invoice
                    </button>
                    <a href="manage_fees.php" class="btn btn-outline-dark">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Danger Zone -->
        <div class="app-card app-card-details shadow-sm border-danger">
            <div class="app-card-header p-3 bg-danger text-white">
                <h6 class="app-card-title mb-0">Danger Zone</h6>
            </div>
            <div class="app-card-body p-3">
                <p class="small text-muted mb-3">
                    Deleting this invoice will permanently remove it from the system.
                    This action cannot be undone.
                </p>
                <button class="btn btn-outline-danger w-100" onclick="confirmDelete()">
                    <i class="fas fa-trash me-2"></i>Delete Invoice
                </button>
                
                <form method="POST" action="delete_fee.php" id="deleteForm" class="d-none">
                    <input type="hidden" name="fee_id" value="<?php echo $fee_id; ?>">
                    <input type="hidden" name="confirm_delete" value="1">
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-calculate balance when amount or amount paid changes
document.querySelector('input[name="amount"]').addEventListener('input', updateBalance);
document.querySelector('input[name="amount_paid"]').addEventListener('input', updateBalance);

function updateBalance() {
    const amount = parseFloat(document.querySelector('input[name="amount"]').value) || 0;
    const amountPaid = parseFloat(document.querySelector('input[name="amount_paid"]').value) || 0;
    const balance = amount - amountPaid;
    
    // Auto-update status based on balance
    const statusSelect = document.querySelector('select[name="status"]');
    if (balance <= 0) {
        statusSelect.value = 'Paid';
    } else if (amountPaid > 0) {
        statusSelect.value = 'Partial';
    } else {
        statusSelect.value = 'Pending';
    }
}

// Form validation
document.getElementById('editFeeForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.querySelector('input[name="amount"]').value);
    const amountPaid = parseFloat(document.querySelector('input[name="amount_paid"]').value);
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Amount must be greater than 0.');
        return false;
    }
    
    if (amountPaid < 0) {
        e.preventDefault();
        alert('Amount paid cannot be negative.');
        return false;
    }
    
    if (amountPaid > amount) {
        if (!confirm('Amount paid exceeds the total amount. This will result in a negative balance (overpayment). Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    return true;
});

function confirmDelete() {
    if (confirm('Are you sure you want to delete this invoice? This action cannot be undone.')) {
        document.getElementById('deleteForm').submit();
    }
}

// Initialize
updateBalance();
</script>

<?php
require_once 'includes/footer.php';
?>