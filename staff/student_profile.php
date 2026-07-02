<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$student_id) {
    header('Location: students.php');
    exit;
}

// Verify student is in staff's course
$verify = $pdo->prepare("
    SELECT 1 FROM course_registrations cr
    JOIN course_assignments ca ON cr.course_id = ca.course_id AND cr.session_year = ca.session_year AND cr.semester = ca.semester
    WHERE cr.student_id = ? AND ca.staff_id = ? AND ca.status = 'Active' AND cr.registration_status = 'Approved'
    LIMIT 1
");
$verify->execute([$student_id, $staff_id]);
if (!$verify->fetch()) {
    header('Location: students.php');
    exit;
}

// Fetch student details
$stmt = $pdo->prepare("
    SELECT s.*, d.department_name, p.program_name, p.program_code,
           n.full_name as kin_name, n.relationship as kin_relationship, n.phone as kin_phone, n.email as kin_email,
           m.blood_group, m.genotype, m.allergies, m.conditions, m.disability, m.emergency_contact, m.emergency_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN next_of_kin n ON s.student_id = n.student_id
    LEFT JOIN medical_records m ON s.student_id = m.student_id
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch student's courses with this staff
$stmt2 = $pdo->prepare("
    SELECT c.course_id, c.course_code, c.course_title, c.credit_units, c.level,
           ca.session_year, ca.semester,
           r.ca_score, r.exam_score, r.total_score, r.grade, r.grade_points
    FROM course_registrations cr
    JOIN courses c ON cr.course_id = c.course_id
    JOIN course_assignments ca ON c.course_id = ca.course_id AND cr.session_year = ca.session_year AND cr.semester = ca.semester
    LEFT JOIN results r ON cr.student_id = r.student_id AND c.course_id = r.course_id AND r.session_year = ca.session_year AND r.semester = ca.semester
    WHERE cr.student_id = ? AND ca.staff_id = ? AND cr.registration_status = 'Approved'
    ORDER BY ca.session_year DESC, ca.semester DESC
");
$stmt2->execute([$student_id, $staff_id]);
$courses = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Fetch attendance summary
$stmt3 = $pdo->prepare("
    SELECT status, COUNT(*) as count
    FROM attendance
    WHERE student_id = ? AND course_id IN (
        SELECT course_id FROM course_assignments WHERE staff_id = ?
    )
    GROUP BY status
");
$stmt3->execute([$student_id, $staff_id]);
$attendance_summary = $stmt3->fetchAll(PDO::FETCH_KEY_PAIR);

// Get staff data
$stmt4 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt4->execute([$staff_id]);
$staff = $stmt4->fetch(PDO::FETCH_ASSOC);

// Page variables
$page_title = 'Student Profile';
$page_icon = 'fas fa-user-graduate';
$active_page = 'students';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Students', 'url' => 'students.php'],
    ['title' => $student['matric_number']]
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .student-profile-layout {
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 25px;
    }
    .student-sidebar {
        animation: fadeInUp 0.6s ease;
    }
    .student-card-sidebar {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .student-cover {
        height: 100px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    }
    .student-avatar-wrap {
        text-align: center;
        margin-top: -50px;
        padding: 0 20px 20px;
    }
    .student-avatar-big {
        width: 100px;
        height: 100px;
        border-radius: 24px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 800;
        border: 4px solid var(--white);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    }
    .student-avatar-big img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 24px;
    }
    .student-name-sidebar {
        font-size: 1.2rem;
        font-weight: 700;
        margin-top: 15px;
    }
    .student-matric-sidebar {
        color: var(--text-light);
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    .student-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .student-contact-list {
        padding: 20px;
        border-top: 1px solid var(--gray-200);
    }
    .student-contact-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid var(--gray-100);
    }
    .student-contact-item:last-child { border-bottom: none; }
    .student-contact-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--primary-soft);
        color: var(--primary-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    .student-contact-text { font-size: 0.85rem; }
    .student-contact-text small { display: block; color: var(--text-light); font-size: 0.7rem; }

    .student-main {
        animation: fadeInUp 0.7s ease;
    }
    .student-info-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 25px;
        overflow: hidden;
    }
    .student-info-header {
        padding: 20px 25px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .student-info-header h4 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .student-info-header h4 i { color: var(--primary-color); }
    .student-info-body { padding: 25px; }
    .info-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }
    .info-block {
        padding: 15px;
        background: var(--gray-100);
        border-radius: 12px;
    }
    .info-block-label {
        font-size: 0.75rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
        font-weight: 600;
    }
    .info-block-value {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .courses-table-student {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .courses-table-student thead th {
        background: var(--gray-100);
        padding: 14px 16px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-light);
        border-bottom: 2px solid var(--gray-200);
    }
    .courses-table-student tbody td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--gray-100);
        font-size: 0.9rem;
    }
    .courses-table-student tbody tr:hover { background: var(--primary-soft); }
    .grade-cell {
        padding: 5px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 700;
    }
    .attendance-chart {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        height: 120px;
        padding: 20px;
    }
    .attendance-bar {
        flex: 1;
        border-radius: 8px 8px 0 0;
        min-height: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        padding-bottom: 8px;
        color: var(--white);
        font-weight: 700;
        font-size: 0.85rem;
    }
    .attendance-bar-present { background: var(--success-color); }
    .attendance-bar-absent { background: var(--danger-color); }
    .attendance-bar-late { background: var(--warning-color); }
    .attendance-bar-excused { background: var(--primary-light); }
    .attendance-label {
        text-align: center;
        font-size: 0.75rem;
        color: var(--text-light);
        margin-top: 5px;
    }

    @media (max-width: 991px) {
        .student-profile-layout { grid-template-columns: 1fr; }
        .info-row { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 768px) {
        .info-row { grid-template-columns: 1fr; }
    }
</style>

<div class="student-profile-layout">
    <!-- Sidebar -->
    <div class="student-sidebar">
        <div class="student-card-sidebar">
            <div class="student-cover"></div>
            <div class="student-avatar-wrap">
                <div class="student-avatar-big">
                    <?php if (!empty($student['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($student['profile_image']); ?>" alt="">
                    <?php else: ?>
                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="student-name-sidebar">
                    <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? '')); ?>
                </div>
                <div class="student-matric-sidebar">
                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($student['matric_number']); ?>
                </div>
                <span class="student-status-badge status-active">
                    <i class="fas fa-circle" style="font-size: 6px; vertical-align: middle; margin-right: 4px;"></i>
                    <?php echo $student['status']; ?>
                </span>
            </div>

            <div class="student-contact-list">
                <div class="student-contact-item">
                    <div class="student-contact-icon"><i class="fas fa-envelope"></i></div>
                    <div class="student-contact-text">
                        <small>Email</small>
                        <?php echo htmlspecialchars($student['email']); ?>
                    </div>
                </div>
                <div class="student-contact-item">
                    <div class="student-contact-icon"><i class="fas fa-phone"></i></div>
                    <div class="student-contact-text">
                        <small>Phone</small>
                        <?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div class="student-contact-item">
                    <div class="student-contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="student-contact-text">
                        <small>Address</small>
                        <?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div class="student-contact-item">
                    <div class="student-contact-icon"><i class="fas fa-user-friends"></i></div>
                    <div class="student-contact-text">
                        <small>Next of Kin</small>
                        <?php echo htmlspecialchars(($student['kin_name'] ?? 'N/A') . ' (' . ($student['kin_relationship'] ?? '') . ')'); ?>
                    </div>
                </div>
                <div class="student-contact-item">
                    <div class="student-contact-icon"><i class="fas fa-phone-alt"></i></div>
                    <div class="student-contact-text">
                        <small>Kin Phone</small>
                        <?php echo htmlspecialchars($student['kin_phone'] ?? 'N/A'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="student-main">
        <!-- Personal Info -->
        <div class="student-info-card">
            <div class="student-info-header">
                <h4><i class="fas fa-user"></i> Personal Information</h4>
                <a href="message_student.php?id=<?php echo $student_id; ?>" class="btn btn-sm" style="background: var(--primary-soft); color: var(--primary-color); border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-envelope me-1"></i>Message
                </a>
            </div>
            <div class="student-info-body">
                <div class="info-row">
                    <div class="info-block">
                        <div class="info-block-label">Department</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Program</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Current Level</div>
                        <div class="info-block-value"><?php echo $student['current_level']; ?> Level</div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Gender</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Date of Birth</div>
                        <div class="info-block-value"><?php echo $student['date_of_birth'] ? date('F d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">State of Origin</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['state_of_origin'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">LGA</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['lga'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Admission Year</div>
                        <div class="info-block-value"><?php echo $student['admission_year'] ?? 'N/A'; ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">CGPA</div>
                        <div class="info-block-value"><?php echo $student['cgpa'] ?? '0.00'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Medical Info -->
        <div class="student-info-card">
            <div class="student-info-header">
                <h4><i class="fas fa-heartbeat"></i> Medical Information</h4>
            </div>
            <div class="student-info-body">
                <div class="info-row">
                    <div class="info-block">
                        <div class="info-block-label">Blood Group</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['blood_group'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Genotype</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['genotype'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Allergies</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['allergies'] ?? 'None'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Conditions</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['conditions'] ?? 'None'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Emergency Contact</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['emergency_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-block-label">Emergency Phone</div>
                        <div class="info-block-value"><?php echo htmlspecialchars($student['emergency_contact'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Summary -->
        <div class="student-info-card">
            <div class="student-info-header">
                <h4><i class="fas fa-chart-pie"></i> Attendance Summary</h4>
            </div>
            <div class="student-info-body">
                <?php 
                $total_att = array_sum($attendance_summary);
                if ($total_att > 0): 
                ?>
                <div class="attendance-chart">
                    <?php 
                    $statuses = ['Present' => 'attendance-bar-present', 'Absent' => 'attendance-bar-absent', 
                                 'Late' => 'attendance-bar-late', 'Excused' => 'attendance-bar-excused'];
                    foreach ($statuses as $status => $class): 
                        $count = $attendance_summary[$status] ?? 0;
                        $height = $total_att > 0 ? ($count / $total_att * 100) : 0;
                    ?>
                    <div style="flex: 1; text-align: center;">
                        <div class="attendance-bar <?php echo $class; ?>" style="height: <?php echo max($height, 5); ?>%;">
                            <?php echo $count; ?>
                        </div>
                        <div class="attendance-label"><?php echo $status; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-chart-bar fa-2x text-muted mb-2"></i>
                    <p class="text-muted">No attendance records found.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Courses & Results -->
        <div class="student-info-card">
            <div class="student-info-header">
                <h4><i class="fas fa-book"></i> Courses & Results</h4>
            </div>
            <div class="table-responsive">
                <table class="courses-table-student">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Session</th>
                            <th>Units</th>
                            <th>CA</th>
                            <th>Exam</th>
                            <th>Total</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): 
                            $grade_class = 'grade-preview-null';
                            if ($course['grade']) {
                                $g = strtolower($course['grade']);
                                if ($g == 'a') $grade_class = 'grade-a';
                                elseif ($g == 'b') $grade_class = 'grade-b';
                                elseif ($g == 'c') $grade_class = 'grade-c';
                                elseif (in_array($g, ['d','e'])) $grade_class = 'grade-d';
                                elseif ($g == 'f') $grade_class = 'grade-f';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                            <td><?php echo $course['session_year']; ?> - Sem <?php echo $course['semester']; ?></td>
                            <td><?php echo $course['credit_units']; ?></td>
                            <td><?php echo $course['ca_score'] ?? '-'; ?></td>
                            <td><?php echo $course['exam_score'] ?? '-'; ?></td>
                            <td><strong><?php echo $course['total_score'] ?? '-'; ?></strong></td>
                            <td>
                                <span class="grade-cell <?php echo $grade_class; ?>">
                                    <?php echo $course['grade'] ?? 'N/A'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div> 