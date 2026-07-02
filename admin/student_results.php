<?php
require_once 'includes/header.php';

// Check if student ID is provided
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0);

if ($student_id === 0) {
    header('Location: manage_students.php');
    exit();
}

// Get student details
$student_sql = "SELECT s.*, d.department_name, p.program_name, 
                       CONCAT(s.first_name, ' ', s.last_name) as full_name 
                FROM students s
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN programs p ON s.program_id = p.program_id
                WHERE s.student_id = ?";
$student_stmt = $pdo->prepare($student_sql);
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

if (!$student) {
    $_SESSION['error_message'] = "Student not found!";
    header('Location: manage_students.php');
    exit();
}

// Get all results grouped by session
$results_sql = "SELECT 
                    r.*,
                    c.course_code,
                    c.course_title,
                    c.credit_units,
                    g.remark as grade_remark
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                LEFT JOIN grade_scale g ON r.grade = g.grade
                WHERE r.student_id = ? AND r.is_published = 1
                ORDER BY r.session_year DESC, r.semester DESC, c.course_code";

$results_stmt = $pdo->prepare($results_sql);
$results_stmt->execute([$student_id]);
$all_results = $results_stmt->fetchAll();

// Group results by session
$grouped_results = [];
$session_gpas = [];

foreach ($all_results as $result) {
    $key = $result['session_year'] . '-Sem' . $result['semester'];
    
    if (!isset($grouped_results[$key])) {
        $grouped_results[$key] = [];
    }
    
    $grouped_results[$key][] = $result;
}

// Calculate GPA for each session
foreach ($grouped_results as $session => $courses) {
    $total_quality_points = 0;
    $total_credits = 0;
    
    foreach ($courses as $course) {
        $quality_points = $course['grade_points'] * $course['credit_units'];
        $total_quality_points += $quality_points;
        $total_credits += $course['credit_units'];
    }
    
    $session_gpas[$session] = $total_credits > 0 ? $total_quality_points / $total_credits : 0;
}

// Calculate overall CGPA
$overall_sql = "SELECT 
                    SUM(r.grade_points * c.credit_units) as total_quality_points,
                    SUM(c.credit_units) as total_credits
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? AND r.is_published = 1";

$overall_stmt = $pdo->prepare($overall_sql);
$overall_stmt->execute([$student_id]);
$overall = $overall_stmt->fetch();

$cgpa = $overall['total_credits'] > 0 ? $overall['total_quality_points'] / $overall['total_credits'] : 0;

$page_title = "Student Results - " . $student['full_name'];
?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="app-page-title mb-0">Academic Results</h1>
                <div class="text-muted">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="text-decoration-none">
                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($student['full_name']); ?>
                    </a>
                    • <?php echo htmlspecialchars($student['matric_number']); ?>
                    • <?php echo htmlspecialchars($student['department_name']); ?>
                </div>
            </div>
            <div class="app-actions">
                <button class="btn app-btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addResultModal">
                    <i class="fas fa-plus me-2"></i>Add Result
                </button>
                <!-- <a href="print_transcript.php?id=<?php echo $student_id; ?>" class="btn app-btn-secondary" target="_blank" disabled>
                    <i class="fas fa-print me-2"></i>Print Transcript
                </a> -->
            </div>
        </div>
    </div>
</div>

