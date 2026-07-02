<?php
// ajax/get_rooms.php
require_once '../includes/header.php';

header('Content-Type: application/json');

$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;
$include_common = isset($_GET['include_common']);

try {
    if ($hostel_id > 0) {
        $sql = "SELECT 
                    hr.room_id, 
                    hr.room_number, 
                    hr.bed_count,
                    hr.room_type,
                    hr.status,
                    hr.floor_number,
                    (hr.bed_count - COALESCE((
                        SELECT COUNT(*) 
                        FROM hostel_allocations ha 
                        WHERE ha.room_id = hr.room_id 
                        AND ha.status = 'Active'
                    ), 0)) as available_beds
                FROM hostel_rooms hr
                WHERE hr.hostel_id = ? 
                AND hr.status IN ('Available', 'Occupied')
                ORDER BY CAST(hr.room_number AS UNSIGNED)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hostel_id]);
        $rooms = $stmt->fetchAll();
        
        echo json_encode($rooms);
    } else {
        echo json_encode([]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>