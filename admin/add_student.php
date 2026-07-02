<?php
require_once 'includes/header.php';

$page_title = "Add New Student";

// Get departments and programs for dropdowns
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();
$academic_advisors = $pdo->query("SELECT * FROM academic_advisors WHERE status = 'Active' ORDER BY first_name, last_name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Generate matric number
        $current_year = date('y'); // Last 2 digits of current year
        $dept_code = ''; // Will get from department
        $serial_number = '';
        
        // Get department code
        if (!empty($_POST['department_id'])) {
            $dept_stmt = $pdo->prepare("SELECT department_code FROM departments WHERE department_id = ?");
            $dept_stmt->execute([$_POST['department_id']]);
            $dept = $dept_stmt->fetch();
            $dept_code = $dept['department_code'] ?? '';
        }
        
        // Get next serial number for this department and year
        $serial_sql = "SELECT COUNT(*) as count FROM students 
                       WHERE matric_number LIKE 'U$current_year/$dept_code/%'";
        $serial_stmt = $pdo->prepare($serial_sql);
        $serial_stmt->execute();
        $serial_count = $serial_stmt->fetchColumn();
        $serial_number = str_pad($serial_count + 1, 3, '0', STR_PAD_LEFT);
        
        $matric_number = "U$current_year/$dept_code/$serial_number";
        
        // Insert student
        $stmt = $pdo->prepare("INSERT INTO students 
            (matric_number, first_name, middle_name, last_name, email,
             date_of_birth, gender, nationality, state_of_origin, lga, phone, address,
             emergency_contact, emergency_contact_name, blood_group, disability,
             department_id, program_id, admission_year, current_level, current_session,
             mode_of_entry, jamb_reg_number, student_type, marital_status, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        
        
        $stmt->execute([
            $matric_number,
            $_POST['first_name'],
            $_POST['middle_name'] ?? null,
            $_POST['last_name'],
            $_POST['email'],
            $_POST['date_of_birth'] ?: null,
            $_POST['gender'] ?: null,
            $_POST['nationality'] ?: null,
            $_POST['state_of_origin'] ?: null,
            $_POST['lga'] ?: null,
            $_POST['phone'] ?: null,
            $_POST['address'] ?: null,
            $_POST['emergency_contact'] ?: null,
            $_POST['emergency_contact_name'] ?: null,
            $_POST['blood_group'] ?: null,
            $_POST['disability'] ?: null,
            $_POST['department_id'] ?: null,
            $_POST['program_id'] ?: null,
            $_POST['admission_year'],
            $_POST['current_level'],
            $_POST['current_session'],
            $_POST['mode_of_entry'] ?: null,
            $_POST['jamb_reg_number'] ?: null,
            $_POST['student_type'] ?: null,
            $_POST['marital_status'] ?: null,
            'Active'
        ]);
        
        $student_id = $pdo->lastInsertId();
        
        // Assign academic advisor if selected
        if (!empty($_POST['advisor_id'])) {
            $advisor_stmt = $pdo->prepare("INSERT INTO student_advisors 
                (student_id, advisor_id, assignment_reason, status)
                VALUES (?, ?, ?, ?)");
            
            $advisor_stmt->execute([
                $student_id,
                $_POST['advisor_id'],
                $_POST['assignment_reason'] ?? 'Initial assignment',
                'Active'
            ]);
            
            // Update advisor's current student count
            $update_stmt = $pdo->prepare("UPDATE academic_advisors 
                SET current_students = current_students + 1 
                WHERE advisor_id = ?");
            $update_stmt->execute([$_POST['advisor_id']]);
        }
        
        // Create user settings entry
        $settings_stmt = $pdo->prepare("INSERT INTO user_settings (student_id) VALUES (?)");
        $settings_stmt->execute([$student_id]);
        
        $_SESSION['success_message'] = "Student added successfully! Matric Number: " . $matric_number . 
        " Default password is 'password'.";
        
        // Redirect to view student page
        header("Location: view_student.php?id=$student_id");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error adding student: " . $e->getMessage();
    }
}

// Get current academic session
$current_session = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn();
if (!$current_session) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
}
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Add New Student</h1>
            <p class="text-muted">Register a new student in the system</p>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
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
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Student Registration Form</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" id="studentForm">
                    <!-- Personal Information -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2 mb-3">Personal Information</h5>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" required>
                                <small class="text-muted">This will be used for login and notifications</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Blood Group</label>
                                <select class="form-select" name="blood_group">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nationality</label>
                                <input type="text" class="form-control" name="nationality" value="Nigerian">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">State of Origin</label>
                                <input type="text" class="form-control" name="state_of_origin">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">LGA (Local Government Area)</label>
                                <input type="text" class="form-control" name="lga">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Marital Status</label>
                                <select class="form-select" name="marital_status">
                                    <option value="">Select Status</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2 mb-3">Emergency Contact</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact Name</label>
                                <input type="text" class="form-control" name="emergency_contact_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact Phone</label>
                                <input type="text" class="form-control" name="emergency_contact">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Disability/Special Needs</label>
                            <input type="text" class="form-control" name="disability" placeholder="If any, please specify">
                        </div>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2 mb-3">Academic Information</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department *</label>
                                <select class="form-select" name="department_id" id="departmentSelect" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                        (<?php echo htmlspecialchars($dept['department_code']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Program *</label>
                                <select class="form-select" name="program_id" id="programSelect" required>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['program_id']; ?>" data-dept="<?php echo $prog['department_id']; ?>">
                                        <?php echo htmlspecialchars($prog['program_name']); ?>
                                        (<?php echo htmlspecialchars($prog['program_code']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Admission Year *</label>
                                <select class="form-select" name="admission_year" required>
                                    <?php 
                                    $current_year = date('Y');
                                    for ($i = $current_year - 10; $i <= $current_year + 1; $i++): 
                                    ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == $current_year ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Current Level *</label>
                                <select class="form-select" name="current_level" required>
                                    <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == 100 ? 'selected' : ''; ?>>
                                        <?php echo $i; ?> Level
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Current Session *</label>
                                <input type="text" class="form-control" name="current_session" 
                                       value="<?php echo htmlspecialchars($current_session); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mode of Entry</label>
                                <select class="form-select" name="mode_of_entry">
                                    <option value="">Select Mode</option>
                                    <option value="UTME">UTME</option>
                                    <option value="Direct Entry">Direct Entry</option>
                                    <option value="Transfer">Transfer</option>
                                    <option value="Remedial">Remedial</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">JAMB Registration Number</label>
                                <input type="text" class="form-control" name="jamb_reg_number">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Student Type</label>
                                <select class="form-select" name="student_type">
                                    <option value="">Select Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Distance Learning">Distance Learning</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Academic Advisor -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2 mb-3">Academic Advisor Assignment</h5>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Academic Advisor</label>
                                <select class="form-select" name="advisor_id" id="advisorSelect">
                                    <option value="">Select Advisor (Optional)</option>
                                    <?php foreach ($academic_advisors as $advisor): ?>
                                    <option value="<?php echo $advisor['advisor_id']; ?>" 
                                            data-dept="<?php echo $advisor['department_id']; ?>"
                                            data-available="<?php echo $advisor['max_students'] - $advisor['current_students']; ?>">
                                        <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                                        (<?php echo htmlspecialchars($advisor['email']); ?>)
                                        - Available: <?php echo $advisor['max_students'] - $advisor['current_students']; ?> slots
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">You can assign an academic advisor now or later</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Assignment Reason</label>
                                <input type="text" class="form-control" name="assignment_reason" 
                                       value="Initial assignment">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="d-flex justify-content-between">
                        <a href="manage_students.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Add Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Quick Tips -->
        <div class="app-card app-card-stat shadow-sm mt-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Registration Tips</h6>
                <ul class="small mb-0">
                    <li>A matric number will be automatically generated based on department and year</li>
                    <li>Default password for new students is: <strong>password</strong></li>
                    <li>Students should change their password after first login</li>
                    <li>Academic advisor assignment can be done later if not sure</li>
                    <li>Emergency contact information is important for safety</li>
                    <li>All required fields are marked with an asterisk (*)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Filter programs based on selected department
document.getElementById('departmentSelect').addEventListener('change', function() {
    const deptId = this.value;
    const programSelect = document.getElementById('programSelect');
    const advisorSelect = document.getElementById('advisorSelect');
    
    // Filter programs
    Array.from(programSelect.options).forEach(option => {
        if (option.value === '') return;
        
        if (!deptId || option.getAttribute('data-dept') === deptId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
            if (option.selected) {
                programSelect.value = '';
            }
        }
    });
    
    // Filter advisors
    Array.from(advisorSelect.options).forEach(option => {
        if (option.value === '') return;
        
        if (!deptId || option.getAttribute('data-dept') === deptId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
            if (option.selected) {
                advisorSelect.value = '';
            }
        }
    });
});

// Form validation
document.getElementById('studentForm').addEventListener('submit', function(e) {
    const email = document.querySelector('input[name="email"]').value;
    const phone = document.querySelector('input[name="phone"]').value;
    const dob = document.querySelector('input[name="date_of_birth"]').value;
    
    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return false;
    }
    
    // Phone validation (basic)
    if (phone && !/^[\d\s\-\+\(\)]{10,15}$/.test(phone)) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-15 digits).');
        return false;
    }
    
    // Date of birth validation
    if (dob) {
        const birthDate = new Date(dob);
        const today = new Date();
        const minAge = 16;
        const maxAge = 50;
        
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        if (age < minAge) {
            e.preventDefault();
            alert('Student must be at least ' + minAge + ' years old.');
            return false;
        }
        
        if (age > maxAge) {
            if (!confirm('Student age seems high (' + age + ' years). Continue anyway?')) {
                e.preventDefault();
                return false;
            }
        }
    }
    
    // Advisor availability check
    const advisorSelect = document.getElementById('advisorSelect');
    if (advisorSelect.value) {
        const selectedOption = advisorSelect.options[advisorSelect.selectedIndex];
        const availableSlots = parseInt(selectedOption.getAttribute('data-available'));
        
        if (availableSlots <= 0) {
            e.preventDefault();
            alert('Selected advisor has no available slots. Please choose another advisor or leave unassigned.');
            return false;
        }
    }
    
    return confirm('Add this student to the system?');
});

// Auto-generate email suggestion
document.querySelector('input[name="first_name"], input[name="last_name"]').addEventListener('blur', function() {
    const firstName = document.querySelector('input[name="first_name"]').value.toLowerCase();
    const lastName = document.querySelector('input[name="last_name"]').value.toLowerCase();
    const emailInput = document.querySelector('input[name="email"]');
    
    if (firstName && lastName && !emailInput.value) {
        // Generate email suggestion
        const emailSuggestion = firstName.charAt(0) + lastName + '@university.edu';
        emailInput.value = emailSuggestion;
        
        // Check if email already exists
        fetch(`check_email.php?email=${encodeURIComponent(emailSuggestion)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    // Try alternative
                    const altEmail = firstName + '.' + lastName + '@university.edu';
                    emailInput.value = altEmail;
                }
            });
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>