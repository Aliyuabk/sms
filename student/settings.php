<?php
require_once 'includes/header.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get complete student profile with related data
$query = "SELECT s.*, 
          d.department_name, d.department_code,
          p.program_name, p.program_code, p.duration_years,
          f.faculty_name,
          sg.scale_name as grading_system,
          sa.advisor_id, 
          CONCAT(a.first_name, ' ', a.last_name) as advisor_name,
          a.email as advisor_email, a.phone as advisor_phone,
          ar.gpa as current_cgpa,
          (SELECT COUNT(*) FROM course_registrations WHERE student_id = s.student_id AND session_year = '2025/2026') as current_courses
          FROM students s
          LEFT JOIN departments d ON s.department_id = d.department_id
          LEFT JOIN programs p ON s.program_id = p.program_id
          LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
          LEFT JOIN grade_scales sg ON p.grade_scale_id = sg.scale_id
          LEFT JOIN student_advisors sa ON s.student_id = sa.student_id AND sa.status = 'Active'
          LEFT JOIN academic_advisors a ON sa.advisor_id = a.advisor_id
          LEFT JOIN academic_records ar ON s.student_id = ar.student_id AND ar.session_year = '2025/2026' AND ar.semester = 1
          WHERE s.student_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_profile = $stmt->get_result()->fetch_assoc();

// Get next of kin information
$next_of_kin = null;
$table_check = $conn->query("SHOW TABLES LIKE 'next_of_kin'");
if($table_check && $table_check->num_rows > 0) {
    $kin_query = "SELECT * FROM next_of_kin WHERE student_id = ?";
    $stmt = $conn->prepare($kin_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $next_of_kin = $stmt->get_result()->fetch_assoc();
}

// Get medical records
$medical = null;
$table_check = $conn->query("SHOW TABLES LIKE 'medical_records'");
if($table_check && $table_check->num_rows > 0) {
    $medical_query = "SELECT * FROM medical_records WHERE student_id = ?";
    $stmt = $conn->prepare($medical_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $medical = $stmt->get_result()->fetch_assoc();
}

// Get user settings
$settings_query = "SELECT * FROM user_settings WHERE student_id = ?";
$stmt = $conn->prepare($settings_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();

// If no settings exist, create default
if(!$settings) {
    $insert = "INSERT INTO user_settings (student_id, email_notifications, sms_notifications, push_notifications, dark_mode, language) VALUES (?, 1, 0, 1, 0, 'en')";
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $settings = [
        'email_notifications' => 1,
        'sms_notifications' => 0,
        'push_notifications' => 1,
        'dark_mode' => 0,
        'language' => 'en'
    ];
}

// Handle profile update
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['update_profile'])) {
        $phone = $conn->real_escape_string($_POST['phone']);
        $address = $conn->real_escape_string($_POST['address']);
        $emergency_contact = $conn->real_escape_string($_POST['emergency_contact']);
        $emergency_name = $conn->real_escape_string($_POST['emergency_name']);
        
        $update = "UPDATE students SET phone = ?, address = ?, emergency_contact = ?, emergency_contact_name = ? WHERE student_id = ?";
        $stmt = $conn->prepare($update);
        $stmt->bind_param("ssssi", $phone, $address, $emergency_contact, $emergency_name, $student_id);
        
        if($stmt->execute()) {
            $success = "Profile updated successfully!";
            // Refresh student data
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $student_profile = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Error updating profile: " . $conn->error;
        }
    }
    
    if(isset($_POST['update_settings'])) {
        $email_notif = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notif = isset($_POST['sms_notifications']) ? 1 : 0;
        $push_notif = isset($_POST['push_notifications']) ? 1 : 0;
        $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
        $language = $conn->real_escape_string($_POST['language']);
        
        $update = "UPDATE user_settings SET email_notifications = ?, sms_notifications = ?, push_notifications = ?, dark_mode = ?, language = ? WHERE student_id = ?";
        $stmt = $conn->prepare($update);
        $stmt->bind_param("iiiisi", $email_notif, $sms_notif, $push_notif, $dark_mode, $language, $student_id);
        
        if($stmt->execute()) {
            $success = "Settings updated successfully!";
            if($dark_mode) {
                echo "<script>document.documentElement.setAttribute('data-theme', 'dark');</script>";
            }
        } else {
            $error = "Error updating settings: " . $conn->error;
        }
    }
    
    if(isset($_POST['update_next_of_kin'])) {
        $full_name = $conn->real_escape_string($_POST['kin_full_name']);
        $relationship = $conn->real_escape_string($_POST['kin_relationship']);
        $phone = $conn->real_escape_string($_POST['kin_phone']);
        $email = $conn->real_escape_string($_POST['kin_email']);
        $address = $conn->real_escape_string($_POST['kin_address']);
        
        $check = $conn->query("SELECT kin_id FROM next_of_kin WHERE student_id = $student_id");
        if($check && $check->num_rows > 0) {
            $update = "UPDATE next_of_kin SET full_name = ?, relationship = ?, phone = ?, email = ?, address = ? WHERE student_id = ?";
            $stmt = $conn->prepare($update);
            $stmt->bind_param("sssssi", $full_name, $relationship, $phone, $email, $address, $student_id);
        } else {
            $insert = "INSERT INTO next_of_kin (student_id, full_name, relationship, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert);
            $stmt->bind_param("isssss", $student_id, $full_name, $relationship, $phone, $email, $address);
        }
        
        if($stmt->execute()) {
            $success = "Next of kin information updated successfully!";
        } else {
            $error = "Error updating next of kin: " . $conn->error;
        }
    }
}

