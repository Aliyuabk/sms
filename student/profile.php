<?php
ob_start();
require_once 'includes/header.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    ob_end_clean();
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$success = null;
$error = null;

// ─── Handle Profile Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $emergency_contact = $_POST['emergency_contact'] ?? '';
    $emergency_name = $_POST['emergency_name'] ?? '';

    $update = "UPDATE students SET phone = ?, address = ?, emergency_contact = ?, emergency_contact_name = ? WHERE student_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ssssi", $phone, $address, $emergency_contact, $emergency_name, $student_id);

    if ($stmt->execute()) {
        $success = "Profile updated successfully!";
    } else {
        $error = "Error updating profile: " . $conn->error;
    }
}

// ─── Handle Bio-Data Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_biodata'])) {
    $date_of_birth = $_POST['date_of_birth'] ?? null;
    $marital_status = $_POST['marital_status'] ?? 'Single';

    $update = "UPDATE students SET date_of_birth = ?, marital_status = ? WHERE student_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ssi", $date_of_birth, $marital_status, $student_id);

    if ($stmt->execute()) {
        $success = "Bio-Data updated successfully!";
        // Refresh student profile data
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
    } else {
        $error = "Error updating bio-data: " . $conn->error;
    }
}

// ─── Handle Settings Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $email_notif = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notif = isset($_POST['sms_notifications']) ? 1 : 0;
    $push_notif = isset($_POST['push_notifications']) ? 1 : 0;
    $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
    $language = $_POST['language'] ?? 'en';

    $update = "UPDATE user_settings SET email_notifications = ?, sms_notifications = ?, push_notifications = ?, dark_mode = ?, language = ? WHERE student_id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("iiiisi", $email_notif, $sms_notif, $push_notif, $dark_mode, $language, $student_id);

    if ($stmt->execute()) {
        $success = "Settings updated successfully!";
    } else {
        $error = "Error updating settings: " . $conn->error;
    }
}

// ─── Handle Next of Kin Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_next_of_kin'])) {
    $full_name = $_POST['kin_full_name'] ?? '';
    $relationship = $_POST['kin_relationship'] ?? '';
    $kin_phone = $_POST['kin_phone'] ?? '';
    $kin_email = $_POST['kin_email'] ?? '';
    $kin_address = $_POST['kin_address'] ?? '';

    $check = $conn->prepare("SELECT kin_id FROM next_of_kin WHERE student_id = ?");
    $check->bind_param("i", $student_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $update = "UPDATE next_of_kin SET full_name = ?, relationship = ?, phone = ?, email = ?, address = ? WHERE student_id = ?";
        $stmt = $conn->prepare($update);
        $stmt->bind_param("sssssi", $full_name, $relationship, $kin_phone, $kin_email, $kin_address, $student_id);
    } else {
        $insert = "INSERT INTO next_of_kin (student_id, full_name, relationship, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param("isssss", $student_id, $full_name, $relationship, $kin_phone, $kin_email, $kin_address);
    }
    $check->close();

    if ($stmt->execute()) {
        $success = "Next of kin information updated successfully!";
    } else {
        $error = "Error updating next of kin: " . $conn->error;
    }
}

// ─── Handle Parents Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_parents'])) {
    $father_name = $_POST['father_name'] ?? '';
    $father_occupation = $_POST['father_occupation'] ?? '';
    $father_phone = $_POST['father_phone'] ?? '';
    $mother_name = $_POST['mother_name'] ?? '';
    $mother_occupation = $_POST['mother_occupation'] ?? '';
    $mother_phone = $_POST['mother_phone'] ?? '';
    $guardian_name = $_POST['guardian_name'] ?? '';
    $guardian_phone = $_POST['guardian_phone'] ?? '';

    $check = $conn->prepare("SELECT parent_id FROM parents WHERE student_id = ?");
    $check->bind_param("i", $student_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $update = "UPDATE parents SET 
                    father_name = ?, father_occupation = ?, father_phone = ?,
                    mother_name = ?, mother_occupation = ?, mother_phone = ?,
                    guardian_name = ?, guardian_phone = ?
                    WHERE student_id = ?";
        $stmt = $conn->prepare($update);
        $stmt->bind_param("ssssssssi", $father_name, $father_occupation, $father_phone,
                          $mother_name, $mother_occupation, $mother_phone,
                          $guardian_name, $guardian_phone, $student_id);
    } else {
        $insert = "INSERT INTO parents (student_id, father_name, father_occupation, father_phone,
                    mother_name, mother_occupation, mother_phone, guardian_name, guardian_phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param("issssssss", $student_id, $father_name, $father_occupation, $father_phone,
                          $mother_name, $mother_occupation, $mother_phone,
                          $guardian_name, $guardian_phone);
    }
    $check->close();

    if ($stmt->execute()) {
        $success = "Parents information updated successfully!";
        // Refresh parents data
        $parents_query = "SELECT * FROM parents WHERE student_id = ?";
        $stmt = $conn->prepare($parents_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $parents = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Error updating parents info: " . $conn->error;
    }
}

