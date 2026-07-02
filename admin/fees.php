<?php
// fees.php - Pro Version with Session-Based Fees & Hostel Pricing
ob_start();

require_once 'includes/header.php';

$page_title = "Fee Management";

// ============================================
// AUTO-MIGRATION: Add missing columns
// ============================================
try {
    $check = $pdo->query("SHOW COLUMNS FROM fee_structure LIKE 'semester'");
    if ($check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE fee_structure ADD COLUMN semester INT DEFAULT 1 AFTER program_id");
    }
} catch (Exception $e) {}

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

// ============================================
// FORM HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new fee structure
    if (isset($_POST['add_fee'])) {
        try {
            $session_year = $_POST['session_year'];
            $level = (int)$_POST['level'];
            $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
            $fee_type = $_POST['fee_type'];
            $description = $_POST['description'] ?? null;
            $amount = floatval($_POST['amount']);
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $applicable_to = $_POST['applicable_to'];

            $check_sql = "SELECT fee_structure_id FROM fee_structure 
                         WHERE session_year = ? AND level = ? AND fee_type = ? AND applicable_to = ?";
            $check_params = [$session_year, $level, $fee_type, $applicable_to];

            if ($program_id !== null) {
                $check_sql .= " AND program_id = ?";
                $check_params[] = $program_id;
            } else {
                $check_sql .= " AND program_id IS NULL";
            }

            $check = $pdo->prepare($check_sql);
            $check->execute($check_params);

            if ($check->rowCount() > 0) {
                throw new Exception("Fee structure already exists for these criteria!");
            }

            $sql = "INSERT INTO fee_structure (
                session_year, level, program_id, fee_type, description, 
                amount, due_date, is_mandatory, applicable_to, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $session_year, $level, $program_id, $fee_type, $description,
                $amount, $due_date, $is_mandatory, $applicable_to
            ]);

            $_SESSION['success_message'] = "Fee structure added successfully!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }

    // Update fee structure
    if (isset($_POST['update_fee'])) {
        try {
            $fee_structure_id = (int)$_POST['fee_structure_id'];
            $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;

            $sql = "UPDATE fee_structure SET
                session_year = ?, level = ?, program_id = ?, fee_type = ?,
                description = ?, amount = ?, due_date = ?, is_mandatory = ?,
                applicable_to = ?
                WHERE fee_structure_id = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['session_year'], (int)$_POST['level'], $program_id,
                $_POST['fee_type'], $_POST['description'] ?? null, floatval($_POST['amount']),
                !empty($_POST['due_date']) ? $_POST['due_date'] : null, 
                isset($_POST['is_mandatory']) ? 1 : 0,
                $_POST['applicable_to'], $fee_structure_id
            ]);

            $_SESSION['success_message'] = "Fee structure updated!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }

    // Delete fee structure
    if (isset($_POST['delete_fee'])) {
        try {
            $fee_structure_id = (int)$_POST['fee_structure_id'];

            $check = $pdo->prepare("SELECT COUNT(*) FROM student_fees WHERE fee_structure_id = ?");
            $check->execute([$fee_structure_id]);

            if ($check->fetchColumn() > 0) {
                throw new Exception("Cannot delete — students have been billed for this fee!");
            }

            $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE fee_structure_id = ?");
            $stmt->execute([$fee_structure_id]);

            $_SESSION['success_message'] = "Fee structure deleted!";

        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }

    // Generate session fees for students
    if (isset($_POST['generate_fees'])) {
        try {
            $session_year = $_POST['session_year'];
            $program_id = (int)$_POST['program_id'];
            $level = (int)$_POST['level'];
            $student_type = $_POST['student_type'];

            $applicable_conditions = ["'All'"];
            if ($student_type == 'new') $applicable_conditions[] = "'New Students'";
            elseif ($student_type == 'returning') $applicable_conditions[] = "'Returning Students'";
            elseif ($student_type == 'all') {
                $applicable_conditions[] = "'New Students'";
                $applicable_conditions[] = "'Returning Students'";
            }

            $applicable_in = implode(',', $applicable_conditions);

            $fee_sql = "
                SELECT * FROM fee_structure 
                WHERE session_year = ? AND level = ? 
                AND (program_id = ? OR program_id IS NULL)
                AND applicable_to IN ($applicable_in)
                AND fee_type != 'Hostel'
            ";
            $fee_stmt = $pdo->prepare($fee_sql);
            $fee_stmt->execute([$session_year, $level, $program_id]);
            $fee_structures = $fee_stmt->fetchAll();

            if (empty($fee_structures)) {
                throw new Exception("No fee structures found! Create them first.");
            }

            $student_sql = "
                SELECT student_id, matric_number, first_name, last_name, mode_of_entry, admission_year 
                FROM students 
                WHERE program_id = ? AND current_level = ? AND status = 'Active'
            ";

            if ($student_type == 'new') {
                $student_sql .= " AND (mode_of_entry IN ('UTME', 'Direct Entry') OR admission_year = YEAR(CURDATE()))";
            } elseif ($student_type == 'returning') {
                $student_sql .= " AND admission_year < YEAR(CURDATE())";
            }

            $student_stmt = $pdo->prepare($student_sql);
            $student_stmt->execute([$program_id, $level]);
            $students = $student_stmt->fetchAll();

            if (empty($students)) {
                throw new Exception("No " . ucfirst($student_type) . " students found!");
            }

            $pdo->beginTransaction();
            $generated = 0;
            $skipped = 0;

            foreach ($students as $student) {
                $is_new = ($student['mode_of_entry'] && in_array($student['mode_of_entry'], ['UTME', 'Direct Entry'])) 
                          || $student['admission_year'] == date('Y');

                foreach ($fee_structures as $fee) {
                    if ($fee['fee_type'] == 'Acceptance' && !$is_new) continue;

                    $check = $pdo->prepare("
                        SELECT fee_id FROM student_fees 
                        WHERE student_id = ? AND fee_structure_id = ? AND session_year = ?
                    ");
                    $check->execute([$student['student_id'], $fee['fee_structure_id'], $session_year]);

                    if ($check->rowCount() == 0) {
                        $invoice = 'INV-' . str_replace('/', '', $session_year) . '-' . 
                                  str_replace('/', '', $student['matric_number']) . '-' . rand(1000, 9999);

                        $insert = $pdo->prepare("
                            INSERT INTO student_fees (
                                student_id, fee_structure_id, session_year, semester,
                                fee_type, description, amount, due_date, status, invoice_number
                            ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, 'Pending', ?)
                        ");

                        $insert->execute([
                            $student['student_id'], $fee['fee_structure_id'], $session_year,
                            $fee['fee_type'], $fee['description'] ?? $fee['fee_type'] . ' Fee', 
                            $fee['amount'], $fee['due_date'], $invoice
                        ]);

                        $generated++;
                    } else {
                        $skipped++;
                    }
                }
            }

            $pdo->commit();

            $msg = "Generated $generated fee records";
            if ($skipped > 0) $msg .= " (skipped $skipped existing)";
            $_SESSION['success_message'] = $msg;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }

    // Apply hostel fees (uses actual hostel/room prices)
    if (isset($_POST['apply_hostel_fee'])) {
        try {
            $session_year = $_POST['session_year'];
            $hostel_id = !empty($_POST['hostel_id']) ? (int)$_POST['hostel_id'] : null;

            $sql = "
                SELECT ha.*, s.matric_number, s.student_id,
                       hr.room_id, hr.monthly_rent as room_rent, hr.room_number,
                       h.hostel_name, h.monthly_rent as hostel_rent
                FROM hostel_allocations ha
                JOIN students s ON ha.student_id = s.student_id
                JOIN hostel_rooms hr ON ha.room_id = hr.room_id
                JOIN hostels h ON ha.hostel_id = h.hostel_id
                WHERE ha.academic_year = ? AND ha.status = 'Active'
            ";
            $params = [$session_year];

            if ($hostel_id) {
                $sql .= " AND ha.hostel_id = ?";
                $params[] = $hostel_id;
            }

            $allocations = $pdo->prepare($sql);
            $allocations->execute($params);
            $allocations = $allocations->fetchAll();

            if (empty($allocations)) {
                throw new Exception("No active hostel allocations found!");
            }

            $pdo->beginTransaction();
            $generated = 0;
            $skipped = 0;

            foreach ($allocations as $alloc) {
                $amount = floatval($alloc['room_rent'] ?? $alloc['hostel_rent'] ?? 0);

                if ($amount <= 0) {
                    $skipped++;
                    continue;
                }

                $check = $pdo->prepare("
                    SELECT fee_id FROM student_fees 
                    WHERE student_id = ? AND session_year = ? AND fee_type = 'Hostel'
                ");
                $check->execute([$alloc['student_id'], $session_year]);

                if ($check->rowCount() == 0) {
                    $invoice = 'HST-' . str_replace('/', '', $session_year) . '-' . 
                              str_replace('/', '', $alloc['matric_number']) . '-' . rand(1000, 9999);

                    $desc = "Hostel: " . $alloc['hostel_name'] . " (Room " . $alloc['room_number'] . ")";

                    $insert = $pdo->prepare("
                        INSERT INTO student_fees (
                            student_id, session_year, semester, fee_type, description,
                            amount, due_date, status, invoice_number
                        ) VALUES (?, ?, 1, 'Hostel', ?, ?, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'Pending', ?)
                    ");

                    $insert->execute([
                        $alloc['student_id'], $session_year, $desc, $amount, $invoice
                    ]);

                    $generated++;
                } else {
                    $skipped++;
                }
            }

            $pdo->commit();

            $msg = "Hostel fees applied to $generated students";
            if ($skipped > 0) $msg .= " (skipped $skipped existing or zero-price)";
            $_SESSION['success_message'] = $msg;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }

    // Bulk update amounts
    if (isset($_POST['bulk_update'])) {
        try {
            $fee_ids = $_POST['fee_ids'] ?? [];
            $new_amounts = $_POST['amounts'] ?? [];

            if (empty($fee_ids)) throw new Exception("No fees selected");

            $pdo->beginTransaction();
            $updated = 0;

            foreach ($fee_ids as $fee_id) {
                if (isset($new_amounts[$fee_id]) && floatval($new_amounts[$fee_id]) > 0) {
                    $stmt = $pdo->prepare("UPDATE fee_structure SET amount = ? WHERE fee_structure_id = ?");
                    $stmt->execute([floatval($new_amounts[$fee_id]), (int)$fee_id]);
                    $updated++;
                }
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Updated $updated fee structures!";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_message'] = $e->getMessage();
        }
        header("Location: fees.php");
        exit();
    }
}

// ============================================
// DATA QUERIES
// ============================================
$filter_session = $_GET['session_year'] ?? '';
$filter_program = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$filter_level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$filter_type = $_GET['fee_type'] ?? '';
$filter_applicable = $_GET['applicable_to'] ?? '';

$conditions = [];
$params = [];

if (!empty($filter_session)) { $conditions[] = "f.session_year = ?"; $params[] = $filter_session; }
if ($filter_program > 0) { $conditions[] = "(f.program_id = ? OR f.program_id IS NULL)"; $params[] = $filter_program; }
if ($filter_level > 0) { $conditions[] = "f.level = ?"; $params[] = $filter_level; }
if (!empty($filter_type)) { $conditions[] = "f.fee_type = ?"; $params[] = $filter_type; }
if (!empty($filter_applicable)) { $conditions[] = "(f.applicable_to = ? OR f.applicable_to = 'All')"; $params[] = $filter_applicable; }

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$fee_structures = $pdo->prepare("
    SELECT f.*, p.program_name, p.program_code
    FROM fee_structure f
    LEFT JOIN programs p ON f.program_id = p.program_id
    $where_clause
    ORDER BY f.session_year DESC, f.level, f.fee_type
");
$fee_structures->execute($params);
$fee_structures = $fee_structures->fetchAll();

$sessions = $pdo->query("SELECT DISTINCT session_year FROM fee_structure ORDER BY session_year DESC")->fetchAll();
$programs = $pdo->query("SELECT program_id, program_name, program_code FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$levels = [100, 200, 300, 400, 500, 600];
$fee_types = ['Tuition', 'Acceptance', 'Library', 'Sports', 'Development', 'Medical', 'Examination', 'Other'];
$applicable_options = ['All', 'New Students', 'Returning Students', 'Final Year'];

$hostels = $pdo->query("
    SELECT h.*, COUNT(hr.room_id) as room_count,
           SUM(CASE WHEN hr.status = 'Available' THEN hr.bed_count ELSE 0 END) as available_beds
    FROM hostels h
    LEFT JOIN hostel_rooms hr ON h.hostel_id = hr.hostel_id
    GROUP BY h.hostel_id
    ORDER BY h.hostel_name
")->fetchAll();

$stats = [
    'total_fees' => $pdo->query("SELECT COUNT(*) FROM fee_structure")->fetchColumn(),
    'total_amount' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fee_structure")->fetchColumn(),
    'active_sessions' => $pdo->query("SELECT COUNT(DISTINCT session_year) FROM fee_structure")->fetchColumn(),
    'student_fees_count' => $pdo->query("SELECT COUNT(*) FROM student_fees")->fetchColumn(),
];

$collections = $pdo->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total_collected,
           COUNT(DISTINCT student_id) as paying_students,
           COUNT(*) as total_payments
    FROM student_fees 
    WHERE status = 'Paid'
")->fetch();

$year = date('Y');
?>

<style>
:root {
    --primary: #4f46e5; --primary-light: #818cf8; --success: #10b981;
    --warning: #f59e0b; --danger: #ef4444; --info: #3b82f6;
    --dark: #1e293b; --light: #f8fafc; --card-bg: #ffffff;
    --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0;
    --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --radius: 12px; --radius-sm: 8px;
}
[data-theme="dark"] {
    --card-bg: #1e293b; --text-main: #f1f5f9; --text-muted: #94a3b8;
    --border: #334155; --light: #0f172a;
}
body { background: var(--light); color: var(--text-main); }

.stat-card {
    background: var(--card-bg); border-radius: var(--radius); padding: 1.5rem;
    box-shadow: var(--shadow); transition: all 0.3s ease;
    border: 1px solid var(--border); position: relative; overflow: hidden;
}
.stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    border-radius: var(--radius) var(--radius) 0 0;
}
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
.stat-card.primary::before { background: var(--primary); }
.stat-card.success::before { background: var(--success); }
.stat-card.warning::before { background: var(--warning); }
.stat-card.info::before { background: var(--info); }

.stat-icon {
    width: 48px; height: 48px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; margin-bottom: 1rem;
}
.stat-card.primary .stat-icon { background: rgba(79, 70, 229, 0.1); color: var(--primary); }
.stat-card.success .stat-icon { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.stat-card.warning .stat-icon { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.stat-card.info .stat-icon { background: rgba(59, 130, 246, 0.1); color: var(--info); }

.stat-value { font-size: 1.875rem; font-weight: 700; color: var(--text-main); line-height: 1; }
.stat-label { font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem; }

.app-card {
    background: var(--card-bg); border-radius: var(--radius);
    box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden;
}
.app-card .card-header {
    padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);
    background: transparent; font-weight: 600;
}
.app-card .card-body { padding: 1.5rem; }

.btn-pro {
    border-radius: var(--radius-sm); padding: 0.625rem 1.25rem;
    font-weight: 500; transition: all 0.2s; border: none;
}
.btn-pro:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-pro-primary { background: var(--primary); color: white; }
.btn-pro-success { background: var(--success); color: white; }
.btn-pro-warning { background: var(--warning); color: white; }
.btn-pro-danger { background: var(--danger); color: white; }
.btn-pro-info { background: var(--info); color: white; }

.pro-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.pro-table thead th {
    background: var(--light); padding: 0.875rem 1rem; font-size: 0.75rem;
    font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--text-muted); border-bottom: 2px solid var(--border);
}
.pro-table tbody td { padding: 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
.pro-table tbody tr:hover { background: rgba(79, 70, 229, 0.02); }
.pro-table tbody tr:last-child td { border-bottom: none; }

.badge-pro {
    padding: 0.375rem 0.75rem; border-radius: 9999px;
    font-size: 0.75rem; font-weight: 500;
}
.badge-pro-primary { background: rgba(79, 70, 229, 0.1); color: var(--primary); }
.badge-pro-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.badge-pro-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.badge-pro-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
.badge-pro-info { background: rgba(59, 130, 246, 0.1); color: var(--info); }
.badge-pro-dark { background: rgba(30, 41, 59, 0.1); color: var(--dark); }

.form-pro {
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    padding: 0.625rem 0.875rem; font-size: 0.875rem;
    transition: all 0.2s; background: var(--card-bg); color: var(--text-main);
}
.form-pro:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); outline: none;
}

.modal-content {
    border-radius: var(--radius); border: none; box-shadow: var(--shadow-lg);
}
.modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); }
.modal-body { padding: 1.5rem; }
.modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); }

@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-in { animation: slideIn 0.3s ease-out; }

.toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
.toast-pro {
    background: var(--card-bg); border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg); padding: 1rem 1.25rem;
    margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem;
    border-left: 4px solid; animation: slideIn 0.3s ease-out; min-width: 300px;
}
.toast-pro.success { border-color: var(--success); }
.toast-pro.error { border-color: var(--danger); }

.empty-state { text-align: center; padding: 3rem 1.5rem; color: var(--text-muted); }
.empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

@media (max-width: 768px) {
    .stat-card { margin-bottom: 1rem; }
    .btn-group-responsive { display: flex; flex-direction: column; gap: 0.5rem; }
    .btn-group-responsive .btn { width: 100%; margin: 0 !important; }
}
</style>

<div class="toast-container">
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="toast-pro success">
        <i class="fas fa-check-circle text-success fa-lg"></i>
        <div><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="toast-pro error">
        <i class="fas fa-exclamation-circle text-danger fa-lg"></i>
        <div><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    </div>
<?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-4 animate-in">
    <div>
        <h2 class="fw-bold mb-1"><i class="fas fa-money-bill-wave text-primary me-2"></i>Fee Management</h2>
        <p class="text-muted mb-0">Manage session-based fees, generate student bills, and track hostel payments</p>
    </div>
    <div class="btn-group-responsive">
        <button class="btn-pro btn-pro-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
            <i class="fas fa-plus me-2"></i>Add Fee Structure
        </button>
        <button class="btn-pro btn-pro-success ms-2" data-bs-toggle="modal" data-bs-target="#generateFeeModal">
            <i class="fas fa-cogs me-2"></i>Generate Fees
        </button>
        <a href="fee_payments.php" class="btn-pro btn-pro-info ms-2">
            <i class="fas fa-credit-card me-2"></i>Payments
        </a>
    </div>
</div>

<div class="row g-3 mb-4 animate-in">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card primary">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                    <div class="stat-value" data-count="<?php echo $stats['total_fees']; ?>">0</div>
                    <div class="stat-label">Total Fee Structures</div>
                </div>
                <span class="badge-pro badge-pro-primary"><?php echo $stats['active_sessions']; ?> sessions</span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card success">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-value" data-count="<?php echo $stats['total_amount']; ?>" data-prefix="₦" data-decimals="0">0</div>
                    <div class="stat-label">Total Fee Amount</div>
                </div>
                <span class="badge-pro badge-pro-success">Active</span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card warning">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value" data-count="<?php echo $stats['student_fees_count']; ?>">0</div>
                    <div class="stat-label">Student Fee Records</div>
                </div>
                <span class="badge-pro badge-pro-warning">Billed</span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card info">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-value" data-count="<?php echo $collections['total_collected'] ?? 0; ?>" data-prefix="₦" data-decimals="0">0</div>
                    <div class="stat-label">Total Collected</div>
                </div>
                <span class="badge-pro badge-pro-info"><?php echo $collections['paying_students'] ?? 0; ?> students</span>
            </div>
        </div>
    </div>
</div>

<div class="app-card mb-4 animate-in">
    <div class="card-header d-flex align-items-center">
        <i class="fas fa-filter text-primary me-2"></i>
        <span>Filter Fee Structures</span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted fw-semibold">Session</label>
                <select class="form-pro w-100" name="session_year">
                    <option value="">All Sessions</option>
                    <?php foreach ($sessions as $session): ?>
                    <option value="<?php echo htmlspecialchars($session['session_year']); ?>" 
                        <?php echo $filter_session == $session['session_year'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($session['session_year']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label small text-muted fw-semibold">Program</label>
                <select class="form-pro w-100" name="program_id">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo $prog['program_id']; ?>" 
                        <?php echo $filter_program == $prog['program_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($prog['program_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted fw-semibold">Level</label>
                <select class="form-pro w-100" name="level">
                    <option value="0">All Levels</option>
                    <?php foreach ($levels as $lvl): ?>
                    <option value="<?php echo $lvl; ?>" <?php echo $filter_level == $lvl ? 'selected' : ''; ?>>
                        <?php echo $lvl; ?>L
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted fw-semibold">Fee Type</label>
                <select class="form-pro w-100" name="fee_type">
                    <option value="">All Types</option>
                    <?php foreach ($fee_types as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo $filter_type == $type ? 'selected' : ''; ?>>
                        <?php echo $type; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted fw-semibold">Applicable</label>
                <select class="form-pro w-100" name="applicable_to">
                    <option value="">All</option>
                    <?php foreach ($applicable_options as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $filter_applicable == $opt ? 'selected' : ''; ?>>
                        <?php echo $opt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn-pro btn-pro-primary w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="app-card animate-in">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <i class="fas fa-list text-primary me-2"></i>
            <span>Fee Structures</span>
            <span class="badge-pro badge-pro-primary ms-2"><?php echo count($fee_structures); ?></span>
        </div>
        <button class="btn-pro btn-pro-warning btn-sm" onclick="showBulkUpdate()">
            <i class="fas fa-pencil-alt me-1"></i>Bulk Update
        </button>
    </div>
    <div class="card-body p-0">
        <form method="POST" id="bulkUpdateForm" style="display: none;" class="p-3 bg-light border-bottom">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <p class="mb-2 fw-semibold"><i class="fas fa-edit text-warning me-2"></i>Bulk Update Amounts</p>
                    <div id="bulkInputs" class="row"></div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" name="bulk_update" class="btn-pro btn-pro-warning">
                        <i class="fas fa-save me-2"></i>Update Selected
                    </button>
                </div>
            </div>
        </form>
        <div class="table-responsive">
            <table class="pro-table">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" id="selectAll" onclick="toggleAll(this)" class="form-check-input"></th>
                        <th>Session</th>
                        <th>Program</th>
                        <th>Level</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>Due Date</th>
                        <th>Applicable</th>
                        <th>Required</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fee_structures)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <h5>No Fee Structures Found</h5>
                                <p>Create your first fee structure to start billing students</p>
                                <button class="btn-pro btn-pro-primary mt-2" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                                    <i class="fas fa-plus me-2"></i>Add Fee Structure
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($fee_structures as $fee): ?>
                    <tr>
                        <td><input type="checkbox" class="fee-checkbox form-check-input" value="<?php echo $fee['fee_structure_id']; ?>"></td>
                        <td><span class="fw-semibold"><?php echo htmlspecialchars($fee['session_year']); ?></span></td>
                        <td>
                            <span class="badge-pro badge-pro-dark"><?php echo htmlspecialchars($fee['program_code'] ?? 'ALL'); ?></span>
                            <div class="small text-muted"><?php echo htmlspecialchars($fee['program_name'] ?? 'All Programs'); ?></div>
                        </td>
                        <td><span class="badge-pro badge-pro-info"><?php echo $fee['level']; ?>L</span></td>
                        <td>
                            <?php
                            $type_config = [
                                'Tuition' => ['primary', 'fa-graduation-cap'],
                                'Acceptance' => ['success', 'fa-handshake'],
                                'Hostel' => ['warning', 'fa-bed'],
                                'Library' => ['info', 'fa-book'],
                                'Sports' => ['warning', 'fa-running'],
                                'Development' => ['dark', 'fa-building'],
                                'Medical' => ['danger', 'fa-hospital'],
                                'Examination' => ['primary', 'fa-file-alt'],
                                'Other' => ['secondary', 'fa-circle']
                            ][$fee['fee_type']] ?? ['secondary', 'fa-circle'];
                            ?>
                            <span class="badge-pro badge-pro-<?php echo $type_config[0]; ?>">
                                <i class="fas <?php echo $type_config[1]; ?> me-1"></i><?php echo $fee['fee_type']; ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($fee['description'] ?? '—'); ?></small></td>
                        <td class="text-end"><span class="fw-bold text-primary"><?php echo formatCurrency($fee['amount']); ?></span></td>
                        <td>
                            <?php if ($fee['due_date']): ?>
                                <span class="badge-pro badge-pro-<?php echo strtotime($fee['due_date']) < time() ? 'danger' : 'success'; ?>">
                                    <?php echo date('M d, Y', strtotime($fee['due_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $app_class = ['All' => 'success', 'New Students' => 'info', 'Returning Students' => 'warning', 'Final Year' => 'dark'][$fee['applicable_to']] ?? 'secondary';
                            $app_icon = ['All' => 'fa-users', 'New Students' => 'fa-user-plus', 'Returning Students' => 'fa-user-check', 'Final Year' => 'fa-user-graduate'][$fee['applicable_to']] ?? 'fa-user';
                            ?>
                            <span class="badge-pro badge-pro-<?php echo $app_class; ?>">
                                <i class="fas <?php echo $app_icon; ?> me-1"></i><?php echo $fee['applicable_to']; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($fee['is_mandatory']): ?>
                                <i class="fas fa-check-circle text-success" title="Mandatory"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle text-muted" title="Optional"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="editFee(<?php echo htmlspecialchars(json_encode($fee)); ?>)" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteFee(<?php echo $fee['fee_structure_id']; ?>)" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Fee Structure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addFeeForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Session Year <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="session_year" required>
                                <option value="">Select Session</option>
                                <?php for ($y = $year - 1; $y <= $year + 3; $y++): 
                                    $session = $y . '/' . ($y + 1); ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Level <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="level" required>
                                <option value="">Select</option>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?>L</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Program</label>
                            <select class="form-pro w-100" name="program_id">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>"><?php echo htmlspecialchars($prog['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Fee Type <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="fee_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($fee_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Amount (₦) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input type="number" class="form-pro w-100" name="amount" min="0" step="100" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" class="form-pro w-100" name="due_date">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-pro w-100" name="description" rows="2" placeholder="What this fee covers..."></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Applicable To <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="applicable_to" required>
                                <option value="All">All Students</option>
                                <option value="New Students">New Students Only</option>
                                <option value="Returning Students">Returning Students Only</option>
                                <option value="Final Year">Final Year Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_mandatory" id="isMandatory" checked>
                            <label class="form-check-label fw-semibold" for="isMandatory">
                                Mandatory Fee <span class="text-muted fw-normal">(Required for all students)</span>
                            </label>
                        </div>
                    </div>
                    <div class="alert alert-info d-flex align-items-center">
                        <i class="fas fa-info-circle me-2 fa-lg"></i>
                        <div><strong>Session-Based:</strong> This fee applies to the entire academic session. Students are billed once per session.</div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn-pro btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_fee" class="btn-pro btn-pro-primary ms-2">
                            <i class="fas fa-save me-2"></i>Save Fee Structure
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Fee Structure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editFeeForm">
                    <input type="hidden" name="fee_structure_id" id="edit_fee_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Session Year <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="session_year" id="edit_session_year" required>
                                <option value="">Select Session</option>
                                <?php for ($y = $year - 1; $y <= $year + 3; $y++): 
                                    $session = $y . '/' . ($y + 1); ?>
                                <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Level <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="level" id="edit_level" required>
                                <option value="">Select</option>
                                <?php foreach ($levels as $lvl): ?>
                                <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?>L</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Program</label>
                            <select class="form-pro w-100" name="program_id" id="edit_program_id">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['program_id']; ?>"><?php echo htmlspecialchars($prog['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Fee Type <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="fee_type" id="edit_fee_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($fee_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Amount (₦) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input type="number" class="form-pro w-100" name="amount" id="edit_amount" min="0" step="100" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" class="form-pro w-100" name="due_date" id="edit_due_date">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-pro w-100" name="description" id="edit_description" rows="2"></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-semibold">Applicable To <span class="text-danger">*</span></label>
                            <select class="form-pro w-100" name="applicable_to" id="edit_applicable_to" required>
                                <option value="All">All Students</option>
                                <option value="New Students">New Students Only</option>
                                <option value="Returning Students">Returning Students Only</option>
                                <option value="Final Year">Final Year Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_mandatory" id="edit_is_mandatory">
                            <label class="form-check-label fw-semibold" for="edit_is_mandatory">Mandatory Fee</label>
                        </div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn-pro btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_fee" class="btn-pro btn-pro-info ms-2">
                            <i class="fas fa-save me-2"></i>Update Fee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="generateFeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-cogs me-2"></i>Generate Student Fees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-pills mb-4" id="feeTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tuition-tab" data-bs-toggle="tab" data-bs-target="#tuition" type="button">
                            <i class="fas fa-graduation-cap me-1"></i>Session Fees
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="hostel-tab" data-bs-toggle="tab" data-bs-target="#hostel" type="button">
                            <i class="fas fa-bed me-1"></i>Hostel Fees
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tuition">
                        <form method="POST" id="generateFeeForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Session Year <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="session_year" required>
                                        <option value="">Select Session</option>
                                        <?php for ($y = $year; $y <= $year + 3; $y++): 
                                            $session = $y . '/' . ($y + 1); ?>
                                        <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="program_id" required>
                                        <option value="">Select Program</option>
                                        <?php foreach ($programs as $prog): ?>
                                        <option value="<?php echo $prog['program_id']; ?>"><?php echo htmlspecialchars($prog['program_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Level <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="level" required>
                                        <option value="">Select Level</option>
                                        <?php foreach ($levels as $lvl): ?>
                                        <option value="<?php echo $lvl; ?>"><?php echo $lvl; ?>L</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Student Type <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="student_type" required>
                                        <option value="all">All Students</option>
                                        <option value="new">New Students (Freshers)</option>
                                        <option value="returning">Returning Students</option>
                                    </select>
                                </div>
                            </div>
                            <div class="alert alert-warning d-flex align-items-center">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <div>This will generate <strong>session-based</strong> fees for all matching students. New students get Acceptance + Tuition. Returning students get Tuition only.</div>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn-pro btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="generate_fees" class="btn-pro btn-pro-success ms-2">
                                    <i class="fas fa-play me-2"></i>Generate Fees
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="hostel">
                        <form method="POST" id="hostelFeeForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Session Year <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="session_year" required>
                                        <option value="">Select Session</option>
                                        <?php for ($y = $year; $y <= $year + 3; $y++): 
                                            $session = $y . '/' . ($y + 1); ?>
                                        <option value="<?php echo $session; ?>"><?php echo $session; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Hostel <span class="text-danger">*</span></label>
                                    <select class="form-pro w-100" name="hostel_id" id="hostel_select" required onchange="updateHostelPrice()">
                                        <option value="">All Hostels (Auto-Price)</option>
                                        <?php foreach ($hostels as $hostel): ?>
                                        <option value="<?php echo $hostel['hostel_id']; ?>" 
                                                data-rent="<?php echo $hostel['monthly_rent']; ?>"
                                                data-name="<?php echo htmlspecialchars($hostel['hostel_name']); ?>">
                                            <?php echo htmlspecialchars($hostel['hostel_name']); ?> 
                                            (₦<?php echo number_format($hostel['monthly_rent'], 0); ?>/mo)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Amount (₦) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" class="form-pro w-100" name="amount" id="hostel_amount" min="0" step="100" required>
                                    </div>
                                    <small class="text-muted">Auto-filled from hostel price. Edit if needed.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-pro w-100" name="due_date" required>
                                </div>
                            </div>
                            <div class="alert alert-info d-flex align-items-center">
                                <i class="fas fa-info-circle me-2"></i>
                                <div>Hostel fees use each hostel's configured price. Select "All Hostels" to apply respective prices automatically.</div>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn-pro btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="apply_hostel_fee" class="btn-pro btn-pro-success ms-2">
                                    <i class="fas fa-bed me-2"></i>Apply Hostel Fees
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.stat-value[data-count]').forEach(el => {
        const target = parseFloat(el.dataset.count);
        const prefix = el.dataset.prefix || '';
        const decimals = parseInt(el.dataset.decimals) || 0;
        const duration = 1500;
        const start = 0;
        const startTime = performance.now();

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = start + (target - start) * easeOut;

            el.textContent = prefix + current.toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });

            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    });
});

function toggleAll(source) {
    document.querySelectorAll('.fee-checkbox').forEach(cb => {
        cb.checked = source.checked;
    });
    updateBulkInputs();
}

function updateBulkInputs() {
    const checkboxes = document.querySelectorAll('.fee-checkbox:checked');
    const bulkForm = document.getElementById('bulkUpdateForm');
    const bulkInputs = document.getElementById('bulkInputs');

    if (checkboxes.length > 0) {
        let html = '<div class="row">';
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            const feeType = row.cells[4].textContent.trim();
            const amount = row.cells[6].textContent.trim().replace('₦', '').replace(/,/g, '');
            const feeId = cb.value;

            html += `
                <div class="col-md-6 mb-2">
                    <label class="small">${feeType} (ID: ${feeId})</label>
                    <input type="number" class="form-pro w-100" 
                           name="amounts[${feeId}]" value="${amount}" min="0" step="100">
                    <input type="hidden" name="fee_ids[]" value="${feeId}">
                </div>
            `;
        });
        html += '</div>';
        bulkInputs.innerHTML = html;
        bulkForm.style.display = 'block';
    } else {
        bulkForm.style.display = 'none';
    }
}

document.querySelectorAll('.fee-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkInputs);
});

function showBulkUpdate() {
    const checkboxes = document.querySelectorAll('.fee-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one fee structure.');
        return;
    }
    document.getElementById('bulkUpdateForm').style.display = 'block';
    document.getElementById('bulkUpdateForm').scrollIntoView({ behavior: 'smooth' });
}

function editFee(fee) {
    document.getElementById('edit_fee_id').value = fee.fee_structure_id;
    document.getElementById('edit_session_year').value = fee.session_year;
    document.getElementById('edit_level').value = fee.level;
    document.getElementById('edit_program_id').value = fee.program_id ?? '';
    document.getElementById('edit_fee_type').value = fee.fee_type;
    document.getElementById('edit_amount').value = fee.amount;
    document.getElementById('edit_due_date').value = fee.due_date || '';
    document.getElementById('edit_description').value = fee.description || '';
    document.getElementById('edit_applicable_to').value = fee.applicable_to;
    document.getElementById('edit_is_mandatory').checked = fee.is_mandatory == 1;

    new bootstrap.Modal(document.getElementById('editFeeModal')).show();
}

function deleteFee(feeId) {
    if (confirm('Are you sure you want to delete this fee structure? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="fee_structure_id" value="${feeId}"><input type="hidden" name="delete_fee" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function updateHostelPrice() {
    const select = document.getElementById('hostel_select');
    const amountInput = document.getElementById('hostel_amount');
    const option = select.options[select.selectedIndex];

    if (option.value) {
        const rent = option.dataset.rent;
        if (rent && parseFloat(rent) > 0) {
            amountInput.value = rent;
        }
    } else {
        amountInput.value = '';
        amountInput.placeholder = 'Will use each hostel\'s price';
    }
}

document.getElementById('addFeeForm')?.addEventListener('submit', function(e) {
    const amount = this.querySelector('input[name="amount"]').value;
    if (parseFloat(amount) <= 0) {
        e.preventDefault();
        alert('Amount must be greater than 0.');
    }
});

document.getElementById('generateFeeForm')?.addEventListener('submit', function(e) {
    return confirm('Generate session fees for selected students? This may take a moment.');
});

document.getElementById('hostelFeeForm')?.addEventListener('submit', function(e) {
    return confirm('Apply hostel fees to all allocated students?');
});

updateBulkInputs();
</script>

<?php
require_once 'includes/footer.php';
?>