// Get profile completion percentage
$profile_fields = [
    'phone' => $student_profile['phone'],
    'address' => $student_profile['address'],
    'emergency_contact' => $student_profile['emergency_contact'],
    'date_of_birth' => $student_profile['date_of_birth'],
    'blood_group' => $student_profile['blood_group']
];
$completed_fields = 0;
foreach($profile_fields as $field) {
    if(!empty($field)) $completed_fields++;
}
$completion_percentage = $completed_fields > 0 ? round(($completed_fields / count($profile_fields)) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Student Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

<div class="fade-in">
    <!-- Alert Messages -->
    <?php if(isset($success)): ?>
    <div class="alert success">
        <i class="fas fa-check-circle"></i>
        <?php echo $success; ?>
    </div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
    <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <!-- Profile Header (Visible on page) -->
    <div class="profile-header">
        <div class="profile-cover">
            <div class="profile-avatar-large">
                <?php echo strtoupper(substr($student_profile['first_name'], 0, 1) . substr($student_profile['last_name'], 0, 1)); ?>
            </div>
            <div class="profile-title">
                <h1><?php echo htmlspecialchars($student_profile['first_name'] . ' ' . ($student_profile['middle_name'] ? $student_profile['middle_name'] . ' ' : '') . $student_profile['last_name']); ?></h1>
                <p class="matric-number"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student_profile['matric_number']); ?></p>
                <p class="program-info">
                    <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($student_profile['program_name']); ?> · 
                    Level <?php echo $student_profile['current_level']; ?>
                </p>
            </div>
            <div class="profile-actions">
                <button class="btn-primary" onclick="showEditModal('personal')">
                    <i class="fas fa-edit"></i> Edit Profile
                </button>
                <button class="btn-primary" onclick="downloadFullProfilePDF()" style="background: #2196f3;">
                    <i class="fas fa-download"></i> Download Full Profile
                </button>
                <button class="btn-primary" onclick="window.print()" style="background: #4caf50;">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
        
        <div class="profile-stats">
            <div class="stat-item">
                <span class="stat-value"><?php echo $student_profile['current_courses'] ?: 0; ?></span>
                <span class="stat-label">Current Courses</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?php echo number_format($student_profile['current_cgpa'] ?: 0, 2); ?></span>
                <span class="stat-label">CGPA</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?php echo $completion_percentage; ?>%</span>
                <span class="stat-label">Profile Complete</span>
            </div>
        </div>
        
        <div class="profile-progress">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- PDF Content Container (Hidden, used for PDF generation) -->


    <!-- Profile Tabs -->
    <div class="profile-tabs">
        <button class="tab-btn active" onclick="showTab('personal')"><i class="fas fa-user"></i> Personal</button>
        <button class="tab-btn" onclick="showTab('academic')"><i class="fas fa-graduation-cap"></i> Academic</button>
        <button class="tab-btn" onclick="showTab('contact')"><i class="fas fa-address-book"></i> Contact</button>
        <button class="tab-btn" onclick="showTab('nextofkin')"><i class="fas fa-users"></i> Next of Kin</button>
        <button class="tab-btn" onclick="showTab('medical')"><i class="fas fa-heartbeat"></i> Medical</button>
        <button class="tab-btn" onclick="showTab('settings')"><i class="fas fa-cog"></i> Settings</button>
    </div>

    <!-- Personal Info Tab -->
    <div id="personal" class="tab-content active">
        <div class="info-card">
            <div class="card-header">
                <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                <button class="btn-edit" onclick="showEditModal('personal')"><i class="fas fa-edit"></i> Edit</button>
            </div>
            <div class="info-grid">
                <div class="info-item"><span class="info-label"><i class="fas fa-user"></i> First Name</span><span class="info-value"><?php echo htmlspecialchars($student_profile['first_name']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-user"></i> Middle Name</span><span class="info-value"><?php echo htmlspecialchars($student_profile['middle_name'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-user"></i> Last Name</span><span class="info-value"><?php echo htmlspecialchars($student_profile['last_name']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-calendar"></i> Date of Birth</span><span class="info-value"><?php echo $student_profile['date_of_birth'] ? date('d F Y', strtotime($student_profile['date_of_birth'])) : '—'; ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-venus-mars"></i> Gender</span><span class="info-value"><?php echo htmlspecialchars($student_profile['gender'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-heart"></i> Marital Status</span><span class="info-value"><?php echo htmlspecialchars($student_profile['marital_status'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-flag"></i> Nationality</span><span class="info-value"><?php echo htmlspecialchars($student_profile['nationality'] ?: 'Nigerian'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-map-marker-alt"></i> State of Origin</span><span class="info-value"><?php echo htmlspecialchars($student_profile['state_of_origin'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-city"></i> LGA</span><span class="info-value"><?php echo htmlspecialchars($student_profile['lga'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-tint"></i> Blood Group</span><span class="info-value"><?php echo htmlspecialchars($student_profile['blood_group'] ?: '—'); ?></span></div>
            </div>
        </div>
    </div>

    <!-- Academic Info Tab -->
    <div id="academic" class="tab-content">
        <div class="info-card">
            <div class="card-header">
                <h3><i class="fas fa-university"></i> Academic Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-item"><span class="info-label"><i class="fas fa-id-card"></i> Matric Number</span><span class="info-value"><?php echo htmlspecialchars($student_profile['matric_number']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-book"></i> Program</span><span class="info-value"><?php echo htmlspecialchars($student_profile['program_name']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-code"></i> Program Code</span><span class="info-value"><?php echo htmlspecialchars($student_profile['program_code']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-building"></i> Department</span><span class="info-value"><?php echo htmlspecialchars($student_profile['department_name']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-chalkboard"></i> Faculty</span><span class="info-value"><?php echo htmlspecialchars($student_profile['faculty_name']); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-chart-line"></i> Current Level</span><span class="info-value"><?php echo $student_profile['current_level']; ?> Level</span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-calendar-alt"></i> Admission Year</span><span class="info-value"><?php echo $student_profile['admission_year']; ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-door-open"></i> Mode of Entry</span><span class="info-value"><?php echo htmlspecialchars($student_profile['mode_of_entry'] ?: 'UTME'); ?></span></div>
                <div class="info-item"><span class="info-label"><i class="fas fa-star"></i> Current CGPA</span><span class="info-value highlight"><?php echo number_format($student_profile['current_cgpa'] ?: 0, 2); ?></span></div>
            </div>

            <?php if($student_profile['advisor_name']): ?>
            <div class="advisor-section">
                <h4><i class="fas fa-chalkboard-teacher"></i> Academic Advisor</h4>
                <div class="advisor-card">
                    <div class="advisor-avatar"><?php echo strtoupper(substr($student_profile['advisor_name'], 0, 1)); ?></div>
                    <div class="advisor-info">
                        <p class="advisor-name"><?php echo htmlspecialchars($student_profile['advisor_name']); ?></p>
                        <p class="advisor-contact"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student_profile['advisor_email']); ?></p>
                        <p class="advisor-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($student_profile['advisor_phone']); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Contact Tab -->
    <div id="contact" class="tab-content">
        <div class="info-card">
            <div class="card-header"><h3><i class="fas fa-address-book"></i> Contact Information</h3></div>
            <form method="POST" action="">
                <div class="info-grid">
                    <div class="info-item"><span class="info-label"><i class="fas fa-envelope"></i> Email</span><span class="info-value"><?php echo htmlspecialchars($student_profile['email']); ?></span></div>
                    <div class="info-item"><span class="info-label"><i class="fas fa-phone"></i> Phone</span><input type="tel" name="phone" class="info-input" value="<?php echo htmlspecialchars($student_profile['phone'] ?: ''); ?>"></div>
                    <div class="info-item full-width"><span class="info-label"><i class="fas fa-home"></i> Address</span><textarea name="address" class="info-textarea"><?php echo htmlspecialchars($student_profile['address'] ?: ''); ?></textarea></div>
                    <div class="info-item"><span class="info-label"><i class="fas fa-user-friends"></i> Emergency Name</span><input type="text" name="emergency_name" class="info-input" value="<?php echo htmlspecialchars($student_profile['emergency_contact_name'] ?: ''); ?>"></div>
                    <div class="info-item"><span class="info-label"><i class="fas fa-phone-alt"></i> Emergency Contact</span><input type="tel" name="emergency_contact" class="info-input" value="<?php echo htmlspecialchars($student_profile['emergency_contact'] ?: ''); ?>"></div>
                </div>
                <div class="form-actions"><button type="submit" name="update_profile" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- Next of Kin Tab -->
    <div id="nextofkin" class="tab-content">
        <div class="info-card">
            <div class="card-header"><h3><i class="fas fa-users"></i> Next of Kin</h3><button class="btn-edit" onclick="showEditModal('kin')"><i class="fas fa-edit"></i> Edit</button></div>
            <?php if($next_of_kin): ?>
            <div class="info-grid">
                <div class="info-item"><span class="info-label">Full Name</span><span class="info-value"><?php echo htmlspecialchars($next_of_kin['full_name']); ?></span></div>
                <div class="info-item"><span class="info-label">Relationship</span><span class="info-value"><?php echo htmlspecialchars($next_of_kin['relationship']); ?></span></div>
                <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($next_of_kin['phone']); ?></span></div>
                <div class="info-item full-width"><span class="info-label">Address</span><span class="info-value"><?php echo htmlspecialchars($next_of_kin['address'] ?: '—'); ?></span></div>
            </div>
            <?php else: ?>
            <div class="empty-section"><i class="fas fa-users"></i><p>No next of kin information added yet</p><button class="btn-primary" onclick="showEditModal('kin')"><i class="fas fa-plus"></i> Add Next of Kin</button></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Medical Tab -->
    <div id="medical" class="tab-content">
        <div class="info-card">
            <div class="card-header"><h3><i class="fas fa-heartbeat"></i> Medical Information</h3></div>
            <div class="info-grid">
                <div class="info-item"><span class="info-label">Blood Group</span><span class="info-value"><?php echo htmlspecialchars($student_profile['blood_group'] ?: '—'); ?></span></div>
                <div class="info-item"><span class="info-label">Genotype</span><span class="info-value"><?php echo $medical ? htmlspecialchars($medical['genotype']) : '—'; ?></span></div>
                <div class="info-item"><span class="info-label">Allergies</span><span class="info-value"><?php echo $medical ? htmlspecialchars($medical['allergies'] ?: 'None') : '—'; ?></span></div>
                <div class="info-item"><span class="info-label">Disability</span><span class="info-value"><?php echo htmlspecialchars($student_profile['disability'] ?: 'None'); ?></span></div>
                <div class="info-item full-width"><span class="info-label">Medical Conditions</span><span class="info-value"><?php echo $medical ? htmlspecialchars($medical['conditions'] ?: 'None') : '—'; ?></span></div>
            </div>
        </div>
    </div>

    <!-- Settings Tab -->
    <div id="settings" class="tab-content">
        <div class="info-card">
            <div class="card-header"><h3><i class="fas fa-cog"></i> Settings</h3></div>
            <form method="POST" action="">
                <div class="settings-group">
                    <div class="setting-item"><div class="setting-info"><h4><i class="fas fa-envelope"></i> Email Notifications</h4><p>Receive updates via email</p></div><label class="switch"><input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="setting-item"><div class="setting-info"><h4><i class="fas fa-sms"></i> SMS Notifications</h4><p>Receive text message alerts</p></div><label class="switch"><input type="checkbox" name="sms_notifications" <?php echo $settings['sms_notifications'] ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="setting-item"><div class="setting-info"><h4><i class="fas fa-bell"></i> Push Notifications</h4><p>Browser notifications</p></div><label class="switch"><input type="checkbox" name="push_notifications" <?php echo $settings['push_notifications'] ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="setting-item"><div class="setting-info"><h4><i class="fas fa-moon"></i> Dark Mode</h4><p>Switch to dark theme</p></div><label class="switch"><input type="checkbox" name="dark_mode" <?php echo $settings['dark_mode'] ? 'checked' : ''; ?> onchange="toggleDarkMode(this.checked)"><span class="slider"></span></label></div>
                    <div class="setting-item"><div class="setting-info"><h4><i class="fas fa-language"></i> Language</h4><p>Select preferred language</p></div><select name="language" class="language-select"><option value="en" <?php echo $settings['language'] == 'en' ? 'selected' : ''; ?>>English</option><option value="ha" <?php echo $settings['language'] == 'ha' ? 'selected' : ''; ?>>Hausa</option><option value="ar" <?php echo $settings['language'] == 'ar' ? 'selected' : ''; ?>>Arabic</option></select></div>
                </div>
                <div class="form-actions"><button type="submit" name="update_settings" class="btn-primary"><i class="fas fa-save"></i> Save Settings</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 id="modalTitle">Edit Information</h2><button class="close-btn" onclick="closeModal()">&times;</button></div>
        <form id="editForm" method="POST" action="">
            <div id="modalBody" class="modal-body"></div>
            <div class="modal-footer"><button type="button" class="btn-outline" onclick="closeModal()">Cancel</button><button type="submit" class="btn-primary" id="modalSubmitBtn">Save Changes</button></div>
        </form>
    </div>
</div>

<!-- Include all CSS styles from previous version -->
<style>
    /* All styles from previous profile.php remain here */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; }
    .fade-in { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
    .alert.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
    .alert.error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
    .profile-header { background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); margin-bottom: 30px; overflow: hidden; }
    .profile-cover { background: linear-gradient(135deg, #2e7d32, #1b5e20); padding: 40px; display: flex; align-items: center; gap: 30px; flex-wrap: wrap; }
    .profile-avatar-large { width: 120px; height: 120px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #2e7d32; font-size: 48px; font-weight: 700; box-shadow: 0 4px 15px rgba(0,0,0,0.2); border: 4px solid white; }
    .profile-title { flex: 1; }
    .profile-title h1 { color: white; font-size: 28px; margin-bottom: 8px; }
    .matric-number { color: rgba(255,255,255,0.9); font-size: 14px; }
    .program-info { color: rgba(255,255,255,0.8); font-size: 13px; }
    .profile-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-primary { background: white; color: #2e7d32; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    .profile-stats { display: flex; justify-content: space-around; padding: 25px; background: white; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; gap: 20px; }
    .stat-item { text-align: center; }
    .stat-value { display: block; font-size: 28px; font-weight: 700; color: #1f2937; margin-bottom: 5px; }
    .stat-label { color: #6b7280; font-size: 13px; }
    .profile-progress { padding: 0 25px 25px; }
    .progress-bar { height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, #2e7d32, #4caf50); transition: width 0.5s ease; }
    .profile-tabs { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
    .tab-btn { background: white; border: 1px solid #e5e7eb; padding: 10px 20px; border-radius: 30px; font-weight: 500; color: #6b7280; cursor: pointer; transition: all 0.3s ease; }
    .tab-btn i { margin-right: 8px; }
    .tab-btn:hover { background: #e8f5e9; color: #2e7d32; border-color: #2e7d32; }
    .tab-btn.active { background: #2e7d32; color: white; border-color: #2e7d32; }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.5s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .info-card { background: white; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb; }
    .card-header h3 { color: #1f2937; font-size: 18px; }
    .card-header h3 i { color: #2e7d32; margin-right: 8px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
    .info-item { display: flex; flex-direction: column; gap: 5px; }
    .info-item.full-width { grid-column: 1 / -1; }
    .info-label { color: #6b7280; font-size: 12px; font-weight: 500; }
    .info-label i { width: 20px; color: #2e7d32; }
    .info-value { color: #1f2937; font-size: 15px; font-weight: 500; }
    .info-value.highlight { color: #2e7d32; font-size: 20px; font-weight: 700; }
    .info-input, .info-textarea { padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.3s ease; width: 100%; }
    .info-input:focus, .info-textarea:focus { outline: none; border-color: #2e7d32; }
    .info-textarea { min-height: 80px; resize: vertical; }
    .form-actions { margin-top: 25px; text-align: right; }
    .advisor-section { margin-top: 30px; padding-top: 20px; border-top: 2px solid #e5e7eb; }
    .advisor-card { display: flex; align-items: center; gap: 20px; padding: 20px; background: #f9fafb; border-radius: 12px; flex-wrap: wrap; }
    .advisor-avatar { width: 60px; height: 60px; background: #2e7d32; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: 700; }
    .advisor-info { flex: 1; }
    .advisor-name { font-weight: 600; color: #1f2937; margin-bottom: 4px; }
    .advisor-contact { display: flex; align-items: center; gap: 8px; color: #6b7280; font-size: 13px; }
    .empty-section { text-align: center; padding: 40px 20px; }
    .empty-section i { font-size: 60px; color: #d1d5db; margin-bottom: 15px; }
    .settings-group { margin-bottom: 30px; }
    .setting-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; gap: 15px; }
    .setting-info h4 { font-size: 14px; margin-bottom: 4px; }
    .setting-info p { color: #6b7280; font-size: 12px; margin: 0; }
    .switch { position: relative; display: inline-block; width: 50px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
    .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #2e7d32; }
    input:checked + .slider:before { transform: translateX(26px); }
    .language-select { padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; background: white; min-width: 150px; }
    .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal.show { display: flex; }
    .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
    .modal-header { padding: 20px 25px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; }
    .modal-header h2 { font-size: 20px; }
    .close-btn { background: none; border: none; font-size: 28px; cursor: pointer; color: #6b7280; }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e5e7eb; display: flex; gap: 15px; justify-content: flex-end; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 8px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    @media (max-width: 768px) { .profile-cover { flex-direction: column; text-align: center; } .info-grid { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
    [data-theme="dark"] body { background: #1a1a1a; }
    [data-theme="dark"] .profile-header, [data-theme="dark"] .info-card, [data-theme="dark"] .tab-btn { background: #2d2d2d; color: #e0e0e0; }
    [data-theme="dark"] .profile-title h1, [data-theme="dark"] .info-value, [data-theme="dark"] .card-header h3 { color: #ffffff; }
    [data-theme="dark"] .stat-value { color: #ffffff; }
</style>

<script>
function showTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
}

function showEditModal(type) {
    const modal = document.getElementById('editModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    
    let content = '';
    
    if(type === 'personal') {
        modalTitle.textContent = 'Edit Personal Information';
        content = `
            <div class="form-row"><div class="form-group"><label>First Name</label><input type="text" value="<?php echo htmlspecialchars($student_profile['first_name']); ?>" readonly disabled><small>Contact admin to change</small></div>
            <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" value="<?php echo htmlspecialchars($student_profile['middle_name'] ?: ''); ?>"></div></div>
            <div class="form-group"><label>Last Name</label><input type="text" value="<?php echo htmlspecialchars($student_profile['last_name']); ?>" readonly disabled></div>
            <div class="form-row"><div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?php echo $student_profile['date_of_birth']; ?>"></div>
            <div class="form-group"><label>Gender</label><select name="gender"><option value="Male" <?php echo $student_profile['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option><option value="Female" <?php echo $student_profile['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option></select></div></div>
            <div class="form-row"><div class="form-group"><label>Marital Status</label><select name="marital_status"><option value="Single">Single</option><option value="Married">Married</option><option value="Divorced">Divorced</option><option value="Widowed">Widowed</option></select></div>
            <div class="form-group"><label>Blood Group</label><select name="blood_group"><option value="">Select</option><option value="A+">A+</option><option value="A-">A-</option><option value="B+">B+</option><option value="B-">B-</option><option value="O+">O+</option><option value="O-">O-</option><option value="AB+">AB+</option><option value="AB-">AB-</option></select></div></div>
            <div class="form-row"><div class="form-group"><label>Nationality</label><input type="text" name="nationality" value="Nigerian"></div>
            <div class="form-group"><label>State of Origin</label><input type="text" name="state_of_origin" value="<?php echo htmlspecialchars($student_profile['state_of_origin'] ?: ''); ?>"></div></div>
            <div class="form-group"><label>LGA</label><input type="text" name="lga" value="<?php echo htmlspecialchars($student_profile['lga'] ?: ''); ?>"></div>
        `;
        document.getElementById('modalSubmitBtn').onclick = function() {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'update_profile';
            input.value = '1';
            document.getElementById('editForm').appendChild(input);
            document.getElementById('editForm').submit();
        };
    } else if(type === 'kin') {
        modalTitle.textContent = 'Edit Next of Kin';
        content = `
            <div class="form-group"><label>Full Name</label><input type="text" name="kin_full_name" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['full_name']) : ''; ?>" required></div>
            <div class="form-group"><label>Relationship</label><select name="kin_relationship" required><option value="">Select</option><option value="Father">Father</option><option value="Mother">Mother</option><option value="Brother">Brother</option><option value="Sister">Sister</option><option value="Spouse">Spouse</option><option value="Guardian">Guardian</option></select></div>
            <div class="form-group"><label>Phone Number</label><input type="tel" name="kin_phone" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['phone']) : ''; ?>" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="kin_email" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['email'] ?: '') : ''; ?>"></div>
            <div class="form-group"><label>Address</label><textarea name="kin_address" rows="3"><?php echo $next_of_kin ? htmlspecialchars($next_of_kin['address'] ?: '') : ''; ?></textarea></div>
        `;
        document.getElementById('modalSubmitBtn').onclick = function() {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'update_next_of_kin';
            input.value = '1';
            document.getElementById('editForm').appendChild(input);
            document.getElementById('editForm').submit();
        };
    }
    
    modalBody.innerHTML = content;
    modal.classList.add('show');
}

function closeModal() {
    document.getElementById('editModal').classList.remove('show');
}

function toggleDarkMode(enabled) {
    if(enabled) {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('darkMode', 'enabled');
    } else {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('darkMode', 'disabled');
    }
}
<script>
function downloadFullProfilePDF() {
    // Create a new div for PDF generation (not hidden with display:none)
    const pdfWindow = window.open('', '_blank');
    
    const student = <?php echo json_encode($student_profile); ?>;
    const nextOfKin = <?php echo json_encode($next_of_kin); ?>;
    const medical = <?php echo json_encode($medical); ?>;
    
    const pdfHTML = `
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Student Bio Data - ${student.matric_number}</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                background: white;
                padding: 40px;
            }
            
            .pdf-profile-container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
            }
            
            .pdf-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid #2e7d32;
            }
            
            .pdf-logo i {
                font-size: 48px;
                color: #2e7d32;
            }
            
            .pdf-university {
                text-align: center;
            }
            
            .pdf-university h1 {
                color: #2e7d32;
                font-size: 22px;
                margin: 0 0 5px;
                letter-spacing: 2px;
            }
            
            .pdf-university h2 {
                color: #333;
                font-size: 16px;
                margin: 0;
            }
            
            .pdf-university p {
                color: #666;
                font-size: 12px;
                margin: 5px 0 0;
            }
            
            .pdf-id {
                text-align: right;
            }
            
            .pdf-id-label {
                background: #2e7d32;
                color: white;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: bold;
            }
            
            .pdf-id-number {
                font-size: 16px;
                font-weight: bold;
                margin-top: 5px;
                color: #333;
            }
            
            .pdf-photo-section {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding: 15px;
                background: #f9f9f9;
                border-radius: 10px;
            }
            
            .pdf-photo-placeholder {
                width: 120px;
                height: 140px;
                border: 2px dashed #ccc;
                text-align: center;
                padding: 20px;
                background: white;
                border-radius: 8px;
            }
            
            .pdf-photo-placeholder i {
                font-size: 48px;
                color: #ccc;
            }
            
            .pdf-photo-placeholder p {
                margin: 10px 0 0;
                font-size: 11px;
                color: #999;
            }
            
            .pdf-student-badge {
                background: #e8f5e9;
                padding: 10px 20px;
                border-radius: 8px;
                color: #2e7d32;
            }
            
            .pdf-section {
                margin-bottom: 25px;
                page-break-inside: avoid;
            }
            
            .pdf-section-title {
                background: linear-gradient(135deg, #2e7d32, #1b5e20);
                color: white;
                padding: 10px 15px;
                border-radius: 8px;
                margin-bottom: 15px;
                font-weight: bold;
                font-size: 14px;
            }
            
            .pdf-section-title i {
                margin-right: 10px;
            }
            
            .pdf-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .pdf-table tr {
                border-bottom: 1px solid #eee;
            }
            
            .pdf-table td {
                padding: 10px 8px;
                vertical-align: top;
            }
            
            .pdf-label {
                width: 140px;
                font-weight: 600;
                color: #555;
                font-size: 12px;
            }
            
            .pdf-value {
                color: #333;
                font-size: 13px;
            }
            
            .pdf-highlight {
                color: #2e7d32;
                font-weight: bold;
                font-size: 16px;
            }
            
            .pdf-declaration {
                margin: 30px 0;
                padding: 20px;
                background: #f5f5f5;
                border-radius: 10px;
            }
            
            .pdf-declaration-text {
                margin-bottom: 25px;
                font-size: 12px;
                line-height: 1.5;
                color: #555;
            }
            
            .pdf-signatures {
                display: flex;
                justify-content: space-between;
                gap: 30px;
            }
            
            .pdf-signature-line {
                text-align: center;
                flex: 1;
            }
            
            .signature-placeholder {
                height: 50px;
                border-bottom: 1px solid #333;
                margin-bottom: 10px;
            }
            
            .pdf-signature-line p {
                margin: 5px 0;
                font-size: 11px;
                color: #666;
            }
            
            .pdf-signature-line .date {
                font-size: 10px;
                color: #999;
            }
            
            .pdf-footer {
                margin-top: 30px;
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                font-size: 10px;
                color: #999;
            }
            
            @media print {
                body {
                    padding: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="pdf-profile-container">
            <!-- Header -->
            <div class="pdf-header">
                <div class="pdf-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="pdf-university">
                    <h1>STUDENT BIO DATA FORM</h1>
                    <h2>${student.faculty_name || 'University'} University</h2>
                    <p>Official Student Information Document</p>
                </div>
                <div class="pdf-id">
                    <div class="pdf-id-label">STUDENT ID</div>
                    <div class="pdf-id-number">${student.matric_number}</div>
                </div>
            </div>

            <!-- Passport Photo Section -->
            <div class="pdf-photo-section">
                <div class="pdf-photo-placeholder">
                    <i class="fas fa-user-graduate"></i>
                    <p>Passport Photo</p>
                </div>
                <div class="pdf-student-badge">
                    <strong>Student Type:</strong> ${student.student_type || 'Regular'}
                </div>
            </div>

            <!-- Personal Information Section -->
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-user"></i> PERSONAL INFORMATION
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Full Name:</td>
                        <td class="pdf-value">${student.first_name || ''} ${student.middle_name || ''} ${student.last_name || ''}</td>
                        <td class="pdf-label">Date of Birth:</td>
                        <td class="pdf-value">${student.date_of_birth ? new Date(student.date_of_birth).toLocaleDateString('en-GB', {day:'numeric', month:'long', year:'numeric'}) : '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Gender:</td>
                        <td class="pdf-value">${student.gender || '—'}</td>
                        <td class="pdf-label">Marital Status:</td>
                        <td class="pdf-value">${student.marital_status || 'Single'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Nationality:</td>
                        <td class="pdf-value">${student.nationality || 'Nigerian'}</td>
                        <td class="pdf-label">State of Origin:</td>
                        <td class="pdf-value">${student.state_of_origin || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">LGA:</td>
                        <td class="pdf-value">${student.lga || '—'}</td>
                        <td class="pdf-label">Blood Group:</td>
                        <td class="pdf-value">${student.blood_group || '—'}</td>
                    </tr>
                </table>
            </div>

            <!-- Contact Information Section -->
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-address-card"></i> CONTACT INFORMATION
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Email Address:</td>
                        <td class="pdf-value">${student.email || '—'}</td>
                        <td class="pdf-label">Phone Number:</td>
                        <td class="pdf-value">${student.phone || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Emergency Contact:</td>
                        <td class="pdf-value">${student.emergency_contact || '—'}</td>
                        <td class="pdf-label">Emergency Name:</td>
                        <td class="pdf-value">${student.emergency_contact_name || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label" colspan="3">Residential Address:</td>
                        <td class="pdf-value">${student.address || '—'}</td>
                    </tr>
                </table>
            </div>

            <!-- Academic Information Section -->
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-graduation-cap"></i> ACADEMIC INFORMATION
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Matric Number:</td>
                        <td class="pdf-value">${student.matric_number}</td>
                        <td class="pdf-label">Program:</td>
                        <td class="pdf-value">${student.program_name || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Program Code:</td>
                        <td class="pdf-value">${student.program_code || '—'}</td>
                        <td class="pdf-label">Department:</td>
                        <td class="pdf-value">${student.department_name || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Faculty:</td>
                        <td class="pdf-value">${student.faculty_name || '—'}</td>
                        <td class="pdf-label">Current Level:</td>
                        <td class="pdf-value">${student.current_level || '—'} Level</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Admission Year:</td>
                        <td class="pdf-value">${student.admission_year || '—'}</td>
                        <td class="pdf-label">Mode of Entry:</td>
                        <td class="pdf-value">${student.mode_of_entry || 'UTME'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">JAMB Reg Number:</td>
                        <td class="pdf-value">${student.jamb_reg_number || '—'}</td>
                        <td class="pdf-label">Duration:</td>
                        <td class="pdf-value">${student.duration_years || '4'} Years</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Current CGPA:</td>
                        <td class="pdf-value pdf-highlight">${(student.current_cgpa || 0).toFixed(2)}</td>
                        <td class="pdf-label">Grading System:</td>
                        <td class="pdf-value">${student.grading_system || '5-Point Scale'}</td>
                    </tr>
                </table>
            </div>

            <!-- Next of Kin Section -->
            ${nextOfKin ? `
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-users"></i> NEXT OF KIN INFORMATION
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Full Name:</td>
                        <td class="pdf-value">${nextOfKin.full_name || '—'}</td>
                        <td class="pdf-label">Relationship:</td>
                        <td class="pdf-value">${nextOfKin.relationship || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Phone Number:</td>
                        <td class="pdf-value">${nextOfKin.phone || '—'}</td>
                        <td class="pdf-label">Email:</td>
                        <td class="pdf-value">${nextOfKin.email || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label" colspan="3">Address:</td>
                        <td class="pdf-value">${nextOfKin.address || '—'}</td>
                    </tr>
                </table>
            </div>
            ` : ''}

            <!-- Medical Information Section -->
            ${(medical || student.blood_group) ? `
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-heartbeat"></i> MEDICAL INFORMATION
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Blood Group:</td>
                        <td class="pdf-value">${student.blood_group || '—'}</td>
                        <td class="pdf-label">Genotype:</td>
                        <td class="pdf-value">${medical?.genotype || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Allergies:</td>
                        <td class="pdf-value" colspan="3">${medical?.allergies || 'None'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Medical Conditions:</td>
                        <td class="pdf-value" colspan="3">${medical?.conditions || 'None'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Disability:</td>
                        <td class="pdf-value" colspan="3">${student.disability || 'None'}</td>
                    </tr>
                </table>
            </div>
            ` : ''}

            <!-- Academic Advisor Section -->
            ${student.advisor_name ? `
            <div class="pdf-section">
                <div class="pdf-section-title">
                    <i class="fas fa-chalkboard-teacher"></i> ACADEMIC ADVISOR
                </div>
                <table class="pdf-table">
                    <tr>
                        <td class="pdf-label">Name:</td>
                        <td class="pdf-value">${student.advisor_name}</td>
                        <td class="pdf-label">Email:</td>
                        <td class="pdf-value">${student.advisor_email || '—'}</td>
                    </tr>
                    <tr>
                        <td class="pdf-label">Phone:</td>
                        <td class="pdf-value" colspan="3">${student.advisor_phone || '—'}</td>
                    </tr>
                </table>
            </div>
            ` : ''}

            <!-- Declaration and Signature Section -->
            <div class="pdf-declaration">
                <div class="pdf-declaration-text">
                    <p><strong>DECLARATION:</strong> I hereby declare that all the information provided above is true and correct to the best of my knowledge. I understand that any false information may lead to disciplinary action.</p>
                </div>
                <div class="pdf-signatures">
                    <div class="pdf-signature-line">
                        <div class="signature-placeholder"></div>
                        <p>Student's Signature</p>
                        <p class="date">Date: ${new Date().toLocaleDateString()}</p>
                    </div>
                    <div class="pdf-signature-line">
                        <div class="signature-placeholder"></div>
                        <p>Academic Advisor's Signature</p>
                    </div>
                    <div class="pdf-signature-line">
                        <div class="signature-placeholder"></div>
                        <p>Registrar's Signature/Stamp</p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="pdf-footer">
                <p>This is a computer-generated document. For official use only.</p>
                <p>Generated on: ${new Date().toLocaleString()} | Document ID: ${student.matric_number}_${new Date().toISOString().slice(0,10).replace(/-/g, '')}</p>
            </div>
        </div>
        
        <script>
            // Auto print and close after load
            window.onload = function() {
                window.print();
                setTimeout(() => window.close(), 1000);
            };
        <\/script>
    </body>
    </html>
    `;
    
    // Write to new window and print/save
    pdfWindow.document.write(pdfHTML);
    pdfWindow.document.close();
}
</script>

if(localStorage.getItem('darkMode') === 'enabled') {
    document.documentElement.setAttribute('data-theme', 'dark');
    const darkModeCheckbox = document.querySelector('input[name="dark_mode"]');
    if(darkModeCheckbox) darkModeCheckbox.checked = true;
}

window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target == modal) closeModal();
}
</script>

<?php require_once 'includes/footer.php'; ?>