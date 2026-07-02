<?php
// reset_hostel_rooms.php
ob_start();

require_once 'includes/header.php';

// Get hostel ID from URL
$hostel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirm = isset($_GET['confirm']) ? (int)$_GET['confirm'] : 0;

if ($hostel_id <= 0) {
    $_SESSION['error_message'] = "Invalid hostel ID";
    header("Location: hostels.php");
    exit();
}

// Fetch hostel details
try {
    $stmt = $pdo->prepare("SELECT * FROM hostels WHERE hostel_id = ?");
    $stmt->execute([$hostel_id]);
    $hostel = $stmt->fetch();

    if (!$hostel) {
        $_SESSION['error_message'] = "Hostel not found";
        header("Location: hostels.php");
        exit();
    }

    // Check for active allocations
    $check_allocations = $pdo->prepare("
        SELECT COUNT(*) as active_count 
        FROM hostel_allocations 
        WHERE hostel_id = ? AND status = 'Active'
    ");
    $check_allocations->execute([$hostel_id]);
    $active_allocations = $check_allocations->fetchColumn();

    if ($active_allocations > 0 && $confirm != 1) {
        $_SESSION['error_message'] = "Cannot reset rooms: There are {$active_allocations} active allocations in this hostel. Please check out all students first.";
        header("Location: view_hostel.php?id=$hostel_id");
        exit();
    }

    // If confirmation is received, proceed with reset
    if ($confirm == 1) {
        // Verify again that no active allocations exist
        if ($active_allocations > 0) {
            throw new Exception("Cannot reset: Active allocations found");
        }

        // Start transaction
        $pdo->beginTransaction();

        // Reset all rooms to 'Available' status
        $reset_rooms = $pdo->prepare("
            UPDATE hostel_rooms 
            SET status = 'Available' 
            WHERE hostel_id = ?
        ");
        $reset_rooms->execute([$hostel_id]);
        $rooms_reset = $reset_rooms->rowCount();

        // Reset hostel statistics
        $reset_hostel = $pdo->prepare("
            UPDATE hostels 
            SET occupied_beds = 0,
                available_beds = (
                    SELECT SUM(bed_count) 
                    FROM hostel_rooms 
                    WHERE hostel_id = ?
                ),
                status = 'Available'
            WHERE hostel_id = ?
        ");
        $reset_hostel->execute([$hostel_id, $hostel_id]);

        // Log the action
        $log_stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) 
            VALUES (?, 'Reset', ?, 'hostels', ?)
        ");
        $log_stmt->execute([
            $_SESSION['admin_id'],
            "Reset all rooms in hostel: {$hostel['hostel_name']} ({$rooms_reset} rooms affected)",
            $hostel_id
        ]);

        // Commit transaction
        $pdo->commit();

        $_SESSION['success_message'] = "Successfully reset {$rooms_reset} rooms in {$hostel['hostel_name']}. All rooms are now available.";
        header("Location: view_hostel.php?id=$hostel_id");
        exit();
    }

} catch (Exception $e) {
    // Rollback transaction if active
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error resetting hostel rooms: " . $e->getMessage());
    $_SESSION['error_message'] = "Error resetting hostel rooms: " . $e->getMessage();
    header("Location: view_hostel.php?id=$hostel_id");
    exit();
}

// If we get here without confirmation, show confirmation page
$page_title = "Reset Rooms - " . htmlspecialchars($hostel['hostel_name']);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                <li class="breadcrumb-item"><a href="view_hostel.php?id=<?php echo $hostel_id; ?>"><?php echo htmlspecialchars($hostel['hostel_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Reset Rooms</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Reset Hostel Rooms</h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="app-card shadow-sm border-warning">
            <div class="app-card-header bg-warning text-white p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Warning: This action will reset all rooms in <?php echo htmlspecialchars($hostel['hostel_name']); ?>
                </h5>
            </div>
            
            <div class="app-card-body p-4">
                <!-- Current Status Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php 
                                    $room_count = $pdo->prepare("SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = ?");
                                    $room_count->execute([$hostel_id]);
                                    echo $room_count->fetchColumn();
                                ?></h3>
                                <p class="text-muted mb-0">Total Rooms</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?php 
                                    $available_count = $pdo->prepare("
                                        SELECT COUNT(*) FROM hostel_rooms 
                                        WHERE hostel_id = ? AND status = 'Available'
                                    ");
                                    $available_count->execute([$hostel_id]);
                                    echo $available_count->fetchColumn();
                                ?></h3>
                                <p class="text-muted mb-0">Available Rooms</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h3 class="text-warning"><?php 
                                    $maintenance_count = $pdo->prepare("
                                        SELECT COUNT(*) FROM hostel_rooms 
                                        WHERE hostel_id = ? AND status = 'Under Maintenance'
                                    ");
                                    $maintenance_count->execute([$hostel_id]);
                                    echo $maintenance_count->fetchColumn();
                                ?></h3>
                                <p class="text-muted mb-0">Under Maintenance</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Warning Messages -->
                <div class="alert alert-danger">
                    <h6><i class="fas fa-radiation me-2"></i>Immediate Effects:</h6>
                    <ul class="mb-0">
                        <li><strong>All rooms</strong> will be set to <span class="badge bg-success">Available</span> status</li>
                        <li>Any rooms marked as <span class="badge bg-warning">Under Maintenance</span> will be cleared</li>
                        <li>Rooms marked as <span class="badge bg-danger">Occupied</span> will be reset (if any exist)</li>
                        <li>Hostel occupancy statistics will be reset to zero</li>
                    </ul>
                </div>

                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>This action WILL NOT:</h6>
                    <ul class="mb-0">
                        <li>Delete any rooms</li>
                        <li>Remove any room configurations (bed counts, room numbers, etc.)</li>
                        <li>Affect historical allocation records</li>
                        <li>Delete maintenance history</li>
                    </ul>
                </div>

                <?php if ($active_allocations > 0): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-ban me-2"></i>
                    <strong>Cannot proceed:</strong> There are <?php echo $active_allocations; ?> active allocations in this hostel.
                    All students must be checked out before resetting rooms.
                    <a href="hostel_allocations.php?hostel_id=<?php echo $hostel_id; ?>&status=Active" class="alert-link">View Active Allocations</a>
                </div>
                <?php endif; ?>

                <!-- Room Status Distribution -->
                <div class="mb-4">
                    <h6>Current Room Status Distribution:</h6>
                    <?php
                    $status_stats = $pdo->prepare("
                        SELECT status, COUNT(*) as count 
                        FROM hostel_rooms 
                        WHERE hostel_id = ? 
                        GROUP BY status
                    ");
                    $status_stats->execute([$hostel_id]);
                    $stats = $status_stats->fetchAll();
                    
                    $total_rooms = array_sum(array_column($stats, 'count'));
                    ?>
                    
                    <?php foreach ($stats as $stat): ?>
                        <?php 
                        $percentage = $total_rooms > 0 ? round(($stat['count'] / $total_rooms) * 100, 1) : 0;
                        $status_class = [
                            'Available' => 'success',
                            'Occupied' => 'primary',
                            'Under Maintenance' => 'warning',
                            'Reserved' => 'info'
                        ][$stat['status']] ?? 'secondary';
                        ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between">
                                <span>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $stat['status']; ?>
                                    </span>
                                </span>
                                <span><?php echo $stat['count']; ?> rooms (<?php echo $percentage; ?>%)</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                     style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Confirmation Form -->
                <?php if ($active_allocations == 0): ?>
                <div class="border-top pt-4">
                    <form method="POST" action="reset_hostel_rooms.php?id=<?php echo $hostel_id; ?>&confirm=1" 
                          id="resetForm" onsubmit="return confirmReset()">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                Type <span class="text-danger">RESET</span> to confirm:
                            </label>
                            <input type="text" class="form-control" id="confirmText" 
                                   placeholder="Enter RESET to confirm" autocomplete="off">
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="understandCheck">
                            <label class="form-check-label" for="understandCheck">
                                I understand that this action will reset all room statuses and cannot be undone
                            </label>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="view_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-danger" id="resetBtn" disabled>
                                <i class="fas fa-sync-alt me-2"></i>Reset All Rooms
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between">
                    <a href="view_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Hostel
                    </a>
                    <a href="hostel_allocations.php?hostel_id=<?php echo $hostel_id; ?>&status=Active" class="btn btn-primary">
                        <i class="fas fa-bed me-2"></i>View Active Allocations
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Related Actions Card -->
        <div class="app-card shadow-sm mt-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-tools me-2"></i>Related Actions
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="row">
                    <div class="col-md-4">
                        <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body text-center p-3">
                                    <i class="fas fa-door-closed fa-2x text-primary mb-2"></i>
                                    <h6>Manage Rooms</h6>
                                    <small class="text-muted">Edit individual rooms</small>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="hostel_maintenance.php?hostel_id=<?php echo $hostel_id; ?>" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body text-center p-3">
                                    <i class="fas fa-tools fa-2x text-warning mb-2"></i>
                                    <h6>Maintenance</h6>
                                    <small class="text-muted">View maintenance requests</small>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="edit_hostel.php?id=<?php echo $hostel_id; ?>" class="text-decoration-none">
                            <div class="card bg-light">
                                <div class="card-body text-center p-3">
                                    <i class="fas fa-edit fa-2x text-success mb-2"></i>
                                    <h6>Edit Hostel</h6>
                                    <small class="text-muted">Update hostel details</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmReset() {
    const confirmText = document.getElementById('confirmText').value;
    const understandCheck = document.getElementById('understandCheck').checked;
    
    if (confirmText !== 'RESET') {
        alert('Please type RESET to confirm');
        return false;
    }
    
    if (!understandCheck) {
        alert('Please check the confirmation box');
        return false;
    }
    
    return confirm('Are you absolutely sure you want to reset all rooms? This action cannot be undone.');
}

// Enable/disable reset button based on input
document.getElementById('confirmText').addEventListener('input', function() {
    const resetBtn = document.getElementById('resetBtn');
    const understandCheck = document.getElementById('understandCheck').checked;
    resetBtn.disabled = this.value !== 'RESET' || !understandCheck;
});

document.getElementById('understandCheck').addEventListener('change', function() {
    const resetBtn = document.getElementById('resetBtn');
    const confirmText = document.getElementById('confirmText').value;
    resetBtn.disabled = confirmText !== 'RESET' || !this.checked;
});

// Prevent accidental form resubmission
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

<style>
.card {
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
}
.border-warning {
    border-left: 4px solid #ffc107 !important;
}
</style>

<?php
require_once 'includes/footer.php';
?>