<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];
$course_id = isset($_GET['course']) ? intval($_GET['course']) : 0;
$student_id = isset($_GET['student']) ? intval($_GET['student']) : 0;

if (!$course_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch course details
$stmt = $pdo->prepare("
    SELECT c.*, ca.session_year, ca.semester, ca.assignment_id
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    WHERE c.course_id = ? AND ca.staff_id = ? AND ca.status = 'Active'
    LIMIT 1
");
$stmt->execute([$course_id, $staff_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header('Location: dashboard.php');
    exit;
}

// Handle single student result submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_single']) && $student_id) {
        // Single student result
        $ca_score = floatval($_POST['ca_score'] ?? 0);
        $exam_score = floatval($_POST['exam_score'] ?? 0);
        $total_score = $ca_score + $exam_score;

        // Determine grade
        $grade = null;
        $grade_points = 0;
        $grade_remark = '';

        if ($total_score >= 70) { $grade = 'A'; $grade_points = 5.00; $grade_remark = 'Excellent'; }
        elseif ($total_score >= 60) { $grade = 'B'; $grade_points = 4.00; $grade_remark = 'Very Good'; }
        elseif ($total_score >= 50) { $grade = 'C'; $grade_points = 3.00; $grade_remark = 'Good'; }
        elseif ($total_score >= 45) { $grade = 'D'; $grade_points = 2.00; $grade_remark = 'Pass'; }
        elseif ($total_score >= 40) { $grade = 'E'; $grade_points = 1.00; $grade_remark = 'Weak Pass'; }
        else { $grade = 'F'; $grade_points = 0.00; $grade_remark = 'Fail'; }

        try {
            // Check if result exists
            $check = $pdo->prepare("
                SELECT result_id FROM results 
                WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?
            ");
            $check->execute([$student_id, $course_id, $course['session_year'], $course['semester']]);

            if ($check->fetch()) {
                $upd = $pdo->prepare("
                    UPDATE results SET 
                        ca_score = ?, exam_score = ?, total_score = ?, grade = ?, 
                        grade_points = ?, grade_remark = ?, calculated_by = ?, is_published = 0
                    WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?
                ");
                $upd->execute([
                    $ca_score, $exam_score, $total_score, $grade, $grade_points, $grade_remark,
                    $staff_id, $student_id, $course_id, $course['session_year'], $course['semester']
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO results 
                    (student_id, course_id, session_year, semester, level, ca_score, exam_score, total_score, grade, grade_points, grade_remark, calculated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $student_id, $course_id, $course['session_year'], $course['semester'], $course['level'],
                    $ca_score, $exam_score, $total_score, $grade, $grade_points, $grade_remark, $staff_id
                ]);
            }
            $success_msg = 'Result saved successfully!';
        } catch (Exception $e) {
            $error_msg = 'Error: ' . $e->getMessage();
        }
    }

    if (isset($_POST['save_bulk'])) {
        // Bulk results
        $pdo->beginTransaction();
        try {
            foreach ($_POST['results'] as $sid => $result) {
                $ca_score = floatval($result['ca_score'] ?? 0);
                $exam_score = floatval($result['exam_score'] ?? 0);
                $total_score = $ca_score + $exam_score;

                $grade = null; $grade_points = 0; $grade_remark = '';
                if ($total_score >= 70) { $grade = 'A'; $grade_points = 5.00; $grade_remark = 'Excellent'; }
                elseif ($total_score >= 60) { $grade = 'B'; $grade_points = 4.00; $grade_remark = 'Very Good'; }
                elseif ($total_score >= 50) { $grade = 'C'; $grade_points = 3.00; $grade_remark = 'Good'; }
                elseif ($total_score >= 45) { $grade = 'D'; $grade_points = 2.00; $grade_remark = 'Pass'; }
                elseif ($total_score >= 40) { $grade = 'E'; $grade_points = 1.00; $grade_remark = 'Weak Pass'; }
                else { $grade = 'F'; $grade_points = 0.00; $grade_remark = 'Fail'; }

                $check = $pdo->prepare("
                    SELECT result_id FROM results 
                    WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?
                ");
                $check->execute([$sid, $course_id, $course['session_year'], $course['semester']]);

                if ($check->fetch()) {
                    $upd = $pdo->prepare("
                        UPDATE results SET 
                            ca_score = ?, exam_score = ?, total_score = ?, grade = ?, 
                            grade_points = ?, grade_remark = ?, calculated_by = ?, is_published = 0
                        WHERE student_id = ? AND course_id = ? AND session_year = ? AND semester = ?
                    ");
                    $upd->execute([
                        $ca_score, $exam_score, $total_score, $grade, $grade_points, $grade_remark,
                        $staff_id, $sid, $course_id, $course['session_year'], $course['semester']
                    ]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO results 
                        (student_id, course_id, session_year, semester, level, ca_score, exam_score, total_score, grade, grade_points, grade_remark, calculated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $sid, $course_id, $course['session_year'], $course['semester'], $course['level'],
                        $ca_score, $exam_score, $total_score, $grade, $grade_points, $grade_remark, $staff_id
                    ]);
                }
            }
            $pdo->commit();
            $success_msg = 'All results saved successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = 'Error: ' . $e->getMessage();
        }
    }
}

// Fetch students
$stmt2 = $pdo->prepare("
    SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.middle_name,
           s.gender, s.profile_image,
           r.ca_score, r.exam_score, r.total_score, r.grade, r.grade_points
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    LEFT JOIN results r ON s.student_id = r.student_id 
        AND r.course_id = ? AND r.session_year = ? AND r.semester = ?
    WHERE cr.course_id = ? AND cr.session_year = ? AND cr.semester = ? AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$stmt2->execute([
    $course_id, $course['session_year'], $course['semester'],
    $course_id, $course['session_year'], $course['semester']
]);
$students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get staff data
$stmt3 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt3->execute([$staff_id]);
$staff = $stmt3->fetch(PDO::FETCH_ASSOC);

// If single student mode
$single_student = null;
if ($student_id) {
    $single_student = array_filter($students, fn($s) => $s['student_id'] == $student_id);
    $single_student = $single_student ? array_values($single_student)[0] : null;
}

// Page variables
$page_title = $single_student ? 'Enter Result' : 'Upload Results';
$page_icon = 'fas fa-upload';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses', 'url' => 'courses.php'],
    ['title' => $course['course_code'], 'url' => 'view_class.php?course=' . $course_id],
    ['title' => $page_title]
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .results-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 30px;
        color: var(--white);
        margin-bottom: 25px;
        animation: fadeInUp 0.6s ease;
    }
    .results-hero h2 {
        font-size: 1.5rem;
        font-weight: 800;
        margin-bottom: 8px;
    }
    .results-hero-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        opacity: 0.9;
        font-size: 0.9rem;
    }
    .results-hero-meta i { margin-right: 6px; }

    .alert-results {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 500;
        animation: fadeInUp 0.4s ease;
    }
    .alert-results-success {
        background: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid var(--success-color);
    }
    .alert-results-error {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid var(--danger-color);
    }

    .results-form-wrap {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.6s ease;
    }
    .results-form-header {
        padding: 25px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .results-form-header h4 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
    }
    .score-legend {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    .score-legend-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
    }
    .score-legend-dot {
        width: 12px;
        height: 12px;
        border-radius: 3px;
    }

    .results-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .results-table thead th {
        background: var(--gray-100);
        padding: 14px 16px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-light);
        border-bottom: 2px solid var(--gray-200);
        white-space: nowrap;
    }
    .results-table tbody td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
    }
    .results-table tbody tr:hover { background: var(--primary-soft); }
    .results-table tbody tr.has-result { background: rgba(232, 245, 233, 0.3); }

    .student-result-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .result-avatar {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.75rem;
    }
    .result-student-name { font-weight: 600; font-size: 0.9rem; }
    .result-student-matric { font-size: 0.75rem; color: var(--text-light); }

    .score-input {
        width: 70px;
        padding: 8px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        text-align: center;
        font-size: 0.9rem;
        font-weight: 600;
        transition: var(--transition);
    }
    .score-input:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .total-cell {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-dark);
    }
    .grade-preview {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-block;
        min-width: 40px;
        text-align: center;
    }
    .grade-preview-a { background: #e8f5e9; color: #2e7d32; }
    .grade-preview-b { background: #e3f2fd; color: #1565c0; }
    .grade-preview-c { background: #fff3e0; color: #ef6c00; }
    .grade-preview-d { background: #fce4ec; color: #c62828; }
    .grade-preview-e { background: #fce4ec; color: #c62828; }
    .grade-preview-f { background: #ffebee; color: #b71c1c; }
    .grade-preview-null { background: var(--gray-200); color: var(--gray-500); }

    .results-form-footer {
        padding: 25px;
        background: var(--gray-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .btn-save-results {
        padding: 14px 35px;
        background: linear-gradient(135deg, var(--success-color), #689f38);
        color: var(--white);
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .btn-save-results:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(124, 179, 66, 0.3);
    }

    .single-result-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        padding: 35px;
        animation: fadeInUp 0.6s ease;
    }
    .single-result-student {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        padding-bottom: 25px;
        border-bottom: 1px solid var(--gray-200);
    }
    .single-result-avatar {
        width: 80px;
        height: 80px;
        border-radius: 20px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        font-weight: 800;
    }
    .single-result-info h3 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 5px;
    }
    .single-result-info p {
        color: var(--text-light);
        margin: 0;
    }
    .score-inputs-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 25px;
    }
    .score-input-group {
        text-align: center;
    }
    .score-input-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--text-dark);
    }
    .score-input-big {
        width: 100%;
        padding: 20px;
        border: 2px solid var(--gray-300);
        border-radius: 16px;
        text-align: center;
        font-size: 2rem;
        font-weight: 800;
        transition: var(--transition);
    }
    .score-input-big:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 4px rgba(63, 116, 156, 0.1);
    }
    .result-preview-box {
        background: var(--gray-100);
        border-radius: 16px;
        padding: 25px;
        text-align: center;
    }
    .result-preview-grade {
        font-size: 3rem;
        font-weight: 800;
        margin-bottom: 10px;
    }
    .result-preview-text {
        font-size: 1rem;
        color: var(--text-light);
    }

    @media (max-width: 768px) {
        .score-inputs-row { grid-template-columns: 1fr; }
        .results-table-wrap { overflow-x: auto; }
        .score-legend { display: none; }
    }
</style>

<?php if ($success_msg): ?>
<div class="alert-results alert-results-success">
    <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert-results alert-results-error">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="results-hero">
    <h2><i class="fas fa-upload me-2"></i><?php echo $single_student ? 'Enter Result' : 'Upload Results'; ?></h2>
    <p class="mb-2"><?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?></p>
    <div class="results-hero-meta">
        <span><i class="fas fa-calendar"></i> <?php echo $course['session_year']; ?></span>
        <span><i class="fas fa-clock"></i> Semester <?php echo $course['semester']; ?></span>
        <span><i class="fas fa-users"></i> <?php echo count($students); ?> Students</span>
    </div>
</div>

<?php if ($single_student && $single_student): ?>
<!-- Single Student Result Entry -->
<div class="single-result-card">
    <div class="single-result-student">
        <div class="single-result-avatar">
            <?php echo strtoupper(substr($single_student['first_name'], 0, 1)); ?>
        </div>
        <div class="single-result-info">
            <h3><?php echo htmlspecialchars($single_student['last_name'] . ', ' . $single_student['first_name']); ?></h3>
            <p><i class="fas fa-id-card me-2"></i><?php echo htmlspecialchars($single_student['matric_number']); ?></p>
        </div>
    </div>

    <form method="POST" action="">
        <div class="score-inputs-row">
            <div class="score-input-group">
                <label><i class="fas fa-pen me-1"></i>CA Score (Max 40)</label>
                <input type="number" class="score-input-big" name="ca_score" id="caScore" 
                       value="<?php echo $single_student['ca_score'] ?? ''; ?>" 
                       min="0" max="40" step="0.01" placeholder="0" oninput="calculateGrade()">
            </div>
            <div class="score-input-group">
                <label><i class="fas fa-file-alt me-1"></i>Exam Score (Max 60)</label>
                <input type="number" class="score-input-big" name="exam_score" id="examScore" 
                       value="<?php echo $single_student['exam_score'] ?? ''; ?>" 
                       min="0" max="60" step="0.01" placeholder="0" oninput="calculateGrade()">
            </div>
            <div class="score-input-group">
                <label><i class="fas fa-calculator me-1"></i>Total Score</label>
                <input type="number" class="score-input-big" id="totalScore" 
                       value="<?php echo $single_student['total_score'] ?? ''; ?>" readonly 
                       style="background: var(--gray-100);">
            </div>
        </div>

        <div class="result-preview-box">
            <div class="result-preview-grade" id="gradePreview">-</div>
            <div class="result-preview-text" id="gradeText">Enter scores to see grade</div>
        </div>

        <div class="mt-4 text-center">
            <button type="submit" name="save_single" class="btn-save-results">
                <i class="fas fa-save"></i> Save Result
            </button>
            <a href="view_class.php?course=<?php echo $course_id; ?>" class="btn btn-outline-secondary ms-2" style="padding: 14px 30px; border-radius: 12px; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Back to Class
            </a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Bulk Results Entry -->
<form method="POST" action="">
    <div class="results-form-wrap">
        <div class="results-form-header">
            <h4><i class="fas fa-list-ol text-primary me-2"></i>Enter Results for All Students</h4>
            <div class="score-legend">
                <div class="score-legend-item">
                    <div class="score-legend-dot" style="background: #2e7d32;"></div> A (70-100)
                </div>
                <div class="score-legend-item">
                    <div class="score-legend-dot" style="background: #1565c0;"></div> B (60-69)
                </div>
                <div class="score-legend-item">
                    <div class="score-legend-dot" style="background: #ef6c00;"></div> C (50-59)
                </div>
                <div class="score-legend-item">
                    <div class="score-legend-dot" style="background: #c62828;"></div> D/E (40-49)
                </div>
                <div class="score-legend-item">
                    <div class="score-legend-dot" style="background: #b71c1c;"></div> F (0-39)
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="results-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Student</th>
                        <th style="width: 90px;">CA (40)</th>
                        <th style="width: 90px;">Exam (60)</th>
                        <th style="width: 80px;">Total</th>
                        <th style="width: 70px;">Grade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $index => $student): 
                        $has_result = !empty($student['grade']);
                        $total = ($student['ca_score'] ?? 0) + ($student['exam_score'] ?? 0);
                    ?>
                    <tr class="<?php echo $has_result ? 'has-result' : ''; ?>" data-student="<?php echo $student['student_id']; ?>">
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <div class="student-result-cell">
                                <div class="result-avatar">
                                    <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="result-student-name">
                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                    </div>
                                    <div class="result-student-matric"><?php echo htmlspecialchars($student['matric_number']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="number" class="score-input ca-input" 
                                   name="results[<?php echo $student['student_id']; ?>][ca_score]"
                                   value="<?php echo $student['ca_score'] ?? ''; ?>"
                                   min="0" max="40" step="0.01" placeholder="0"
                                   oninput="updateRow(this)">
                        </td>
                        <td>
                            <input type="number" class="score-input exam-input" 
                                   name="results[<?php echo $student['student_id']; ?>][exam_score]"
                                   value="<?php echo $student['exam_score'] ?? ''; ?>"
                                   min="0" max="60" step="0.01" placeholder="0"
                                   oninput="updateRow(this)">
                        </td>
                        <td class="total-cell" id="total_<?php echo $student['student_id']; ?>">
                            <?php echo $total > 0 ? $total : '-'; ?>
                        </td>
                        <td>
                            <span class="grade-preview grade-preview-<?php echo strtolower($student['grade'] ?? 'null'); ?>" 
                                  id="grade_<?php echo $student['student_id']; ?>">
                                <?php echo $student['grade'] ?? '-'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($has_result): ?>
                                <span class="badge-status status-active">
                                    <i class="fas fa-check me-1"></i>Graded
                                </span>
                            <?php else: ?>
                                <span class="badge-status" style="background: var(--gray-200); color: var(--gray-500);">
                                    <i class="fas fa-minus me-1"></i>Pending
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="results-form-footer">
            <div>
                <strong><?php echo count($students); ?></strong> students total |
                <strong><?php echo count(array_filter($students, fn($s) => !empty($s['grade']))); ?></strong> graded |
                <strong><?php echo count(array_filter($students, fn($s) => empty($s['grade']))); ?></strong> pending
            </div>
            <button type="submit" name="save_bulk" class="btn-save-results">
                <i class="fas fa-save"></i> Save All Results
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<script>
    function calculateGrade() {
        const ca = parseFloat(document.getElementById('caScore').value) || 0;
        const exam = parseFloat(document.getElementById('examScore').value) || 0;
        const total = ca + exam;

        document.getElementById('totalScore').value = total > 0 ? total.toFixed(2) : '';

        let grade = '-';
        let text = 'Enter scores to see grade';
        let color = 'var(--gray-500)';

        if (total > 0) {
            if (total >= 70) { grade = 'A'; text = 'Excellent'; color = '#2e7d32'; }
            else if (total >= 60) { grade = 'B'; text = 'Very Good'; color = '#1565c0'; }
            else if (total >= 50) { grade = 'C'; text = 'Good'; color = '#ef6c00'; }
            else if (total >= 45) { grade = 'D'; text = 'Pass'; color = '#c62828'; }
            else if (total >= 40) { grade = 'E'; text = 'Weak Pass'; color = '#c62828'; }
            else { grade = 'F'; text = 'Fail'; color = '#b71c1c'; }
        }

        const preview = document.getElementById('gradePreview');
        preview.textContent = grade;
        preview.style.color = color;
        document.getElementById('gradeText').textContent = text;
    }

    function updateRow(input) {
        const row = input.closest('tr');
        const studentId = row.getAttribute('data-student');
        const ca = parseFloat(row.querySelector('.ca-input').value) || 0;
        const exam = parseFloat(row.querySelector('.exam-input').value) || 0;
        const total = ca + exam;

        document.getElementById('total_' + studentId).textContent = total > 0 ? total.toFixed(2) : '-';

        let grade = '-';
        let gradeClass = 'grade-preview-null';

        if (total > 0) {
            if (total >= 70) { grade = 'A'; gradeClass = 'grade-preview-a'; }
            else if (total >= 60) { grade = 'B'; gradeClass = 'grade-preview-b'; }
            else if (total >= 50) { grade = 'C'; gradeClass = 'grade-preview-c'; }
            else if (total >= 45) { grade = 'D'; gradeClass = 'grade-preview-d'; }
            else if (total >= 40) { grade = 'E'; gradeClass = 'grade-preview-e'; }
            else { grade = 'F'; gradeClass = 'grade-preview-f'; }
        }

        const gradeEl = document.getElementById('grade_' + studentId);
        gradeEl.textContent = grade;
        gradeEl.className = 'grade-preview ' + gradeClass;
    }

    // Initialize
    if (document.getElementById('caScore')) calculateGrade();
</script>
 