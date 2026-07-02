<?php
require_once 'includes/header.php';

$page_title = "Manage Student Fees";

// ============================================
// BULK ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];

        if (!empty($selected_ids)) {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

            try {
                switch ($action) {
                    case 'mark_paid':
                        $stmt = $pdo->prepare("UPDATE student_fees SET status = 'Paid', amount_paid = amount, updated_date = NOW() WHERE fee_id IN ($placeholders)");
                        $stmt->execute($selected_ids);
                        $_SESSION['success_message'] = "✅ " . $stmt->rowCount() . " fees marked as paid!";
                        break;

                    case 'mark_pending':
                        $stmt = $pdo->prepare("UPDATE student_fees SET status = 'Pending', amount_paid = 0, updated_date = NOW() WHERE fee_id IN ($placeholders)");
                        $stmt->execute($selected_ids);
                        $_SESSION['success_message'] = "✅ " . $stmt->rowCount() . " fees marked as pending!";
                        break;

                    case 'send_reminders':
                        foreach ($selected_ids as $fee_id) {
                            $fee = $pdo->prepare("
                                SELECT sf.*, s.email, s.first_name, s.last_name 
                                FROM student_fees sf 
                                JOIN students s ON sf.student_id = s.student_id 
                                WHERE sf.fee_id = ?
                            ");
                            $fee->execute([$fee_id]);
                            $fee_data = $fee->fetch();

                            if ($fee_data) {
                                $queue = $pdo->prepare("
                                    INSERT INTO email_queue (student_id, recipient_email, recipient_name, subject, message, priority) 
                                    VALUES (?, ?, ?, ?, ?, 'High')
                                ");
                                $subject = "Fee Payment Reminder - Invoice " . $fee_data['invoice_number'];
                                $message = "Dear " . $fee_data['first_name'] . ",\n\nThis is a reminder that your fee payment of ₦" . number_format($fee_data['balance'], 2) . " for " . $fee_data['fee_type'] . " is pending.\n\nPlease make payment before the due date.\n\nThank you.";
                                $queue->execute([
                                    $fee_data['student_id'], 
                                    $fee_data['email'], 
                                    $fee_data['first_name'] . ' ' . $fee_data['last_name'],
                                    $subject, 
                                    $message
                                ]);
                            }
                        }
                        $_SESSION['success_message'] = "📧 Reminders queued for " . count($selected_ids) . " students!";
                        break;

                    case 'delete':
                        $stmt = $pdo->prepare("DELETE FROM student_fees WHERE fee_id IN ($placeholders) AND status = 'Pending'");
                        $stmt->execute($selected_ids);
                        $deleted = $stmt->rowCount();
                        if ($deleted > 0) {
                            $_SESSION['success_message'] = "🗑️ Deleted $deleted pending fee records!";
                        } else {
                            $_SESSION['error_message'] = "❌ Cannot delete paid fees. Only pending fees can be removed.";
                        }
                        break;

                    case 'export':
                        header('Content-Type: text/csv');
                        header('Content-Disposition: attachment; filename="student_fees_' . date('Y-m-d') . '.csv"');
                        $output = fopen('php://output', 'w');
                        fputcsv($output, ['Invoice', 'Student', 'Matric', 'Fee Type', 'Amount', 'Paid', 'Balance', 'Status', 'Due Date', 'Session']);

                        $export = $pdo->prepare("
                            SELECT sf.*, s.first_name, s.last_name, s.matric_number 
                            FROM student_fees sf 
                            JOIN students s ON sf.student_id = s.student_id 
                            WHERE sf.fee_id IN ($placeholders)
                        ");
                        $export->execute($selected_ids);
                        while ($row = $export->fetch()) {
                            fputcsv($output, [
                                $row['invoice_number'],
                                $row['first_name'] . ' ' . $row['last_name'],
                                $row['matric_number'],
                                $row['fee_type'],
                                $row['amount'],
                                $row['amount_paid'],
                                $row['balance'],
                                $row['status'],
                                $row['due_date'],
                                $row['session_year']
                            ]);
                        }
                        fclose($output);
                        exit();
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = "❌ Error: " . $e->getMessage();
            }
        }
        header("Location: manage_fees.php?" . $_SERVER['QUERY_STRING']);
        exit();
    }
}

// ============================================
// FILTERS
// ============================================
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$fee_type = $_GET['fee_type'] ?? '';
$session_year = $_GET['session_year'] ?? '';
$level = $_GET['level'] ?? '';
$department_id = $_GET['department_id'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR sf.invoice_number LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if (!empty($status)) {
    $where_conditions[] = "sf.status = ?";
    $params[] = $status;
}

if (!empty($fee_type)) {
    $where_conditions[] = "sf.fee_type = ?";
    $params[] = $fee_type;
}

if (!empty($session_year)) {
    $where_conditions[] = "sf.session_year = ?";
    $params[] = $session_year;
}

if (!empty($level)) {
    $where_conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if (!empty($department_id)) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get fees with pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM student_fees sf JOIN students s ON sf.student_id = s.student_id $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = (int) ($count_stmt->fetchColumn() ?? 0);
$total_pages = max(1, ceil($total_records / $per_page));

$sql = "
    SELECT sf.*, 
           s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.phone,
           s.current_level, s.current_session, s.department_id,
           d.department_name,
           p.program_name,
           fs.fee_structure_id
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN fee_structure fs ON sf.fee_structure_id = fs.fee_structure_id
    $where_sql
    ORDER BY sf.created_date DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fees = $stmt->fetchAll();

// Get filter options
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$fee_types = $pdo->query("SELECT DISTINCT fee_type FROM student_fees WHERE fee_type IS NOT NULL ORDER BY fee_type")->fetchAll();
$session_years = $pdo->query("SELECT DISTINCT session_year FROM student_fees WHERE session_year IS NOT NULL ORDER BY session_year DESC")->fetchAll();

// Statistics - FIX: Use COALESCE to handle NULL values
$stats_sql = "
    SELECT 
        COALESCE(SUM(sf.amount), 0) as total_amount,
        COALESCE(SUM(sf.amount_paid), 0) as total_paid,
        COALESCE(SUM(sf.balance), 0) as total_balance,
        COUNT(*) as total_records,
        COALESCE(SUM(CASE WHEN sf.status = 'Paid' THEN 1 ELSE 0 END), 0) as paid_count,
        COALESCE(SUM(CASE WHEN sf.status = 'Pending' THEN 1 ELSE 0 END), 0) as pending_count,
        COALESCE(SUM(CASE WHEN sf.status = 'Partial' THEN 1 ELSE 0 END), 0) as partial_count,
        COALESCE(SUM(CASE WHEN sf.status = 'Overdue' THEN 1 ELSE 0 END), 0) as overdue_count
    FROM student_fees sf
    JOIN students s ON sf.student_id = s.student_id
    $where_sql
";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Ensure all stats are numeric (not null)
$stats['total_amount'] = (float) ($stats['total_amount'] ?? 0);
$stats['total_paid'] = (float) ($stats['total_paid'] ?? 0);
$stats['total_balance'] = (float) ($stats['total_balance'] ?? 0);
$stats['total_records'] = (int) ($stats['total_records'] ?? 0);
$stats['paid_count'] = (int) ($stats['paid_count'] ?? 0);
$stats['pending_count'] = (int) ($stats['pending_count'] ?? 0);
$stats['partial_count'] = (int) ($stats['partial_count'] ?? 0);
$stats['overdue_count'] = (int) ($stats['overdue_count'] ?? 0);

$collection_rate = $stats['total_amount'] > 0 ? ($stats['total_paid'] / $stats['total_amount']) * 100 : 0;
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
body { background: var(--light); color: var(--text-main); }

.stat-card {
    background: var(--card-bg); border-radius: var(--radius); padding: 1.25rem;
    box-shadow: var(--shadow); transition: all 0.3s ease;
    border: 1px solid var(--border); position: relative; overflow: hidden;
}
.stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.stat-card.primary::before { background: var(--primary); }
.stat-card.success::before { background: var(--success); }
.stat-card.warning::before { background: var(--warning); }
.stat-card.danger::before { background: var(--danger); }
.stat-card.info::before { background: var(--info); }

.stat-icon {
    width: 40px; height: 40px; border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; margin-bottom: 0.75rem;
}
.stat-card.primary .stat-icon { background: rgba(79, 70, 229, 0.1); color: var(--primary); }
.stat-card.success .stat-icon { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.stat-card.warning .stat-icon { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.stat-card.danger .stat-icon { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
.stat-card.info .stat-icon { background: rgba(59, 130, 246, 0.1); color: var(--info); }

.stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1; }
.stat-label { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem; }

.app-card {
    background: var(--card-bg); border-radius: var(--radius);
    box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden;
}
.app-card .card-header {
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);
    background: transparent; font-weight: 600; display: flex; align-items: center;
}

.btn-pro {
    border-radius: var(--radius-sm); padding: 0.5rem 1rem;
    font-weight: 500; transition: all 0.2s; border: none;
}
.btn-pro:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-pro-primary { background: var(--primary); color: white; }
.btn-pro-success { background: var(--success); color: white; }
.btn-pro-warning { background: var(--warning); color: white; }
.btn-pro-danger { background: var(--danger); color: white; }
.btn-pro-info { background: var(--info); color: white; }
.btn-pro-secondary { background: var(--light); color: var(--text-main); border: 1px solid var(--border); }

.pro-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.pro-table thead th {
    background: var(--light); padding: 0.75rem 1rem; font-size: 0.7rem;
    font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--text-muted); border-bottom: 2px solid var(--border);
}
.pro-table tbody td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
.pro-table tbody tr:hover { background: rgba(79, 70, 229, 0.02); }
.pro-table tbody tr:last-child td { border-bottom: none; }

.badge-pro {
    padding: 0.3rem 0.6rem; border-radius: 9999px;
    font-size: 0.7rem; font-weight: 500;
}
.badge-pro-primary { background: rgba(79, 70, 229, 0.1); color: var(--primary); }
.badge-pro-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
.badge-pro-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
.badge-pro-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
.badge-pro-info { background: rgba(59, 130, 246, 0.1); color: var(--info); }
.badge-pro-secondary { background: rgba(100, 116, 139, 0.1); color: var(--text-muted); }

.form-pro {
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    padding: 0.5rem 0.75rem; font-size: 0.875rem;
    transition: all 0.2s; background: var(--card-bg);
}
.form-pro:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); outline: none;
}

.toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
.toast-pro {
    background: var(--card-bg); border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg); padding: 1rem 1.25rem;
    margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem;
    border-left: 4px solid; min-width: 300px;
    animation: slideIn 0.3s ease-out;
}
.toast-pro.success { border-color: var(--success); }
.toast-pro.error { border-color: var(--danger); }
.toast-pro.info { border-color: var(--info); }

@keyframes slideIn {
    from { opacity: 0; transform: translateX(20px); }
    to { opacity: 1; transform: translateX(0); }
}

.pagination .page-link {
    border-radius: var(--radius-sm); margin: 0 0.125rem;
    border: 1px solid var(--border); color: var(--text-main);
}
.pagination .page-item.active .page-link {
    background: var(--primary); border-color: var(--primary); color: white;
}

.empty-state { text-align: center; padding: 3rem; color: var(--text-muted); }
.empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.4; }
</style>

