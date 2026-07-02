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

if (!$course_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch course details with staff verification
$stmt = $pdo->prepare("
    SELECT c.*, ca.session_year, ca.semester, ca.assignment_id,
           d.department_name, COUNT(cr.student_id) as total_students
    FROM courses c
    JOIN course_assignments ca ON c.course_id = ca.course_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_registrations cr ON c.course_id = cr.course_id 
        AND cr.session_year = ca.session_year 
        AND cr.semester = ca.semester 
        AND cr.registration_status = 'Approved'
    WHERE c.course_id = ? AND ca.staff_id = ? AND ca.status = 'Active'
    GROUP BY c.course_id
");
$stmt->execute([$course_id, $staff_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header('Location: dashboard.php');
    exit;
}

// Fetch enrolled students with their details
$stmt2 = $pdo->prepare("
    SELECT s.student_id, s.matric_number, s.first_name, s.middle_name, s.last_name, 
           s.email, s.phone, s.gender, s.current_level, s.status as student_status,
           s.profile_image, cr.registration_date, cr.attendance_percentage,
           r.ca_score, r.exam_score, r.total_score, r.grade, r.grade_points
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    LEFT JOIN results r ON s.student_id = r.student_id 
        AND r.course_id = ? 
        AND r.session_year = ? 
        AND r.semester = ?
    WHERE cr.course_id = ? 
        AND cr.session_year = ? 
        AND cr.semester = ? 
        AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$stmt2->execute([
    $course_id, $course['session_year'], $course['semester'],
    $course_id, $course['session_year'], $course['semester']
]);
$students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get staff data for header/sidebar
$stmt3 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt3->execute([$staff_id]);
$staff = $stmt3->fetch(PDO::FETCH_ASSOC);

// Calculate stats
$total_students = count($students);
$present_today = 0; // Would need attendance table query
$graded_count = count(array_filter($students, fn($s) => !empty($s['grade'])));
$avg_score = $total_students > 0 ? round(array_sum(array_column($students, 'total_score')) / $total_students, 2) : 0;

// Page variables
$page_title = htmlspecialchars($course['course_code']);
$page_icon = 'fas fa-users';
$active_page = 'courses';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Courses', 'url' => 'courses.php'],
    ['title' => $course['course_code']]
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .course-header-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 35px;
        color: var(--white);
        margin-bottom: 30px;
        animation: fadeInUp 0.6s ease;
    }
    .course-header-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 20px;
    }
    .course-code-big {
        background: var(--secondary-color);
        color: var(--primary-dark);
        padding: 8px 20px;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 800;
        letter-spacing: 1px;
        display: inline-block;
        margin-bottom: 15px;
    }
    .course-title-big {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 15px;
    }
    .course-meta-row {
        display: flex;
        gap: 25px;
        flex-wrap: wrap;
        opacity: 0.9;
    }
    .course-meta-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.95rem;
    }
    .course-actions-top {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .btn-hero {
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.9rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
        border: none;
        cursor: pointer;
    }
    .btn-hero-primary {
        background: var(--secondary-color);
        color: var(--primary-dark);
    }
    .btn-hero-primary:hover {
        background: var(--accent-color);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(197, 234, 79, 0.3);
    }
    .btn-hero-outline {
        background: rgba(255,255,255,0.15);
        color: var(--white);
        border: 1px solid rgba(255,255,255,0.3);
    }
    .btn-hero-outline:hover {
        background: rgba(255,255,255,0.25);
        color: var(--white);
    }

    .filter-bar {
        background: var(--white);
        border-radius: 16px;
        padding: 20px 25px;
        margin-bottom: 25px;
        box-shadow: var(--shadow-sm);
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
        animation: fadeInUp 0.5s ease;
    }
    .filter-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .filter-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-light);
    }
    .filter-input, .filter-select {
        padding: 10px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 0.9rem;
        transition: var(--transition);
        min-width: 180px;
    }
    .filter-input:focus, .filter-select:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .btn-filter {
        padding: 10px 20px;
        background: var(--primary-color);
        color: var(--white);
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }
    .btn-filter:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }
    .btn-export {
        padding: 10px 20px;
        background: var(--success-color);
        color: var(--white);
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-export:hover {
        background: #689f38;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(124, 179, 66, 0.3);
    }

    .students-table-wrap {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.6s ease;
    }
    .students-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .students-table thead th {
        background: var(--gray-100);
        padding: 16px 20px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-light);
        border-bottom: 2px solid var(--gray-200);
        white-space: nowrap;
    }
    .students-table tbody td {
        padding: 16px 20px;
        font-size: 0.9rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
    }
    .students-table tbody tr {
        transition: var(--transition);
    }
    .students-table tbody tr:hover {
        background: var(--primary-soft);
    }
    .student-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .student-table-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    .student-table-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 12px;
    }
    .student-name {
        font-weight: 600;
        color: var(--text-dark);
    }
    .student-matric {
        font-size: 0.8rem;
        color: var(--text-light);
    }
    .badge-status {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .status-active { background: #e8f5e9; color: var(--success-color); }
    .status-inactive { background: #ffebee; color: var(--danger-color); }
    .grade-badge {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-block;
    }
    .grade-a { background: #e8f5e9; color: #2e7d32; }
    .grade-b { background: #e3f2fd; color: #1565c0; }
    .grade-c { background: #fff3e0; color: #ef6c00; }
    .grade-d { background: #fce4ec; color: #c62828; }
    .grade-f { background: #ffebee; color: #b71c1c; }
    .grade-null { background: var(--gray-200); color: var(--gray-500); }
    .score-bar {
        width: 60px;
        height: 6px;
        background: var(--gray-200);
        border-radius: 10px;
        overflow: hidden;
        display: inline-block;
        vertical-align: middle;
        margin-right: 8px;
    }
    .score-fill {
        height: 100%;
        border-radius: 10px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        transition: width 1s ease;
    }
    .attendance-pill {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        background: var(--primary-soft);
        color: var(--primary-color);
    }
    .action-btns {
        display: flex;
        gap: 6px;
    }
    .btn-table {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        color: var(--text-light);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }
    .btn-table:hover {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
    }
    .table-footer {
        padding: 20px;
        background: var(--gray-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .table-info {
        font-size: 0.85rem;
        color: var(--text-light);
    }
    .pagination {
        display: flex;
        gap: 5px;
    }
    .page-btn {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        color: var(--text-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
    }
    .page-btn:hover, .page-btn.active {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
    }

    @media (max-width: 768px) {
        .students-table-wrap { overflow-x: auto; }
        .course-header-top { flex-direction: column; }
        .filter-bar { flex-direction: column; align-items: stretch; }
        .filter-group { width: 100%; }
        .filter-input, .filter-select { width: 100%; }
    }
</style>

<!-- Course Header -->
<div class="course-header-hero">
    <div class="course-header-top">
        <div>
            <span class="course-code-big"><?php echo htmlspecialchars($course['course_code']); ?></span>
            <h1 class="course-title-big"><?php echo htmlspecialchars($course['course_title']); ?></h1>
            <div class="course-meta-row">
                <div class="course-meta-item">
                    <i class="fas fa-building"></i>
                    <?php echo htmlspecialchars($course['department_name'] ?? 'N/A'); ?>
                </div>
                <div class="course-meta-item">
                    <i class="fas fa-layer-group"></i>
                    Level <?php echo $course['level']; ?>
                </div>
                <div class="course-meta-item">
                    <i class="fas fa-star"></i>
                    <?php echo $course['credit_units']; ?> Credit Units
                </div>
                <div class="course-meta-item">
                    <i class="fas fa-calendar"></i>
                    <?php echo $course['session_year']; ?> - Semester <?php echo $course['semester']; ?>
                </div>
                <div class="course-meta-item">
                    <i class="fas fa-users"></i>
                    <?php echo $total_students; ?> Students
                </div>
            </div>
        </div>
        <div class="course-actions-top">
            <a href="take_attendance.php?course=<?php echo $course_id; ?>" class="btn-hero btn-hero-primary">
                <i class="fas fa-clipboard-check"></i>Take Attendance
            </a>
            <a href="upload_results.php?course=<?php echo $course_id; ?>" class="btn-hero btn-hero-outline">
                <i class="fas fa-upload"></i>Upload Results
            </a>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-primary"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-value"><?php echo $total_students; ?></div>
            <div class="stat-label">Total Students</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-success"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="stat-value"><?php echo $present_today; ?></div>
            <div class="stat-label">Present Today</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-info"><i class="fas fa-chart-line"></i></div>
            </div>
            <div class="stat-value"><?php echo $avg_score; ?>%</div>
            <div class="stat-label">Average Score</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon-wrap stat-icon-warning"><i class="fas fa-graduation-cap"></i></div>
            </div>
            <div class="stat-value"><?php echo $graded_count; ?>/<?php echo $total_students; ?></div>
            <div class="stat-label">Results Uploaded</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-search me-1"></i>Search:</span>
        <input type="text" class="filter-input" id="searchStudent" placeholder="Name or matric number...">
    </div>
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-filter me-1"></i>Grade:</span>
        <select class="filter-select" id="filterGrade">
            <option value="">All Grades</option>
            <option value="A">A (Excellent)</option>
            <option value="B">B (Very Good)</option>
            <option value="C">C (Good)</option>
            <option value="D">D (Pass)</option>
            <option value="E">E (Weak Pass)</option>
            <option value="F">F (Fail)</option>
            <option value="null">Not Graded</option>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label"><i class="fas fa-venus-mars me-1"></i>Gender:</span>
        <select class="filter-select" id="filterGender">
            <option value="">All</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
        </select>
    </div>
    <button class="btn-filter" onclick="applyFilters()">
        <i class="fas fa-filter"></i> Filter
    </button>
    <a href="export_class.php?course=<?php echo $course_id; ?>&format=csv" class="btn-export">
        <i class="fas fa-file-csv"></i> Export CSV
    </a>
    <a href="export_class.php?course=<?php echo $course_id; ?>&format=pdf" class="btn-export" style="background: var(--danger-color);">
        <i class="fas fa-file-pdf"></i> Export PDF
    </a>
</div>

<!-- Students Table -->
<div class="students-table-wrap">
    <table class="students-table">
        <thead>
            <tr>
                <th>Student</th>
                <th>Matric No</th>
                <th>Gender</th>
                <th>Level</th>
                <th>Attendance</th>
                <th>CA Score</th>
                <th>Exam Score</th>
                <th>Total</th>
                <th>Grade</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="studentsTableBody">
            <?php foreach ($students as $student): 
                $grade_class = 'grade-null';
                if ($student['grade']) {
                    $grade_class = 'grade-' . strtolower($student['grade']);
                    if ($student['grade'] == 'A') $grade_class = 'grade-a';
                    elseif ($student['grade'] == 'B') $grade_class = 'grade-b';
                    elseif ($student['grade'] == 'C') $grade_class = 'grade-c';
                    elseif ($student['grade'] == 'D' || $student['grade'] == 'E') $grade_class = 'grade-d';
                    elseif ($student['grade'] == 'F') $grade_class = 'grade-f';
                }
                $total_score = $student['total_score'] ?? 0;
            ?>
            <tr data-name="<?php echo strtolower(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])); ?>"
                data-matric="<?php echo strtolower(htmlspecialchars($student['matric_number'])); ?>"
                data-grade="<?php echo $student['grade'] ?? 'null'; ?>"
                data-gender="<?php echo $student['gender'] ?? ''; ?>">
                <td>
                    <div class="student-cell">
                        <div class="student-table-avatar">
                            <?php if (!empty($student['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($student['profile_image']); ?>" alt="">
                            <?php else: ?>
                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="student-name">
                                <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? '')); ?>
                            </div>
                            <div class="student-matric"><?php echo htmlspecialchars($student['email']); ?></div>
                        </div>
                    </div>
                </td>
                <td><strong><?php echo htmlspecialchars($student['matric_number']); ?></strong></td>
                <td>
                    <span class="badge-status status-active">
                        <i class="fas fa-<?php echo $student['gender'] == 'Female' ? 'venus' : 'mars'; ?> me-1"></i>
                        <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?>
                    </span>
                </td>
                <td>Level <?php echo $student['current_level']; ?></td>
                <td>
                    <span class="attendance-pill">
                        <i class="fas fa-clock me-1"></i>
                        <?php echo $student['attendance_percentage'] ?? 0; ?>%
                    </span>
                </td>
                <td>
                    <span class="score-bar"><span class="score-fill" style="width: <?php echo ($student['ca_score'] ?? 0) * 2; ?>%"></span></span>
                    <?php echo $student['ca_score'] ?? '-'; ?>
                </td>
                <td>
                    <span class="score-bar"><span class="score-fill" style="width: <?php echo ($student['exam_score'] ?? 0); ?>%"></span></span>
                    <?php echo $student['exam_score'] ?? '-'; ?>
                </td>
                <td><strong><?php echo $total_score > 0 ? $total_score : '-'; ?></strong></td>
                <td>
                    <span class="grade-badge <?php echo $grade_class; ?>">
                        <?php echo $student['grade'] ?? 'N/A'; ?>
                    </span>
                </td>
                <td>
                    <div class="action-btns">
                        <a href="student_profile.php?id=<?php echo $student['student_id']; ?>" class="btn-table" title="View Profile">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="upload_results.php?course=<?php echo $course_id; ?>&student=<?php echo $student['student_id']; ?>" class="btn-table" title="Enter Result">
                            <i class="fas fa-edit"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-footer">
        <div class="table-info">
            Showing <strong><?php echo $total_students; ?></strong> students 
            | <strong><?php echo $graded_count; ?></strong> graded 
            | <strong><?php echo $total_students - $graded_count; ?></strong> pending
        </div>
        <div class="pagination">
            <a href="#" class="page-btn"><i class="fas fa-chevron-left"></i></a>
            <a href="#" class="page-btn active">1</a>
            <a href="#" class="page-btn"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
</div>

<script>
    function applyFilters() {
        const search = document.getElementById('searchStudent').value.toLowerCase();
        const grade = document.getElementById('filterGrade').value;
        const gender = document.getElementById('filterGender').value;

        document.querySelectorAll('#studentsTableBody tr').forEach(row => {
            const name = row.getAttribute('data-name');
            const matric = row.getAttribute('data-matric');
            const rowGrade = row.getAttribute('data-grade');
            const rowGender = row.getAttribute('data-gender');

            let show = true;

            if (search && !name.includes(search) && !matric.includes(search)) show = false;
            if (grade && rowGrade !== grade) show = false;
            if (gender && rowGender !== gender) show = false;

            row.style.display = show ? '' : 'none';
        });
    }

    document.getElementById('searchStudent').addEventListener('input', applyFilters);
    document.getElementById('filterGrade').addEventListener('change', applyFilters);
    document.getElementById('filterGender').addEventListener('change', applyFilters);

    // Animate score bars
    window.addEventListener('load', () => {
        document.querySelectorAll('.score-fill').forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => { bar.style.width = width; }, 300);
        });
    });
</script>
 