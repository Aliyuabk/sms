<?php
// session_management.php
ob_start();

require_once 'includes/header.php';

// Set page title
$page_title = "Session Management";

// Get all sessions
$sessions = $pdo->query("
    SELECT * FROM academic_sessions 
    ORDER BY 
        CASE 
            WHEN is_current = 1 THEN 0 
            ELSE 1 
        END,
        session_year DESC, 
        semester DESC
")->fetchAll();

// Get current session
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();

// Get session statistics
$stats = [
    'total_sessions' => count($sessions),
    'current' => $current_session ? 1 : 0,
    'active' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Active'")->fetchColumn(),
    'planning' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Planning'")->fetchColumn(),
    'completed' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Completed'")->fetchColumn(),
    'archived' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Archived'")->fetchColumn(),
];

// Get registration statistics
$today = date('Y-m-d');
$reg_stats = [
    'open' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_start <= '$today' AND registration_end >= '$today'")->fetchColumn(),
    'upcoming' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_start > '$today'")->fetchColumn(),
    'closed' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE registration_end < '$today' AND registration_end IS NOT NULL")->fetchColumn(),
];

// Get session activity counts
$activity_counts = [];
foreach ($sessions as $session) {
    $session_id = $session['session_id'];
    
    // Count registrations
    $reg_count = $pdo->prepare("
        SELECT COUNT(*) FROM course_registrations 
        WHERE session_year = ? AND semester = ?
    ");
    $reg_count->execute([$session['session_year'], $session['semester']]);
    $activity_counts[$session_id]['registrations'] = $reg_count->fetchColumn();
    
    // Count results
    $res_count = $pdo->prepare("
        SELECT COUNT(*) FROM results 
        WHERE session_year = ? AND semester = ?
    ");
    $res_count->execute([$session['session_year'], $session['semester']]);
    $activity_counts[$session_id]['results'] = $res_count->fetchColumn();
    
    // Count unique students
    $student_count = $pdo->prepare("
        SELECT COUNT(DISTINCT student_id) FROM course_registrations 
        WHERE session_year = ? AND semester = ?
    ");
    $student_count->execute([$session['session_year'], $session['semester']]);
    $activity_counts[$session_id]['students'] = $student_count->fetchColumn();
}

// Handle status update
if (isset($_POST['update_status'])) {
    try {
        $session_id = (int)$_POST['session_id'];
        $new_status = $_POST['new_status'];
        
        $stmt = $pdo->prepare("UPDATE academic_sessions SET status = ? WHERE session_id = ?");
        $stmt->execute([$new_status, $session_id]);
        
        $_SESSION['success_message'] = "Session status updated to $new_status!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating status: " . $e->getMessage();
    }
    
    header("Location: session_management.php");
    exit();
}

// Handle registration period update
if (isset($_POST['update_registration'])) {
    try {
        $session_id = (int)$_POST['session_id'];
        $reg_start = $_POST['registration_start'];
        $reg_end = $_POST['registration_end'];
        
        $stmt = $pdo->prepare("
            UPDATE academic_sessions SET 
                registration_start = ?,
                registration_end = ?
            WHERE session_id = ?
        ");
        $stmt->execute([$reg_start, $reg_end, $session_id]);
        
        $_SESSION['success_message'] = "Registration period updated!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating registration: " . $e->getMessage();
    }
    
    header("Location: session_management.php");
    exit();
}

// Handle date updates
if (isset($_POST['update_dates'])) {
    try {
        $session_id = (int)$_POST['session_id'];
        
        $stmt = $pdo->prepare("
            UPDATE academic_sessions SET 
                start_date = ?,
                end_date = ?,
                lectures_start = ?,
                lectures_end = ?,
                exams_start = ?,
                exams_end = ?,
                break_start = ?,
                break_end = ?,
                results_deadline = ?
            WHERE session_id = ?
        ");
        
        $stmt->execute([
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['lectures_start'],
            $_POST['lectures_end'],
            $_POST['exams_start'],
            $_POST['exams_end'],
            $_POST['break_start'],
            $_POST['break_end'],
            $_POST['results_deadline'],
            $session_id
        ]);
        
        $_SESSION['success_message'] = "Session dates updated!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating dates: " . $e->getMessage();
    }
    
    header("Location: session_management.php");
    exit();
}

// Handle session copy
if (isset($_POST['copy_session'])) {
    try {
        $source_id = (int)$_POST['source_session'];
        $new_session_year = $_POST['new_session_year'];
        $new_semester = (int)$_POST['new_semester'];
        
        // Get source session
        $source = $pdo->prepare("SELECT * FROM academic_sessions WHERE session_id = ?");
        $source->execute([$source_id]);
        $source_data = $source->fetch();
        
        if (!$source_data) {
            throw new Exception("Source session not found");
        }
        
        // Check if target session exists
        $check = $pdo->prepare("SELECT session_id FROM academic_sessions WHERE session_year = ? AND semester = ?");
        $check->execute([$new_session_year, $new_semester]);
        
        if ($check->rowCount() > 0) {
            throw new Exception("Target session already exists!");
        }
        
        // Calculate new dates (shift by difference in years)
        $year_diff = substr($new_session_year, 0, 4) - substr($source_data['session_year'], 0, 4);
        
        $insert = $pdo->prepare("
            INSERT INTO academic_sessions (
                session_year, semester, session_name, start_date, end_date,
                registration_start, registration_end, add_drop_start, add_drop_end,
                lectures_start, lectures_end, exams_start, exams_end,
                break_start, break_end, results_deadline, is_current, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Planning')
        ");
        
        $insert->execute([
            $new_session_year,
            $new_semester,
            $source_data['session_name'] ? str_replace($source_data['session_year'], $new_session_year, $source_data['session_name']) : null,
            $source_data['start_date'] ? date('Y-m-d', strtotime($source_data['start_date'] . " +$year_diff years")) : null,
            $source_data['end_date'] ? date('Y-m-d', strtotime($source_data['end_date'] . " +$year_diff years")) : null,
            $source_data['registration_start'] ? date('Y-m-d', strtotime($source_data['registration_start'] . " +$year_diff years")) : null,
            $source_data['registration_end'] ? date('Y-m-d', strtotime($source_data['registration_end'] . " +$year_diff years")) : null,
            $source_data['add_drop_start'] ? date('Y-m-d', strtotime($source_data['add_drop_start'] . " +$year_diff years")) : null,
            $source_data['add_drop_end'] ? date('Y-m-d', strtotime($source_data['add_drop_end'] . " +$year_diff years")) : null,
            $source_data['lectures_start'] ? date('Y-m-d', strtotime($source_data['lectures_start'] . " +$year_diff years")) : null,
            $source_data['lectures_end'] ? date('Y-m-d', strtotime($source_data['lectures_end'] . " +$year_diff years")) : null,
            $source_data['exams_start'] ? date('Y-m-d', strtotime($source_data['exams_start'] . " +$year_diff years")) : null,
            $source_data['exams_end'] ? date('Y-m-d', strtotime($source_data['exams_end'] . " +$year_diff years")) : null,
            $source_data['break_start'] ? date('Y-m-d', strtotime($source_data['break_start'] . " +$year_diff years")) : null,
            $source_data['break_end'] ? date('Y-m-d', strtotime($source_data['break_end'] . " +$year_diff years")) : null,
            $source_data['results_deadline'] ? date('Y-m-d', strtotime($source_data['results_deadline'] . " +$year_diff years")) : null
        ]);
        
        $_SESSION['success_message'] = "Session copied successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error copying session: " . $e->getMessage();
    }
    
    header("Location: session_management.php");
    exit();
}

// Handle bulk registration open/close
if (isset($_POST['bulk_registration_action'])) {
    try {
        $action = $_POST['bulk_action'];
        $selected_sessions = $_POST['selected_sessions'] ?? [];
        
        if (empty($selected_sessions)) {
            throw new Exception("No sessions selected");
        }
        
        $placeholders = implode(',', array_fill(0, count($selected_sessions), '?'));
        
        if ($action == 'open') {
            // Open registration - set to current date + 30 days
            $sql = "UPDATE academic_sessions SET 
                    registration_start = CURDATE(),
                    registration_end = DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    WHERE session_id IN ($placeholders)";
        } elseif ($action == 'close') {
            // Close registration - set end to yesterday
            $sql = "UPDATE academic_sessions SET 
                    registration_end = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                    WHERE session_id IN ($placeholders)";
        } elseif ($action == 'extend') {
            // Extend by 14 days
            $sql = "UPDATE academic_sessions SET 
                    registration_end = DATE_ADD(registration_end, INTERVAL 14 DAY)
                    WHERE session_id IN ($placeholders) AND registration_end >= CURDATE()";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($selected_sessions);
        
        $_SESSION['success_message'] = "Bulk action completed for " . count($selected_sessions) . " sessions!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    header("Location: session_management.php");
    exit();
}
?>

<!-- Display Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0">
        <i class="fas fa-cog me-2"></i>Session Management
    </h1>
    <div>
        <a href="academic_sessions.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Sessions
        </a>
    </div>
</div>

<!-- Current Session Banner -->
<?php if ($current_session): ?>
<div class="alert alert-info mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Current Active Session:</strong> 
            <?php echo htmlspecialchars($current_session['session_name']); ?> 
            (<?php echo htmlspecialchars($current_session['session_year']); ?> - Semester <?php echo $current_session['semester']; ?>)
        </div>
        <div class="col-md-4 text-end">
            <span class="badge bg-<?php 
                echo $current_session['status'] == 'Active' ? 'success' : 
                    ($current_session['status'] == 'Planning' ? 'warning' : 'secondary'); 
            ?> me-2">
                <?php echo $current_session['status']; ?>
            </span>
            <button class="btn btn-sm btn-primary" onclick="editSession(<?php echo htmlspecialchars(json_encode($current_session)); ?>)">
                <i class="fas fa-edit"></i> Edit Current
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="card-title">Total Sessions</h6>
                <h3><?php echo $stats['total_sessions']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Active Sessions</h6>
                <h3><?php echo $stats['active']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">Registration Open</h6>
                <h3><?php echo $reg_stats['open']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">Upcoming</h6>
                <h3><?php echo $reg_stats['upcoming']; ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Actions -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Bulk Actions</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="bulkForm" onsubmit="return confirmBulkAction()">
            <div class="row">
                <div class="col-md-8">
                    <select class="form-control" name="bulk_action" required>
                        <option value="">Select Action</option>
                        <option value="open">Open Registration (Set to current date + 30 days)</option>
                        <option value="close">Close Registration</option>
                        <option value="extend">Extend Registration (+14 days)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" name="bulk_registration_action" class="btn btn-primary w-100">
                        <i class="fas fa-play me-2"></i>Apply to Selected
                    </button>
                </div>
            </div>
            <input type="hidden" name="selected_sessions" id="selectedSessions">
        </form>
    </div>
</div>

<!-- Sessions Management Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Session Management</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                        </th>
                        <th>Session</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Registration Period</th>
                        <th>Academic Dates</th>
                        <th>Activities</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): 
                        $session_id = $session['session_id'];
                        $today = date('Y-m-d');
                        $reg_status = '';
                        $reg_class = '';
                        
                        if ($session['registration_start'] && $session['registration_end']) {
                            if ($today < $session['registration_start']) {
                                $reg_status = 'Upcoming';
                                $reg_class = 'warning';
                            } elseif ($today > $session['registration_end']) {
                                $reg_status = 'Closed';
                                $reg_class = 'danger';
                            } else {
                                $reg_status = 'Open';
                                $reg_class = 'success';
                            }
                        } else {
                            $reg_status = 'Not Set';
                            $reg_class = 'secondary';
                        }
                    ?>
                    <tr class="<?php echo $session['is_current'] ? 'table-primary' : ''; ?>">
                        <td>
                            <input type="checkbox" class="session-checkbox" value="<?php echo $session_id; ?>">
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($session['session_year']); ?></strong>
                            <br>
                            <small><?php echo htmlspecialchars($session['session_name']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-info">Semester <?php echo $session['semester']; ?></span>
                        </td>
                        <td>
                            <?php
                            $status_class = [
                                'Active' => 'success',
                                'Planning' => 'warning',
                                'Completed' => 'info',
                                'Archived' => 'secondary'
                            ][$session['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_class; ?>">
                                <?php echo $session['status']; ?>
                            </span>
                            <br>
                            <button class="btn btn-sm btn-link p-0 mt-1" onclick="changeStatus(<?php echo $session_id; ?>, '<?php echo $session['status']; ?>')">
                                <i class="fas fa-edit"></i> Change
                            </button>
                        </td>
                        <td>
                            <?php if ($session['registration_start'] && $session['registration_end']): ?>
                                <small>
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo date('M d', strtotime($session['registration_start'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($session['registration_end'])); ?>
                                </small>
                                <br>
                                <span class="badge bg-<?php echo $reg_class; ?>"><?php echo $reg_status; ?></span>
                                
                                <?php if ($reg_status == 'Open'): ?>
                                <?php 
                                    $start = strtotime($session['registration_start']);
                                    $end = strtotime($session['registration_end']);
                                    $now = time();
                                    $total = $end - $start;
                                    $elapsed = $now - $start;
                                    $percent = ($elapsed / $total) * 100;
                                ?>
                                <div class="progress mt-1" style="height: 3px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo min(100, $percent); ?>%"></div>
                                </div>
                                <small class="text-muted">
                                    <?php echo round(($end - $now) / (60 * 60 * 24)); ?> days left
                                </small>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm btn-link p-0 mt-1 d-block" 
                                        onclick="editRegistration(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                    <i class="fas fa-edit"></i> Edit Dates
                                </button>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                                <button class="btn btn-sm btn-link p-0 mt-1 d-block" 
                                        onclick="editRegistration(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                    <i class="fas fa-plus"></i> Set Dates
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <?php if ($session['lectures_start']): ?>
                                    <i class="fas fa-chalkboard"></i> Lectures: <?php echo date('M d', strtotime($session['lectures_start'])); ?><br>
                                <?php endif; ?>
                                <?php if ($session['exams_start']): ?>
                                    <i class="fas fa-pencil-alt"></i> Exams: <?php echo date('M d', strtotime($session['exams_start'])); ?><br>
                                <?php endif; ?>
                                <?php if ($session['results_deadline']): ?>
                                    <i class="fas fa-file-alt"></i> Results: <?php echo date('M d', strtotime($session['results_deadline'])); ?>
                                <?php endif; ?>
                            </small>
                            <button class="btn btn-sm btn-link p-0 mt-1 d-block" 
                                    onclick="editDates(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                <i class="fas fa-calendar"></i> Manage Dates
                            </button>
                        </td>
                        <td>
                            <div class="mb-2">
                                <span class="badge bg-info" title="Registrations">
                                    <i class="fas fa-users"></i> <?php echo $activity_counts[$session_id]['registrations'] ?? 0; ?>
                                </span>
                                <span class="badge bg-success" title="Results">
                                    <i class="fas fa-file-alt"></i> <?php echo $activity_counts[$session_id]['results'] ?? 0; ?>
                                </span>
                                <span class="badge bg-secondary" title="Students">
                                    <i class="fas fa-user-graduate"></i> <?php echo $activity_counts[$session_id]['students'] ?? 0; ?>
                                </span>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick="editSession(<?php echo htmlspecialchars(json_encode($session)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="copySession(<?php echo $session_id; ?>)">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Change Session Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="statusForm">
                    <input type="hidden" name="session_id" id="status_session_id">
                    <input type="hidden" name="update_status" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Status</label>
                        <select class="form-control" name="new_status" id="new_status" required>
                            <option value="Planning">Planning</option>
                            <option value="Active">Active</option>
                            <option value="Completed">Completed</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Changing status affects session visibility and operations.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Registration Modal -->
<div class="modal fade" id="registrationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Manage Registration Period</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="registrationForm">
                    <input type="hidden" name="session_id" id="reg_session_id">
                    <input type="hidden" name="update_registration" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Registration Start Date</label>
                        <input type="date" class="form-control" name="registration_start" id="reg_start" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Registration End Date</label>
                        <input type="date" class="form-control" name="registration_end" id="reg_end" required>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <button type="button" class="btn btn-outline-success w-100" onclick="setRegistrationPeriod(30)">
                                <i class="fas fa-clock me-1"></i> 30 Days
                            </button>
                        </div>
                        <div class="col-6">
                            <button type="button" class="btn btn-outline-warning w-100" onclick="setRegistrationPeriod(45)">
                                <i class="fas fa-clock me-1"></i> 45 Days
                            </button>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Dates Modal -->
<div class="modal fade" id="datesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-calendar me-2"></i>Manage Academic Dates</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="datesForm">
                    <input type="hidden" name="session_id" id="dates_session_id">
                    <input type="hidden" name="update_dates" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Start</label>
                            <input type="date" class="form-control" name="start_date" id="dates_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session End</label>
                            <input type="date" class="form-control" name="end_date" id="dates_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Lectures Start</label>
                            <input type="date" class="form-control" name="lectures_start" id="dates_lectures_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Lectures End</label>
                            <input type="date" class="form-control" name="lectures_end" id="dates_lectures_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Exams Start</label>
                            <input type="date" class="form-control" name="exams_start" id="dates_exams_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Exams End</label>
                            <input type="date" class="form-control" name="exams_end" id="dates_exams_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Break Start</label>
                            <input type="date" class="form-control" name="break_start" id="dates_break_start">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Break End</label>
                            <input type="date" class="form-control" name="break_end" id="dates_break_end">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Results Deadline</label>
                            <input type="date" class="form-control" name="results_deadline" id="dates_results">
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save All Dates</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Copy Session Modal -->
<div class="modal fade" id="copyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-copy me-2"></i>Copy Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="copyForm">
                    <input type="hidden" name="source_session" id="copy_source">
                    <input type="hidden" name="copy_session" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Session Year</label>
                        <select class="form-control" name="new_session_year" required>
                            <option value="">Select Year</option>
                            <?php 
                            $year = date('Y');
                            for ($y = $year; $y <= $year + 5; $y++):
                                $session = $y . '/' . ($y + 1);
                            ?>
                            <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Semester</label>
                        <select class="form-control" name="new_semester" required>
                            <option value="1">First Semester</option>
                            <option value="2">Second Semester</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        All dates will be shifted by the year difference.
                    </div>
                    
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Copy Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Toggle all checkboxes
function toggleAll(source) {
    document.querySelectorAll('.session-checkbox').forEach(cb => {
        cb.checked = source.checked;
    });
    updateSelectedSessions();
}

// Update selected sessions hidden input
function updateSelectedSessions() {
    const selected = [];
    document.querySelectorAll('.session-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });
    document.getElementById('selectedSessions').value = JSON.stringify(selected);
}

// Add change event to checkboxes
document.querySelectorAll('.session-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedSessions);
});

// Confirm bulk action
function confirmBulkAction() {
    const selected = document.querySelectorAll('.session-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one session.');
        return false;
    }
    
    const action = document.querySelector('select[name="bulk_action"]').value;
    let message = '';
    
    switch(action) {
        case 'open':
            message = `Open registration for ${selected.length} selected session(s)?`;
            break;
        case 'close':
            message = `Close registration for ${selected.length} selected session(s)?`;
            break;
        case 'extend':
            message = `Extend registration by 14 days for ${selected.length} selected session(s)?`;
            break;
        default:
            alert('Please select an action.');
            return false;
    }
    
    return confirm(message);
}

// Change status
function changeStatus(sessionId, currentStatus) {
    document.getElementById('status_session_id').value = sessionId;
    document.getElementById('new_status').value = currentStatus;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

// Edit registration
function editRegistration(session) {
    document.getElementById('reg_session_id').value = session.session_id;
    document.getElementById('reg_start').value = session.registration_start || '';
    document.getElementById('reg_end').value = session.registration_end || '';
    new bootstrap.Modal(document.getElementById('registrationModal')).show();
}

// Edit dates
function editDates(session) {
    document.getElementById('dates_session_id').value = session.session_id;
    document.getElementById('dates_start').value = session.start_date || '';
    document.getElementById('dates_end').value = session.end_date || '';
    document.getElementById('dates_lectures_start').value = session.lectures_start || '';
    document.getElementById('dates_lectures_end').value = session.lectures_end || '';
    document.getElementById('dates_exams_start').value = session.exams_start || '';
    document.getElementById('dates_exams_end').value = session.exams_end || '';
    document.getElementById('dates_break_start').value = session.break_start || '';
    document.getElementById('dates_break_end').value = session.break_end || '';
    document.getElementById('dates_results').value = session.results_deadline || '';
    new bootstrap.Modal(document.getElementById('datesModal')).show();
}

// Edit session (from main edit button)
function editSession(session) {
    // You can implement this to redirect to edit page or use the existing edit functionality
    window.location.href = `academic_sessions.php?edit=${session.session_id}`;
}

// Copy session
function copySession(sessionId) {
    document.getElementById('copy_source').value = sessionId;
    new bootstrap.Modal(document.getElementById('copyModal')).show();
}

// Set registration period presets
function setRegistrationPeriod(days) {
    const today = new Date();
    const endDate = new Date();
    endDate.setDate(today.getDate() + days);
    
    document.getElementById('reg_start').value = today.toISOString().split('T')[0];
    document.getElementById('reg_end').value = endDate.toISOString().split('T')[0];
}

// Form validations
document.getElementById('registrationForm')?.addEventListener('submit', function(e) {
    const start = document.getElementById('reg_start').value;
    const end = document.getElementById('reg_end').value;
    
    if (start && end && start > end) {
        e.preventDefault();
        alert('Registration end date must be after start date.');
    }
});

document.getElementById('datesForm')?.addEventListener('submit', function(e) {
    const start = document.getElementById('dates_start').value;
    const end = document.getElementById('dates_end').value;
    
    if (start && end && start > end) {
        e.preventDefault();
        alert('Session end date must be after start date.');
    }
});

// Initialize
updateSelectedSessions();
</script>

<style>
.modal-header.bg-warning .btn-close,
.modal-header.bg-info .btn-close-white,
.modal-header.bg-success .btn-close-white {
    filter: brightness(0) invert(1);
}
.table-primary {
    background-color: #cfe2ff !important;
}
.progress {
    background-color: #e9ecef;
    border-radius: 2px;
}
.badge {
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
}
</style>

<?php
require_once 'includes/footer.php';
?>