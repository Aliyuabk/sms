<?php
// add_course.php - FIXED VERSION
ob_start();
require_once 'includes/header.php';

// Validate admin session
if (empty($admin_id)) {
    $_SESSION['error_message'] = "Admin session expired. Please log in again.";
    header("Location: login.php");
    exit();
}

$page_title = "Add New Course";

// Get data for form
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$courses = $pdo->query("SELECT course_id, course_code, course_title FROM courses ORDER BY course_code")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$semesters = ['1' => 'First Semester', '2' => 'Second Semester'];
$elective_types = ['University' => 'University Elective', 'Faculty' => 'Faculty Elective', 'Department' => 'Department Elective'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (if implemented in header.php)
    // if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    //     die('Invalid CSRF token');
    // }
    
    try {
        $pdo->beginTransaction();
        
        // Get & sanitize form data
        $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
        $course_title = trim($_POST['course_title'] ?? '');
        $credit_units = (int)($_POST['credit_units'] ?? 3);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $level = !empty($_POST['level']) ? (int)$_POST['level'] : null;
        $semester = !empty($_POST['semester']) ? (int)$_POST['semester'] : null;
        $prerequisite_course_id = !empty($_POST['prerequisite_course_id']) ? (int)$_POST['prerequisite_course_id'] : null;
        $is_core = isset($_POST['is_core']) ? 1 : 0;
        $is_elective = isset($_POST['is_elective']) ? 1 : 0;
        $elective_type = !empty($_POST['elective_type']) ? $_POST['elective_type'] : null;
        $course_description = trim($_POST['course_description'] ?? '');
        
        // Validation
        $errors = [];
        
        if (empty($course_code)) {
            $errors[] = "Course code is required";
        } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $course_code)) {
            $errors[] = "Course code can only contain letters, numbers, and hyphens";
        }
        
        if (empty($course_title)) {
            $errors[] = "Course title is required";
        }
        
        if ($credit_units < 1 || $credit_units > 6) {
            $errors[] = "Credit units must be between 1 and 6";
        }
        
        if ($department_id < 1) {
            $errors[] = "Please select a department";
        }
        
        // Mutual exclusivity: core vs elective
        if ($is_core && $is_elective) {
            $errors[] = "A course cannot be both core and elective";
        }
        
        // Clear elective_type if not elective
        if (!$is_elective) {
            $elective_type = null;
        }
        
        // Check for existing course code
        $check_sql = "SELECT course_id FROM courses WHERE course_code = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$course_code]);
        
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "Course code already exists";
        }
        
        // If no errors, insert
        if (empty($errors)) {
            $insert_sql = "INSERT INTO courses (
                course_code, course_title, credit_units, department_id,
                level, semester, prerequisite_course_id, is_core, is_elective,
                elective_type, course_description, created_by, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $course_code,
                $course_title,
                $credit_units,
                $department_id,
                $level,
                $semester,
                $prerequisite_course_id,
                $is_core,
                $is_elective,
                $elective_type,
                $course_description,
                $admin_id
            ]);
            
            $course_id = $pdo->lastInsertId();
            $pdo->commit();
            
            $_SESSION['success_message'] = "Course added successfully!";
            
            // FIX: Handle "Save & Add Another"
            if (isset($_POST['save_and_add'])) {
                header("Location: add_course.php");
            } else {
                header("Location: edit_course.php?id=$course_id");
            }
            exit();
        } else {
            $pdo->rollBack();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Error adding course: " . $e->getMessage();
    }
}

// Generate CSRF token (add to form if implemented)
// $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!-- Add to form for CSRF: -->
<!-- <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"> -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Add New Course</h1>
    <div class="app-actions">
        <a href="courses.php" class="btn app-btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Courses
        </a>
    </div>
</div>

