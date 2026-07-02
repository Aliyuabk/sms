<?php
// academic_sessions.php - Separate semester controls with individual dates and activation
ob_start();
require_once 'includes/header.php';

$page_title = "Academic Sessions";

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new session (creates BOTH semesters with separate dates)
    if (isset($_POST['add_session'])) {
        try {
            $session_year = $_POST['session_year'];

            // Check if this session year already exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM academic_sessions WHERE session_year = ?");
            $check->execute([$session_year]);

            if ($check->fetchColumn() > 0) {
                throw new Exception("Session year $session_year already exists!");
            }

            $pdo->beginTransaction();

            // Create FIRST SEMESTER
            $stmt1 = $pdo->prepare("
                INSERT INTO academic_sessions (
                    session_year, semester, session_name, start_date, end_date,
                    registration_start, registration_end, add_drop_start, add_drop_end,
                    lectures_start, lectures_end, exams_start, exams_end,
                    break_start, break_end, results_deadline, is_current, status
                ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Planning')
            ");
            $stmt1->execute([
                $session_year,
                $session_year . ' First Semester',
                $_POST['sem1_start_date'] ?: null,
                $_POST['sem1_end_date'] ?: null,
                $_POST['sem1_reg_start'] ?: null,
                $_POST['sem1_reg_end'] ?: null,
                $_POST['sem1_add_drop_start'] ?: null,
                $_POST['sem1_add_drop_end'] ?: null,
                $_POST['sem1_lectures_start'] ?: null,
                $_POST['sem1_lectures_end'] ?: null,
                $_POST['sem1_exams_start'] ?: null,
                $_POST['sem1_exams_end'] ?: null,
                $_POST['sem1_break_start'] ?: null,
                $_POST['sem1_break_end'] ?: null,
                $_POST['sem1_results_deadline'] ?: null
            ]);

            // Create SECOND SEMESTER
            $stmt2 = $pdo->prepare("
                INSERT INTO academic_sessions (
                    session_year, semester, session_name, start_date, end_date,
                    registration_start, registration_end, add_drop_start, add_drop_end,
                    lectures_start, lectures_end, exams_start, exams_end,
                    break_start, break_end, results_deadline, is_current, status
                ) VALUES (?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Planning')
            ");
            $stmt2->execute([
                $session_year,
                $session_year . ' Second Semester',
                $_POST['sem2_start_date'] ?: null,
                $_POST['sem2_end_date'] ?: null,
                $_POST['sem2_reg_start'] ?: null,
                $_POST['sem2_reg_end'] ?: null,
                $_POST['sem2_add_drop_start'] ?: null,
                $_POST['sem2_add_drop_end'] ?: null,
                $_POST['sem2_lectures_start'] ?: null,
                $_POST['sem2_lectures_end'] ?: null,
                $_POST['sem2_exams_start'] ?: null,
                $_POST['sem2_exams_end'] ?: null,
                $_POST['sem2_break_start'] ?: null,
                $_POST['sem2_break_end'] ?: null,
                $_POST['sem2_results_deadline'] ?: null
            ]);

            $pdo->commit();

            $_SESSION['success_message'] = "Session $session_year created with both semesters!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: academic_sessions.php");
        exit();
    }

    // Update a specific semester
    if (isset($_POST['update_semester'])) {
        try {
            $session_id = (int)$_POST['session_id'];

            $sql = "UPDATE academic_sessions SET
                session_year = ?, semester = ?, session_name = ?,
                start_date = ?, end_date = ?,
                registration_start = ?, registration_end = ?,
                add_drop_start = ?, add_drop_end = ?,
                lectures_start = ?, lectures_end = ?,
                exams_start = ?, exams_end = ?,
                break_start = ?, break_end = ?,
                results_deadline = ?, status = ?
                WHERE session_id = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['session_year'],
                $_POST['semester'],
                $_POST['session_name'],
                $_POST['start_date'] ?: null,
                $_POST['end_date'] ?: null,
                $_POST['registration_start'] ?: null,
                $_POST['registration_end'] ?: null,
                $_POST['add_drop_start'] ?: null,
                $_POST['add_drop_end'] ?: null,
                $_POST['lectures_start'] ?: null,
                $_POST['lectures_end'] ?: null,
                $_POST['exams_start'] ?: null,
                $_POST['exams_end'] ?: null,
                $_POST['break_start'] ?: null,
                $_POST['break_end'] ?: null,
                $_POST['results_deadline'] ?: null,
                $_POST['status'],
                $session_id
            ]);

            $_SESSION['success_message'] = "Semester updated successfully!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: academic_sessions.php");
        exit();
    }

    // Set a specific semester as CURRENT
    if (isset($_POST['set_current_semester'])) {
        try {
            $session_id = (int)$_POST['session_id'];

            // Get session info
            $info = $pdo->prepare("SELECT session_year, semester FROM academic_sessions WHERE session_id = ?");
            $info->execute([$session_id]);
            $session_data = $info->fetch();

            if (!$session_data) {
                throw new Exception("Session not found");
            }

            $pdo->beginTransaction();

            // Set ALL to not current first
            $pdo->query("UPDATE academic_sessions SET is_current = 0");

            // Set selected semester as current and Active
            $stmt = $pdo->prepare("UPDATE academic_sessions SET is_current = 1, status = 'Active' WHERE session_id = ?");
            $stmt->execute([$session_id]);

            $pdo->commit();

            $sem_name = $session_data['semester'] == 1 ? 'First' : 'Second';
            $_SESSION['success_message'] = $session_data['session_year'] . ' ' . $sem_name . " Semester set as current!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: academic_sessions.php");
        exit();
    }

    // Delete entire session year (both semesters)
    if (isset($_POST['delete_session'])) {
        try {
            $session_year = $_POST['session_year'];

            // Check related records
            $checks = [
                'course_registrations' => "SELECT COUNT(*) FROM course_registrations WHERE session_year = ?",
                'results' => "SELECT COUNT(*) FROM results WHERE session_year = ?",
                'student_fees' => "SELECT COUNT(*) FROM student_fees WHERE session_year = ?"
            ];

            foreach ($checks as $table => $sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$session_year]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Cannot delete session with existing $table records!");
                }
            }

            $stmt = $pdo->prepare("DELETE FROM academic_sessions WHERE session_year = ?");
            $stmt->execute([$session_year]);

            $_SESSION['success_message'] = "Session $session_year deleted successfully!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: academic_sessions.php");
        exit();
    }
}

