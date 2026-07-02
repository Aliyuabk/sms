<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Get filter parameters
$session_filter = isset($_GET['session']) ? $_GET['session'] : 'all';
$semester_filter = isset($_GET['semester']) ? $_GET['semester'] : 'all';

// Build query
$query = "SELECT p.*, sf.description as fee_description, sf.semester 
          FROM payments p 
          LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id 
          WHERE p.student_id = ?";
$params = [$student_id];
$types = "i";

if ($session_filter != 'all') {
    $query .= " AND sf.session_year = ?";
    $params[] = $session_filter;
    $types .= "s";
}

if ($semester_filter != 'all') {
    $query .= " AND sf.semester = ?";
    $params[] = $semester_filter;
    $types .= "i";
}

$query .= " ORDER BY p.payment_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Get available sessions for filter
$sessions_query = "SELECT DISTINCT session_year FROM student_fees WHERE student_id = ? ORDER BY session_year DESC";
$stmt = $conn->prepare($sessions_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$sessions = $stmt->get_result();

// Get student matric for display
$student_query = "SELECT matric_number FROM students WHERE student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_data = $stmt->get_result()->fetch_assoc();
$matric = $student_data['matric_number'] ?? '';
?>

<div class="tx-page">
    <h1 class="tx-title">Transaction History</h1>

    <!-- Filters -->
    <div class="tx-filters">
        <div class="tx-filter-group">
            <label>Session</label>
            <select class="tx-select" id="sessionFilter" onchange="applyFilters()">
                <option value="all">Select...</option>
                <?php while ($session = $sessions->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($session['session_year']); ?>" 
                    <?php echo $session_filter == $session['session_year'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($session['session_year']); ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="tx-filter-group">
            <label>Semester</label>
            <select class="tx-select" id="semesterFilter" onchange="applyFilters()">
                <option value="all" <?php echo $semester_filter == 'all' ? 'selected' : ''; ?>>Select...</option>
                <option value="1" <?php echo $semester_filter == '1' ? 'selected' : ''; ?>>First Semester</option>
                <option value="2" <?php echo $semester_filter == '2' ? 'selected' : ''; ?>>Second Semester</option>
            </select>
        </div>
    </div>

    <!-- Requery Link -->
    <div class="tx-requery">
        <span class="requery-text">Payment Still Pending?</span>
        <button class="requery-btn" onclick="requeryPayment()">Requery Payment Status</button>
    </div>

    <!-- Table -->
    <div class="tx-table-wrap">
        <table class="tx-table">
            <thead>
                <tr>
                    <th>Transaction Ref</th>
                    <th>Remita Retrieval Reference</th>
                    <th>Student ID</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions->num_rows > 0): ?>
                    <?php while ($tx = $transactions->fetch_assoc()): 
                        $status_class = 'pending';
                        $status_text = 'Pending';
                        if ($tx['status'] == 'Verified') {
                            $status_class = 'success';
                            $status_text = 'Success';
                        } elseif ($tx['status'] == 'Failed') {
                            $status_class = 'failed';
                            $status_text = 'Failed';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tx['transaction_id'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($tx['receipt_number'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($matric); ?></td>
                        <td><?php echo date('d M Y', strtotime($tx['payment_date'])); ?></td>
                        <td class="tx-amount">₦<?php echo number_format($tx['amount']); ?></td>
                        <td><?php echo htmlspecialchars($tx['fee_description'] ?? 'Tuition Fees'); ?></td>
                        <td><span class="tx-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="tx-empty">No transactions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($transactions->num_rows > 0): ?>
    <div class="tx-pagination">
        <span class="tx-page-info">Displaying 1 - <?php echo $transactions->num_rows; ?> out of <?php echo $transactions->num_rows; ?></span>
        <div class="tx-page-btns">
            <button class="tx-page-btn" disabled><i class="fas fa-chevron-left"></i></button>
            <button class="tx-page-btn active">1</button>
            <button class="tx-page-btn" disabled><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* ===== SIMPLIFIED TRANSACTION HISTORY ===== */
.tx-page {
    max-width: 1100px;
    margin: 0 auto;
    padding: 30px 20px;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.tx-title {
    font-size: 20px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 28px 0;
}

/* Filters */
.tx-filters {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    max-width: 600px;
    margin-bottom: 20px;
}

.tx-filter-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 8px;
}

.tx-select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    color: #374151;
    background: #fff;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
}

.tx-select:focus {
    outline: none;
    border-color: #1a4db5;
}

/* Requery */
.tx-requery {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-bottom: 24px;
}

.requery-text {
    font-size: 14px;
    font-weight: 600;
    color: #1a4db5;
}

.requery-btn {
    padding: 10px 18px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: #fff;
    color: #374151;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.requery-btn:hover {
    border-color: #1a4db5;
    color: #1a4db5;
}

/* Table */
.tx-table-wrap {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 24px;
}

.tx-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.tx-table thead {
    background: #f9fafb;
}

.tx-table th {
    padding: 14px 18px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
    border-bottom: 1px solid #e5e7eb;
}

.tx-table td {
    padding: 16px 18px;
    color: #4b5563;
    border-bottom: 1px solid #f3f4f6;
}

.tx-table tbody tr:last-child td {
    border-bottom: none;
}

.tx-table tbody tr:hover {
    background: #fafafa;
}

.tx-amount {
    font-weight: 600;
    color: #1e293b;
}

/* Status */
.tx-status {
    font-size: 13px;
    font-weight: 600;
}

.tx-status.success {
    color: #10b981;
}

.tx-status.pending {
    color: #f59e0b;
}

.tx-status.failed {
    color: #ef4444;
}

/* Empty */
.tx-empty {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
}

/* Pagination */
.tx-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
}

.tx-page-info {
    font-size: 13px;
    color: #6b7280;
}

.tx-page-btns {
    display: flex;
    gap: 6px;
}

.tx-page-btn {
    width: 36px;
    height: 36px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: #fff;
    color: #6b7280;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.tx-page-btn:hover:not(:disabled) {
    border-color: #1a4db5;
    color: #1a4db5;
}

.tx-page-btn.active {
    background: #1a4db5;
    border-color: #1a4db5;
    color: #fff;
}

.tx-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 768px) {
    .tx-filters {
        grid-template-columns: 1fr;
    }
    .tx-table-wrap {
        overflow-x: auto;
    }
    .tx-table {
        min-width: 800px;
    }
    .tx-requery {
        justify-content: flex-start;
    }
    .tx-pagination {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script>
function applyFilters() {
    const session = document.getElementById('sessionFilter').value;
    const semester = document.getElementById('semesterFilter').value;
    let url = 'transactions.php?';
    if (session !== 'all') url += 'session=' + encodeURIComponent(session) + '&';
    if (semester !== 'all') url += 'semester=' + encodeURIComponent(semester);
    window.location.href = url;
}

function requeryPayment() {
    alert('Requerying payment status... Please wait.');
    // Implement requery logic here
}
</script>

<?php require_once 'includes/footer.php'; ?>