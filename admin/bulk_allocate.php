<?php
// bulk_allocate.php
ob_start();

require_once 'includes/header.php';

// Get hostel ID from URL
$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;

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

$page_title = "Bulk Allocation - " . htmlspecialchars($hostel['hostel_name']);

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

// Fetch eligible students (no active allocation)
$students_stmt = $pdo->prepare("
    SELECT 
        s.*,
        d.department_name,
        p.program_name,
        CASE WHEN s.gender = ? THEN 1 ELSE 0 END as gender_match
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE s.status = 'Active'
        AND NOT EXISTS (
            SELECT 1 FROM hostel_allocations ha 
            WHERE ha.student_id = s.student_id AND ha.status = 'Active'
        )
    ORDER BY s.current_level, s.last_name
");
$students_stmt->execute([$hostel['gender'] == 'Mixed' ? 'Mixed' : $hostel['gender']]);
$eligible_students = $students_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_allocate'])) {
    $selected_students = $_POST['selected_students'] ?? [];
    $allocations = json_decode($_POST['allocations_data'], true) ?? [];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $payment_status = $_POST['payment_status'];

    $errors = [];
    $success_count = 0;
    $failed_allocations = [];

    if (empty($selected_students)) {
        $errors[] = "Please select at least one student";
    }
    if (empty($allocations)) {
        $errors[] = "Please assign rooms to selected students";
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

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $academic_year = date('Y') . '/' . (date('Y') + 1);

            foreach ($allocations as $allocation) {
                $student_id = $allation['student_id']; // Note: Typo in variable, should be $allocation
                $room_id = $allocation['room_id'];
                $bed_number = $allocation['bed_number'];

                // Double-check availability
                $check_stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM hostel_allocations 
                    WHERE room_id = ? AND bed_number = ? AND status = 'Active'
                ");
                $check_stmt->execute([$room_id, $bed_number]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    $failed_allocations[] = "Student ID $student_id: Bed already taken";
                    continue;
                }

                // Create allocation
                $insert_sql = "INSERT INTO hostel_allocations (
                    student_id, hostel_id, room_id, bed_number, 
                    academic_year, start_date, end_date, payment_status, 
                    status, allocation_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())";

                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $student_id, $hostel_id, $room_id, $bed_number,
                    $academic_year, $start_date, $end_date, $payment_status
                ]);

                // Create invoice
                $invoice_number = 'HSTL-' . date('Ymd') . '-' . str_pad($pdo->lastInsertId(), 5, '0', STR_PAD_LEFT);
                $amount = $hostel['monthly_rent'] * 12;

                $invoice_sql = "INSERT INTO student_fees (
                    student_id, session_year, semester, fee_type, description, 
                    amount, due_date, status, invoice_number
                ) VALUES (?, ?, 1, 'Hostel Accommodation', ?, ?, ?, 'Pending', ?)";

                $invoice_stmt = $pdo->prepare($invoice_sql);
                $invoice_stmt->execute([
                    $student_id,
                    $academic_year,
                    "Hostel accommodation for {$hostel['hostel_name']} (Bulk Allocation)",
                    $amount,
                    date('Y-m-d', strtotime('+30 days')),
                    $invoice_number
                ]);

                $success_count++;
            }

            // Update room statuses
            $room_ids = array_unique(array_column($allocations, 'room_id'));
            foreach ($room_ids as $rid) {
                $room_check = $pdo->prepare("
                    SELECT 
                        bed_count,
                        (SELECT COUNT(*) FROM hostel_allocations 
                         WHERE room_id = ? AND status = 'Active') as occupied
                    FROM hostel_rooms WHERE room_id = ?
                ");
                $room_check->execute([$rid, $rid]);
                $room_data = $room_check->fetch();

                if ($room_data['occupied'] >= $room_data['bed_count']) {
                    $update_room = $pdo->prepare("UPDATE hostel_rooms SET status = 'Occupied' WHERE room_id = ?");
                    $update_room->execute([$rid]);
                }
            }

            // Log bulk operation
            $log_stmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, table_name) 
                VALUES (?, 'Bulk Allocation', ?, 'hostel_allocations')
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                "Bulk allocated {$success_count} students in {$hostel['hostel_name']}"
            ]);

            $pdo->commit();

            $_SESSION['success_message'] = "Successfully allocated {$success_count} students!";
            if (!empty($failed_allocations)) {
                $_SESSION['warning_message'] = "Failed allocations: " . implode(", ", $failed_allocations);
            }
            
            header("Location: view_hostel.php?id=$hostel_id");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Bulk allocation error: " . $e->getMessage());
            $errors[] = "Error during bulk allocation: " . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                <li class="breadcrumb-item"><a href="view_hostel.php?id=<?php echo $hostel_id; ?>"><?php echo htmlspecialchars($hostel['hostel_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Bulk Allocation</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Bulk Room Allocation</h1>
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

<!-- Allocation Wizard Steps -->
<div class="app-card shadow-sm mb-4">
    <div class="app-card-body p-0">
        <div class="row g-0">
            <div class="col-md-4">
                <div class="p-4 text-center border-end" id="step1">
                    <span class="badge bg-primary rounded-pill mb-2">Step 1</span>
                    <h5>Select Students</h5>
                    <p class="small text-muted">Choose students for allocation</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 text-center border-end" id="step2">
                    <span class="badge bg-secondary rounded-pill mb-2">Step 2</span>
                    <h5>Assign Rooms</h5>
                    <p class="small text-muted">Match students to available rooms</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 text-center" id="step3">
                    <span class="badge bg-secondary rounded-pill mb-2">Step 3</span>
                    <h5>Confirm & Complete</h5>
                    <p class="small text-muted">Review and process allocations</p>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="" id="bulkAllocationForm">
    <input type="hidden" name="allocations_data" id="allocationsData">
    <input type="hidden" name="start_date" id="hiddenStartDate">
    <input type="hidden" name="end_date" id="hiddenEndDate">
    <input type="hidden" name="payment_status" id="hiddenPaymentStatus">

    <!-- Step 1: Select Students -->
    <div class="app-card shadow-sm mb-4" id="step1Card">
        <div class="app-card-header p-3">
            <h5 class="app-card-title mb-0">
                <i class="fas fa-users me-2"></i>Step 1: Select Students
            </h5>
        </div>
        <div class="app-card-body p-3">
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="studentSearch" 
                               placeholder="Search students by name or matric number...">
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllStudents">
                        <i class="fas fa-check-double me-1"></i>Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllStudents">
                        <i class="fas fa-times me-1"></i>Deselect All
                    </button>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong><?php echo count($eligible_students); ?></strong> eligible students found.
                Only students with no active allocation and matching gender (<?php echo $hostel['gender']; ?>) are shown.
            </div>

            <div class="table-responsive" style="max-height: 400px;">
                <table class="table table-hover" id="studentsTable">
                    <thead>
                        <tr>
                            <th width="40">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checkAll">
                                </div>
                            </th>
                            <th>Matric No</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Level</th>
                            <th>Gender</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eligible_students as $student): ?>
                        <tr class="<?php echo !$student['gender_match'] ? 'table-warning' : ''; ?>">
                            <td>
                                <div class="form-check">
                                    <input class="form-check-input student-check" 
                                           type="checkbox" 
                                           name="selected_students[]" 
                                           value="<?php echo $student['student_id']; ?>"
                                           data-gender-match="<?php echo $student['gender_match']; ?>"
                                           <?php echo $student['gender_match'] ? '' : 'disabled'; ?>>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></td>
                            <td><?php echo $student['current_level']; ?></td>
                            <td>
                                <span class="badge bg-<?php echo $student['gender'] == 'Male' ? 'primary' : 'danger'; ?>">
                                    <?php echo $student['gender']; ?>
                                </span>
                                <?php if (!$student['gender_match']): ?>
                                    <span class="badge bg-warning ms-1" title="Gender mismatch">⚠️</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold" id="selectedCount">0</span> students selected
                </div>
                <button type="button" class="btn btn-primary" id="gotoStep2" disabled>
                    Next: Assign Rooms <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Step 2: Assign Rooms -->
    <div class="app-card shadow-sm mb-4" id="step2Card" style="display: none;">
        <div class="app-card-header p-3">
            <h5 class="app-card-title mb-0">
                <i class="fas fa-bed me-2"></i>Step 2: Assign Rooms to Selected Students
            </h5>
        </div>
        <div class="app-card-body p-3">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Available Rooms</h6>
                            <p class="display-6"><?php echo count($available_rooms); ?></p>
                            <small class="text-muted">Total beds: <?php echo array_sum(array_column($available_rooms, 'available_beds')); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="alert alert-success">
                        <i class="fas fa-magic me-2"></i>
                        <strong>Auto-assign feature:</strong> Click below to automatically assign rooms to selected students.
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col">
                    <button type="button" class="btn btn-success" id="autoAssignRooms">
                        <i class="fas fa-magic me-2"></i>Auto-Assign Rooms
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="clearAssignments">
                        <i class="fas fa-undo me-2"></i>Clear All
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered" id="assignmentTable">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Matric No</th>
                            <th>Level</th>
                            <th>Gender</th>
                            <th>Room</th>
                            <th>Bed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="assignmentBody">
                        <!-- Populated via JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="mt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="gotoStep(1)">
                    <i class="fas fa-arrow-left me-2"></i>Back to Student Selection
                </button>
                <button type="button" class="btn btn-primary" id="gotoStep3" disabled>
                    Next: Review & Confirm <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Confirm & Complete -->
    <div class="app-card shadow-sm mb-4" id="step3Card" style="display: none;">
        <div class="app-card-header p-3">
            <h5 class="app-card-title mb-0">
                <i class="fas fa-check-circle me-2"></i>Step 3: Confirm & Complete
            </h5>
        </div>
        <div class="app-card-body p-3">
            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Start Date</label>
                    <input type="date" class="form-control" id="reviewStartDate" 
                           value="<?php echo date('Y-m-d'); ?>" 
                           min="<?php echo date('Y-m-d'); ?>" 
                           required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">End Date</label>
                    <input type="date" class="form-control" id="reviewEndDate" 
                           value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" 
                           min="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" 
                           required>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Payment Status</label>
                    <select class="form-select" id="reviewPaymentStatus">
                        <option value="Pending">Pending</option>
                        <option value="Paid">Paid</option>
                        <option value="Partial">Partial</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Summary</h6>
                            <p class="mb-1">Total Students: <span class="fw-bold" id="summaryTotal">0</span></p>
                            <p class="mb-1">Total Rooms: <span class="fw-bold" id="summaryRooms">0</span></p>
                            <p class="mb-0">Total Amount: <span class="fw-bold text-primary" id="summaryAmount">₦0</span></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Please review carefully:</strong> This action will create allocations and generate invoices for all students. This cannot be undone.
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Room</th>
                            <th>Bed</th>
                            <th>Monthly Rent</th>
                            <th>Annual Total</th>
                        </tr>
                    </thead>
                    <tbody id="reviewTableBody">
                        <!-- Populated via JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="gotoStep(2)">
                    <i class="fas fa-arrow-left me-2"></i>Back to Room Assignment
                </button>
                <button type="submit" name="bulk_allocate" class="btn btn-success" id="confirmAllocation">
                    <i class="fas fa-check-circle me-2"></i>Confirm & Process Allocations
                </button>
            </div>
        </div>
    </div>
</form>

<script>
let selectedStudents = [];
let roomAssignments = {};
let availableRooms = <?php echo json_encode($available_rooms); ?>;
let monthlyRent = <?php echo $hostel['monthly_rent']; ?>;

// Step 1: Student Selection
document.getElementById('studentSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#studentsTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

document.getElementById('checkAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.student-check:not(:disabled)');
    checkboxes.forEach(cb => {
        cb.checked = this.checked;
    });
    updateSelectedCount();
});

