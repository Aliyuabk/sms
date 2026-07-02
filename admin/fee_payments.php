<?php
// fee_payments.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Fee Payments";

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify payment
    if (isset($_POST['verify_payment'])) {
        try {
            $payment_id = (int)$_POST['payment_id'];
            $admin_id = $_SESSION['admin_id'];

            $stmt = $pdo->prepare("
                UPDATE payments SET 
                    status = 'Verified',
                    verified_by = ?,
                    verification_date = CURDATE()
                WHERE payment_id = ?
            ");
            $stmt->execute([$admin_id, $payment_id]);

            // Update student_fees balance
            $payment = $pdo->prepare("SELECT student_id, fee_id, amount FROM payments WHERE payment_id = ?");
            $payment->execute([$payment_id]);
            $payment_data = $payment->fetch();

            if ($payment_data && $payment_data['fee_id']) {
                $update_fee = $pdo->prepare("
                    UPDATE student_fees SET 
                        amount_paid = amount_paid + ?,
                        status = CASE 
                            WHEN amount_paid >= amount THEN 'Paid'
                            ELSE 'Partial'
                        END
                    WHERE fee_id = ?
                ");
                $update_fee->execute([$payment_data['amount'], $payment_data['fee_id']]);
            }

            $_SESSION['success_message'] = "Payment verified successfully!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error verifying payment: " . $e->getMessage();
        }

        header("Location: fee_payments.php");
        exit();
    }

    // Reject payment
    if (isset($_POST['reject_payment'])) {
        try {
            $payment_id = (int)$_POST['payment_id'];
            $rejection_reason = $_POST['rejection_reason'] ?? 'Payment rejected';

            $stmt = $pdo->prepare("
                UPDATE payments SET 
                    status = 'Failed',
                    remarks = ?
                WHERE payment_id = ?
            ");
            $stmt->execute([$rejection_reason, $payment_id]);

            $_SESSION['success_message'] = "Payment rejected!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: fee_payments.php");
        exit();
    }

    // Record manual payment
    if (isset($_POST['record_payment'])) {
        try {
            $student_id = (int)$_POST['student_id'];
            $fee_id = !empty($_POST['fee_id']) ? (int)$_POST['fee_id'] : null;
            $amount = floatval($_POST['amount']);
            $payment_method = $_POST['payment_method'];
            $transaction_id = $_POST['transaction_id'] ?? null;
            $bank_name = $_POST['bank_name'] ?? null;
            $payer_name = $_POST['payer_name'] ?? null;
            $remarks = $_POST['remarks'] ?? null;

            // Validate amount
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than zero.");
            }

            // Generate receipt number
            $receipt_no = 'RCP-' . date('Ymd') . '-' . rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    student_id, fee_id, amount, payment_method, transaction_id,
                    bank_name, payer_name, payment_date, receipt_number, status,
                    verified_by, verification_date, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'Verified', ?, CURDATE(), ?)
            ");

            $stmt->execute([
                $student_id, $fee_id, $amount, $payment_method, $transaction_id,
                $bank_name, $payer_name, $receipt_no, $_SESSION['admin_id'], $remarks
            ]);

            // Update student_fees if fee_id provided
            if ($fee_id) {
                $update_fee = $pdo->prepare("
                    UPDATE student_fees SET 
                        amount_paid = amount_paid + ?,
                        status = CASE 
                            WHEN amount_paid >= amount THEN 'Paid'
                            ELSE 'Partial'
                        END
                    WHERE fee_id = ?
                ");
                $update_fee->execute([$amount, $fee_id]);
            }

            $_SESSION['success_message'] = "Payment recorded successfully! Receipt: $receipt_no";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error recording payment: " . $e->getMessage();
        }

        header("Location: fee_payments.php");
        exit();
    }

    // Bulk verify payments
    if (isset($_POST['bulk_verify'])) {
        try {
            $payment_ids = $_POST['payment_ids'] ?? [];

            if (empty($payment_ids)) {
                throw new Exception("No payments selected");
            }

            // Sanitize IDs
            $payment_ids = array_map('intval', $payment_ids);
            $placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
            $admin_id = $_SESSION['admin_id'];

            $pdo->beginTransaction();

            // Update payments
            $stmt = $pdo->prepare("
                UPDATE payments SET 
                    status = 'Verified',
                    verified_by = ?,
                    verification_date = CURDATE()
                WHERE payment_id IN ($placeholders)
            ");
            $params = array_merge([$admin_id], $payment_ids);
            $stmt->execute($params);

            // Update corresponding student fees
            foreach ($payment_ids as $pid) {
                $payment = $pdo->prepare("SELECT student_id, fee_id, amount FROM payments WHERE payment_id = ?");
                $payment->execute([$pid]);
                $pdata = $payment->fetch();

                if ($pdata && $pdata['fee_id']) {
                    $update = $pdo->prepare("
                        UPDATE student_fees SET 
                            amount_paid = amount_paid + ?,
                            status = CASE 
                                WHEN amount_paid >= amount THEN 'Paid'
                                ELSE 'Partial'
                            END
                        WHERE fee_id = ?
                    ");
                    $update->execute([$pdata['amount'], $pdata['fee_id']]);
                }
            }

            $pdo->commit();
            $_SESSION['success_message'] = count($payment_ids) . " payments verified successfully!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: fee_payments.php");
        exit();
    }

    // Generate invoice
    if (isset($_POST['generate_invoice'])) {
        try {
            $student_id = (int)$_POST['student_id'];
            $fee_structure_id = (int)$_POST['fee_structure_id'];
            $session_year = $_POST['session_year'];
            $semester = (int)$_POST['semester'];
            $custom_amount = !empty($_POST['custom_amount']) ? floatval($_POST['custom_amount']) : null;

            // Get fee structure details
            $fee = $pdo->prepare("SELECT * FROM fee_structure WHERE fee_structure_id = ?");
            $fee->execute([$fee_structure_id]);
            $fee_data = $fee->fetch();

            if (!$fee_data) {
                throw new Exception("Fee structure not found");
            }

            $amount = $custom_amount ?? $fee_data['amount'];
            $invoice_no = 'INV-' . $session_year . '-' . $student_id . '-' . rand(1000, 9999);

            // Check if already exists
            $check = $pdo->prepare("
                SELECT fee_id FROM student_fees 
                WHERE student_id = ? AND fee_structure_id = ? AND session_year = ?
            ");
            $check->execute([$student_id, $fee_structure_id, $session_year]);

            if ($check->rowCount() > 0) {
                throw new Exception("Invoice already exists for this student!");
            }

            $insert = $pdo->prepare("
                INSERT INTO student_fees (
                    student_id, fee_structure_id, session_year, semester,
                    fee_type, description, amount, due_date, status, invoice_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");

            $insert->execute([
                $student_id, $fee_structure_id, $session_year, $semester,
                $fee_data['fee_type'], $fee_data['description'], $amount,
                $fee_data['due_date'], $invoice_no
            ]);

            $_SESSION['success_message'] = "Invoice generated successfully!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: fee_payments.php");
        exit();
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_method = isset($_GET['method']) ? $_GET['method'] : '';
$filter_session = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build query conditions
$conditions = [];
$params = [];

if (!empty($filter_status)) {
    $conditions[] = "p.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_method)) {
    $conditions[] = "p.payment_method = ?";
    $params[] = $filter_method;
}

if (!empty($filter_session)) {
    $conditions[] = "sf.session_year = ?";
    $params[] = $filter_session;
}

if (!empty($filter_date_from)) {
    $conditions[] = "DATE(p.payment_date) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $conditions[] = "DATE(p.payment_date) <= ?";
    $params[] = $filter_date_to;
}

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR p.receipt_number LIKE ? OR p.transaction_id LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total records count
$count_sql = "
    SELECT COUNT(*) 
    FROM payments p
    JOIN students s ON p.student_id = s.student_id
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    $where_clause
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get payments
$payments_sql = "
    SELECT 
        p.*,
        s.matric_number,
        s.first_name,
        s.last_name,
        s.email,
        s.phone,
        sf.fee_type,
        sf.description as fee_description,
        sf.amount as fee_amount,
        sf.invoice_number,
        sf.session_year,
        sf.semester,
        a.full_name as verified_by_name
    FROM payments p
    JOIN students s ON p.student_id = s.student_id
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    LEFT JOIN admin_users a ON p.verified_by = a.admin_id
    $where_clause
    ORDER BY p.payment_date DESC
    LIMIT $offset, $records_per_page
";

$payments_stmt = $pdo->prepare($payments_sql);
$payments_stmt->execute($params);
$payments = $payments_stmt->fetchAll();

// Get filter data
$sessions = $pdo->query("SELECT DISTINCT session_year FROM student_fees ORDER BY session_year DESC")->fetchAll();
$payment_methods = ['Cash', 'Bank Transfer', 'Online', 'Card', 'Bank Draft', 'Cheque'];
$statuses = ['Pending', 'Verified', 'Failed', 'Refunded'];

// Get statistics
$stats = [
    'total_payments' => $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn(),
    'verified' => $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Verified'")->fetchColumn(),
    'pending' => $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'")->fetchColumn(),
    'total_amount' => $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'Verified'")->fetchColumn(),
    'today_amount' => $pdo->query("SELECT SUM(amount) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = 'Verified'")->fetchColumn(),
    'today_count' => $pdo->query("SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn(),
];

// Get students for dropdown
$students = $pdo->query("
    SELECT student_id, matric_number, first_name, last_name 
    FROM students WHERE status = 'Active' 
    ORDER BY matric_number LIMIT 500
")->fetchAll();

// Get fee structures for invoice generation
$fee_structures = $pdo->query("
    SELECT fs.*, p.program_name 
    FROM fee_structure fs
    LEFT JOIN programs p ON fs.program_id = p.program_id
    ORDER BY fs.session_year DESC, fs.level
")->fetchAll();

// Get pending invoices
$pending_invoices = $pdo->query("
    SELECT sf.*, s.matric_number, s.first_name, s.last_name
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    WHERE sf.status IN ('Pending', 'Partial')
    ORDER BY sf.due_date ASC
    LIMIT 10
")->fetchAll();
?>

<!-- Display Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-credit-card me-2"></i>Fee Payments
    </h1>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
            <i class="fas fa-plus me-2"></i>Record Payment
        </button>
        <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#generateInvoiceModal">
            <i class="fas fa-file-invoice me-2"></i>Generate Invoice
        </button>
        <a href="fees.php" class="btn btn-info ms-2">
            <i class="fas fa-cog me-2"></i>Manage Fees
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title">Total Payments</h6>
                <h3><?php echo number_format($stats['total_payments']); ?></h3>
                <small><?php echo number_format($stats['verified']); ?> verified</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Total Amount</h6>
                <h3>₦<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></h3>
                <small>Verified payments</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">Pending</h6>
                <h3><?php echo number_format($stats['pending']); ?></h3>
                <small>Awaiting verification</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">Today</h6>
                <h3>₦<?php echo number_format($stats['today_amount'] ?? 0, 2); ?></h3>
                <small><?php echo $stats['today_count']; ?> payments</small>
            </div>
        </div>
    </div>
</div>

<!-- Pending Invoices Alert -->
<?php if (!empty($pending_invoices)): ?>
<div class="alert alert-warning alert-dismissible fade show mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?php echo count($pending_invoices); ?> pending invoices</strong> approaching due date
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <div class="mt-2 small">
        <?php foreach ($pending_invoices as $inv): ?>
        <span class="badge bg-warning me-2">
            <?php echo htmlspecialchars($inv['matric_number']); ?> - ₦<?php echo number_format($inv['balance'] ?? $inv['amount'], 2); ?>
            (Due: <?php echo $inv['due_date'] ? date('M d', strtotime($inv['due_date'])) : 'N/A'; ?>)
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Payments</h5>
    </div>
    <div class="card-body">
        <form method="GET" id="filterForm" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Matric, name, receipt...">
            </div>

            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-control" name="status">
                    <option value="">All</option>
                    <?php foreach ($statuses as $stat): ?>
                    <option value="<?php echo $stat; ?>" <?php echo $filter_status == $stat ? 'selected' : ''; ?>>
                        <?php echo $stat; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Payment Method</label>
                <select class="form-control" name="method">
                    <option value="">All</option>
                    <?php foreach ($payment_methods as $method): ?>
                    <option value="<?php echo $method; ?>" <?php echo $filter_method == $method ? 'selected' : ''; ?>>
                        <?php echo $method; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Session</label>
                <select class="form-control" name="session_year">
                    <option value="">All</option>
                    <?php foreach ($sessions as $session): ?>
                    <option value="<?php echo htmlspecialchars($session['session_year']); ?>"
                        <?php echo $filter_session == $session['session_year'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($session['session_year']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Date Range</label>
                <div class="input-group">
                    <input type="date" class="form-control" name="date_from" value="<?php echo $filter_date_from; ?>">
                    <span class="input-group-text">to</span>
                    <input type="date" class="form-control" name="date_to" value="<?php echo $filter_date_to; ?>">
                </div>
            </div>
        </form>

        <div class="row mt-3">
            <div class="col-12 text-end">
                <button type="submit" form="filterForm" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i>Apply Filters
                </button>
                <a href="fee_payments.php" class="btn btn-secondary ms-2">
                    <i class="fas fa-redo me-1"></i>Reset
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Payment Records</h5>
        <div>
            <span class="text-muted me-3">
                Showing <?php echo min($offset + 1, $total_records); ?> - 
                <?php echo min($offset + $records_per_page, $total_records); ?> 
                of <?php echo $total_records; ?>
            </span>
            <button class="btn btn-sm btn-warning" onclick="showBulkVerify()">
                <i class="fas fa-check-double me-1"></i>Bulk Verify
            </button>
            <a href="export_payments.php?<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success ms-2">
                <i class="fas fa-download me-1"></i>Export
            </a>
        </div>
    </div>

    <div class="card-body p-0">
        <form method="POST" id="bulkVerifyForm" style="display: none;" class="p-3 bg-light border-bottom">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <p class="mb-0">
                        <strong>Bulk Verify Payments</strong> - 
                        <span id="selectedCount">0</span> payments selected
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <input type="hidden" name="payment_ids" id="selectedPayments">
                    <button type="submit" name="bulk_verify" class="btn btn-warning" onclick="return confirmBulkVerify()">
                        <i class="fas fa-check-double me-2"></i>Verify Selected
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="hideBulkVerify()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                        </th> 
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Verified By</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <tr class="<?php echo $payment['status'] == 'Pending' ? 'table-warning' : ''; ?>">
                        <td>
                            <input type="checkbox" class="payment-checkbox" value="<?php echo $payment['payment_id']; ?>"
                                   <?php echo $payment['status'] == 'Pending' ? '' : 'disabled'; ?>>
                        </td> 
                        <td>
                            <strong><?php echo htmlspecialchars($payment['matric_number']); ?></strong>
                            <br>
                            <small><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></small>
                            <br>
                            <small class="text-muted"><?php echo htmlspecialchars($payment['email']); ?></small>
                        </td>
                        <td>
                            <?php if ($payment['fee_type']): ?>
                                <span class="badge bg-info"><?php echo htmlspecialchars($payment['fee_type']); ?></span>
                                <br>
                                <small><?php echo htmlspecialchars($payment['fee_description']); ?></small>
                            <?php else: ?>
                                <span class="badge bg-secondary">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-primary">₦<?php echo number_format($payment['amount'], 2); ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                            <?php if ($payment['transaction_id']): ?>
                            <br><small>ID: <?php echo htmlspecialchars($payment['transaction_id']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo date('M d, Y H:i', strtotime($payment['payment_date'])); ?>
                        </td>
                        <td>
                            <?php
                            $status_class = [
                                'Verified' => 'success',
                                'Pending' => 'warning',
                                'Failed' => 'danger',
                                'Refunded' => 'info'
                            ][$payment['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo $payment['status']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($payment['verified_by_name']): ?>
                                <small><?php echo htmlspecialchars($payment['verified_by_name']); ?></small>
                                <br>
                                <small class="text-muted"><?php echo $payment['verification_date'] ? date('M d', strtotime($payment['verification_date'])) : ''; ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick="viewPayment(<?php echo $payment['payment_id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($payment['status'] == 'Pending'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                    <button type="submit" name="verify_payment" class="btn btn-sm btn-success" 
                                            onclick="return confirm('Verify this payment?')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-danger" onclick="rejectPayment(<?php echo $payment['payment_id']; ?>)">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i >= $current_page - 2 && $i <= $current_page + 2): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endfor; ?>

                <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Record Manual Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="recordPaymentForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Student *</label>
                            <select class="form-control" name="student_id" required>
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['student_id']; ?>">
                                    <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Invoice (Optional)</label>
                            <select class="form-control" name="fee_id" id="feeSelect">
                                <option value="">Manual Payment (No Invoice)</option>
                                <?php
                                $unpaid_invoices = $pdo->query("
                                    SELECT sf.*, s.matric_number 
                                    FROM student_fees sf
                                    JOIN students s ON sf.student_id = s.student_id
                                    WHERE sf.status IN ('Pending', 'Partial')
                                    ORDER BY sf.due_date
                                ")->fetchAll();
                                foreach ($unpaid_invoices as $inv):
                                ?>
                                <option value="<?php echo $inv['fee_id']; ?>" 
                                        data-amount="<?php echo $inv['balance'] ?? $inv['amount']; ?>">
                                    <?php echo htmlspecialchars($inv['invoice_number'] . ' - ' . $inv['fee_type'] . ' (₦' . number_format($inv['balance'] ?? $inv['amount'], 2) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" id="paymentAmount" min="1" step="100" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Payment Method *</label>
                            <select class="form-control" name="payment_method" required>
                                <option value="">Select Method</option>
                                <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Transaction ID</label>
                            <input type="text" class="form-control" name="transaction_id" 
                                   placeholder="Reference number">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Bank Name</label>
                            <input type="text" class="form-control" name="bank_name" 
                                   placeholder="e.g., Access Bank">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Payer Name</label>
                            <input type="text" class="form-control" name="payer_name" 
                                   placeholder="Name of person making payment">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2"></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Payment will be automatically verified and applied to the student's account.
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="record_payment" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Generate Invoice Modal -->
<div class="modal fade" id="generateInvoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Generate Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="generateInvoiceForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Student *</label>
                        <select class="form-control" name="student_id" required>
                            <option value="">Select Student</option>
                            <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>">
                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Fee Structure *</label>
                        <select class="form-control" name="fee_structure_id" required>
                            <option value="">Select Fee Type</option>
                            <?php foreach ($fee_structures as $fee): ?>
                            <option value="<?php echo $fee['fee_structure_id']; ?>" 
                                    data-amount="<?php echo $fee['amount']; ?>">
                                <?php echo htmlspecialchars($fee['fee_type'] . ' - ' . $fee['session_year'] . ' (Level ' . $fee['level'] . ') - ₦' . number_format($fee['amount'], 2)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Year *</label>
                            <select class="form-control" name="session_year" required>
                                <option value="">Select Session</option>
                                <?php 
                                $year = date('Y');
                                for ($y = $year - 1; $y <= $year + 3; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Semester</label>
                            <select class="form-control" name="semester">
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Custom Amount (Optional)</label>
                        <input type="number" class="form-control" name="custom_amount" id="customAmount" min="0" step="100">
                        <small class="text-muted">Leave empty to use default fee amount</small>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will create a new invoice for the student. Ensure the student doesn't already have this fee.
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="generate_invoice" class="btn btn-success">
                            <i class="fas fa-file-invoice me-2"></i>Generate Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Reject Payment Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-times me-2"></i>Reject Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="rejectForm">
                    <input type="hidden" name="payment_id" id="reject_payment_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Rejection</label>
                        <textarea class="form-control" name="rejection_reason" rows="3" required></textarea>
                    </div>

                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        This action cannot be undone. The payment will be marked as failed.
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reject_payment" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Reject Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Payment Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Payment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="paymentDetails">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printReceipt()">
                    <i class="fas fa-print me-2"></i>Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Toggle all checkboxes
function toggleAll(source) {
    document.querySelectorAll('.payment-checkbox:not([disabled])').forEach(cb => {
        cb.checked = source.checked;
    });
    updateSelectedCount();
}

// Update selected count
function updateSelectedCount() {
    const selected = document.querySelectorAll('.payment-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selected;

    const selectedIds = [];
    document.querySelectorAll('.payment-checkbox:checked').forEach(cb => {
        selectedIds.push(cb.value);
    });
    document.getElementById('selectedPayments').value = JSON.stringify(selectedIds);
}

// Add change event to checkboxes
document.querySelectorAll('.payment-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Show bulk verify
function showBulkVerify() {
    const selected = document.querySelectorAll('.payment-checkbox:checked').length;
    if (selected === 0) {
        alert('Please select at least one pending payment.');
        return;
    }
    document.getElementById('bulkVerifyForm').style.display = 'block';
}

// Hide bulk verify
function hideBulkVerify() {
    document.getElementById('bulkVerifyForm').style.display = 'none';
}

// Confirm bulk verify
function confirmBulkVerify() {
    const selected = document.querySelectorAll('.payment-checkbox:checked').length;
    return confirm(`Verify ${selected} selected payment(s)?`);
}

// View payment details
function viewPayment(paymentId) {
    document.getElementById('paymentDetails').innerHTML = 'Loading...';

    fetch(`get_payment_details.php?id=${paymentId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('paymentDetails').innerHTML = html;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        })
        .catch(error => {
            document.getElementById('paymentDetails').innerHTML = 'Error loading payment details.';
        });
}

// Reject payment
function rejectPayment(paymentId) {
    document.getElementById('reject_payment_id').value = paymentId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

// Print receipt
function printReceipt() {
    const printContent = document.getElementById('paymentDetails').innerHTML;
    const originalContent = document.body.innerHTML;

    document.body.innerHTML = `
        <div style="padding: 20px; max-width: 800px; margin: 0 auto;">
            <h2 style="text-align: center;">Payment Receipt</h2>
            ${printContent}
        </div>
    `;

    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

// Auto-fill amount when fee selected
document.getElementById('feeSelect')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const amount = selected.dataset.amount;
    if (amount) {
        document.getElementById('paymentAmount').value = amount;
    }
});

// Update custom amount based on fee structure
document.querySelector('select[name="fee_structure_id"]')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const amount = selected.dataset.amount;
    if (amount) {
        document.getElementById('customAmount').placeholder = `Default: ₦${amount}`;
    }
});

// Form validations
document.getElementById('recordPaymentForm')?.addEventListener('submit', function(e) {
    const amount = parseFloat(this.querySelector('input[name="amount"]').value);
    if (isNaN(amount) || amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount greater than zero.');
        return false;
    }
});

document.getElementById('generateInvoiceForm')?.addEventListener('submit', function(e) {
    const student = this.querySelector('select[name="student_id"]').value;
    const fee = this.querySelector('select[name="fee_structure_id"]').value;

    if (!student || !fee) {
        e.preventDefault();
        alert('Please select student and fee type.');
        return false;
    }
});

// Initialize
updateSelectedCount();
</script>

<style>
.modal-header.bg-primary .btn-close-white,
.modal-header.bg-success .btn-close-white,
.modal-header.bg-danger .btn-close-white,
.modal-header.bg-info .btn-close-white {
    filter: brightness(0) invert(1);
}
.table-warning {
    background-color: #fff3cd;
}
.payment-checkbox:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}
</style>

<?php
require_once 'includes/footer.php';
?>