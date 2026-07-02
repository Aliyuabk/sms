<?php
/**
 * Staff Analytics Page
 * View detailed analytics and insights about courses, students, and performance
 */

ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$staff_id = $_SESSION['staff_id'];

// Get staff info
$stmt = $pdo->prepare("SELECT s.*, d.department_name FROM staff s LEFT JOIN departments d ON s.department_id = d.department_id WHERE s.staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 1. COURSE STATISTICS
// ============================================================
$courseStats = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ca.course_id) as total_courses,
        COUNT(DISTINCT cr.student_id) as total_students,
        COUNT(DISTINCT CASE WHEN cr.registration_status = 'Approved' THEN cr.student_id END) as active_students,
        AVG(CASE WHEN cr.registration_status = 'Approved' THEN 1 ELSE 0 END) * 100 as avg_enrollment_rate
    FROM course_assignments ca
    LEFT JOIN course_registrations cr ON ca.course_id = cr.course_id
    WHERE ca.staff_id = ? AND ca.status = 'Active'
");
$courseStats->execute([$staff_id]);
$stats = $courseStats->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 2. ATTENDANCE STATISTICS
// ============================================================
$attStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_attendance,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) as excused_count,
        ROUND(AVG(CASE WHEN status = 'Present' THEN 100 ELSE 0 END), 1) as attendance_rate
    FROM attendance a
    JOIN course_assignments ca ON a.course_id = ca.course_id
    WHERE ca.staff_id = ?
");
$attStats->execute([$staff_id]);
$attendance = $attStats->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 3. PERFORMANCE STATISTICS
// ============================================================
$perfStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_results,
        AVG(total_score) as avg_score,
        MIN(total_score) as min_score,
        MAX(total_score) as max_score,
        SUM(CASE WHEN grade IN ('A', 'B', 'C') THEN 1 ELSE 0 END) as pass_count,
        SUM(CASE WHEN grade IN ('D', 'E') THEN 1 ELSE 0 END) as marginal_count,
        SUM(CASE WHEN grade = 'F' THEN 1 ELSE 0 END) as fail_count,
        ROUND(AVG(CASE WHEN grade IN ('A', 'B', 'C') THEN 100 ELSE 0 END), 1) as pass_rate
    FROM results r
    JOIN course_assignments ca ON r.course_id = ca.course_id
    WHERE ca.staff_id = ? AND r.is_published = 1
");
$perfStats->execute([$staff_id]);
$performance = $perfStats->fetch(PDO::FETCH_ASSOC);

// ============================================================
// 4. COURSE PERFORMANCE BREAKDOWN
// ============================================================
$coursePerf = $pdo->prepare("
    SELECT 
        c.course_id,
        c.course_code,
        c.course_title,
        COUNT(DISTINCT cr.student_id) as student_count,
        ROUND(AVG(r.total_score), 1) as avg_score,
        ROUND(AVG(CASE WHEN r.grade IN ('A', 'B', 'C') THEN 100 ELSE 0 END), 1) as pass_rate,
        COUNT(r.result_id) as results_count
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN course_registrations cr ON c.course_id = cr.course_id AND cr.registration_status = 'Approved'
    LEFT JOIN results r ON c.course_id = r.course_id AND r.is_published = 1
    WHERE ca.staff_id = ? AND ca.status = 'Active'
    GROUP BY c.course_id, c.course_code, c.course_title
    ORDER BY avg_score DESC
");
$coursePerf->execute([$staff_id]);
$course_performance = $coursePerf->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 5. GRADE DISTRIBUTION
// ============================================================
$gradeDist = $pdo->prepare("
    SELECT 
        grade,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM results r2 
            JOIN course_assignments ca2 ON r2.course_id = ca2.course_id 
            WHERE ca2.staff_id = ? AND r2.is_published = 1), 1) as percentage
    FROM results r
    JOIN course_assignments ca ON r.course_id = ca.course_id
    WHERE ca.staff_id = ? AND r.is_published = 1
    GROUP BY grade
    ORDER BY grade
