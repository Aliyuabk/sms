<?php
// get_occupied_beds.php
require_once 'includes/header.php';

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

header('Content-Type: application/json');

if ($room_id <= 0) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT bed_number 
        FROM hostel_allocations 
        WHERE room_id = ? AND status = 'Active'
    ");
    $stmt->execute([$room_id]);
    $occupied = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($occupied);
} catch (Exception $e) {
    echo json_encode([]);
}
?>