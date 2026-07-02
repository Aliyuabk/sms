<?php
require_once 'includes/header.php';

$page_title = "Payment Reports";

// Get date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$department_id = $_GET['department_id'] ?? '';
$fee_type = $_GET['fee_type'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';

// Build WHERE clause
$where_conditions = ["p.status = 'Verified'"];
$params = [];

if (!empty($start_date)) {
    $where_conditions[] = "DATE(p.payment_date) >= ?";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $where_conditions[] = "DATE(p.payment_date) <= ?";
    $params[] = $end_date;
}

if (!empty($department_id)) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if (!empty($fee_type)) {
    $where_conditions[] = "sf.fee_type = ?";
    $params[] = $fee_type;
}

if (!empty($payment_method)) {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $payment_method;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get payment summary
$summary_sql = "
    SELECT 
        COUNT(p.payment_id) as total_transactions,
        SUM(p.amount) as total_amount,
        AVG(p.amount) as avg_amount,
        MIN(p.amount) as min_amount,
        MAX(p.amount) as max_amount
    FROM payments p
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    LEFT JOIN students s ON p.student_id = s.student_id
    $where_sql
";

$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch();

// Get daily payments
$daily_sql = "
    SELECT 
        DATE(p.payment_date) as payment_day,
        COUNT(p.payment_id) as transaction_count,
        SUM(p.amount) as daily_total,
        AVG(p.amount) as daily_avg
    FROM payments p
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    LEFT JOIN students s ON p.student_id = s.student_id
    $where_sql
    GROUP BY DATE(p.payment_date)
    ORDER BY payment_day DESC
    LIMIT 30
";

$daily_stmt = $pdo->prepare($daily_sql);
$daily_stmt->execute($params);
$daily_payments = $daily_stmt->fetchAll();

// Get payments by method
$method_sql = "
    SELECT 
        p.payment_method,
        COUNT(p.payment_id) as transaction_count,
        SUM(p.amount) as total_amount,
        AVG(p.amount) as avg_amount
    FROM payments p
    LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
    LEFT JOIN students s ON p.student_id = s.student_id
    $where_sql
    GROUP BY p.payment_method
    ORDER BY total_amount DESC
";

$method_stmt = $pdo->prepare($method_sql);
$method_stmt->execute($params);
$method_summary = $method_stmt->fetchAll();

// Get payments by department
$dept_sql = "
    SELECT 
        d.department_name,
        COUNT(p.payment_id) as transaction_count,
        SUM(p.amount) as total_amount,
        AVG(p.amount) as avg_amount
    FROM payments p
    JOIN students s ON p.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    $where_sql
    GROUP BY d.department_id
    ORDER BY total_amount DESC
";

$dept_stmt = $pdo->prepare($dept_sql);
$dept_stmt->execute($params);
$dept_summary = $dept_stmt->fetchAll();

// Get filter options
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$fee_types = $pdo->query("SELECT DISTINCT fee_type FROM student_fees WHERE fee_type IS NOT NULL ORDER BY fee_type")->fetchAll();
$payment_methods = $pdo->query("SELECT DISTINCT payment_method FROM payments WHERE payment_method IS NOT NULL ORDER BY payment_method")->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Payment Reports</h1>
            <p class="text-muted">View detailed payment analytics and reports</p>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Report Filters</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-2">
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
                    <div class="col-md-2">
                        <label class="form-label">Fee Type</label>
                        <select class="form-select" name="fee_type">
                            <option value="">All Fee Types</option>
                            <?php foreach ($fee_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['fee_type']); ?>" 
                                    <?php echo $fee_type === $type['fee_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['fee_type']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Payment Method</label>
                        <select class="form-select" name="payment_method">
                            <option value="">All Methods</option>
                            <?php foreach ($payment_methods as $method): ?>
                            <option value="<?php echo htmlspecialchars($method['payment_method']); ?>" 
                                    <?php echo $payment_method === $method['payment_method'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($method['payment_method']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 mt-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="payment_reports.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                            <button type="button" class="btn btn-outline-success" onclick="exportReport()">
                                <i class="fas fa-file-excel me-2"></i>Export to Excel
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="printReport()">
                                <i class="fas fa-print me-2"></i>Print Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h6 class="stats-type">Total Payments</h6>
                <div class="stats-figure">₦<?php echo number_format($summary['total_amount'] ?? 0, 2); ?></div>
                <p class="stats-detail mb-0"><?php echo $summary['total_transactions'] ?? 0; ?> transactions</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h6 class="stats-type">Average Payment</h6>
                <div class="stats-figure">₦<?php echo number_format($summary['avg_amount'] ?? 0, 2); ?></div>
                <p class="stats-detail mb-0">Per transaction</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h6 class="stats-type">Minimum Payment</h6>
                <div class="stats-figure">₦<?php echo number_format($summary['min_amount'] ?? 0, 2); ?></div>
                <p class="stats-detail mb-0">Smallest transaction</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h6 class="stats-type">Maximum Payment</h6>
                <div class="stats-figure">₦<?php echo number_format($summary['max_amount'] ?? 0, 2); ?></div>
                <p class="stats-detail mb-0">Largest transaction</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Daily Payments Chart -->
    <div class="col-md-8 mb-4">
        <div class="app-card app-card-settings shadow-sm h-100">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Daily Payment Trends</h6>
            </div>
            <div class="app-card-body p-3">
                <canvas id="dailyPaymentsChart" height="250"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods -->
    <div class="col-md-4 mb-4">
        <div class="app-card app-card-settings shadow-sm h-100">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Payment Methods</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Transactions</th>
                                <th>Amount</th>
                                <th>Avg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($method_summary as $method): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($method['payment_method']); ?></td>
                                <td><?php echo $method['transaction_count']; ?></td>
                                <td>₦<?php echo number_format($method['total_amount'], 2); ?></td>
                                <td>₦<?php echo number_format($method['avg_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Department Breakdown -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Payments by Department</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Average Payment</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_summary as $dept): 
                                $percentage = $summary['total_amount'] > 0 ? 
                                    ($dept['total_amount'] / $summary['total_amount']) * 100 : 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept['department_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo $dept['transaction_count']; ?></td>
                                <td>₦<?php echo number_format($dept['total_amount'], 2); ?></td>
                                <td>₦<?php echo number_format($dept['avg_amount'], 2); ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <span><?php echo number_format($percentage, 1); ?>%</span>
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
</div>

<!-- Daily Payments Table -->
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Daily Payment Details</h6>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transactions</th>
                                <th>Daily Total</th>
                                <th>Average Payment</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_payments as $day): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($day['payment_day'])); ?></td>
                                <td><?php echo $day['transaction_count']; ?></td>
                                <td>₦<?php echo number_format($day['daily_total'], 2); ?></td>
                                <td>₦<?php echo number_format($day['daily_avg'], 2); ?></td>
                                <td>
                                    <?php if ($day['daily_total'] > 0): ?>
                                        <span class="text-success">
                                            <i class="fas fa-arrow-up"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">
                                            <i class="fas fa-minus"></i> No payments
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Daily Payments Chart
const dailyPaymentsChart = new Chart(document.getElementById('dailyPaymentsChart'), {
    type: 'line',
    data: {
        labels: [
            <?php 
            $labels = [];
            foreach ($daily_payments as $day) {
                $labels[] = "'" . date('M d', strtotime($day['payment_day'])) . "'";
            }
            echo implode(', ', array_reverse($labels));
            ?>
        ].reverse(),
        datasets: [{
            label: 'Daily Payments (₦)',
            data: [
                <?php 
                $data = [];
                foreach ($daily_payments as $day) {
                    $data[] = $day['daily_total'];
                }
                echo implode(', ', array_reverse($data));
                ?>
            ].reverse(),
            borderColor: '#4361ee',
            backgroundColor: 'rgba(67, 97, 238, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₦' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Export function
function exportReport() {
    alert('Export feature will generate an Excel file with the current report data.');
    // In a real implementation, this would make an AJAX call to generate an Excel file
}

// Print function
function printReport() {
    window.print();
}

// Initialize date fields
document.addEventListener('DOMContentLoaded', function() {
    // Set default date range to current month if not set
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    
    if (!startDateInput.value) {
        const firstDay = new Date();
        firstDay.setDate(1);
        startDateInput.value = firstDay.toISOString().split('T')[0];
    }
    
    if (!endDateInput.value) {
        const lastDay = new Date();
        lastDay.setMonth(lastDay.getMonth() + 1);
        lastDay.setDate(0);
        endDateInput.value = lastDay.toISOString().split('T')[0];
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>