<!-- Toast Notifications -->
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
<?php if (isset($_SESSION['info_message'])): ?>
    <div class="toast-pro info">
        <i class="fas fa-info-circle text-info fa-lg"></i>
        <div><?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
    </div>
<?php endif; ?>
</div>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Manage Student Fees</h2>
        <p class="text-muted mb-0">View, track, and manage all student fee invoices</p>
    </div>
    <div class="d-flex gap-2">
        <a href="fees.php" class="btn-pro btn-pro-secondary">
            <i class="fas fa-cog me-2"></i>Fee Structures
        </a>
        <a href="record_payment.php" class="btn-pro btn-pro-success">
            <i class="fas fa-plus me-2"></i>Record Payment
        </a>
    </div>
</div>

<!-- Statistics -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_records']); ?></div>
            <div class="stat-label">Total Invoices</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card success">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?php echo number_format($stats['paid_count']); ?></div>
            <div class="stat-label">Paid</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?php echo number_format($stats['pending_count']); ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card info">
            <div class="stat-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-value"><?php echo number_format($stats['partial_count']); ?></div>
            <div class="stat-label">Partial</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card danger">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value"><?php echo number_format($stats['overdue_count']); ?></div>
            <div class="stat-label">Overdue</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="stat-card primary">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="stat-icon mb-0"><i class="fas fa-chart-pie"></i></div>
                <span class="fw-bold text-primary"><?php echo number_format($collection_rate, 1); ?>%</span>
            </div>
            <div class="progress" style="height: 6px;">
                <div class="progress-bar bg-primary" style="width: <?php echo $collection_rate; ?>%"></div>
            </div>
            <div class="stat-label mt-1">Collection Rate</div>
        </div>
    </div>
