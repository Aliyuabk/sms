<?php
require_once 'includes/header.php';

$page_title = "Record Payment";

// ============================================
// AJAX SEARCH ENDPOINT - Must be at top, before any output
// ============================================
if (!empty($_GET['q']) && isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        $search = trim($_GET['q']);
        if (strlen($search) < 2) {
            echo json_encode([]);
            exit();
        }

        $like = "%$search%";
        $stmt = $pdo->prepare("
            SELECT s.student_id, s.matric_number, s.first_name, s.last_name, 
                   s.current_level, d.department_name
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            WHERE s.status = 'Active' 
            AND (s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)
            ORDER BY s.matric_number
            LIMIT 10
        ");
        $stmt->execute([$like, $like, $like, $like]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['student_id'])) {
            throw new Exception("Please search and select a student first.");
        }
        if (empty($_POST['amount']) || floatval($_POST['amount']) <= 0) {
            throw new Exception("Please enter a valid payment amount.");
        }
        if (empty($_POST['payment_method'])) {
            throw new Exception("Please select a payment method.");
        }

        $pdo->beginTransaction();

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
             bank_name, account_number, payer_name, receipt_number, verified_by, status, remarks, proof_of_payment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $_POST['student_id'],
            !empty($_POST['fee_id']) ? $_POST['fee_id'] : null,
            $_POST['invoice_number'] ?? null,
            floatval($_POST['amount']),
            $_POST['payment_method'],
            $_POST['transaction_id'] ?? null,
            $_POST['bank_name'] ?? null,
            $_POST['account_number'] ?? null,
            $_POST['payer_name'] ?? null,
            $receipt_number,
            $admin_id ?? 1,
            'Verified',
            $_POST['remarks'] ?? null,
            null
        ]);

        $payment_id = $pdo->lastInsertId();

        // Update student_fees
        if (!empty($_POST['fee_id'])) {
            $fee_id = (int)$_POST['fee_id'];

            $fee_stmt = $pdo->prepare("SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?");
            $fee_stmt->execute([$fee_id]);
            $fee = $fee_stmt->fetch();

            if ($fee) {
                $new_amount_paid = floatval($fee['amount_paid']) + floatval($_POST['amount']);
                $balance = floatval($fee['amount']) - $new_amount_paid;

                if ($balance <= 0) {
                    $status = 'Paid';
                } elseif ($new_amount_paid > 0) {
                    $status = 'Partial';
                } else {
                    $status = 'Pending';
                }

                $update_stmt = $pdo->prepare("UPDATE student_fees SET 
                    amount_paid = ?, balance = ?, status = ?, updated_date = NOW()
                    WHERE fee_id = ?");
                $update_stmt->execute([$new_amount_paid, max(0, $balance), $status, $fee_id]);
            }
        }

        // Add notification
        $notification_stmt = $pdo->prepare("INSERT INTO notifications 
            (student_id, title, message, notification_type, priority)
            VALUES (?, ?, ?, 'Financial', 'Normal')");
        $notification_stmt->execute([
            $_POST['student_id'],
            "Payment Received",
            "Your payment of ₦" . number_format(floatval($_POST['amount']), 2) . " has been verified. Receipt: " . $receipt_number
        ]);

        $pdo->commit();

        $_SESSION['success_message'] = "✅ Payment recorded! Receipt: " . $receipt_number;

        if (isset($_POST['print_receipt'])) {
            header("Location: print_receipt.php?id=$payment_id");
        } else {
            header("Location: manage_fees.php?status=Paid");
        }
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "❌ " . $e->getMessage();
        header("Location: record_payment.php" . (!empty($_POST['student_id']) ? "?student_id=" . $_POST['student_id'] : ""));
        exit();
    }
}

// ============================================
// GET DATA FOR PAGE LOAD
// ============================================
$student_id = $_GET['student_id'] ?? '';

$selected_student = null;
$student_fees = [];

if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, d.department_name, p.program_name 
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.student_id = ? AND s.status = 'Active'
    ");
    $stmt->execute([$student_id]);
    $selected_student = $stmt->fetch();

    if ($selected_student) {
        $fee_stmt = $pdo->prepare("
            SELECT * FROM student_fees 
            WHERE student_id = ? AND status IN ('Pending', 'Partial')
            ORDER BY due_date ASC
        ");
        $fee_stmt->execute([$student_id]);
        $student_fees = $fee_stmt->fetchAll();
    }
}

