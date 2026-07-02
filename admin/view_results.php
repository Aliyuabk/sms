<?php
// view_results.php - UPDATED TO MATCH DATABASE SCHEMA
ob_start();

require_once 'includes/header.php';

$page_title = "View Results";

// ==================== DATABASE CONNECTION ====================
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'student_portal_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Get filter parameters
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$course_code = isset($_GET['course_code']) ? trim($_GET['course_code']) : '';
$session_year = isset($_GET['session_year']) ? trim($_GET['session_year']) : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$records_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if ($course_id > 0) {
    $where_conditions[] = "r.course_id = ?";
    $params[] = $course_id;
}

if (!empty($course_code)) {
    $where_conditions[] = "c.course_code = ?";
    $params[] = $course_code;
}

if (!empty($session_year)) {
    $where_conditions[] = "r.session_year = ?";
    $params[] = $session_year;
}

if ($semester > 0) {
    $where_conditions[] = "r.semester = ?";
    $params[] = $semester;
}

if ($level > 0) {
    $where_conditions[] = "r.level = ?";
    $params[] = $level;
}

if ($department_id > 0) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if ($program_id > 0) {
    $where_conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

if (!empty($search)) {
    $where_conditions[] = "(s.matric_number LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR c.course_code LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    JOIN courses c ON r.course_id = c.course_id
    $where_sql
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get results
$results_sql = "
    SELECT r.*,
           s.student_id, s.matric_number, s.first_name, s.last_name, s.middle_name,
           s.current_level, s.department_id, s.program_id, s.email as student_email, s.phone as student_phone,
           c.course_id, c.course_code, c.course_title, c.credit_units,
           d.department_name, d.department_code,
           p.program_name, p.program_code,
           a.full_name as published_by_name,
           CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN admin_users a ON r.published_by = a.admin_id
    $where_sql
    ORDER BY r.created_at DESC, r.session_year DESC, r.semester DESC, s.matric_number
    LIMIT $offset, $records_per_page
";

$results_stmt = $pdo->prepare($results_sql);
$results_stmt->execute($params);
$results = $results_stmt->fetchAll();

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_results,
        COUNT(CASE WHEN r.grade = 'F' THEN 1 END) as failed_count,
        COUNT(CASE WHEN r.is_published = 1 THEN 1 END) as published_count,
        COUNT(CASE WHEN r.is_published = 0 THEN 1 END) as pending_count,
        AVG(r.total_score) as avg_score,
        AVG(r.grade_points) as avg_gpa,
        COUNT(DISTINCT r.course_id) as courses_count,
        COUNT(DISTINCT r.student_id) as students_count
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    $where_sql
";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// Get filter options
$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();

// Get distinct sessions and levels from results
$sessions = $pdo->query("SELECT DISTINCT session_year FROM results ORDER BY session_year DESC")->fetchAll();
$levels_from_results = $pdo->query("SELECT DISTINCT level FROM results ORDER BY level")->fetchAll();
?>

<div class="container-fluid px-4 py-3">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="app-page-title mb-0">
            <i class="fas fa-chart-line me-2 text-primary"></i>View Results
        </h1>
        <div>
            <a href="upload_results.php" class="btn btn-primary">
                <i class="fas fa-upload me-2"></i>Upload Results
            </a>
            <button type="button" class="btn btn-success ms-2" onclick="exportResults()">
                <i class="fas fa-file-excel me-2"></i>Export Results
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Total Results</h6>
                    <h3><?php echo number_format($stats['total_results'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Published</h6>
                    <h3><?php echo number_format($stats['published_count'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Pending</h6>
                    <h3><?php echo number_format($stats['pending_count'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Failed (F)</h6>
                    <h3><?php echo number_format($stats['failed_count'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Avg Score</h6>
                    <h3><?php echo number_format($stats['avg_score'] ?? 0, 1); ?>%</h3>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white">
                <div class="card-body py-2">
                    <h6 class="card-title">Courses/Students</h6>
                    <h3><?php echo ($stats['courses_count'] ?? 0) . '/' . ($stats['students_count'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-transparent">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Matric, name, course...">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Course</label>
                    <select class="form-select" name="course_id">
                        <option value="0">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?php echo $course['course_id']; ?>" 
                                <?php echo $course_id == $course['course_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course['course_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Session</label>
                    <select class="form-select" name="session_year">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $session): ?>
                        <option value="<?php echo htmlspecialchars($session['session_year']); ?>" 
                                <?php echo $session_year == $session['session_year'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($session['session_year']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <label class="form-label">Sem</label>
                    <select class="form-select" name="semester">
                        <option value="0">All</option>
                        <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>1st</option>
                        <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>2nd</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Level</label>
                    <select class="form-select" name="level">
                        <option value="0">All Levels</option>
                        <?php foreach ($levels_from_results as $lvl): ?>
                        <option value="<?php echo $lvl['level']; ?>" <?php echo $level == $lvl['level'] ? 'selected' : ''; ?>>
                            <?php echo $lvl['level']; ?> Level
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="0">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>" 
                                <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_code']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Apply Filters
                        </button>
                        <a href="view_results.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Active Filters Display -->
    <?php if ($course_id > 0 || !empty($session_year) || $semester > 0 || $level > 0 || $department_id > 0 || !empty($search)): ?>
    <div class="mb-3">
        <span class="text-muted small me-2">Active filters:</span>
        <?php if (!empty($search)): ?>
            <span class="badge bg-secondary me-1 p-2">Search: <?php echo htmlspecialchars($search); ?></span>
        <?php endif; ?>
        <?php if ($course_id > 0): ?>
            <?php 
                $course_name = '';
                foreach ($courses as $c) {
                    if ($c['course_id'] == $course_id) {
                        $course_name = $c['course_code'];
                        break;
                    }
                }
            ?>
            <span class="badge bg-secondary me-1 p-2">Course: <?php echo htmlspecialchars($course_name); ?></span>
        <?php endif; ?>
        <?php if (!empty($session_year)): ?>
            <span class="badge bg-secondary me-1 p-2">Session: <?php echo htmlspecialchars($session_year); ?></span>
        <?php endif; ?>
        <?php if ($semester > 0): ?>
            <span class="badge bg-secondary me-1 p-2">Semester: <?php echo $semester == 1 ? '1st' : '2nd'; ?></span>
        <?php endif; ?>
        <?php if ($level > 0): ?>
            <span class="badge bg-secondary me-1 p-2">Level: <?php echo $level; ?></span>
        <?php endif; ?>
        <?php if ($department_id > 0): ?>
            <span class="badge bg-secondary me-1 p-2">Dept: <?php echo htmlspecialchars($department_id); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Results Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-table me-2"></i>Results List</h5>
            <span class="text-muted">Showing <?php echo count($results); ?> of <?php echo number_format($total_records); ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Session</th>
                            <th>Scores</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($results)): ?>
                            <?php foreach ($results as $result): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="avatar-sm rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                <i class="fas fa-user-graduate"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($result['full_name']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($result['matric_number']); ?></div>
                                            <div class="small"><span class="badge bg-info bg-opacity-10 text-info">Level <?php echo $result['current_level']; ?></span></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($result['course_code']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(substr($result['course_title'], 0, 40)) . (strlen($result['course_title'] ?? '') > 40 ? '...' : ''); ?></div>
                                    <div><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo $result['credit_units']; ?> units</span></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($result['session_year']); ?></div>
                                    <div class="small text-muted">Semester <?php echo $result['semester']; ?></div>
                                 </td>
                                 <td>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <small class="text-muted">CA:</small>
                                            <strong><?php echo $result['ca_score']; ?></strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Exam:</small>
                                            <strong><?php echo $result['exam_score']; ?></strong>
                                        </div>
                                        <div class="col-12 mt-1">
                                            <small class="text-muted">Total:</small>
                                            <strong class="text-primary"><?php echo $result['total_score']; ?>%</strong>
                                        </div>
                                    </div>
                                </td>
                                 <td>
                                    <?php 
                                    $grade_class = '';
                                    switch ($result['grade']) {
                                        case 'A': $grade_class = 'success'; break;
                                        case 'B': $grade_class = 'primary'; break;
                                        case 'C': $grade_class = 'info'; break;
                                        case 'D': $grade_class = 'warning'; break;
                                        case 'E': $grade_class = 'secondary'; break;
                                        case 'F': $grade_class = 'danger'; break;
                                        default: $grade_class = 'secondary';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $grade_class; ?> bg-opacity-10 text-<?php echo $grade_class; ?> p-2">
                                        <?php echo $result['grade']; ?> (<?php echo $result['grade_points']; ?>)
                                    </span>
                                    <div class="small text-muted mt-1"><?php echo $result['grade_remark']; ?></div>
                                </td>
                                 <td>
                                    <?php if ($result['is_published']): ?>
                                        <span class="badge bg-success">Published</span>
                                        <div class="small text-muted mt-1">
                                            <?php echo date('d/m/Y', strtotime($result['published_date'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                 <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="edit_result.php?id=<?php echo $result['result_id']; ?>" 
                                           class="btn btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!$result['is_published']): ?>
                                            <button onclick="publishResult(<?php echo $result['result_id']; ?>)" 
                                                    class="btn btn-outline-success" title="Approve">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="view_student_results.php?student_id=<?php echo $result['student_id']; ?>" 
                                           class="btn btn-outline-info" title="Student Results">
                                            <i class="fas fa-list"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                    <h5>No results found</h5>
                                    <p class="text-muted">Try adjusting your filters or upload results first.</p>
                                    <a href="upload_results.php" class="btn btn-primary mt-2">
                                        <i class="fas fa-upload me-2"></i>Upload Results
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-transparent">
            <nav>
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => 0])); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php 
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=1&<?php echo http_build_query(array_diff_key($_GET, ['page' => 0])); ?>">1</a></li>
                        <?php if ($start_page > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => 0])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => 0])); ?>"><?php echo $total_pages; ?></a></li>
                    <?php endif; ?>
                    
                    <li class="page-item <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => 0])); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function exportResults() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.set('format', 'csv');
    window.location.href = 'export_results.php?' + params.toString();
}

function publishResult(resultId) {
    if (confirm('Are you sure you want to publish this result? Students will be able to see it.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'approve_results.php';
        form.innerHTML = `
            <input type="hidden" name="result_id" value="${resultId}">
            <input type="hidden" name="action" value="publish">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-submit on filter change (optional)
// document.querySelectorAll('#filterForm select').forEach(select => {
//     select.addEventListener('change', () => document.getElementById('filterForm').submit());
// });
</script>

<style>
.avatar-sm {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}
.table-hover tbody tr:hover {
    background-color: rgba(67, 97, 238, 0.05);
}
.card {
    border-radius: 12px;
}
.badge.bg-success.bg-opacity-10 {
    background-color: rgba(40, 167, 69, 0.1) !important;
}
.badge.bg-primary.bg-opacity-10 {
    background-color: rgba(13, 110, 253, 0.1) !important;
}
.badge.bg-info.bg-opacity-10 {
    background-color: rgba(23, 162, 184, 0.1) !important;
}
.badge.bg-warning.bg-opacity-10 {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
.badge.bg-danger.bg-opacity-10 {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
.badge.bg-secondary.bg-opacity-10 {
    background-color: rgba(108, 117, 125, 0.1) !important;
}
</style>

<?php
require_once 'includes/footer.php';
?>