// ============================================
// GET DATA FOR DISPLAY
// ============================================

// Get all sessions grouped by year
$sessions_raw = $pdo->query("
    SELECT * FROM academic_sessions 
    ORDER BY session_year DESC, semester ASC
")->fetchAll();

// Group sessions by year
$sessions = [];
foreach ($sessions_raw as $session) {
    $year = $session['session_year'];
    if (!isset($sessions[$year])) {
        $sessions[$year] = [];
    }
    $sessions[$year][$session['semester']] = $session;
}

// Get current semester
$current_semester = $pdo->query("SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetch();

// Statistics
$stats = [
    'total_years' => count($sessions),
    'total_semesters' => count($sessions_raw),
    'active' => $pdo->query("SELECT COUNT(*) FROM academic_sessions WHERE status = 'Active'")->fetchColumn(),
    'current' => $current_semester ? 1 : 0,
];

$year = date('Y');
?>

<!-- Messages -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0"><i class="fas fa-calendar-alt me-2"></i>Academic Sessions</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSessionModal">
        <i class="fas fa-plus me-2"></i>Add New Session
    </button>
</div>

<!-- Statistics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <h6>Session Years</h6>
                <h3><?php echo $stats['total_years']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <h6>Total Semesters</h6>
                <h3><?php echo $stats['total_semesters']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body text-center">
                <h6>Active Semesters</h6>
                <h3><?php echo $stats['active']; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <h6>Current Semester</h6>
                <h3><?php echo $stats['current']; ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Current Semester Banner -->
<?php if ($current_semester): ?>
<div class="alert alert-success mb-4">
    <i class="fas fa-check-circle me-2"></i>
    <strong>Current Active Semester:</strong> 
    <?php echo htmlspecialchars($current_semester['session_name']); ?> 
    <span class="badge bg-warning ms-2"><?php echo $current_semester['session_year']; ?></span>
    <span class="badge bg-primary ms-1">Semester <?php echo $current_semester['semester']; ?></span>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>No current semester set.</strong> Please activate a semester from the table below.
</div>
<?php endif; ?>

<!-- Sessions Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Sessions</h5>
        <span class="text-muted"><?php echo $stats['total_years']; ?> years, <?php echo $stats['total_semesters']; ?> semesters</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Session Year</th>
                        <th>Semester</th>
                        <th>Name</th>
                        <th>Duration</th>
                        <th>Registration</th>
                        <th>Status</th>
                        <th>Current</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $year => $semesters): 
                        foreach ([1, 2] as $sem_num):
                            $sem = $semesters[$sem_num] ?? null;
                            if (!$sem) continue;
                            $is_current = $sem['is_current'] == 1;
                    ?>
                    <tr class="<?php echo $is_current ? 'table-success' : ''; ?>">
                        <?php if ($sem_num == 1): ?>
                        <td rowspan="2" class="align-middle"><strong><?php echo htmlspecialchars($year); ?></strong></td>
                        <?php endif; ?>

                        <td>
                            <span class="badge bg-<?php echo $sem_num == 1 ? 'primary' : 'secondary'; ?>">
                                <?php echo $sem_num; ?><?php echo $sem_num == 1 ? 'st' : 'nd'; ?>
                            </span>
                        </td>

                        <td><?php echo htmlspecialchars($sem['session_name']); ?></td>

                        <td>
                            <?php if ($sem['start_date'] && $sem['end_date']): ?>
                                <small><?php echo date('M d', strtotime($sem['start_date'])); ?> - <?php echo date('M d, Y', strtotime($sem['end_date'])); ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($sem['registration_start'] && $sem['registration_end']): 
                                $today = date('Y-m-d');
                                $reg_class = ($today >= $sem['registration_start'] && $today <= $sem['registration_end']) ? 'success' : 
                                            (($today < $sem['registration_start']) ? 'warning' : 'danger');
                                $reg_text = ($today >= $sem['registration_start'] && $today <= $sem['registration_end']) ? 'Open' : 
                                           (($today < $sem['registration_start']) ? 'Upcoming' : 'Closed');
                            ?>
                                <span class="badge bg-<?php echo $reg_class; ?>"><?php echo $reg_text; ?></span>
                                <small class="d-block text-muted"><?php echo date('M d', strtotime($sem['registration_start'])); ?> - <?php echo date('M d', strtotime($sem['registration_end'])); ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php 
                            $status_class = ['Active' => 'success', 'Planning' => 'warning', 'Completed' => 'info', 'Archived' => 'secondary'][$sem['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_class; ?>"><?php echo $sem['status']; ?></span>
                        </td>

                        <td class="text-center">
                            <?php if ($is_current): ?>
                                <span class="badge bg-success"><i class="fas fa-check"></i> Active</span>
                            <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="session_id" value="<?php echo $sem['session_id']; ?>">
                                    <button type="submit" name="set_current_semester" class="btn btn-sm btn-outline-success" onclick="return confirm('Set <?php echo $sem['session_name']; ?> as current semester?')">
                                        <i class="fas fa-play"></i> Activate
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-info" onclick='editSemester(<?php echo json_encode($sem); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($sem_num == 1): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteSession('<?php echo $year; ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Session Modal -->
<div class="modal fade" id="addSessionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Academic Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addSessionForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Session Year *</label>
                            <select class="form-select" name="session_year" id="addSessionYear" required>
                                <option value="">Select Session</option>
                                <?php 
                                for ($y = $year - 1; $y <= $year + 5; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <!-- FIRST SEMESTER -->
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="fas fa-1 me-2"></i>First Semester</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="sem1_start_date">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="sem1_end_date">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Registration Start</label>
                                    <input type="date" class="form-control" name="sem1_reg_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Registration End</label>
                                    <input type="date" class="form-control" name="sem1_reg_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Lectures Start</label>
                                    <input type="date" class="form-control" name="sem1_lectures_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Lectures End</label>
                                    <input type="date" class="form-control" name="sem1_lectures_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Exams Start</label>
                                    <input type="date" class="form-control" name="sem1_exams_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Exams End</label>
                                    <input type="date" class="form-control" name="sem1_exams_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Results Deadline</label>
                                    <input type="date" class="form-control" name="sem1_results_deadline">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECOND SEMESTER -->
                    <div class="card border-secondary mb-3">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="mb-0"><i class="fas fa-2 me-2"></i>Second Semester</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="sem2_start_date">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="sem2_end_date">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Registration Start</label>
                                    <input type="date" class="form-control" name="sem2_reg_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Registration End</label>
                                    <input type="date" class="form-control" name="sem2_reg_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Lectures Start</label>
                                    <input type="date" class="form-control" name="sem2_lectures_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Lectures End</label>
                                    <input type="date" class="form-control" name="sem2_lectures_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Exams Start</label>
                                    <input type="date" class="form-control" name="sem2_exams_start">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Exams End</label>
                                    <input type="date" class="form-control" name="sem2_exams_end">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label">Results Deadline</label>
                                    <input type="date" class="form-control" name="sem2_results_deadline">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_session" class="btn btn-primary ms-2">
                            <i class="fas fa-save me-2"></i>Create Both Semesters
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Semester Modal -->
<div class="modal fade" id="editSemesterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Semester</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editSemesterForm">
                    <input type="hidden" name="session_id" id="edit_sem_session_id">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Session Year</label>
                            <input type="text" class="form-control" name="session_year" id="edit_sem_session_year" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Semester</label>
                            <input type="text" class="form-control" name="semester" id="edit_sem_semester" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Session Name</label>
                        <input type="text" class="form-control" name="session_name" id="edit_sem_session_name">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status" id="edit_sem_status">
                                <option value="Planning">Planning</option>
                                <option value="Active">Active</option>
                                <option value="Completed">Completed</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="edit_sem_start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="edit_sem_end_date">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Start</label>
                            <input type="date" class="form-control" name="registration_start" id="edit_sem_reg_start">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration End</label>
                            <input type="date" class="form-control" name="registration_end" id="edit_sem_reg_end">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures Start</label>
                            <input type="date" class="form-control" name="lectures_start" id="edit_sem_lectures_start">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lectures End</label>
                            <input type="date" class="form-control" name="lectures_end" id="edit_sem_lectures_end">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams Start</label>
                            <input type="date" class="form-control" name="exams_start" id="edit_sem_exams_start">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exams End</label>
                            <input type="date" class="form-control" name="exams_end" id="edit_sem_exams_end">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Results Deadline</label>
                            <input type="date" class="form-control" name="results_deadline" id="edit_sem_results_deadline">
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_semester" class="btn btn-info ms-2">
                            <i class="fas fa-save me-2"></i>Update Semester
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Edit specific semester
function editSemester(semester) {
    document.getElementById('edit_sem_session_id').value = semester.session_id;
    document.getElementById('edit_sem_session_year').value = semester.session_year;
    document.getElementById('edit_sem_semester').value = semester.semester;
    document.getElementById('edit_sem_session_name').value = semester.session_name || '';
    document.getElementById('edit_sem_status').value = semester.status || 'Planning';
    document.getElementById('edit_sem_start_date').value = semester.start_date || '';
    document.getElementById('edit_sem_end_date').value = semester.end_date || '';
    document.getElementById('edit_sem_reg_start').value = semester.registration_start || '';
    document.getElementById('edit_sem_reg_end').value = semester.registration_end || '';
    document.getElementById('edit_sem_lectures_start').value = semester.lectures_start || '';
    document.getElementById('edit_sem_lectures_end').value = semester.lectures_end || '';
    document.getElementById('edit_sem_exams_start').value = semester.exams_start || '';
    document.getElementById('edit_sem_exams_end').value = semester.exams_end || '';
    document.getElementById('edit_sem_results_deadline').value = semester.results_deadline || '';

    new bootstrap.Modal(document.getElementById('editSemesterModal')).show();
}

// Delete entire session year
function deleteSession(sessionYear) {
    if (confirm('Delete session ' + sessionYear + '? This will remove BOTH semesters and cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="session_year" value="${sessionYear}">
            <input type="hidden" name="delete_session" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Form validation
document.getElementById('addSessionForm')?.addEventListener('submit', function(e) {
    const year = this.querySelector('select[name="session_year"]').value;
    if (!year) {
        e.preventDefault();
        alert('Please select a session year.');
        return false;
    }
    return confirm('Create session ' + year + ' with both semesters?');
});

// Date validation
document.querySelectorAll('input[type="date"]').forEach(input => {
    input.addEventListener('change', function() {
        const form = this.closest('form');
        if (!form) return;
        const start = form.querySelector('input[name$="start_date"]')?.value;
        const end = form.querySelector('input[name$="end_date"]')?.value;
        if (start && end && start > end) {
            alert('End date must be after start date.');
            this.value = '';
        }
    });
});
</script>

<style>
.modal-header.bg-primary .btn-close-white,
.modal-header.bg-info .btn-close-white {
    filter: brightness(0) invert(1);
}
.table-success {
    background-color: #d1e7dd !important;
}
</style>

<?php require_once 'includes/footer.php'; ?>