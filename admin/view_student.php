<?php
require_once 'includes/header.php';

// Check if student ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_students.php');
    exit();
}

$student_id = (int)$_GET['id'];

// Get student details
$sql = "SELECT 
            s.*,
            d.department_name,
            d.faculty,
            p.program_name,
            p.duration_years,
            a.first_name as advisor_first_name,
            a.last_name as advisor_last_name,
            a.email as advisor_email,
            CONCAT(s.first_name, ' ', s.last_name) as full_name
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN student_advisors sa ON s.student_id = sa.student_id AND sa.status = 'Active'
        LEFT JOIN academic_advisors a ON sa.advisor_id = a.advisor_id
        WHERE s.student_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $_SESSION['error_message'] = "Student not found!";
    header('Location: manage_students.php');
    exit();
}

// Get student's current courses
$current_courses_sql = "SELECT 
                            c.course_code,
                            c.course_title,
                            c.credit_units,
                            cr.registration_status,
                            cr.attendance_percentage
                        FROM course_registrations cr
                        JOIN courses c ON cr.course_id = c.course_id
                        WHERE cr.student_id = ? 
                            AND cr.session_year = ?
                            AND cr.semester = ?";

$current_courses_stmt = $pdo->prepare($current_courses_sql);
$current_courses_stmt->execute([
    $student_id, 
    $student['current_session'] ?? date('Y') . '/' . (date('Y') + 1),
    $student['current_semester'] ?? 1
]);
$current_courses = $current_courses_stmt->fetchAll();

// Get student's fee summary
$fee_sql = "SELECT 
                SUM(amount) as total_fees,
                SUM(amount_paid) as total_paid,
                SUM(balance) as total_balance,
                COUNT(*) as total_invoices
            FROM student_fees
            WHERE student_id = ?";

$fee_stmt = $pdo->prepare($fee_sql);
$fee_stmt->execute([$student_id]);
$fee_summary = $fee_stmt->fetch();

// Get student's academic performance
$performance_sql = "SELECT 
                        COUNT(*) as courses_taken,
                        AVG(grade_points) as average_gpa,
                        SUM(CASE WHEN grade = 'F' THEN 1 ELSE 0 END) as failed_courses
                    FROM results 
                    WHERE student_id = ? AND is_published = 1";

$performance_stmt = $pdo->prepare($performance_sql);
$performance_stmt->execute([$student_id]);
$performance = $performance_stmt->fetch();

$page_title = "View Student - " . $student['full_name'];
?>

