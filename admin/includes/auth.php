<?php
// includes/auth.php

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
}

function getQuickStat($pdo, $sql) {
    try {
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_NUM);
        return $result[0] ?? 0;
    } catch (Exception $e) {
        error_log("Quick stat error: " . $e->getMessage());
        return 0;
    }
}

function timeAgo($datetime) {
    if (!$datetime) return 'Never';
    
    $time = strtotime($datetime);
    $time = time() - $time;
    
    $units = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    
    foreach ($units as $unit => $val) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return ($val == 'second') ? 'just now' : 
               (($numberOfUnits > 1) ? $numberOfUnits.' '.$val.'s ago' : '1 '.$val.' ago');
    }
    return 'just now';
}
?>