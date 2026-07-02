<?php
// edit_program.php
session_start();
require_once 'includes/database.php';
require_once 'includes/auth.php';

// Check if user is logged in
requireLogin();

// Check if program ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: programs.php');
    exit();
}

$program_id = (int)$_GET['id'];

// Fetch current program data
try {
    $sql = "SELECT p.*, d.department_name, d.faculty 
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.department_id
            WHERE p.program_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$program_id]);
    $program = $stmt->fetch();
    
    if (!$program) {
        $_SESSION['error_message'] = "Program not found.";
        header('Location: programs.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: programs.php');
    exit();
}

// Fetch departments for dropdown
$departments = [];
try {
    $departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name")->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

// Degree types
$degree_types = ['Undergraduate', 'Postgraduate', 'Diploma', 'Certificate', 'PhD', 'Masters', 'Bachelors'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Get and sanitize form data
    $program_code = trim($_POST['program_code'] ?? '');
    $program_name = trim($_POST['program_name'] ?? '');
    $department_id = (int)($_POST['department_id'] ?? 0);
    $degree_type = trim($_POST['degree_type'] ?? '');
    $duration_years = (int)($_POST['duration_years'] ?? 4);
    $total_credits = (int)($_POST['total_credits'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate input
    if (empty($program_code)) {
        $errors[] = "Program code is required.";
    }
    
    if (empty($program_name)) {
        $errors[] = "Program name is required.";
    }
    
    if ($department_id <= 0) {
        $errors[] = "Please select a department.";
    }
    
    if ($duration_years < 1 || $duration_years > 10) {
        $errors[] = "Duration must be between 1 and 10 years.";
    }
    
    // Check for duplicate program code
    if (empty($errors)) {
        try {
            $check_sql = "SELECT program_id FROM programs WHERE program_code = ? AND program_id != ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$program_code, $program_id]);
            
            if ($check_stmt->fetch()) {
                $errors[] = "Program code already exists. Please choose a different code.";
            }
        } catch (PDOException $e) {
            $errors[] = "Error checking program code: " . $e->getMessage();
        }
    }
    
    // Update program if no errors
    if (empty($errors)) {
        try {
            $update_sql = "UPDATE programs SET 
                          program_code = ?,
                          program_name = ?,
                          department_id = ?,
                          degree_type = ?,
                          duration_years = ?,
                          total_credits = ?,
                          description = ?,
                          is_active = ?,
                          updated_at = NOW()
                          WHERE program_id = ?";
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $program_code,
                $program_name,
                $department_id,
                $degree_type,
                $duration_years,
                $total_credits,
                $description,
                $is_active,
                $program_id
            ]);
            
            $_SESSION['success_message'] = "Program updated successfully!";
            header("Location: view_program.php?id=" . $program_id);
            exit();
            
        } catch (PDOException $e) {
            $errors[] = "Error updating program: " . $e->getMessage();
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
}

$page_title = "Edit Program - " . htmlspecialchars($program['program_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        .form-label {
            font-weight: 500;
            color: #495057;
        }
        .required::after {
            content: " *";
            color: #dc3545;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .form-control:focus, .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .sidebar-card {
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>Student Portal
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="programs.php">
                            <i class="fas fa-book"></i> Programs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="programs.php">Programs</a></li>
                <li class="breadcrumb-item"><a href="view_program.php?id=<?php echo $program_id; ?>">
                    <?php echo htmlspecialchars($program['program_name']); ?>
                </a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>

        <!-- Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1">
                    <i class="fas fa-edit text-primary me-2"></i>
                    Edit Program
                </h1>
                <p class="text-muted mb-0">Update program information</p>
            </div>
            <div>
                <a href="view_program.php?id=<?php echo $program_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-eye me-1"></i> View Program
                </a>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="row">
            <div class="col-lg-8">
                <form method="POST" action="" id="editProgramForm">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle text-primary me-2"></i>
                                Program Details
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Program Code -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="program_code" class="form-label required">Program Code</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="program_code" 
                                           name="program_code" 
                                           value="<?php echo htmlspecialchars($program['program_code']); ?>"
                                           required
                                           maxlength="20"
                                           placeholder="e.g., CSC101">
                                    <div class="form-text">
                                        Unique code for the program (max 20 characters)
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="program_name" class="form-label required">Program Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="program_name" 
                                           name="program_name" 
                                           value="<?php echo htmlspecialchars($program['program_name']); ?>"
                                           required
                                           placeholder="e.g., Bachelor of Computer Science">
                                </div>
                            </div>
                            
                            <!-- Department & Degree Type -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="department_id" class="form-label required">Department</label>
                                    <select class="form-select" id="department_id" name="department_id" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['department_id']; ?>"
                                            <?php echo ($program['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['department_name']); ?>
                                            <?php if (!empty($dept['department_code'])): ?>
                                            (<?php echo htmlspecialchars($dept['department_code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="degree_type" class="form-label">Degree Type</label>
                                    <select class="form-select" id="degree_type" name="degree_type">
                                        <option value="">Select Degree Type</option>
                                        <?php foreach ($degree_types as $type): ?>
                                        <option value="<?php echo $type; ?>"
                                            <?php echo ($program['degree_type'] == $type) ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Duration & Credits -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="duration_years" class="form-label">Duration (Years)</label>
                                    <select class="form-select" id="duration_years" name="duration_years">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?php echo $i; ?>"
                                            <?php echo ($program['duration_years'] == $i) ? 'selected' : ''; ?>>
                                            <?php echo $i; ?> Year<?php echo $i > 1 ? 's' : ''; ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="total_credits" class="form-label">Total Credits</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               class="form-control" 
                                               id="total_credits" 
                                               name="total_credits" 
                                               value="<?php echo htmlspecialchars($program['total_credits'] ?? 120); ?>"
                                               min="0"
                                               step="1">
                                        <span class="input-group-text">credits</span>
                                    </div>
                                    <div class="form-text">
                                        Total credit units required for graduation
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Status -->
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="is_active" 
                                               name="is_active" 
                                               value="1"
                                               <?php echo ($program['is_active'] == 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active Program
                                        </label>
                                        <div class="form-text">
                                            Inactive programs won't be available for new student enrollments
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Description -->
                            <div class="row mb-3">
                                <div class="col-12">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" 
                                              id="description" 
                                              name="description" 
                                              rows="6"
                                              placeholder="Describe the program objectives, learning outcomes, etc..."><?php echo htmlspecialchars($program['description'] ?? ''); ?></textarea>
                                    <div class="form-text">
                                        This description will be visible to students and faculty
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="card-footer bg-white border-top py-3">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="view_program.php?id=<?php echo $program_id; ?>" class="btn btn-outline-danger">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Changes
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                        <i class="fas fa-redo me-1"></i> Reset
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <div class="sidebar-card">
                    <!-- Program Summary -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-file-alt text-info me-2"></i>
                                Program Summary
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <h6>Current Information</h6>
                                <ul class="list-unstyled small">
                                    <li class="mb-2">
                                        <i class="fas fa-building text-muted me-2"></i>
                                        <strong>Department:</strong><br>
                                        <?php echo htmlspecialchars($program['department_name'] ?? 'Not assigned'); ?>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-university text-muted me-2"></i>
                                        <strong>Faculty:</strong><br>
                                        <?php echo htmlspecialchars($program['faculty'] ?? 'Not assigned'); ?>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-calendar text-muted me-2"></i>
                                        <strong>Created:</strong><br>
                                        <?php echo date('M d, Y', strtotime($program['created_at'] ?? 'now')); ?>
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-history text-muted me-2"></i>
                                        <strong>Last Updated:</strong><br>
                                        <?php echo timeAgo($program['updated_at'] ?? ''); ?>
                                    </li>
                                </ul>
                            </div>
                            
                            <hr>
                            
                            <div class="alert alert-info">
                                <h6 class="alert-heading">
                                    <i class="fas fa-lightbulb me-2"></i>
                                    Editing Tips
                                </h6>
                                <ul class="mb-0 small">
                                    <li>Program code must be unique</li>
                                    <li>Changing department affects all enrolled students</li>
                                    <li>Deactivating prevents new enrollments</li>
                                    <li>Save changes to update all information</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt text-warning me-2"></i>
                                Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="add_course.php?program_id=<?php echo $program_id; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-plus-circle me-1"></i> Add Course
                                </a>
                                <a href="program_curriculum.php?id=<?php echo $program_id; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-book-open me-1"></i> Manage Curriculum
                                </a>
                                <a href="duplicate_program.php?id=<?php echo $program_id; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-copy me-1"></i> Duplicate Program
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Form validation
    document.getElementById('editProgramForm').addEventListener('submit', function(e) {
        const programCode = document.getElementById('program_code');
        const programName = document.getElementById('program_name');
        const department = document.getElementById('department_id');
        
        let isValid = true;
        
        // Reset previous error states
        [programCode, programName, department].forEach(field => {
            field.classList.remove('is-invalid');
        });
        
        // Validate program code
        if (!programCode.value.trim()) {
            programCode.classList.add('is-invalid');
            isValid = false;
        }
        
        // Validate program name
        if (!programName.value.trim()) {
            programName.classList.add('is-invalid');
            isValid = false;
        }
        
        // Validate department
        if (!department.value) {
            department.classList.add('is-invalid');
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
            
            // Scroll to first error
            const firstInvalid = document.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
        }
    });
    
    // Reset form
    function resetForm() {
        if (confirm('Are you sure you want to reset all changes? This cannot be undone.')) {
            // Reset form fields to their original values
            document.getElementById('editProgramForm').reset();
            
            // Manually set the checkbox state
            const isActive = <?php echo $program['is_active']; ?>;
            document.getElementById('is_active').checked = isActive == 1;
            
            // Reset select fields
            document.getElementById('department_id').value = '<?php echo $program['department_id']; ?>';
            document.getElementById('degree_type').value = '<?php echo addslashes($program['degree_type'] ?? ''); ?>';
            document.getElementById('duration_years').value = '<?php echo $program['duration_years']; ?>';
            
            // Remove validation classes
            document.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });
            
            alert('Form has been reset to original values.');
        }
    }
    
    // Character counter for description
    const description = document.getElementById('description');
    const charCount = document.createElement('div');
    charCount.className = 'form-text text-end mt-1';
    charCount.id = 'charCount';
    description.parentNode.appendChild(charCount);
    
    function updateCharCount() {
        const length = description.value.length;
        charCount.textContent = `${length} / 2000 characters`;
        
        if (length > 1900) {
            charCount.classList.add('text-warning');
        } else {
            charCount.classList.remove('text-warning');
        }
    }
    
    description.addEventListener('input', updateCharCount);
    updateCharCount(); // Initial count
    
    // Program code validation
    const programCodeInput = document.getElementById('program_code');
    programCodeInput.addEventListener('blur', function() {
        const value = this.value.trim();
        const pattern = /^[A-Z0-9\-_]+$/i;
        
        if (value && !pattern.test(value)) {
            this.classList.add('is-invalid');
            this.nextElementSibling.textContent = 'Only letters, numbers, hyphens and underscores allowed';
        } else {
            this.classList.remove('is-invalid');
            this.nextElementSibling.textContent = 'Unique code for the program (max 20 characters)';
        }
    });
    </script>
</body>
</html>