<?php
// edit_department.php
ob_start();
require_once 'includes/header.php';

// Set page title
$page_title = "Edit Department";

// Get department ID from URL
$department_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($department_id <= 0) {
    header("Location: departments.php");
    exit();
}

// Get department details
$department_stmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = ?");
$department_stmt->execute([$department_id]);
$department = $department_stmt->fetch();

if (!$department) {
    $_SESSION['error_message'] = "Department not found!";
    header("Location: departments.php");
    exit();
}

// Get all faculties
$faculties = $pdo->query("SELECT DISTINCT faculty FROM departments WHERE faculty IS NOT NULL AND faculty != '' ORDER BY faculty")->fetchAll();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $department_code = $_POST['department_code'] ?? '';
    $department_name = $_POST['department_name'] ?? '';
    $faculty = $_POST['faculty'] ?? '';
    $hod_name = $_POST['hod_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    
    // Validate
    if (empty($department_code) || empty($department_name)) {
        $_SESSION['error_message'] = "Department code and name are required!";
    } else {
        try {
            // Check if department code or name already exists (excluding current department)
            $check_stmt = $pdo->prepare("SELECT department_id FROM departments WHERE (department_code = ? OR department_name = ?) AND department_id != ?");
            $check_stmt->execute([$department_code, $department_name, $department_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Another department with this code or name already exists!";
            } else {
                $update_sql = "UPDATE departments SET 
                    department_code = ?, department_name = ?, faculty = ?, hod_name = ?, email = ?, phone = ?
                    WHERE department_id = ?";
                
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $department_code, $department_name, $faculty, $hod_name, $email, $phone, $department_id
                ]);
                
                // Log the action
                $log_stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([
                    $_SESSION['admin_id'] ?? 1,
                    'Update',
                    "Updated department: {$department_name}",
                    'departments',
                    $department_id
                ]);
                
                $_SESSION['success_message'] = "Department updated successfully!";
                header("Location: view_department.php?id=" . $department_id);
                exit();
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating department: " . $e->getMessage();
        }
    }
}

// Display success/error messages
if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Edit Department</h1>
    <div class="app-actions">
        <a href="view_department.php?id=<?php echo $department_id; ?>" class="btn btn-outline-secondary">
            <i class="fas fa-eye me-2"></i>View Department
        </a>
        <a href="departments.php" class="btn btn-outline-secondary ms-2">
            <i class="fas fa-arrow-left me-2"></i>Back to Departments
        </a>
    </div>
</div>

<div class="app-card app-card-form shadow-sm">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-edit me-2"></i>Edit Department Information
        </h5>
    </div>
    
    <div class="app-card-body p-3">
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Department Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="department_code" required 
                           value="<?php echo htmlspecialchars($department['department_code']); ?>"
                           placeholder="e.g., CSC, MTH, BUS">
                    <div class="form-text">Unique code for the department</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Department Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="department_name" required 
                           value="<?php echo htmlspecialchars($department['department_name']); ?>"
                           placeholder="e.g., Computer Science">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Faculty</label>
                    <select class="form-select" name="faculty">
                        <option value="">Select Faculty</option>
                        <?php foreach ($faculties as $faculty): ?>
                        <option value="<?php echo htmlspecialchars($faculty['faculty']); ?>" 
                            <?php echo ($department['faculty'] == $faculty['faculty']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($faculty['faculty']); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Other" <?php echo (!in_array($department['faculty'], array_column($faculties, 'faculty')) && $department['faculty']) ? 'selected' : ''; ?>>Other</option>
                    </select>
                    
                    <?php if (!in_array($department['faculty'], array_column($faculties, 'faculty')) && $department['faculty']): ?>
                    <div class="mt-2">
                        <input type="text" class="form-control" name="faculty_other" 
                               value="<?php echo htmlspecialchars($department['faculty']); ?>"
                               placeholder="Enter faculty name">
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Head of Department (HOD)</label>
                    <input type="text" class="form-control" name="hod_name" 
                           value="<?php echo htmlspecialchars($department['hod_name'] ?? ''); ?>"
                           placeholder="e.g., Prof. James Anderson">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" 
                           value="<?php echo htmlspecialchars($department['email'] ?? ''); ?>"
                           placeholder="e.g., department@university.edu">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" 
                           value="<?php echo htmlspecialchars($department['phone'] ?? ''); ?>"
                           placeholder="e.g., 08011112222">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Created Date</label>
                    <input type="text" class="form-control" 
                           value="<?php echo date('F j, Y', strtotime($department['created_date'])); ?>" 
                           readonly>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="view_department.php?id=<?php echo $department_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Department
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Handle "Other" faculty selection
document.querySelector('select[name="faculty"]').addEventListener('change', function() {
    const otherInput = document.querySelector('input[name="faculty_other"]');
    if (this.value === 'Other') {
        if (!otherInput) {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control mt-2';
            input.name = 'faculty_other';
            input.placeholder = 'Enter faculty name';
            this.parentNode.appendChild(input);
        }
    } else if (otherInput) {
        otherInput.remove();
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>