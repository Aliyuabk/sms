<?php
require_once 'config/database.php';

$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;

if (!$hostel_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid hostel ID']);
    exit;
}

// Get all rooms for this hostel
$sql = "
    SELECT room_id, room_number, room_name, room_type, status
    FROM hostel_rooms
    WHERE hostel_id = ?
    ORDER BY room_number
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$hostel_id]);
$rooms = $stmt->fetchAll();

echo json_encode(['success' => true, 'rooms' => $rooms]);
?>