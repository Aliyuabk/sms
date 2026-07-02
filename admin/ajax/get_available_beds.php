<?php
// ajax/get_available_beds.php
require_once '../includes/header.php';

header('Content-Type: application/json');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

try {
    if ($room_id > 0) {
        // Get total beds in room
        $stmt = $pdo->prepare("SELECT bed_count FROM hostel_rooms WHERE room_id = ?");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch();
        
        if (!$room) {
            echo json_encode([]);
            exit;
        }
        
        // Get occupied beds
        $stmt2 = $pdo->prepare("SELECT bed_number FROM hostel_allocations WHERE room_id = ? AND status = 'Active'");
        $stmt2->execute([$room_id]);
        $occupied_beds = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        
        // Generate available beds list
        $available_beds = [];
        for ($i = 1; $i <= $room['bed_count']; $i++) {
            if (!in_array($i, $occupied_beds)) {
                $available_beds[] = ['bed_number' => $i];
            }
        }
        
        echo json_encode($available_beds);
    } else {
        echo json_encode([]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>