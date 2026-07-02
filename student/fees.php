<?php
/**
 * Fees Management Page - Simplified UI with Paystack Integration
 */

require_once '../config/db.php';

$student_id = $_SESSION['student_id'] ?? 0;

if (!$student_id) {
    header('Location: login.php');
    exit;
}

// ==================== SESSION DETECTION ====================
$session_id = 0;
$current_session = null;
$current_semester = 1;
$session_name = '';

$db_session = null;
$query = "SELECT session_id, session_year, semester, session_name 
          FROM academic_sessions 
          WHERE is_current = 1 AND status = 'Active' 
          LIMIT 1";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $db_session = $row;
}

if (function_exists('getCurrentSession')) {
    $session_data = getCurrentSession($conn);
    if (!empty($session_data['session_year'])) {
        $current_session = $session_data['session_year'];
        $current_semester = (int)$session_data['semester'];
        $session_name = $session_data['session_name'] ?? "$current_session Semester $current_semester";
        $session_id = $session_data['session_id'] ?? 0;
    }
}

if (empty($current_session) && $db_session) {
    $session_id = (int)$db_session['session_id'];
    $current_session = $db_session['session_year'];
    $current_semester = (int)$db_session['semester'];
    $session_name = $db_session['session_name'];
}

if (empty($current_session)) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
    $current_semester = 1;
    $session_name = "$current_session First Semester";
    $session_id = 0;
}

$_SESSION['current_session_year'] = $current_session;
$_SESSION['current_semester'] = $current_semester;
$_SESSION['session_name'] = $session_name;
$_SESSION['session_id'] = $session_id;

// ==================== HELPER FUNCTIONS ====================
function fees_getStudentInfo($conn, $student_id) {
    $query = "SELECT s.*, d.department_name, p.program_name, p.duration_years, p.total_credits,
                     f.faculty_name
              FROM students s
              LEFT JOIN departments d ON s.department_id = d.department_id
              LEFT JOIN programs p ON s.program_id = p.program_id
              LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
              WHERE s.student_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: [];
}

function fees_determineStudentType($student) {
    $student_level = (int)($student['current_level'] ?? 100);
    $admission_year = !empty($student['admission_year']) ? (int)$student['admission_year'] : 0;
    $current_year = (int)date('Y');
    $duration_years = (int)($student['duration_years'] ?? 4);

    if ($student_level >= ($duration_years * 100)) {
        return 'Final Year';
    }
    if ($student_level == 100 || ($admission_year > 0 && ($current_year - $admission_year) <= 1)) {
        return 'New Students';
    }
    return 'Returning Students';
}

function fees_generateInvoiceNumber($session, $student_id, $semester) {
    return 'INV-' . str_replace('/', '', $session) . '-' . str_pad($student_id, 5, '0', STR_PAD_LEFT) . '-' . $semester;
}

function fees_autoCreateStudentFee($conn, $student_id, $student_level, $student_type, $session, $semester) {
    $query = "SELECT * FROM fee_structure 
              WHERE level = ? 
              AND (session_year = ? OR session_year IS NULL)
              AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
              AND is_mandatory = 1
              ORDER BY 
                  CASE WHEN session_year = ? THEN 0 ELSE 1 END,
                  fee_structure_id DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issi", $student_level, $session, $student_type, $session);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $total_amount = 0;
    $items = [];
    $first_structure_id = null;

    while ($item = $result->fetch_assoc()) {
        $total_amount += (float)$item['amount'];
        $items[] = $item;
        if (!$first_structure_id) {
            $first_structure_id = $item['fee_structure_id'];
        }
    }

    if ($total_amount <= 0) {
        return null;
    }

    $due_date = null;
    foreach ($items as $item) {
        if (!empty($item['due_date'])) {
            if (!$due_date || $item['due_date'] < $due_date) {
                $due_date = $item['due_date'];
            }
        }
    }
    if (!$due_date) {
        $due_date = date('Y-m-d', strtotime('+30 days'));
    }

    $invoice_number = fees_generateInvoiceNumber($session, $student_id, $semester);

    $insert = "INSERT INTO student_fees 
        (student_id, fee_structure_id, session_year, semester, fee_type, description, 
         amount, due_date, payment_deadline, status, invoice_number) 
        VALUES (?, ?, ?, ?, 'Tuition', 'Session Fees Consolidated', ?, ?, ?, 'Pending', ?)";
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("iisidsss", $student_id, $first_structure_id, $session, $semester, 
                     $total_amount, $due_date, $due_date, $invoice_number);

    if ($stmt->execute()) {
        $new_fee_id = $stmt->insert_id;
        $stmt->close();

        $query = "SELECT * FROM student_fees WHERE fee_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $new_fee_id);
        $stmt->execute();
        $fee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fee;
    }
    $stmt->close();
    return null;
}