// Recent payments
$recent_payments = $pdo->query("
    SELECT p.*, s.matric_number, s.first_name, s.last_name, sf.fee_type
    FROM payments p
    LEFT JOIN students s ON p.student_id = s.student_id
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    WHERE p.status = 'Verified'
    ORDER BY p.payment_date DESC
    LIMIT 10
")->fetchAll();

// Today's stats
$today = date('Y-m-d');
$today_payments = $pdo->prepare("
    SELECT COALESCE(COUNT(*), 0) as count, COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE DATE(payment_date) = ? AND status = 'Verified'
");
$today_payments->execute([$today]);
$today_stats = $today_payments->fetch();
$today_stats['count'] = (int) ($today_stats['count'] ?? 0);
$today_stats['total'] = (float) ($today_stats['total'] ?? 0);
?>

<style>
:root {
    --primary: #4f46e5; --primary-light: #818cf8; --success: #10b981;
    --warning: #f59e0b; --danger: #ef4444; --info: #3b82f6;
    --dark: #1e293b; --light: #f8fafc; --card-bg: #ffffff;
    --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0;
    --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --radius: 12px; --radius-sm: 8px;
}
body { background: var(--light); color: var(--text-main); }

.app-card {
    background: var(--card-bg); border-radius: var(--radius);
    box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden;
}
.app-card .card-header {
    padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);
    background: transparent; font-weight: 600;
}
.app-card .card-body { padding: 1.5rem; }

.btn-pro {
    border-radius: var(--radius-sm); padding: 0.625rem 1.25rem;
    font-weight: 500; transition: all 0.2s; border: none;
}
.btn-pro:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-pro-primary { background: var(--primary); color: white; }
.btn-pro-success { background: var(--success); color: white; }
.btn-pro-secondary { background: var(--light); color: var(--text-main); border: 1px solid var(--border); }

.form-pro {
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    padding: 0.625rem 0.875rem; font-size: 0.875rem;
    transition: all 0.2s; background: var(--card-bg); color: var(--text-main);
}
.form-pro:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); outline: none;
}

/* Student Search */
.student-search-container { position: relative; }
.student-search-input {
    width: 100%; padding: 0.875rem 1rem 0.875rem 2.75rem;
    border: 2px solid var(--border); border-radius: var(--radius-sm);
    font-size: 1rem; transition: all 0.2s; background: var(--card-bg);
}
.student-search-input:focus {
    border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
    outline: none;
}
.student-search-input:disabled {
    background: var(--light); opacity: 0.7;
}
.student-search-icon {
    position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: 1.1rem;
}
.student-search-clear {
    position: absolute; right: 1rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; display: none; font-size: 1.1rem;
}
.student-search-clear:hover { color: var(--danger); }

/* Search Results Dropdown */
.search-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); box-shadow: var(--shadow-lg);
    max-height: 320px; overflow-y: auto; z-index: 1000;
    display: none; margin-top: 0.5rem;
}
.search-results.active { display: block; }
.search-result-item {
    padding: 0.875rem 1rem; cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: all 0.15s;
}
.search-result-item:hover { background: rgba(79, 70, 229, 0.05); }
.search-result-item:last-child { border-bottom: none; }
.search-result-matric {
    display: inline-block; background: var(--primary);
    color: white; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.75rem; font-weight: 600; margin-right: 0.5rem;
}
.search-result-name { font-weight: 600; color: var(--text-main); }
.search-result-dept { font-size: 0.8rem; color: var(--text-muted); }
.search-result-level {
    float: right; background: rgba(16, 185, 129, 0.1);
    color: var(--success); padding: 0.15rem 0.5rem;
    border-radius: 4px; font-size: 0.75rem; font-weight: 500;
}
.search-no-results {
    padding: 1.5rem; text-align: center; color: var(--text-muted);
}
.search-loading {
    padding: 1rem; text-align: center; color: var(--text-muted);
}
.search-error {
    padding: 1rem; text-align: center; color: var(--danger);
}

