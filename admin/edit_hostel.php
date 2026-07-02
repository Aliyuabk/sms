<?php
// edit_hostel.php
ob_start();

require_once 'includes/header.php';

// Get hostel ID from URL
$hostel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
} catch (Exception $e) {
    error_log("Error fetching hostel: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading hostel details";
    header("Location: hostels.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hostel'])) {
    $hostel_name = trim($_POST['hostel_name']);
    $hostel_code = trim($_POST['hostel_code']);
    $gender = $_POST['gender'];
    $capacity_per_room = (int)$_POST['capacity_per_room'];
    $warden_name = trim($_POST['warden_name']);
    $warden_phone = trim($_POST['warden_phone']);
    $warden_email = trim($_POST['warden_email']);
    $monthly_rent = (float)$_POST['monthly_rent'];
    $amenities = trim($_POST['amenities']);
    $rules = trim($_POST['rules']);
    $status = $_POST['status'];

    $errors = [];

    // Validation
    if (empty($hostel_name)) {
        $errors[] = "Hostel name is required";
    }
    if (empty($hostel_code)) {
        $errors[] = "Hostel code is required";
    }
    if (!in_array($gender, ['Male', 'Female', 'Mixed'])) {
        $errors[] = "Invalid gender selection";
    }
    if ($capacity_per_room < 1 || $capacity_per_room > 10) {
        $errors[] = "Capacity per room must be between 1 and 10";
    }
    if ($monthly_rent < 0) {
        $errors[] = "Monthly rent cannot be negative";
    }
    if ($warden_email && !filter_var($warden_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Check if hostel code exists (excluding current hostel)
    if (!empty($hostel_code)) {
        $check_stmt = $pdo->prepare("SELECT hostel_id FROM hostels WHERE hostel_code = ? AND hostel_id != ?");
        $check_stmt->execute([$hostel_code, $hostel_id]);
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "Hostel code already exists";
        }
    }

    if (empty($errors)) {
        try {
            $update_sql = "UPDATE hostels SET 
                hostel_name = ?,
                hostel_code = ?,
                gender = ?,
                capacity_per_room = ?,
                warden_name = ?,
                warden_phone = ?,
                warden_email = ?,
                monthly_rent = ?,
                amenities = ?,
                rules = ?,
                status = ?
                WHERE hostel_id = ?";

            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $hostel_name, $hostel_code, $gender, $capacity_per_room,
                $warden_name, $warden_phone, $warden_email, $monthly_rent,
                $amenities, $rules, $status, $hostel_id
            ]);

            // Log the update
            $log_stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) VALUES (?, 'Update', ?, 'hostels', ?)");
            $log_stmt->execute([$_SESSION['admin_id'], "Updated hostel: $hostel_name", $hostel_id]);

            $_SESSION['success_message'] = "Hostel updated successfully!";
            header("Location: view_hostel.php?id=$hostel_id");
            exit();
        } catch (Exception $e) {
            error_log("Error updating hostel: " . $e->getMessage());
            $errors[] = "Error updating hostel: " . $e->getMessage();
        }
    }
}

$page_title = "Edit Hostel - " . htmlspecialchars($hostel['hostel_name']);

