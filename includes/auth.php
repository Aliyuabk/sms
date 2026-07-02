<?php
// Authentication helper functions

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }
}

function requireRole($allowedRoles) {
    requireAuth();
    
    if (!in_array($_SESSION['user_type'], (array)$allowedRoles)) {
        header('Location: ../unauthorized.php');
        exit();
    }
}

// Store user activity
function logActivity($action, $details = null) {
    global $pdo;
    
    try {
        $sql = "INSERT INTO user_activity (user_id, action, details, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// Update last activity
function updateLastActivity() {
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

// Check session timeout (30 minutes)
function checkSessionTimeout() {
    $timeout = 1800; // 30 minutes in seconds
    
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity']) > $timeout) {
        
        session_unset();
        session_destroy();
        header('Location: ../login.php?timeout=1');
        exit();
    }
    
    updateLastActivity();
}
?>