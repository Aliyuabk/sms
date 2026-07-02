<?php
require_once 'includes/header.php';

$page_title = "Student Advisors Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_advisor'])) {
        try {
            $student_id = $_POST['student_id'];
            $advisor_id = $_POST['advisor_id'];
            
            // Check if student already has an active advisor
            $check_stmt = $pdo->prepare("
                SELECT advisor_id FROM student_advisors 
                WHERE student_id = ? AND status = 'Active'
            ");
            $check_stmt->execute([$student_id]);
            $existing_advisor = $check_stmt->fetch();
            
            if ($existing_advisor) {
                // Mark previous assignment as changed
                $update_stmt = $pdo->prepare("
                    UPDATE student_advisors SET status = 'Changed' 
                    WHERE student_id = ? AND status = 'Active'
                ");
                $update_stmt->execute([$student_id]);
            }
            
            // Assign new advisor
            $stmt = $pdo->prepare("INSERT INTO student_advisors 
                (student_id, advisor_id, assignment_reason, status)
                VALUES (?, ?, ?, 'Active')");
            
            $stmt->execute([
                $student_id,
                $advisor_id,
                $_POST['assignment_reason'] ?? 'Manual assignment'
            ]);
            
            // Update advisor's current student count
            $update_stmt = $pdo->prepare("UPDATE academic_advisors 
                SET current_students = current_students + 1 
                WHERE advisor_id = ?");
            $update_stmt->execute([$advisor_id]);
            
            $_SESSION['success_message'] = "Academic advisor assigned successfully!";
            header("Location: student_advisors.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error assigning advisor: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['reassign_advisor'])) {
        try {
            $assignment_id = $_POST['assignment_id'];
            $new_advisor_id = $_POST['new_advisor_id'];
            
            // Get current assignment details
            $current_stmt = $pdo->prepare("
                SELECT student_id, advisor_id 
                FROM student_advisors 
                WHERE id = ?
            ");
            $current_stmt->execute([$assignment_id]);
            $current = $current_stmt->fetch();
            
            if ($current) {
                // Mark current assignment as changed
                $update_stmt = $pdo->prepare("
                    UPDATE student_advisors SET status = 'Changed' 
                    WHERE id = ?
                ");
                $update_stmt->execute([$assignment_id]);
                
                // Decrement old advisor's count
                $decrement_stmt = $pdo->prepare("UPDATE academic_advisors 
                    SET current_students = current_students - 1 
                    WHERE advisor_id = ? AND current_students > 0");
                $decrement_stmt->execute([$current['advisor_id']]);
                
                // Create new assignment
                $stmt = $pdo->prepare("INSERT INTO student_advisors 
                    (student_id, advisor_id, assignment_reason, status)
                    VALUES (?, ?, ?, 'Active')");
                
                $stmt->execute([
                    $current['student_id'],
                    $new_advisor_id,
                    $_POST['reassignment_reason'] ?? 'Reassignment'
                ]);
                
                // Increment new advisor's count
                $increment_stmt = $pdo->prepare("UPDATE academic_advisors 
                    SET current_students = current_students + 1 
                    WHERE advisor_id = ?");
                $increment_stmt->execute([$new_advisor_id]);
                
                $_SESSION['success_message'] = "Advisor reassigned successfully!";
                header("Location: student_advisors.php");
                exit();
            }
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error reassigning advisor: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['remove_advisor'])) {
        try {
            $assignment_id = $_POST['assignment_id'];
            
            // Get assignment details
            $stmt = $pdo->prepare("
                SELECT advisor_id, student_id 
                FROM student_advisors 
                WHERE id = ?
            ");
            $stmt->execute([$assignment_id]);
            $assignment = $stmt->fetch();
            
            if ($assignment) {
                // Mark as completed
                $update_stmt = $pdo->prepare("
                    UPDATE student_advisors SET status = 'Completed' 
                    WHERE id = ?
                ");
                $update_stmt->execute([$assignment_id]);
                
                // Decrement advisor's count
                $decrement_stmt = $pdo->prepare("UPDATE academic_advisors 
                    SET current_students = current_students - 1 
                    WHERE advisor_id = ? AND current_students > 0");
                $decrement_stmt->execute([$assignment['advisor_id']]);
                
                $_SESSION['success_message'] = "Advisor assignment removed successfully!";
                header("Location: student_advisors.php");
                exit();
            }
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error removing advisor: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$advisor_id = $_GET['advisor_id'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$status = $_GET['status'] ?? 'Active';

// Build WHERE clause for assignments
$where_conditions = [];
$params = [];

if (!empty($advisor_id)) {
    $where_conditions[] = "sa.advisor_id = ?";
    $params[] = $advisor_id;
}

if (!empty($department_id)) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if (!empty($status)) {
    $where_conditions[] = "sa.status = ?";
    $params[] = $status;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get student-advisor assignments
$assignments_sql = "
    SELECT sa.*,
           s.student_id, s.matric_number, s.first_name as student_first, s.last_name as student_last,
           s.email as student_email, s.current_level, s.department_id,
           a.advisor_id, a.first_name as advisor_first, a.last_name as advisor_last,
           a.email as advisor_email, a.phone as advisor_phone, a.department_id as advisor_dept_id,
           d.department_name,
           ad.department_name as advisor_department
    FROM student_advisors sa
    JOIN students s ON sa.student_id = s.student_id
    JOIN academic_advisors a ON sa.advisor_id = a.advisor_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN departments ad ON a.department_id = ad.department_id
    $where_sql
    ORDER BY sa.assigned_date DESC
";

$assignments_stmt = $pdo->prepare($assignments_sql);
$assignments_stmt->execute($params);
$assignments = $assignments_stmt->fetchAll();

// Get advisors for dropdowns
$advisors = $pdo->query("
    SELECT a.*, d.department_name,
           COUNT(sa.id) as assigned_students
    FROM academic_advisors a
    LEFT JOIN departments d ON a.department_id = d.department_id
    LEFT JOIN student_advisors sa ON a.advisor_id = sa.advisor_id AND sa.status = 'Active'
    WHERE a.status = 'Active'
    GROUP BY a.advisor_id
    ORDER BY a.first_name, a.last_name
")->fetchAll();

// Get departments
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

// Get students without advisors
$unassigned_students = $pdo->query("
    SELECT s.*, d.department_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.status = 'Active'
    AND NOT EXISTS (
        SELECT 1 FROM student_advisors sa 
        WHERE sa.student_id = s.student_id AND sa.status = 'Active'
    )
    ORDER BY s.matric_number
    LIMIT 50
")->fetchAll();

// Get advisor workload statistics
$workload_stats = $pdo->query("
    SELECT 
        a.advisor_id,
        CONCAT(a.first_name, ' ', a.last_name) as advisor_name,
        a.department_id,
        d.department_name,
        a.max_students,
        a.current_students,
        COUNT(DISTINCT sa.student_id) as assigned_students,
        ROUND((a.current_students / a.max_students) * 100, 1) as workload_percentage
    FROM academic_advisors a
    LEFT JOIN departments d ON a.department_id = d.department_id
    LEFT JOIN student_advisors sa ON a.advisor_id = sa.advisor_id AND sa.status = 'Active'
    WHERE a.status = 'Active'
    GROUP BY a.advisor_id
    ORDER BY workload_percentage DESC
")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Student Advisors Management</h1>
            <p class="text-muted">Manage academic advisor assignments and workload</p>
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

<!-- Filters -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Filters</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Advisor</label>
                        <select class="form-select" name="advisor_id">
                            <option value="">All Advisors</option>
                            <?php foreach ($advisors as $advisor): ?>
                            <option value="<?php echo $advisor['advisor_id']; ?>" 
                                    <?php echo $advisor_id == $advisor['advisor_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                                (<?php echo $advisor['current_students']; ?>/<?php echo $advisor['max_students']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_id">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" 
                                    <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="Active" <?php echo $status === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Changed" <?php echo $status === 'Changed' ? 'selected' : ''; ?>>Changed</option>
                            <option value="Completed" <?php echo $status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i>
                            </button>
                            <a href="student_advisors.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Advisor Workload -->
    <div class="col-md-4 mb-4">
        <div class="app-card app-card-settings shadow-sm h-100">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Advisor Workload</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Advisor</th>
                                <th>Current</th>
                                <th>Max</th>
                                <th>Workload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workload_stats as $stat): 
                                $workload_class = $stat['workload_percentage'] >= 90 ? 'danger' : 
                                                ($stat['workload_percentage'] >= 70 ? 'warning' : 'success');
                            ?>
                            <tr>
                                <td>
                                    <small><?php echo htmlspecialchars($stat['advisor_name']); ?></small><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($stat['department_name']); ?></small>
                                </td>
                                <td><?php echo $stat['current_students']; ?></td>
                                <td><?php echo $stat['max_students']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                            <div class="progress-bar bg-<?php echo $workload_class; ?>" 
                                                 style="width: <?php echo min($stat['workload_percentage'], 100); ?>%"></div>
                                        </div>
                                        <small><?php echo $stat['workload_percentage']; ?>%</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Assign -->
    <div class="col-md-8 mb-4">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Quick Advisor Assignment</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Select Student</label>
                        <select class="form-select" name="student_id" required>
                            <option value="">Select Student</option>
                            <?php foreach ($unassigned_students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>">
                                <?php echo htmlspecialchars($student['matric_number'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                (<?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Showing <?php echo count($unassigned_students); ?> unassigned students</small>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Select Advisor</label>
                        <select class="form-select" name="advisor_id" required>
                            <option value="">Select Advisor</option>
                            <?php foreach ($advisors as $advisor): 
                                $available = $advisor['max_students'] - $advisor['current_students'];
                            ?>
                            <option value="<?php echo $advisor['advisor_id']; ?>" 
                                    data-available="<?php echo $available; ?>">
                                <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                                (Available: <?php echo $available; ?> slots)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" name="assign_advisor" class="btn btn-primary w-100">
                            <i class="fas fa-user-plus me-1"></i> Assign
                        </button>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Assignment Reason</label>
                        <input type="text" class="form-control" name="assignment_reason" 
                               value="Manual assignment by admin">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Current Assignments -->
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-header p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="app-card-title mb-0">
                        Current Advisor Assignments
                        <span class="badge bg-primary ms-2"><?php echo count($assignments); ?></span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportAssignments()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Advisor</th>
                                <th>Department</th>
                                <th>Assignment Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['student_first'] . ' ' . $assignment['student_last']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($assignment['matric_number']); ?></small><br>
                                    <small>Level: <?php echo $assignment['current_level']; ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['advisor_first'] . ' ' . $assignment['advisor_last']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($assignment['advisor_email']); ?></small><br>
                                    <small><?php echo htmlspecialchars($assignment['advisor_phone'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($assignment['department_name'] ?? 'N/A'); ?><br>
                                    <small class="text-muted">Advisor Dept: <?php echo htmlspecialchars($assignment['advisor_department'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?><br>
                                    <small class="text-muted">
                                        <?php 
                                        $days_assigned = floor((time() - strtotime($assignment['assigned_date'])) / (60 * 60 * 24));
                                        echo $days_assigned . ' days ago';
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($assignment['status'] === 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php elseif ($assignment['status'] === 'Changed'): ?>
                                        <span class="badge bg-warning">Changed</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo $assignment['status']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($assignment['status'] === 'Active'): ?>
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" data-bs-target="#reassignModal"
                                                    onclick="setReassignData(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['student_first'] . ' ' . $assignment['student_last']); ?>', '<?php echo htmlspecialchars($assignment['advisor_first'] . ' ' . $assignment['advisor_last']); ?>')">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmRemove(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['student_first'] . ' ' . $assignment['student_last']); ?>')">
                                                <i class="fas fa-user-minus"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="view_assignment.php?id=<?php echo $assignment['id']; ?>" 
                                           class="btn btn-outline-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                    
                                    <!-- Remove Form -->
                                    <form method="POST" id="remove-form-<?php echo $assignment['id']; ?>" class="d-none">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                        <input type="hidden" name="remove_advisor" value="1">
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-users fa-2x text-muted mb-3"></i>
                                    <h5>No assignments found</h5>
                                    <p class="text-muted">Use the quick assignment form above to assign advisors to students.</p>
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

<!-- Reassign Modal -->
<div class="modal fade" id="reassignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reassign Advisor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="reassignForm">
                <div class="modal-body">
                    <input type="hidden" name="assignment_id" id="reassignAssignmentId">
                    <input type="hidden" name="reassign_advisor" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Current Assignment</label>
                        <div class="alert alert-secondary">
                            <strong id="currentStudent">Student Name</strong><br>
                            Currently assigned to: <strong id="currentAdvisor">Advisor Name</strong>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Advisor *</label>
                        <select class="form-select" name="new_advisor_id" required>
                            <option value="">Select New Advisor</option>
                            <?php foreach ($advisors as $advisor): 
                                $available = $advisor['max_students'] - $advisor['current_students'];
                            ?>
                            <option value="<?php echo $advisor['advisor_id']; ?>">
                                <?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?>
                                (Available: <?php echo $available; ?> slots)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reassignment Reason *</label>
                        <input type="text" class="form-control" name="reassignment_reason" 
                               value="Reassignment by administrator" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reassign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setReassignData(assignmentId, studentName, advisorName) {
    document.getElementById('reassignAssignmentId').value = assignmentId;
    document.getElementById('currentStudent').textContent = studentName;
    document.getElementById('currentAdvisor').textContent = advisorName;
}

function confirmRemove(assignmentId, studentName) {
    if (confirm(`Remove advisor assignment for ${studentName}? The advisor's student count will be decremented.`)) {
        document.getElementById(`remove-form-${assignmentId}`).submit();
    }
}

function exportAssignments() {
    // Create CSV content
    let csv = 'Student,Matric Number,Advisor,Advisor Email,Advisor Phone,Department,Assignment Date,Status\n';
    
    <?php foreach ($assignments as $assignment): ?>
    csv += `"<?php echo addslashes($assignment['student_first'] . ' ' . $assignment['student_last']); ?>",` +
           `"<?php echo addslashes($assignment['matric_number']); ?>",` +
           `"<?php echo addslashes($assignment['advisor_first'] . ' ' . $assignment['advisor_last']); ?>",` +
           `"<?php echo addslashes($assignment['advisor_email']); ?>",` +
           `"<?php echo addslashes($assignment['advisor_phone'] ?? ''); ?>",` +
           `"<?php echo addslashes($assignment['department_name'] ?? ''); ?>",` +
           `"<?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?>",` +
           `"<?php echo $assignment['status']; ?>"\n`;
    <?php endforeach; ?>
    
    // Create download link
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `advisor_assignments_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Advisor availability validation
document.querySelector('select[name="advisor_id"]').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const available = parseInt(selectedOption.getAttribute('data-available') || 0);
    
    if (available <= 0 && this.value) {
        alert('Selected advisor has no available slots. Please choose another advisor.');
        this.value = '';
    }
});

// Form validation for quick assign
document.querySelector('form[action*="student_advisors.php"]').addEventListener('submit', function(e) {
    const studentSelect = this.querySelector('select[name="student_id"]');
    const advisorSelect = this.querySelector('select[name="advisor_id"]');
    
    if (!studentSelect.value) {
        e.preventDefault();
        alert('Please select a student.');
        studentSelect.focus();
        return false;
    }
    
    if (!advisorSelect.value) {
        e.preventDefault();
        alert('Please select an advisor.');
        advisorSelect.focus();
        return false;
    }
    
    return confirm('Assign selected advisor to student?');
});
</script>

<?php
require_once 'includes/footer.php';
?>