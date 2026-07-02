<?php
// edit_faculty.php
ob_start();
require_once 'includes/header.php';

// Set page title
$page_title = "Edit Faculty";

// Get faculty ID from URL
$faculty_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($faculty_id <= 0) {
    header("Location: manage_faculties.php");
    exit();
}

// Get faculty details
$faculty_stmt = $pdo->prepare("SELECT * FROM faculties WHERE faculty_id = ?");
$faculty_stmt->execute([$faculty_id]);
$faculty = $faculty_stmt->fetch();

if (!$faculty) {
    $_SESSION['error_message'] = "Faculty not found!";
    header("Location: manage_faculties.php");
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faculty_code = $_POST['faculty_code'] ?? '';
    $faculty_name = $_POST['faculty_name'] ?? '';
    $dean_name = $_POST['dean_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $office_location = $_POST['office_location'] ?? '';
    $description = $_POST['description'] ?? '';
    $established_year = $_POST['established_year'] ?? '';
    $status = $_POST['status'] ?? 'Active';
    
    // Validate
    if (empty($faculty_code) || empty($faculty_name)) {
        $_SESSION['error_message'] = "Faculty code and name are required!";
    } else {
        try {
            // Check if faculty code or name already exists (excluding current faculty)
            $check_stmt = $pdo->prepare("SELECT faculty_id FROM faculties WHERE (faculty_code = ? OR faculty_name = ?) AND faculty_id != ?");
            $check_stmt->execute([$faculty_code, $faculty_name, $faculty_id]);
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error_message'] = "Another faculty with this code or name already exists!";
            } else {
                $old_faculty_name = $faculty['faculty_name'];
                
                $update_sql = "UPDATE faculties SET 
                    faculty_code = ?, faculty_name = ?, dean_name = ?, email = ?, phone = ?, 
                    office_location = ?, description = ?, established_year = ?, status = ?
                    WHERE faculty_id = ?";
                
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $faculty_code, $faculty_name, $dean_name, $email, $phone, 
                    $office_location, $description, $established_year, $status, $faculty_id
                ]);
                
                // Update department faculty names if faculty name changed
                if ($old_faculty_name != $faculty_name) {
                    $update_dept_stmt = $pdo->prepare("UPDATE departments SET faculty = ? WHERE faculty = ?");
                    $update_dept_stmt->execute([$faculty_name, $old_faculty_name]);
                }
                
                // Log the action
                $log_stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([
                    $_SESSION['admin_id'] ?? 1,
                    'Update',
                    "Updated faculty: {$faculty_name}",
                    'faculties',
                    $faculty_id
                ]);
                
                $_SESSION['success_message'] = "Faculty updated successfully!";
                header("Location: view_faculty.php?id=" . $faculty_id);
                exit();
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating faculty: " . $e->getMessage();
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
    <h1 class="app-page-title mb-0">Edit Faculty</h1>
    <div class="app-actions">
        <a href="view_faculty.php?id=<?php echo $faculty_id; ?>" class="btn btn-outline-secondary">
            <i class="fas fa-eye me-2"></i>View Faculty
        </a>
        <a href="manage_faculties.php" class="btn btn-outline-secondary ms-2">
            <i class="fas fa-arrow-left me-2"></i>Back to Faculties
        </a>
    </div>
</div>

<div class="app-card app-card-form shadow-sm">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-edit me-2"></i>Edit Faculty Information
        </h5>
    </div>
    
    <div class="app-card-body p-3">
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Faculty Code <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="faculty_code" required 
                           value="<?php echo htmlspecialchars($faculty['faculty_code']); ?>"
                           placeholder="e.g., SCI, ART, BUS">
                    <div class="form-text">Unique code for the faculty</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Faculty Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="faculty_name" required 
                           value="<?php echo htmlspecialchars($faculty['faculty_name']); ?>"
                           placeholder="e.g., Faculty of Science">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Dean Name</label>
                    <input type="text" class="form-control" name="dean_name" 
                           value="<?php echo htmlspecialchars($faculty['dean_name'] ?? ''); ?>"
                           placeholder="e.g., Prof. James Anderson">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Established Year</label>
                    <select class="form-select" name="established_year">
                        <option value="">Select Year</option>
                        <?php for ($year = date('Y'); $year >= 1900; $year--): ?>
                        <option value="<?php echo $year; ?>" 
                            <?php echo ($faculty['established_year'] == $year) ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" 
                           value="<?php echo htmlspecialchars($faculty['email'] ?? ''); ?>"
                           placeholder="e.g., faculty@university.edu">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" 
                           value="<?php echo htmlspecialchars($faculty['phone'] ?? ''); ?>"
                           placeholder="e.g., 08011112222">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Office Location</label>
                <input type="text" class="form-control" name="office_location" 
                       value="<?php echo htmlspecialchars($faculty['office_location'] ?? ''); ?>"
                       placeholder="e.g., Science Block A, 2nd Floor">
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="4"
                          placeholder="Brief description of the faculty..."><?php echo htmlspecialchars($faculty['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="Active" <?php echo ($faculty['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($faculty['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">Created</label>
                    <input type="text" class="form-control" 
                           value="<?php echo date('F j, Y, g:i a', strtotime($faculty['created_at'])); ?>" 
                           readonly>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="view_faculty.php?id=<?php echo $faculty_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Faculty
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>