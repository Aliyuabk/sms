<?php
/**
 * Staff Results Page
 * View and manage student results
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
$course_id = $_GET['course'] ?? 0;
$student_id = $_GET['student'] ?? 0;

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all courses for this staff
$courseStmt = $pdo->prepare("
    SELECT DISTINCT c.course_id, c.course_code, c.course_title, ca.session_year, ca.semester
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE ca.staff_id = ? AND ca.status = 'Active'
");
$courseStmt->execute([$staff_id]);
$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Get course details if selected
$course = null;
$students = [];
$results = [];
$grade_scale = [];

if ($course_id > 0) {
    // Get course details
    $courseStmt = $pdo->prepare("SELECT * FROM courses WHERE course_id = ?");
    $courseStmt->execute([$course_id]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get students enrolled in this course
    $studentStmt = $pdo->prepare("
        SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.email, s.profile_image
        FROM students s
        JOIN course_registrations cr ON s.student_id = cr.student_id
        WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
        ORDER BY s.last_name, s.first_name
    ");
    $studentStmt->execute([$course_id]);
    $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get grade scale
    $gradeStmt = $pdo->query("SELECT * FROM grade_scale ORDER BY min_score DESC");
    $grade_scale = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current session
    $sessionStmt = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
    $current_session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get results for this course
    $resultStmt = $pdo->prepare("
        SELECT * FROM results 
        WHERE course_id = ? 
        AND session_year = ? 
        AND semester = ?
        ORDER BY student_id
    ");
    $resultStmt->execute([
        $course_id,
        $current_session['session_year'] ?? date('Y'),
        $current_session['semester'] ?? 1
    ]);
    $results_data = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
    $results_by_student = [];
    foreach ($results_data as $r) {
        $results_by_student[$r['student_id']] = $r;
    }
}

// Handle result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    $course_id = $_POST['course_id'];
    $session_year = $_POST['session_year'];
    $semester = $_POST['semester'];
    $level = $_POST['level'];
    $ca_scores = $_POST['ca_score'] ?? [];
    $exam_scores = $_POST['exam_score'] ?? [];
    $result_ids = $_POST['result_id'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($ca_scores as $student_id => $ca_score) {
            $exam_score = $exam_scores[$student_id] ?? 0;
            $total_score = floatval($ca_score) + floatval($exam_score);
            
            // Determine grade
            $grade = 'F';
            $grade_points = 0;
            $grade_remark = 'Fail';
            
            foreach ($grade_scale as $g) {
                if ($total_score >= $g['min_score'] && $total_score <= $g['max_score']) {
                    $grade = $g['grade'];
                    $grade_points = $g['grade_points'];
                    $grade_remark = $g['remark'];
                    break;
                }
            }
            
            $result_id = $result_ids[$student_id] ?? 0;
            
            if ($result_id > 0) {
                // Update existing result
                $updateStmt = $pdo->prepare("
                    UPDATE results 
                    SET ca_score = ?, exam_score = ?, total_score = ?, 
                        grade = ?, grade_points = ?, grade_remark = ?,
                        calculated_by = ?, created_at = NOW()
                    WHERE result_id = ?
                ");
                $updateStmt->execute([
                    $ca_score, $exam_score, $total_score,
                    $grade, $grade_points, $grade_remark,
                    $staff_id, $result_id
                ]);
            } else {
                // Insert new result
                $insertStmt = $pdo->prepare("
                    INSERT INTO results (
                        student_id, course_id, session_year, semester, level,
                        ca_score, exam_score, total_score, grade, grade_points, grade_remark,
                        calculated_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $student_id, $course_id, $session_year, $semester, $level,
                    $ca_score, $exam_score, $total_score,
                    $grade, $grade_points, $grade_remark,
                    $staff_id
                ]);
            }
        }
        
        $pdo->commit();
        $success = "Results saved successfully!";
        
        // Refresh results
        $resultStmt = $pdo->prepare("
            SELECT * FROM results 
            WHERE course_id = ? AND session_year = ? AND semester = ?
        ");
        $resultStmt->execute([$course_id, $session_year, $semester]);
        $results_data = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
        $results_by_student = [];
        foreach ($results_data as $r) {
            $results_by_student[$r['student_id']] = $r;
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving results: " . $e->getMessage();
        error_log("Result Error: " . $e->getMessage());
    }
}

// Handle result publication
if (isset($_GET['publish'])) {
    $course_id = $_GET['publish'];
    try {
        $pdo->prepare("
            UPDATE results 
            SET is_published = 1, published_date = NOW(), published_by = ?
            WHERE course_id = ? AND is_published = 0
        ")->execute([$staff_id, $course_id]);
        $success = "Results published successfully!";
    } catch (Exception $e) {
        $error = "Error publishing results: " . $e->getMessage();
    }
}

$page_title = 'Results';
$page_icon = 'fas fa-graduation-cap';
$active_page = 'results';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Results']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .result-toolbar {
        background: var(--white);
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-sm);
        margin-bottom: 30px;
    }
    .result-table {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .result-table table { margin-bottom: 0; }
    .result-table th {
        background: var(--gray-100);
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 15px;
        border-bottom: 2px solid var(--gray-200);
        text-align: center;
    }
    .result-table td {
        padding: 10px 12px;
        vertical-align: middle;
        border-bottom: 1px solid var(--gray-200);
        text-align: center;
    }
    .result-table tr:hover td { background: var(--primary-soft); }
    .result-table input[type="number"] {
        width: 80px;
        padding: 6px 10px;
        border-radius: 8px;
        border: 1.5px solid var(--gray-200);
        text-align: center;
        font-size: 0.9rem;
        transition: var(--transition);
    }
    .result-table input[type="number"]:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
        outline: none;
    }
    .grade-badge {
        padding: 6px 14px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        display: inline-block;
        min-width: 40px;
    }
    .grade-A { background: #e8f5e9; color: #2e7d32; }
    .grade-B { background: #e3f2fd; color: #1565c0; }
    .grade-C { background: #fff3e0; color: #e65100; }
    .grade-D { background: #f3e5f5; color: #6a1b9a; }
    .grade-E { background: #fce4ec; color: #c62828; }
    .grade-F { background: #ffebee; color: #b71c1c; }
    
    .publish-toggle {
        padding: 8px 20px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        border: none;
        transition: var(--transition);
    }
    .publish-toggle.published {
        background: #e8f5e9;
        color: var(--success-color);
    }
    .publish-toggle.unpublished {
        background: #fff3e0;
        color: var(--warning-color);
    }
    .btn-save-results {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-save-results:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(63, 116, 156, 0.4);
        color: var(--white);
    }
    .btn-publish-results {
        background: linear-gradient(135deg, var(--success-color), #558b2f);
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-publish-results:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(124, 179, 66, 0.4);
        color: var(--white);
    }
    .empty-results { text-align: center; padding: 60px 20px; }
    .empty-results .empty-icon {
        width: 100px;
        height: 100px;
        background: var(--primary-soft);
        border-radius: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
    }
    .empty-results .empty-icon i { font-size: 2.5rem; color: var(--primary-color); }
</style>

<div class="result-toolbar">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Select Course</label>
            <select name="course" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select Course --</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['course_id']; ?>" <?php echo $course_id == $c['course_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Session</label>
            <input type="text" class="form-control" value="<?php echo $current_session['session_year'] ?? date('Y'); ?>" readonly>
            <input type="hidden" name="session_year" value="<?php echo $current_session['session_year'] ?? date('Y'); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Semester</label>
            <input type="text" class="form-control" value="Semester <?php echo $current_session['semester'] ?? 1; ?>" readonly>
            <input type="hidden" name="semester" value="<?php echo $current_session['semester'] ?? 1; ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">&nbsp;</label>
            <button type="submit" class="btn btn-primary-custom w-100">
                <i class="fas fa-sync me-2"></i> Refresh
            </button>
        </div>
    </form>
</div>

<?php if ($course_id > 0 && $course): ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5>
            <i class="fas fa-book text-primary me-2"></i>
            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']); ?>
            <span class="badge bg-secondary ms-2"><?php echo count($students); ?> Students</span>
        </h5>
        <div>
            <button class="btn btn-outline-custom btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <button class="btn btn-outline-custom btn-sm" onclick="exportResults()">
                <i class="fas fa-file-export me-1"></i> Export
            </button>
        </div>
    </div>
    
    <?php if (!empty($students)): ?>
        <form method="POST" action="">
            <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
            <input type="hidden" name="session_year" value="<?php echo $current_session['session_year'] ?? date('Y'); ?>">
            <input type="hidden" name="semester" value="<?php echo $current_session['semester'] ?? 1; ?>">
            <input type="hidden" name="level" value="<?php echo $course['level'] ?? 100; ?>">
            <input type="hidden" name="save_results" value="1">
            
            <div class="result-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="text-align: left;">Student</th>
                            <th>Matric No.</th>
                            <th>CA (40)</th>
                            <th>Exam (60)</th>
                            <th>Total</th>
                            <th>Grade</th>
                            <th>Points</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 0;
                        foreach ($students as $student): 
                            $counter++;
                            $result = $results_by_student[$student['student_id']] ?? null;
                            $ca = $result['ca_score'] ?? '';
                            $exam = $result['exam_score'] ?? '';
                            $total = $result['total_score'] ?? '';
                            $grade = $result['grade'] ?? '';
                            $points = $result['grade_points'] ?? '';
                            $remark = $result['grade_remark'] ?? '';
                            $is_published = $result['is_published'] ?? 0;
                            $result_id = $result['result_id'] ?? 0;
                            $name = $student['first_name'] . ' ' . $student['last_name'];
                            $initial = strtoupper(substr($student['first_name'], 0, 1));
                            
                            $grade_class = $grade ? 'grade-' . $grade : '';
                        ?>
                        <tr>
                            <td><?php echo $counter; ?></td>
                            <td style="text-align: left;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="student-avatar-small" style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-soft); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; color: var(--primary-color);">
                                        <?php echo $initial; ?>
                                    </div>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($name); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                            <td>
                                <input type="number" name="ca_score[<?php echo $student['student_id']; ?>]" 
                                       value="<?php echo $ca; ?>" min="0" max="40" step="0.5"
                                       class="form-control form-control-sm text-center"
                                       <?php echo $is_published ? 'readonly' : ''; ?>
                                       onchange="calculateTotal(this)">
                            </td>
                            <td>
                                <input type="number" name="exam_score[<?php echo $student['student_id']; ?>]" 
                                       value="<?php echo $exam; ?>" min="0" max="60" step="0.5"
                                       class="form-control form-control-sm text-center"
                                       <?php echo $is_published ? 'readonly' : ''; ?>
                                       onchange="calculateTotal(this)">
                            </td>
                            <td>
                                <span class="fw-bold" id="total_<?php echo $student['student_id']; ?>">
                                    <?php echo $total ? number_format($total, 1) : '-'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($grade): ?>
                                    <span class="grade-badge <?php echo $grade_class; ?>">
                                        <?php echo htmlspecialchars($grade); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo $points ? number_format($points, 2) : '-'; ?></td>
                            <td>
                                <?php if ($is_published): ?>
                                    <span class="badge bg-success">Published</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Draft</span>
                                <?php endif; ?>
                            </td>
                            <input type="hidden" name="result_id[<?php echo $student['student_id']; ?>]" value="<?php echo $result_id; ?>">
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                <div class="text-muted" style="font-size: 0.85rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    CA = Continuous Assessment (max 40), Exam = Examination (max 60)
                </div>
                <div class="d-flex gap-3">
                    <?php 
                    // Check if any results are published
                    $has_published = false;
                    foreach ($results_by_student as $r) {
                        if ($r['is_published'] ?? 0) {
                            $has_published = true;
                            break;
                        }
                    }
                    ?>
                    <?php if (!$has_published && !empty($results_by_student)): ?>
                        <a href="?publish=<?php echo $course_id; ?>" class="btn-publish-results" 
                           onclick="return confirm('Are you sure you want to publish all results for this course? This action cannot be undone.')">
                            <i class="fas fa-globe me-2"></i> Publish Results
                        </a>
                    <?php endif; ?>
                    <?php if (!$has_published): ?>
                        <button type="submit" class="btn-save-results">
                            <i class="fas fa-save me-2"></i> Save Results
                        </button>
                    <?php else: ?>
                        <span class="badge bg-success p-3">
                            <i class="fas fa-check-circle me-2"></i> Results Published
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        
        <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-results">
            <div class="empty-icon">
                <i class="fas fa-user-graduate"></i>
            </div>
            <h5>No Students Enrolled</h5>
            <p class="text-muted">There are no students enrolled in this course yet.</p>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <div class="empty-results">
        <div class="empty-icon">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <h5>Select a Course</h5>
        <p class="text-muted">Please select a course from the dropdown above to manage results.</p>
    </div>
<?php endif; ?>

<script>
    function calculateTotal(input) {
        const row = input.closest('tr');
        const caInput = row.querySelector('input[name^="ca_score"]');
        const examInput = row.querySelector('input[name^="exam_score"]');
        const totalSpan = row.querySelector('span[id^="total_"]');
        
        const ca = parseFloat(caInput.value) || 0;
        const exam = parseFloat(examInput.value) || 0;
        const total = ca + exam;
        
        totalSpan.textContent = total.toFixed(1);
        
        // Auto-update grade (simple client-side preview)
        const gradeSpan = row.querySelector('td:nth-child(7) span');
        if (gradeSpan) {
            let grade = 'F';
            if (total >= 70) grade = 'A';
            else if (total >= 60) grade = 'B';
            else if (total >= 50) grade = 'C';
            else if (total >= 45) grade = 'D';
            else if (total >= 40) grade = 'E';
            
            gradeSpan.textContent = grade;
            gradeSpan.className = 'grade-badge grade-' + grade;
        }
    }
    
    function exportResults() {
        alert('Export functionality will be implemented here.');
        // You can implement CSV/Excel export
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>