");
$gradeDist->execute([$staff_id, $staff_id]);
$grade_distribution = $gradeDist->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 6. MONTHLY ATTENDANCE TREND
// ============================================================
$attTrend = $pdo->prepare("
    SELECT 
        DATE_FORMAT(class_date, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent,
        ROUND(AVG(CASE WHEN status = 'Present' THEN 100 ELSE 0 END), 1) as rate
    FROM attendance a
    JOIN course_assignments ca ON a.course_id = ca.course_id
    WHERE ca.staff_id = ?
    GROUP BY DATE_FORMAT(class_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$attTrend->execute([$staff_id]);
$attendance_trend = $attTrend->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 7. STUDENT PERFORMANCE DISTRIBUTION
// ============================================================
$studentPerf = $pdo->prepare("
    SELECT 
        s.student_id,
        s.matric_number,
        s.first_name,
        s.last_name,
        ROUND(AVG(r.total_score), 1) as avg_score,
        COUNT(r.result_id) as courses_taken,
        SUM(CASE WHEN r.grade IN ('A', 'B', 'C') THEN 1 ELSE 0 END) as passed_courses
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id
    JOIN results r ON s.student_id = r.student_id AND r.course_id = cr.course_id
    WHERE ca.staff_id = ? AND r.is_published = 1
    GROUP BY s.student_id, s.matric_number, s.first_name, s.last_name
    ORDER BY avg_score DESC
    LIMIT 10
");
$studentPerf->execute([$staff_id]);
$top_students = $studentPerf->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 8. GENDER DISTRIBUTION
// ============================================================
$genderDist = $pdo->prepare("
    SELECT 
        s.gender,
        COUNT(DISTINCT s.student_id) as count,
        ROUND(COUNT(DISTINCT s.student_id) * 100.0 / (SELECT COUNT(DISTINCT s2.student_id) 
            FROM students s2
            JOIN course_registrations cr2 ON s2.student_id = cr2.student_id
            JOIN course_assignments ca2 ON cr2.course_id = ca2.course_id
            WHERE ca2.staff_id = ?), 1) as percentage
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id
    WHERE ca.staff_id = ?
    GROUP BY s.gender
");
$genderDist->execute([$staff_id, $staff_id]);
$gender_distribution = $genderDist->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 9. LEVEL DISTRIBUTION
// ============================================================
$levelDist = $pdo->prepare("
    SELECT 
        s.current_level,
        COUNT(DISTINCT s.student_id) as count
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id
    WHERE ca.staff_id = ?
    GROUP BY s.current_level
    ORDER BY s.current_level
");
$levelDist->execute([$staff_id]);
$level_distribution = $levelDist->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Analytics';
$page_icon = 'fas fa-chart-line';
$active_page = 'analytics';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Analytics']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    /* ===== ANALYTICS STYLES ===== */
    .analytics-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 20px;
        padding: 30px 35px;
        color: var(--white);
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    .analytics-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(197, 234, 79, 0.1) 0%, transparent 70%);
        border-radius: 50%;
    }
    .analytics-header h4 {
        font-weight: 800;
        margin-bottom: 5px;
        position: relative;
        z-index: 1;
    }
    .analytics-header p {
        opacity: 0.85;
        margin-bottom: 0;
        position: relative;
        z-index: 1;
    }

    .stat-card-analytics {
        background: var(--white);
        border-radius: 16px;
        padding: 20px 25px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        border: 1px solid var(--gray-200);
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    .stat-card-analytics:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-light);
    }
    .stat-card-analytics .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        margin-bottom: 12px;
    }
    .stat-card-analytics .stat-number {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--text-dark);
        line-height: 1.2;
    }
    .stat-card-analytics .stat-label {
        font-size: 0.85rem;
        color: var(--text-light);
        font-weight: 500;
    }
    .stat-card-analytics .stat-change {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 2px 10px;
        border-radius: 6px;
        display: inline-block;
        margin-top: 8px;
    }
    .stat-change.up { background: #e8f5e9; color: var(--success-color); }
    .stat-change.down { background: #ffebee; color: var(--danger-color); }
    .stat-change.neutral { background: var(--gray-100); color: var(--text-light); }

    .icon-primary { background: var(--primary-soft); color: var(--primary-color); }
    .icon-success { background: #e8f5e9; color: var(--success-color); }
    .icon-warning { background: #fff3e0; color: var(--warning-color); }
    .icon-danger { background: #ffebee; color: var(--danger-color); }
    .icon-info { background: #e3f2fd; color: var(--primary-light); }
    .icon-purple { background: #f3e5f5; color: #7b1fa2; }

    .chart-card {
        background: var(--white);
        border-radius: 16px;
        padding: 25px;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--gray-200);
        height: 100%;
        transition: var(--transition);
    }
    .chart-card:hover {
        box-shadow: var(--shadow-lg);
    }
    .chart-card .card-title {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-dark);
        margin-bottom: 20px;
    }
    .chart-card .card-title i {
        color: var(--primary-color);
        margin-right: 10px;
    }

    .chart-container {
        position: relative;
        height: 250px;
    }
    .chart-container-sm {
        height: 200px;
    }

    /* Grade Distribution Bars */
    .grade-bar-container {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 6px 0;
    }
    .grade-bar-label {
        font-weight: 700;
        font-size: 0.9rem;
        min-width: 30px;
    }
    .grade-bar-track {
        flex: 1;
        height: 24px;
        background: var(--gray-100);
        border-radius: 12px;
        overflow: hidden;
        position: relative;
    }
    .grade-bar-fill {
        height: 100%;
        border-radius: 12px;
        transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 8px;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--white);
        min-width: 30px;
    }
    .grade-bar-fill.A { background: linear-gradient(90deg, #43a047, #66bb6a); }
    .grade-bar-fill.B { background: linear-gradient(90deg, #1e88e5, #42a5f5); }
    .grade-bar-fill.C { background: linear-gradient(90deg, #fb8c00, #ffa726); }
    .grade-bar-fill.D { background: linear-gradient(90deg, #8e24aa, #ab47bc); }
    .grade-bar-fill.E { background: linear-gradient(90deg, #e53935, #ef5350); }
    .grade-bar-fill.F { background: linear-gradient(90deg, #c62828, #d32f2f); }
    .grade-bar-percent {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-light);
        min-width: 40px;
        text-align: right;
    }

    /* Gender Distribution */
    .gender-donut {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 15px;
    }
    .gender-donut-chart {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .gender-donut-chart .center-text {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--text-dark);
        text-align: center;
        line-height: 1.2;
    }
    .gender-donut-chart .center-text small {
        display: block;
        font-size: 0.6rem;
        font-weight: 500;
        color: var(--text-light);
    }
    .gender-legend {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .gender-legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .gender-legend-item .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }
    .dot-male { background: var(--primary-color); }
    .dot-female { background: #ec407a; }

    /* Level Distribution */
    .level-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .level-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        background: var(--gray-50);
        border-radius: 10px;
        transition: var(--transition);
    }
    .level-item:hover {
        background: var(--primary-soft);
    }
    .level-item .level-name {
        font-weight: 600;
        min-width: 80px;
        font-size: 0.9rem;
    }
    .level-item .level-bar {
        flex: 1;
        height: 8px;
        background: var(--gray-200);
        border-radius: 10px;
        overflow: hidden;
    }
    .level-item .level-bar-fill {
        height: 100%;
        border-radius: 10px;
        background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
        transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .level-item .level-count {
        font-weight: 600;
        color: var(--text-light);
        font-size: 0.85rem;
        min-width: 40px;
        text-align: right;
    }

    /* Course Performance Table */
    .perf-table {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .perf-table table { margin-bottom: 0; }
    .perf-table th {
        background: var(--gray-100);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 10px 15px;
        border-bottom: 2px solid var(--gray-200);
    }
    .perf-table td {
        padding: 10px 15px;
        vertical-align: middle;
        border-bottom: 1px solid var(--gray-200);
        font-size: 0.9rem;
    }
    .perf-table tr:hover td { background: var(--primary-soft); }

    .score-badge {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.8rem;
    }
    .score-excellent { background: #e8f5e9; color: #2e7d32; }
    .score-good { background: #e3f2fd; color: #1565c0; }
    .score-average { background: #fff3e0; color: #e65100; }
    .score-poor { background: #ffebee; color: #c62828; }

    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        font-weight: 700;
        font-size: 0.75rem;
    }
    .rank-1 { background: #ffd700; color: #7c6a00; }
    .rank-2 { background: #c0c0c0; color: #4a4a4a; }
    .rank-3 { background: #cd7f32; color: #4a2a0a; }
    .rank-other { background: var(--gray-200); color: var(--text-light); }

    .empty-analytics {
        text-align: center;
        padding: 60px 20px;
    }
    .empty-analytics .empty-icon {
        width: 100px;
        height: 100px;
        background: var(--primary-soft);
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }
    .empty-analytics .empty-icon i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }

    @media (max-width: 768px) {
        .analytics-header { padding: 20px; }
        .stat-card-analytics .stat-number { font-size: 1.4rem; }
        .chart-container { height: 200px; }
        .gender-donut-chart { width: 100px; height: 100px; }
    }
</style>

<!-- Analytics Header -->
<div class="analytics-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h4><i class="fas fa-chart-line me-2"></i>Analytics Dashboard</h4>
            <p>Comprehensive insights into your courses, students, and performance metrics</p>
        </div>
        <div>
            <button class="btn btn-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
            </button>
            <button class="btn btn-light btn-sm" onclick="exportAnalytics()">
                <i class="fas fa-file-export me-1"></i> Export
            </button>
        </div>
    </div>
</div>

<!-- Stats Overview -->
<div class="row g-4 mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stat-card-analytics">
            <div class="stat-icon icon-primary">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="stat-number"><?php echo number_format($stats['total_courses'] ?? 0); ?></div>
            <div class="stat-label">Total Courses</div>
            <span class="stat-change neutral">
                <i class="fas fa-minus me-1"></i> Active
            </span>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-card-analytics">
            <div class="stat-icon icon-success">
                <i class="fas fa-user-graduate"></i>
            </div>
            <div class="stat-number"><?php echo number_format($stats['active_students'] ?? 0); ?></div>
            <div class="stat-label">Active Students</div>
            <span class="stat-change up">
                <i class="fas fa-arrow-up me-1"></i> <?php echo number_format($stats['avg_enrollment_rate'] ?? 0, 1); ?>% enrolled
            </span>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-card-analytics">
            <div class="stat-icon icon-warning">
                <i class="fas fa-clipboard-check"></i>
            </div>
            <div class="stat-number"><?php echo number_format($attendance['attendance_rate'] ?? 0, 1); ?>%</div>
            <div class="stat-label">Attendance Rate</div>
            <span class="stat-change <?php echo ($attendance['attendance_rate'] ?? 0) >= 75 ? 'up' : 'down'; ?>">
                <i class="fas fa-<?php echo ($attendance['attendance_rate'] ?? 0) >= 75 ? 'arrow-up' : 'arrow-down'; ?> me-1"></i>
                <?php echo number_format($attendance['present_count'] ?? 0); ?> present
            </span>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-card-analytics">
            <div class="stat-icon icon-info">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="stat-number"><?php echo number_format($performance['pass_rate'] ?? 0, 1); ?>%</div>
            <div class="stat-label">Pass Rate</div>
            <span class="stat-change <?php echo ($performance['pass_rate'] ?? 0) >= 70 ? 'up' : 'down'; ?>">
                <i class="fas fa-<?php echo ($performance['pass_rate'] ?? 0) >= 70 ? 'arrow-up' : 'arrow-down'; ?> me-1"></i>
                <?php echo number_format($performance['avg_score'] ?? 0, 1); ?> avg score
            </span>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div class="row g-4 mb-4">
    <!-- Grade Distribution -->
    <div class="col-lg-6">
        <div class="chart-card">
            <div class="card-title">
                <i class="fas fa-chart-bar"></i> Grade Distribution
            </div>
            <div class="chart-container chart-container-sm">
                <?php if (!empty($grade_distribution)): ?>
                    <?php foreach ($grade_distribution as $grade): ?>
                    <div class="grade-bar-container">
                        <span class="grade-bar-label"><?php echo htmlspecialchars($grade['grade']); ?></span>
                        <div class="grade-bar-track">
                            <div class="grade-bar-fill <?php echo htmlspecialchars($grade['grade']); ?>" 
                                 style="width: 0%;" 
                                 data-width="<?php echo $grade['percentage']; ?>">
                                <?php if ($grade['percentage'] >= 15): ?>
                                    <?php echo number_format($grade['percentage'], 1); ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="grade-bar-percent">
                            <?php echo number_format($grade['percentage'], 1); ?>%
                            <span style="font-weight:400;color:var(--gray-400);font-size:0.7rem;">
                                (<?php echo $grade['count']; ?>)
                            </span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-analytics" style="padding: 30px 10px;">
                        <p class="text-muted">No grade data available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Gender Distribution -->
    <div class="col-lg-6">
        <div class="chart-card">
            <div class="card-title">
                <i class="fas fa-venus-mars"></i> Gender Distribution
            </div>
            <div class="gender-donut">
                <?php 
                $male_count = 0;
                $female_count = 0;
                foreach ($gender_distribution as $g) {
                    if ($g['gender'] == 'Male') $male_count = $g['count'];
                    elseif ($g['gender'] == 'Female') $female_count = $g['count'];
                }
                $total_gender = $male_count + $female_count;
                $male_pct = $total_gender > 0 ? ($male_count / $total_gender * 100) : 0;
                $female_pct = $total_gender > 0 ? ($female_count / $total_gender * 100) : 0;
                ?>
                <?php if ($total_gender > 0): ?>
                <div class="gender-donut-chart" style="background: conic-gradient(
                    var(--primary-color) 0% <?php echo $male_pct; ?>%, 
                    #ec407a <?php echo $male_pct; ?>% 100%
                );">
                    <div class="center-text" style="background: var(--white); width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                        <?php echo $total_gender; ?>
                        <small>Total</small>
                    </div>
                </div>
                <div class="gender-legend">
                    <div class="gender-legend-item">
                        <span class="dot dot-male"></span>
                        Male (<?php echo number_format($male_pct, 1); ?>%) - <?php echo $male_count; ?>
                    </div>
                    <div class="gender-legend-item">
                        <span class="dot dot-female"></span>
                        Female (<?php echo number_format($female_pct, 1); ?>%) - <?php echo $female_count; ?>
                    </div>
                </div>
                <?php else: ?>
                    <div class="empty-analytics" style="padding: 30px 10px;">
                        <p class="text-muted">No gender data available.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-4 mb-4">
    <!-- Level Distribution -->
    <div class="col-lg-6">
        <div class="chart-card">
            <div class="card-title">
                <i class="fas fa-layer-group"></i> Level Distribution
            </div>
            <?php if (!empty($level_distribution)): 
                $max_level_count = max(array_column($level_distribution, 'count'));
            ?>
                <div class="level-list">
                    <?php foreach ($level_distribution as $level): 
                        $pct = $max_level_count > 0 ? ($level['count'] / $max_level_count * 100) : 0;
                    ?>
                    <div class="level-item">
                        <span class="level-name">Level <?php echo htmlspecialchars($level['current_level']); ?></span>
                        <div class="level-bar">
                            <div class="level-bar-fill" style="width: 0%;" data-width="<?php echo $pct; ?>"></div>
                        </div>
                        <span class="level-count"><?php echo $level['count']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-analytics" style="padding: 30px 10px;">
                    <p class="text-muted">No level data available.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendance Trend -->
    <div class="col-lg-6">
        <div class="chart-card">
            <div class="card-title">
                <i class="fas fa-chart-line"></i> Attendance Trend (Last 6 Months)
            </div>
            <?php if (!empty($attendance_trend)): ?>
                <div class="chart-container">
                    <canvas id="attendanceTrendChart"></canvas>
                </div>
            <?php else: ?>
                <div class="empty-analytics" style="padding: 30px 10px;">
                    <p class="text-muted">No attendance trend data available.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Course Performance -->
<div class="chart-card mb-4">
    <div class="card-title">
        <i class="fas fa-trophy"></i> Course Performance Overview
    </div>
    <?php if (!empty($course_performance)): ?>
        <div class="perf-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Course</th>
                        <th>Students</th>
                        <th>Avg Score</th>
                        <th>Pass Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; foreach ($course_performance as $course): $rank++; ?>
                    <tr>
                        <td>
                            <span class="rank-badge rank-<?php echo $rank <= 3 ? $rank : 'other'; ?>">
                                <?php echo $rank; ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($course['course_code']); ?></strong>
                            <div style="font-size:0.8rem;color:var(--text-light);">
                                <?php echo htmlspecialchars($course['course_title']); ?>
                            </div>
                        </td>
                        <td><?php echo $course['student_count']; ?></td>
                        <td>
                            <span class="score-badge <?php 
                                echo ($course['avg_score'] ?? 0) >= 70 ? 'score-excellent' : 
                                    (($course['avg_score'] ?? 0) >= 60 ? 'score-good' : 
                                    (($course['avg_score'] ?? 0) >= 50 ? 'score-average' : 'score-poor')); 
                            ?>">
                                <?php echo number_format($course['avg_score'] ?? 0, 1); ?>
                            </span>
                        </td>
                        <td>
                            <span style="color: <?php echo ($course['pass_rate'] ?? 0) >= 70 ? 'var(--success-color)' : 'var(--danger-color)'; ?>; font-weight:600;">
                                <?php echo number_format($course['pass_rate'] ?? 0, 1); ?>%
                            </span>
                        </td>
                        <td>
                            <span class="status-badge-sm <?php echo ($course['pass_rate'] ?? 0) >= 70 ? 'status-active' : 'status-suspended'; ?>">
                                <?php echo ($course['pass_rate'] ?? 0) >= 70 ? 'Excellent' : 'Needs Improvement'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-analytics" style="padding: 30px 10px;">
            <p class="text-muted">No course performance data available.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Top Students -->
<div class="chart-card mb-4">
    <div class="card-title">
        <i class="fas fa-star"></i> Top Performing Students
    </div>
    <?php if (!empty($top_students)): ?>
        <div class="perf-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Matric</th>
                        <th>Avg Score</th>
                        <th>Courses</th>
                        <th>Passed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 0; foreach ($top_students as $student): $rank++; ?>
                    <tr>
                        <td>
                            <span class="rank-badge rank-<?php echo $rank <= 3 ? $rank : 'other'; ?>">
                                <?php echo $rank; ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                        <td>
                            <span class="score-badge <?php 
                                echo ($student['avg_score'] ?? 0) >= 70 ? 'score-excellent' : 
                                    (($student['avg_score'] ?? 0) >= 60 ? 'score-good' : 'score-average'); 
                            ?>">
                                <?php echo number_format($student['avg_score'] ?? 0, 1); ?>
                            </span>
                        </td>
                        <td><?php echo $student['courses_taken']; ?></td>
                        <td>
                            <span style="color: var(--success-color); font-weight:600;">
                                <?php echo $student['passed_courses']; ?>/<?php echo $student['courses_taken']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-analytics" style="padding: 30px 10px;">
            <p class="text-muted">No student performance data available.</p>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // ============================================================
    // ANIMATE BARS ON LOAD
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Animate grade bars
        document.querySelectorAll('.grade-bar-fill[data-width]').forEach((bar, index) => {
            setTimeout(() => {
                bar.style.width = bar.dataset.width + '%';
            }, 200 + (index * 100));
        });

        // Animate level bars
        document.querySelectorAll('.level-bar-fill[data-width]').forEach((bar, index) => {
            setTimeout(() => {
                bar.style.width = bar.dataset.width + '%';
            }, 300 + (index * 100));
        });
    });

    // ============================================================
    // ATTENDANCE TREND CHART
    // ============================================================
    <?php if (!empty($attendance_trend)): ?>
    const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
    
    const months = <?php echo json_encode(array_reverse(array_column($attendance_trend, 'month'))); ?>;
    const present = <?php echo json_encode(array_reverse(array_column($attendance_trend, 'present'))); ?>;
    const absent = <?php echo json_encode(array_reverse(array_column($attendance_trend, 'absent'))); ?>;
    const rates = <?php echo json_encode(array_reverse(array_column($attendance_trend, 'rate'))); ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                {
                    label: 'Present',
                    data: present,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#10b981',
                },
                {
                    label: 'Absent',
                    data: absent,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.05)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#ef4444',
                },
                {
                    label: 'Rate (%)',
                    data: rates,
                    borderColor: '#3f749c',
                    backgroundColor: 'rgba(63, 116, 156, 0.05)',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4,
                    pointRadius: 3,
                    pointBackgroundColor: '#3f749c',
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { size: 11, weight: '600' }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: { font: { size: 10 } }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    max: 100,
                    grid: { display: false },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) { return value + '%'; }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    });
    <?php endif; ?>

    // ============================================================
    // EXPORT ANALYTICS
    // ============================================================
    function exportAnalytics() {
        alert('Export functionality will be implemented here.');
        // You can implement PDF/CSV export using libraries like jsPDF or SheetJS
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>