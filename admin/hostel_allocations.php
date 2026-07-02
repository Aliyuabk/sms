<?php
// hostel_allocations.php
ob_start();
require_once 'includes/header.php';

$page_title = "Hostel Allocations";

// Get parameters
$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

// Get academic session
$current_session = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn();
if (!$current_session) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get hostels for dropdown
$hostels = $pdo->query("SELECT * FROM hostels WHERE status = 'Available' ORDER BY hostel_name")->fetchAll();

// Get rooms based on selected hostel
$rooms = [];
if ($hostel_id > 0) {
    $stmt = $pdo->prepare("
        SELECT r.*, 
            (SELECT COUNT(*) FROM hostel_allocations WHERE room_id = r.room_id AND status = 'Active') as occupied_beds
        FROM hostel_rooms r
        WHERE r.hostel_id = ? AND r.status = 'Available'
        HAVING occupied_beds < r.bed_count
        ORDER BY r.room_number
    ");
    $stmt->execute([$hostel_id]);
    $rooms = $stmt->fetchAll();
}

// Get specific room if selected
$selected_room = null;
if ($room_id > 0) {
    $stmt = $pdo->prepare("
        SELECT r.*, h.hostel_name, h.gender,
            (SELECT COUNT(*) FROM hostel_allocations WHERE room_id = r.room_id AND status = 'Active') as occupied_beds
        FROM hostel_rooms r
        JOIN hostels h ON r.hostel_id = h.hostel_id
        WHERE r.room_id = ?
    ");
    $stmt->execute([$room_id]);
    $selected_room = $stmt->fetch();
    
    if ($selected_room) {
        $hostel_id = $selected_room['hostel_id'];
    }
}

// Handle Allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid security token";
    } else {
        if ($_POST['action'] === 'allocate') {
            $matric_number = strtoupper(trim($_POST['matric_number']));
            $allocation_room_id = (int)$_POST['room_id'];
            $academic_year = $_POST['academic_year'];
            $notes = trim($_POST['notes'] ?? '');
            
            try {
                // Get student details
                $student_stmt = $pdo->prepare("
                    SELECT s.*, d.gender as student_gender 
                    FROM students s
                    LEFT JOIN departments d ON s.department_id = d.department_id
                    WHERE s.matric_number = ? AND s.status = 'Active'
                ");
                $student_stmt->execute([$matric_number]);
                $student = $student_stmt->fetch();
                
                if (!$student) {
                    throw new Exception("Student not found or inactive");
                }
                
                // Get room details
                $room_stmt = $pdo->prepare("
                    SELECT r.*, h.gender as hostel_gender, h.hostel_name
                    FROM hostel_rooms r
                    JOIN hostels h ON r.hostel_id = h.hostel_id
                    WHERE r.room_id = ?
                ");
                $room_stmt->execute([$allocation_room_id]);
                $room = $room_stmt->fetch();
                
                if (!$room) {
                    throw new Exception("Room not found");
                }
                
                // Check gender compatibility
                if ($room['hostel_gender'] != 'Mixed' && $room['hostel_gender'] != $student['gender']) {
                    throw new Exception("Gender mismatch. This hostel is for {$room['hostel_gender']} students only.");
                }
                
                // Check if student already has active allocation
                $check_stmt = $pdo->prepare("
                    SELECT ha.* FROM hostel_allocations ha
                    JOIN hostel_rooms hr ON ha.room_id = hr.room_id
                    WHERE ha.student_id = ? AND ha.status = 'Active'
                ");
                $check_stmt->execute([$student['student_id']]);
                if ($check_stmt->fetch()) {
                    throw new Exception("Student already has an active hostel allocation");
                }
                
                // Check available beds
                $occupied = $pdo->prepare("
                    SELECT COUNT(*) FROM hostel_allocations 
                    WHERE room_id = ? AND status = 'Active'
                ");
                $occupied->execute([$allocation_room_id]);
                $occupied_count = $occupied->fetchColumn();
                
                if ($occupied_count >= $room['bed_count']) {
                    throw new Exception("Room is full. No available beds.");
                }
                
                // Get next bed number
                $bed_stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(bed_number), 0) + 1 as next_bed 
                    FROM hostel_allocations 
                    WHERE room_id = ? AND status = 'Active'
                ");
                $bed_stmt->execute([$allocation_room_id]);
                $bed_number = $bed_stmt->fetchColumn();
                if ($bed_number > $room['bed_count']) {
                    $bed_number = $room['bed_count'];
                }
                
                // Create allocation
                $start_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime('+1 year'));
                
                $insert = $pdo->prepare("
                    INSERT INTO hostel_allocations (
                        student_id, hostel_id, room_id, bed_number, academic_year,
                        start_date, end_date, payment_status, status, allocation_date, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'Active', NOW(), ?)
                ");
                $insert->execute([
                    $student['student_id'], $room['hostel_id'], $allocation_room_id, $bed_number,
                    $academic_year, $start_date, $end_date, $notes
                ]);
                
                // Update hostel occupied beds
                $update_hostel = $pdo->prepare("
                    UPDATE hostels SET 
                        occupied_beds = (SELECT COUNT(*) FROM hostel_allocations ha 
                                         JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
                                         WHERE hr.hostel_id = ? AND ha.status = 'Active'),
                        available_beds = (SELECT SUM(bed_count) - COUNT(ha.allocation_id)
                                         FROM hostel_rooms r
                                         LEFT JOIN hostel_allocations ha ON r.room_id = ha.room_id AND ha.status = 'Active'
                                         WHERE r.hostel_id = ?)
                    WHERE hostel_id = ?
                ");
                $update_hostel->execute([$room['hostel_id'], $room['hostel_id'], $room['hostel_id']]);
                
                $_SESSION['success'] = "Student {$student['first_name']} {$student['last_name']} allocated to Room {$room['room_number']}, Bed {$bed_number}";
                
                header("Location: hostel_allocations.php?hostel_id={$room['hostel_id']}");
                exit();
                
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
                header("Location: hostel_allocations.php" . ($room_id ? "?room_id=$room_id" : ""));
                exit();
            }
        }
        
        if ($_POST['action'] === 'cancel_allocation') {
            $allocation_id = (int)$_POST['allocation_id'];
            
            try {
                $get_stmt = $pdo->prepare("
                    SELECT ha.*, hr.hostel_id, hr.room_number 
                    FROM hostel_allocations ha
                    JOIN hostel_rooms hr ON ha.room_id = hr.room_id
                    WHERE ha.allocation_id = ?
                ");
                $get_stmt->execute([$allocation_id]);
                $allocation = $get_stmt->fetch();
                
                if (!$allocation) {
                    throw new Exception("Allocation not found");
                }
                
                $update = $pdo->prepare("UPDATE hostel_allocations SET status = 'Cancelled' WHERE allocation_id = ?");
                $update->execute([$allocation_id]);
                
                // Update hostel counts
                $update_hostel = $pdo->prepare("
                    UPDATE hostels SET 
                        occupied_beds = (SELECT COUNT(*) FROM hostel_allocations ha 
                                         JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
                                         WHERE hr.hostel_id = ? AND ha.status = 'Active'),
                        available_beds = (SELECT SUM(bed_count) - COUNT(ha.allocation_id)
                                         FROM hostel_rooms r
                                         LEFT JOIN hostel_allocations ha ON r.room_id = ha.room_id AND ha.status = 'Active'
                                         WHERE r.hostel_id = ?)
                    WHERE hostel_id = ?
                ");
                $update_hostel->execute([$allocation['hostel_id'], $allocation['hostel_id'], $allocation['hostel_id']]);
                
                $_SESSION['success'] = "Allocation cancelled successfully";
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
            
            header("Location: hostel_allocations.php?hostel_id=" . ($allocation['hostel_id'] ?? $hostel_id));
            exit();
        }
    }
}

// Get allocations
$allocations = [];
if ($hostel_id > 0) {
    $stmt = $pdo->prepare("
        SELECT ha.*, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
               hr.room_number, h.hostel_name, h.gender as hostel_gender
        FROM hostel_allocations ha
        JOIN students s ON ha.student_id = s.student_id
        JOIN hostel_rooms hr ON ha.room_id = hr.room_id
        JOIN hostels h ON hr.hostel_id = h.hostel_id
        WHERE h.hostel_id = ? AND ha.status != 'Cancelled'
        ORDER BY ha.allocation_date DESC
    ");
    $stmt->execute([$hostel_id]);
    $allocations = $stmt->fetchAll();
} elseif ($room_id > 0) {
    $stmt = $pdo->prepare("
        SELECT ha.*, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
               hr.room_number, h.hostel_name, h.gender as hostel_gender
        FROM hostel_allocations ha
        JOIN students s ON ha.student_id = s.student_id
        JOIN hostel_rooms hr ON ha.room_id = hr.room_id
        JOIN hostels h ON hr.hostel_id = h.hostel_id
        WHERE ha.room_id = ? AND ha.status != 'Cancelled'
        ORDER BY ha.bed_number
    ");
    $stmt->execute([$room_id]);
    $allocations = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin Portal</title>
    <style>
        .allocation-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .student-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
        }
        .bed-badge {
            display: inline-block;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #28a745;
            color: white;
            text-align: center;
            line-height: 40px;
            font-weight: bold;
            margin: 5px;
        }
        .bed-occupied {
            background: #dc3545;
        }
        .status-pending { background: #ffc107; color: #856404; }
        .status-active { background: #d4edda; color: #155724; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">
                <i class="fas fa-bed text-primary me-2"></i>Hostel Allocations
            </h1>
            <?php if ($selected_room): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 mt-2">
                    <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                    <li class="breadcrumb-item"><a href="hostel_rooms.php?hostel_id=<?php echo $selected_room['hostel_id']; ?>"><?php echo htmlspecialchars($selected_room['hostel_name']); ?></a></li>
                    <li class="breadcrumb-item active">Room <?php echo htmlspecialchars($selected_room['room_number']); ?> - Allocations</li>
                </ol>
            </nav>
            <?php endif; ?>
        </div>
        <div>
            <a href="hostels.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
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

    <!-- Allocation Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Allocate Student</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="allocate">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <?php if (!$selected_room): ?>
                <div class="col-md-4">
                    <label class="form-label">Select Hostel</label>
                    <select class="form-select" name="hostel_id" id="hostel_select" required>
                        <option value="">-- Select Hostel --</option>
                        <?php foreach ($hostels as $h): ?>
                        <option value="<?php echo $h['hostel_id']; ?>" <?php echo $hostel_id == $h['hostel_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($h['hostel_name']); ?> (<?php echo $h['gender']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-<?php echo $selected_room ? '6' : '4'; ?>">
                    <label class="form-label">Select Room</label>
                    <select class="form-select" name="room_id" id="room_select" required>
                        <?php if ($selected_room): ?>
                        <option value="<?php echo $selected_room['room_id']; ?>" selected>
                            Room <?php echo htmlspecialchars($selected_room['room_number']); ?> 
                            (<?php echo $selected_room['occupied_beds']; ?>/<?php echo $selected_room['bed_count']; ?> beds)
                        </option>
                        <?php else: ?>
                        <option value="">-- First Select Hostel --</option>
                        <?php foreach ($rooms as $r): ?>
                        <option value="<?php echo $r['room_id']; ?>" data-beds="<?php echo $r['bed_count']; ?>" data-occupied="<?php echo $r['occupied_beds']; ?>">
                            Room <?php echo htmlspecialchars($r['room_number']); ?> 
                            (<?php echo $r['occupied_beds']; ?>/<?php echo $r['bed_count']; ?> beds)
                        </option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-<?php echo $selected_room ? '6' : '4'; ?>">
                    <label class="form-label">Student Registration Number</label>
                    <input type="text" class="form-control" name="matric_number" placeholder="e.g., CSC2024001" required>
                </div>
                
                <div class="col-md-<?php echo $selected_room ? '12' : '12'; ?>">
                    <label class="form-label">Academic Year</label>
                    <input type="text" class="form-control" name="academic_year" value="<?php echo $current_session; ?>" required>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Any special notes about this allocation..."></textarea>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Allocate Student
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Verification Preview -->
    <div class="card mb-4" id="studentPreview" style="display: none;">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Student Information</h5>
        </div>
        <div class="card-body" id="studentInfo">
        </div>
    </div>

    <!-- Allocations List -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current Allocations</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Matric No</th>
                            <th>Room</th>
                            <th>Bed</th>
                            <th>Allocation Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allocations)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="fas fa-bed fa-2x text-muted mb-2 d-block"></i>
                                No allocations found.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($allocations as $alloc): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($alloc['first_name'] . ' ' . $alloc['last_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($alloc['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($alloc['matric_number']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($alloc['room_number']); ?>
                                    <br><small><?php echo htmlspecialchars($alloc['hostel_name']); ?></small>
                                 </td>
                                <td><span class="badge bg-secondary">Bed <?php echo $alloc['bed_number']; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($alloc['allocation_date'])); ?></td>
                                <td>
                                    <span class="badge <?php echo $alloc['status'] == 'Active' ? 'bg-success' : ($alloc['status'] == 'Pending' ? 'bg-warning' : 'bg-secondary'); ?>">
                                        <?php echo $alloc['status']; ?>
                                    </span>
                                 </td>
                                <td>
                                    <?php if ($alloc['status'] == 'Active'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel this allocation?')">
                                        <input type="hidden" name="action" value="cancel_allocation">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="allocation_id" value="<?php echo $alloc['allocation_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Student verification on matric number input
const matricInput = document.querySelector('input[name="matric_number"]');
let verifyTimeout;

matricInput?.addEventListener('input', function() {
    clearTimeout(verifyTimeout);
    const matric = this.value.trim().toUpperCase();
    
    if (matric.length < 5) {
        document.getElementById('studentPreview').style.display = 'none';
        return;
    }
    
    verifyTimeout = setTimeout(() => {
        fetch(`ajax/get_student.php?matric=${encodeURIComponent(matric)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.student) {
                    document.getElementById('studentInfo').innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-user me-2"></i>Name:</strong> ${data.student.first_name} ${data.student.last_name || ''}</p>
                                <p><strong><i class="fas fa-graduation-cap me-2"></i>Department:</strong> ${data.student.department_name || 'N/A'}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-envelope me-2"></i>Email:</strong> ${data.student.email || 'N/A'}</p>
                                <p><strong><i class="fas fa-phone me-2"></i>Phone:</strong> ${data.student.phone || 'N/A'}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('studentPreview').style.display = 'block';
                } else {
                    document.getElementById('studentInfo').innerHTML = `
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i> ${data.message || 'Student not found'}
                        </div>
                    `;
                    document.getElementById('studentPreview').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
    }, 500);
});

// Hostel change to load rooms
document.getElementById('hostel_select')?.addEventListener('change', function() {
    const hostelId = this.value;
    const roomSelect = document.getElementById('room_select');
    
    if (hostelId) {
        roomSelect.innerHTML = '<option value="">Loading...</option>';
        fetch(`ajax/get_rooms_by_hostel.php?hostel_id=${hostelId}`)
            .then(res => res.json())
            .then(data => {
                roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
                if (data.success && data.rooms) {
                    data.rooms.forEach(room => {
                        const option = document.createElement('option');
                        option.value = room.room_id;
                        option.textContent = `Room ${room.room_number} (${room.occupied_beds}/${room.bed_count} beds available)`;
                        roomSelect.appendChild(option);
                    });
                } else {
                    roomSelect.innerHTML = '<option value="">No available rooms</option>';
                }
            });
    } else {
        roomSelect.innerHTML = '<option value="">-- First Select Hostel --</option>';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>