// ==================== PAYSTACK PAYMENT PROCESSING ====================
if (isset($_GET['action']) && $_GET['action'] === 'paystack') {

    $fee_query = "SELECT sf.*, fs.fee_structure_id 
                  FROM student_fees sf
                  LEFT JOIN fee_structure fs ON sf.fee_structure_id = fs.fee_structure_id
                  WHERE sf.student_id = ? 
                  AND sf.session_year = ? 
                  AND sf.status IN ('Pending', 'Partial', 'Overdue')
                  ORDER BY sf.fee_id DESC LIMIT 1";
    $stmt = $conn->prepare($fee_query);
    $stmt->bind_param("is", $student_id, $current_session);
    $stmt->execute();
    $fee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fee) {
        $student_info = fees_getStudentInfo($conn, $student_id);
        $student_level = $student_info['current_level'] ?? 100;
        $student_type = fees_determineStudentType($student_info);
        $fee = fees_autoCreateStudentFee($conn, $student_id, $student_level, $student_type, $current_session, $current_semester);
    }

    if ($fee) {
        $outstanding = (float)$fee['amount'] - (float)$fee['amount_paid'];

        if ($outstanding > 0) {
            $check_pending = "SELECT payment_id, transaction_id FROM payments 
                              WHERE student_id = ? AND fee_id = ? AND status = 'Pending' 
                              ORDER BY payment_date DESC LIMIT 1";
            $stmt = $conn->prepare($check_pending);
            $stmt->bind_param("ii", $student_id, $fee['fee_id']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && !empty($existing['transaction_id'])) {
                header("Location: process-paystack.php?payment_id=" . $existing['payment_id'] . "&ref=" . urlencode($existing['transaction_id']));
                exit;
            } else {
                $student_data = fees_getStudentInfo($conn, $student_id);
                $transaction_ref = 'TXN-' . time() . '-' . rand(1000, 9999);
                $receipt_number = 'RCP-' . str_replace('/', '', $current_session) . '-' . str_pad($student_id, 5, '0', STR_PAD_LEFT) . '-' . time();
                $payer_name = trim(($student_data['first_name'] ?? '') . ' ' . ($student_data['last_name'] ?? ''));
                $invoice = $fee['invoice_number'] ?? fees_generateInvoiceNumber($current_session, $student_id, $current_semester);

                $insert = "INSERT INTO payments 
                    (student_id, fee_id, invoice_number, amount, payment_method, transaction_id, 
                     payer_name, receipt_number, status, payment_date) 
                    VALUES (?, ?, ?, ?, 'Online', ?, ?, ?, 'Pending', NOW())";
                $stmt = $conn->prepare($insert);
                $stmt->bind_param("iisdsss", $student_id, $fee['fee_id'], $invoice, $outstanding, 
                                 $transaction_ref, $payer_name, $receipt_number);

                if ($stmt->execute()) {
                    $new_payment_id = $stmt->insert_id;
                    $stmt->close();

                    if ($fee['status'] === 'Pending') {
                        $update_fee = "UPDATE student_fees SET status = 'Partial' WHERE fee_id = ?";
                        $stmt = $conn->prepare($update_fee);
                        $stmt->bind_param("i", $fee['fee_id']);
                        $stmt->execute();
                        $stmt->close();
                    }

                    header("Location: process-paystack.php?payment_id=" . $new_payment_id . "&ref=" . urlencode($transaction_ref));
                    exit;
                }
                $stmt->close();
            }
        } else {
            header("Location: fees.php?success=" . urlencode("All fees cleared for this session!"));
            exit;
        }
    }

    header("Location: fees.php?error=" . urlencode("No fee structure available for $current_session. Please contact the bursary."));
    exit;
}