// Status options
$status_options = ['Available', 'Full', 'Under Maintenance', 'Closed'];
$gender_options = ['Male', 'Female', 'Mixed'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                <li class="breadcrumb-item"><a href="view_hostel.php?id=<?php echo $hostel_id; ?>"><?php echo htmlspecialchars($hostel['hostel_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Edit Hostel</h1>
    </div>
    <div class="app-actions">
        <a href="view_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Hostel
        </a>
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
                    <i class="fas fa-edit me-2"></i>Hostel Information
                </h5>
            </div>
            <div class="app-card-body p-4">
                <form method="POST" action="" id="editHostelForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Hostel Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="hostel_name" 
                                   value="<?php echo htmlspecialchars($hostel['hostel_name']); ?>" 
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hostel Code <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="hostel_code" 
                                   value="<?php echo htmlspecialchars($hostel['hostel_code']); ?>" 
                                   required 
                                   maxlength="10"
                                   pattern="[A-Za-z0-9\-]+"
                                   title="Only letters, numbers, and hyphens allowed">
                            <div class="form-text">Unique code for the hostel</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select class="form-select" name="gender" required>
                                <?php foreach ($gender_options as $opt): ?>
                                    <option value="<?php echo $opt; ?>" 
                                        <?php echo ($hostel['gender'] == $opt) ? 'selected' : ''; ?>>
                                        <?php echo $opt; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Capacity per Room <span class="text-danger">*</span></label>
                            <select class="form-select" name="capacity_per_room" required>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>" 
                                        <?php echo ($hostel['capacity_per_room'] == $i) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?> <?php echo $i == 1 ? 'bed' : 'beds'; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <div class="form-text">Number of beds per room</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Monthly Rent (₦) <span class="text-danger">*</span></label>
                            <input type="number" 
                                   class="form-control" 
                                   name="monthly_rent" 
                                   value="<?php echo htmlspecialchars($hostel['monthly_rent']); ?>" 
                                   required 
                                   min="0" 
                                   step="1000">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($status_options as $opt): ?>
                                    <option value="<?php echo $opt; ?>" 
                                        <?php echo ($hostel['status'] == $opt) ? 'selected' : ''; ?>>
                                        <?php echo $opt; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Warden Information</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" 
                                       class="form-control" 
                                       name="warden_name" 
                                       value="<?php echo htmlspecialchars($hostel['warden_name'] ?? ''); ?>"
                                       placeholder="Warden Name">
                            </div>
                            <div class="col-md-4">
                                <input type="tel" 
                                       class="form-control" 
                                       name="warden_phone" 
                                       value="<?php echo htmlspecialchars($hostel['warden_phone'] ?? ''); ?>"
                                       placeholder="Phone Number">
                            </div>
                            <div class="col-md-4">
                                <input type="email" 
                                       class="form-control" 
                                       name="warden_email" 
                                       value="<?php echo htmlspecialchars($hostel['warden_email'] ?? ''); ?>"
                                       placeholder="Email Address">
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amenities</label>
                            <textarea class="form-control" 
                                      name="amenities" 
                                      rows="4" 
                                      placeholder="List amenities separated by commas (e.g., WiFi, Laundry, Gym, Study Room)"><?php echo htmlspecialchars($hostel['amenities'] ?? ''); ?></textarea>
                            <div class="form-text">Separate multiple amenities with commas</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hostel Rules</label>
                            <textarea class="form-control" 
                                      name="rules" 
                                      rows="4" 
                                      placeholder="Enter hostel rules and regulations"><?php echo htmlspecialchars($hostel['rules'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Note:</h6>
                        <ul class="mb-0 small">
                            <li>Changing capacity per room will affect new rooms only. Existing rooms will retain their original capacity.</li>
                            <li>To add or remove rooms, please use the <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>">Room Management</a> page.</li>
                            <li>Status changes will affect availability for new allocations.</li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="view_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" name="update_hostel" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Hostel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Quick Stats Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Current Statistics
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php
                // Fetch current stats
                $stats_stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_rooms,
                        SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) as occupied_rooms,
                        SUM(bed_count) as total_beds,
                        (SELECT COUNT(*) FROM hostel_allocations WHERE hostel_id = ? AND status = 'Active') as occupied_beds
                    FROM hostel_rooms
                    WHERE hostel_id = ?
                ");
                $stats_stmt->execute([$hostel_id, $hostel_id]);
                $stats = $stats_stmt->fetch();
                ?>
                
                <table class="table table-borderless">
                    <tr>
                        <td><i class="fas fa-door-closed text-primary me-2"></i>Total Rooms:</td>
                        <td class="text-end fw-bold"><?php echo $stats['total_rooms'] ?? 0; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-bed text-success me-2"></i>Total Beds:</td>
                        <td class="text-end fw-bold"><?php echo $stats['total_beds'] ?? 0; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-user-check text-info me-2"></i>Occupied Beds:</td>
                        <td class="text-end fw-bold"><?php echo $stats['occupied_beds'] ?? 0; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-door-open text-warning me-2"></i>Available Beds:</td>
                        <td class="text-end fw-bold"><?php echo ($stats['total_beds'] ?? 0) - ($stats['occupied_beds'] ?? 0); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="app-card shadow-sm border-danger">
            <div class="app-card-header p-3 bg-danger text-white">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
                </h5>
            </div>
            <div class="app-card-body p-3">
                <p class="text-muted small">These actions are irreversible. Please proceed with caution.</p>
                
                <button class="btn btn-outline-danger w-100 mb-2" 
                        onclick="confirmResetRooms()"
                        <?php echo ($stats['occupied_beds'] > 0) ? 'disabled' : ''; ?>>
                    <i class="fas fa-sync-alt me-2"></i>Reset Room Status
                </button>
                
                <button class="btn btn-outline-danger w-100" 
                        onclick="confirmDeleteHostel()"
                        <?php echo ($stats['occupied_beds'] > 0) ? 'disabled' : ''; ?>>
                    <i class="fas fa-trash me-2"></i>Delete Hostel
                </button>
                
                <?php if ($stats['occupied_beds'] > 0): ?>
                <div class="alert alert-warning mt-2 mb-0 small">
                    <i class="fas fa-info-circle me-1"></i>
                    Cannot delete or reset while beds are occupied.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Reset Rooms Confirmation Modal -->
<div class="modal fade" id="resetRoomsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white">
                    <i class="fas fa-exclamation-triangle me-2"></i>Reset Room Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Are you sure you want to reset all rooms?</strong></p>
                <p>This action will:</p>
                <ul>
                    <li>Set all rooms to "Available" status</li>
                    <li>Clear all maintenance flags</li>
                    <li>Cannot be undone</li>
                </ul>
                <p class="text-danger">This will not affect current allocations.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="reset_hostel_rooms.php?id=<?php echo $hostel_id; ?>" class="btn btn-warning">
                    <i class="fas fa-sync-alt me-2"></i>Yes, Reset Rooms
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Delete Hostel Confirmation Modal -->
<div class="modal fade" id="deleteHostelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Hostel
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Are you absolutely sure you want to delete this hostel?</strong></p>
                <p>This action will:</p>
                <ul class="text-danger">
                    <li>Permanently delete the hostel record</li>
                    <li>Delete all room configurations</li>
                    <li>Remove all maintenance history</li>
                    <li>Cannot be recovered</li>
                </ul>
                <p>Type <strong class="text-danger">DELETE</strong> to confirm:</p>
                <input type="text" id="confirmDelete" class="form-control" placeholder="DELETE">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                    <i class="fas fa-trash me-2"></i>Delete Permanently
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmResetRooms() {
    new bootstrap.Modal(document.getElementById('resetRoomsModal')).show();
}

function confirmDeleteHostel() {
    new bootstrap.Modal(document.getElementById('deleteHostelModal')).show();
}

// Enable delete button only when correct text is entered
document.getElementById('confirmDelete').addEventListener('input', function() {
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    deleteBtn.disabled = this.value !== 'DELETE';
});

// Handle delete confirmation
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (document.getElementById('confirmDelete').value === 'DELETE') {
        window.location.href = 'delete_hostel.php?id=<?php echo $hostel_id; ?>&confirm=1';
    }
});

// Form validation
document.getElementById('editHostelForm').addEventListener('submit', function(e) {
    const monthlyRent = this.querySelector('input[name="monthly_rent"]').value;
    const hostelCode = this.querySelector('input[name="hostel_code"]').value;
    
    if (monthlyRent < 0) {
        e.preventDefault();
        alert('Monthly rent cannot be negative');
        return false;
    }
    
    if (!/^[A-Za-z0-9\-]+$/.test(hostelCode)) {
        e.preventDefault();
        alert('Hostel code can only contain letters, numbers, and hyphens');
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>