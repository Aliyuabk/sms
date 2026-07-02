<?php
require_once 'includes/header.php';

$page_title = "Approve Results";
$current_session ='';
// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_results'])) {
        try {
            $result_ids = $_POST['result_ids'] ?? [];
            $approved_by = $admin_id;
            
            if (empty($result_ids)) {
                throw new Exception('No results selected for approval.');
            }
            
            $placeholders = str_repeat('?,', count($result_ids) - 1) . '?';
            $update_stmt = $pdo->prepare("
                UPDATE results SET 
                    is_published = 1,
                    published_date = NOW(),
                    published_by = ?
                WHERE result_id IN ($placeholders)
                AND is_published = 0
            ");
            
            $params = array_merge([$approved_by], $result_ids);
            $update_stmt->execute($params);
            
            $affected_rows = $update_stmt->rowCount();
            
            // Create notification
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (title, message, notification_type, sent_to)
                VALUES ('Results Approved', CONCAT('Results for ', ?, ' student(s) have been approved and published'), 'academic', 'all_students')
            ");
            $notif_stmt->execute([$affected_rows]);
            
            $_SESSION['success_message'] = "Successfully approved and published $affected_rows result(s)!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error approving results: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_result'])) {
        try {
            $result_id = $_POST['result_id'];
            $rejection_reason = $_POST['rejection_reason'];
            
            if (empty($rejection_reason)) {
                throw new Exception('Please provide a rejection reason.');
            }
            
            $update_stmt = $pdo->prepare("
                UPDATE results SET 
                    is_published = 0,
                    rejection_reason = ?,
                    published_date = NULL,
                    published_by = NULL
                WHERE result_id = ?
            ");
            
            $update_stmt->execute([$rejection_reason, $result_id]);
            
            $_SESSION['success_message'] = "Result rejected successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error rejecting result: " . $e->getMessage();
        }
    }
}

// Get filters
$course_id = $_GET['course_id'] ?? '';
$session_year = $_GET['session_year'] ?? $current_session;
$semester = $_GET['semester'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$status = $_GET['status'] ?? 'pending'; // pending, approved, all

// Build query
$query = "
    SELECT 
        r.*,
        s.matric_number,
        s.first_name,
        s.last_name,
        s.current_level,
        c.course_code,
        c.course_title,
        c.credit_units,
        d.department_name,
        a.full_name as approved_by_name
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN admin_users a ON r.published_by = a.admin_id
    WHERE 1=1
";

$params = [];

// Apply filters
if ($course_id) {
    $query .= " AND r.course_id = ?";
    $params[] = $course_id;
}

if ($session_year) {
    $query .= " AND r.session_year = ?";
    $params[] = $session_year;
}

if ($semester) {
    $query .= " AND r.semester = ?";
    $params[] = $semester;
}

if ($department_id) {
    $query .= " AND s.department_id = ?";
    $params[] = $department_id;
}

if ($status === 'pending') {
    $query .= " AND r.is_published = 0";
} elseif ($status === 'approved') {
    $query .= " AND r.is_published = 1";
}

$query .= " ORDER BY r.session_year DESC, r.semester DESC, c.course_code, s.matric_number";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Get filters data
$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Approve Results</h1>
            <p class="text-muted">Review, approve or reject student results</p>
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

<!-- Filter Section -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Filter Results</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="GET" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Course</label>
                            <select class="form-select" name="course_id">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course['course_id']; ?>" <?php echo $course_id == $course['course_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['course_code']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Session</label>
                            <select class="form-select" name="session_year">
                                <option value="">All Sessions</option>
                                <?php 
                                $current_year = date('Y');
                                for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                    $session = ($i) . '/' . ($i + 1);
                                ?>
                                <option value="<?php echo $session; ?>" <?php echo $session_year == $session ? 'selected' : ''; ?>>
                                    <?php echo $session; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Semester</label>
                            <select class="form-select" name="semester">
                                <option value="">All Semesters</option>
                                <option value="1" <?php echo $semester == '1' ? 'selected' : ''; ?>>First</option>
                                <option value="2" <?php echo $semester == '2' ? 'selected' : ''; ?>>Second</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>" <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="approve_results.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Results Table -->
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3 d-flex justify-content-between align-items-center">
                <h6 class="app-card-title mb-0">
                    Results (<?php echo count($results); ?> records found)
                    <?php if ($status === 'pending'): ?>
                    <span class="badge bg-warning ms-2">Pending Approval</span>
                    <?php endif; ?>
                </h6>
                
                <?php if ($status === 'pending' && count($results) > 0): ?>
                <form method="POST" id="bulkApproveForm" class="d-inline">
                    <button type="submit" name="approve_results" class="btn btn-success btn-sm">
                        <i class="fas fa-check-circle me-2"></i>Approve All Pending
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <div class="app-card-body p-0">
                <?php if (empty($results)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5>No results found</h5>
                    <p class="text-muted"><?php echo $status === 'pending' ? 'No pending results to approve.' : 'No results match your filters.'; ?></p>
                </div>
                <?php else: ?>
                <form method="POST" id="resultsForm">
                    <div class="table-responsive">
                        <table class="table table-hover app-table-hover mb-0">
                            <thead>
                                <tr>
                                    <?php if ($status === 'pending'): ?>
                                    <th width="50">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <?php endif; ?>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Academic Info</th>
                                    <th>Scores</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                <tr>
                                    <?php if ($status === 'pending'): ?>
                                    <td>
                                        <input type="checkbox" name="result_ids[]" value="<?php echo $result['result_id']; ?>" class="result-checkbox">
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['matric_number']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?>
                                        </small>
                                        <?php if ($result['department_name']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($result['department_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['course_code']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($result['course_title']); ?></small><br>
                                        <small><?php echo $result['credit_units']; ?> units</small>
                                    </td>
                                    
                                    <td>
                                        <div class="text-nowrap">
                                            <?php echo htmlspecialchars($result['session_year']); ?><br>
                                            Semester <?php echo $result['semester']; ?><br>
                                            Level <?php echo $result['level'] ?? $result['current_level']; ?>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="text-nowrap">
                                            CA: <?php echo number_format($result['ca_score'], 1); ?><br>
                                            Exam: <?php echo number_format($result['exam_score'], 1); ?><br>
                                            <strong>Total: <?php echo number_format($result['total_score'], 1); ?></strong>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <div class="text-center">
                                            <span class="badge bg-primary fs-6"><?php echo $result['grade']; ?></span><br>
                                            <small>Points: <?php echo number_format($result['grade_points'], 1); ?></small><br>
                                            <small class="text-muted"><?php echo $result['grade_remark']; ?></small>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <?php if ($result['is_published']): ?>
                                        <span class="badge bg-success">Approved</span><br>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y', strtotime($result['published_date'])); ?><br>
                                            By: <?php echo htmlspecialchars($result['approved_by_name'] ?? 'System'); ?>
                                        </small>
                                        <?php else: ?>
                                        <span class="badge bg-warning">Pending</span><br>
                                        <small class="text-muted">
                                            Added: <?php echo date('M d, Y', strtotime($result['created_at'] ?? 'now')); ?>
                                        </small>
                                        <?php endif; ?>
                                        
                                        <?php if ($result['rejection_reason']): ?>
                                        <div class="mt-1">
                                            <small class="text-danger">
                                                <i class="fas fa-times-circle me-1"></i>
                                                <?php echo htmlspecialchars($result['rejection_reason']); ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (!$result['is_published']): ?>
                                            <button type="button" class="btn btn-success" 
                                                    onclick="approveSingleResult(<?php echo $result['result_id']; ?>)"
                                                    title="Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            
                                            <button type="button" class="btn btn-danger" 
                                                    onclick="showRejectModal(<?php echo $result['result_id']; ?>)"
                                                    title="Reject">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-info" 
                                                    onclick="viewResultDetails(<?php echo $result['result_id']; ?>)"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            
            <?php if (count($results) > 0 && $status === 'pending'): ?>
            <div class="app-card-footer p-3 border-top">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAllFooter">
                            <label class="form-check-label" for="selectAllFooter">
                                Select All <?php echo count($results); ?> Results
                            </label>
                        </div>
                    </div>
                    <div>
                        <button type="submit" form="resultsForm" name="approve_results" 
                                class="btn btn-success" id="approveSelectedBtn" disabled>
                            <i class="fas fa-check-circle me-2"></i>
                            Approve Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="result_id" id="rejectResultId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection *</label>
                        <textarea class="form-control" name="rejection_reason" rows="3" 
                                  placeholder="Please provide a reason for rejecting this result..." required></textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Rejected results will need to be reviewed and resubmitted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_result" class="btn btn-danger">Reject Result</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all checkboxes
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.result-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

document.getElementById('selectAllFooter').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.result-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

// Update selected count
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.result-checkbox');
    const selected = Array.from(checkboxes).filter(cb => cb.checked).length;
    document.getElementById('selectedCount').textContent = selected;
    document.getElementById('approveSelectedBtn').disabled = selected === 0;
}

// Initialize
document.querySelectorAll('.result-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});
updateSelectedCount();

// Show reject modal
function showRejectModal(resultId) {
    document.getElementById('rejectResultId').value = resultId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

// Approve single result
function approveSingleResult(resultId) {
    if (confirm('Approve this result?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const input1 = document.createElement('input');
        input1.type = 'hidden';
        input1.name = 'result_ids[]';
        input1.value = resultId;
        
        const input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'approve_results';
        input2.value = '1';
        
        form.appendChild(input1);
        form.appendChild(input2);
        document.body.appendChild(form);
        form.submit();
    }
}

// View result details (placeholder)
function viewResultDetails(resultId) {
    window.location.href = 'result_details.php?id=' + resultId;
}

// Confirm bulk approval
document.getElementById('bulkApproveForm')?.addEventListener('submit', function(e) {
    if (!confirm('Approve all pending results?')) {
        e.preventDefault();
    }
});

document.getElementById('resultsForm')?.addEventListener('submit', function(e) {
    const selectedCount = document.getElementById('selectedCount').textContent;
    if (selectedCount === '0') {
        e.preventDefault();
        alert('Please select at least one result to approve.');
        return false;
    }
    
    if (!confirm(`Approve ${selectedCount} selected result(s)?`)) {
        e.preventDefault();
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>