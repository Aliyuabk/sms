<?php
// advisor_students.php
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
$advisor_stmt = $pdo->prepare("
    SELECT 
        aa.*,
        d.department_name
    FROM academic_advisors aa
    LEFT JOIN departments d ON aa.department_id = d.department_id
    WHERE aa.advisor_id = ?
");
$advisor_stmt->execute([$advisor_id]);
$advisor = $advisor_stmt->fetch();

if (!$advisor) {
    $_SESSION['error_message'] = "Advisor not found";
    header("Location: academic_advisors.php");
    exit();
}

$page_title = "Manage Students - " . htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']);

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query conditions
$conditions = ["sa.advisor_id = ?", "sa.status = 'Active'"];
$params = [$advisor_id];

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if ($level > 0) {
    $conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if (!empty($status_filter)) {
    $conditions[] = "s.status = ?";
    $params[] = $status_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $conditions);

// Get total records
$count_sql = "
    SELECT COUNT(*) 
    FROM student_advisors sa
    JOIN students s ON sa.student_id = s.student_id
    {$where_clause}
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Fetch students
$sql = "
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
         WHERE ha.student_id = s.student_id AND ha.status = 'Active') as has_hostel,
        (SELECT COUNT(*) FROM results r 
         WHERE r.student_id = s.student_id AND r.grade = 'F' AND r.session_year = ?) as failed_courses
    FROM student_advisors sa
    JOIN students s ON sa.student_id = s.student_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    {$where_clause}
    ORDER BY s.current_level DESC, s.last_name ASC
    LIMIT {$offset}, {$records_per_page}
";

$current_session = date('Y') . '/' . (date('Y') + 1);
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$current_session, $current_session], $params));
$students = $stmt->fetchAll();

