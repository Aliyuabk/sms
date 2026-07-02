<?php
require_once 'config/database.php';

$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;

if (!$hostel_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid hostel ID']);
    exit;
}

// Get available rooms (with available beds)
$sql = "
    SELECT 
        hr.room_id, hr.room_number, hr.room_name, hr.room_type,
        hr.bed_count,
        (SELECT COUNT(*) FROM hostel_allocations ha 
         WHERE ha.room_id = hr.room_id AND ha.status = 'Active') as occupied_beds
    FROM hostel_rooms hr
    WHERE hr.hostel_id = ? 
    AND hr.status IN ('Available', 'Occupied')
    AND hr.bed_count > (SELECT COUNT(*) FROM hostel_allocations ha 
                       WHERE ha.room_id = hr.room_id AND ha.status = 'Active')
    ORDER BY hr.room_number
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$hostel_id]);
$rooms = $stmt->fetchAll();

echo json_encode(['success' => true, 'rooms' => $rooms]);
?>