/* Selected Student Card */
.selected-student-card {
    background: linear-gradient(135deg, rgba(79, 70, 229, 0.05) 0%, rgba(59, 130, 246, 0.05) 100%);
    border: 2px solid var(--primary); border-radius: var(--radius);
    padding: 1.25rem; position: relative;
}
.selected-student-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--primary); color: white;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; font-weight: 700;
}
.selected-student-remove {
    position: absolute; top: 0.75rem; right: 0.75rem;
    background: none; border: none; color: var(--danger);
    cursor: pointer; font-size: 1.1rem;
}
.selected-student-remove:hover { color: #dc2626; }

/* Fee Table */
.fee-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.fee-table thead th {
    background: var(--light); padding: 0.75rem 1rem;
    font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--text-muted);
    border-bottom: 2px solid var(--border);
}
.fee-table tbody td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--border); }
.fee-table tbody tr:hover { background: rgba(79, 70, 229, 0.02); }
.fee-table tbody tr.selected { background: rgba(79, 70, 229, 0.08); }

/* Amount Input */
.amount-input-group {
    display: flex; align-items: center;
    border: 2px solid var(--border); border-radius: var(--radius-sm);
    overflow: hidden; transition: all 0.2s;
}
.amount-input-group:focus-within {
    border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
}
.amount-input-prefix {
    background: var(--light); padding: 0.75rem 1rem;
    font-weight: 600; color: var(--text-muted);
    border-right: 1px solid var(--border);
}
.amount-input {
    border: none; padding: 0.75rem 1rem;
    font-size: 1.125rem; font-weight: 600;
    flex: 1; outline: none; background: transparent;
}

/* Payment Method Cards */
.payment-methods { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.payment-method-card {
    border: 2px solid var(--border); border-radius: var(--radius-sm);
    padding: 1rem; text-align: center; cursor: pointer;
    transition: all 0.2s;
}
.payment-method-card:hover { border-color: var(--primary-light); }
.payment-method-card.selected {
    border-color: var(--primary); background: rgba(79, 70, 229, 0.05);
}
.payment-method-card i { font-size: 1.5rem; margin-bottom: 0.5rem; display: block; }
.payment-method-card span { font-size: 0.8rem; font-weight: 500; }

/* Toast */
.toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
.toast-pro {
    background: var(--card-bg); border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg); padding: 1rem 1.25rem;
    margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem;
    border-left: 4px solid; min-width: 300px;
    animation: slideIn 0.3s ease-out;
}
.toast-pro.success { border-color: var(--success); }
.toast-pro.error { border-color: var(--danger); }
@keyframes slideIn {
    from { opacity: 0; transform: translateX(20px); }
    to { opacity: 1; transform: translateX(0); }
}

/* Recent Payment Item */
.recent-payment-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--border);
}
.recent-payment-item:last-child { border-bottom: none; }
.recent-payment-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--success); color: white;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 600;
}