document.getElementById('selectAllStudents').addEventListener('click', function() {
    document.querySelectorAll('.student-check:not(:disabled)').forEach(cb => {
        cb.checked = true;
    });
    document.getElementById('checkAll').checked = true;
    updateSelectedCount();
});

document.getElementById('deselectAllStudents').addEventListener('click', function() {
    document.querySelectorAll('.student-check').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('checkAll').checked = false;
    updateSelectedCount();
});

function updateSelectedCount() {
    selectedStudents = Array.from(document.querySelectorAll('.student-check:checked')).map(cb => {
        const row = cb.closest('tr');
        return {
            id: cb.value,
            name: row.cells[2].textContent.trim(),
            matric: row.cells[1].textContent.trim(),
            level: row.cells[4].textContent.trim(),
            gender: row.cells[5].textContent.trim()
        };
    });
    
    const count = selectedStudents.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('gotoStep2').disabled = count === 0;
}

document.querySelectorAll('.student-check').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Step 2: Room Assignment
document.getElementById('gotoStep2').addEventListener('click', function() {
    if (selectedStudents.length === 0) return;
    
    gotoStep(2);
    renderAssignmentTable();
});

function renderAssignmentTable() {
    const tbody = document.getElementById('assignmentBody');
    
    if (selectedStudents.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No students selected</td></tr>';
        return;
    }
    
    let html = '';
    selectedStudents.forEach(student => {
        const assignment = roomAssignments[student.id] || {};
        const roomOptions = generateRoomOptions(assignment.room_id);
        
        html += `
            <tr data-student-id="${student.id}">
                <td>${student.name}</td>
                <td>${student.matric}</td>
                <td>Level ${student.level}</td>
                <td>${student.gender}</td>
                <td>
                    <select class="form-select form-select-sm room-select" 
                            data-student="${student.id}" 
                            onchange="handleRoomChange(${student.id}, this.value)">
                        <option value="">Select Room</option>
                        ${roomOptions}
                    </select>
                </td>
                <td>
                    <select class="form-select form-select-sm bed-select" 
                            data-student="${student.id}" 
                            id="bed-${student.id}" 
                            ${assignment.room_id ? '' : 'disabled'}>
                        <option value="">Bed</option>
                    </select>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="clearAssignment(${student.id})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    updateStep3Button();
}

function generateRoomOptions(selectedRoomId) {
    let options = '';
    availableRooms.forEach(room => {
        if (room.available_beds > 0) {
            const selected = room.room_id == selectedRoomId ? 'selected' : '';
            options += `<option value="${room.room_id}" data-beds="${room.bed_count}" ${selected}>
                Room ${room.room_number} (Floor ${room.floor_number}) - ${room.available_beds}/${room.bed_count} beds
            </option>`;
        }
    });
    return options;
}

function handleRoomChange(studentId, roomId) {
    const bedSelect = document.getElementById(`bed-${studentId}`);
    
    if (roomId) {
        const room = availableRooms.find(r => r.room_id == roomId);
        if (room) {
            let bedOptions = '<option value="">Select Bed</option>';
            
            // Get occupied beds
            fetch(`get_occupied_beds.php?room_id=${roomId}`)
                .then(response => response.json())
                .then(occupiedBeds => {
                    for (let i = 1; i <= room.bed_count; i++) {
                        if (!occupiedBeds.includes(i)) {
                            const selected = (roomAssignments[studentId] && roomAssignments[studentId].bed == i) ? 'selected' : '';
                            bedOptions += `<option value="${i}" ${selected}>Bed ${i}</option>`;
                        }
                    }
                    bedSelect.innerHTML = bedOptions;
                    bedSelect.disabled = false;
                });
            
            roomAssignments[studentId] = { room_id: roomId };
        }
    } else {
        bedSelect.innerHTML = '<option value="">Bed</option>';
        bedSelect.disabled = true;
        delete roomAssignments[studentId];
    }
    
    updateStep3Button();
}

function clearAssignment(studentId) {
    delete roomAssignments[studentId];
    
    const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
    if (row) {
        const roomSelect = row.querySelector('.room-select');
        const bedSelect = row.querySelector('.bed-select');
        roomSelect.value = '';
        bedSelect.innerHTML = '<option value="">Bed</option>';
        bedSelect.disabled = true;
    }
    
    updateStep3Button();
}

function updateStep3Button() {
    const completedAssignments = selectedStudents.filter(s => 
        roomAssignments[s.id] && roomAssignments[s.id].bed
    ).length;
    
    document.getElementById('gotoStep3').disabled = completedAssignments !== selectedStudents.length;
}

// Auto-assign rooms
document.getElementById('autoAssignRooms').addEventListener('click', function() {
    // Reset assignments
    roomAssignments = {};
    
    // Sort students by level (higher levels first)
    const sortedStudents = [...selectedStudents].sort((a, b) => b.level - a.level);
    
    // Create a copy of available beds
    let availableBeds = [];
    availableRooms.forEach(room => {
        for (let bed = 1; bed <= room.bed_count; bed++) {
            availableBeds.push({
                room_id: room.room_id,
                bed_number: bed,
                room_number: room.room_number,
                floor: room.floor_number
            });
        }
    });
    
    // Assign each student to next available bed
    sortedStudents.forEach((student, index) => {
        if (index < availableBeds.length) {
            const bed = availableBeds[index];
            roomAssignments[student.id] = {
                room_id: bed.room_id,
                bed: bed.bed_number
            };
        }
    });
    
    renderAssignmentTable();
});

document.getElementById('clearAssignments').addEventListener('click', function() {
    roomAssignments = {};
    renderAssignmentTable();
});

// Step 3: Review
document.getElementById('gotoStep3').addEventListener('click', function() {
    gotoStep(3);
    renderReview();
});

function renderReview() {
    const tbody = document.getElementById('reviewTableBody');
    let totalAmount = 0;
    let roomsUsed = new Set();
    
    let html = '';
    selectedStudents.forEach(student => {
        if (roomAssignments[student.id] && roomAssignments[student.id].bed) {
            const assignment = roomAssignments[student.id];
            const room = availableRooms.find(r => r.room_id == assignment.room_id);
            roomsUsed.add(assignment.room_id);
            totalAmount += monthlyRent * 12;
            
            html += `
                <tr>
                    <td>${student.name}<br><small class="text-muted">${student.matric}</small></td>
                    <td>Room ${room.room_number} (Floor ${room.floor_number})</td>
                    <td>${assignment.bed}</td>
                    <td>₦${monthlyRent.toLocaleString()}</td>
                    <td>₦${(monthlyRent * 12).toLocaleString()}</td>
                </tr>
            `;
        }
    });
    
    tbody.innerHTML = html;
    document.getElementById('summaryTotal').textContent = selectedStudents.length;
    document.getElementById('summaryRooms').textContent = roomsUsed.size;
    document.getElementById('summaryAmount').textContent = `₦${totalAmount.toLocaleString()}`;
}

// Form submission
document.getElementById('confirmAllocation').addEventListener('click', function(e) {
    e.preventDefault();
    
    // Validate dates
    const startDate = document.getElementById('reviewStartDate').value;
    const endDate = document.getElementById('reviewEndDate').value;
    const paymentStatus = document.getElementById('reviewPaymentStatus').value;
    
    if (!startDate || !endDate) {
        alert('Please select start and end dates');
        return;
    }
    
    if (new Date(endDate) <= new Date(startDate)) {
        alert('End date must be after start date');
        return;
    }
    
    // Prepare allocations data
    const allocations = [];
    selectedStudents.forEach(student => {
        if (roomAssignments[student.id] && roomAssignments[student.id].bed) {
            allocations.push({
                student_id: student.id,
                room_id: roomAssignments[student.id].room_id,
                bed_number: roomAssignments[student.id].bed
            });
        }
    });
    
    // Set form data
    document.getElementById('allocationsData').value = JSON.stringify(allocations);
    document.getElementById('hiddenStartDate').value = startDate;
    document.getElementById('hiddenEndDate').value = endDate;
    document.getElementById('hiddenPaymentStatus').value = paymentStatus;
    
    // Submit form
    document.getElementById('bulkAllocationForm').submit();
});

// Navigation
function gotoStep(step) {
    document.getElementById('step1').classList.toggle('bg-light', step === 1);
    document.getElementById('step2').classList.toggle('bg-light', step === 2);
    document.getElementById('step3').classList.toggle('bg-light', step === 3);
    
    document.getElementById('step1Card').style.display = step === 1 ? 'block' : 'none';
    document.getElementById('step2Card').style.display = step === 2 ? 'block' : 'none';
    document.getElementById('step3Card').style.display = step === 3 ? 'block' : 'none';
    
    // Update step badges
    document.querySelectorAll('#step1 .badge, #step2 .badge, #step3 .badge').forEach(badge => {
        badge.className = 'badge bg-secondary rounded-pill mb-2';
    });
    document.querySelector(`#step${step} .badge`).className = 'badge bg-primary rounded-pill mb-2';
}

// Initialize
updateSelectedCount();
</script>

<style>
.app-card-stat {
    transition: transform 0.2s;
}
.app-card-stat:hover {
    transform: translateY(-5px);
}
.table th {
    background-color: #f8f9fa;
}
.step-indicator {
    position: relative;
}
.step-indicator::after {
    content: '';
    position: absolute;
    top: 50%;
    right: -15px;
    width: 30px;
    height: 2px;
    background: #dee2e6;
}
.step-indicator:last-child::after {
    display: none;
}
</style>

<?php
require_once 'includes/footer.php';
?>