// ==================== LOAD STUDENT DATA ====================
$student = fees_getStudentInfo($conn, $student_id);

if (!$student) {
    die("Student record not found.");
}

$student_level = (int)($student['current_level'] ?? 100);
$student_type = fees_determineStudentType($student);

// ==================== CHECK/CREATE STUDENT FEES ====================
$check_fee_query = "SELECT * FROM student_fees 
                    WHERE student_id = ? 
                    AND session_year = ? 
                    LIMIT 1";
$stmt = $conn->prepare($check_fee_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$fee = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fee) {
    $fee = fees_autoCreateStudentFee($conn, $student_id, $student_level, $student_type, $current_session, $current_semester);
}

// ==================== GET FEE STRUCTURE DETAILS ====================
$fee_structure_query = "SELECT * FROM fee_structure 
                        WHERE level = ? 
                        AND (session_year = ? OR session_year IS NULL)
                        AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
                        ORDER BY 
                            CASE WHEN session_year = ? THEN 0 ELSE 1 END,
                            is_mandatory DESC, fee_type";
$stmt = $conn->prepare($fee_structure_query);
$stmt->bind_param("issi", $student_level, $current_session, $student_type, $current_session);
$stmt->execute();
$fee_structure = $stmt->get_result();
$stmt->close();

if (!$fee_structure || $fee_structure->num_rows == 0) {
    $fee_structure_query = "SELECT * FROM fee_structure 
                            WHERE level = ? 
                            AND (applicable_to = ? OR applicable_to = 'All' OR applicable_to IS NULL)
                            ORDER BY is_mandatory DESC, fee_type";
    $stmt = $conn->prepare($fee_structure_query);
    $stmt->bind_param("is", $student_level, $student_type);
    $stmt->execute();
    $fee_structure = $stmt->get_result();
    $stmt->close();
}

// ==================== GET PAYMENT HISTORY ====================
$payments_query = "SELECT p.*, sf.fee_type, sf.description as fee_description, sf.invoice_number
                   FROM payments p
                   LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                   WHERE p.student_id = ? 
                   AND (sf.session_year = ? OR sf.session_year IS NULL)
                   ORDER BY p.payment_date DESC";