</div>

<!-- Financial Summary -->
<div class="app-card mb-4">
    <div class="card-header">
        <i class="fas fa-chart-bar text-primary me-2"></i>
        Financial Summary
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-md-3 border-end">
                <div class="text-muted small mb-1">Total Billed</div>
                <div class="fw-bold fs-4">₦<?php echo number_format($stats['total_amount'], 2); ?></div>
            </div>
            <div class="col-md-3 border-end">
                <div class="text-muted small mb-1">Total Paid</div>
                <div class="fw-bold fs-4 text-success">₦<?php echo number_format($stats['total_paid'], 2); ?></div>
            </div>
            <div class="col-md-3 border-end">
                <div class="text-muted small mb-1">Outstanding</div>
                <div class="fw-bold fs-4 text-danger">₦<?php echo number_format($stats['total_balance'], 2); ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Per Student Avg</div>
                <div class="fw-bold fs-4 text-info">
                    ₦<?php echo number_format($stats['total_records'] > 0 ? $stats['total_amount'] / $stats['total_records'] : 0, 2); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="app-card mb-4">
    <div class="card-header">
        <i class="fas fa-filter text-primary me-2"></i>Filters
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-lg-2 col-md-4">
                <input type="text" class="form-pro w-100" name="search" placeholder="Search student or invoice..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <select class="form-pro w-100" name="status">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo $status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Partial" <?php echo $status === 'Partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="Paid" <?php echo $status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="Overdue" <?php echo $status === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                    <option value="Waived" <?php echo $status === 'Waived' ? 'selected' : ''; ?>>Waived</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select class="form-pro w-100" name="fee_type">
                    <option value="">All Fee Types</option>
                    <?php foreach ($fee_types as $type): ?>
                    <option value="<?php echo htmlspecialchars($type['fee_type']); ?>" 
                            <?php echo $fee_type === $type['fee_type'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['fee_type']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select class="form-pro w-100" name="session_year">
                    <option value="">All Sessions</option>
                    <?php foreach ($session_years as $yr): ?>
                    <option value="<?php echo htmlspecialchars($yr['session_year']); ?>" 
                            <?php echo $session_year === $yr['session_year'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($yr['session_year']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select class="form-pro w-100" name="department_id">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['department_id']; ?>" 
                            <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['department_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 d-flex gap-2">
                <button type="submit" class="btn-pro btn-pro-primary flex-fill">
                    <i class="fas fa-search"></i>
                </button>
                <a href="manage_fees.php" class="btn-pro btn-pro-secondary">
                    <i class="fas fa-redo"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions & Table -->
<form method="POST" id="bulkForm">
    <div class="app-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <i class="fas fa-list text-primary me-2"></i>
                <span>Student Fees</span>
                <span class="badge-pro badge-pro-primary ms-2"><?php echo $total_records; ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <select class="form-pro" style="width: auto;" name="bulk_action" id="bulkAction" onchange="toggleBulkButton()">
                    <option value="">Bulk Actions</option>
                    <option value="mark_paid">✅ Mark as Paid</option>
                    <option value="mark_pending">⏳ Mark as Pending</option>
                    <option value="send_reminders">📧 Send Reminders</option>
                    <option value="export">📤 Export CSV</option>
                    <option value="delete" class="text-danger">🗑️ Delete Pending</option>
                </select>
                <button type="submit" class="btn-pro btn-pro-primary" id="applyBulkAction" disabled>
                    Apply
                </button>
                <span class="text-muted small" id="selectedCount">0 selected</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                            <th>Invoice</th>
                            <th>Student</th>
                            <th>Fee Type</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fees)): ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h5>No Fee Records Found</h5>
                                    <p>Try adjusting your filters or generate fees from the Fee Structures page</p>
                                    <a href="fees.php" class="btn-pro btn-pro-primary mt-2">
                                        <i class="fas fa-cogs me-2"></i>Generate Fees
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($fees as $fee): ?>
                        <tr>
                            <td><input type="checkbox" class="fee-checkbox form-check-input" name="selected_ids[]" value="<?php echo $fee['fee_id']; ?>"></td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($fee['invoice_number'] ?? 'N/A'); ?></div>
                                <div class="small text-muted"><?php echo date('M d, Y', strtotime($fee['created_date'])); ?></div>
                                <?php if ($fee['fee_structure_id']): ?>
                                    <span class="badge-pro badge-pro-secondary">Structured</span>
                                <?php else: ?>
                                    <span class="badge-pro badge-pro-warning">Manual</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-2">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 36px; height: 36px; font-size: 0.8rem; font-weight: 600;">
                                            <?php echo strtoupper(substr($fee['first_name'], 0, 1) . substr($fee['last_name'], 0, 1)); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($fee['first_name'] . ' ' . $fee['last_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($fee['matric_number']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($fee['department_name'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </td>
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
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars($fee['session_year'] ?? ''); ?> • Level <?php echo $fee['current_level']; ?></div>
                            </td>
                            <td class="text-end fw-semibold">₦<?php echo number_format((float)$fee['amount'], 2); ?></td>
                            <td class="text-end text-success">₦<?php echo number_format((float)$fee['amount_paid'], 2); ?></td>
                            <td class="text-end">
                                <span class="fw-bold <?php echo ($fee['balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                    ₦<?php echo number_format((float)($fee['balance'] ?? 0), 2); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($fee['due_date']): ?>
                                    <div><?php echo date('M d, Y', strtotime($fee['due_date'])); ?></div>
                                    <?php if (strtotime($fee['due_date']) < time() && ($fee['balance'] ?? 0) > 0): ?>
                                        <span class="badge-pro badge-pro-danger">Overdue</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $status_config = [
                                    'Paid' => ['success', 'fa-check'],
                                    'Partial' => ['warning', 'fa-hourglass-half'],
                                    'Pending' => ['secondary', 'fa-clock'],
                                    'Overdue' => ['danger', 'fa-exclamation'],
                                    'Waived' => ['info', 'fa-hand-holding-heart']
                                ][$fee['status']] ?? ['secondary', 'fa-circle'];
                                ?>
                                <span class="badge-pro badge-pro-<?php echo $status_config[0]; ?>">
                                    <i class="fas <?php echo $status_config[1]; ?> me-1"></i><?php echo $fee['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="view_invoice.php?id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-info" title="View Invoice">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_fee.php?id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-primary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="record_payment.php?fee_id=<?php echo $fee['fee_id']; ?>" class="btn btn-outline-success" title="Record Payment">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <div class="text-muted small">
                    Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> records
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.fee-checkbox').forEach(cb => cb.checked = this.checked);
    updateSelectionCount();
});

document.querySelectorAll('.fee-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectionCount);
});

function updateSelectionCount() {
    const selected = document.querySelectorAll('.fee-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selected + ' selected';
    toggleBulkButton();
}

function toggleBulkButton() {
    const selected = document.querySelectorAll('.fee-checkbox:checked').length;
    const action = document.getElementById('bulkAction').value;
    document.getElementById('applyBulkAction').disabled = selected === 0 || !action;
}

document.getElementById('bulkForm').addEventListener('submit', function(e) {
    const action = document.getElementById('bulkAction').value;
    const selected = document.querySelectorAll('.fee-checkbox:checked').length;

    if (action === 'delete' && selected > 0) {
        if (!confirm(`Delete ${selected} pending fee record(s)? Paid fees cannot be deleted.`)) {
            e.preventDefault();
        }
    }

    if (action === 'mark_paid' && selected > 0) {
        if (!confirm(`Mark ${selected} fee(s) as fully paid? This will set amount_paid = amount.`)) {
            e.preventDefault();
        }
    }
});

setTimeout(() => {
    document.querySelectorAll('.toast-pro').forEach(t => {
        t.style.opacity = '0';
        t.style.transform = 'translateX(20px)';
        t.style.transition = 'all 0.3s ease';
        setTimeout(() => t.remove(), 300);
    });
}, 5000);
</script>

<?php
require_once 'includes/footer.php';
?>