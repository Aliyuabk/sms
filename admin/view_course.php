<?php
// view_course.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "View Course";

// Get course ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: courses.php");
    exit();
}

$course_id = (int)$_GET['id'];

// Fetch course details
$sql = "
    SELECT 
        c.*,
        d.department_name,
        d.department_code,
        d.email as department_email,
        pre.course_code as prerequisite_code,
        pre.course_title as prerequisite_title,
        creator.full_name as created_by_name,
        c.created_date
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
    LEFT JOIN admin_users creator ON c.created_by = creator.admin_id
    WHERE c.course_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$course_id]);
$course = $stmt->fetch();

if (!$course) {
    $_SESSION['error_message'] = "Course not found!";
    header("Location: courses.php");
    exit();
}

// Get course statistics
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM course_registrations WHERE course_id = ?) as total_registrations,
        (SELECT COUNT(*) FROM course_registrations WHERE course_id = ? AND registration_status = 'Approved') as approved_registrations,
        (SELECT COUNT(*) FROM results WHERE course_id = ? AND is_published = 1) as published_results,
        (SELECT AVG(total_score) FROM results WHERE course_id = ? AND is_published = 1) as average_score,
        (SELECT COUNT(*) FROM attendance WHERE course_id = ?) as attendance_records
";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$course_id, $course_id, $course_id, $course_id, $course_id]);
$stats = $stats_stmt->fetch();

// Get recent registrations
$registrations_sql = "
    SELECT 
        cr.*,
        s.matric_number,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.current_level,
        s.email
    FROM course_registrations cr
    JOIN students s ON cr.student_id = s.student_id
    WHERE cr.course_id = ?
    ORDER BY cr.registration_date DESC
    LIMIT 10
";

$registrations_stmt = $pdo->prepare($registrations_sql);
$registrations_stmt->execute([$course_id]);
$recent_registrations = $registrations_stmt->fetchAll();

// Get recent results
$results_sql = "
    SELECT 
        r.*,
        s.matric_number,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.current_level
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    WHERE r.course_id = ? AND r.is_published = 1
    ORDER BY r.published_date DESC
    LIMIT 10
";

$results_stmt = $pdo->prepare($results_sql);
$results_stmt->execute([$course_id]);
$recent_results = $results_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">Course Details</h1>
    <div class="app-actions">
        <a href="courses.php" class="btn app-btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Courses
        </a>
        <a href="edit_course.php?id=<?php echo $course_id; ?>" class="btn app-btn-primary ms-2">
            <i class="fas fa-edit me-2"></i>Edit Course
        </a>
    </div>
</div>