@media (max-width: 768px) {
    .payment-methods { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- Toast Notifications -->
<div class="toast-container">
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="toast-pro success">
        <i class="fas fa-check-circle text-success fa-lg"></i>
        <div><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="toast-pro error">
        <i class="fas fa-exclamation-circle text-danger fa-lg"></i>
        <div><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    </div>
<?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1"><i class="fas fa-cash-register text-primary me-2"></i>Record Payment</h2>
        <p class="text-muted mb-0">Record and verify student fee payments</p>
    </div>
    <a href="manage_fees.php" class="btn-pro btn-pro-secondary">
        <i class="fas fa-arrow-left me-2"></i>Back to Fees
    </a>
</div>

<div class="row">
    <!-- Main Payment Form -->
    <div class="col-lg-8">
        <div class="app-card mb-4">
            <div class="card-header">
                <i class="fas fa-user-search text-primary me-2"></i>Student Search
            </div>
            <div class="card-body">
                <form method="POST" id="paymentForm" autocomplete="off">

                    <!-- Student Search Input -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Search Student by Reg/Matric Number or Name</label>
                        <div class="student-search-container">
                            <i class="fas fa-search student-search-icon"></i>
                            <input type="text" 
                                   class="student-search-input" 
                                   id="studentSearch" 
                                   placeholder="Type matric number (e.g., 22/65659U/1) or name..."
                                   value="<?php echo $selected_student ? htmlspecialchars($selected_student['matric_number'] . ' - ' . $selected_student['first_name'] . ' ' . $selected_student['last_name']) : ''; ?>"
                                   <?php echo $selected_student ? 'disabled' : ''; ?>>
                            <button type="button" class="student-search-clear" id="clearSearch" onclick="clearStudent()" style="<?php echo $selected_student ? 'display:block' : ''; ?>">
                                <i class="fas fa-times-circle"></i>
                            </button>
                            <div class="search-results" id="searchResults"></div>
                        </div>
                        <input type="hidden" name="student_id" id="studentIdField" value="<?php echo $selected_student ? $selected_student['student_id'] : ''; ?>">
                        <small class="text-muted">Start typing to search. Minimum 2 characters.</small>
                    </div>

                    <?php if ($selected_student): ?>
                    <!-- Selected Student Card -->
                    <div class="selected-student-card mb-4">
                        <button type="button" class="selected-student-remove" onclick="clearStudent()" title="Remove student">
                            <i class="fas fa-times"></i>
                        </button>
                        <div class="d-flex align-items-center gap-3">
                            <div class="selected-student-avatar">
                                <?php echo strtoupper(substr($selected_student['first_name'], 0, 1) . substr($selected_student['last_name'], 0, 1)); ?>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-1"><?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?></h5>
                                <div class="d-flex gap-3 text-muted small">
                                    <span><i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($selected_student['matric_number']); ?></span>
                                    <span><i class="fas fa-graduation-cap me-1"></i>Level <?php echo $selected_student['current_level']; ?></span>
                                    <span><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($selected_student['department_name'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Fees -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Select Fee to Pay (Optional)</label>
                        <div class="table-responsive">
                            <table class="fee-table">
                                <thead>
                                    <tr>
                                        <th width="40"></th>
                                        <th>Invoice</th>
                                        <th>Type</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th>Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($student_fees)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            No pending fees. Student is fully paid or no fees generated yet.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($student_fees as $fee): ?>
                                    <tr class="fee-row" onclick="selectFee(this, <?php echo $fee['fee_id']; ?>, <?php echo (float)$fee['balance']; ?>, '<?php echo htmlspecialchars($fee['invoice_number'] ?? ''); ?>')">
                                        <td>
                                            <input type="radio" name="fee_id" value="<?php echo $fee['fee_id']; ?>" 
                                                   class="form-check-input fee-radio" style="pointer-events: none;">
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($fee['session_year'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge-pro badge-pro-primary"><?php echo $fee['fee_type']; ?></span>
                                        </td>
                                        <td class="text-end">₦<?php echo number_format((float)$fee['amount'], 2); ?></td>
                                        <td class="text-end text-success">₦<?php echo number_format((float)$fee['amount_paid'], 2); ?></td>
                                        <td class="text-end fw-bold text-danger">₦<?php echo number_format((float)$fee['balance'], 2); ?></td>
                                        <td>
                                            <?php if ($fee['due_date']): ?>
                                                <?php echo date('M d', strtotime($fee['due_date'])); ?>
                                                <?php if (strtotime($fee['due_date']) < time()): ?>
                                                    <span class="badge-pro badge-pro-danger">Overdue</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="invoice_number" id="invoiceNumberField">
                        <small class="text-muted">Click a row to auto-fill the payment amount. Leave unselected for miscellaneous payment.</small>
                    </div>

                    <!-- Payment Amount -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Payment Amount <span class="text-danger">*</span></label>
                        <div class="amount-input-group">
                            <span class="amount-input-prefix">₦</span>
                            <input type="number" name="amount" id="amount" class="amount-input" 
                                   step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Payment Method <span class="text-danger">*</span></label>
                        <div class="payment-methods">
                            <div class="payment-method-card" data-method="Cash" onclick="selectMethod(this)">
                                <i class="fas fa-money-bill-wave text-success"></i>
                                <span>Cash</span>
                            </div>
                            <div class="payment-method-card" data-method="Bank Transfer" onclick="selectMethod(this)">
                                <i class="fas fa-university text-primary"></i>
                                <span>Bank Transfer</span>
                            </div>
                            <div class="payment-method-card" data-method="Online" onclick="selectMethod(this)">
                                <i class="fas fa-globe text-info"></i>
                                <span>Online</span>
                            </div>
                            <div class="payment-method-card" data-method="Card" onclick="selectMethod(this)">
                                <i class="fas fa-credit-card text-warning"></i>
                                <span>Card</span>
                            </div>
                            <div class="payment-method-card" data-method="Bank Draft" onclick="selectMethod(this)">
                                <i class="fas fa-file-invoice text-dark"></i>
                                <span>Bank Draft</span>
                            </div>
                            <div class="payment-method-card" data-method="Cheque" onclick="selectMethod(this)">
                                <i class="fas fa-money-check text-danger"></i>
                                <span>Cheque</span>
                            </div>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethodField" required>
                    </div>

                    <!-- Transaction Details (conditional) -->
                    <div id="transactionDetails" style="display: none;">
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Transaction ID / Reference</label>
                                <input type="text" class="form-pro w-100" name="transaction_id" placeholder="e.g., TRX123456789">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payer Name</label>
                                <input type="text" class="form-pro w-100" name="payer_name" 
                                       value="<?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name</label>
                                <input type="text" class="form-pro w-100" name="bank_name" placeholder="e.g., First Bank">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account Number</label>
                                <input type="text" class="form-pro w-100" name="account_number" placeholder="e.g., 0123456789">
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="mb-4">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-pro w-100" name="remarks" rows="2" placeholder="Optional notes..."></textarea>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="manage_fees.php" class="btn-pro btn-pro-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <div class="d-flex gap-2">
                            <button type="submit" name="print_receipt" value="1" class="btn-pro btn-pro-success">
                                <i class="fas fa-print me-2"></i>Save & Print
                            </button>
                            <button type="submit" class="btn-pro btn-pro-primary">
                                <i class="fas fa-save me-2"></i>Save Payment
                            </button>
                        </div>
                    </div>

                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Today's Stats -->
        <div class="app-card mb-4">
            <div class="card-header">
                <i class="fas fa-calendar-day text-success me-2"></i>Today's Summary
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="text-muted small">Total Collected Today</div>
                    <div class="fw-bold fs-2 text-success">₦<?php echo number_format($today_stats['total'], 2); ?></div>
                </div>
                <div class="d-flex justify-content-around text-center">
                    <div>
                        <div class="fw-bold"><?php echo $today_stats['count']; ?></div>
                        <div class="text-muted small">Transactions</div>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo number_format($today_stats['count'] > 0 ? $today_stats['total'] / $today_stats['count'] : 0, 0); ?></div>
                        <div class="text-muted small">Avg Amount</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="app-card">
            <div class="card-header">
                <i class="fas fa-history text-primary me-2"></i>Recent Payments
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_payments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox mb-2"></i>
                    <p class="mb-0">No recent payments</p>
                </div>
                <?php else: ?>
                <?php foreach ($recent_payments as $payment): ?>
                <div class="recent-payment-item">
                    <div class="d-flex align-items-center gap-2">
                        <div class="recent-payment-avatar">
                            <?php echo strtoupper(substr($payment['first_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold small"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></div>
                            <div class="text-muted small"><?php echo htmlspecialchars($payment['fee_type'] ?? 'Misc'); ?></div>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold text-success">₦<?php echo number_format((float)$payment['amount'], 2); ?></div>
                        <div class="text-muted small"><?php echo date('M d, H:i', strtotime($payment['payment_date'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Student Search
const searchInput = document.getElementById('studentSearch');
const searchResults = document.getElementById('searchResults');
const studentIdField = document.getElementById('studentIdField');
const clearBtn = document.getElementById('clearSearch');
let searchTimeout;

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

        if (query.length < 2) {
            searchResults.classList.remove('active');
            return;
        }

        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => performSearch(query), 300);
    });

    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) {
            searchResults.classList.add('active');
        }
    });
}

// Close search when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.student-search-container')) {
        searchResults.classList.remove('active');
    }
});

function performSearch(query) {
    searchResults.innerHTML = '<div class="search-loading"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</div>';
    searchResults.classList.add('active');

    // Use current page URL with ajax parameter
    const url = new URL(window.location.href);
    url.searchParams.set('q', query);
    url.searchParams.set('ajax', '1');

    fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(data => {
        if (data.error) {
            searchResults.innerHTML = '<div class="search-error"><i class="fas fa-exclamation-triangle me-2"></i>' + data.error + '</div>';
            return;
        }

        if (data.length === 0) {
            searchResults.innerHTML = `
                <div class="search-no-results">
                    <i class="fas fa-search mb-2 d-block" style="font-size: 1.5rem;"></i>
                    <p class="mb-0">No students found for "<strong>${escapeHtml(query)}</strong>"</p>
                    <small class="text-muted">Try a different search term</small>
                </div>
            `;
            return;
        }

        let html = '';
        data.forEach(student => {
            html += `
                <div class="search-result-item" onclick="selectStudent(${parseInt(student.student_id)}, '${escapeHtml(student.matric_number)}', '${escapeHtml(student.first_name + ' ' + student.last_name)}')">
                    <span class="search-result-matric">${escapeHtml(student.matric_number)}</span>
                    <span class="search-result-name">${escapeHtml(student.first_name + ' ' + student.last_name)}</span>
                    <span class="search-result-level">${parseInt(student.current_level)}L</span>
                    <div class="search-result-dept">${escapeHtml(student.department_name || 'N/A')}</div>
                </div>
            `;
        });
        searchResults.innerHTML = html;
    })
    .catch(error => {
        console.error('Search error:', error);
        searchResults.innerHTML = `
            <div class="search-error">
                <i class="fas fa-exclamation-circle mb-2 d-block" style="font-size: 1.5rem;"></i>
                <p class="mb-1"><strong>Error searching</strong></p>
                <small class="text-muted">${escapeHtml(error.message)}</small>
                <br><small class="text-muted">Check browser console for details</small>
            </div>
        `;
    });
}

function selectStudent(id, matric, name) {
    window.location.href = 'record_payment.php?student_id=' + id;
}

function clearStudent() {
    window.location.href = 'record_payment.php';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fee Selection
function selectFee(row, feeId, balance, invoice) {
    document.querySelectorAll('.fee-row').forEach(r => r.classList.remove('selected'));
    row.classList.add('selected');
    const radio = row.querySelector('.fee-radio');
    if (radio) radio.checked = true;

    const amountInput = document.getElementById('amount');
    if (amountInput) amountInput.value = balance.toFixed(2);

    const invoiceField = document.getElementById('invoiceNumberField');
    if (invoiceField) invoiceField.value = invoice;
}

// Payment Method Selection
function selectMethod(card) {
    document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    const method = card.dataset.method;
    const methodField = document.getElementById('paymentMethodField');
    if (methodField) methodField.value = method;

    const details = document.getElementById('transactionDetails');
    if (details) {
        if (method === 'Bank Transfer' || method === 'Online' || method === 'Card' || method === 'Cheque') {
            details.style.display = 'block';
        } else {
            details.style.display = 'none';
        }
    }
}

// Form Validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const studentId = document.getElementById('studentIdField').value;
    const amount = parseFloat(document.getElementById('amount').value);
    const method = document.getElementById('paymentMethodField').value;

    if (!studentId) {
        e.preventDefault();
        alert('Please search and select a student first.');
        if (searchInput) searchInput.focus();
        return false;
    }

    if (!amount || amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount.');
        document.getElementById('amount').focus();
        return false;
    }

    if (!method) {
        e.preventDefault();
        alert('Please select a payment method.');
        return false;
    }

    return true;
});

// Auto-hide toasts
setTimeout(() => {
    document.querySelectorAll('.toast-pro').forEach(t => {
        t.style.opacity = '0';
        t.style.transform = 'translateX(20px)';
        t.style.transition = 'all 0.3s ease';
        setTimeout(() => t.remove(), 300);
    });
}, 5000);
</script>

<?php
require_once 'includes/footer.php';
?>