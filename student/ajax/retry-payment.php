<?php
/**
 * Payment Verification Retry Handler
 * AJAX endpoint to retry/re-verify pending payments
 */
header('Content-Type: application/json');
require_once '../includes/config.php';

$response = ['success' => false, 'message' => ''];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$transaction_id = $input['transaction_id'] ?? '';

if (empty($transaction_id)) {
    $response['message'] = 'Transaction ID required.';
    echo json_encode($response);
    exit;
}

// Verify transaction exists and is pending
$check_query = "SELECT p.*, sf.amount as fee_amount, sf.balance as fee_balance
                FROM payments p
                LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                WHERE p.transaction_id = ? 
                AND p.status = 'Pending'
                LIMIT 1";
$stmt = $conn->prepare($check_query);
$stmt->bind_param("s", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();
$stmt->close();

if (!$payment) {
    $response['message'] = 'Transaction not found or already processed.';
    echo json_encode($response);
    exit;
}

// In production, here you would call the actual payment gateway API
// to verify the transaction status. For demo, we'll simulate verification.

// Simulate gateway verification (replace with actual API call)
$gateway_verified = simulateGatewayCheck($transaction_id);

if ($gateway_verified) {
    // Update payment status to Verified
    $update_payment = "UPDATE payments 
                      SET status = 'Verified', 
                          verification_date = CURDATE(),
                          verified_by = 1
                      WHERE transaction_id = ?";
    $stmt = $conn->prepare($update_payment);
    $stmt->bind_param("s", $transaction_id);

    if ($stmt->execute()) {
        $stmt->close();

        // Update student_fees amount_paid and status
        $fee_id = $payment['fee_id'];
        $paid_amount = (float)$payment['amount'];

        // Get current fee details
        $fee_query = "SELECT amount, amount_paid FROM student_fees WHERE fee_id = ?";
        $stmt = $conn->prepare($fee_query);
        $stmt->bind_param("i", $fee_id);
        $stmt->execute();
        $fee_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $new_paid = (float)($fee_result['amount_paid'] ?? 0) + $paid_amount;
        $total_fee = (float)($fee_result['amount'] ?? 0);

        // Determine new status
        $new_status = 'Partial';
        if ($new_paid >= $total_fee) {
            $new_status = 'Paid';
        }

        // Update student_fees
        $update_fee = "UPDATE student_fees 
                       SET amount_paid = ?, 
                           status = ?,
                           updated_date = NOW()
                       WHERE fee_id = ?";
        $stmt = $conn->prepare($update_fee);
        $stmt->bind_param("dsi", $new_paid, $new_status, $fee_id);
        $stmt->execute();
        $stmt->close();

        // Log admin action
        $log_query = "INSERT INTO admin_logs 
            (admin_id, action, description, table_name, record_id, new_data, created_at)
            VALUES (1, 'Payment Verified', ?, 'payments', ?, ?, NOW())";
        $stmt = $conn->prepare($log_query);
        $desc = "Payment verified for transaction: " . $transaction_id;
        $new_data = json_encode(['status' => 'Verified', 'amount' => $paid_amount]);
        $stmt->bind_param("sis", $desc, $payment['payment_id'], $new_data);
        $stmt->execute();
        $stmt->close();

        $response['success'] = true;
        $response['message'] = 'Payment verified successfully. Amount: ₦' . number_format($paid_amount, 2);
        $response['new_status'] = $new_status;
        $response['total_paid'] = $new_paid;

    } else {
        $response['message'] = 'Failed to update payment status.';
        $stmt->close();
    }
} else {
    $response['message'] = 'Payment not confirmed by gateway. Please try again later.';
}

echo json_encode($response);

/**
 * Simulate payment gateway verification
 * In production, replace with actual API call to Paystack/Flutterwave/etc.
 */
function simulateGatewayCheck($transaction_id) {
    // Simulate 80% success rate for demo
    // return rand(1, 100) <= 80;

    // For testing, always return true
    // In production, implement actual gateway API:
    // $url = "https://api.paystack.co/transaction/verify/" . $transaction_id;
    // $headers = ["Authorization: Bearer SECRET_KEY"];
    // ...

    return true;
}
?>