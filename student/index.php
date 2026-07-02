<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Get current session
$session_query = "SELECT session_year, semester, session_name 
                  FROM academic_sessions 
                  WHERE is_current = 1 AND status = 'Active' LIMIT 1";
$session_result = $conn->query($session_query);
$session_data = $session_result->fetch_assoc();

$current_session = $session_data['session_year'] ?? '';
$current_semester = $session_data['semester'] ?? 1;
$session_name = $session_data['session_name'] ?? '';

// Load student data
if (!isset($student) || empty($student)) {
    $student_query = "SELECT s.*, d.department_name, p.program_name 
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

// Check fee status
$fee_query = "SELECT * FROM student_fees 
              WHERE student_id = ? AND session_year = ?
              ORDER BY fee_id DESC LIMIT 1";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$fee_status = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check approved course registrations
$course_query = "SELECT COUNT(*) as total FROM course_registrations 
                 WHERE student_id = ? AND session_year = ? AND semester = ? AND registration_status = 'Approved'";
$stmt = $conn->prepare($course_query);
$stmt->bind_param("isi", $student_id, $current_session, $current_semester);
$stmt->execute();
$course_reg = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate steps
$steps_completed = 0;
$total_steps = 2;
if ($fee_status && $fee_status['status'] == 'Paid') $steps_completed++;
if ($course_reg['total'] > 0) $steps_completed++;
?>

<div class="home-container">

    <!-- Session Badge -->
    

    <div class="main-grid">

        <!-- Left: Student Profile Card -->
        <div class="profile-card">
            <div class="profile-photo">
                <?php if (!empty($student['photo']) && file_exists($student['photo'])): ?>
                    <img src="<?php echo htmlspecialchars($student['photo']); ?>" alt="Profile">
                <?php else: ?>
                    <div class="photo-placeholder">
                        <?php echo strtoupper(substr($student['first_name'] ?? 'S', 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <h2 class="student-name"><?php echo strtoupper(htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></h2>
            <p class="matric-no"><?php echo htmlspecialchars($student['matric_number'] ?? ''); ?></p>
            <p class="program-info">
                <?php echo htmlspecialchars($student['student_type'] ?? 'Full Time'); ?> · 
                <?php echo htmlspecialchars($student['department_name'] ?? 'Computer Science'); ?> · 
                <?php echo htmlspecialchars($student['current_level'] ?? '300'); ?> Level
            </p>
            <a href="dashboard.php" class="btn-dashboard">Proceed to Dashboard</a>
        </div>

        <!-- Right: Welcome & Steps -->
        <div class="right-panel">

            <div class="welcome-box">
                <h2>Welcome back, <?php echo htmlspecialchars($student['first_name'] ?? 'Student'); ?></h2>
                <div class="progress-line"></div>
                <p class="steps-text"><?php echo $steps_completed; ?>/<?php echo $total_steps; ?> registration steps completed</p>
                <p class="note">*Please note hostel accommodation is not compulsory and it depends on eligibility and availability.</p>
                <p class="guide-text">Follow the steps to get you started for the new session</p>
            </div>

            <!-- Steps List -->
            <div class="steps-list">

                <!-- Fees Step -->
                <a href="fees.php" class="step-item <?php echo ($fee_status && $fee_status['status'] == 'Paid') ? 'done' : ''; ?>">
                    <div class="step-check">
                        <?php if ($fee_status && $fee_status['status'] == 'Paid'): ?>
                            <i class="fas fa-check"></i>
                        <?php endif; ?>
                    </div>
                    <span class="step-label">Fees</span>
                    <i class="fas fa-chevron-right step-arrow"></i>
                </a>

                <!-- Courses Step -->
                <a href="course-registration.php" class="step-item <?php echo ($course_reg['total'] > 0) ? 'done' : ''; ?>">
                    <div class="step-check">
                        <?php if ($course_reg['total'] > 0): ?>
                            <i class="fas fa-check"></i>
                        <?php endif; ?>
                    </div>
                    <span class="step-label">Courses</span>
                    <i class="fas fa-chevron-right step-arrow"></i>
                </a>

            </div>

            <!-- Payment Trouble -->
            <?php 
            $pending_query = "SELECT COUNT(*) as total FROM payments WHERE student_id = ? AND status = 'Pending'";
            $stmt = $conn->prepare($pending_query);
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $pending = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($pending['total'] > 0): 
            ?>
            <div class="trouble-box">
                <h3>Having Trouble with Payment Verification?</h3>
                <p>Most payments are verified automatically. If yours has not gone through yet, you can manually recheck it below.</p>
                <button class="btn-retry" onclick="retryPayment()">Retry Payment Verification</button>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<style>
.home-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px;
}

/* Session Badge */
.session-badge {
    background: #f5f5f5;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #555;
    margin-bottom: 20px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.session-badge i {
    color: #888;
}

/* Main Grid */
.main-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 24px;
    align-items: start;
}

/* Profile Card */
.profile-card {
    background: #fff;
    border-radius: 16px;
    padding: 40px 30px;
    text-align: center;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}

.profile-photo {
    width: 120px;
    height: 120px;
    margin: 0 auto 20px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid #e8e8e8;
}

.profile-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-placeholder {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    font-weight: 600;
}

.student-name {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 6px;
    letter-spacing: 0.5px;
}

.matric-no {
    font-size: 14px;
    color: #666;
    margin-bottom: 12px;
    font-family: monospace;
}

.program-info {
    font-size: 13px;
    color: #777;
    margin-bottom: 24px;
    line-height: 1.6;
}

.btn-dashboard {
    display: block;
    background: #1a3a7a;
    color: #fff;
    text-decoration: none;
    padding: 14px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.2s;
}

.btn-dashboard:hover {
    background: #0f2a5a;
}

/* Right Panel */
.right-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Welcome Box */
.welcome-box {
    background: #fff;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}

.welcome-box h2 {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 12px;
}

.progress-line {
    height: 3px;
    background: #1a3a7a;
    width: 60px;
    margin-bottom: 12px;
    border-radius: 2px;
}

.steps-text {
    font-size: 14px;
    color: #888;
    margin-bottom: 6px;
}

.note {
    font-size: 12px;
    color: #aaa;
    margin-bottom: 16px;
    line-height: 1.5;
}

.guide-text {
    font-size: 15px;
    color: #555;
    font-weight: 500;
}

/* Steps List */
.steps-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.step-item {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #f0f2f5;
    padding: 16px 20px;
    border-radius: 12px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
}

.step-item:hover {
    background: #e8eaf0;
    transform: translateX(4px);
}

.step-item.done {
    background: #f0f2f5;
}

.step-check {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.step-item.done .step-check {
    background: #4caf50;
    color: #fff;
}

.step-check i {
    font-size: 12px;
}

.step-label {
    flex: 1;
    font-size: 15px;
    font-weight: 500;
    color: #333;
}

.step-arrow {
    color: #bbb;
    font-size: 12px;
}

/* Trouble Box */
.trouble-box {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    border-top: 1px solid #eee;
}

.trouble-box h3 {
    font-size: 15px;
    color: #e91e63;
    font-weight: 600;
    margin-bottom: 8px;
}

.trouble-box p {
    font-size: 13px;
    color: #666;
    line-height: 1.6;
    margin-bottom: 16px;
}

.btn-retry {
    width: 100%;
    background: #1a3a7a;
    color: #fff;
    border: none;
    padding: 14px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-retry:hover {
    background: #0f2a5a;
}

/* Responsive */
@media (max-width: 900px) {
    .main-grid {
        grid-template-columns: 1fr;
    }

    .profile-card {
        max-width: 400px;
        margin: 0 auto;
    }
}
</style>

<script>
function retryPayment() {
    const btn = document.querySelector('.btn-retry');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Retrying...';
    btn.disabled = true;

    fetch('ajax/retry-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({student_id: <?php echo $student_id; ?>})
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? 'Verification initiated!' : 'Error: ' + data.message);
        location.reload();
    })
    .catch(() => {
        alert('An error occurred. Please try again.');
        btn.innerHTML = 'Retry Payment Verification';
        btn.disabled = false;
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>