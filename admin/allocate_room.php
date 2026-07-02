<?php
// allocate_room.php
ob_start();

require_once 'includes/header.php';

// Get parameters
$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

if ($hostel_id <= 0) {
    $_SESSION['error_message'] = "Invalid hostel ID";
    header("Location: hostels.php");
    exit();
}

// Fetch hostel details
$hostel_stmt = $pdo->prepare("SELECT * FROM hostels WHERE hostel_id = ?");
$hostel_stmt->execute([$hostel_id]);
$hostel = $hostel_stmt->fetch();

if (!$hostel) {
    $_SESSION['error_message'] = "Hostel not found";
    header("Location: hostels.php");
    exit();
}

$page_title = "Allocate Room - " . htmlspecialchars($hostel['hostel_name']);

// Fetch available rooms
$rooms_stmt = $pdo->prepare("
    SELECT 
        hr.*,
        (SELECT COUNT(*) FROM hostel_allocations ha 
         WHERE ha.room_id = hr.room_id AND ha.status = 'Active') as occupied_beds,
        (hr.bed_count - (SELECT COUNT(*) FROM hostel_allocations ha 
         WHERE ha.room_id = hr.room_id AND ha.status = 'Active')) as available_beds
    FROM hostel_rooms hr
    WHERE hr.hostel_id = ? 
        AND hr.status = 'Available'
        AND hr.bed_count > (
            SELECT COUNT(*) FROM hostel_allocations ha 
            WHERE ha.room_id = hr.room_id AND ha.status = 'Active'
        )
    ORDER BY hr.floor_number, hr.room_number
");
$rooms_stmt->execute([$hostel_id]);
$available_rooms = $rooms_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_room'])) {
    $selected_student_id = (int)$_POST['student_id'];
    $selected_room_id = (int)$_POST['room_id'];
    $bed_number = (int)$_POST['bed_number'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $payment_status = $_POST['payment_status'];
    $notes = trim($_POST['notes']);

    $errors = [];

    // Validation
    if ($selected_student_id <= 0) {
        $errors[] = "Please select a student";
    }
    if ($selected_room_id <= 0) {
        $errors[] = "Please select a room";
    }
    if ($bed_number <= 0) {
        $errors[] = "Please select a bed number";
    }
    if (empty($start_date)) {
        $errors[] = "Start date is required";
    }
    if (empty($end_date)) {
        $errors[] = "End date is required";
    }
    if (strtotime($end_date) <= strtotime($start_date)) {
        $errors[] = "End date must be after start date";
    }

    // Check if student already has active allocation
    if (empty($errors)) {
        $check_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM hostel_allocations 
            WHERE student_id = ? AND status = 'Active'
        ");
        $check_stmt->execute([$selected_student_id]);
        if ($check_stmt->fetchColumn() > 0) {
            $errors[] = "Student already has an active hostel allocation";
        }
    }

    // Check if bed is still available
    if (empty($errors)) {
        $bed_check = $pdo->prepare("
            SELECT COUNT(*) FROM hostel_allocations 
            WHERE room_id = ? AND bed_number = ? AND status = 'Active'
        ");
        $bed_check->execute([$selected_room_id, $bed_number]);
        if ($bed_check->fetchColumn() > 0) {
            $errors[] = "Selected bed is no longer available";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Create allocation
            $insert_sql = "INSERT INTO hostel_allocations (
                student_id, hostel_id, room_id, bed_number, 
                academic_year, start_date, end_date, payment_status, 
                status, notes, allocation_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW())";

            $academic_year = date('Y') . '/' . (date('Y') + 1);
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $selected_student_id, $hostel_id, $selected_room_id, $bed_number,
                $academic_year, $start_date, $end_date, $payment_status, $notes
            ]);

            $allocation_id = $pdo->lastInsertId();

            // Update room status if fully occupied
            $room_check = $pdo->prepare("
                SELECT 
                    bed_count,
                    (SELECT COUNT(*) FROM hostel_allocations 
                     WHERE room_id = ? AND status = 'Active') as occupied
                FROM hostel_rooms WHERE room_id = ?
            ");
            $room_check->execute([$selected_room_id, $selected_room_id]);
            $room_data = $room_check->fetch();

            if ($room_data['occupied'] >= $room_data['bed_count']) {
                $update_room = $pdo->prepare("UPDATE hostel_rooms SET status = 'Occupied' WHERE room_id = ?");
                $update_room->execute([$selected_room_id]);
            }

            // Create invoice
            $invoice_sql = "INSERT INTO student_fees (
                student_id, session_year, semester, fee_type, description, 
                amount, due_date, status, invoice_number
            ) VALUES (?, ?, 1, 'Hostel Accommodation', ?, ?, ?, 'Pending', ?)";

            $invoice_number = 'HSTL-' . date('Ymd') . '-' . str_pad($allocation_id, 5, '0', STR_PAD_LEFT);
            $amount = $hostel['monthly_rent'] * 12; // Annual rent

            $invoice_stmt = $pdo->prepare($invoice_sql);
            $invoice_stmt->execute([
                $selected_student_id,
                $academic_year,
                "Hostel accommodation for {$hostel['hostel_name']} (Room {$room_data['room_number']}, Bed {$bed_number})",
                $amount,
                date('Y-m-d', strtotime('+30 days')),
                $invoice_number
            ]);

            // Log the allocation
            $log_stmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) 
                VALUES (?, 'Create', ?, 'hostel_allocations', ?)
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                "Allocated room in {$hostel['hostel_name']} to student ID: $selected_student_id",
                $allocation_id
            ]);

            $pdo->commit();

            $_SESSION['success_message'] = "Room allocated successfully! Invoice #$invoice_number created.";
            header("Location: view_allocation.php?id=$allocation_id");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Allocation error: " . $e->getMessage());
            $errors[] = "Error creating allocation: " . $e->getMessage();
        }
    }
}