// ─── Handle Medical Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_medical'])) {
    $blood_group = $_POST['blood_group'] ?? '';
    $genotype = $_POST['genotype'] ?? '';
    $allergies = $_POST['allergies'] ?? '';
    $conditions = $_POST['conditions'] ?? '';
    $disability = $_POST['disability'] ?? '';

    $update_student = "UPDATE students SET blood_group = ? WHERE student_id = ?";
    $stmt = $conn->prepare($update_student);
    $stmt->bind_param("si", $blood_group, $student_id);
    $stmt->execute();

    $check = $conn->prepare("SELECT record_id FROM medical_records WHERE student_id = ?");
    $check->bind_param("i", $student_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $update = "UPDATE medical_records SET genotype = ?, allergies = ?, conditions = ? WHERE student_id = ?";
        $stmt = $conn->prepare($update);
        $stmt->bind_param("sssi", $genotype, $allergies, $conditions, $student_id);
    } else {
        $insert = "INSERT INTO medical_records (student_id, genotype, allergies, conditions) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param("isss", $student_id, $genotype, $allergies, $conditions);
    }
    $check->close();

    if ($stmt->execute()) {
        $success = "Medical information updated successfully!";
    } else {
        $error = "Error updating medical info: " . $conn->error;
    }
}

// ─── Handle Photo Upload ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo']) && isset($_FILES['photo'])) {
    $upload_dir = 'uploads/students/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $file = $_FILES['photo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($ext, $allowed) && $file['size'] <= 2097152) {
        $filename = 'student_' . $student_id . '_' . time() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $conn->prepare("UPDATE students SET photo = ? WHERE student_id = ?");
            $stmt->bind_param("si", $filepath, $student_id);
            $stmt->execute();
            $success = "Photo uploaded successfully!";
        } else {
            $error = "Failed to upload photo.";
        }
    } else {
        $error = "Invalid file. Use JPG/PNG under 2MB.";
    }
}

// ─── Handle Signature Upload ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_signature']) && isset($_FILES['signature'])) {
    $upload_dir = 'uploads/signatures/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $file = $_FILES['signature'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($ext, $allowed) && $file['size'] <= 1048576) {
        $filename = 'sig_' . $student_id . '_' . time() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $conn->prepare("UPDATE students SET signature = ? WHERE student_id = ?");
            $stmt->bind_param("si", $filepath, $student_id);
            $stmt->execute();
            $success = "Signature uploaded successfully!";
        } else {
            $error = "Failed to upload signature.";
        }
    } else {
        $error = "Invalid file. Use JPG/PNG under 1MB.";
    }
}


// ─── Fetch Student Profile ───
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

