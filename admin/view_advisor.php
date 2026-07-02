<?php
// view_advisor.php
ob_start();

require_once 'includes/header.php';

// Get advisor ID from URL
$advisor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($advisor_id <= 0) {
    $_SESSION['error_message'] = "Invalid advisor ID";
    header("Location: academic_advisors.php");
    exit();
}

// Fetch advisor details
try {
    $stmt = $pdo->prepare("
        SELECT 
            aa.*,
            d.department_name,
            d.department_code,
            d.faculty,
            COUNT(DISTINCT sa.student_id) as current_students_count,
            GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) SEPARATOR '|') as student_names
        FROM academic_advisors aa
        LEFT JOIN departments d ON aa.department_id = d.department_id
        LEFT JOIN student_advisors sa ON aa.advisor_id = sa.advisor_id AND sa.status = 'Active'
        LEFT JOIN students s ON sa.student_id = s.student_id
        WHERE aa.advisor_id = ?
        GROUP BY aa.advisor_id
    ");
    $stmt->execute([$advisor_id]);
    $advisor = $stmt->fetch();

    if (!$advisor) {
        $_SESSION['error_message'] = "Advisor not found";
        header("Location: academic_advisors.php");
        exit();
    }

    // Fetch assigned students with details
    $students_stmt = $pdo->prepare("
        SELECT 
            s.*,
            sa.assigned_date,
            sa.notes as assignment_notes,
            p.program_name,
            d.department_name,
            (SELECT COUNT(*) FROM course_registrations cr 
             WHERE cr.student_id = s.student_id AND cr.session_year = ?) as current_courses,
            (SELECT SUM(sf.balance) FROM student_fees sf 
             WHERE sf.student_id = s.student_id AND sf.status IN ('Pending', 'Partial')) as fee_balance,
            (SELECT COUNT(*) FROM hostel_allocations ha 
             WHERE ha.student_id = s.student_id AND ha.status = 'Active') as has_hostel
        FROM student_advisors sa
        JOIN students s ON sa.student_id = s.student_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE sa.advisor_id = ? AND sa.status = 'Active'
        ORDER BY s.current_level DESC, s.last_name ASC
    ");
    
    $current_session = date('Y') . '/' . (date('Y') + 1);
    $students_stmt->execute([$current_session, $advisor_id]);
    $assigned_students = $students_stmt->fetchAll();

    // Fetch available students (not assigned to any advisor)
    $available_stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            s.matric_number,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.current_level,
            s.email,
            s.phone,
            d.department_name,
            p.program_name,
            s.cgpa
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.department_id = ? 
            AND s.status = 'Active'
            AND NOT EXISTS (
                SELECT 1 FROM student_advisors sa 
                WHERE sa.student_id = s.student_id AND sa.status = 'Active'
            )
        ORDER BY s.current_level DESC, s.last_name ASC
        LIMIT 50
    ");
    $available_stmt->execute([$advisor['department_id']]);
    $available_students = $available_stmt->fetchAll();

    // Fetch recent meetings/activities
    $activities_stmt = $pdo->prepare("
        (SELECT 
            'allocation' as type,
            sa.assigned_date as date,
            CONCAT('Assigned as advisor to ', s.first_name, ' ', s.last_name) as description,
            NULL as details
        FROM student_advisors sa
        JOIN students s ON sa.student_id = s.student_id
        WHERE sa.advisor_id = ?)
        
        UNION ALL
        
        (SELECT 
            'meeting' as type,
            created_at as date,
            'Advisor meeting' as description,
            notes as details
        FROM advisor_meetings
        WHERE advisor_id = ?)
        
        ORDER BY date DESC
        LIMIT 20
    ");
    $activities_stmt->execute([$advisor_id, $advisor_id]);
    $activities = $activities_stmt->fetchAll();

    // Fetch performance summary
    $performance_stmt = $pdo->prepare("
        SELECT 
            AVG(s.cgpa) as avg_cgpa,
            COUNT(CASE WHEN s.cgpa >= 3.5 THEN 1 END) as excellent_students,
            COUNT(CASE WHEN s.cgpa < 2.0 AND s.cgpa > 0 THEN 1 END) as at_risk_students,
            COUNT(CASE WHEN s.cgpa = 0 THEN 1 END) as new_students
        FROM students s
        JOIN student_advisors sa ON s.student_id = sa.student_id
        WHERE sa.advisor_id = ? AND sa.status = 'Active'
    ");
    $performance_stmt->execute([$advisor_id]);
    $performance = $performance_stmt->fetch();

    $page_title = htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']) . " - Advisor Profile";

} catch (Exception $e) {
    error_log("Error fetching advisor details: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading advisor details";
    header("Location: academic_advisors.php");
    exit();
}

// Handle assign student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_student'])) {
    $student_id = (int)$_POST['student_id'];
    $assignment_notes = trim($_POST['assignment_notes']);

    try {
        // Check if student already has an advisor
        $check_stmt = $pdo->prepare("
            SELECT advisor_id FROM student_advisors 
            WHERE student_id = ? AND status = 'Active'
        ");
        $check_stmt->execute([$student_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $_SESSION['error_message'] = "Student already has an active advisor";
        } else {
            // Check advisor capacity
            if ($advisor['current_students'] >= $advisor['max_students']) {
                $_SESSION['error_message'] = "Advisor has reached maximum student capacity";
            } else {
                $pdo->beginTransaction();

                // Assign student
                $insert_stmt = $pdo->prepare("
                    INSERT INTO student_advisors (student_id, advisor_id, assigned_date, notes, status)
                    VALUES (?, ?, CURDATE(), ?, 'Active')
                ");
                $insert_stmt->execute([$student_id, $advisor_id, $assignment_notes]);

                // Update advisor count
                $update_stmt = $pdo->prepare("
                    UPDATE academic_advisors 
                    SET current_students = current_students + 1 
                    WHERE advisor_id = ?
                ");
                $update_stmt->execute([$advisor_id]);

                // Log activity
                $log_stmt = $pdo->prepare("
                    INSERT INTO admin_logs (admin_id, action, description, table_name, record_id) 
                    VALUES (?, 'Assign', ?, 'student_advisors', ?)
                ");
                $log_stmt->execute([
                    $_SESSION['admin_id'],
                    "Assigned student ID: $student_id to advisor ID: $advisor_id",
                    $pdo->lastInsertId()
                ]);

                $pdo->commit();
                $_SESSION['success_message'] = "Student assigned successfully!";
                header("Location: view_advisor.php?id=$advisor_id");
                exit();
            }
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error assigning student: " . $e->getMessage());
        $_SESSION['error_message'] = "Error assigning student: " . $e->getMessage();
    }
}

// Handle remove student
if (isset($_GET['remove_student'])) {
    $student_id = (int)$_GET['remove_student'];
    
    try {
        $pdo->beginTransaction();

        // Remove assignment
        $remove_stmt = $pdo->prepare("
            UPDATE student_advisors 
            SET status = 'Completed', end_date = CURDATE() 
            WHERE student_id = ? AND advisor_id = ? AND status = 'Active'
        ");
        $remove_stmt->execute([$student_id, $advisor_id]);

        if ($remove_stmt->rowCount() > 0) {
            // Update advisor count
            $update_stmt = $pdo->prepare("
                UPDATE academic_advisors 
                SET current_students = current_students - 1 
                WHERE advisor_id = ?
            ");
            $update_stmt->execute([$advisor_id]);

            // Log activity
            $log_stmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, table_name) 
                VALUES (?, 'Remove', ?, 'student_advisors')
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                "Removed student ID: $student_id from advisor ID: $advisor_id"
            ]);

            $pdo->commit();
            $_SESSION['success_message'] = "Student removed from advisor successfully!";
        } else {
            $_SESSION['error_message'] = "Assignment not found or already completed";
        }
        
        header("Location: view_advisor.php?id=$advisor_id");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error removing student: " . $e->getMessage());
        $_SESSION['error_message'] = "Error removing student: " . $e->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="academic_advisors.php">Academic Advisors</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?></li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Advisor Profile</h1>
    </div>
    <div class="app-actions">
        <a href="edit_advisor.php?id=<?php echo $advisor_id; ?>" class="btn btn-warning me-2">
            <i class="fas fa-edit me-2"></i>Edit Advisor
        </a>
        <a href="advisor_students.php?id=<?php echo $advisor_id; ?>" class="btn btn-primary">
            <i class="fas fa-users me-2"></i>Manage Students
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<!-- Advisor Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Current Students</div>
                        <div class="stats-figure"><?php echo count($assigned_students); ?> / <?php echo $advisor['max_students']; ?></div>
                    </div>
                    <div class="app-icon-holder bg-primary bg-opacity-10">
                        <i class="fas fa-users text-primary"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 5px;">
                    <?php $capacity_percent = ($advisor['current_students'] / $advisor['max_students']) * 100; ?>
                    <div class="progress-bar bg-<?php echo $capacity_percent >= 90 ? 'danger' : ($capacity_percent >= 70 ? 'warning' : 'success'); ?>" 
                         style="width: <?php echo min($capacity_percent, 100); ?>%"></div>
                </div>
                <div class="stats-meta mt-2">
                    <?php echo $advisor['max_students'] - $advisor['current_students']; ?> slots available
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Average CGPA</div>
                        <div class="stats-figure"><?php echo number_format($performance['avg_cgpa'] ?? 0, 2); ?></div>
                    </div>
                    <div class="app-icon-holder bg-success bg-opacity-10">
                        <i class="fas fa-chart-line text-success"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <span class="text-success">
                        <i class="fas fa-arrow-up me-1"></i><?php echo $performance['excellent_students'] ?? 0; ?> excellent
                    </span>
                    <span class="text-danger ms-2">
                        <i class="fas fa-arrow-down me-1"></i><?php echo $performance['at_risk_students'] ?? 0; ?> at risk
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Department</div>
                        <div class="stats-figure"><?php echo htmlspecialchars($advisor['department_code'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="app-icon-holder bg-info bg-opacity-10">
                        <i class="fas fa-building text-info"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <?php echo htmlspecialchars($advisor['department_name'] ?? ''); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Status</div>
                        <div class="stats-figure">
                            <span class="badge bg-<?php echo $advisor['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                <?php echo $advisor['status']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="app-icon-holder bg-warning bg-opacity-10">
                        <i class="fas fa-id-card text-warning"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    Staff ID: <?php echo htmlspecialchars($advisor['staff_id'] ?? 'N/A'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column - Advisor Info & Quick Actions -->
    <div class="col-lg-4">
        <!-- Advisor Information Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-user-tie me-2"></i>Advisor Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="text-center mb-4">
                    <div class="app-icon-holder icon-holder-lg bg-primary bg-opacity-10 mx-auto mb-3">
                        <i class="fas fa-user-graduate fa-3x text-primary"></i>
                    </div>
                    <h4><?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?></h4>
                    <p class="text-muted mb-1"><?php echo htmlspecialchars($advisor['staff_id']); ?></p>
                    <span class="badge bg-<?php echo $advisor['status'] == 'Active' ? 'success' : 'secondary'; ?>">
                        <?php echo $advisor['status']; ?>
                    </span>
                </div>

                <table class="table table-borderless">
                    <tr>
                        <td width="40%"><i class="fas fa-envelope me-2 text-muted"></i>Email:</td>
                        <td><a href="mailto:<?php echo htmlspecialchars($advisor['email']); ?>"><?php echo htmlspecialchars($advisor['email']); ?></a></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-phone me-2 text-muted"></i>Phone:</td>
                        <td><a href="tel:<?php echo htmlspecialchars($advisor['phone']); ?>"><?php echo htmlspecialchars($advisor['phone'] ?? 'N/A'); ?></a></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-building me-2 text-muted"></i>Department:</td>
                        <td><?php echo htmlspecialchars($advisor['department_name'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-layer-group me-2 text-muted"></i>Faculty:</td>
                        <td><?php echo htmlspecialchars($advisor['faculty'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-users me-2 text-muted"></i>Student Capacity:</td>
                        <td><?php echo $advisor['current_students']; ?> / <?php echo $advisor['max_students']; ?></td>
                    </tr>
                </table>

                <?php if ($advisor['status'] == 'Active' && $advisor['current_students'] < $advisor['max_students']): ?>
                <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#assignStudentModal">
                    <i class="fas fa-user-plus me-2"></i>Assign New Student
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Student Distribution
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php
                $level_distribution = [];
                foreach ($assigned_students as $student) {
                    $level = $student['current_level'] ?? 'Unknown';
                    $level_distribution[$level] = ($level_distribution[$level] ?? 0) + 1;
                }
                ?>
                
                <?php foreach ($level_distribution as $level => $count): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Level <?php echo $level; ?></span>
                        <span class="fw-bold"><?php echo $count; ?> students</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-info" style="width: <?php echo ($count / count($assigned_students)) * 100; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($assigned_students)): ?>
                <p class="text-muted text-center py-3">No students assigned yet</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activities -->
        <?php if (!empty($activities)): ?>
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-history me-2"></i>Recent Activities
                </h5>
            </div>
            <div class="app-card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($activities as $activity): ?>
                    <div class="list-group-item">
                        <div class="d-flex">
                            <div class="me-3">
                                <?php if ($activity['type'] == 'allocation'): ?>
                                <span class="badge bg-success rounded-pill p-2">
                                    <i class="fas fa-user-plus"></i>
                                </span>
                                <?php else: ?>
                                <span class="badge bg-info rounded-pill p-2">
                                    <i class="fas fa-calendar-check"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                <?php if ($activity['details']): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($activity['details']); ?></small><br>
                                <?php endif; ?>
                                <small class="text-muted">
                                    <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column - Students List -->
    <div class="col-lg-8">
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="app-card-title mb-0">
                            <i class="fas fa-users me-2"></i>Assigned Students
                            <span class="badge bg-primary ms-2"><?php echo count($assigned_students); ?></span>
                        </h5>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" id="studentSearch" placeholder="Search students...">
                            <button class="btn btn-outline-primary" type="button">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-card-body p-0">
                <?php if (!empty($assigned_students)): ?>
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0" id="studentsTable">
                        <thead>
                            <tr>
                                <th class="cell">Matric No</th>
                                <th class="cell">Student Name</th>
                                <th class="cell">Level</th>
                                <th class="cell">Program</th>
                                <th class="cell">CGPA</th>
                                <th class="cell">Status</th>
                                <th class="cell text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assigned_students as $student): ?>
                            <tr>
                                <td class="cell">
                                    <span class="fw-bold"><?php echo htmlspecialchars($student['matric_number']); ?></span>
                                </td>
                                <td class="cell">
                                    <div><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                </td>
                                <td class="cell"><?php echo $student['current_level']; ?></td>
                                <td class="cell">
                                    <small><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></small>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $cgpa_class = 'success';
                                    if ($student['cgpa'] < 1.0) $cgpa_class = 'danger';
                                    elseif ($student['cgpa'] < 2.0) $cgpa_class = 'warning';
                                    elseif ($student['cgpa'] < 3.0) $cgpa_class = 'info';
                                    ?>
                                    <span class="badge bg-<?php echo $cgpa_class; ?>">
                                        <?php echo number_format($student['cgpa'], 2); ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_icons = [];
                                    if ($student['has_hostel']) $status_icons[] = '<i class="fas fa-bed text-info" title="Has hostel"></i>';
                                    if ($student['fee_balance'] > 0) $status_icons[] = '<i class="fas fa-exclamation-circle text-warning" title="Fee balance"></i>';
                                    if ($student['current_courses'] == 0) $status_icons[] = '<i class="fas fa-book text-danger" title="No courses"></i>';
                                    
                                    if (!empty($status_icons)): ?>
                                        <div class="d-flex gap-1">
                                            <?php foreach ($status_icons as $icon): ?>
                                                <?php echo $icon; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell text-end">
                                    <div class="btn-group">
                                        <a href="view_student.php?id=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="View Student">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="advisor_notes.php?student_id=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="Add Notes">
                                            <i class="fas fa-sticky-note"></i>
                                        </a>
                                        <a href="?id=<?php echo $advisor_id; ?>&remove_student=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Remove this student from advisor?')"
                                           title="Remove">
                                            <i class="fas fa-user-minus"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5>No Students Assigned</h5>
                    <p class="text-muted">This advisor has no students assigned yet.</p>
                    <?php if (!empty($available_students)): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignStudentModal">
                        <i class="fas fa-user-plus me-2"></i>Assign First Student
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (count($assigned_students) > 10): ?>
            <div class="app-card-footer p-3">
                <a href="advisor_students.php?id=<?php echo $advisor_id; ?>" class="btn btn-link">
                    View All Students <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Assign Student Modal -->
<div class="modal fade" id="assignStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Assign Student to <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="assignStudentForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Student</label>
                        <select class="form-select" name="student_id" required>
                            <option value="">Choose a student...</option>
                            <?php foreach ($available_students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>">
                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['student_name'] . ' (Level ' . $student['current_level'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($available_students)): ?>
                        <div class="alert alert-warning mt-2">
                            <i class="fas fa-info-circle me-2"></i>
                            No available students in this department. All students already have advisors.
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Assignment Notes</label>
                        <textarea class="form-control" name="assignment_notes" rows="3" 
                                  placeholder="Optional notes about this assignment..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Advisor capacity: <?php echo $advisor['current_students']; ?>/<?php echo $advisor['max_students']; ?> students
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="assignStudentForm" name="assign_student" class="btn btn-primary" 
                        <?php echo empty($available_students) ? 'disabled' : ''; ?>>
                    <i class="fas fa-save me-2"></i>Assign Student
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Search functionality
document.getElementById('studentSearch')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('studentsTable');
    if (!table) return;
    
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    Array.from(rows).forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        bootstrap.Alert.getOrCreateInstance(alert).close();
    });
}, 5000);

// Prevent double form submission
document.getElementById('assignStudentForm')?.addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Assigning...';
});
</script>

<?php
require_once 'includes/footer.php';
?>