<!-- Course Header Card -->
<div class="app-card app-card-header-info shadow-sm mb-4">
    <div class="app-card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2"><?php echo htmlspecialchars($course['course_code']); ?></h2>
                <h4 class="mb-3 text-primary"><?php echo htmlspecialchars($course['course_title']); ?></h4>
                
                <div class="row g-3">
                    <div class="col-auto">
                        <span class="badge bg-info fs-6"><?php echo $course['credit_units']; ?> Credit Units</span>
                    </div>
                    <?php if ($course['level']): ?>
                    <div class="col-auto">
                        <span class="badge bg-warning fs-6"><?php echo $course['level']; ?> Level</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($course['semester']): ?>
                    <div class="col-auto">
                        <span class="badge bg-secondary fs-6">Semester <?php echo $course['semester']; ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($course['is_core']): ?>
                    <div class="col-auto">
                        <span class="badge bg-primary fs-6">Core Course</span>
                    </div>
                    <?php elseif ($course['is_elective']): ?>
                    <div class="col-auto">
                        <span class="badge bg-success fs-6">Elective Course</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="app-icon-holder icon-holder-lg bg-primary text-white mb-3">
                    <i class="fas fa-book fa-2x"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Course Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Registrations</div>
                <div class="stats-figure"><?php echo number_format($stats['total_registrations'] ?? 0); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-users text-primary"></i> Student Enrollments
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Approved Registrations</div>
                <div class="stats-figure"><?php echo number_format($stats['approved_registrations'] ?? 0); ?></div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently Enrolled
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Published Results</div>
                <div class="stats-figure"><?php echo number_format($stats['published_results'] ?? 0); ?></div>
                <div class="stats-meta text-info">
                    <i class="fas fa-chart-bar"></i> Graded Students
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Average Score</div>
                <div class="stats-figure">
                    <?php 
                    $avg_score = $stats['average_score'] ?? 0;
                    echo $avg_score > 0 ? number_format($avg_score, 1) . '%' : 'N/A';
                    ?>
                </div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-percentage"></i> Performance Average
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Course Information -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <!-- Course Details Card -->
        <div class="app-card app-card-form shadow-sm h-100">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-info-circle me-2"></i>Course Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Department</label>
                        <p><?php echo htmlspecialchars($course['department_name']); ?> (<?php echo htmlspecialchars($course['department_code']); ?>)</p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Course Type</label>
                        <p>
                            <?php if ($course['is_core']): ?>
                            <span class="badge bg-primary">Core Course</span> - Required for all students
                            <?php elseif ($course['is_elective']): ?>
                            <span class="badge bg-success">Elective Course</span> - 
                            <?php echo htmlspecialchars($course['elective_type'] ?? 'Optional'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Prerequisite</label>
                        <p>
                            <?php if ($course['prerequisite_code']): ?>
                            <a href="view_course.php?id=<?php echo $course['prerequisite_course_id']; ?>" class="text-decoration-none">
                                <span class="badge bg-danger"><?php echo htmlspecialchars($course['prerequisite_code']); ?></span>
                                <?php echo htmlspecialchars($course['prerequisite_title']); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">No prerequisite</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Created</label>
                        <p>
                            <?php echo date('F d, Y', strtotime($course['created_date'])); ?>
                            <?php if ($course['created_by_name']): ?>
                            <br><small class="text-muted">By: <?php echo htmlspecialchars($course['created_by_name']); ?></small>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if (!empty($course['course_description'])): ?>
                <div class="mb-3">
                    <label class="form-label fw-bold">Course Description</label>
                    <div class="border rounded p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($course['course_description'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="d-flex gap-2 mt-4">
                    <a href="course_registrations.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary">
                        <i class="fas fa-users me-2"></i>View All Registrations
                    </a>
                    <a href="course_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-info">
                        <i class="fas fa-chart-bar me-2"></i>View All Results
                    </a>
                    <a href="upload_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-success">
                        <i class="fas fa-upload me-2"></i>Upload Results
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Actions Card -->
        <div class="app-card app-card-actions shadow-sm h-100">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="edit_course.php?id=<?php echo $course_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-edit me-2"></i>Edit Course Details
                    </a>
                    <a href="manage_students.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-success">
                        <i class="fas fa-user-plus me-2"></i>Register Students
                    </a>
                    <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#emailStudentsModal">
                        <i class="fas fa-envelope me-2"></i>Email Students
                    </button>
                    <a href="export_course_data.php?id=<?php echo $course_id; ?>" class="btn btn-outline-warning">
                        <i class="fas fa-download me-2"></i>Export Course Data
                    </a>
                    <button class="btn btn-outline-danger" onclick="confirmDelete()">
                        <i class="fas fa-trash me-2"></i>Delete Course
                    </button>
                </div>
                
                <hr class="my-3">
                
                <div class="text-center">
                    <div class="qr-code-placeholder bg-light rounded p-3 mb-2">
                        <i class="fas fa-qrcode fa-3x text-muted"></i>
                    </div>
                    <small class="text-muted">Course QR Code for quick access</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row g-3">
    <div class="col-md-6">
        <!-- Recent Registrations -->
        <div class="app-card app-card-table shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-user-plus me-2"></i>Recent Registrations
                </h5>
                <a href="course_registrations.php?course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-outline-primary">
                    View All
                </a>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="cell">Student</th>
                                <th class="cell">Level</th>
                                <th class="cell">Status</th>
                                <th class="cell">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_registrations)): ?>
                                <?php foreach ($recent_registrations as $reg): ?>
                                <tr>
                                    <td class="cell">
                                        <div class="fw-bold"><?php echo htmlspecialchars($reg['matric_number']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($reg['student_name']); ?></small>
                                    </td>
                                    <td class="cell">
                                        <span class="badge bg-info"><?php echo $reg['current_level']; ?> Level</span>
                                    </td>
                                    <td class="cell">
                                        <?php 
                                        $status_class = [
                                            'Pending' => 'warning',
                                            'Approved' => 'success',
                                            'Rejected' => 'danger'
                                        ][$reg['registration_status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>">
                                            <?php echo $reg['registration_status']; ?>
                                        </span>
                                    </td>
                                    <td class="cell">
                                        <?php echo date('M d, Y', strtotime($reg['registration_date'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">
                                        No registrations yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <!-- Recent Results -->
        <div class="app-card app-card-table shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title">
                    <i class="fas fa-chart-bar me-2"></i>Recent Results
                </h5>
                <a href="course_results.php?course_id=<?php echo $course_id; ?>" class="btn btn-sm btn-outline-primary">
                    View All
                </a>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="cell">Student</th>
                                <th class="cell">Score</th>
                                <th class="cell">Grade</th>
                                <th class="cell">Published</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_results)): ?>
                                <?php foreach ($recent_results as $result): ?>
                                <tr>
                                    <td class="cell">
                                        <div class="fw-bold"><?php echo htmlspecialchars($result['matric_number']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($result['student_name']); ?></small>
                                    </td>
                                    <td class="cell">
                                        <span class="fw-bold"><?php echo $result['total_score']; ?>%</span>
                                        <br><small>CA: <?php echo $result['ca_score']; ?>% | Exam: <?php echo $result['exam_score']; ?>%</small>
                                    </td>
                                    <td class="cell">
                                        <?php 
                                        $grade_class = [
                                            'A' => 'success',
                                            'B' => 'primary',
                                            'C' => 'info',
                                            'D' => 'warning',
                                            'E' => 'warning',
                                            'F' => 'danger'
                                        ][$result['grade']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $grade_class; ?> fs-6">
                                            <?php echo $result['grade']; ?> (<?php echo $result['grade_points']; ?>)
                                        </span>
                                    </td>
                                    <td class="cell">
                                        <?php echo date('M d, Y', strtotime($result['published_date'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">
                                        No published results yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email Students Modal -->
<div class="modal fade" id="emailStudentsModal" tabindex="-1" aria-labelledby="emailStudentsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailStudentsModalLabel">
                    <i class="fas fa-envelope me-2"></i>Email Course Students
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="send_course_email.php">
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" class="form-control" name="subject" 
                               value="Course Update: <?php echo htmlspecialchars($course['course_code']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="message" rows="5" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Recipients</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipients[]" value="approved" checked>
                            <label class="form-check-label">
                                Approved Registrations (<?php echo $stats['approved_registrations'] ?? 0; ?> students)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipients[]" value="pending">
                            <label class="form-check-label">
                                Pending Registrations
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipients[]" value="results">
                            <label class="form-check-label">
                                Students with Published Results (<?php echo $stats['published_results'] ?? 0; ?> students)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Confirm course deletion
function confirmDelete() {
    if (confirm('Are you sure you want to delete this course? This action cannot be undone.')) {
        // Add to bulk form and submit (you would need to create a delete endpoint)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete_course.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'course_id';
        input.value = '<?php echo $course_id; ?>';
        form.appendChild(input);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Generate QR code (placeholder)
function generateQRCode() {
    const qrContainer = document.querySelector('.qr-code-placeholder');
    const courseUrl = window.location.href;
    
    // In a real implementation, you would use a QR code library
    // For now, just show a link
    qrContainer.innerHTML = `
        <div class="text-center">
            <div class="mb-2">
                <i class="fas fa-qrcode fa-3x text-primary"></i>
            </div>
            <small class="text-muted d-block">Scan to view course</small>
            <small class="text-muted">${courseUrl}</small>
        </div>
    `;
}

// Initialize on page load
window.addEventListener('DOMContentLoaded', function() {
    generateQRCode();
});
</script>

<?php
require_once 'includes/footer.php';
?>