<!-- Display errors -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Course Form -->
<div class="app-card app-card-form shadow-sm">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-book me-2"></i>Course Information
        </h5>
    </div>
    <div class="app-card-body p-4">
        <form method="POST" id="courseForm">
            <div class="row g-3">
                <!-- Basic Information -->
                <div class="col-md-6">
                    <label class="form-label">Course Code *</label>
                    <input type="text" 
                           class="form-control" 
                           name="course_code" 
                           value="<?php echo htmlspecialchars($_POST['course_code'] ?? ''); ?>" 
                           required
                           placeholder="e.g., CSC101">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Course Title *</label>
                    <input type="text" 
                           class="form-control" 
                           name="course_title" 
                           value="<?php echo htmlspecialchars($_POST['course_title'] ?? ''); ?>" 
                           required
                           placeholder="e.g., Introduction to Computer Science">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Credit Units *</label>
                    <input type="number" 
                           class="form-control" 
                           name="credit_units" 
                           value="<?php echo htmlspecialchars($_POST['credit_units'] ?? 3); ?>" 
                           min="1" 
                           max="6" 
                           required>
                    <div class="form-text">Typically 1-6 units</div>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Department *</label>
                    <select class="form-select" name="department_id" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>"
                            <?php echo ($_POST['department_id'] ?? 0) == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Level</label>
                    <select class="form-select" name="level">
                        <option value="">Select Level</option>
                        <?php foreach ($levels as $lvl): ?>
                        <option value="<?php echo $lvl; ?>"
                            <?php echo ($_POST['level'] ?? '') == $lvl ? 'selected' : ''; ?>>
                            <?php echo $lvl; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Semester</label>
                    <select class="form-select" name="semester">
                        <option value="">Select Semester</option>
                        <?php foreach ($semesters as $val => $label): ?>
                        <option value="<?php echo $val; ?>"
                            <?php echo ($_POST['semester'] ?? '') == $val ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Course Type -->
                <div class="col-md-12">
                    <div class="border p-3 rounded mb-3">
                        <h6 class="mb-3"><i class="fas fa-tag me-2"></i>Course Type</h6>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="is_core" 
                                           id="is_core"
                                           value="1"
                                           <?php echo isset($_POST['is_core']) ? 'checked' : 'checked'; ?>>
                                    <label class="form-check-label fw-bold" for="is_core">
                                        Core Course
                                    </label>
                                    <div class="form-text">Required for all students in the program</div>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="is_elective" 
                                           id="is_elective"
                                           value="1"
                                           <?php echo isset($_POST['is_elective']) ? 'checked' : ''; ?>
                                           onchange="toggleElectiveType()">
                                    <label class="form-check-label fw-bold" for="is_elective">
                                        Elective Course
                                    </label>
                                </div>
                                
                                <div id="electiveTypeGroup" style="display: none;">
                                    <label class="form-label">Elective Type</label>
                                    <select class="form-select" name="elective_type">
                                        <option value="">Select Type</option>
                                        <?php foreach ($elective_types as $val => $label): ?>
                                        <option value="<?php echo $val; ?>"
                                            <?php echo ($_POST['elective_type'] ?? '') == $val ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Prerequisite -->
                <div class="col-md-6">
                    <label class="form-label">Prerequisite Course</label>
                    <select class="form-select" name="prerequisite_course_id">
                        <option value="">Select Prerequisite (Optional)</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?php echo $course['course_id']; ?>"
                            <?php echo ($_POST['prerequisite_course_id'] ?? 0) == $course['course_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Course that must be completed before taking this course</div>
                </div>
                
                <!-- Course Description -->
                <div class="col-md-12">
                    <label class="form-label">Course Description</label>
                    <textarea class="form-control" 
                              name="course_description" 
                              rows="4"
                              placeholder="Enter course description, objectives, learning outcomes..."><?php echo htmlspecialchars($_POST['course_description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="d-flex justify-content-end gap-2">
                        <a href="courses.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Course
                        </button>
                        <button type="submit" name="save_and_add" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Save & Add Another
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Quick Tips Card -->
<div class="app-card app-card-info shadow-sm mt-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-lightbulb me-2"></i>Quick Tips
        </h5>
    </div>
    <div class="app-card-body p-3">
        <ul class="mb-0 small">
            <li>Course codes should follow your institution's naming convention (e.g., CSC101, MAT201)</li>
            <li>Core courses are mandatory for all students in the program</li>
            <li>Elective courses are optional and can be university-wide, faculty-wide, or department-specific</li>
            <li>Prerequisites ensure students have the required knowledge before taking advanced courses</li>
            <li>Credit units typically represent contact hours per week</li>
        </ul>
    </div>
</div>

<script>
// Toggle elective type field
function toggleElectiveType() {
    const electiveCheckbox = document.getElementById('is_elective');
    const electiveTypeGroup = document.getElementById('electiveTypeGroup');
    
    if (electiveCheckbox.checked) {
        electiveTypeGroup.style.display = 'block';
        // Uncheck core if elective is checked
        document.getElementById('is_core').checked = false;
    } else {
        electiveTypeGroup.style.display = 'none';
    }
}

// Toggle core/elective exclusivity
document.getElementById('is_core').addEventListener('change', function() {
    if (this.checked) {
        document.getElementById('is_elective').checked = false;
        document.getElementById('electiveTypeGroup').style.display = 'none';
    }
});

document.getElementById('is_elective').addEventListener('change', function() {
    if (this.checked) {
        document.getElementById('is_core').checked = false;
    }
});

// Initialize on page load
window.addEventListener('DOMContentLoaded', function() {
    toggleElectiveType();
});

// Form validation
document.getElementById('courseForm').addEventListener('submit', function(e) {
    const courseCode = this.querySelector('input[name="course_code"]').value.trim();
    const courseTitle = this.querySelector('input[name="course_title"]').value.trim();
    const department = this.querySelector('select[name="department_id"]').value;
    
    if (!courseCode) {
        e.preventDefault();
        alert('Please enter a course code');
        return false;
    }
    
    if (!courseTitle) {
        e.preventDefault();
        alert('Please enter a course title');
        return false;
    }
    
    if (!department) {
        e.preventDefault();
        alert('Please select a department');
        return false;
    }
    
    // Course code format validation (alphanumeric and hyphens)
    if (!/^[A-Za-z0-9\-]+$/.test(courseCode)) {
        e.preventDefault();
        alert('Course code can only contain letters, numbers, and hyphens');
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>