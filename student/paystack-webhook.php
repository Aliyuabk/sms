<?php
/**
 * Paystack Webhook Handler
 * Receives async payment notifications from Paystack
 * Must be publicly accessible (HTTPS required in production)
 */require_once '../config/db.php';

// Paystack Configuration
$PAYSTACK_SECRET_KEY = 'sk_test_fb18c30800549f40a70118d04a258dce667f6988';

// Get raw POST body
$input = file_get_contents('php://input');
$event = json_decode($input, true);

if (!$event || !isset($event['event'])) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Invalid payload']);
    exit;
}

// Verify Paystack signature (if available)
$headers = getallheaders();
$signature = $headers['X-Paystack-Signature'] ?? $headers['x-paystack-signature'] ?? '';

if ($signature) {
    $computed = hash_hmac('sha512', $input, $PAYSTACK_SECRET_KEY);
    if (!hash_equals($computed, $signature)) {
        http_response_code(401);
        error_log("Paystack Webhook: Invalid signature");
        echo json_encode(['status' => false, 'message' => 'Invalid signature']);
        exit;
    }
}

$event_type = $event['event'];
$data = $event['data'] ?? [];

// Only process charge.success events
if ($event_type !== 'charge.success') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Event ignored']);
    exit;
}

$reference = $data['reference'] ?? '';
$amount = ($data['amount'] ?? 0) / 100;
$status = $data['status'] ?? '';
$channel = $data['channel'] ?? 'Online';
$metadata = $data['metadata'] ?? [];
$payment_id = $metadata['payment_id'] ?? 0;

if (empty($reference) || $status !== 'success') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'No action needed']);
    exit;
}

// Find payment by reference if payment_id not in metadata
if (!$payment_id) {
    $find = "SELECT payment_id FROM payments WHERE transaction_id = ? LIMIT 1";
    $stmt = $conn->prepare($find);
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $payment_id = $res['payment_id'] ?? 0;
}

if (!$payment_id) {
    error_log("Paystack Webhook: Payment not found for ref: " . $reference);
    http_response_code(404);
    echo json_encode(['status' => false, 'message' => 'Payment not found']);
    exit;
}

// Check if already processed
$check = "SELECT status, fee_id, student_id, amount FROM payments WHERE payment_id = ?";
$stmt = $conn->prepare($check);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($payment['status'] === 'Verified') {
    http_response_code(200);
    echo json_encode(['status' => true, 'message' => 'Already processed']);
    exit;
}

$fee_id = $payment['fee_id'];
$student_id = $payment['student_id'];
$amount_paid = (float)$payment['amount'];

// Update payment
$update = "UPDATE payments SET 
    status = 'Verified',
    payment_method = ?,
    verification_date = NOW(),
    verified_by = 1,
    remarks = CONCAT(remarks, ' | Webhook verified: ', ?)
    WHERE payment_id = ?";
$stmt = $conn->prepare($update);
$stmt->bind_param("ssi", $channel, $reference, $payment_id);
$stmt->execute();
$stmt->close();

// Update student_fees
if ($fee_id) {
    $fee_q = "SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?";
    $stmt = $conn->prepare($fee_q);
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $fee_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($fee_data) {
        $new_paid = (float)$fee_data['amount_paid'] + $amount_paid;
        $total = (float)$fee_data['amount'];
        $fee_status = $new_paid >= $total ? 'Paid' : 'Partial';

        $upd = "UPDATE student_fees SET amount_paid = ?, status = ?, updated_date = NOW() WHERE fee_id = ?";
        $stmt = $conn->prepare($upd);
        $stmt->bind_param("dsi", $new_paid, $fee_status, $fee_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Log webhook
$log = "INSERT INTO admin_logs (admin_id, action, description, table_name, record_id, new_data, created_at)
        VALUES (1, 'Paystack Webhook', ?, 'payments', ?, ?, NOW())";
$stmt = $conn->prepare($log);
$desc = "Webhook: charge.success for ref " . $reference;
$new_data = json_encode(['status' => 'Verified', 'amount' => $amount_paid, 'channel' => $channel]);
$stmt->bind_param("sis", $desc, $payment_id, $new_data);
$stmt->execute();
$stmt->close();

http_response_code(200);
echo json_encode(['status' => true, 'message' => 'Payment verified']);
exit;
?>