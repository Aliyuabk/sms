<?php
/**
 * Payment Gateway Integration - Gateway Only
 * Supports: Paystack, Remita, Interswitch
 */
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'] ?? 0;
if (!$student_id) {
    header('Location: login.php');
    exit;
}

// Get current active session
$session_query = "SELECT session_year, semester, session_name 
                  FROM academic_sessions 
                  WHERE is_current = 1 AND status = 'Active' 
                  LIMIT 1";
$session_result = $conn->query($session_query);
$session_data = $session_result->fetch_assoc() ?? [
    'session_year' => '2025/2026',
    'semester' => 1,
    'session_name' => 'First Semester 2025/2026'
];
$current_session = $session_data['session_year'];
$current_semester = (int)$session_data['semester'];

// Get student details
$student_query = "SELECT s.*, d.department_name, p.program_name
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student not found.");
}

// Get outstanding fee for current session/semester
$fee_query = "SELECT * FROM student_fees 
              WHERE student_id = ? 
              AND session_year = ? 
              AND semester = ?
              AND status IN ('Pending', 'Partial', 'Overdue')
              ORDER BY fee_id DESC 
              LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$fee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate outstanding
$total_paid = 0;
if ($fee) {
    $paid_query = "SELECT COALESCE(SUM(amount), 0) as total FROM payments 
                   WHERE student_id = ? AND fee_id = ? AND status = 'Verified'";
    $stmt = $conn->prepare($paid_query);
    $stmt->bind_param("ii", $student_id, $fee['fee_id']);
    $stmt->execute();
    $total_paid = (float)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
}

$total_fees = (float)($fee['amount'] ?? 0);
$outstanding = max(0, $total_fees - $total_paid);

// Determine student type for fee structure fallback
$student_level = (int)($student['current_level'] ?? 100);
$student_type = 'Returning Students';
$admission_year = $student['admission_year'] ? (int)$student['admission_year'] : 0;
$current_year = (int)date('Y');
if ($student_level == 100 || ($admission_year > 0 && ($current_year - $admission_year) <= 1)) {
    $student_type = 'New Students';
}
$duration_years = (int)($student['duration_years'] ?? 4);
if ($student_level >= ($duration_years * 100)) {
    $student_type = 'Final Year';
}

// If no fee record, try to get from fee_structure
if (!$fee || $outstanding <= 0) {
    $fs_query = "SELECT * FROM fee_structure 
                 WHERE level = ? AND session_year = ?
                 AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
                 AND (semester = ? OR semester IS NULL OR semester = 1)
                 AND is_mandatory = 1";
    $stmt = $conn->prepare($fs_query);
    $stmt->bind_param("issi", $student_level, $current_session, $student_type, $current_semester);
    $stmt->execute();
    $fs_result = $stmt->get_result();
    $stmt->close();

    $fs_total = 0;
    while ($f = $fs_result->fetch_assoc()) {
        $fs_total += (float)$f['amount'];
    }

    if ($fs_total > 0) {
        // Auto-create student_fees record
        $invoice = 'INV-' . str_replace('/', '', $current_session) . '-' . str_pad($student_id, 5, '0', STR_PAD_LEFT) . '-' . $current_semester;
        $due_date = date('Y-m-d', strtotime('+30 days'));

        $insert_fee = "INSERT INTO student_fees 
            (student_id, session_year, semester, fee_type, description, amount, due_date, payment_deadline, status, invoice_number)
            VALUES (?, ?, ?, 'Tuition', 'Session Fees', ?, ?, ?, 'Pending', ?)";
        $stmt = $conn->prepare($insert_fee);
        $stmt->bind_param("isidsss", $student_id, $current_session, $current_semester, $fs_total, $due_date, $due_date, $invoice);
        $stmt->execute();
        $new_fee_id = $stmt->insert_id;
        $stmt->close();

        // Re-fetch
        $stmt = $conn->prepare($fee_query);
        $stmt->bind_param("isi", $student_id, $current_session, $current_semester);
        $stmt->execute();
        $fee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total_fees = $fs_total;
        $outstanding = $fs_total;
    }
}

