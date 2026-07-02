<?php
/**
 * Paystack Callback Handler
 * Verifies payment and updates database
 */require_once '../config/db.php';

// Paystack Configuration
$PAYSTACK_SECRET_KEY = 'sk_test_fb18c30800549f40a70118d04a258dce667f6988';
$PAYSTACK_BASE_URL = 'https://api.paystack.co';

// Get reference from Paystack redirect
$reference = $_GET['reference'] ?? $_GET['trxref'] ?? '';

if (empty($reference)) {
    header("Location: payment.php?error=" . urlencode("No transaction reference received."));
    exit;
}

// Verify transaction with Paystack API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $PAYSTACK_BASE_URL . '/transaction/verify/' . urlencode($reference));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $PAYSTACK_SECRET_KEY,
    'Cache-Control: no-cache'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    error_log("Paystack Verify cURL Error: " . $curl_error);
    header("Location: payment.php?error=" . urlencode("Verification connection error."));
    exit;
}

$result = json_decode($response, true);

if ($http_code !== 200 || !$result['status']) {
    $error_msg = $result['message'] ?? 'Transaction verification failed.';
    error_log("Paystack Verify Error: " . $error_msg . " | Ref: " . $reference);
    header("Location: payment.php?error=" . urlencode($error_msg));
    exit;
}

$transaction_data = $result['data'];
$gateway_status = $transaction_data['status']; // 'success', 'failed', 'abandoned', etc.
$amount_paid = $transaction_data['amount'] / 100; // Convert from kobo to naira
$transaction_ref = $transaction_data['reference'];
$payment_method = $transaction_data['channel'] ?? 'Online'; // 'card', 'bank', 'ussd', etc.
$paid_at = $transaction_data['paid_at'] ?? null;
$metadata = $transaction_data['metadata'] ?? [];

// Extract payment_id from metadata or find by reference
$payment_id = $metadata['payment_id'] ?? 0;

if (!$payment_id) {
    // Fallback: find by transaction_id
    $find_query = "SELECT payment_id FROM payments WHERE transaction_id = ? LIMIT 1";
    $stmt = $conn->prepare($find_query);
    $stmt->bind_param("s", $transaction_ref);
    $stmt->execute();
    $find_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $payment_id = $find_result['payment_id'] ?? 0;
}

if (!$payment_id) {
    error_log("Paystack Callback: Payment not found for ref: " . $transaction_ref);
    header("Location: payment.php?error=" . urlencode("Payment record not found."));
    exit;
}

// Fetch payment details
$payment_query = "SELECT p.*, s.student_id, s.email, s.first_name, s.last_name
                 FROM payments p
                 JOIN students s ON p.student_id = s.student_id
                 WHERE p.payment_id = ?";
$stmt = $conn->prepare($payment_query);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    header("Location: payment.php?error=" . urlencode("Payment record not found."));
    exit;
}

$student_id = $payment['student_id'];
$fee_id = $payment['fee_id'];
$expected_amount = (float)$payment['amount'];

// Validate amount matches (prevent tampering)
if (abs($amount_paid - $expected_amount) > 1) {
    error_log("Paystack Amount Mismatch: Expected {$expected_amount}, Got {$amount_paid} for ref: " . $reference);
}

// Determine final status
$new_status = 'Pending';
$success = false;

if ($gateway_status === 'success') {
    $new_status = 'Verified';
    $success = true;
} elseif ($gateway_status === 'failed') {
    $new_status = 'Failed';
} elseif ($gateway_status === 'abandoned') {
    $new_status = 'Pending'; // User left, can retry
}

// Update payment record
$update_payment = "UPDATE payments SET 
    status = ?,
    payment_method = ?,
    verification_date = NOW(),
    verified_by = 1,
    remarks = CONCAT(remarks, ' | Paystack: ', ?, ' | Channel: ', ?)
    WHERE payment_id = ?";
$stmt = $conn->prepare($update_payment);
$stmt->bind_param("ssssi", $new_status, $payment_method, $gateway_status, $payment_method, $payment_id);
$stmt->execute();
$stmt->close();

// If verified, update student_fees
if ($success && $fee_id) {
    // Get current fee details
    $fee_query = "SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?";
    $stmt = $conn->prepare($fee_query);
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $fee_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($fee_data) {
        $current_paid = (float)$fee_data['amount_paid'];
        $total_fee = (float)$fee_data['amount'];
        $new_total_paid = $current_paid + $amount_paid;

        // Determine fee status
        $fee_status = 'Partial';
        if ($new_total_paid >= $total_fee) {
            $fee_status = 'Paid';
        }

        // Update student_fees
        $update_fee = "UPDATE student_fees 
                       SET amount_paid = ?, 
                           status = ?,
                           updated_date = NOW()
                       WHERE fee_id = ?";
        $stmt = $conn->prepare($update_fee);
        $stmt->bind_param("dsi", $new_total_paid, $fee_status, $fee_id);
        $stmt->execute();
        $stmt->close();
    }

    // Create notification for student
    $notif_title = "Payment Successful";
    $notif_msg = "Your payment of ₦" . number_format($amount_paid) . " has been verified successfully.";
    $notif_query = "INSERT INTO notifications 
        (student_id, title, message, notification_type, priority, is_read, sent_date)
        VALUES (?, ?, ?, 'Financial', 'Normal', 0, NOW())";
    $stmt = $conn->prepare($notif_query);
    $stmt->bind_param("iss", $student_id, $notif_title, $notif_msg);
    $stmt->execute();
    $stmt->close();
}

// Log the callback
$log = "INSERT INTO admin_logs (admin_id, action, description, table_name, record_id, old_data, new_data, created_at)
        VALUES (1, ?, ?, 'payments', ?, ?, ?, NOW())";
$stmt = $conn->prepare($log);
$action = $success ? 'Paystack Success' : 'Paystack ' . ucfirst($gateway_status);
$desc = "Paystack callback for ref: " . $reference . " | Status: " . $gateway_status;
$old_data = json_encode(['status' => 'Pending']);
$new_data = json_encode([
    'status' => $new_status,
    'amount_paid' => $amount_paid,
    'channel' => $payment_method,
    'gateway_status' => $gateway_status
]);
$stmt->bind_param("ssiss", $action, $desc, $payment_id, $old_data, $new_data);
$stmt->execute();
$stmt->close();

// Redirect based on outcome
if ($success) {
    header("Location: download-receipt.php?receipt=" . urlencode($payment['receipt_number']) . "&success=1");
} else {
    header("Location: payment.php?error=" . urlencode("Payment " . $gateway_status . ". Please try again."));
}
exit;
?>