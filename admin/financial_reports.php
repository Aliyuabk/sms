<?php
// financial_reports.php - Financial Reports (FIXED - No ambiguous columns)
ob_start();
require_once 'includes/header.php';
$page_title = "Financial Reports";

// Database connection
if (!isset($pdo)) {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Get filter data
$departments = $pdo->query("SELECT department_id, department_code, department_name FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$fee_types = $pdo->query("SELECT DISTINCT fee_type FROM fee_structure ORDER BY fee_type")->fetchAll();
$sessions = $pdo->query("SELECT DISTINCT session_year FROM student_fees ORDER BY session_year DESC")->fetchAll();

// Get filters
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$session_year = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$fee_type = isset($_GET['fee_type']) ? $_GET['fee_type'] : '';
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'fee_summary';

// Build filters with proper table aliases
$filters = []; 
$params = [];

if ($department_id > 0) { 
    $filters[] = "s.department_id = ?"; 
    $params[] = $department_id; 
}
if ($program_id > 0) { 
    $filters[] = "s.program_id = ?"; 
    $params[] = $program_id; 
}
if (!empty($session_year)) { 
    $filters[] = "sf.session_year = ?"; 
    $params[] = $session_year; 
}
if (!empty($fee_type)) { 
    $filters[] = "sf.fee_type = ?"; 
    $params[] = $fee_type; 
}
if (!empty($payment_status)) { 
    $filters[] = "sf.status = ?"; 
    $params[] = $payment_status; 
}

$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

// Get report data
$summary = []; 
$reports = [];

if ($report_type == 'fee_summary') {
    $sql = "
        SELECT 
            sf.session_year, 
            sf.fee_type, 
            COUNT(DISTINCT sf.student_id) as student_count, 
            SUM(sf.amount) as total_fees, 
            SUM(sf.amount_paid) as total_paid, 
            SUM(sf.balance) as total_balance, 
            AVG(sf.amount_paid) as avg_paid 
        FROM student_fees sf 
        JOIN students s ON sf.student_id = s.student_id 
        LEFT JOIN departments d ON s.department_id = d.department_id 
        $where_clause 
        GROUP BY sf.session_year, sf.fee_type 
        ORDER BY sf.session_year DESC
    ";
    $stmt = $pdo->prepare($sql); 
    $stmt->execute($params); 
    $reports = $stmt->fetchAll();
    
    $summary_sql = "
        SELECT 
            SUM(sf.amount) as total_fees, 
            SUM(sf.amount_paid) as total_paid, 
            SUM(sf.balance) as total_balance, 
            COUNT(DISTINCT sf.student_id) as students 
        FROM student_fees sf 
        JOIN students s ON sf.student_id = s.student_id 
        $where_clause
    ";
    $summary_stmt = $pdo->prepare($summary_sql); 
    $summary_stmt->execute($params); 
    $summary = $summary_stmt->fetch();
} else {
    $sql = "
        SELECT 
            sf.fee_id,
            sf.student_id,
            sf.session_year,
            sf.fee_type,
            sf.amount,
            sf.amount_paid,
            sf.balance,
            sf.status,
            sf.due_date,
            sf.invoice_number,
            sf.created_date,
            s.matric_number, 
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name, 
            d.department_name, 
            d.department_code,
            p.program_name, 
            p.program_code
        FROM student_fees sf 
        JOIN students s ON sf.student_id = s.student_id 
        LEFT JOIN departments d ON s.department_id = d.department_id 
        LEFT JOIN programs p ON s.program_id = p.program_id 
        $where_clause 
        ORDER BY sf.due_date ASC 
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql); 
    $stmt->execute($params); 
    $reports = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-section { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .stat-card { transition: transform 0.2s; border-radius: 12px; }
        .stat-card:hover { transform: translateY(-3px); }
        .report-table th { background-color: #f8f9fa; font-weight: 600; }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; border: none; }
        .nav-tabs .nav-link.active { color: #4361ee; border-bottom: 2px solid #4361ee; background: transparent; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="app-page-title mb-1"><i class="fas fa-chart-line me-2 text-primary"></i>Financial Reports</h1>
            <p class="text-muted mb-0">Fee collection analysis, payment tracking and financial summaries</p>
        </div>
        <div>
            <button class="btn btn-success" onclick="exportReport()"><i class="fas fa-file-excel me-2"></i>Export Report</button>
            <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $report_type == 'fee_summary' ? 'active' : ''; ?>" href="?report_type=fee_summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'fee_summary'])); ?>">
                <i class="fas fa-chart-bar me-2"></i>Fee Summary
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $report_type == 'payment_details' ? 'active' : ''; ?>" href="?report_type=payment_details&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'payment_details'])); ?>">
                <i class="fas fa-list me-2"></i>Payment Details
            </a>
        </li>
    </ul>
    
    <!-- Filters Section -->
    <div class="filter-section">
        <h6 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Reports</h6>
        <form method="GET" class="row g-3" id="filterForm">
            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
            
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department_id" onchange="this.form.submit()">
                    <option value="0">All Departments</option>
                    <?php foreach($departments as $d): ?>
                    <option value="<?php echo $d['department_id']; ?>" <?php echo $department_id == $d['department_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($d['department_code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id" onchange="this.form.submit()">
                    <option value="0">All Programs</option>
                    <?php foreach($programs as $p): ?>
                    <option value="<?php echo $p['program_id']; ?>" <?php echo $program_id == $p['program_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['program_code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Session</label>
                <select class="form-select" name="session_year" onchange="this.form.submit()">
                    <option value="">All Sessions</option>
                    <?php foreach($sessions as $s): ?>
                    <option value="<?php echo $s['session_year']; ?>" <?php echo $session_year == $s['session_year'] ? 'selected' : ''; ?>>
                        <?php echo $s['session_year']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Fee Type</label>
                <select class="form-select" name="fee_type" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php foreach($fee_types as $f): ?>
                    <option value="<?php echo $f['fee_type']; ?>" <?php echo $fee_type == $f['fee_type'] ? 'selected' : ''; ?>>
                        <?php echo $f['fee_type']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Payment Status</label>
                <select class="form-select" name="payment_status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="Paid" <?php echo $payment_status == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="Pending" <?php echo $payment_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Partial" <?php echo $payment_status == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="Overdue" <?php echo $payment_status == 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <a href="financial_reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-outline-secondary w-100">Reset Filters</a>
            </div>
        </form>
    </div>
    
    <!-- Statistics Summary Cards -->
    <?php if($report_type == 'fee_summary' && !empty($summary) && isset($summary['total_fees'])): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white stat-card">
                <div class="card-body">
                    <h6 class="card-title">Total Fees</h6>
                    <h2 class="mb-0">₦<?php echo number_format($summary['total_fees'] ?? 0, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white stat-card">
                <div class="card-body">
                    <h6 class="card-title">Total Paid</h6>
                    <h2 class="mb-0">₦<?php echo number_format($summary['total_paid'] ?? 0, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white stat-card">
                <div class="card-body">
                    <h6 class="card-title">Total Balance</h6>
                    <h2 class="mb-0">₦<?php echo number_format($summary['total_balance'] ?? 0, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white stat-card">
                <div class="card-body">
                    <h6 class="card-title">Total Students</h6>
                    <h2 class="mb-0"><?php echo number_format($summary['students'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Collection Rate Chart -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5><i class="fas fa-chart-pie me-2"></i>Collection Rate</h5>
                    <canvas id="collectionChart" height="80"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Reports Table -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 report-table">
                    <?php if($report_type == 'fee_summary'): ?>
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Fee Type</th>
                            <th class="text-center">Students</th>
                            <th class="text-end">Total Fees (₦)</th>
                            <th class="text-end">Total Paid (₦)</th>
                            <th class="text-end">Balance (₦)</th>
                            <th class="text-center">Collection Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): ?>
                        <?php $rate = $r['total_fees'] > 0 ? ($r['total_paid'] / $r['total_fees']) * 100 : 0; ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['session_year']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['fee_type']); ?></td>
                            <td class="text-center"><?php echo number_format($r['student_count']); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['total_fees'], 2); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['total_paid'], 2); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['total_balance'], 2); ?></td>
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $rate; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($rate, 1); ?>%</small>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($reports)): ?>
                        <tr><td colspan="7" class="text-center py-5">No financial data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php else: ?>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Student</th>
                            <th>Department/Program</th>
                            <th>Fee Type</th>
                            <th>Session</th>
                            <th class="text-end">Amount (₦)</th>
                            <th class="text-end">Paid (₦)</th>
                            <th class="text-end">Balance (₦)</th>
                            <th>Status</th>
                            <th>Due Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): ?>
                        <?php 
                        $status_class = match($r['status']) {
                            'Paid' => 'success',
                            'Partial' => 'warning',
                            'Overdue' => 'danger',
                            default => 'secondary'
                        };
                        ?>
                        <tr>
                            <td><small><?php echo htmlspecialchars($r['invoice_number'] ?? 'N/A'); ?></small></td>
                            <td>
                                <strong><?php echo htmlspecialchars($r['matric_number']); ?></strong><br>
                                <small><?php echo htmlspecialchars($r['student_name']); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($r['department_code'] ?? 'N/A'); ?></small><br>
                                <small class="text-muted"><?php echo htmlspecialchars($r['program_code'] ?? 'N/A'); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($r['fee_type']); ?></td>
                            <td><?php echo htmlspecialchars($r['session_year']); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['amount'], 2); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['amount_paid'] ?? 0, 2); ?></td>
                            <td class="text-end">₦<?php echo number_format($r['balance'] ?? 0, 2); ?></td>
                            <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo $r['status']; ?></span></td>
                            <td><?php echo date('d/m/Y', strtotime($r['due_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($reports)): ?>
                        <tr><td colspan="10" class="text-center py-5">No payment records found</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Summary Footer -->
    <?php if(!empty($reports) && $report_type == 'payment_details'): ?>
    <div class="mt-3 text-end">
        <small class="text-muted">
            Showing <?php echo count($reports); ?> records | 
            Total Amount: ₦<?php echo number_format(array_sum(array_column($reports, 'amount')), 2); ?> |
            Total Paid: ₦<?php echo number_format(array_sum(array_column($reports, 'amount_paid')), 2); ?> |
            Total Balance: ₦<?php echo number_format(array_sum(array_column($reports, 'balance')), 2); ?>
        </small>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function exportReport() {
    window.location.href = 'export_financial_report.php?' + new URLSearchParams(window.location.search).toString();
}

<?php if($report_type == 'fee_summary' && !empty($summary) && isset($summary['total_fees'])): ?>
// Collection Rate Chart
const totalFees = <?php echo $summary['total_fees'] ?? 0; ?>;
const totalPaid = <?php echo $summary['total_paid'] ?? 0; ?>;
const totalBalance = <?php echo $summary['total_balance'] ?? 0; ?>;

new Chart(document.getElementById('collectionChart'), {
    type: 'doughnut',
    data: {
        labels: ['Paid (₦' + totalPaid.toLocaleString() + ')', 'Outstanding (₦' + totalBalance.toLocaleString() + ')'],
        datasets: [{
            data: [totalPaid, totalBalance],
            backgroundColor: ['#28a745', '#dc3545'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
<?php endif; ?>

// Auto-submit form on select change
document.querySelectorAll('.filter-section select').forEach(select => {
    select.addEventListener('change', () => {
        document.getElementById('filterForm').submit();
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>