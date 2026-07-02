<?php
// promotion.php - Simple Student Level Promotion
// Just changes student level to the next level

ob_start();
require_once 'includes/header.php';

$page_title = "Student Promotion";

// ============================================
// HANDLE POST REQUESTS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Promote selected students
    if (isset($_POST['promote_students'])) {
        try {
            $student_ids = $_POST['student_ids'] ?? [];
            $target_level = (int)$_POST['target_level'];
            $new_session = $_POST['new_session'];

            if (empty($student_ids)) {
                throw new Exception("No students selected for promotion");
            }

            if ($target_level < 100 || $target_level > 600) {
                throw new Exception("Invalid target level");
            }

            $pdo->beginTransaction();

            $success_count = 0;
            $failed_count = 0;
            $errors = [];

            foreach ($student_ids as $student_id) {
                try {
                    // Get current student data
                    $stmt = $pdo->prepare("SELECT matric_number, current_level, current_session FROM students WHERE student_id = ? AND status = 'Active'");
                    $stmt->execute([$student_id]);
                    $student = $stmt->fetch();

                    if (!$student) {
                        throw new Exception("Student not found or not active");
                    }

                    $old_level = $student['current_level'];

                    // Update student level
                    $update = $pdo->prepare("
                        UPDATE students SET 
                            current_level = ?,
                            current_session = ?
                        WHERE student_id = ?
                    ");
                    $update->execute([$target_level, $new_session, $student_id]);

                    // Log the action
                    $log = $pdo->prepare("
                        INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                        VALUES (?, 'Student Promotion', ?, ?, NOW())
                    ");
                    $log->execute([
                        $_SESSION['admin_id'],
                        "Promoted {$student['matric_number']} from Level $old_level to Level $target_level",
                        $_SERVER['REMOTE_ADDR'] ?? '::1'
                    ]);

                    $success_count++;

                } catch (Exception $e) {
                    $failed_count++;
                    $errors[] = "Student ID $student_id: " . $e->getMessage();
                }
            }

            $pdo->commit();

            $_SESSION['success_message'] = "Successfully promoted $success_count student(s) to Level $target_level.";
            if ($failed_count > 0) {
                $_SESSION['warning_message'] = "$failed_count student(s) failed.";
                $_SESSION['promotion_errors'] = $errors;
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: promotion.php");
        exit();
    }

    // Bulk promote by level
    if (isset($_POST['bulk_promote'])) {
        try {
            $from_level = (int)$_POST['from_level'];
            $to_level = (int)$_POST['to_level'];
            $program_id = (int)$_POST['program_id'];
            $department_id = (int)$_POST['department_id'];
            $session_year = $_POST['session_year'];

            if ($to_level <= $from_level) {
                throw new Exception("Target level must be higher than current level");
            }

            // Build query
            $conditions = ["current_level = ?", "status = 'Active'"];
            $params = [$from_level];

            if ($program_id > 0) {
                $conditions[] = "program_id = ?";
                $params[] = $program_id;
            }

            if ($department_id > 0) {
                $conditions[] = "department_id = ?";
                $params[] = $department_id;
            }

            $where = implode(" AND ", $conditions);

            // Get students
            $stmt = $pdo->prepare("SELECT student_id, matric_number, first_name, last_name, current_level, current_session FROM students WHERE $where ORDER BY matric_number");
            $stmt->execute($params);
            $students = $stmt->fetchAll();

            if (empty($students)) {
                throw new Exception("No students found at Level $from_level");
            }

            // Store for confirmation
            $_SESSION['pending_promotion'] = [
                'students' => $students,
                'from_level' => $from_level,
                'to_level' => $to_level,
                'new_session' => $session_year,
                'total' => count($students)
            ];

            $_SESSION['info_message'] = count($students) . " students ready for promotion. Please confirm.";

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: promotion.php");
        exit();
    }

    // Confirm bulk promotion
    if (isset($_POST['confirm_promotion'])) {
        try {
            $pending = $_SESSION['pending_promotion'] ?? null;

            if (!$pending) {
                throw new Exception("No pending promotion");
            }

            $pdo->beginTransaction();

            $success_count = 0;

            foreach ($pending['students'] as $student) {
                $update = $pdo->prepare("UPDATE students SET current_level = ?, current_session = ? WHERE student_id = ?");
                $update->execute([$pending['to_level'], $pending['new_session'], $student['student_id']]);

                $log = $pdo->prepare("
                    INSERT INTO admin_logs (admin_id, action, description, ip_address, created_at) 
                    VALUES (?, 'Bulk Promotion', ?, ?, NOW())
                ");
                $log->execute([
                    $_SESSION['admin_id'],
                    "Promoted {$student['matric_number']} from Level {$pending['from_level']} to Level {$pending['to_level']}",
                    $_SERVER['REMOTE_ADDR'] ?? '::1'
                ]);

                $success_count++;
            }

            $pdo->commit();

            unset($_SESSION['pending_promotion']);

            $_SESSION['success_message'] = "Successfully promoted $success_count students to Level {$pending['to_level']}.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }

        header("Location: promotion.php");
        exit();
    }

    // Cancel promotion
    if (isset($_POST['cancel_promotion'])) {
        unset($_SESSION['pending_promotion']);
        $_SESSION['info_message'] = "Promotion cancelled.";
        header("Location: promotion.php");
        exit();
    }
}

// ============================================
// GET DATA FOR DISPLAY
// ============================================

$filter_program = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$filter_department = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$filter_level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$conditions = ["s.status = 'Active'"];
$params = [];

if ($filter_program > 0) {
    $conditions[] = "s.program_id = ?";
    $params[] = $filter_program;
}

if ($filter_department > 0) {
    $conditions[] = "s.department_id = ?";
    $params[] = $filter_department;
}

if ($filter_level > 0) {
    $conditions[] = "s.current_level = ?";
    $params[] = $filter_level;
}

if (!empty($search)) {
    $conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

$where = implode(" AND ", $conditions);

$students = $pdo->prepare("
    SELECT s.*, d.department_name, p.program_name 
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE $where
    ORDER BY s.current_level, s.matric_number
    LIMIT 500
");
$students->execute($params);
$students_list = $students->fetchAll();

$programs = $pdo->query("SELECT program_id, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];

$stats = [];
foreach ($levels as $level) {
    $count = $pdo->prepare("SELECT COUNT(*) FROM students WHERE current_level = ? AND status = 'Active'");
    $count->execute([$level]);
    $stats[$level] = $count->fetchColumn();
}

$current_session = $pdo->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn();
if (!$current_session) {
    $current_session = date('Y') . '/' . (date('Y') + 1);
}

$pending_promotion = $_SESSION['pending_promotion'] ?? null;
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

<?php if (isset($_SESSION['warning_message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $_SESSION['warning_message']; unset($_SESSION['warning_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['info_message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <i class="fas fa-info-circle me-2"></i><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['promotion_errors'])): ?>
    <div class="alert alert-danger">
        <h6>Errors:</h6>
        <ul class="mb-0 small">
            <?php foreach ($_SESSION['promotion_errors'] as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['promotion_errors']); ?>
<?php endif; ?>

<!-- Pending Promotion -->
<?php if ($pending_promotion): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Promotion</h5>
    </div>
    <div class="card-body">
        <p><strong><?php echo $pending_promotion['total']; ?> students</strong> from Level <?php echo $pending_promotion['from_level']; ?> to Level <?php echo $pending_promotion['to_level']; ?></p>
        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;" class="mb-3">
            <?php foreach ($pending_promotion['students'] as $student): ?>
            <span class="badge bg-info m-1"><?php echo htmlspecialchars($student['matric_number']); ?></span>
            <?php endforeach; ?>
        </div>
        <form method="POST">
            <button type="submit" name="confirm_promotion" class="btn btn-success me-2"><i class="fas fa-check me-2"></i>Confirm</button>
            <button type="submit" name="cancel_promotion" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Cancel</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="app-page-title mb-0"><i class="fas fa-arrow-up me-2"></i>Student Promotion</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkPromoteModal">
        <i class="fas fa-users me-2"></i>Bulk Promotion
    </button>
</div>

<!-- Statistics -->
<div class="row g-3 mb-4">
    <?php foreach ($levels as $level): ?>
    <div class="col-md-2">
        <div class="card <?php 
            echo $level == 100 ? 'bg-info' : ($level == 200 ? 'bg-primary' : ($level == 300 ? 'bg-success' : ($level == 400 ? 'bg-warning' : ($level == 500 ? 'bg-secondary' : 'bg-dark'))));
        ?> text-white">
            <div class="card-body text-center">
                <h6 class="card-title">Level <?php echo $level; ?></h6>
                <h3><?php echo number_format($stats[$level]); ?></h3>
                <small>Students</small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Quick Paths -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-road me-2"></i>Quick Promote</h5></div>
    <div class="card-body">
        <div class="row">
            <?php
            $paths = [
                ['from' => 100, 'to' => 200, 'color' => 'info'],
                ['from' => 200, 'to' => 300, 'color' => 'primary'],
                ['from' => 300, 'to' => 400, 'color' => 'success'],
                ['from' => 400, 'to' => 500, 'color' => 'warning'],
                ['from' => 500, 'to' => 600, 'color' => 'secondary'],
            ];
            foreach ($paths as $path):
                $eligible = $stats[$path['from']];
            ?>
            <div class="col-md-2 mb-2">
                <div class="card border-<?php echo $path['color']; ?>">
                    <div class="card-body text-center p-3">
                        <h5 class="text-<?php echo $path['color']; ?>"><?php echo $path['from']; ?> <i class="fas fa-arrow-right mx-2"></i> <?php echo $path['to']; ?></h5>
                        <p class="mb-2"><?php echo $eligible; ?> students</p>
                        <button class="btn btn-sm btn-<?php echo $path['color']; ?> w-100" onclick="quickPromote(<?php echo $path['from']; ?>, <?php echo $path['to']; ?>)" <?php echo $eligible == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-arrow-up me-1"></i>Promote
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Bulk Promotion Modal -->
<div class="modal fade" id="bulkPromoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Bulk Promotion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="bulkPromoteForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">From Level</label>
                        <select class="form-select" name="from_level" id="fromLevel" required>
                            <option value="">Select</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">To Level</label>
                        <select class="form-select" name="to_level" id="toLevel" required>
                            <option value="">Select</option>
                            <?php foreach ($levels as $level): ?>
                            <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Program</label>
                        <select class="form-select" name="program_id">
                            <option value="0">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['program_id']; ?>"><?php echo htmlspecialchars($prog['program_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Department</label>
                        <select class="form-select" name="department_id">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Session</label>
                        <select class="form-select" name="session_year" required>
                            <option value="">Select Session</option>
                            <?php 
                            $year = date('Y');
                            for ($y = $year - 1; $y <= $year + 2; $y++):
                                $session = $y . '/' . ($y + 1);
                            ?>
                            <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>><?php echo $session; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="bulk_promote" class="btn btn-primary">Preview Students</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Manual Promotion Modal -->
<div class="modal fade" id="manualPromoteModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-hand-pointer me-2"></i>Manual Promotion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="manualPromoteForm">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Target Level</label>
                            <select class="form-select" name="target_level" id="manualTargetLevel" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $level): ?>
                                <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">New Session</label>
                            <select class="form-select" name="new_session" required>
                                <option value="">Select Session</option>
                                <?php 
                                for ($y = $year - 1; $y <= $year + 2; $y++):
                                    $session = $y . '/' . ($y + 1);
                                ?>
                                <option value="<?php echo $session; ?>" <?php echo $session == $current_session ? 'selected' : ''; ?>><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllStudents()">Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">Deselect All</button>
                            <span class="ms-3 text-muted" id="selectedCount">0 selected</span>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                            <?php foreach ($students_list as $student): ?>
                            <div class="form-check">
                                <input class="form-check-input student-checkbox" type="checkbox" name="student_ids[]" value="<?php echo $student['student_id']; ?>" id="student_<?php echo $student['student_id']; ?>">
                                <label class="form-check-label" for="student_<?php echo $student['student_id']; ?>">
                                    <strong><?php echo htmlspecialchars($student['matric_number']); ?></strong> - 
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                    <span class="badge bg-secondary">Level <?php echo $student['current_level']; ?></span>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['program_name']); ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="promote_students" class="btn btn-primary" onclick="return confirmPromotion()">Promote Selected</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Filter Form -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Students</h5></div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo $prog['program_id']; ?>" <?php echo $filter_program == $prog['program_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog['program_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>" <?php echo $filter_department == $dept['department_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="0">All Levels</option>
                    <?php foreach ($levels as $lvl): ?>
                    <option value="<?php echo $lvl; ?>" <?php echo $filter_level == $lvl ? 'selected' : ''; ?>>Level <?php echo $lvl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Matric or name">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="promotion.php" class="btn btn-secondary"><i class="fas fa-redo me-1"></i>Reset</a>
                <button type="button" class="btn btn-info ms-2" onclick="openManualPromote()"><i class="fas fa-hand-pointer me-1"></i>Manual</button>
            </div>
        </form>
    </div>
</div>

<!-- Students Table -->
<div class="card">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-users me-2"></i>Students (<?php echo count($students_list); ?> found)</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Matric No</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Level</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students_list as $student): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($student['matric_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                        <td><small><?php echo htmlspecialchars($student['program_name']); ?><br><span class="text-muted"><?php echo htmlspecialchars($student['department_name']); ?></span></small></td>
                        <td><span class="badge bg-<?php echo $student['current_level'] == 100 ? 'info' : ($student['current_level'] == 200 ? 'primary' : ($student['current_level'] == 300 ? 'success' : ($student['current_level'] == 400 ? 'warning' : ($student['current_level'] == 500 ? 'secondary' : 'dark')))); ?>">Level <?php echo $student['current_level']; ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="promoteSingle(<?php echo $student['student_id']; ?>, <?php echo $student['current_level']; ?>)"><i class="fas fa-arrow-up"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Quick promote
document.getElementById('fromLevel')?.addEventListener('change', function() {
    const from = parseInt(this.value);
    const toSelect = document.getElementById('toLevel');
    Array.from(toSelect.options).forEach(opt => {
        if (opt.value) {
            const toVal = parseInt(opt.value);
            opt.disabled = toVal <= from;
            if (toVal <= from && opt.selected) opt.selected = false;
        }
    });
});

function quickPromote(fromLevel, toLevel) {
    document.getElementById('fromLevel').value = fromLevel;
    document.getElementById('toLevel').value = toLevel;
    document.getElementById('fromLevel').dispatchEvent(new Event('change'));
    new bootstrap.Modal(document.getElementById('bulkPromoteModal')).show();
}

function openManualPromote() {
    new bootstrap.Modal(document.getElementById('manualPromoteModal')).show();
}

function selectAllStudents() {
    document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAllStudents() {
    document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.student-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count + ' selected';
}

document.querySelectorAll('.student-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

function promoteSingle(studentId, currentLevel) {
    document.getElementById('manualTargetLevel').value = currentLevel + 100;
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.checked = parseInt(cb.value) === studentId;
    });
    updateSelectedCount();
    openManualPromote();
}

function confirmPromotion() {
    const selected = document.querySelectorAll('.student-checkbox:checked').length;
    if (selected === 0) {
        alert('Please select at least one student.');
        return false;
    }
    const toLevel = document.getElementById('manualTargetLevel').value;
    return confirm('Promote ' + selected + ' student(s) to Level ' + toLevel + '?');
}

document.getElementById('bulkPromoteForm')?.addEventListener('submit', function(e) {
    const from = parseInt(this.querySelector('select[name="from_level"]').value);
    const to = parseInt(this.querySelector('select[name="to_level"]').value);
    if (to <= from) {
        e.preventDefault();
        alert('Target level must be higher than current level.');
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>