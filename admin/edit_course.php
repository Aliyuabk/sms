<?php
// edit_course.php - FIXED VERSION
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Edit Course";

// Get course ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: courses.php");
    exit();
}

$course_id = (int)$_GET['id'];

// Fetch course details
$sql = "SELECT * FROM courses WHERE course_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$course_id]);
$course = $stmt->fetch();

if (!$course) {
    $_SESSION['error_message'] = "Course not found!";
    header("Location: courses.php");
    exit();
}

// Get data for form
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

// FIXED: Removed parameter from query() - use prepare/execute instead
$courses_sql = "SELECT course_id, course_code, course_title FROM courses WHERE course_id != ? ORDER BY course_code";
$courses_stmt = $pdo->prepare($courses_sql);
$courses_stmt->execute([$course_id]);
$courses = $courses_stmt->fetchAll();

$levels = [100, 200, 300, 400, 500, 600];
$semesters = ['1' => 'First Semester', '2' => 'Second Semester'];
$elective_types = ['University' => 'University Elective', 'Faculty' => 'Faculty Elective', 'Department' => 'Department Elective'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $course_code = trim($_POST['course_code'] ?? '');
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
        
        // Validate required fields
        $errors = [];
        
        if (empty($course_code)) {
            $errors[] = "Course code is required";
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
        
        // Check for existing course code (excluding current course)
        $check_sql = "SELECT course_id FROM courses WHERE course_code = ? AND course_id != ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$course_code, $course_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "Course code already exists for another course";
        }
        
        // Check for circular dependency
        if ($prerequisite_course_id == $course_id) {
            $errors[] = "A course cannot be a prerequisite for itself";
        }
        
        // If no errors, update the course
        if (empty($errors)) {
            $update_sql = "UPDATE courses SET 
                course_code = ?,
                course_title = ?,
                credit_units = ?,
                department_id = ?,
                level = ?,
                semester = ?,
                prerequisite_course_id = ?,
                is_core = ?,
                is_elective = ?,
                elective_type = ?,
                course_description = ?
                WHERE course_id = ?";
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
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
                $course_id
            ]);
            
            $_SESSION['success_message'] = "Course updated successfully!";
            header("Location: view_course.php?id=$course_id");
            exit();
        }
        
    } catch (Exception $e) {
        $errors[] = "Error updating course: " . $e->getMessage();
    }
} else {
    // Pre-fill form with existing data
    $_POST = $course;
}
?>

<!-- The rest of the file remains the same... -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Edit Course</h1>
    <div class="app-actions">
        <a href="view_course.php?id=<?php echo $course_id; ?>" class="btn app-btn-secondary">
            <i class="fas fa-eye me-2"></i>View Course
        </a>
        <a href="courses.php" class="btn app-btn-secondary ms-2">
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
            <i class="fas fa-edit me-2"></i>Edit Course Information
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
                                           <?php echo (isset($_POST['is_core']) && $_POST['is_core']) ? 'checked' : ''; ?>>
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
                                           <?php echo (isset($_POST['is_elective']) && $_POST['is_elective']) ? 'checked' : ''; ?>
                                           onchange="toggleElectiveType()">
                                    <label class="form-check-label fw-bold" for="is_elective">
                                        Elective Course
                                    </label>
                                </div>
                                
                                <div id="electiveTypeGroup" style="display: <?php echo (isset($_POST['is_elective']) && $_POST['is_elective']) ? 'block' : 'none'; ?>;">
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
                        <?php foreach ($courses as $course_item): ?>
                        <option value="<?php echo $course_item['course_id']; ?>"
                            <?php echo ($_POST['prerequisite_course_id'] ?? 0) == $course_item['course_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course_item['course_code'] . ' - ' . $course_item['course_title']); ?>
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
                              rows="6"
                              placeholder="Enter course description, objectives, learning outcomes..."><?php echo htmlspecialchars($_POST['course_description'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <a href="delete_course.php?id=<?php echo $course_id; ?>" 
                               class="btn btn-danger"
                               onclick="return confirm('Are you sure you want to delete this course? This action cannot be undone.')">
                                <i class="fas fa-trash me-2"></i>Delete Course
                            </a>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <a href="view_course.php?id=<?php echo $course_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Course
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Course Statistics Card -->
<div class="app-card app-card-info shadow-sm mt-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-chart-bar me-2"></i>Course Statistics
        </h5>
    </div>
    <div class="app-card-body p-3">
        <?php
        // Get course statistics
        $stats_sql = "
            SELECT 
                (SELECT COUNT(*) FROM course_registrations WHERE course_id = ?) as total_registrations,
                (SELECT COUNT(*) FROM results WHERE course_id = ?) as total_results,
                (SELECT COUNT(DISTINCT session_year) FROM course_registrations WHERE course_id = ?) as sessions_offered
        ";
        
        $stats_stmt = $pdo->prepare($stats_sql);
        $stats_stmt->execute([$course_id, $course_id, $course_id]);
        $stats = $stats_stmt->fetch();
        ?>
        
        <div class="row text-center">
            <div class="col-md-4">
                <div class="fs-4 fw-bold text-primary"><?php echo $stats['total_registrations'] ?? 0; ?></div>
                <div class="text-muted small">Total Registrations</div>
            </div>
            <div class="col-md-4">
                <div class="fs-4 fw-bold text-success"><?php echo $stats['total_results'] ?? 0; ?></div>
                <div class="text-muted small">Results Recorded</div>
            </div>
            <div class="col-md-4">
                <div class="fs-4 fw-bold text-info"><?php echo $stats['sessions_offered'] ?? 0; ?></div>
                <div class="text-muted small">Academic Sessions</div>
            </div>
        </div>
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

// Form validation
document.getElementById('courseForm').addEventListener('submit', function(e) {
    const courseCode = this.querySelector('input[name="course_code"]').value.trim();
    const courseTitle = this.querySelector('input[name="course_title"]').value.trim();
    const department = this.querySelector('select[name="department_id"]').value;
    const creditUnits = this.querySelector('input[name="credit_units"]').value;
    const prerequisite = this.querySelector('select[name="prerequisite_course_id"]').value;
    
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
    
    if (creditUnits < 1 || creditUnits > 6) {
        e.preventDefault();
        alert('Credit units must be between 1 and 6');
        return false;
    }
    
    // Check for circular dependency
    const courseId = <?php echo $course_id; ?>;
    if (prerequisite == courseId) {
        e.preventDefault();
        alert('A course cannot be a prerequisite for itself');
        return false;
    }
    
    // Course code format validation
    if (!/^[A-Za-z0-9\-]+$/.test(courseCode)) {
        e.preventDefault();
        alert('Course code can only contain letters, numbers, and hyphens');
        return false;
    }
    
    return true;
});

// Initialize on page load
window.addEventListener('DOMContentLoaded', function() {
    // Already initialized by PHP
});
</script>

<?php
require_once 'includes/footer.php';
?>