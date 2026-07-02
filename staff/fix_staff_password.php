<?php
/**
 * Fix Staff Password Script
 * Run this once to set a proper password for staff
 */

require_once 'config/database.php';

// Only allow from CLI or localhost for security
$allowed_ips = ['::1', '127.0.0.1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips) && php_sapi_name() !== 'cli') {
    die('Access denied. This script can only be run locally.');
}

echo "<h1>Staff Password Fix</h1>";

// Get all staff
$stmt = $pdo->query("SELECT staff_id, email, first_name, last_name, password_hash FROM staff");
$staff_list = $stmt->fetchAll();

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Email</th><th>Name</th><th>Password Hash</th><th>Action</th></tr>";

foreach ($staff_list as $staff) {
    $needs_update = false;
    $new_hash = $staff['password_hash'];
    
    // Check if it's the default hash
    if ($staff['password_hash'] === '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi') {
        $needs_update = true;
        $new_hash = password_hash('password', PASSWORD_DEFAULT);
    }
    // Check if it's plain text
    elseif (strlen($staff['password_hash']) < 20) {
        $needs_update = true;
        $new_hash = password_hash($staff['password_hash'], PASSWORD_DEFAULT);
    }
    
    // Update if needed
    if ($needs_update) {
        $update = $pdo->prepare("UPDATE staff SET password_hash = ? WHERE staff_id = ?");
        $update->execute([$new_hash, $staff['staff_id']]);
        echo "<tr style='background:#d4edda;'>";
        echo "<td>{$staff['staff_id']}</td>";
        echo "<td>{$staff['email']}</td>";
        echo "<td>{$staff['first_name']} {$staff['last_name']}</td>";
        echo "<td><span style='color:green;'>Updated ✓</span></td>";
        echo "<td><strong>Password: 'password'</strong></td>";
    } else {
        echo "<tr>";
        echo "<td>{$staff['staff_id']}</td>";
        echo "<td>{$staff['email']}</td>";
        echo "<td>{$staff['first_name']} {$staff['last_name']}</td>";
        echo "<td>OK ✓</td>";
        echo "<td>No action needed</td>";
    }
    echo "</tr>";
}

echo "</table>";

echo "<br><br><strong>You can now login with:</strong><br>";
echo "Email: aliyuabubakar11117@gmail.com<br>";
echo "Password: password<br>";

// Also ensure staff can login
$update = $pdo->prepare("UPDATE staff SET can_login = 1 WHERE staff_id IN (SELECT staff_id FROM staff)");
$update->execute();
echo "<br>✅ All staff can now login.";
?>