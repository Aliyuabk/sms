<?php
// staff_dashboard.php
$page_title = 'Staff Dashboard';
require_once 'includes/header.php';

// Check if user has staff access
$staff_id = $_SESSION['staff_id'] ?? null;
$staff_role = $_SESSION['staff_role'] ?? null;
$staff_permissions = $_SESSION['staff_permissions'] ?? [];

// If admin is logged in, they can view all staff dashboards
$is_admin_view = isAdminLoggedIn() && isset($_GET['staff_id']);
if ($is_admin_view) {
    $staff_id = intval($_GET['staff_id']);
}

if (!$staff_id) {
    header('Location: login.php');
    exit();
}

// Fetch staff details
try {
    $stmt = $pdo->prepare("
        SELECT s.*, sr.role_name, sr.role_slug, sr.permissions as role_permissions,
               d.department_name, d.department_code
        FROM staff s
        LEFT JOIN staff_roles sr ON s.staff_role_id = sr.role_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE s.staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch();
    
    if (!$staff) {
        die("Staff not found");
    }
    
    // Parse permissions
    $permissions = json_decode($staff['role_permissions'] ?? '{}', true);
    
} catch (Exception $e) {
    die("Error loading staff data");
}

// Get current academic session
try {
    $stmt = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1");
    $current_session = $stmt->fetch();
    $session_year = $current_session['session_year'] ?? date('Y') . '/' . (date('Y') + 1);
    $semester = $current_session['semester'] ?? 1;
} catch (Exception $e) {
    $session_year = date('Y') . '/' . (date('Y') + 1);
    $semester = 1;
}

// Fetch classes assigned to staff
try {
    $stmt = $pdo->prepare("
        SELECT sc.*, c.course_code, c.course_title, c.credit_units,
               p.program_name, p.program_code,
               (SELECT COUNT(*) FROM course_registrations cr 
                WHERE cr.course_id = sc.course_id 
                AND cr.session_year = sc.session_year 
                AND cr.semester = sc.semester
                AND cr.registration_status = 'Approved') as student_count
        FROM staff_classes sc
        JOIN courses c ON sc.course_id = c.course_id
        LEFT JOIN programs p ON sc.program_id = p.program_id
        WHERE sc.staff_id = ? AND sc.session_year = ? AND sc.semester = ? AND sc.status = 'Active'
        ORDER BY c.course_code
    ");
    $stmt->execute([$staff_id, $session_year, $semester]);
    $assigned_classes = $stmt->fetchAll();
} catch (Exception $e) {
    $assigned_classes = [];
}

// Get total students across all classes
$total_students = array_sum(array_column($assigned_classes, 'student_count'));

// Get recent activities
try {
    $stmt = $pdo->prepare("
        SELECT * FROM staff_activity_log 
        WHERE staff_id = ? 
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute([$staff_id]);
    $activities = $stmt->fetchAll();
} catch (Exception $e) {
    $activities = [];
}

// Quick stats
$total_classes = count($assigned_classes);
$total_credits = array_sum(array_column($assigned_classes, 'credit_units'));
?>

<div class="app-wrapper">
    <div class="app-content pt-3 p-md-3 p-lg-4">
        <div class="container-xl">
            
            <!-- Staff Profile Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="app-card app-card-stat border-left-decoration">
                        <div class="app-card-body p-4">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="app-icon-holder icon-holder-lg bg-primary text-white">
                                        <svg width="2em" height="2em" viewBox="0 0 16 16" class="bi bi-person-badge" fill="currentColor">
                                            <path fill-rule="evenodd" d="M2 2.5A2.5 2.5 0 0 1 4.5 0h7A2.5 2.5 0 0 1 14 2.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2.5zM4.5 1A1.5 1.5 0 0 0 3 2.5v.382l.5.275V14a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V3.157l.5-.275V2.5A1.5 1.5 0 0 0 11.5 1h-7z"/>
                                            <path fill-rule="evenodd" d="M4.5 2.5A1.5 1.5 0 0 1 6 1h4a1.5 1.5 0 0 1 1.5 1.5v1a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 3.5v-1zM6 2a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5v-1A.5.5 0 0 0 10 2H6z"/>
                                        </svg>
                                    </div>
                                </div>
                                <div class="col">
                                    <h2 class="mb-1"><?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></h2>
                                    <p class="mb-0 text-muted">
                                        <span class="badge bg-<?php echo $staff['contract_status'] === 'Active' ? 'success' : 'warning'; ?> me-2">
                                            <?php echo $staff['contract_status']; ?>
                                        </span>
                                        <span class="me-3"><i class="fas fa-id-badge me-1"></i><?php echo htmlspecialchars($staff['staff_number']); ?></span>
                                        <span class="me-3"><i class="fas fa-user-tag me-1"></i><?php echo htmlspecialchars($staff['role_name'] ?? 'Staff'); ?></span>
                                        <span class="me-3"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($staff['department_name'] ?? 'N/A'); ?></span>
                                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($staff['email']); ?></span>
                                    </p>
                                </div>
                                <div class="col-auto">
                                    <?php if ($is_admin_view): ?>
                                    <a href="manage_staffs.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Staff List
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat h-100 shadow-sm">
                        <div class="app-card-body p-3">
                            <div class="stats-meta text-success mb-1">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <span class="stats-type">Active Classes</span>
                            </div>
                            <div class="stats-figure"><?php echo $total_classes; ?></div>
                            <div class="stats-meta text-success">
                                <i class="fas fa-arrow-up"></i> Current Semester
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat h-100 shadow-sm">
                        <div class="app-card-body p-3">
                            <div class="stats-meta text-primary mb-1">
                                <i class="fas fa-users"></i>
                                <span class="stats-type">Total Students</span>
                            </div>
                            <div class="stats-figure"><?php echo $total_students; ?></div>
                            <div class="stats-meta text-primary">
                                <i class="fas fa-user-graduate"></i> Across all classes
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat h-100 shadow-sm">
                        <div class="app-card-body p-3">
                            <div class="stats-meta text-info mb-1">
                                <i class="fas fa-book"></i>
                                <span class="stats-type">Credit Units</span>
                            </div>
                            <div class="stats-figure"><?php echo $total_credits; ?></div>
                            <div class="stats-meta text-info">
                                <i class="fas fa-clock"></i> Teaching load
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat h-100 shadow-sm">
                        <div class="app-card-body p-3">
                            <div class="stats-meta text-warning mb-1">
                                <i class="fas fa-file-alt"></i>
                                <span class="stats-type">Contract Status</span>
                            </div>
                            <div class="stats-figure" style="font-size: 1.5rem;">
                                <span class="badge bg-<?php 
                                    echo $staff['contract_status'] === 'Active' ? 'success' : 
                                         ($staff['contract_status'] === 'On Leave' ? 'warning' : 'danger'); 
                                ?> px-3 py-2">
                                    <?php echo $staff['contract_status']; ?>
                                </span>
                            </div>
                            <div class="stats-meta text-muted" style="font-size: 12px;">
                                <?php if ($staff['contract_end']): ?>
                                Expires: <?php echo date('M d, Y', strtotime($staff['contract_end'])); ?>
                                <?php else: ?>
                                No expiry set
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Row -->
            <div class="row g-4">
                <!-- My Classes Table -->
                <div class="col-12 col-lg-8">
                    <div class="app-card shadow-sm">
                        <div class="app-card-header d-flex justify-content-between align-items-center">
                            <h4 class="app-card-title mb-0">
                                <i class="fas fa-chalkboard me-2 text-primary"></i>My Classes (<?php echo $session_year; ?> - Semester <?php echo $semester; ?>)
                            </h4>
                            <div>
                                <span class="badge bg-primary"><?php echo $total_classes; ?> Classes</span>
                            </div>
                        </div>
                        <div class="app-card-body p-0">
                            <?php if (empty($assigned_classes)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No classes assigned for this semester.</p>
                                <?php if (hasPermission('academics', 'manage_courses')): ?>
                                <a href="assign_classes.php" class="btn btn-sm btn-primary">Assign Classes</a>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table app-table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="cell">Course Code</th>
                                            <th class="cell">Course Title</th>
                                            <th class="cell">Program</th>
                                            <th class="cell text-center">Level</th>
                                            <th class="cell text-center">Credits</th>
                                            <th class="cell text-center">Students</th>
                                            <th class="cell text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assigned_classes as $class): ?>
                                        <tr>
                                            <td class="cell">
                                                <span class="badge bg-dark"><?php echo htmlspecialchars($class['course_code']); ?></span>
                                            </td>
                                            <td class="cell">
                                                <strong><?php echo htmlspecialchars($class['course_title']); ?></strong>
                                            </td>
                                            <td class="cell">
                                                <small class="text-muted"><?php echo htmlspecialchars($class['program_name'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td class="cell text-center">
                                                <span class="badge bg-secondary"><?php echo $class['level']; ?>L</span>
                                            </td>
                                            <td class="cell text-center">
                                                <span class="badge bg-info"><?php echo $class['credit_units']; ?></span>
                                            </td>
                                            <td class="cell text-center">
                                                <a href="class_students.php?course_id=<?php echo $class['course_id']; ?>&session=<?php echo $session_year; ?>&semester=<?php echo $semester; ?>" 
                                                   class="badge bg-success text-decoration-none">
                                                    <i class="fas fa-users me-1"></i><?php echo $class['student_count']; ?>
                                                </a>
                                            </td>
                                            <td class="cell text-center">
                                                <div class="btn-group">
                                                    <a href="view_class.php?course_id=<?php echo $class['course_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="View Class">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (hasPermission('academics', 'manage_courses')): ?>
                                                    <a href="upload_results.php?course_id=<?php echo $class['course_id']; ?>" 
                                                       class="btn btn-sm btn-outline-success" title="Upload Results">
                                                        <i class="fas fa-upload"></i>
                                                    </a>
                                                    <a href="attendance.php?course_id=<?php echo $class['course_id']; ?>" 
                                                       class="btn btn-sm btn-outline-info" title="Attendance">
                                                        <i class="fas fa-clipboard-check"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Widgets -->
                <div class="col-12 col-lg-4">
                    <!-- Contract Info Card -->
                    <div class="app-card shadow-sm mb-4">
                        <div class="app-card-header">
                            <h5 class="app-card-title mb-0">
                                <i class="fas fa-file-contract me-2 text-warning"></i>Contract Information
                            </h5>
                        </div>
                        <div class="app-card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Employment Type</span>
                                    <span class="badge bg-primary"><?php echo $staff['employment_type']; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Designation</span>
                                    <span><?php echo htmlspecialchars($staff['designation'] ?? 'N/A'); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Department</span>
                                    <span><?php echo htmlspecialchars($staff['department_name'] ?? 'N/A'); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Contract Start</span>
                                    <span><?php echo $staff['contract_start'] ? date('M d, Y', strtotime($staff['contract_start'])) : 'N/A'; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Contract End</span>
                                    <span class="<?php echo ($staff['contract_end'] && strtotime($staff['contract_end']) < time()) ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo $staff['contract_end'] ? date('M d, Y', strtotime($staff['contract_end'])) : 'N/A'; ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="text-muted">Qualification</span>
                                    <span><?php echo htmlspecialchars($staff['qualification'] ?? 'N/A'); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="app-card shadow-sm mb-4">
                        <div class="app-card-header">
                            <h5 class="app-card-title mb-0">
                                <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                            </h5>
                        </div>
                        <div class="app-card-body">
                            <div class="d-grid gap-2">
                                <?php if (hasPermission('academics', 'manage_courses')): ?>
                                <a href="upload_results.php" class="btn btn-outline-primary">
                                    <i class="fas fa-upload me-2"></i>Upload Results
                                </a>
                                <a href="attendance.php" class="btn btn-outline-info">
                                    <i class="fas fa-clipboard-check me-2"></i>Mark Attendance
                                </a>
                                <?php endif; ?>
                                <a href="course_materials.php" class="btn btn-outline-success">
                                    <i class="fas fa-book me-2"></i>Course Materials
                                </a>
                                <a href="student_queries.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-comments me-2"></i>Student Queries
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="app-card shadow-sm">
                        <div class="app-card-header">
                            <h5 class="app-card-title mb-0">
                                <i class="fas fa-history me-2 text-info"></i>Recent Activity
                            </h5>
                        </div>
                        <div class="app-card-body p-0">
                            <?php if (empty($activities)): ?>
                            <div class="text-center py-3 text-muted">
                                <small>No recent activity</small>
                            </div>
                            <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($activities as $activity): ?>
                                <div class="list-group-item px-3 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <small class="mb-1"><?php echo htmlspecialchars($activity['activity_type']); ?></small>
                                        <small class="text-muted"><?php echo timeAgo($activity['created_at']); ?></small>
                                    </div>
                                    <p class="mb-0 small text-muted"><?php echo htmlspecialchars($activity['description']); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Per Class Chart Section -->
            <?php if (!empty($assigned_classes)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="app-card shadow-sm">
                        <div class="app-card-header">
                            <h4 class="app-card-title mb-0">
                                <i class="fas fa-chart-bar me-2 text-primary"></i>Class Enrollment Overview
                            </h4>
                        </div>
                        <div class="app-card-body">
                            <canvas id="classEnrollmentChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!empty($assigned_classes)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('classEnrollmentChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($c) { return $c['course_code']; }, $assigned_classes)); ?>,
            datasets: [{
                label: 'Number of Students',
                data: <?php echo json_encode(array_map(function($c) { return $c['student_count']; }, $assigned_classes)); ?>,
                backgroundColor: 'rgba(67, 97, 238, 0.7)',
                borderColor: 'rgba(67, 97, 238, 1)',
                borderWidth: 1,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: function(context) {
                            const titles = <?php echo json_encode(array_map(function($c) { return $c['course_title']; }, $assigned_classes)); ?>;
                            return titles[context.dataIndex];
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>