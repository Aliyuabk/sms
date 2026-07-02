<?php
require_once 'includes/header.php';

// Check if student ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_students.php');
    exit();
}

$student_id = (int)$_GET['id'];

// Get student details
$sql = "SELECT * FROM students WHERE student_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $_SESSION['error_message'] = "Student not found!";
    header('Location: manage_students.php');
    exit();
}

// Get departments and programs
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();

// Update student if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $update_data = [
            'first_name' => $_POST['first_name'],
            'middle_name' => $_POST['middle_name'] ?? null,
            'last_name' => $_POST['last_name'],
            'email' => $_POST['email'],
            'phone' => $_POST['phone'] ?? null,
            'date_of_birth' => $_POST['date_of_birth'] ?? null,
            'gender' => $_POST['gender'] ?? null,
            'department_id' => $_POST['department_id'] ?: null,
            'program_id' => $_POST['program_id'] ?: null,
            'current_level' => $_POST['current_level'],
            'current_session' => $_POST['current_session'],
            'status' => $_POST['status'],
            'admission_year' => $_POST['admission_year'],
            'mode_of_entry' => $_POST['mode_of_entry'] ?? null,
            'jamb_reg_number' => $_POST['jamb_reg_number'] ?? null,
            'nationality' => $_POST['nationality'] ?? null,
            'state_of_origin' => $_POST['state_of_origin'] ?? null,
            'address' => $_POST['address'] ?? null,
            'emergency_contact' => $_POST['emergency_contact'] ?? null,
            'emergency_contact_name' => $_POST['emergency_contact_name'] ?? null,
            'student_id' => $student_id
        ];
        
        // Update query
        $update_sql = "UPDATE students SET 
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            email = :email,
            phone = :phone,
            date_of_birth = :date_of_birth,
            gender = :gender,
            department_id = :department_id,
            program_id = :program_id,
            current_level = :current_level,
            current_session = :current_session,
            status = :status,
            admission_year = :admission_year,
            mode_of_entry = :mode_of_entry,
            jamb_reg_number = :jamb_reg_number,
            nationality = :nationality,
            state_of_origin = :state_of_origin,
            address = :address,
            emergency_contact = :emergency_contact,
            emergency_contact_name = :emergency_contact_name
            WHERE student_id = :student_id";
        
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute($update_data);
        
        $_SESSION['success_message'] = "Student profile updated successfully!";
        header("Location: view_student.php?id=$student_id");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating student: " . $e->getMessage();
    }
}

$page_title = "Edit Student - " . $student['first_name'] . ' ' . $student['last_name'];
?>

<div class="row">
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm p-4">
            <div class="app-card-header">
                <h3 class="app-card-title">Edit Student Profile</h3>
                <div class="text-muted">Matric: <?php echo htmlspecialchars($student['matric_number']); ?></div>
            </div>
            <div class="app-card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="first_name" class="form-label">First Name *</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="middle_name" class="form-label">Middle Name</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                   value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="last_name" class="form-label">Last Name *</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($student['email']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                   value="<?php echo $student['date_of_birth'] ?? ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select" id="gender" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($student['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($student['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="nationality" class="form-label">Nationality</label>
                            <input type="text" class="form-control" id="nationality" name="nationality" 
                                   value="<?php echo htmlspecialchars($student['nationality'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="department_id" class="form-label">Department</label>
                            <select class="form-select" id="department_id" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>"
                                    <?php echo ($student['department_id'] == $dept['department_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="program_id" class="form-label">Program</label>
                            <select class="form-select" id="program_id" name="program_id">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>"
                                    <?php echo ($student['program_id'] == $prog['program_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prog['program_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="current_level" class="form-label">Current Level *</label>
                            <select class="form-select" id="current_level" name="current_level" required>
                                <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo ($student['current_level'] == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> Level
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="current_session" class="form-label">Current Session *</label>
                            <input type="text" class="form-control" id="current_session" name="current_session" 
                                   value="<?php echo htmlspecialchars($student['current_session']); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="admission_year" class="form-label">Admission Year *</label>
                            <input type="number" class="form-control" id="admission_year" name="admission_year" 
                                   min="2000" max="2026" value="<?php echo htmlspecialchars($student['admission_year']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Active" <?php echo ($student['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($student['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Graduated" <?php echo ($student['status'] === 'Graduated') ? 'selected' : ''; ?>>Graduated</option>
                                <option value="Suspended" <?php echo ($student['status'] === 'Suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="Withdrawn" <?php echo ($student['status'] === 'Withdrawn') ? 'selected' : ''; ?>>Withdrawn</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mode_of_entry" class="form-label">Mode of Entry</label>
                            <select class="form-select" id="mode_of_entry" name="mode_of_entry">
                                <option value="">Select Mode</option>
                                <option value="UTME" <?php echo ($student['mode_of_entry'] === 'UTME') ? 'selected' : ''; ?>>UTME</option>
                                <option value="Direct Entry" <?php echo ($student['mode_of_entry'] === 'Direct Entry') ? 'selected' : ''; ?>>Direct Entry</option>
                                <option value="Transfer" <?php echo ($student['mode_of_entry'] === 'Transfer') ? 'selected' : ''; ?>>Transfer</option>
                                <option value="Remedial" <?php echo ($student['mode_of_entry'] === 'Remedial') ? 'selected' : ''; ?>>Remedial</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="jamb_reg_number" class="form-label">JAMB Registration No.</label>
                            <input type="text" class="form-control" id="jamb_reg_number" name="jamb_reg_number" 
                                   value="<?php echo htmlspecialchars($student['jamb_reg_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="state_of_origin" class="form-label">State of Origin</label>
                            <input type="text" class="form-control" id="state_of_origin" name="state_of_origin" 
                                   value="<?php echo htmlspecialchars($student['state_of_origin'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="emergency_contact" class="form-label">Emergency Contact</label>
                            <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
                                   value="<?php echo htmlspecialchars($student['emergency_contact'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" 
                                   value="<?php echo htmlspecialchars($student['emergency_contact_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Quick Actions</h6>
                <div class="d-grid gap-2">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-eye me-2"></i>View Profile
                    </a>
                    <a href="student_fees.php?id=<?php echo $student_id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-money-bill-wave me-2"></i>Manage Fees
                    </a>
                    <a href="student_results.php?id=<?php echo $student_id; ?>" class="btn btn-outline-info">
                        <i class="fas fa-chart-bar me-2"></i>View Results
                    </a>
                    <a href="course_registrations.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-warning">
                        <i class="fas fa-book me-2"></i>Course Registration
                    </a>
                    <a href="manage_students.php" class="btn btn-outline-secondary mt-3">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Student Info -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Student Information</h6>
            </div>
            <div class="app-card-body p-3">
                <dl class="row mb-0">
                    <dt class="col-6">Matric Number:</dt>
                    <dd class="col-6"><?php echo htmlspecialchars($student['matric_number']); ?></dd>
                    
                    <dt class="col-6">Registration Date:</dt>
                    <dd class="col-6"><?php echo date('M d, Y', strtotime($student['registration_date'])); ?></dd>
                    
                    <dt class="col-6">Last Login:</dt>
                    <dd class="col-6"><?php echo $student['last_login'] ? date('M d, Y H:i', strtotime($student['last_login'])) : 'Never'; ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>