// Check for pending payment
$pending_payment = null;
if ($outstanding > 0 && $fee) {
    $pending_query = "SELECT * FROM payments 
                      WHERE student_id = ? AND fee_id = ? AND status = 'Pending' 
                      ORDER BY payment_date DESC LIMIT 1";
    $stmt = $conn->prepare($pending_query);
    $stmt->bind_param("ii", $student_id, $fee['fee_id']);
    $stmt->execute();
    $pending_payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_amount = (float)($_POST['amount'] ?? $outstanding);
    $payment_amount = min($payment_amount, $outstanding);
    $fee_id = $fee['fee_id'] ?? 0;

    if (empty($payment_method)) {
        $error = 'Please select a payment gateway.';
    } elseif ($payment_amount <= 0) {
        $error = 'Invalid payment amount.';
    } else {
        // Map gateway to DB enum
        $db_method = match($payment_method) {
            'Paystack', 'Remita', 'Interswitch' => 'Online',
            default => 'Online'
        };

        // Generate transaction reference
        $transaction_ref = strtoupper($payment_method) . '-' . time() . '-' . rand(1000, 9999);
        $receipt_number = 'RCP-' . str_replace('/', '', $current_session) . '-' . str_pad($student_id, 5, '0', STR_PAD_LEFT) . '-' . time();

        // Insert payment record
        $insert = "INSERT INTO payments 
            (student_id, fee_id, invoice_number, amount, payment_method, transaction_id, 
             payer_name, receipt_number, status, payment_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
        $stmt = $conn->prepare($insert);
        $payer_name = trim($student['first_name'] . ' ' . $student['last_name']);
        $invoice = $fee['invoice_number'] ?? 'N/A';
        $stmt->bind_param("iisdssss", $student_id, $fee_id, $invoice, $payment_amount, 
                         $db_method, $transaction_ref, $payer_name, $receipt_number);

        if ($stmt->execute()) {
            $payment_id = $stmt->insert_id;
            $stmt->close();

            // Update fee status to Partial
            $update_fee = "UPDATE student_fees SET status = 'Partial' WHERE fee_id = ? AND status = 'Pending'";
            $stmt = $conn->prepare($update_fee);
            $stmt->bind_param("i", $fee_id);
            $stmt->execute();
            $stmt->close();

            // Redirect to gateway processor
            $gateway_url = match($payment_method) {
                'Paystack' => "process-paystack.php?payment_id=$payment_id&ref=" . urlencode($transaction_ref),
                'Remita' => "process-remita.php?payment_id=$payment_id&ref=" . urlencode($transaction_ref),
                'Interswitch' => "process-interswitch.php?payment_id=$payment_id&ref=" . urlencode($transaction_ref),
                default => "payment-confirmation.php?payment_id=$payment_id"
            };

            header("Location: $gateway_url");
            exit();
        } else {
            $error = "Error processing payment: " . $conn->error;
            $stmt->close();
        }
    }
}
?>

<div class="fade-in">
    <div class="page-header">
        <div class="header-content">
            <h1><i class="fas fa-credit-card"></i> Make Payment</h1>
            <p>Pay your fees securely via trusted payment gateways</p>
            <div class="session-badges">
                <span class="badge-session"><?php echo htmlspecialchars($session_data['session_name']); ?></span>
                <span class="badge-level">Level <?php echo $student_level; ?></span>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <!-- No Outstanding / Already Cleared -->
    <?php if ($outstanding <= 0 && !$pending_payment): ?>
    <div class="card status-card cleared">
        <div class="card-body">
            <i class="fas fa-check-circle"></i>
            <h2>All Fees Cleared!</h2>
            <p>You have no outstanding payments for <?php echo htmlspecialchars($current_session); ?> Semester <?php echo $current_semester; ?>.</p>
            <a href="fees.php" class="btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Fees
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Payment Exists -->
    <?php if ($pending_payment): ?>
    <div class="card status-card pending">
        <div class="card-body">
            <i class="fas fa-clock"></i>
            <h2>Payment Pending Verification</h2>
            <p>You have a pending payment of <strong>₦<?php echo number_format($pending_payment['amount']); ?></strong>.</p>
            <p class="txn-ref">Transaction Ref: <code><?php echo htmlspecialchars($pending_payment['transaction_id']); ?></code></p>
            <div class="pending-actions">
                <button class="btn-primary" onclick="checkPaymentStatus(<?php echo $pending_payment['payment_id']; ?>)">
                    <i class="fas fa-sync-alt"></i> Check Status
                </button>
                <a href="fees.php" class="btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Fees
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Form -->
    <?php if ($outstanding > 0): ?>
    <div class="payment-layout">
        <!-- Left: Summary -->
        <div class="summary-panel">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice"></i> Payment Summary</h3>
                </div>
                <div class="card-body">
                    <div class="student-info">
                        <div class="info-row">
                            <span class="label">Student</span>
                            <span class="value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Matric No</span>
                            <span class="value"><?php echo htmlspecialchars($student['matric_number']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Department</span>
                            <span class="value"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></span>
                        </div>
                    </div>

                    <div class="fee-breakdown">
                        <div class="break-row">
                            <span>Total Fees</span>
                            <span class="amount">₦<?php echo number_format($total_fees); ?></span>
                        </div>
                        <div class="break-row">
                            <span>Amount Paid</span>
                            <span class="amount paid">₦<?php echo number_format($total_paid); ?></span>
                        </div>
                        <div class="break-row highlight">
                            <span>Outstanding</span>
                            <span class="amount due">₦<?php echo number_format($outstanding); ?></span>
                        </div>
                    </div>

                    <?php if ($fee && !empty($fee['due_date'])): 
                        $is_overdue = strtotime($fee['due_date']) < time();
                    ?>
                    <div class="due-date <?php echo $is_overdue ? 'overdue' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        Due: <?php echo date('d M Y', strtotime($fee['due_date'])); ?>
                        <?php if ($is_overdue): ?>
                        <span class="overdue-tag">OVERDUE</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Gateway Selection -->
        <div class="gateway-panel">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-alt"></i> Select Payment Gateway</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="paymentForm">
                        <input type="hidden" name="amount" value="<?php echo $outstanding; ?>">
                        <?php if ($fee): ?>
                        <input type="hidden" name="fee_id" value="<?php echo $fee['fee_id']; ?>">
                        <?php endif; ?>

                        <div class="amount-display">
                            <span class="label">Amount to Pay</span>
                            <span class="value">₦<?php echo number_format($outstanding); ?></span>
                        </div>

                        <div class="gateways">
                            <!-- Paystack -->
                            <label class="gateway-card">
                                <input type="radio" name="payment_method" value="Paystack" required>
                                <div class="gateway-content">
                                    <div class="gateway-logo paystack">
                                        <i class="fas fa-bolt"></i>
                                    </div>
                                    <div class="gateway-info">
                                        <h4>Paystack</h4>
                                        <p>Card, Bank Transfer, USSD, Apple Pay</p>
                                        <div class="gateway-badges">
                                            <span class="badge popular">Popular</span>
                                            <span class="badge instant">Instant</span>
                                        </div>
                                    </div>
                                    <div class="gateway-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>

                            <!-- Remita -->
                            <label class="gateway-card">
                                <input type="radio" name="payment_method" value="Remita">
                                <div class="gateway-content">
                                    <div class="gateway-logo remita">
                                        <i class="fas fa-university"></i>
                                    </div>
                                    <div class="gateway-info">
                                        <h4>Remita</h4>
                                        <p>Internet Banking, POS, Mobile Wallet</p>
                                        <div class="gateway-badges">
                                            <span class="badge">All Banks</span>
                                        </div>
                                    </div>
                                    <div class="gateway-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>

                            <!-- Interswitch -->
                            <label class="gateway-card">
                                <input type="radio" name="payment_method" value="Interswitch">
                                <div class="gateway-content">
                                    <div class="gateway-logo interswitch">
                                        <i class="fas fa-exchange-alt"></i>
                                    </div>
                                    <div class="gateway-info">
                                        <h4>Interswitch</h4>
                                        <p>Verve, Mastercard, Visa, eCash</p>
                                        <div class="gateway-badges">
                                            <span class="badge">Multi-Card</span>
                                        </div>
                                    </div>
                                    <div class="gateway-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="security-badges">
                            <span><i class="fas fa-lock"></i> 256-bit SSL</span>
                            <span><i class="fas fa-shield-alt"></i> PCI-DSS</span>
                            <span><i class="fas fa-check-double"></i> Verified by Visa</span>
                        </div>

                        <button type="submit" name="process_payment" class="btn-pay" id="payBtn" disabled>
                            <span>Proceed to Payment</span>
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
    :root {
    /* Primary: Logo Blue */
    --primary-color: #3f749c;
    --primary-dark: #2a5a7a;
    --primary-light: #5a9bc4;
    --primary-soft: #e8f2f8;
    
    /* Secondary: Logo Lime/Yellow-Green */
    --secondary-color: #c5ea4f;
    --accent-color: #d4f07a;
    
    /* Functional colors */
    --danger-color: #f44336;
    --warning-color: #ff9800;
    --success-color: #7cb342;
    
    /* Text */
    --text-dark: #2c3e50;
    --text-light: #7f8c8d;
    
    /* Neutrals */
    --white: #ffffff;
    --gray-100: #f8f9fa;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    
    /* Shadows & effects */
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
    --shadow: 0 4px 6px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
    --transition: all 0.3s ease;
    
    /* Layout */
    --sidebar-width: 280px;
    --sidebar-collapsed: 80px;
    --header-height: 70px;
}

    .page-header {
        margin-bottom: 30px;
    }
    .header-content h1 {
        font-size: 28px;
        color: var(--text-dark);
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .header-content h1 i { color: var(--primary-color); }
    .header-content p { color: var(--text-light); margin-bottom: 15px; }
    .session-badges { display: flex; gap: 10px; flex-wrap: wrap; }
    .session-badges span {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 30px;
        font-size: 13px; font-weight: 600;
    }
    .badge-session { background: var(--primary-soft); color: var(--primary-color); }
    .badge-level { background: rgba(23, 162, 184, 0.1); color: var(--info-color); }

    .alert {
        padding: 15px 20px; border-radius: 12px;
        margin-bottom: 25px; display: flex;
        align-items: center; gap: 10px; font-weight: 500;
    }
    .alert-error { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); border: 1px solid rgba(220, 53, 69, 0.2); }
    .alert-success { background: rgba(40, 167, 69, 0.1); color: var(--success-color); border: 1px solid rgba(40, 167, 69, 0.2); }

    .card {
        background: var(--white); border-radius: 16px;
        box-shadow: var(--shadow); overflow: hidden;
    }
    .card-header {
        padding: 20px 25px; border-bottom: 1px solid var(--gray-200);
        background: linear-gradient(to right, var(--primary-soft), transparent);
    }
    .card-header h3 {
        margin: 0; font-size: 18px;
        display: flex; align-items: center; gap: 10px;
        color: var(--text-dark);
    }
    .card-header h3 i { color: var(--primary-color); }
    .card-body { padding: 25px; }

    .status-card { text-align: center; padding: 60px 40px; max-width: 600px; margin: 0 auto; }
    .status-card i { font-size: 64px; margin-bottom: 20px; display: block; }
    .status-card h2 { color: var(--text-dark); margin-bottom: 10px; }
    .status-card p { color: var(--text-light); margin-bottom: 25px; }
    .status-card.cleared i { color: var(--success-color); }
    .status-card.pending i { color: var(--warning-color); }
    .txn-ref { font-family: 'Courier New', monospace; font-size: 13px; background: var(--gray-100); padding: 8px 12px; border-radius: 6px; margin: 15px 0; }
    .pending-actions { display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; }

    .payment-layout {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 25px;
        max-width: 1000px;
        margin: 0 auto;
    }

    .student-info { margin-bottom: 25px; }
    .info-row {
        display: flex; justify-content: space-between;
        padding: 8px 0; border-bottom: 1px solid var(--gray-200);
    }
    .info-row .label { color: var(--text-light); font-size: 13px; }
    .info-row .value { font-weight: 600; color: var(--text-dark); font-size: 14px; }

    .fee-breakdown { margin: 20px 0; }
    .break-row {
        display: flex; justify-content: space-between;
        padding: 10px 0; border-bottom: 1px dashed var(--gray-200);
    }
    .break-row .amount { font-weight: 700; color: var(--primary-color); }
    .break-row .amount.paid { color: var(--success-color); }
    .break-row .amount.due { color: var(--danger-color); font-size: 18px; }
    .break-row.highlight {
        background: var(--primary-soft); margin: 0 -25px;
        padding: 15px 25px; border-bottom: none;
    }

    .due-date {
        display: flex; align-items: center; gap: 8px;
        margin-top: 15px; padding: 10px;
        background: var(--gray-100); border-radius: 8px;
        font-size: 13px; color: var(--text-light);
    }
    .due-date.overdue { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }
    .overdue-tag {
        margin-left: auto; background: var(--danger-color);
        color: var(--white); padding: 2px 8px;
        border-radius: 4px; font-size: 11px; font-weight: 700;
    }

    .amount-display {
        text-align: center; padding: 25px;
        background: linear-gradient(135deg, var(--primary-soft), rgba(30, 86, 49, 0.05));
        border: 2px solid var(--primary-color); border-radius: 12px;
        margin-bottom: 25px;
    }
    .amount-display .label {
        display: block; font-size: 14px;
        color: var(--text-light); margin-bottom: 8px;
    }
    .amount-display .value {
        font-size: 36px; font-weight: 800; color: var(--primary-color);
    }

    .gateways { display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px; }

    .gateway-card {
        cursor: pointer; position: relative;
        border: 2px solid var(--gray-200); border-radius: 16px;
        transition: all 0.3s; overflow: hidden;
    }
    .gateway-card:hover { border-color: var(--primary-color); transform: translateY(-2px); box-shadow: var(--shadow); }
    .gateway-card input { position: absolute; opacity: 0; }
    .gateway-card input:checked + .gateway-content {
        background: var(--primary-soft); border-color: var(--primary-color);
    }
    .gateway-card input:checked + .gateway-content .gateway-check i { color: var(--primary-color); opacity: 1; }
    .gateway-card input:checked + .gateway-content .gateway-logo { background: var(--primary-color) !important; }

    .gateway-content {
        display: flex; align-items: center; gap: 15px;
        padding: 20px; background: var(--white);
    }
    .gateway-logo {
        width: 50px; height: 50px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: var(--white); flex-shrink: 0;
    }
    .gateway-logo.paystack { background: #00C853; }
    .gateway-logo.remita { background: #1a4d8c; }
    .gateway-logo.interswitch { background: #f7941e; }

    .gateway-info { flex: 1; }
    .gateway-info h4 { margin: 0 0 4px; font-size: 16px; color: var(--text-dark); }
    .gateway-info p { margin: 0; font-size: 13px; color: var(--text-light); }

    .gateway-badges { display: flex; gap: 6px; margin-top: 8px; }
    .badge {
        padding: 2px 8px; border-radius: 4px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge.popular { background: var(--success-color); color: var(--white); }
    .badge.instant { background: var(--info-color); color: var(--white); }
    .badge { background: var(--gray-200); color: var(--text-light); }

    .gateway-check { margin-left: auto; }
    .gateway-check i { font-size: 24px; color: var(--gray-300); opacity: 0.5; transition: all 0.3s; }

    .security-badges {
        display: flex; justify-content: center; gap: 20px;
        margin-bottom: 25px; flex-wrap: wrap;
    }
    .security-badges span {
        display: flex; align-items: center; gap: 6px;
        font-size: 12px; color: var(--text-light);
    }
    .security-badges i { color: var(--primary-color); }

    .btn-primary, .btn-outline, .btn-pay {
        display: inline-flex; align-items: center; gap: 10px;
        padding: 14px 28px; border-radius: 12px;
        font-weight: 700; cursor: pointer; text-decoration: none;
        border: none; font-size: 16px; transition: all 0.3s;
    }
    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white); box-shadow: 0 4px 16px rgba(30, 86, 49, 0.3);
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(30, 86, 49, 0.4); }
    .btn-outline {
        background: transparent; border: 2px solid var(--primary-color);
        color: var(--primary-color);
    }
    .btn-outline:hover { background: var(--primary-color); color: var(--white); }
    .btn-pay {
        width: 100%; justify-content: center;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white); box-shadow: 0 4px 16px rgba(30, 86, 49, 0.3);
    }
    .btn-pay:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(30, 86, 49, 0.4); }
    .btn-pay:disabled { opacity: 0.5; cursor: not-allowed; }

    @media (max-width: 768px) {
        .payment-layout { grid-template-columns: 1fr; }
        .header-content h1 { font-size: 24px; }
        .amount-display .value { font-size: 28px; }
    }
</style>

<script>
document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('payBtn').disabled = false;
    });
});

document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
    const btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Redirecting to gateway...';
});

function checkPaymentStatus(paymentId) {
    fetch('ajax/check-payment-status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({payment_id: paymentId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'Verified') {
            alert('Payment verified successfully!');
            location.reload();
        } else {
            alert('Payment still pending. Please try again later.');
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}
</script>

<?php require_once 'includes/footer.php'; ?>