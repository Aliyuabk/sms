<?php
// logout.php - Simple version
session_start();

// Destroy session
session_unset();
session_destroy();

// Clear cookies
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect to login
header('Location: ../index.php');
exit();
?>