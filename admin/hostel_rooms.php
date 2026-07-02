<?php
// hostel_rooms.php
ob_start();
require_once 'includes/header.php';

$page_title = "Hostel Rooms Management";

// Get hostel ID from URL
$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;

// Get hostel info
$hostel = null;
if ($hostel_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM hostels WHERE hostel_id = ?");
    $stmt->execute([$hostel_id]);
    $hostel = $stmt->fetch();
}

if (!$hostel && $hostel_id > 0) {
    $_SESSION['error'] = "Hostel not found";
    header("Location: hostels.php");
    exit();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Add Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid security token";
    } else {
        if ($_POST['action'] === 'add_room') {
            $room_number = trim($_POST['room_number']);
            $room_type = $_POST['room_type'];
            $bed_count = (int)$_POST['bed_count'];
            $monthly_rent = (float)$_POST['monthly_rent'];
            
            try {
                $check = $pdo->prepare("SELECT room_id FROM hostel_rooms WHERE hostel_id = ? AND room_number = ?");
                $check->execute([$hostel_id, $room_number]);
                if ($check->fetch()) {
                    throw new Exception("Room number already exists");
                }
                
                $insert = $pdo->prepare("
                    INSERT INTO hostel_rooms (hostel_id, room_number, room_type, bed_count, monthly_rent, status, created_date)
                    VALUES (?, ?, ?, ?, ?, 'Available', NOW())
                ");
                $insert->execute([$hostel_id, $room_number, $room_type, $bed_count, $monthly_rent]);
                
                // Update hostel total rooms count
                $update = $pdo->prepare("
                    UPDATE hostels SET 
                        total_rooms = (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = ?),
                        available_beds = (SELECT SUM(bed_count) FROM hostel_rooms WHERE hostel_id = ?) - occupied_beds
                    WHERE hostel_id = ?
                ");
                $update->execute([$hostel_id, $hostel_id, $hostel_id]);
                
                $_SESSION['success'] = "Room added successfully!";
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
            header("Location: hostel_rooms.php?hostel_id=$hostel_id");
            exit();
        }
        
        if ($_POST['action'] === 'delete_room') {
            $room_id = (int)$_POST['room_id'];
            
            try {
                // Check if room has allocations
                $check = $pdo->prepare("SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ? AND status = 'Active'");
                $check->execute([$room_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete room with active allocations");
                }
                
                $delete = $pdo->prepare("DELETE FROM hostel_rooms WHERE room_id = ?");
                $delete->execute([$room_id]);
                
                // Update hostel totals
                $update = $pdo->prepare("
                    UPDATE hostels SET 
                        total_rooms = (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = ?),
                        available_beds = (SELECT SUM(bed_count) FROM hostel_rooms WHERE hostel_id = ?) - occupied_beds
                    WHERE hostel_id = ?
                ");
                $update->execute([$hostel_id, $hostel_id, $hostel_id]);
                
                $_SESSION['success'] = "Room deleted successfully!";
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
            header("Location: hostel_rooms.php?hostel_id=$hostel_id");
            exit();
        }
    }
}

// Get rooms
$rooms = [];
if ($hostel_id > 0) {
    $stmt = $pdo->prepare("
        SELECT r.*,
            (SELECT COUNT(*) FROM hostel_allocations WHERE room_id = r.room_id AND status = 'Active') as occupied_beds
        FROM hostel_rooms r
        WHERE r.hostel_id = ?
        ORDER BY r.room_number
    ");
    $stmt->execute([$hostel_id]);
    $rooms = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin Portal</title>
    <style>
        .room-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .bed-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 2px;
            font-size: 12px;
        }
        .bed-available { background: #d4edda; color: #155724; }
        .bed-occupied { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="fas fa-door-open text-primary me-2"></i>
                <?php echo $hostel ? htmlspecialchars($hostel['hostel_name']) : 'Rooms Management'; ?>
            </h1>
            <?php if ($hostel): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 mt-2">
                    <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($hostel['hostel_name']); ?> - Rooms</li>
                </ol>
            </nav>
            <?php endif; ?>
        </div>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                <i class="fas fa-plus me-2"></i>Add Room
            </button>
            <a href="hostels.php" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left me-2"></i>Back to Hostels
            </a>
        </div>
    </div>

    <!-- Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Summary -->
    <?php if ($hostel): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Rooms</h6>
                    <h3 class="mb-0"><?php echo count($rooms); ?> / <?php echo $hostel['total_rooms']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Beds</h6>
                    <h3 class="mb-0"><?php echo array_sum(array_column($rooms, 'bed_count')); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h6 class="card-title">Occupied Beds</h6>
                    <h3 class="mb-0"><?php echo $hostel['occupied_beds']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6 class="card-title">Available Beds</h6>
                    <h3 class="mb-0"><?php echo $hostel['available_beds']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rooms Grid -->
    <div class="row g-4">
        <?php if (empty($rooms)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-door-open fa-3x mb-3 d-block text-muted"></i>
                    <h5>No rooms found</h5>
                    <p>Click "Add Room" to create rooms for this hostel.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($rooms as $room): 
                $available_beds = $room['bed_count'] - $room['occupied_beds'];
                $occupancy = $room['bed_count'] > 0 ? ($room['occupied_beds'] / $room['bed_count']) * 100 : 0;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card room-card h-100">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Room <?php echo htmlspecialchars($room['room_number']); ?></h5>
                            <span class="badge bg-secondary"><?php echo $room['room_type']; ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="d-flex flex-wrap justify-content-center">
                                <?php for ($i = 1; $i <= $room['bed_count']; $i++): ?>
                                    <div class="bed-icon <?php echo $i <= $room['occupied_beds'] ? 'bed-occupied' : 'bed-available'; ?>">
                                        <i class="fas fa-bed"></i>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <small class="text-muted"><?php echo $room['occupied_beds']; ?>/<?php echo $room['bed_count']; ?> beds occupied</small>
                        </div>
                        
                        <div class="mb-2">
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar <?php echo $occupancy > 90 ? 'bg-danger' : ($occupancy > 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                     style="width: <?php echo $occupancy; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <i class="fas fa-money-bill-wave text-success me-1"></i>
                            <strong>₦<?php echo number_format($room['monthly_rent'], 2); ?></strong> / month
                        </div>
                        
                        <div class="mb-2">
                            <i class="fas fa-bed me-1"></i>
                            <strong><?php echo $available_beds; ?></strong> available beds
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <div class="btn-group w-100">
                            <a href="hostel_allocations.php?room_id=<?php echo $room['room_id']; ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-user-plus me-1"></i>Allocate
                            </a>
                            <button class="btn btn-sm btn-danger" onclick="deleteRoom(<?php echo $room['room_id']; ?>, '<?php echo addslashes($room['room_number']); ?>')">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Room Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_room">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Room</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Room Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="room_number" required placeholder="e.g., 101, 102, A101">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Room Type</label>
                        <select class="form-select" name="room_type">
                            <option value="Standard">Standard</option>
                            <option value="Economy">Economy</option>
                            <option value="Deluxe">Deluxe</option>
                            <option value="Executive">Executive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Number of Beds <span class="text-danger">*</span></label>
                        <select class="form-select" name="bed_count" required>
                            <option value="2">2 Beds</option>
                            <option value="4" selected>4 Beds</option>
                            <option value="6">6 Beds</option>
                            <option value="8">8 Beds</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monthly Rent (₦)</label>
                        <input type="number" class="form-control" name="monthly_rent" value="<?php echo $hostel['monthly_rent'] ?? 0; ?>" step="1000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete_room">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="room_id" id="delete_room_id">
    <input type="hidden" name="hostel_id" value="<?php echo $hostel_id; ?>">
</form>

<script>
function deleteRoom(id, number) {
    if (confirm(`Delete Room ${number}? This action cannot be undone.`)) {
        document.getElementById('delete_room_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>