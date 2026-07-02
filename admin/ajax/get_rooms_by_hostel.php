<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (isset($_GET['hostel_id']) && !empty($_GET['hostel_id'])) {
    $hostel_id = (int)$_GET['hostel_id'];
    
    $stmt = $pdo->prepare("
        SELECT r.*, 
            (SELECT COUNT(*) FROM hostel_allocations WHERE room_id = r.room_id AND status = 'Active') as occupied_beds
        FROM hostel_rooms r
        WHERE r.hostel_id = ? AND r.status = 'Available'
        HAVING occupied_beds < r.bed_count
        ORDER BY r.room_number
    ");
    $stmt->execute([$hostel_id]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'rooms' => $rooms]);
} else {
    echo json_encode(['success' => false, 'message' => 'Hostel ID required']);
}
?>