<?php
/**
 * Paystack Payment Processor
 * Initialize transaction and redirect to Paystack checkout
 */require_once '../config/db.php';
// Paystack Configuration
$PAYSTACK_SECRET_KEY = 'sk_test_fb18c30800549f40a70118d04a258dce667f6988';
$PAYSTACK_PUBLIC_KEY = 'pk_test_e2aad26468628d8f156bbf6dd3d15f451484ac61';
$PAYSTACK_BASE_URL = 'https://api.paystack.co';

// Get payment ID from URL
$payment_id = (int)($_GET['payment_id'] ?? 0);
$transaction_ref = $_GET['ref'] ?? '';

if (!$payment_id) {
    die('Payment ID required.');
}

// Fetch payment details from database
$payment_query = "SELECT p.*, s.email, s.first_name, s.last_name, s.phone, s.matric_number,
                        sf.fee_type, sf.description as fee_description
                 FROM payments p
                 JOIN students s ON p.student_id = s.student_id
                 LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                 WHERE p.payment_id = ? AND p.status = 'Pending'";
$stmt = $conn->prepare($payment_query);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    die('Payment not found or already processed.');
}

// Build callback URL (must be HTTPS in production)
$callback_url = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/paystack-callback.php';

// Prepare Paystack API payload
$payload = [
    'email' => $payment['email'],
    'amount' => (int)($payment['amount'] * 100), // Paystack expects amount in kobo
    'reference' => $payment['transaction_id'],
    'callback_url' => $callback_url,
    'metadata' => [
        'payment_id' => $payment_id,
        'student_id' => $payment['student_id'],
        'matric_number' => $payment['matric_number'],
        'fee_id' => $payment['fee_id'],
        'fee_type' => $payment['fee_type'] ?? 'Tuition',
        'custom_fields' => [
            [
                'display_name' => 'Student Name',
                'variable_name' => 'student_name',
                'value' => $payment['first_name'] . ' ' . $payment['last_name']
            ],
            [
                'display_name' => 'Matric Number',
                'variable_name' => 'matric_number',
                'value' => $payment['matric_number']
            ],
            [
                'display_name' => 'Fee Type',
                'variable_name' => 'fee_type',
                'value' => $payment['fee_type'] ?? 'Tuition'
            ]
        ]
    ]
];

// Call Paystack API to initialize transaction
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $PAYSTACK_BASE_URL . '/transaction/initialize');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $PAYSTACK_SECRET_KEY,
    'Content-Type: application/json',
    'Cache-Control: no-cache'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// Handle API errors
if ($curl_error) {
    error_log("Paystack cURL Error: " . $curl_error);
    header("Location: payment.php?error=" . urlencode("Connection error. Please try again."));
    exit;
}

$result = json_decode($response, true);

if ($http_code !== 200 || !$result['status']) {
    $error_msg = $result['message'] ?? 'Payment initialization failed.';
    error_log("Paystack Init Error: " . $error_msg . " | Response: " . $response);
    header("Location: payment.php?error=" . urlencode($error_msg));
    exit;
}

// Update payment with authorization URL for tracking
$auth_url = $result['data']['authorization_url'];
$access_code = $result['data']['access_code'] ?? '';

$update = "UPDATE payments SET 
    proof_of_payment = ?,
    remarks = CONCAT(remarks, ' | Paystack access_code: ', ?)
    WHERE payment_id = ?";
$stmt = $conn->prepare($update);
$stmt->bind_param("ssi", $auth_url, $access_code, $payment_id);
$stmt->execute();
$stmt->close();

// Log the initialization
$log = "INSERT INTO admin_logs (admin_id, action, description, table_name, record_id, new_data, created_at)
        VALUES (1, 'Paystack Init', ?, 'payments', ?, ?, NOW())";
$stmt = $conn->prepare($log);
$desc = "Paystack transaction initialized for payment_id: " . $payment_id;
$new_data = json_encode(['reference' => $payment['transaction_id'], 'amount' => $payment['amount']]);
$stmt->bind_param("sis", $desc, $payment_id, $new_data);
$stmt->execute();
$stmt->close();

// Redirect to Paystack checkout
header("Location: " . $auth_url);
exit;
?>