// Get level options
$level_options = [100, 200, 300, 400, 500];
$status_options = ['Active', 'Inactive', 'Suspended', 'Graduated', 'Withdrawn'];

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selected_students = $_POST['selected_students'] ?? [];
    
    if (empty($selected_students)) {
        $_SESSION['error_message'] = "No students selected";
    } else {
        try {
            $pdo->beginTransaction();
            
            switch ($_POST['bulk_action']) {
                case 'remove':
                    $placeholders = implode(',', array_fill(0, count($selected_students), '?'));
                    
                    // Update assignments
                    $update_sql = "UPDATE student_advisors 
                                   SET status = 'Completed', end_date = CURDATE() 
                                   WHERE student_id IN ($placeholders) AND advisor_id = ? AND status = 'Active'";
                    $update_params = array_merge($selected_students, [$advisor_id]);
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute($update_params);
                    $affected = $update_stmt->rowCount();
                    
                    // Update advisor count
                    $update_advisor = $pdo->prepare("
                        UPDATE academic_advisors 
                        SET current_students = current_students - ? 
                        WHERE advisor_id = ?
                    ");
                    $update_advisor->execute([$affected, $advisor_id]);
                    
                    $_SESSION['success_message'] = "Removed {$affected} student(s) from advisor";
                    break;
                    
                case 'send_email':
                    // Queue emails for selected students
                    foreach ($selected_students as $student_id) {
                        $queue_sql = "INSERT INTO email_queue (student_id, subject, message, status) 
                                     VALUES (?, 'Message from Academic Advisor', 'Placeholder message', 'Pending')";
                        $queue_stmt = $pdo->prepare($queue_sql);
                        $queue_stmt->execute([$student_id]);
                    }
                    $_SESSION['success_message'] = "Emails queued for " . count($selected_students) . " students";
                    break;
                    
                case 'export':
                    // Redirect to export with selected IDs
                    $export_ids = implode(',', $selected_students);
                    header("Location: export_student_data.php?ids={$export_ids}&advisor_id={$advisor_id}");
                    exit();
                    break;
            }
            
            $pdo->commit();
            header("Location: advisor_students.php?id={$advisor_id}");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Bulk action error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error processing bulk action";
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="academic_advisors.php">Academic Advisors</a></li>
                <li class="breadcrumb-item"><a href="view_advisor.php?id=<?php echo $advisor_id; ?>"><?php echo htmlspecialchars($advisor['first_name'] . ' ' . $advisor['last_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Manage Students</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Manage Advisees</h1>
    </div>
    <div class="app-actions">
        <a href="view_advisor.php?id=<?php echo $advisor_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Profile
        </a>
    </div>
</div>

<!-- Filters Card -->
<div class="app-card app-card-filters shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">
            <i class="fas fa-filter me-2"></i>Filters & Search
        </h5>
    </div>
    <div class="app-card-body p-3">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="id" value="<?php echo $advisor_id; ?>">
            
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           name="search" 
                           placeholder="Name, matric, email..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="">All Levels</option>
                    <?php foreach ($level_options as $level_opt): ?>
                    <option value="<?php echo $level_opt; ?>" <?php echo $level == $level_opt ? 'selected' : ''; ?>>
                        Level <?php echo $level_opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <?php foreach ($status_options as $status_opt): ?>
                    <option value="<?php echo $status_opt; ?>" <?php echo $status_filter == $status_opt ? 'selected' : ''; ?>>
                        <?php echo $status_opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-grid gap-2 w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="advisor_students.php?id=<?php echo $advisor_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Total Advisees</div>
                <div class="stats-figure"><?php echo $total_records; ?></div>
                <div class="stats-meta">
                    <i class="fas fa-users text-primary"></i> Assigned students
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Active Students</div>
                <?php
                $active_count = array_filter($students, function($s) { return $s['status'] == 'Active'; });
                ?>
                <div class="stats-figure"><?php echo count($active_count); ?></div>
                <div class="stats-meta text-success">
                    <i class="fas fa-check-circle"></i> Currently active
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">At Risk</div>
                <?php
                $at_risk = array_filter($students, function($s) { 
                    return $s['cgpa'] < 2.0 || $s['failed_courses'] > 0; 
                });
                ?>
                <div class="stats-figure"><?php echo count($at_risk); ?></div>
                <div class="stats-meta text-warning">
                    <i class="fas fa-exclamation-triangle"></i> Need attention
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <div class="stats-type">Capacity Used</div>
                <div class="stats-figure"><?php echo round(($advisor['current_students'] / $advisor['max_students']) * 100, 1); ?>%</div>
                <div class="stats-meta">
                    <i class="fas fa-chart-line"></i> <?php echo $advisor['max_students'] - $advisor['current_students']; ?> slots left
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Students Table -->
<div class="app-card shadow-sm">
    <div class="app-card-header p-3">
        <div class="row align-items-center">
            <div class="col">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-list me-2"></i>Students List
                </h5>
            </div>
            <div class="col-auto">
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="export_advisees.php?id=<?php echo $advisor_id; ?>&format=excel">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </a></li>
                        <li><a class="dropdown-item" href="export_advisees.php?id=<?php echo $advisor_id; ?>&format=pdf">
                            <i class="fas fa-file-pdf me-2"></i>PDF
                        </a></li>
                        <li><a class="dropdown-item" href="export_advisees.php?id=<?php echo $advisor_id; ?>&format=csv">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-card-body p-0">
        <form method="POST" id="bulkForm">
            <div class="table-responsive">
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="40">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                </div>
                            </th>
                            <th>Matric No</th>
                            <th>Student Name</th>
                            <th>Level</th>
                            <th>Program</th>
                            <th>CGPA</th>
                            <th>Assigned Date</th>
                            <th>Status</th>
                            <th>Alerts</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <div class="form-check">
                                        <input class="form-check-input select-checkbox" 
                                               type="checkbox" 
                                               name="selected_students[]" 
                                               value="<?php echo $student['student_id']; ?>">
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold"><?php echo htmlspecialchars($student['matric_number']); ?></span>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                </td>
                                <td>Level <?php echo $student['current_level']; ?></td>
                                <td>
                                    <small><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <?php 
                                    $cgpa_class = 'success';
                                    $cgpa = floatval($student['cgpa']);
                                    if ($cgpa < 1.0) $cgpa_class = 'danger';
                                    elseif ($cgpa < 2.0) $cgpa_class = 'warning';
                                    elseif ($cgpa < 3.0) $cgpa_class = 'info';
                                    ?>
                                    <span class="badge bg-<?php echo $cgpa_class; ?>">
                                        <?php echo number_format($cgpa, 2); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($student['assigned_date'])); ?></td>
                                <td>
                                    <?php 
                                    $status_class = [
                                        'Active' => 'success',
                                        'Inactive' => 'secondary',
                                        'Suspended' => 'danger',
                                        'Graduated' => 'info',
                                        'Withdrawn' => 'dark'
                                    ][$student['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $student['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $alerts = [];
                                    if ($student['fee_balance'] > 0) 
                                        $alerts[] = '<i class="fas fa-coins text-warning" title="Fee balance: ₦' . number_format($student['fee_balance']) . '"></i>';
                                    if ($student['failed_courses'] > 0) 
                                        $alerts[] = '<i class="fas fa-exclamation-circle text-danger" title="Failed ' . $student['failed_courses'] . ' course(s)"></i>';
                                    if ($student['current_courses'] == 0) 
                                        $alerts[] = '<i class="fas fa-book text-info" title="No course registration"></i>';
                                    if ($student['has_hostel']) 
                                        $alerts[] = '<i class="fas fa-bed text-primary" title="Has hostel"></i>';
                                    
                                    if (!empty($alerts)): ?>
                                        <div class="d-flex gap-1">
                                            <?php foreach ($alerts as $alert): ?>
                                                <?php echo $alert; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="view_student.php?id=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="add_advisor_note.php?student_id=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="Add Note">
                                            <i class="fas fa-sticky-note"></i>
                                        </a>
                                        <a href="view_advisor.php?id=<?php echo $advisor_id; ?>&remove_student=<?php echo $student['student_id']; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Remove this student?')"
                                           title="Remove">
                                            <i class="fas fa-user-minus"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <h5>No students found</h5>
                                    <p class="text-muted">No students match your search criteria.</p>
                                    <?php if ($search || $level || $status_filter): ?>
                                    <a href="advisor_students.php?id=<?php echo $advisor_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-redo me-1"></i>Clear Filters
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    
    <!-- Table Footer -->
    <div class="app-card-footer p-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <div class="form-check me-3">
                        <input class="form-check-input" type="checkbox" id="selectAllBottom">
                        <label class="form-check-label" for="selectAllBottom">
                            Select All
                        </label>
                    </div>
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                            Bulk Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="submitBulkAction('remove')">
                                    <i class="fas fa-user-minus me-2"></i>Remove Selected
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="submitBulkAction('send_email')">
                                    <i class="fas fa-envelope me-2"></i>Send Email
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="submitBulkAction('export')">
                                    <i class="fas fa-download me-2"></i>Export Selected
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="float-md-end">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($current_page == 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?id=<?php echo $advisor_id; ?>&page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&level=<?php echo $level; ?>&status=<?php echo urlencode($status_filter); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?id=<?php echo $advisor_id; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&level=<?php echo $level; ?>&status=<?php echo urlencode($status_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo ($current_page == $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?id=<?php echo $advisor_id; ?>&page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&level=<?php echo $level; ?>&status=<?php echo urlencode($status_filter); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <div class="text-muted small text-end mt-2">
                    Showing <?php echo min($offset + 1, $total_records); ?> - 
                    <?php echo min($offset + $records_per_page, $total_records); ?> 
                    of <?php echo $total_records; ?> students
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Select all functionality
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    document.getElementById('selectAllBottom').checked = this.checked;
});

document.getElementById('selectAllBottom')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.select-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    document.getElementById('selectAll').checked = this.checked;
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = Array.from(document.querySelectorAll('.select-checkbox:checked')).map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('Please select at least one student.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'remove':
            confirmMessage = `Remove ${selectedIds.length} selected student(s) from this advisor?`;
            break;
        case 'send_email':
            confirmMessage = `Send email to ${selectedIds.length} selected student(s)?`;
            break;
        case 'export':
            // Export will redirect, no confirmation needed
            break;
    }
    
    if (confirmMessage && !confirm(confirmMessage)) {
        return false;
    }
    
    // Create form and submit
    const form = document.getElementById('bulkForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'bulk_action';
    input.value = action;
    form.appendChild(input);
    form.submit();
}

// Search with debounce
let searchTimeout;
document.getElementById('studentSearch')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        this.form.submit();
    }, 500);
});
</script>

<?php
require_once 'includes/footer.php';
?>