<!-- Academic Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">CGPA</h4>
                <div class="stats-figure"><?php echo number_format($cgpa, 2); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-chart-line text-primary"></i> Cumulative GPA
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Courses Taken</h4>
                <div class="stats-figure"><?php echo count($all_results); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-book text-info"></i> Total Courses
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Credit Units</h4>
                <div class="stats-figure"><?php echo $overall['total_credits'] ?? 0; ?></div>
                <div class="stats-meta">
                    <i class="fas fa-weight-hanging text-success"></i> Total Credits
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="app-card app-card-stat shadow-sm">
            <div class="app-card-body p-3">
                <h4 class="stats-type mb-2">Academic Sessions</h4>
                <div class="stats-figure"><?php echo count($grouped_results); ?></div>
                <div class="stats-meta">
                    <i class="fas fa-calendar-alt text-warning"></i> Sessions Completed
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($grouped_results)): ?>
    <?php foreach ($grouped_results as $session => $results): ?>
    <div class="app-card app-card-table shadow-sm mb-4">
        <div class="app-card-header p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="app-card-title mb-0"><?php echo str_replace('-Sem', ' - Semester ', $session); ?></h5>
                    <div class="text-muted small">
                        <?php echo count($results); ?> courses • 
                        GPA: <strong><?php echo number_format($session_gpas[$session], 2); ?></strong>
                    </div>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleSession('<?php echo $session; ?>')">
                        <i class="fas fa-chevron-down"></i> Toggle
                    </button>
                </div>
            </div>
        </div>
        
        <div class="app-card-body p-0" id="session-<?php echo $session; ?>">
            <div class="table-responsive">
                <table class="table app-table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th class="text-center">Credit Units</th>
                            <th class="text-center">CA Score</th>
                            <th class="text-center">Exam Score</th>
                            <th class="text-center">Total Score</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Grade Points</th>
                            <th>Remark</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($result['course_code']); ?></strong>
                                <br>
                                <small class="text-muted">Level: <?php echo $result['level']; ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($result['course_title']); ?></td>
                            <td class="text-center"><?php echo $result['credit_units']; ?></td>
                            <td class="text-center"><?php echo $result['ca_score']; ?></td>
                            <td class="text-center"><?php echo $result['exam_score']; ?></td>
                            <td class="text-center">
                                <strong><?php echo $result['total_score']; ?></strong>
                            </td>
                            <td class="text-center">
                                <?php 
                                $grade_color = [
                                    'A' => 'success',
                                    'B' => 'primary',
                                    'C' => 'info',
                                    'D' => 'warning',
                                    'E' => 'secondary',
                                    'F' => 'danger'
                                ][$result['grade']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $grade_color; ?>">
                                    <?php echo $result['grade']; ?>
                                </span>
                            </td>
                            <td class="text-center"><?php echo $result['grade_points']; ?></td>
                            <td><?php echo $result['grade_remark']; ?></td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a class="dropdown-item" href="#" onclick="editResult(<?php echo $result['result_id']; ?>)">
                                                <i class="fas fa-edit me-2"></i>Edit
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="#" onclick="deleteResult(<?php echo $result['result_id']; ?>)">
                                                <i class="fas fa-trash me-2 text-danger"></i>Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Session Summary -->
                        <tr class="table-light">
                            <td colspan="2" class="text-end"><strong>Session Summary:</strong></td>
                            <td class="text-center"><strong><?php echo array_sum(array_column($results, 'credit_units')); ?></strong></td>
                            <td colspan="3"></td>
                            <td class="text-center">
                                <strong>GPA:</strong> <?php echo number_format($session_gpas[$session], 2); ?>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="app-card app-card-table shadow-sm">
        <div class="app-card-body p-5 text-center">
            <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
            <h4>No Results Available</h4>
            <p class="text-muted">This student has no published results yet.</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addResultModal">
                <i class="fas fa-plus me-2"></i>Add First Result
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- Grade Legend -->
<div class="app-card app-card-details shadow-sm mb-4">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">Grade Scale Legend</h5>
    </div>
    <div class="app-card-body p-3">
        <div class="row">
            <?php
            $grade_scale = $pdo->query("SELECT * FROM grade_scale ORDER BY grade_points DESC")->fetchAll();
            foreach ($grade_scale as $grade):
            ?>
            <div class="col-md-2 col-4 mb-2">
                <div class="card text-center border-<?php echo $grade['is_pass'] ? 'success' : 'danger'; ?>">
                    <div class="card-body p-2">
                        <h5 class="card-title mb-1"><?php echo $grade['grade']; ?></h5>
                        <p class="card-text mb-1 small"><?php echo $grade['min_score']; ?>-<?php echo $grade['max_score']; ?>%</p>
                        <span class="badge bg-<?php echo $grade['is_pass'] ? 'success' : 'danger'; ?>">
                            <?php echo $grade['grade_points']; ?> pts
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="app-card app-card-details shadow-sm">
    <div class="app-card-header p-3">
        <h5 class="app-card-title">Quick Actions</h5>
    </div>
    <div class="app-card-body p-3">
        <div class="row g-3">
            <div class="col-md-4">
                <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-outline-primary w-100">
                    <i class="fas fa-user me-2"></i>Student Profile
                </a>
            </div>
            <div class="col-md-4">
                <a href="student_fees.php?id=<?php echo $student_id; ?>" class="btn btn-outline-success w-100">
                    <i class="fas fa-money-bill-wave me-2"></i>Fee Management
                </a>
            </div>
            <div class="col-md-4">
                <a href="course_registrations.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-info w-100">
                    <i class="fas fa-book me-2"></i>Course Registration
                </a>
            </div>
            <div class="col-md-6">
                <a href="manage_students.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
            </div>
            <div class="col-md-6">
                <a href="print_transcript.php?id=<?php echo $student_id; ?>" class="btn btn-outline-warning w-100" target="_blank">
                    <i class="fas fa-print me-2"></i>Print Official Transcript
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Result Modal -->
<div class="modal fade" id="addResultModal" tabindex="-1" aria-labelledby="addResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addResultModalLabel">
                    <i class="fas fa-plus me-2"></i>Add New Result
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="process_result.php">
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Academic Session *</label>
                            <input type="text" class="form-control" name="session_year" required 
                                   value="<?php echo $student['current_session']; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="semester" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="level" required>
                                <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($student['current_level'] == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> Level
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Course *</label>
                        <select class="form-select" name="course_id" id="courseSelect" required>
                            <option value="">Select Course</option>
                            <?php
                            $courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
                            foreach ($courses as $course):
                            ?>
                            <option value="<?php echo $course['course_id']; ?>">
                                <?php echo htmlspecialchars($course['course_code']); ?> - <?php echo htmlspecialchars($course['course_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">CA Score (0-30) *</label>
                            <input type="number" class="form-control" name="ca_score" 
                                   step="0.01" min="0" max="30" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Exam Score (0-70) *</label>
                            <input type="number" class="form-control" name="exam_score" 
                                   step="0.01" min="0" max="70" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea class="form-control" name="remarks" rows="2"></textarea>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_published" id="is_published" checked>
                        <label class="form-check-label" for="is_published">
                            Publish result immediately
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_result" class="btn btn-primary">Add Result</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle session display
function toggleSession(session) {
    const sessionDiv = document.getElementById('session-' + session);
    if (sessionDiv.style.display === 'none') {
        sessionDiv.style.display = '';
    } else {
        sessionDiv.style.display = 'none';
    }
}

// Edit result
function editResult(resultId) {
    window.location.href = 'edit_result.php?id=' + resultId + '&student_id=<?php echo $student_id; ?>';
}

// Delete result
function deleteResult(resultId) {
    if (confirm('Are you sure you want to delete this result? This action cannot be undone.')) {
        window.location.href = 'delete_result.php?id=' + resultId + '&student_id=<?php echo $student_id; ?>';
    }
}

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});
</script>

<?php
require_once 'includes/footer.php';
?>