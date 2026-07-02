<?php
/**
 * Logout All Devices
 * Clear all sessions and logout from all devices
 */

ob_start();
session_start();

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$staff_id = $_SESSION['staff_id'];

// Log the logout all action
try {
    $logStmt = $pdo->prepare("
        INSERT INTO staff_activity_log (staff_id, activity_type, description, ip_address) 
        VALUES (?, 'Logout All', 'Logged out from all devices', ?)
    ");
    $logStmt->execute([$staff_id, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
} catch (Exception $e) {
    // Ignore logging errors
}

// Clear all session data
$_SESSION = array();

// If session cookies are used, delete them
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Clear any other cookies used by the application
setcookie('staff_remember', '', time() - 3600, '/');

// Redirect to login with success message
ob_end_clean();
header('Location: index.php?logout=all');
exit;
?>