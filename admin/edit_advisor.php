<?php
// edit_advisor.php
ob_start();

require_once 'includes/header.php';

// Get advisor ID from URL
$advisor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($advisor_id <= 0) {
    $_SESSION['error_message'] = "Invalid advisor ID";
    header("Location: academic_advisors.php");
    exit();
}

// Fetch advisor details
try {
    $stmt = $pdo->prepare("
        SELECT aa.*, d.department_name 
        FROM academic_advisors aa
        LEFT JOIN departments d ON aa.department_id = d.department_id
        WHERE aa.advisor_id = ?
    ");
    $stmt->execute([$advisor_id]);
    $advisor = $stmt->fetch();

    if (!$advisor) {
        $_SESSION['error_message'] = "Advisor not found";
        header("Location: academic_advisors.php");
        exit();
    }

    // Fetch departments for dropdown
    $depts_stmt = $pdo->query("
        SELECT department_id, department_name, department_code 
        FROM departments 
        ORDER BY department_name
    ");
    $departments = $depts_stmt->fetchAll();

} catch (Exception $e) {
    error_log("Error fetching advisor: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading advisor details";
    header("Location: academic_advisors.php");
    exit();
}

$page_title = "Edit Advisor - " . htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_advisor'])) {
    $staff_id = trim($_POST['staff_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $department_id = (int)$_POST['department_id'];
    $max_students = (int)$_POST['max_students'];
    $status = $_POST['status'];

    $errors = [];

    // Validation
    if (empty($staff_id)) {
        $errors[] = "Staff ID is required";
    }
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    if ($department_id <= 0) {
        $errors[] = "Please select a department";
    }
    if ($max_students < 1 || $max_students > 100) {
        $errors[] = "Max students must be between 1 and 100";
    }

    // Check if staff ID exists (excluding current advisor)
    if (!empty($staff_id)) {
        $check_stmt = $pdo->prepare("
            SELECT advisor_id FROM academic_advisors 
            WHERE staff_id = ? AND advisor_id != ?
        ");
        $check_stmt->execute([$staff_id, $advisor_id]);
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "Staff ID already exists";
        }
    }

    // Check if email exists (excluding current advisor)
    if (!empty($email)) {
        $check_stmt = $pdo->prepare("
            SELECT advisor_id FROM academic_advisors 
            WHERE email = ? AND advisor_id != ?
        ");
        $check_stmt->execute([$email, $advisor_id]);
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "Email already exists";
        }
    }

    // Check if reducing max students below current count
    if ($max_students < $advisor['current_students']) {
        $errors[] = "Cannot reduce max students below current count ({$advisor['current_students']})";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $update_sql = "UPDATE academic_advisors SET 
                staff_id = ?,
                first_name = ?,
                last_name = ?,
                email = ?,
                phone = ?,
                department_id = ?,
                max_students = ?,
                status = ?
                WHERE advisor_id = ?";

            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $staff_id, $first_name, $last_name, $email, $phone,
                $department_id, $max_students, $status, $advisor_id
            ]);

            // Log the update
            $log_stmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) 
                VALUES (?, 'Update', ?, 'academic_advisors', ?)
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                "Updated advisor: $first_name $last_name (Staff ID: $staff_id)",
                $advisor_id
            ]);

            $pdo->commit();

            $_SESSION['success_message'] = "Advisor updated successfully!";
            header("Location: view_advisor.php?id=$advisor_id");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error updating advisor: " . $e->getMessage());
            $errors[] = "Error updating advisor: " . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="academic_advisors.php">Academic Advisors</a></li>
                <li class="breadcrumb-item"><a href="view_advisor.php?id=<?php echo $advisor_id; ?>"><?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Edit Advisor</h1>
    </div>
    <div class="app-actions">
        <a href="view_advisor.php?id=<?php echo $advisor_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Profile
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
                    <i class="fas fa-edit me-2"></i>Advisor Information
                </h5>
            </div>
            <div class="app-card-body p-4">
                <form method="POST" action="" id="editAdvisorForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Staff ID <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="staff_id" 
                                   value="<?php echo htmlspecialchars($advisor['staff_id']); ?>" 
                                   required
                                   pattern="[A-Za-z0-9\-]+"
                                   title="Only letters, numbers, and hyphens allowed">
                            <div class="form-text">Unique staff identifier</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" 
                                   class="form-control" 
                                   name="email" 
                                   value="<?php echo htmlspecialchars($advisor['email']); ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="first_name" 
                                   value="<?php echo htmlspecialchars($advisor['first_name']); ?>" 
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" 
                                   class="form-control" 
                                   name="last_name" 
                                   value="<?php echo htmlspecialchars($advisor['last_name']); ?>" 
                                   required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" 
                                   class="form-control" 
                                   name="phone" 
                                   value="<?php echo htmlspecialchars($advisor['phone'] ?? ''); ?>"
                                   placeholder="e.g., 08012345678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>" 
                                        <?php echo ($advisor['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department_name'] . ' (' . $dept['department_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Max Students <span class="text-danger">*</span></label>
                            <input type="number" 
                                   class="form-control" 
                                   name="max_students" 
                                   value="<?php echo $advisor['max_students']; ?>" 
                                   min="1" 
                                   max="100" 
                                   required>
                            <div class="form-text">Maximum number of students this advisor can handle</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Active" <?php echo $advisor['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $advisor['status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Current Statistics:</h6>
                        <ul class="mb-0">
                            <li><strong><?php echo $advisor['current_students']; ?></strong> students currently assigned</li>
                            <li><strong><?php echo $advisor['max_students'] - $advisor['current_students']; ?></strong> available slots</li>
                            <li>Department: <?php echo htmlspecialchars($advisor['department_name'] ?? 'N/A'); ?></li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="view_advisor.php?id=<?php echo $advisor_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" name="update_advisor" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Advisor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Quick Actions Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="view_advisor.php?id=<?php echo $advisor_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-eye me-2"></i>View Profile
                    </a>
                    <a href="advisor_students.php?id=<?php echo $advisor_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-users me-2"></i>Manage Students
                    </a>
                    <?php if ($advisor['current_students'] < $advisor['max_students']): ?>
                    <a href="view_advisor.php?id=<?php echo $advisor_id; ?>#assign" class="btn btn-success">
                        <i class="fas fa-user-plus me-2"></i>Assign New Student
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity Preview -->
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-history me-2"></i>Recent Activity
                </h5>
            </div>
            <div class="app-card-body p-0">
                <?php
                $activity_stmt = $pdo->prepare("
                    SELECT 
                        'assignment' as type,
                        sa.assigned_date as date,
                        CONCAT('Assigned ', s.first_name, ' ', s.last_name) as description
                    FROM student_advisors sa
                    JOIN students s ON sa.student_id = s.student_id
                    WHERE sa.advisor_id = ?
                    ORDER BY sa.assigned_date DESC
                    LIMIT 5
                ");
                $activity_stmt->execute([$advisor_id]);
                $recent_activity = $activity_stmt->fetchAll();
                ?>
                
                <div class="list-group list-group-flush">
                    <?php if (!empty($recent_activity)): ?>
                        <?php foreach ($recent_activity as $activity): ?>
                        <div class="list-group-item">
                            <small class="text-muted d-block">
                                <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                            </small>
                            <span><?php echo htmlspecialchars($activity['description']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="list-group-item text-center text-muted py-3">
                            No recent activity
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Form Validation Script -->
<script>
document.getElementById('editAdvisorForm')?.addEventListener('submit', function(e) {
    const staffId = this.querySelector('input[name="staff_id"]').value;
    const maxStudents = parseInt(this.querySelector('input[name="max_students"]').value);
    const currentStudents = <?php echo $advisor['current_students']; ?>;
    
    // Validate staff ID format
    if (!/^[A-Za-z0-9\-]+$/.test(staffId)) {
        e.preventDefault();
        alert('Staff ID can only contain letters, numbers, and hyphens');
        return false;
    }
    
    // Validate max students
    if (maxStudents < currentStudents) {
        e.preventDefault();
        alert(`Cannot reduce max students below current count (${currentStudents})`);
        return false;
    }
    
    if (maxStudents < 1 || maxStudents > 100) {
        e.preventDefault();
        alert('Max students must be between 1 and 100');
        return false;
    }
    
    // Confirm if reducing capacity
    if (maxStudents < <?php echo $advisor['max_students']; ?>) {
        return confirm('Are you sure you want to reduce the maximum student capacity?');
    }
    
    return true;
});

// Phone number formatting
document.querySelector('input[name="phone"]')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9+]/g, '');
});
</script>

<?php
require_once 'includes/footer.php';
?>