// Fetch student if ID provided
$selected_student = null;
if ($student_id > 0) {
    $student_stmt = $pdo->prepare("
        SELECT s.*, d.department_name, p.program_name 
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.student_id = ?
    ");
    $student_stmt->execute([$student_id]);
    $selected_student = $student_stmt->fetch();
}

// Fetch room if ID provided
$selected_room = null;
if ($room_id > 0) {
    $room_stmt = $pdo->prepare("
        SELECT hr.*, h.hostel_name, h.monthly_rent
        FROM hostel_rooms hr
        JOIN hostels h ON hr.hostel_id = h.hostel_id
        WHERE hr.room_id = ? AND hr.hostel_id = ?
    ");
    $room_stmt->execute([$room_id, $hostel_id]);
    $selected_room = $room_stmt->fetch();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                <li class="breadcrumb-item"><a href="view_hostel.php?id=<?php echo $hostel_id; ?>"><?php echo htmlspecialchars($hostel['hostel_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Allocate Room</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Allocate Room</h1>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-bed me-2"></i>Allocation Details
                </h5>
            </div>
            <div class="app-card-body p-4">
                <form method="POST" action="" id="allocationForm">
                    <!-- Student Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Student <span class="text-danger">*</span></label>
                        <div class="row">
                            <div class="col-md-8">
                                <select class="form-select" name="student_id" id="studentSelect" required>
                                    <option value="">Search for student...</option>
                                    <?php if ($selected_student): ?>
                                        <option value="<?php echo $selected_student['student_id']; ?>" selected>
                                            <?php echo htmlspecialchars($selected_student['matric_number'] . ' - ' . $selected_student['first_name'] . ' ' . $selected_student['last_name']); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#studentSearchModal">
                                    <i class="fas fa-search me-2"></i>Browse Students
                                </button>
                            </div>
                        </div>
                        <div id="studentInfo" class="mt-2 small text-muted"></div>
                    </div>

                    <!-- Room Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Room <span class="text-danger">*</span></label>
                        <select class="form-select" name="room_id" id="roomSelect" required>
                            <option value="">Choose a room...</option>
                            <?php foreach ($available_rooms as $room): ?>
                                <?php 
                                $selected = ($selected_room && $selected_room['room_id'] == $room['room_id']) ? 'selected' : '';
                                $available = $room['available_beds'];
                                ?>
                                <option value="<?php echo $room['room_id']; ?>" 
                                        data-beds="<?php echo $room['bed_count']; ?>"
                                        data-available="<?php echo $available; ?>"
                                        data-floor="<?php echo $room['floor_number']; ?>"
                                        <?php echo $selected; ?>>
                                    Room <?php echo $room['room_number']; ?> (Floor <?php echo $room['floor_number']; ?>) - 
                                    <?php echo $room['room_type']; ?> - 
                                    <?php echo $available; ?>/<?php echo $room['bed_count']; ?> beds available
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-2" id="roomDetails"></div>
                    </div>

                    <!-- Bed Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Bed Number <span class="text-danger">*</span></label>
                        <select class="form-select" name="bed_number" id="bedSelect" required>
                            <option value="">First select a room</option>
                        </select>
                    </div>

                    <!-- Dates -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" 
                                   class="form-control" 
                                   name="start_date" 
                                   value="<?php echo date('Y-m-d'); ?>" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   required>
                            <div class="form-text">Allocation start date</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                            <input type="date" 
                                   class="form-control" 
                                   name="end_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" 
                                   required>
                            <div class="form-text">Usually one academic year</div>
                        </div>
                    </div>

                    <!-- Payment Information -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Payment Status</label>
                        <select class="form-select" name="payment_status">
                            <option value="Pending">Pending</option>
                            <option value="Paid">Paid</option>
                            <option value="Partial">Partial</option>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Additional Notes</label>
                        <textarea class="form-control" name="notes" rows="3" 
                                  placeholder="Any special requirements or notes about this allocation"></textarea>
                    </div>

                    <!-- Fee Summary -->
                    <div class="alert alert-info" id="feeSummary" style="display: none;">
                        <h6 class="alert-heading"><i class="fas fa-calculator me-2"></i>Fee Summary</h6>
                        <div class="row">
                            <div class="col-sm-6">
                                <small>Monthly Rent:</small>
                                <strong class="d-block">₦<?php echo number_format($hostel['monthly_rent'], 2); ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small>Annual Total:</small>
                                <strong class="d-block text-primary">₦<?php echo number_format($hostel['monthly_rent'] * 12, 2); ?></strong>
                            </div>
                        </div>
                        <hr>
                        <p class="mb-0 small">
                            <i class="fas fa-info-circle me-1"></i>
                            An invoice will be generated automatically upon allocation.
                        </p>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="view_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" name="allocate_room" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Allocation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Hostel Info Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-building me-2"></i>Hostel Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <h6><?php echo htmlspecialchars($hostel['hostel_name']); ?></h6>
                <p class="text-muted small mb-2">Code: <?php echo htmlspecialchars($hostel['hostel_code']); ?></p>
                
                <table class="table table-sm table-borderless">
                    <tr>
                        <td>Gender:</td>
                        <td>
                            <span class="badge bg-<?php echo $hostel['gender'] == 'Male' ? 'primary' : ($hostel['gender'] == 'Female' ? 'danger' : 'success'); ?>">
                                <?php echo $hostel['gender']; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td>Monthly Rent:</td>
                        <td class="fw-bold">₦<?php echo number_format($hostel['monthly_rent'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Available Rooms:</td>
                        <td><?php echo count($available_rooms); ?> rooms</td>
                    </tr>
                </table>

                <?php if ($hostel['warden_name']): ?>
                <div class="mt-2 pt-2 border-top">
                    <small class="text-muted d-block">Warden: <?php echo htmlspecialchars($hostel['warden_name']); ?></small>
                    <?php if ($hostel['warden_phone']): ?>
                        <small class="text-muted d-block">📞 <?php echo htmlspecialchars($hostel['warden_phone']); ?></small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Availability
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php
                $total_rooms = count($available_rooms);
                $total_beds_available = array_sum(array_column($available_rooms, 'available_beds'));
                ?>
                
                <div class="text-center mb-3">
                    <div class="display-4 fw-bold text-primary"><?php echo $total_beds_available; ?></div>
                    <div class="text-muted">Beds Available</div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Rooms Available:</span>
                        <span class="fw-bold"><?php echo $total_rooms; ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Average per room:</span>
                        <span class="fw-bold"><?php echo $total_rooms > 0 ? round($total_beds_available / $total_rooms, 1) : 0; ?> beds</span>
                    </div>
                </div>

                <?php if ($total_rooms > 0): ?>
                <div class="mt-3">
                    <h6 class="small fw-bold">Quick Pick by Floor:</h6>
                    <div class="list-group list-group-flush">
                        <?php
                        $floors = array_unique(array_column($available_rooms, 'floor_number'));
                        sort($floors);
                        foreach ($floors as $floor):
                            $floor_rooms = array_filter($available_rooms, function($r) use ($floor) {
                                return $r['floor_number'] == $floor;
                            });
                            $floor_beds = array_sum(array_column($floor_rooms, 'available_beds'));
                        ?>
                        <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2"
                           onclick="selectFloor(<?php echo $floor; ?>); return false;">
                            Floor <?php echo $floor; ?>
                            <span class="badge bg-primary rounded-pill"><?php echo $floor_beds; ?> beds</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Student Search Modal -->
<div class="modal fade" id="studentSearchModal" tabindex="-1" size="lg">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-users me-2"></i>Select Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="studentSearch" 
                           placeholder="Search by name, matric number, or email...">
                </div>
                <div class="table-responsive" style="max-height: 400px;">
                    <table class="table table-hover" id="studentTable">
                        <thead>
                            <tr>
                                <th>Matric No</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Level</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="studentTableBody">
                            <!-- Populated via AJAX -->
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading students...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let students = [];

// Load students for modal
fetch('get_students_list.php')
    .then(response => response.json())
    .then(data => {
        students = data;
        renderStudentTable(data);
    });

function renderStudentTable(students) {
    const tbody = document.getElementById('studentTableBody');
    
    if (students.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">No students found</td></tr>';
        return;
    }
    
    tbody.innerHTML = students.map(student => `
        <tr>
            <td>${student.matric_number}</td>
            <td>${student.first_name} ${student.last_name}</td>
            <td>${student.department_name || 'N/A'}</td>
            <td>${student.current_level}</td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="selectStudent(${student.student_id}, '${student.matric_number} - ${student.first_name} ${student.last_name}')">
                    Select
                </button>
            </td>
        </tr>
    `).join('');
}

// Search functionality
document.getElementById('studentSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const filtered = students.filter(student => 
        student.matric_number.toLowerCase().includes(searchTerm) ||
        student.first_name.toLowerCase().includes(searchTerm) ||
        student.last_name.toLowerCase().includes(searchTerm) ||
        student.email.toLowerCase().includes(searchTerm)
    );
    renderStudentTable(filtered);
});

function selectStudent(id, displayText) {
    const select = document.getElementById('studentSelect');
    select.innerHTML = `<option value="${id}" selected>${displayText}</option>`;
    
    // Fetch student details
    fetch(`get_student_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('studentInfo').innerHTML = `
                <i class="fas fa-check-circle text-success me-1"></i>
                Selected: ${data.matric_number} - ${data.first_name} ${data.last_name} (Level ${data.current_level})
                ${data.has_allocation ? '<span class="text-danger ms-2">⚠️ Already has allocation</span>' : ''}
            `;
        });
    
    bootstrap.Modal.getInstance(document.getElementById('studentSearchModal')).hide();
}

// Room selection handler
document.getElementById('roomSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const bedSelect = document.getElementById('bedSelect');
    const roomDetails = document.getElementById('roomDetails');
    
    if (this.value) {
        const available = selected.dataset.available;
        const beds = selected.dataset.beds;
        const floor = selected.dataset.floor;
        
        // Generate bed options
        let bedOptions = '<option value="">Select bed number...</option>';
        for (let i = 1; i <= beds; i++) {
            // Check if bed is available (in real scenario, you'd check against database)
            bedOptions += `<option value="${i}">Bed ${i}</option>`;
        }
        bedSelect.innerHTML = bedOptions;
        
        // Show room details
        roomDetails.innerHTML = `
            <div class="alert alert-info py-2">
                <small>
                    <strong>Room ${selected.text.split(' - ')[0]}</strong><br>
                    Floor: ${floor} | Type: ${selected.text.includes('Standard') ? 'Standard' : 'VIP'}<br>
                    Available beds: ${available}/${beds}
                </small>
            </div>
        `;
        
        document.getElementById('feeSummary').style.display = 'block';
    } else {
        bedSelect.innerHTML = '<option value="">First select a room</option>';
        roomDetails.innerHTML = '';
        document.getElementById('feeSummary').style.display = 'none';
    }
});

// Floor selection
function selectFloor(floor) {
    const roomSelect = document.getElementById('roomSelect');
    
    // Find first room on this floor
    for (let option of roomSelect.options) {
        if (option.dataset.floor == floor) {
            roomSelect.value = option.value;
            roomSelect.dispatchEvent(new Event('change'));
            break;
        }
    }
    
    // Scroll to room select
    roomSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Form validation
document.getElementById('allocationForm').addEventListener('submit', function(e) {
    const studentId = document.getElementById('studentSelect').value;
    const roomId = document.getElementById('roomSelect').value;
    const bedNumber = document.getElementById('bedSelect').value;
    const startDate = this.querySelector('input[name="start_date"]').value;
    const endDate = this.querySelector('input[name="end_date"]').value;
    
    if (!studentId) {
        e.preventDefault();
        alert('Please select a student');
        return false;
    }
    
    if (!roomId) {
        e.preventDefault();
        alert('Please select a room');
        return false;
    }
    
    if (!bedNumber) {
        e.preventDefault();
        alert('Please select a bed number');
        return false;
    }
    
    if (new Date(endDate) <= new Date(startDate)) {
        e.preventDefault();
        alert('End date must be after start date');
        return false;
    }
    
    return confirm('Create this allocation? An invoice will be generated automatically.');
});

// Initialize if room pre-selected
<?php if ($selected_room): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('roomSelect').value = '<?php echo $selected_room['room_id']; ?>';
    document.getElementById('roomSelect').dispatchEvent(new Event('change'));
});
<?php endif; ?>
</script>

<?php
require_once 'includes/footer.php';
?>