<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Get current session
$session_query = "SELECT session_year, semester, session_name 
                  FROM academic_sessions 
                  WHERE is_current = 1 AND status = 'Active' LIMIT 1";
$session_result = $conn->query($session_query);
$session_data = $session_result->fetch_assoc();

$current_session = $session_data['session_year'] ?? '2025/2026';
$current_semester = $session_data['semester'] ?? 1;
$session_name = $session_data['session_name'] ?? 'First Semester 2025/2026';

// Load student data if not already loaded
if (!isset($student) || empty($student)) {
    $student_query = "SELECT s.*, d.department_name, p.program_name, p.program_code
                      FROM students s
                      LEFT JOIN departments d ON s.department_id = d.department_id
                      LEFT JOIN programs p ON s.program_id = p.program_id
                      WHERE s.student_id = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Calculate CGPA
$cgpa = 0;
$all_results_query = "SELECT r.grade_points, c.credit_units 
                      FROM results r JOIN courses c ON r.course_id = c.course_id
                      WHERE r.student_id = ? AND r.is_published = 1";
$stmt = $conn->prepare($all_results_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$all_results = $stmt->get_result();

$total_all_units = 0;
$total_all_points = 0;
while ($row = $all_results->fetch_assoc()) {
    $total_all_units += $row['credit_units'];
    $total_all_points += ($row['grade_points'] ?? 0) * $row['credit_units'];
}
$cgpa = $total_all_units > 0 ? $total_all_points / $total_all_units : 0;

// Current semester results
$current_results_query = "SELECT r.grade_points, c.credit_units, r.grade 
                          FROM results r JOIN courses c ON r.course_id = c.course_id
                          WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1";
$stmt = $conn->prepare($current_results_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$current_results = $stmt->get_result();

$sem_units = 0;
$sem_points = 0;
$courses_passed = 0;
$courses_failed = 0;
while ($row = $current_results->fetch_assoc()) {
    $sem_units += $row['credit_units'];
    $sem_points += ($row['grade_points'] ?? 0) * $row['credit_units'];
    if ($row['grade'] == 'F') $courses_failed++;
    else $courses_passed++;
}
$semester_gpa = $sem_units > 0 ? $sem_points / $sem_units : 0;

// Registered courses count
$course_query = "SELECT COUNT(*) as total, SUM(c.credit_units) as units
                 FROM course_registrations cr JOIN courses c ON cr.course_id = c.course_id
                 WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ? AND cr.registration_status = 'Approved'";
$stmt = $conn->prepare($course_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$course_data = $stmt->get_result()->fetch_assoc();
$course_count = $course_data['total'] ?? 0;
$total_units = $course_data['units'] ?? 0;

// Carry-over courses (failed in previous sessions)
$carryover_query = "SELECT COUNT(*) as carryover 
                    FROM results r 
                    WHERE r.student_id = ? AND r.grade = 'F' AND r.session_year != ?";
$stmt = $conn->prepare($carryover_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$carryover_data = $stmt->get_result()->fetch_assoc();
$carryover_count = $carryover_data['carryover'] ?? 0;

// Fee status
$fee_query = "SELECT status, amount, amount_paid FROM student_fees 
              WHERE student_id = ? AND session_year = ? AND semester = ? ORDER BY fee_id DESC LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$fee = $stmt->get_result()->fetch_assoc();

// Recent payments
$payments_query = "SELECT p.amount, p.status, p.payment_date, sf.fee_type 
                   FROM payments p LEFT JOIN student_fees sf ON p.fee_id = sf.fee_id
                   WHERE p.student_id = ? ORDER BY p.payment_date DESC LIMIT 3";
$stmt = $conn->prepare($payments_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$payments = $stmt->get_result();

$semester_label = ($current_semester == 1) ? 'First Semester' : 'Second Semester';
?>

<div class="dashboard-simple">
    <!-- Welcome Header -->
    <div class="welcome-bar">
        <h2>Hi <?php echo htmlspecialchars($student['first_name'] ?? 'Student'); ?>,</h2>
        <p>Welcome to your dashboard</p>
    </div>

    <!-- Overview Section -->
    <div class="overview-section">
        <h3 class="section-title">Overview</h3>
        <div class="stats-grid">
            <!-- Main Blue Card -->
            <div class="stat-card main-card">
                <div class="main-card-header">
                    <div class="icon-box">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="main-card-info">
                        <span class="card-label">Registered Courses</span>
                        <span class="card-value"><?php echo $course_count; ?></span>
                    </div>
                </div>
                <div class="main-card-breakdown">
                    <div class="breakdown-item">
                        <span class="breakdown-label">Regular</span>
                        <span class="breakdown-value regular"><?php echo $course_count; ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Carry-over</span>
                        <span class="breakdown-value carryover"><?php echo $carryover_count; ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span class="breakdown-label">Arrears</span>
                        <span class="breakdown-value arrears">0</span>
                    </div>
                </div>
                <a href="courses.php" class="view-more">
                    View More Details <i class="fas fa-chevron-right"></i>
                </a>
            </div>

            <!-- Level Card -->
            <div class="stat-card info-card">
                <div class="info-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="info-label">Level</div>
                <div class="info-value"><?php echo $student['current_level'] ?? 100; ?></div>
            </div>

            <!-- Semester Card -->
            <div class="stat-card info-card">
                <div class="info-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="info-label">Semester</div>
                <div class="info-value"><?php echo htmlspecialchars($semester_label); ?></div>
            </div>

            <!-- CGPA Card -->
            <div class="stat-card info-card">
                <div class="info-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="info-label">CGPA</div>
                <div class="info-value"><?php echo number_format($cgpa, 1); ?></div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="quick-links-section">
        <h3 class="section-title">Quick Links</h3>
        <div class="links-list">
            <a href="transactions.php" class="link-row">
                <span class="link-text">Payments history</span>
                <i class="fas fa-chevron-right"></i>
            </a>
            <a href="courses.php" class="link-row">
                <span class="link-text">Courses</span>
                <i class="fas fa-chevron-right"></i>
            </a>
            <a href="result.php" class="link-row">
                <span class="link-text">Results</span>
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
    </div>

    <!-- Recent Payments (hidden by default, shown if data exists) -->
    <?php if ($payments->num_rows > 0): ?>
    <div class="payments-section">
        <h3 class="section-title">Recent Payments</h3>
        <div class="payments-list">
            <?php while ($p = $payments->fetch_assoc()): ?>
            <div class="payment-item">
                <div class="payment-info">
                    <span class="payment-title"><?php echo htmlspecialchars($p['fee_type'] ?? 'Payment'); ?></span>
                    <span class="payment-date"><?php echo date('d M Y', strtotime($p['payment_date'])); ?></span>
                </div>
                <div class="payment-amount">
                    ₦<?php echo number_format($p['amount']); ?>
                    <span class="payment-status <?php echo strtolower($p['status']); ?>"><?php echo $p['status']; ?></span>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* ===== SIMPLIFIED DASHBOARD STYLES ===== */
.dashboard-simple {
    max-width: 1000px;
    margin: 0 auto;
    padding: 24px 16px;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

/* Welcome Bar */
.welcome-bar {
    background: #f1f5f9;
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 28px;
}
.welcome-bar h2 {
    margin: 0 0 4px 0;
    font-size: 20px;
    font-weight: 600;
    color: #1e293b;
}
.welcome-bar p {
    margin: 0;
    font-size: 14px;
    color: #64748b;
}

/* Section Titles */
.section-title {
    font-size: 14px;
    font-weight: 600;
    color: #334155;
    margin: 0 0 14px 0;
    text-transform: none;
    letter-spacing: 0;
}

/* Overview Section */
.overview-section {
    margin-bottom: 28px;

}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 14px;
}

/* Main Blue Card */
.main-card {
    background: #2a5a7a;
    color: white;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.main-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}
.icon-box {
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.15);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
}
.main-card-info {
    display: flex;
    flex-direction: column;
}
.card-label {
    font-size: 13px;
    opacity: 0.85;
    margin-bottom: 2px;
}
.card-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
}

/* Breakdown */
.main-card-breakdown {
    display: flex;
    justify-content: space-between;
    padding: 14px 0;
    border-top: 1px solid rgba(255,255,255,0.15);
    border-bottom: 1px solid rgba(255,255,255,0.15);
    margin-bottom: 12px;
}
.breakdown-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}
.breakdown-label {
    font-size: 12px;
    opacity: 0.75;
    margin-bottom: 4px;
}
.breakdown-value {
    font-size: 18px;
    font-weight: 700;
}
.breakdown-value.regular { color: #4ade80; }
.breakdown-value.carryover { color: #fb923c; }
.breakdown-value.arrears { color: #f87171; }

/* View More */
.view-more {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    color: white;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    opacity: 0.9;
    transition: opacity 0.2s;
}
.view-more:hover { opacity: 1; }
.view-more i { font-size: 11px; }

/* Info Cards (Level, Semester, CGPA) */
.info-card {
    background: #e8f4fd;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 8px;
}
.info-icon {
    width: 36px;
    height: 36px;
    background: #2a5a7a;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 15px;
}
.info-label {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
}
.info-value {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
}

/* Quick Links */
.quick-links-section {
    margin-bottom: 28px;
}
.links-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.link-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 20px;
    text-decoration: none;
    transition: all 0.2s;
}
.link-row:hover {
    border-color: #2a5a7a;
    box-shadow: 0 2px 8px #2a5a7a33;
}
.link-text {
    font-size: 15px;
    font-weight: 600;
    color: #2a5a7a;
}
.link-row i {
    color: #94a3b8;
    font-size: 13px;
}

/* Payments Section */
.payments-section {
    margin-bottom: 28px;
}
.payments-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.payment-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 18px;
}
.payment-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.payment-title {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}
.payment-date {
    font-size: 12px;
    color: #94a3b8;
}
.payment-amount {
    text-align: right;
    font-size: 15px;
    font-weight: 700;
    color: #2a5a7a;
}
.payment-status {
    display: block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    margin-top: 4px;
}
.payment-status.verified, .payment-status.paid {
    background: #dcfce7;
    color: #16a34a;
}
.payment-status.pending {
    background: #fef3c7;
    color: #d97706;
}
.payment-status.failed {
    background: #fee2e2;
    color: #dc2626;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    .main-card {
        grid-column: span 2;
    }
}
@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .main-card {
        grid-column: span 1;
    }
    .dashboard-simple {
        padding: 16px 12px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>