// ─── Fetch Related Data ───
$next_of_kin = null;
$table_check = $conn->query("SHOW TABLES LIKE 'next_of_kin'");
if ($table_check && $table_check->num_rows > 0) {
    $kin_query = "SELECT * FROM next_of_kin WHERE student_id = ?";
    $stmt = $conn->prepare($kin_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $next_of_kin = $stmt->get_result()->fetch_assoc();
}

$medical = null;
$table_check = $conn->query("SHOW TABLES LIKE 'medical_records'");
if ($table_check && $table_check->num_rows > 0) {
    $medical_query = "SELECT * FROM medical_records WHERE student_id = ?";
    $stmt = $conn->prepare($medical_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $medical = $stmt->get_result()->fetch_assoc();
}

$parents = null;
$table_check = $conn->query("SHOW TABLES LIKE 'parents'");
if ($table_check && $table_check->num_rows > 0) {
    $parents_query = "SELECT * FROM parents WHERE student_id = ?";
    $stmt = $conn->prepare($parents_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $parents = $stmt->get_result()->fetch_assoc();
}

// ─── Fetch or Create User Settings ───
$settings_query = "SELECT * FROM user_settings WHERE student_id = ?";
$stmt = $conn->prepare($settings_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();

if (!$settings) {
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

$photo_path = $student_profile['photo'] ?? '';
$signature_path = $student_profile['signature'] ?? '';
$current_session = "2025/2026";

// ─── Prepare data for PDF generation (same pattern as course page) ───
$pdf_student = [
    'first_name' => $student_profile['first_name'] ?? '',
    'middle_name' => $student_profile['middle_name'] ?? '',
    'last_name' => $student_profile['last_name'] ?? '',
    'matric_number' => $student_profile['matric_number'] ?? '',
    'date_of_birth' => $student_profile['date_of_birth'] ?? '',
    'gender' => $student_profile['gender'] ?? '',
    'nationality' => $student_profile['nationality'] ?? 'Nigerian',
    'state_of_origin' => $student_profile['state_of_origin'] ?? '',
    'lga' => $student_profile['lga'] ?? '',
    'blood_group' => $student_profile['blood_group'] ?? '',
    'disability' => $student_profile['disability'] ?? '',
    'marital_status' => $student_profile['marital_status'] ?: 'Single',
    'department_name' => $student_profile['department_name'] ?? '',
    'faculty_name' => $student_profile['faculty_name'] ?? '',
    'program_name' => $student_profile['program_name'] ?? '',
    'current_level' => $student_profile['current_level'] ?? '',
    'admission_year' => $student_profile['admission_year'] ?? '',
    'mode_of_entry' => $student_profile['mode_of_entry'] ?: 'UTME',
    'jamb_reg_number' => $student_profile['jamb_reg_number'] ?? '',
    'duration_years' => $student_profile['duration_years'] ?? '',
    'grading_system' => $student_profile['grading_system'] ?: '5-Point Scale',
    'student_type' => $student_profile['student_type'] ?: 'Full Time',
    'email' => $student_profile['email'] ?? '',
    'phone' => $student_profile['phone'] ?? '',
    'address' => $student_profile['address'] ?? '',
    'emergency_contact' => $student_profile['emergency_contact'] ?? '',
    'emergency_contact_name' => $student_profile['emergency_contact_name'] ?? ''
];

$pdf_kin = [
    'full_name' => $next_of_kin['full_name'] ?? '',
    'relationship' => $next_of_kin['relationship'] ?? '',
    'phone' => $next_of_kin['phone'] ?? '',
    'email' => $next_of_kin['email'] ?? '',
    'address' => $next_of_kin['address'] ?? ''
];

$pdf_medical = [
    'genotype' => $medical['genotype'] ?? '',
    'allergies' => $medical['allergies'] ?? '',
    'conditions' => $medical['conditions'] ?? ''
];

$pdf_parents = [
    'father_name' => $parents['father_name'] ?? '',
    'father_occupation' => $parents['father_occupation'] ?? '',
    'father_phone' => $parents['father_phone'] ?? '',
    'mother_name' => $parents['mother_name'] ?? '',
    'mother_occupation' => $parents['mother_occupation'] ?? '',
    'mother_phone' => $parents['mother_phone'] ?? '',
    'guardian_name' => $parents['guardian_name'] ?? '',
    'guardian_phone' => $parents['guardian_phone'] ?? ''
];
?>

<style>
:root {
    --primary-color: #3f749c;
    --primary-dark: #2a5a7a;
    --primary-light: #5a9bc4;
    --primary-soft: #e8f2f8;
    --secondary-color: #c5ea4f;
    --accent-color: #d4f07a;
    --danger-color: #f44336;
    --warning-color: #ff9800;
    --success-color: #7cb342;
    --text-dark: #2c3e50;
    --text-light: #7f8c8d;
    --white: #ffffff;
    --gray-100: #f8f9fa;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
    --shadow: 0 4px 6px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
    --transition: all 0.3s ease;
}

.profile-page {
    max-width: 1200px;
    margin: 0 auto;
    padding-left: 130px;
    padding-right: 30px;
}

/* Alert Messages */
.alert {
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.5s ease;
    font-size: 14px;
}
.alert.success {
    background: #e8f5e9;
    color: #2e7d32;
    border-left: 4px solid var(--success-color);
}
.alert.error {
    background: #ffebee;
    color: #c62828;
    border-left: 4px solid var(--danger-color);
}
@keyframes slideIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Session Banner */
.session-banner {
    background: var(--primary-color);
    color: white;
    padding: 12px 24px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
    font-size: 14px;
    font-weight: 500;
}
.session-banner i {
    font-size: 16px;
}

/* Profile Layout */
.profile-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 50px;
    align-items: start;
}

.container {
    padding: 20px;
    background: var(--gray-100);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}

/* Left Card */
.profile-card {
    background: var(--white);
    border-radius: 16px;
    box-shadow: var(--shadow);
    padding: 30px;
    text-align: center;
    position: sticky;
    top: 20px;
}

.photo-container {
    position: relative;
    width: 140px;
    height: 140px;
    margin: 0 auto 20px;
}
.profile-photo {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--primary-soft);
    background: var(--gray-100);
}
.profile-photo-placeholder {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: var(--primary-soft);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color);
    font-size: 48px;
    font-weight: 700;
    border: 4px solid var(--primary-soft);
    margin: 0 auto 20px;
}
.upload-btn {
    position: absolute;
    bottom: 5px;
    right: 5px;
    background: var(--primary-color);
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 3px solid white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    transition: var(--transition);
}
.upload-btn:hover {
    background: var(--primary-dark);
    transform: scale(1.1);
}
.upload-btn input {
    display: none;
}

.student-name {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 6px;
}
.matric-display {
    font-size: 14px;
    color: var(--text-light);
    margin-bottom: 8px;
    font-family: monospace;
}
.program-display {
    font-size: 13px;
    color: var(--primary-color);
    font-weight: 500;
}

/* Right Side Accordions */
.accordion-container {
    display: flex;
    flex-direction: column;
    gap: 12px; 
}

.accordion-item {
    background: var(--white);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: var(--transition);
}
.accordion-item:hover {
    box-shadow: var(--shadow);
}

.accordion-header {
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    background: var(--white);
    border: none;
    width: 100%;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: var(--transition);
}
.accordion-header:hover {
    background: var(--primary-soft);
}
.accordion-header i:first-child {
    margin-right: 12px;
    color: var(--primary-color);
    font-size: 16px;
}
.accordion-header .chevron {
    transition: transform 0.3s ease;
    color: var(--gray-500);
    font-size: 12px;
}
.accordion-item.active .chevron {
    transform: rotate(180deg);
}

.accordion-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease, padding 0.4s ease;
}
.accordion-item.active .accordion-body {
    max-height: 2000px;
    padding: 0 24px 24px;
}

/* Form Styles inside accordions */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.info-grid.full {
    grid-template-columns: 1fr;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.form-group .value-display {
    font-size: 15px;
    color: var(--text-dark);
    font-weight: 500;
    padding: 10px 0;
}
.form-group input,
.form-group select,
.form-group textarea {
    padding: 10px 14px;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    font-size: 14px;
    color: var(--text-dark);
    transition: var(--transition);
    background: var(--white);
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px var(--primary-soft);
}
.form-group input:read-only,
.form-group input:disabled {
    background: var(--gray-100);
    color: var(--gray-500);
    cursor: not-allowed;
}
.form-group textarea {
    min-height: 80px;
    resize: vertical;
}
.form-group small {
    font-size: 11px;
    color: var(--gray-500);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 24px;
}
.btn {
    padding: 14px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: var(--transition);
    border: none;
    text-decoration: none;
}
.btn-primary {
    background: var(--primary-color);
    color: white;
}
.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}
.btn-outline {
    background: transparent;
    color: var(--primary-color);
    border: 2px solid var(--primary-color);
}
.btn-outline:hover {
    background: var(--primary-soft);
}
.btn-secondary {
    background: var(--secondary-color);
    color: var(--text-dark);
}
.btn-secondary:hover {
    background: var(--accent-color);
}

/* Settings switches */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
.setting-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--gray-200);
}
.setting-row:last-child {
    border-bottom: none;
}
.setting-info h4 {
    font-size: 14px;
    color: var(--text-dark);
    margin-bottom: 4px;
}
.setting-info p {
    font-size: 12px;
    color: var(--text-light);
    margin: 0;
}

.switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: var(--gray-300);
    transition: .3s;
    border-radius: 24px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background: white;
    transition: .3s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
input:checked + .slider {
    background: var(--primary-color);
}
input:checked + .slider:before {
    transform: translateX(20px);
}

/* Print Styles */
@media print {
    .no-print {
        display: none !important;
    }
    .profile-layout {
        grid-template-columns: 1fr;
    }
    .profile-card {
        position: static;
        box-shadow: none;
        border: 1px solid #ddd;
    }
    .accordion-item {
        box-shadow: none;
        border: 1px solid #ddd;
        margin-bottom: 10px;
    }
    .accordion-body {
        max-height: none !important;
        padding: 20px !important;
    }
    .accordion-header {
        background: #f5f5f5 !important;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .profile-page{
    padding-left: 5px;
    padding-right: 0;
    }
    .profile-layout {
        grid-template-columns: 1fr;
    }
    .profile-card {
        position: static;
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
    .settings-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="profile-page">
    <!-- Alert Messages -->
    <?php if ($success): ?>
    <div class="alert success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Session Banner -->
   
    <div class="profile-layout">
        <!-- Left: Photo Card -->
        <div class="profile-card">
            <form method="POST" action="" enctype="multipart/form-data" id="photoForm">
                <div class="photo-container">
                    <?php if ($photo_path && file_exists($photo_path)): ?>
                        <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="Profile" class="profile-photo">
                    <?php else: ?>
                        <div class="profile-photo-placeholder">
                            <?php echo strtoupper(substr($student_profile['first_name'] ?? '', 0, 1) . substr($student_profile['last_name'] ?? '', 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <label class="upload-btn no-print" title="Upload Photo">
                        <i class="fas fa-camera"></i>
                        <input type="file" name="photo" accept="image/*" onchange="document.getElementById('photoForm').submit()">
                    </label>
                </div>
                <input type="hidden" name="upload_photo" value="1">
            </form>

            <div class="student-name">
                <?php echo strtoupper(htmlspecialchars(($student_profile['first_name'] ?? '') . ' ' . ($student_profile['last_name'] ?? ''))); ?>
            </div>
            <div class="matric-display"><?php echo htmlspecialchars($student_profile['matric_number'] ?? ''); ?></div>
            <div class="program-display">
                <?php echo htmlspecialchars($student_profile['student_type'] ?: 'Full Time'); ?> &middot;
                <?php echo htmlspecialchars($student_profile['program_name'] ?? ''); ?> &middot;
                <?php echo $student_profile['current_level'] ?? ''; ?> Level
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons no-print" style="margin-top: 24px;">
                <button type="button" class="btn btn-outline" onclick="generateProfilePDF()">
                    <i class="fas fa-file-pdf"></i> Download Profile Records
                </button>
            </div>
        </div>

        <!-- Right: Accordions -->
        <div class="accordion-container">

            <!-- BIO-DATA -->
            <div class="accordion-item ">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-user"></i> Bio-Data</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['first_name'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['middle_name'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['last_name'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth</label>
                                <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($student_profile['date_of_birth'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['gender'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Marital Status</label>
                                <select name="marital_status">
                                    <option value="Single" <?php echo ($student_profile['marital_status'] ?? 'Single') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married" <?php echo ($student_profile['marital_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                    <option value="Divorced" <?php echo ($student_profile['marital_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="Widowed" <?php echo ($student_profile['marital_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Nationality</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['nationality'] ?: 'Nigerian'); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>State of Origin</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['state_of_origin'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>LGA</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['lga'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Blood Group</label>
                                <input type="text" value="<?php echo htmlspecialchars($student_profile['blood_group'] ?? ''); ?>" readonly disabled>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="update_biodata" class="btn btn-primary no-print">
                                <i class="fas fa-save"></i> Save Bio-Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ADMISSION -->
            <div class="accordion-item">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-graduation-cap"></i> Admission</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <div class="info-grid">
                        <div class="form-group">
                            <label>Matric Number</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['matric_number'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Program</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['program_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Department</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['department_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Faculty</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['faculty_name'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Current Level</label>
                            <input type="text" value="<?php echo $student_profile['current_level'] ?? ''; ?> Level" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Admission Year</label>
                            <input type="text" value="<?php echo $student_profile['admission_year'] ?? ''; ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Mode of Entry</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['mode_of_entry'] ?: 'UTME'); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>JAMB Reg Number</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['jamb_reg_number'] ?? ''); ?>" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Duration</label>
                            <input type="text" value="<?php echo $student_profile['duration_years'] ?? ''; ?> Years" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label>Grading System</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_profile['grading_system'] ?: '5-Point Scale'); ?>" readonly disabled>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MEDICAL RECORD -->
            <div class="accordion-item" id="medical-section">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-heartbeat"></i> Medical Record</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div class="form-group">
                                <label>Blood Group</label>
                                <select name="blood_group">
                                    <option value="">Select</option>
                                    <option value="A+" <?php echo ($student_profile['blood_group'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                    <option value="A-" <?php echo ($student_profile['blood_group'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                    <option value="B+" <?php echo ($student_profile['blood_group'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                    <option value="B-" <?php echo ($student_profile['blood_group'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                    <option value="O+" <?php echo ($student_profile['blood_group'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                    <option value="O-" <?php echo ($student_profile['blood_group'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                                    <option value="AB+" <?php echo ($student_profile['blood_group'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                    <option value="AB-" <?php echo ($student_profile['blood_group'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Genotype</label>
                                <select name="genotype">
                                    <option value="">Select</option>
                                    <option value="AA" <?php echo ($medical['genotype'] ?? '') == 'AA' ? 'selected' : ''; ?>>AA</option>
                                    <option value="AS" <?php echo ($medical['genotype'] ?? '') == 'AS' ? 'selected' : ''; ?>>AS</option>
                                    <option value="SS" <?php echo ($medical['genotype'] ?? '') == 'SS' ? 'selected' : ''; ?>>SS</option>
                                    <option value="AC" <?php echo ($medical['genotype'] ?? '') == 'AC' ? 'selected' : ''; ?>>AC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Allergies</label>
                                <input type="text" name="allergies" value="<?php echo htmlspecialchars($medical['allergies'] ?? ''); ?>" placeholder="e.g., Penicillin, Peanuts">
                            </div>
                            <div class="form-group">
                                <label>Disability</label>
                                <input type="text" name="disability" value="<?php echo htmlspecialchars($student_profile['disability'] ?? ''); ?>" placeholder="None if none">
                            </div>
                            <div class="form-group full">
                                <label>Medical Conditions</label>
                                <textarea name="conditions" placeholder="List any medical conditions"><?php echo htmlspecialchars($medical['conditions'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="update_medical" class="btn btn-primary no-print">
                                <i class="fas fa-save"></i> Save Medical Info
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- NEXT OF KIN & SPONSOR -->
            <div class="accordion-item">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-users"></i> Next of Kin & Sponsor</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="kin_full_name" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['full_name'] ?? '') : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Relationship</label>
                                <select name="kin_relationship" required>
                                    <option value="">Select</option>
                                    <option value="Father" <?php echo ($next_of_kin['relationship'] ?? '') == 'Father' ? 'selected' : ''; ?>>Father</option>
                                    <option value="Mother" <?php echo ($next_of_kin['relationship'] ?? '') == 'Mother' ? 'selected' : ''; ?>>Mother</option>
                                    <option value="Brother" <?php echo ($next_of_kin['relationship'] ?? '') == 'Brother' ? 'selected' : ''; ?>>Brother</option>
                                    <option value="Sister" <?php echo ($next_of_kin['relationship'] ?? '') == 'Sister' ? 'selected' : ''; ?>>Sister</option>
                                    <option value="Spouse" <?php echo ($next_of_kin['relationship'] ?? '') == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
                                    <option value="Guardian" <?php echo ($next_of_kin['relationship'] ?? '') == 'Guardian' ? 'selected' : ''; ?>>Guardian</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="kin_phone" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['phone'] ?? '') : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="kin_email" value="<?php echo $next_of_kin ? htmlspecialchars($next_of_kin['email'] ?? '') : ''; ?>">
                            </div>
                            <div class="form-group full">
                                <label>Address</label>
                                <textarea name="kin_address" rows="2"><?php echo $next_of_kin ? htmlspecialchars($next_of_kin['address'] ?? '') : ''; ?></textarea>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="update_next_of_kin" class="btn btn-primary no-print">
                                <i class="fas fa-save"></i> Save Next of Kin
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- PARENTS -->
            <div class="accordion-item">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-home"></i> Parents</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div class="form-group">
                                <label>Father's Name</label>
                                <input type="text" name="father_name" value="<?php echo htmlspecialchars($parents['father_name'] ?? ''); ?>" placeholder="Enter father's full name">
                            </div>
                            <div class="form-group">
                                <label>Father's Occupation</label>
                                <input type="text" name="father_occupation" value="<?php echo htmlspecialchars($parents['father_occupation'] ?? ''); ?>" placeholder="e.g., Civil Servant">
                            </div>
                            <div class="form-group">
                                <label>Father's Phone</label>
                                <input type="tel" name="father_phone" value="<?php echo htmlspecialchars($parents['father_phone'] ?? ''); ?>" placeholder="e.g., 08012345678">
                            </div>
                            <div class="form-group">
                                <label>Mother's Name</label>
                                <input type="text" name="mother_name" value="<?php echo htmlspecialchars($parents['mother_name'] ?? ''); ?>" placeholder="Enter mother's full name">
                            </div>
                            <div class="form-group">
                                <label>Mother's Occupation</label>
                                <input type="text" name="mother_occupation" value="<?php echo htmlspecialchars($parents['mother_occupation'] ?? ''); ?>" placeholder="e.g., Teacher">
                            </div>
                            <div class="form-group">
                                <label>Mother's Phone</label>
                                <input type="tel" name="mother_phone" value="<?php echo htmlspecialchars($parents['mother_phone'] ?? ''); ?>" placeholder="e.g., 08012345678">
                            </div>
                            <div class="form-group">
                                <label>Guardian Name</label>
                                <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($parents['guardian_name'] ?? ''); ?>" placeholder="If different from parents">
                            </div>
                            <div class="form-group">
                                <label>Guardian Phone</label>
                                <input type="tel" name="guardian_phone" value="<?php echo htmlspecialchars($parents['guardian_phone'] ?? ''); ?>" placeholder="Guardian phone number">
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="update_parents" class="btn btn-primary no-print">
                                <i class="fas fa-save"></i> Save Parents Info
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- SIGNATURE UPLOAD -->
            <div class="accordion-item">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-signature"></i> Signature Upload</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="info-grid full">
                            <div class="form-group">
                                <label>Current Signature</label>
                                <?php if ($signature_path && file_exists($signature_path)): ?>
                                    <img src="<?php echo htmlspecialchars($signature_path); ?>" alt="Signature" style="max-width: 300px; border: 1px solid var(--gray-300); border-radius: 8px; padding: 10px;">
                                <?php else: ?>
                                    <div style="padding: 30px; background: var(--gray-100); border-radius: 8px; text-align: center; color: var(--gray-500);">
                                        <i class="fas fa-signature" style="font-size: 32px; margin-bottom: 10px;"></i>
                                        <p>No signature uploaded yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label>Upload New Signature (JPG/PNG, max 1MB)</label>
                                <input type="file" name="signature" accept="image/*" required>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="upload_signature" class="btn btn-primary no-print">
                                <i class="fas fa-upload"></i> Upload Signature
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- CONTACT (Editable) -->
            <div class="accordion-item">
                <button class="accordion-header" onclick="toggleAccordion(this)">
                    <span><i class="fas fa-address-book"></i> Contact Information</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                <div class="accordion-body">
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div class="form-group">
                                <label>Email Address <small>(Cannot be changed)</small></label>
                                <input type="email" value="<?php echo htmlspecialchars($student_profile['email'] ?? ''); ?>" readonly disabled>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($student_profile['phone'] ?? ''); ?>" placeholder="Enter phone number">
                            </div>
                            <div class="form-group">
                                <label>Emergency Contact Name</label>
                                <input type="text" name="emergency_name" value="<?php echo htmlspecialchars($student_profile['emergency_contact_name'] ?? ''); ?>" placeholder="Full name">
                            </div>
                            <div class="form-group">
                                <label>Emergency Contact Phone</label>
                                <input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($student_profile['emergency_contact'] ?? ''); ?>" placeholder="Phone number">
                            </div>
                            <div class="form-group full">
                                <label>Residential Address</label>
                                <textarea name="address" rows="2" placeholder="Enter your current address"><?php echo htmlspecialchars($student_profile['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div style="text-align: right; margin-top: 16px;">
                            <button type="submit" name="update_profile" class="btn btn-primary no-print">
                                <i class="fas fa-save"></i> Save Contact Info
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function toggleAccordion(header) {
    const item = header.parentElement;
    const wasActive = item.classList.contains('active');
    document.querySelectorAll('.accordion-item').forEach(acc => acc.classList.remove('active'));
    if (!wasActive) item.classList.add('active');
}

function toggleDarkMode(enabled) {
    if (enabled) {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('darkMode', 'enabled');
    } else {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('darkMode', 'disabled');
    }
}

// ─── PDF Generation using dynamic element creation (same pattern as course page) ───
function generateProfilePDF() {
    // Embed PHP data as JSON (same pattern as course page)
    const student = <?php echo json_encode($pdf_student); ?>;
    const kin = <?php echo json_encode($pdf_kin); ?>;
    const medical = <?php echo json_encode($pdf_medical); ?>;
    const parents = <?php echo json_encode($pdf_parents); ?>;
    const currentSession = <?php echo json_encode($current_session); ?>;
    const photoPath = <?php echo json_encode($photo_path && file_exists($photo_path) ? $photo_path : ''); ?>;

    // Create hidden div dynamically (same pattern as course page)
    const pdfContent = document.createElement('div');
    pdfContent.style.padding = '40px';
    pdfContent.style.fontFamily = 'Arial, sans-serif';
    pdfContent.style.backgroundColor = 'white';
    pdfContent.style.width = '210mm';
    pdfContent.style.color = '#000';

    // Format date helper
    const fmtDate = (d) => {
        if (!d) return '';
        const date = new Date(d);
        if (isNaN(date)) return d;
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
    };

    // Format short date
    const fmtShortDate = (d) => {
        if (!d) return '';
        const date = new Date(d);
        if (isNaN(date)) return d;
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

    // Build PDF HTML with embedded data (same pattern as course page)
    pdfContent.innerHTML = `
        <style>
            .pdf-wrapper { font-family: Arial, sans-serif; color: #000; background: #fff; margin-top:-30px }
            .pdf-header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
            .pdf-header .inst-name { font-size: 14px; font-weight: bold; }
            .pdf-header .form-type { font-size: 12px; }
            .institution-box { background: #f0f0f0; padding: 10px; border: 1px solid #333; margin-bottom: 20px; font-weight: bold; font-size: 13px; }
            .section-header { background: #c8dce8; padding: 8px 12px; font-weight: bold; border: 1px solid #333; font-size: 13px; }
            .pdf-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px; }
            .pdf-table td { padding: 6px 10px; border-bottom: 1px solid #eee; }
            .pdf-table td.label { font-weight: bold; width: 35%; }
            .photo-box { float: right; width: 100px; height: 120px; border: 1px solid #333; text-align: center; line-height: 120px; font-size: 10px; color: #666; margin-left: 10px; margin-right: 50px; margin-bottom: 15px; }
            .photo-box img { width: 100%; height: 100%; object-fit: cover; }
            .clear { clear: both; }
            .footer { margin-top: 30px; border-top: 1px solid #333; padding-top: 15px; font-size: 13px; }
            .signature-line { display: inline-block; width: 200px; border-bottom: 1px solid #333; margin: 0 10px; }
            .generated-footer { margin-top: 20px; font-size: 10px; color: #666; text-align: center; }
            .checkbox { display: inline-block; width: 15px; height: 15px; border: 1px solid #333; text-align: center; line-height: 15px; font-size: 10px; margin-right: 5px; }
        </style>

        <div class="pdf-wrapper">
            <!-- Header -->
            <div class="pdf-header">
                <div class="inst-name">NATIONAL HEALTH INSURANCE SCHEME</div>
                <div class="form-type">STUDENTS REGISTRATION FORM</div>
                <div class="form-type">TERTIARY INSTITUTIONS SOCIAL HEALTH INSURANCE PROGRAM</div>
            </div>

            <!-- Institution Box -->
            <div class="institution-box">
                NAME OF TERTIARY INSTITUTION: 5G E-GURU SCHOOL
            </div>

            <!-- Form Title -->
            <div style="text-align: center; font-size: 16px; font-weight: bold; color: #003366; margin-bottom: 20px;">
                STUDENT PROFILE RECORDS
            </div>

            <!-- Photo Box -->
            <div class="photo-box">
                ${photoPath ? `<img src="${photoPath}" alt="Photo">` : 'Passport<br>Photo'}
            </div>

            <!-- Section 1: Personal Data -->
            <div class="section-header">1. PERSONAL DATA:</div>
            <table class="pdf-table">
                <tr><td class="label">Full Name:</td><td>${(student.first_name + ' ' + (student.middle_name || '') + ' ' + student.last_name).toUpperCase().trim()}</td></tr>
                <tr><td class="label">MAT./REG.NUMBER:</td><td>${(student.matric_number || '').toUpperCase()}</td></tr>
                <tr><td class="label">DATE OF BIRTH:</td><td>${fmtShortDate(student.date_of_birth)}</td></tr>
                <tr><td class="label">SEX:</td><td>${(student.gender || '').toUpperCase()}</td></tr>
                <tr><td class="label">MARITAL STATUS:</td><td>${(student.marital_status || '').toUpperCase()}</td></tr>
                <tr><td class="label">NATIONALITY:</td><td>${(student.nationality || '').toUpperCase()}</td></tr>
                <tr><td class="label">STATE OF ORIGIN:</td><td>${(student.state_of_origin || '').toUpperCase()}</td></tr>
                <tr><td class="label">LGA:</td><td>${(student.lga || '').toUpperCase()}</td></tr>
                <tr><td class="label">FACULTY:</td><td>${(student.faculty_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">DEPARTMENT:</td><td>${(student.department_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">PROGRAM:</td><td>${(student.program_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">SESSION:</td><td>${student.admission_year || ''}</td></tr>
                <tr><td class="label">LEVEL:</td><td>${student.current_level ? student.current_level + ' LEVEL' : ''}</td></tr>
                <tr><td class="label">EMAIL:</td><td>${(student.email || '').toLowerCase()}</td></tr>
                <tr><td class="label">PHONE:</td><td>${student.phone || ''}</td></tr>
            </table>

            <div class="clear"></div>

            <!-- Section 2: Admission Information -->
            <div class="section-header">2. ADMISSION INFORMATION:</div>
            <table class="pdf-table">
                <tr><td class="label">Mode of Entry:</td><td>${student.mode_of_entry || 'UTME'}</td></tr>
                <tr><td class="label">JAMB Reg Number:</td><td>${student.jamb_reg_number || ''}</td></tr>
                <tr><td class="label">Duration:</td><td>${student.duration_years ? student.duration_years + ' Years' : ''}</td></tr>
                <tr><td class="label">Grading System:</td><td>${student.grading_system || '5-Point Scale'}</td></tr>
                <tr><td class="label">Student Type:</td><td>${student.student_type || 'Full Time'}</td></tr>
            </table>

            <!-- Section 3: Medical History -->
            <div class="section-header">3. MEDICAL HISTORY:</div>
            <table class="pdf-table">
                <tr>
                    <td>
                        <span class="checkbox">${medical.conditions && medical.conditions.toLowerCase().includes('diabetes') ? 'X' : '&nbsp;'}</span> A. Diabetes
                    </td>
                    <td>
                        <span class="checkbox">${medical.conditions && medical.conditions.toLowerCase().includes('hypertension') ? 'X' : '&nbsp;'}</span> B. Hypertension
                    </td>
                </tr>
                <tr>
                    <td>
                        <span class="checkbox">${medical.conditions && medical.conditions.toLowerCase().includes('epilepsy') ? 'X' : '&nbsp;'}</span> C. Epilepsy
                    </td>
                    <td>
                        <span class="checkbox">${medical.conditions && medical.conditions.toLowerCase().includes('sickle') ? 'X' : '&nbsp;'}</span> D. Sickle Cell
                    </td>
                </tr>
                <tr>
                    <td>
                        <span class="checkbox">${medical.allergies ? 'X' : '&nbsp;'}</span> E. Allergy
                    </td>
                    <td></td>
                </tr>
            </table>

            <table class="pdf-table">
                <tr><td class="label">BLOOD GROUP:</td><td>${student.blood_group || 'Not specified'}</td></tr>
                <tr><td class="label">GENOTYPE:</td><td>${medical.genotype || 'Not specified'}</td></tr>
                <tr><td class="label">ALLERGIES (Specify):</td><td>${medical.allergies || 'None'}</td></tr>
                <tr><td class="label">OTHER CONDITIONS:</td><td>${medical.conditions || 'None'}</td></tr>
                <tr><td class="label">DISABILITY:</td><td>${student.disability || 'None'}</td></tr>
            </table>

            <!-- Section 4: Contact Information -->
            <div class="section-header">4. CONTACT INFORMATION:</div>
            <table class="pdf-table">
                <tr><td class="label">Phone:</td><td>${student.phone || ''}</td></tr>
                <tr><td class="label">Address:</td><td>${student.address || ''}</td></tr>
                <tr><td class="label">Emergency Contact:</td><td>${(student.emergency_contact_name || '') + (student.emergency_contact ? ' - ' + student.emergency_contact : '')}</td></tr>
            </table>

            <!-- Section 5: Next of Kin -->
            <div class="section-header">5. NEXT OF KIN:</div>
            <table class="pdf-table">
                <tr><td class="label">Name:</td><td>${(kin.full_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">Relationship:</td><td>${kin.relationship || ''}</td></tr>
                <tr><td class="label">Phone:</td><td>${kin.phone || ''}</td></tr>
                <tr><td class="label">Email:</td><td>${kin.email || ''}</td></tr>
                <tr><td class="label">Address:</td><td>${kin.address || ''}</td></tr>
            </table>

            <!-- Section 6: Parents/Guardian -->
            <div class="section-header">6. PARENTS / GUARDIAN INFORMATION:</div>
            <table class="pdf-table">
                <tr><td class="label">Father's Name:</td><td>${(parents.father_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">Father's Occupation:</td><td>${parents.father_occupation || ''}</td></tr>
                <tr><td class="label">Father's Phone:</td><td>${parents.father_phone || ''}</td></tr>
                <tr><td class="label">Mother's Name:</td><td>${(parents.mother_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">Mother's Occupation:</td><td>${parents.mother_occupation || ''}</td></tr>
                <tr><td class="label">Mother's Phone:</td><td>${parents.mother_phone || ''}</td></tr>
                <tr><td class="label">Guardian Name:</td><td>${(parents.guardian_name || '').toUpperCase()}</td></tr>
                <tr><td class="label">Guardian Phone:</td><td>${parents.guardian_phone || ''}</td></tr>
            </table>

            <!-- Footer -->
            <div class="footer">
                <p><strong>STUDENT SIGNATURE:</strong> <span class="signature-line"></span>
                <strong>DATE:</strong> <span class="signature-line" style="width: 150px;"></span></p>
            </div>
            <div class="generated-footer">
                Generated on ${new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' })} ${new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })} from 5G E-GURU School Student Portal
            </div>
        </div>
    `;

    document.body.appendChild(pdfContent);

    const opt = {
        margin: [10, 10, 10, 10],
        filename: 'profile_records_' + (student.matric_number || 'student').replace(/\//g, '_') + '_' + Date.now() + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
            scale: 2,
            useCORS: true,
            logging: false
        },
        jsPDF: {
            unit: 'mm',
            format: 'a4',
            orientation: 'portrait'
        }
    };

    html2pdf().set(opt).from(pdfContent).save().then(() => {
        document.body.removeChild(pdfContent);
    }).catch(err => {
        console.error('PDF generation failed:', err);
        alert('PDF generation failed. Please try using the Print button instead.');
        if (pdfContent.parentNode) {
            document.body.removeChild(pdfContent);
        }
    });
}

// Check for saved dark mode
if (localStorage.getItem('darkMode') === 'enabled') {
    document.documentElement.setAttribute('data-theme', 'dark');
    const darkModeCheckbox = document.querySelector('input[name="dark_mode"]');
    if (darkModeCheckbox) darkModeCheckbox.checked = true;
}
</script>

<?php require_once 'includes/footer.php'; ?>