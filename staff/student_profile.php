<?php
/**
 * Student Profile Page
 * View detailed student information
 */

ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$staff_id = $_SESSION['staff_id'];
$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    header('Location: students.php');
    exit;
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Verify staff has access to this student (teaches at least one course the student is enrolled in)
$checkStmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM course_assignments ca
    JOIN course_registrations cr ON ca.course_id = cr.course_id
    WHERE ca.staff_id = ? 
    AND cr.student_id = ?
    AND cr.registration_status = 'Approved'
    AND ca.status = 'Active'
");
$checkStmt->execute([$staff_id, $student_id]);
$access = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($access['count'] == 0) {
    header('Location: students.php?error=unauthorized');
    exit;
}

// Get student details with relationships
$studentStmt = $pdo->prepare("
    SELECT 
        s.*,
        d.department_name,
        p.program_name,
        p.program_code,
        f.faculty_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
    WHERE s.student_id = ?
");
$studentStmt->execute([$student_id]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: students.php?error=not_found');
    exit;
}

// Get academic records
$academicStmt = $pdo->prepare("
    SELECT * FROM academic_records 
    WHERE student_id = ? 
    ORDER BY session_year DESC, semester DESC
");
$academicStmt->execute([$student_id]);
$academic_records = $academicStmt->fetchAll(PDO::FETCH_ASSOC);

// Get courses the student is enrolled in (taught by this staff)
$courseStmt = $pdo->prepare("
    SELECT 
        c.course_id,
        c.course_code,
        c.course_title,
        c.credit_units,
        cr.session_year,
        cr.semester,
        cr.registration_date,
        cr.registration_status,
        cr.grade,
        cr.score,
        cr.grade_points,
        cr.attendance_percentage
    FROM course_registrations cr
    JOIN courses c ON cr.course_id = c.course_id
    JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE cr.student_id = ?
    AND ca.staff_id = ?
    AND cr.registration_status = 'Approved'
    ORDER BY cr.session_year DESC, cr.semester DESC
");
$courseStmt->execute([$student_id, $staff_id]);
$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance records
$attendanceStmt = $pdo->prepare("
    SELECT 
        a.*,
        c.course_code,
        c.course_title
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
    ORDER BY a.class_date DESC
    LIMIT 20
");
$attendanceStmt->execute([$student_id]);
$attendance_records = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment records
$paymentStmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE student_id = ? 
    ORDER BY payment_date DESC
    LIMIT 10
");
$paymentStmt->execute([$student_id]);
$payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get next of kin
$kinStmt = $pdo->prepare("SELECT * FROM next_of_kin WHERE student_id = ?");
$kinStmt->execute([$student_id]);
$next_of_kin = $kinStmt->fetch(PDO::FETCH_ASSOC);

// Get medical records
$medicalStmt = $pdo->prepare("SELECT * FROM medical_records WHERE student_id = ?");
$medicalStmt->execute([$student_id]);
$medical = $medicalStmt->fetch(PDO::FETCH_ASSOC);

// Calculate statistics
$total_courses = count($courses);
$total_credits = 0;
$total_grade_points = 0;
$passed_courses = 0;

foreach ($courses as $course) {
    $total_credits += $course['credit_units'];
    if (!empty($course['grade']) && $course['grade'] != 'F') {
        $passed_courses++;
        $total_grade_points += ($course['grade_points'] ?? 0) * $course['credit_units'];
    }
}
$cgpa = $total_credits > 0 ? $total_grade_points / $total_credits : 0;

$page_title = 'Student Profile';
$page_icon = 'fas fa-user-graduate';
$active_page = 'students';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Students', 'url' => 'students.php'],
    ['title' => htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])]
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .profile-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 20px;
        padding: 30px 35px;
        color: var(--white);
        margin-bottom: 30px;
    }
    .profile-header .avatar-lg {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 4px solid rgba(255,255,255,0.3);
        flex-shrink: 0;
    }
    .profile-header .avatar-lg img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .profile-header .avatar-lg i {
        font-size: 3rem;
        color: var(--primary-color);
    }
    .profile-header .student-name {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 5px;
    }
    .profile-header .student-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        opacity: 0.9;
        font-size: 0.9rem;
        margin-top: 10px;
    }
    .profile-header .student-meta i { margin-right: 5px; }
    
    .stat-box {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        text-align: center;
        border: 1px solid var(--gray-200);
        transition: var(--transition);
    }
    .stat-box:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }
    .stat-box .number {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
    }
    .stat-box .label {
        font-size: 0.85rem;
        color: var(--text-light);
        font-weight: 500;
    }
    .stat-box .icon {
        font-size: 1.5rem;
        opacity: 0.3;
        margin-bottom: 5px;
    }
    
    .info-section {
        background: var(--white);
        border-radius: 16px;
        padding: 25px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 25px;
        border: 1px solid var(--gray-200);
    }
    .info-section .section-title {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--text-dark);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--gray-200);
    }
    .info-section .section-title i {
        color: var(--primary-color);
        margin-right: 10px;
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }
    .info-item .label {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-light);
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    .info-item .value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-top: 2px;
    }
    .info-item .value i {
        color: var(--primary-color);
        margin-right: 8px;
        width: 18px;
    }
    
    .table-custom {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .table-custom table { margin-bottom: 0; }
    .table-custom th {
        background: var(--gray-100);
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 15px;
        border-bottom: 2px solid var(--gray-200);
    }
    .table-custom td {
        padding: 10px 15px;
        vertical-align: middle;
        border-bottom: 1px solid var(--gray-200);
    }
    .table-custom tr:hover td { background: var(--primary-soft); }
    
    .grade-badge-sm {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.8rem;
        display: inline-block;
        min-width: 35px;
        text-align: center;
    }
    .grade-A { background: #e8f5e9; color: #2e7d32; }
    .grade-B { background: #e3f2fd; color: #1565c0; }
    .grade-C { background: #fff3e0; color: #e65100; }
    .grade-D { background: #f3e5f5; color: #6a1b9a; }
    .grade-E { background: #fce4ec; color: #c62828; }
    .grade-F { background: #ffebee; color: #b71c1c; }
    
    .status-badge-sm {
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-active { background: #e8f5e9; color: var(--success-color); }
    .status-inactive { background: #ffebee; color: var(--danger-color); }
    .status-suspended { background: #fff3e0; color: var(--warning-color); }
    .status-graduated { background: #e3f2fd; color: var(--primary-color); }
    
    .status-present { color: var(--success-color); }
    .status-absent { color: var(--danger-color); }
    .status-late { color: var(--warning-color); }
    .status-excused { color: var(--primary-color); }
    
    .btn-action {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    @media (max-width: 768px) {
        .profile-header { padding: 20px; }
        .profile-header .avatar-lg { width: 70px; height: 70px; }
        .profile-header .student-name { font-size: 1.3rem; }
        .info-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Profile Header -->
<div class="profile-header">
    <div class="d-flex align-items-center gap-4 flex-wrap">
        <div class="avatar-lg">
            <?php if (!empty($student['profile_image']) && file_exists('../' . $student['profile_image'])): ?>
                <img src="../<?php echo htmlspecialchars($student['profile_image']); ?>" alt="Student">
            <?php else: ?>
                <i class="fas fa-user-graduate"></i>
            <?php endif; ?>
        </div>
        <div class="flex-1">
            <h2 class="student-name">
                <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?>
            </h2>
            <div class="student-meta">
                <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['matric_number']); ?></span>
                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></span>
                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-layer-group"></i> Level <?php echo htmlspecialchars($student['current_level'] ?? 'N/A'); ?></span>
            </div>
            <div class="student-meta">
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?>
                </span>
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="fas fa-graduation-cap me-1"></i> <?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?>
                </span>
                <span class="status-badge-sm status-<?php echo strtolower($student['status'] ?? 'active'); ?>">
                    <?php echo htmlspecialchars($student['status'] ?? 'Active'); ?>
                </span>
            </div>
        </div>
        <div class="ms-auto text-end">
            <div style="font-size: 0.8rem; opacity: 0.8;">
                <i class="fas fa-calendar-alt me-1"></i>
                Registered: <?php echo date('M d, Y', strtotime($student['registration_date'])); ?>
            </div>
            <div class="mt-2">
                <a href="message.php?student=<?php echo $student_id; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-envelope me-1"></i> Message
                </a>
                <a href="take_attendance.php?student=<?php echo $student_id; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-clipboard-check me-1"></i> Attendance
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="stat-box">
            <div class="icon"><i class="fas fa-book"></i></div>
            <div class="number"><?php echo $total_courses; ?></div>
            <div class="label">Enrolled Courses</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box">
            <div class="icon"><i class="fas fa-star"></i></div>
            <div class="number"><?php echo number_format($cgpa, 2); ?></div>
            <div class="label">CGPA</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box">
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <div class="number"><?php echo $passed_courses; ?>/<?php echo $total_courses; ?></div>
            <div class="label">Passed Courses</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box">
            <div class="icon"><i class="fas fa-credit-card"></i></div>
            <div class="number"><?php echo count($payments); ?></div>
            <div class="label">Payments</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Course Enrollment -->
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-book-open"></i> Course Enrollment
            </div>
            <?php if (count($courses) > 0): ?>
                <div class="table-custom">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Title</th>
                                <th>Credits</th>
                                <th>Semester</th>
                                <th>Score</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $course): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                                <td><?php echo $course['credit_units']; ?></td>
                                <td>Sem <?php echo $course['semester']; ?> (<?php echo htmlspecialchars($course['session_year']); ?>)</td>
                                <td><?php echo isset($course['score']) ? number_format($course['score'], 1) : '-'; ?></td>
                                <td>
                                    <?php if (!empty($course['grade'])): ?>
                                        <span class="grade-badge-sm grade-<?php echo $course['grade']; ?>">
                                            <?php echo htmlspecialchars($course['grade']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-book-open fa-2x mb-2 d-block"></i>
                    No courses enrolled yet.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Attendance Records -->
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-clipboard-check"></i> Recent Attendance
            </div>
            <?php if (count($attendance_records) > 0): ?>
                <div class="table-custom">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Course</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_records as $att): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($att['class_date'])); ?></td>
                                <td><?php echo htmlspecialchars($att['course_code']); ?></td>
                                <td>
                                    <span class="fw-semibold status-<?php echo strtolower($att['status']); ?>">
                                        <i class="fas fa-<?php echo $att['status'] == 'Present' ? 'check-circle' : ($att['status'] == 'Absent' ? 'times-circle' : ($att['status'] == 'Late' ? 'clock' : 'info-circle')); ?> me-1"></i>
                                        <?php echo htmlspecialchars($att['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-clipboard-check fa-2x mb-2 d-block"></i>
                    No attendance records found.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Personal Information -->
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-user"></i> Personal Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Full Name</div>
                    <div class="value">
                        <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label">Matric Number</div>
                    <div class="value"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['matric_number']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Email</div>
                    <div class="value"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Phone</div>
                    <div class="value"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Gender</div>
                    <div class="value"><i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Date of Birth</div>
                    <div class="value"><i class="fas fa-calendar"></i> <?php echo $student['date_of_birth'] ? date('M d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Nationality</div>
                    <div class="value"><i class="fas fa-flag"></i> <?php echo htmlspecialchars($student['nationality'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">State of Origin</div>
                    <div class="value"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($student['state_of_origin'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="label">Address</div>
                    <div class="value"><i class="fas fa-home"></i> <?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Academic Information -->
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-graduation-cap"></i> Academic Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Program</div>
                    <div class="value"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Department</div>
                    <div class="value"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Faculty</div>
                    <div class="value"><?php echo htmlspecialchars($student['faculty_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Current Level</div>
                    <div class="value">Level <?php echo htmlspecialchars($student['current_level'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">CGPA</div>
                    <div class="value"><?php echo number_format($student['cgpa'] ?? 0, 2); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Admission Year</div>
                    <div class="value"><?php echo htmlspecialchars($student['admission_year'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Medical Information -->
        <?php if ($medical): ?>
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-heartbeat"></i> Medical Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Blood Group</div>
                    <div class="value"><?php echo htmlspecialchars($medical['blood_group'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Genotype</div>
                    <div class="value"><?php echo htmlspecialchars($medical['genotype'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="label">Allergies</div>
                    <div class="value"><?php echo htmlspecialchars($medical['allergies'] ?? 'None'); ?></div>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="label">Medical Conditions</div>
                    <div class="value"><?php echo htmlspecialchars($medical['conditions'] ?? 'None'); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Next of Kin -->
        <?php if ($next_of_kin): ?>
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-users"></i> Next of Kin
            </div>
            <div class="info-grid">
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="label">Full Name</div>
                    <div class="value"><?php echo htmlspecialchars($next_of_kin['full_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Relationship</div>
                    <div class="value"><?php echo htmlspecialchars($next_of_kin['relationship'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Phone</div>
                    <div class="value"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($next_of_kin['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="label">Address</div>
                    <div class="value"><?php echo htmlspecialchars($next_of_kin['address'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Payments -->
        <div class="info-section">
            <div class="section-title">
                <i class="fas fa-credit-card"></i> Recent Payments
            </div>
            <?php if (count($payments) > 0): ?>
                <div class="table-custom">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td>₦<?php echo number_format($payment['amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge-sm status-<?php echo strtolower($payment['status'] ?? 'pending'); ?>">
                                        <?php echo htmlspecialchars($payment['status'] ?? 'Pending'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-credit-card fa-2x mb-2 d-block"></i>
                    No payment records found.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function sendMessage(studentId) {
        window.location.href = 'message.php?student=' + studentId;
    }
    
    function takeAttendance(studentId) {
        window.location.href = 'take_attendance.php?student=' + studentId;
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>