$stmt = $conn->prepare($payments_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$payments_result = $stmt->get_result();
$stmt->close();

// ==================== CALCULATE FINANCIAL SUMMARY ====================
$total_fees = 0;
$fee_items = [];

if ($fee_structure && $fee_structure->num_rows > 0) {
    while ($item = $fee_structure->fetch_assoc()) {
        $total_fees += (float)$item['amount'];
        $fee_items[] = $item;
    }
} elseif ($fee) {
    $total_fees = (float)($fee['amount'] ?? 0);
}

$total_paid = 0;
$total_pending = 0;
$total_failed = 0;
$total_refunded = 0;
$payments_data = [];

while ($payment = $payments_result->fetch_assoc()) {
    $payments_data[] = $payment;
    $status = strtolower($payment['status'] ?? 'pending');
    $amount = (float)$payment['amount'];

    switch ($status) {
        case 'verified': $total_paid += $amount; break;
        case 'pending': $total_pending += $amount; break;
        case 'failed': $total_failed += $amount; break;
        case 'refunded': $total_refunded += $amount; break;
    }
}

$outstanding = max(0, $total_fees - $total_paid);
$payment_progress = ($total_fees > 0) ? min(100, round(($total_paid / $total_fees) * 100, 1)) : 0;

$payment_status = 'pending';
if ($outstanding <= 0 && $total_fees > 0) {
    $payment_status = 'cleared';
} elseif ($total_paid > 0) {
    $payment_status = 'partial';
}

$is_overdue = false;
if ($fee && !empty($fee['due_date'])) {
    $is_overdue = (strtotime($fee['due_date']) < time()) && $outstanding > 0;
}

// Get latest verified payment for receipt popup
$latest_payment = null;
foreach ($payments_data as $p) {
    if (strtolower($p['status']) === 'verified') {
        $latest_payment = $p;
        break;
    }
}

// Get all sessions for dropdown
$all_sessions_query = "SELECT DISTINCT session_year FROM student_fees WHERE student_id = ? ORDER BY session_year DESC";
$stmt = $conn->prepare($all_sessions_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$all_sessions = $stmt->get_result();

// ==================== OUTPUT ====================
require_once 'includes/header.php';
?>

<div class="fees-page">
    <!-- Receipt Download Dropdown -->
    <div class="receipt-download">
        <label>Download Previous Payments Receipts</label>
        <select class="receipt-select" onchange="downloadReceipt(this.value)">
            <option value="">Select...</option>
            <?php while ($s = $all_sessions->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($s['session_year']); ?>">
                <?php echo htmlspecialchars($s['session_year']); ?> Session
            </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="fees-layout">
        <!-- Left Sidebar -->
        <div class="fees-sidebar">
            <div class="notice-box">
                <i class="fas fa-info-circle"></i>
                <span>Complete payment must be made</span>
            </div>

            <div class="amount-box">
                <label>Amount Paid</label>
                <div class="amount paid">₦<?php echo number_format($total_paid); ?></div>
            </div>

            <div class="amount-box">
                <label>Outstanding Amount</label>
                <div class="amount outstanding <?php echo $outstanding > 0 ? 'due' : 'cleared'; ?>">
                    ₦<?php echo number_format($outstanding); ?>
                </div>
            </div>

            <button class="print-btn" onclick="openReceiptPopup()">
                <span>Print Payment receipt</span>
                <i class="fas fa-print"></i>
            </button>
        </div>

        <!-- Right Main Area -->
        <div class="fees-main">
            <div class="compulsory-box">
                <div class="compulsory-header">Compulsory Items</div>

                <?php if (count($fee_items) > 0): ?>
                    <?php foreach ($fee_items as $item): ?>
                    <div class="fee-row">
                        <div class="fee-check">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="fee-name"><?php echo htmlspecialchars(strtoupper($item['fee_type'])); ?></div>
                        <div class="fee-price">₦<?php echo number_format($item['amount']); ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="fee-row">
                        <div class="fee-check"><i class="fas fa-check-circle"></i></div>
                        <div class="fee-name">RETURNING STUDENTS FEES</div>
                        <div class="fee-price">₦<?php echo number_format($total_fees); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pay-box">
                <div class="pay-input-row">
                    <label>Amount To Pay</label>
                    <div class="pay-input">₦<?php echo number_format($outstanding); ?></div>
                </div>

                <div class="pay-buttons">
                    <!-- Make full payment - uses original Paystack processing -->
                    <a href="fees.php?action=paystack" 
                       class="pay-btn full <?php echo $outstanding <= 0 ? 'disabled' : ''; ?>"
                       <?php echo $outstanding <= 0 ? 'onclick="return false;"' : 'onclick="return confirmPaystack()"'; ?>>
                        Make full payment <i class="fas fa-arrow-right"></i>
                    </a>

                    <button class="pay-btn part <?php echo $outstanding <= 0 ? 'disabled' : ''; ?>" 
                            onclick="makePartPayment()" 
                            <?php echo $outstanding <= 0 ? 'disabled' : ''; ?>>
                        Make part payment <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Popup Modal -->
<div class="receipt-modal" id="receiptModal" style="display:none;">
    <div class="receipt-overlay" onclick="closeReceiptPopup()"></div>
    <div class="receipt-popup">
        <div class="receipt-body">
            <div class="receipt-qr">
                <img id="receiptQrCode" src="" alt="QR Code">
            </div>
            <div class="receipt-student">
                <div class="receipt-name" id="receiptName">
                    <?php echo htmlspecialchars(strtoupper($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? 'Student')); ?>
                </div>
                <div class="receipt-matric" id="receiptMatric">
                    <?php echo htmlspecialchars($student['matric_number'] ?? ''); ?>
                </div>
            </div>
            <div class="receipt-divider"></div>
            <div class="receipt-amount-section">
                <div class="receipt-label">Amount Paid</div>
                <div class="receipt-amount" id="receiptAmount">₦<?php echo number_format($total_paid); ?></div>
            </div>
            <div class="receipt-divider"></div>
            <div class="receipt-details">
                <div class="receipt-detail">
                    <div class="detail-icon"><i class="fas fa-receipt"></i></div>
                    <div class="detail-label">Reference No</div>
                    <div class="detail-value" id="receiptRef">
                        <?php echo htmlspecialchars($latest_payment['transaction_id'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div class="receipt-detail">
                    <div class="detail-icon"><i class="fas fa-calendar"></i></div>
                    <div class="detail-label">Transaction Date</div>
                    <div class="detail-value" id="receiptDate">
                        <?php echo $latest_payment ? date('d M Y', strtotime($latest_payment['payment_date'])) : 'N/A'; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="receipt-actions">
            <button class="receipt-btn close" onclick="closeReceiptPopup()">Close</button>
            <button class="receipt-btn download" onclick="downloadReceiptPDF()">
                <i class="fas fa-download"></i> Download
            </button>
        </div>
    </div>
</div>

<style>
/* ===== SIMPLIFIED FEES PAGE ===== */
.fees-page {
    max-width: 1000px;
    margin: 0 auto;
    padding: 24px 20px;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

/* Receipt Download */
.receipt-download {
    margin-bottom: 20px;
}
.receipt-download label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}
.receipt-select {
    width: 100%;
    max-width: 320px;
    padding: 12px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    color: #6b7280;
    background: #fff;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
}

/* Layout */
.fees-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 24px;
    align-items: start;
}

/* Sidebar */
.fees-sidebar {
    background: #f9fafb;
    border-radius: 12px;
    padding: 20px;
}
.notice-box {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #fff;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #374151;
}
.notice-box i {
    color: #6b7280;
    font-size: 14px;
}
.amount-box {
    background: #fff;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
}
.amount-box label {
    display: block;
    font-size: 12px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.amount {
    font-size: 24px;
    font-weight: 700;
}
.amount.paid {
    color: #9ca3af;
}
.amount.outstanding.due {
    color: #ec4899;
}
.amount.outstanding.cleared {
    color: #10b981;
}
.print-btn {
    width: 100%;
    padding: 14px;
    background: #1a4db5;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: background 0.2s;
}
.print-btn:hover {
    background: #153d8f;
}

/* Main Area */
.fees-main {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
}
.compulsory-header {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 16px;
}
.fee-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid #f3f4f6;
}
.fee-row:last-child {
    border-bottom: none;
}
.fee-check {
    color: #10b981;
    font-size: 20px;
}
.fee-name {
    flex: 1;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
}
.fee-price {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
}

/* Pay Box */
.pay-box {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}
.pay-input-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f3f4f6;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 16px;
}
.pay-input-row label {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
}
.pay-input {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
}
.pay-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.pay-btn {
    padding: 14px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}
.pay-btn.full {
    background: #d1d5db;
    color: #6b7280;
    border: none;
}
.pay-btn.full:not(.disabled):hover {
    background: #1a4db5;
    color: #fff;
}
.pay-btn.part {
    background: #fff;
    color: #6b7280;
    border: 1px dashed #d1d5db;
}
.pay-btn.part:not(.disabled):hover {
    border-color: #1a4db5;
    color: #1a4db5;
}
.pay-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}
.pay-btn i {
    font-size: 12px;
}

/* ===== RECEIPT POPUP ===== */
.receipt-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.receipt-overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
}
.receipt-popup {
    position: relative;
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 380px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    overflow: hidden;
    animation: popupIn 0.3s ease;
}
@keyframes popupIn {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}
.receipt-body {
    padding: 32px 28px;
    text-align: center;
}
.receipt-qr {
    margin-bottom: 20px;
}
.receipt-qr img {
    width: 140px;
    height: 140px;
}
.receipt-name {
    font-size: 16px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}
.receipt-matric {
    font-size: 13px;
    color: #9ca3af;
}
.receipt-divider {
    height: 1px;
    background: #e5e7eb;
    margin: 20px 0;
}
.receipt-label {
    font-size: 13px;
    color: #9ca3af;
    margin-bottom: 6px;
}
.receipt-amount {
    font-size: 28px;
    font-weight: 700;
    color: #1e293b;
}
.receipt-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.receipt-detail {
    text-align: center;
}
.detail-icon {
    width: 40px;
    height: 40px;
    border: 1px solid #e5e7eb;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    color: #6b7280;
    font-size: 14px;
}
.detail-label {
    font-size: 11px;
    color: #9ca3af;
    margin-bottom: 4px;
}
.detail-value {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    word-break: break-all;
}
.receipt-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding: 16px 24px 24px;
}
.receipt-btn {
    padding: 12px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.receipt-btn.close {
    background: #374151;
    color: #fff;
}
.receipt-btn.close:hover {
    background: #1f2937;
}
.receipt-btn.download {
    background: #fff;
    color: #1a4db5;
    border: 1px solid #1a4db5;
}
.receipt-btn.download:hover {
    background: #1a4db5;
    color: #fff;
}

/* Responsive */
@media (max-width: 768px) {
    .fees-layout {
        grid-template-columns: 1fr;
    }
    .fees-sidebar {
        order: 2;
    }
    .fees-main {
        order: 1;
    }
    .pay-buttons {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Paystack payment confirmation
function confirmPaystack() {
    const amount = <?php echo $outstanding; ?>;
    if (amount <= 0) {
        alert('You have no outstanding fees to pay!');
        return false;
    }
    return confirm('You will be redirected to Paystack to pay ₦' + amount.toLocaleString('en-NG') + '. Continue?');
}

// Part payment (placeholder)
function makePartPayment() {
    alert('Part payment feature coming soon. Please use full payment.');
}

// Receipt popup functions
function openReceiptPopup() {
    const ref = document.getElementById('receiptRef').textContent.trim();
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(ref);
    document.getElementById('receiptQrCode').src = qrUrl;
    document.getElementById('receiptModal').style.display = 'flex';
}

function closeReceiptPopup() {
    document.getElementById('receiptModal').style.display = 'none';
}

// PDF Download
function downloadReceiptPDF() {
    const ref = document.getElementById('receiptRef').textContent.trim();
    const session = '<?php echo $current_session; ?>';
    const pdfUrl = 'generate-receipt-pdf.php?ref=' + encodeURIComponent(ref) + '&session=' + encodeURIComponent(session);
    window.open(pdfUrl, '_blank');
}

// Download previous receipt by session
function downloadReceipt(session) {
    if (!session) return;
    window.open('generate-receipt-pdf.php?session=' + encodeURIComponent(session), '_blank');
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReceiptPopup();
});
</script>

<?php require_once 'includes/footer.php'; ?>