<div class="row">
    <div class="col-md-4">
        <!-- Student Profile Card -->
        <div class="app-card app-card-profile shadow-sm mb-4">
            <div class="app-card-header p-4 bg-primary text-white text-center">
                <div class="mb-3">
                    <img src="../assets/images/user.png" alt="Student" class="rounded-circle" width="120" height="120">
                </div>
                <h4 class="mb-1"><?php echo htmlspecialchars($student['full_name']); ?></h4>
                <p class="mb-0"><?php echo htmlspecialchars($student['matric_number']); ?></p>
                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($student['status']); ?></span>
            </div>
            <div class="app-card-body p-4">
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-envelope me-2"></i>Email</h6>
                    <p><?php echo htmlspecialchars($student['email']); ?></p>
                </div>
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-phone me-2"></i>Phone</h6>
                    <p><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></p>
                </div>
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-birthday-cake me-2"></i>Date of Birth</h6>
                    <p><?php echo $student['date_of_birth'] ? date('F d, Y', strtotime($student['date_of_birth'])) : 'Not provided'; ?></p>
                </div>
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-venus-mars me-2"></i>Gender</h6>
                    <p><?php echo htmlspecialchars($student['gender'] ?? 'Not specified'); ?></p>
                </div>
                <div class="d-grid gap-2">
                    <a href="edit_student.php?id=<?php echo $student_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </a>
                    <a href="manage_students.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Academic Summary</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-center">
                            <div class="stats-figure"><?php echo $performance['courses_taken'] ?? 0; ?></div>
                            <div class="stats-meta">Courses Taken</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center">
                            <div class="stats-figure"><?php echo number_format($performance['average_gpa'] ?? 0, 2); ?></div>
                            <div class="stats-meta">Average GPA</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center">
                            <div class="stats-figure text-<?php echo ($fee_summary['total_balance'] > 0) ? 'danger' : 'success'; ?>">
                                ₦<?php echo number_format($fee_summary['total_balance'] ?? 0, 2); ?>
                            </div>
                            <div class="stats-meta">Fee Balance</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center">
                            <div class="stats-figure"><?php echo count($current_courses); ?></div>
                            <div class="stats-meta">Current Courses</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <!-- Academic Information -->
        <div class="app-card app-card-details shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-graduation-cap me-2"></i>Academic Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Department</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Faculty</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['faculty'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Program</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Duration</label>
                        <p class="fw-bold"><?php echo $student['duration_years'] ?? 'N/A'; ?> Years</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Current Level</label>
                        <p class="fw-bold">
                            <span class="badge bg-info"><?php echo $student['current_level']; ?> Level</span>
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Current Session</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['current_session']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Admission Year</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['admission_year']); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Mode of Entry</label>
                        <p class="fw-bold"><?php echo htmlspecialchars($student['mode_of_entry'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Academic Advisor</label>
                        <p class="fw-bold">
                            <?php if ($student['advisor_first_name']): ?>
                                <?php echo htmlspecialchars($student['advisor_first_name'] . ' ' . $student['advisor_last_name']); ?>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($student['advisor_email'] ?? ''); ?></small>
                            <?php else: ?>
                                Not Assigned
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Current Courses -->
        <div class="app-card app-card-details shadow-sm mb-4">
            <div class="app-card-header p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="app-card-title mb-0">
                        <i class="fas fa-book me-2"></i>Current Courses
                    </h5>
                    <a href="course_registrations.php?student_id=<?php echo $student_id; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i>Manage Courses
                    </a>
                </div>
            </div>
            <div class="app-card-body p-3">
                <?php if (!empty($current_courses)): ?>
                    <div class="table-responsive">
                        <table class="table app-table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Credit Units</th>
                                    <th>Attendance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_courses as $course): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                    <td><?php echo htmlspecialchars($course['course_title']); ?></td>
                                    <td><?php echo $course['credit_units']; ?></td>
                                    <td>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-success" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $course['attendance_percentage']; ?>%">
                                            </div>
                                        </div>
                                        <small><?php echo $course['attendance_percentage']; ?>%</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $course['registration_status'] === 'Approved' ? 'success' : 'warning'; ?>">
                                            <?php echo $course['registration_status']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                        <p class="text-muted">No courses registered for current session.</p>
                        <a href="course_registrations.php?student_id=<?php echo $student_id; ?>" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Register Courses
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="app-card app-card-action shadow-sm">
                    <div class="app-card-body p-3 text-center">
                        <div class="app-icon-holder icon-holder-lg bg-primary text-white mb-3 mx-auto">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h5>Fee Management</h5>
                        <p class="text-muted small">View and manage student fees</p>
                        <a href="student_fees.php?id=<?php echo $student_id; ?>" class="btn btn-primary w-100">
                            <i class="fas fa-external-link-alt me-2"></i>Go to Fees
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="app-card app-card-action shadow-sm">
                    <div class="app-card-body p-3 text-center">
                        <div class="app-icon-holder icon-holder-lg bg-success text-white mb-3 mx-auto">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <h5>Academic Results</h5>
                        <p class="text-muted small">View and manage student results</p>
                        <a href="student_results.php?id=<?php echo $student_id; ?>" class="btn btn-success w-100">
                            <i class="fas fa-external-link-alt me-2"></i>Go to Results
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>