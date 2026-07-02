<?php
// delete_hostel.php
ob_start();

require_once 'includes/header.php';

$hostel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirm = isset($_GET['confirm']) ? (int)$_GET['confirm'] : 0;

if ($hostel_id <= 0) {
    $_SESSION['error_message'] = "Invalid hostel ID";
    header("Location: hostels.php");
    exit();
}

// Check for active allocations
try {
    // Fetch hostel details for logging
    $hostel_stmt = $pdo->prepare("SELECT * FROM hostels WHERE hostel_id = ?");
    $hostel_stmt->execute([$hostel_id]);
    $hostel = $hostel_stmt->fetch();

    if (!$hostel) {
        $_SESSION['error_message'] = "Hostel not found";
        header("Location: hostels.php");
        exit();
    }

    // Check for active allocations
    $check_allocations = $pdo->prepare("
        SELECT COUNT(*) FROM hostel_allocations 
        WHERE hostel_id = ? AND status = 'Active'
    ");
    $check_allocations->execute([$hostel_id]);
    $active_allocations = $check_allocations->fetchColumn();

    if ($active_allocations > 0) {
        $_SESSION['error_message'] = "Cannot delete hostel: There are {$active_allocations} active allocations. Please check out all students first.";
        header("Location: view_hostel.php?id=$hostel_id");
        exit();
    }

    // Check for pending maintenance
    $check_maintenance = $pdo->prepare("
        SELECT COUNT(*) FROM hostel_maintenance 
        WHERE hostel_id = ? AND status IN ('Pending', 'In Progress')
    ");
    $check_maintenance->execute([$hostel_id]);
    $pending_maintenance = $check_maintenance->fetchColumn();

    if ($pending_maintenance > 0 && $confirm != 2) {
        $_SESSION['error_message'] = "Cannot delete hostel: There are {$pending_maintenance} pending maintenance requests. Please resolve them first.";
        header("Location: view_hostel.php?id=$hostel_id");
        exit();
    }

    if ($confirm == 1) {
        // Double-check again
        $check_allocations->execute([$hostel_id]);
        if ($check_allocations->fetchColumn() > 0) {
            throw new Exception("Active allocations found");
        }

        // Start transaction
        $pdo->beginTransaction();

        // Delete maintenance records
        $delete_maintenance = $pdo->prepare("DELETE FROM hostel_maintenance WHERE hostel_id = ?");
        $delete_maintenance->execute([$hostel_id]);

        // Delete room allocations (should be none active, but clean up history)
        $delete_allocations = $pdo->prepare("DELETE FROM hostel_allocations WHERE hostel_id = ?");
        $delete_allocations->execute([$hostel_id]);

        // Delete rooms
        $delete_rooms = $pdo->prepare("DELETE FROM hostel_rooms WHERE hostel_id = ?");
        $delete_rooms->execute([$hostel_id]);
        $rooms_deleted = $delete_rooms->rowCount();

        // Delete hostel
        $delete_hostel = $pdo->prepare("DELETE FROM hostels WHERE hostel_id = ?");
        $delete_hostel->execute([$hostel_id]);

        // Log the action
        $log_stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, description, table_name) 
            VALUES (?, 'Delete', ?, 'hostels')
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            "Deleted hostel: {$hostel['hostel_name']} (Code: {$hostel['hostel_code']}) with {$rooms_deleted} rooms"
        ]);

        $pdo->commit();

        $_SESSION['success_message'] = "Hostel '{$hostel['hostel_name']}' and all associated records have been deleted.";
        header("Location: hostels.php");
        exit();
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting hostel: " . $e->getMessage());
    $_SESSION['error_message'] = "Error deleting hostel: " . $e->getMessage();
    header("Location: view_hostel.php?id=$hostel_id");
    exit();
}

// If we get here, show error
$_SESSION['error_message'] = "Invalid confirmation";
header("Location: view_hostel.php?id=$hostel_id");
exit();
?>