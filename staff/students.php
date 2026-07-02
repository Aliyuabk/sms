<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Get staff data
$stmt = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all students enrolled in staff's courses
$stmt2 = $pdo->prepare("
    SELECT DISTINCT s.student_id, s.matric_number, s.first_name, s.middle_name, s.last_name,
           s.email, s.phone, s.gender, s.current_level, s.status as student_status,
           s.profile_image, s.date_of_birth, s.state_of_origin, s.lga,
           d.department_name, p.program_name,
           GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as courses
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id AND cr.session_year = ca.session_year AND cr.semester = ca.semester
    JOIN courses c ON cr.course_id = c.course_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE ca.staff_id = ? AND ca.status = 'Active' AND cr.registration_status = 'Approved'
    GROUP BY s.student_id
    ORDER BY s.last_name, s.first_name
");
$stmt2->execute([$staff_id]);
$students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Get unique filter values
$levels = array_unique(array_column($students, 'current_level'));
$genders = array_unique(array_column($students, 'gender'));
$departments = array_unique(array_column($students, 'department_name'));

// Page variables
$page_title = 'My Students';
$page_icon = 'fas fa-users';
$active_page = 'students';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Students']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .students-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 35px;
        color: var(--white);
        margin-bottom: 30px;
        animation: fadeInUp 0.6s ease;
    }
    .students-hero h1 {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 10px;
    }
    .students-hero-stats {
        display: flex;
        gap: 25px;
        margin-top: 20px;
        flex-wrap: wrap;
    }
    .students-hero-stat {
        background: rgba(255,255,255,0.15);
        padding: 12px 20px;
        border-radius: 12px;
        backdrop-filter: blur(10px);
    }
    .students-hero-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
    }
    .students-hero-stat-label {
        font-size: 0.8rem;
        opacity: 0.8;
    }

    .students-filter-bar {
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

    .student-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        animation: fadeInUp 0.6s ease backwards;
        border: 1px solid transparent;
    }
    .student-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-lg);
        border-color: var(--primary-soft);
    }
    .student-card-header {
        padding: 25px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-bottom: 1px solid var(--gray-100);
    }
    .student-card-avatar {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .student-card-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 16px;
    }
    .student-card-info h4 {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 4px;
        color: var(--text-dark);
    }
    .student-card-info p {
        font-size: 0.85rem;
        color: var(--text-light);
        margin: 0;
    }
    .student-card-body {
        padding: 20px 25px;
    }
    .student-detail-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid var(--gray-100);
        font-size: 0.9rem;
    }
    .student-detail-row:last-child { border-bottom: none; }
    .student-detail-label {
        color: var(--text-light);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .student-detail-label i { color: var(--primary-light); font-size: 0.8rem; }
    .student-detail-value {
        font-weight: 600;
        color: var(--text-dark);
    }
    .student-card-footer {
        padding: 15px 25px;
        background: var(--gray-100);
        display: flex;
        gap: 10px;
    }
    .btn-student-action {
        flex: 1;
        padding: 10px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .btn-student-primary {
        background: var(--primary-color);
        color: var(--white);
    }
    .btn-student-primary:hover {
        background: var(--primary-dark);
        color: var(--white);
    }
    .btn-student-outline {
        background: var(--white);
        color: var(--primary-color);
        border: 1px solid var(--primary-soft);
    }
    .btn-student-outline:hover {
        background: var(--primary-soft);
        color: var(--primary-dark);
    }

    .gender-badge {
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .gender-male { background: #e3f2fd; color: #1565c0; }
    .gender-female { background: #fce4ec; color: #c62828; }

    .courses-tag {
        display: inline-block;
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        margin: 2px;
    }

    @media (max-width: 768px) {
        .students-filter-bar { flex-direction: column; align-items: stretch; }
        .filter-group { width: 100%; }
        .filter-input, .filter-select { width: 100%; }
    }
</style>

<!-- Hero -->
<div class="students-hero">
    <h1><i class="fas fa-user-graduate me-2"></i>My Students</h1>
    <p>View all students enrolled in your courses across all sessions.</p>
    <div class="students-hero-stats">
        <div class="students-hero-stat">
            <div class="students-hero-stat-value"><?php echo count($students); ?></div>
            <div class="students-hero-stat-label">Total Students</div>
        </div>
        <div class="students-hero-stat">
            <div class="students-hero-stat-value"><?php echo count(array_filter($students, fn($s) => ($s['gender'] ?? '') === 'Male')); ?></div>
            <div class="students-hero-stat-label">Male</div>
        </div>
        <div class="students-hero-stat">
            <div class="students-hero-stat-value"><?php echo count(array_filter($students, fn($s) => ($s['gender'] ?? '') === 'Female')); ?></div>
            <div class="students-hero-stat-label">Female</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="students-filter-bar">
    <div class="filter-group" style="flex: 1;">
        <span class="filter-label"><i class="fas fa-search me-1"></i></span>
        <input type="text" class="filter-select" id="searchStudents" placeholder="Search by name or matric..." style="width: 100%; max-width: 300px;">
    </div>
    <div class="filter-group">
        <span class="filter-label">Level:</span>
        <select class="filter-select" id="filterLevel" onchange="applyStudentFilters()">
            <option value="">All Levels</option>
            <?php foreach ($levels as $level): if($level): ?>
            <option value="<?php echo $level; ?>"><?php echo $level; ?> Level</option>
            <?php endif; endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label">Gender:</span>
        <select class="filter-select" id="filterGender" onchange="applyStudentFilters()">
            <option value="">All</option>
            <?php foreach ($genders as $gender): if($gender): ?>
            <option value="<?php echo $gender; ?>"><?php echo $gender; ?></option>
            <?php endif; endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <span class="filter-label">Department:</span>
        <select class="filter-select" id="filterDept" onchange="applyStudentFilters()">
            <option value="">All</option>
            <?php foreach ($departments as $dept): if($dept): ?>
            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
            <?php endif; endforeach; ?>
        </select>
    </div>
    <a href="export_students.php" class="btn-export">
        <i class="fas fa-file-export"></i> Export
    </a>
</div>

<!-- Students Grid -->
<div class="row g-4" id="studentsGrid">
    <?php if (count($students) > 0): ?>
        <?php foreach ($students as $student): 
            $gender_class = ($student['gender'] ?? '') === 'Female' ? 'gender-female' : 'gender-male';
            $gender_icon = ($student['gender'] ?? '') === 'Female' ? 'fa-venus' : 'fa-mars';
        ?>
        <div class="col-md-6 col-lg-4 col-xl-3 student-item"
             data-name="<?php echo strtolower(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])); ?>"
             data-matric="<?php echo strtolower(htmlspecialchars($student['matric_number'])); ?>"
             data-level="<?php echo $student['current_level']; ?>"
             data-gender="<?php echo $student['gender']; ?>"
             data-dept="<?php echo htmlspecialchars($student['department_name'] ?? ''); ?>">
            <div class="student-card h-100">
                <div class="student-card-header">
                    <div class="student-card-avatar">
                        <?php if (!empty($student['profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($student['profile_image']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="student-card-info" style="flex: 1; min-width: 0;">
                        <h4><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></h4>
                        <p><i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($student['matric_number']); ?></p>
                    </div>
                    <span class="gender-badge <?php echo $gender_class; ?>">
                        <i class="fas <?php echo $gender_icon; ?>"></i>
                    </span>
                </div>

                <div class="student-card-body">
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="student-detail-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($student['email']); ?></span>
                    </div>
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="student-detail-value"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-layer-group"></i> Level</span>
                        <span class="student-detail-value"><?php echo $student['current_level']; ?> Level</span>
                    </div>
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-building"></i> Dept</span>
                        <span class="student-detail-value"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-graduation-cap"></i> Program</span>
                        <span class="student-detail-value"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="student-detail-row">
                        <span class="student-detail-label"><i class="fas fa-book"></i> Courses</span>
                        <span style="text-align: right;">
                            <?php foreach (explode(', ', $student['courses'] ?? '') as $course_code): if($course_code): ?>
                            <span class="courses-tag"><?php echo htmlspecialchars($course_code); ?></span>
                            <?php endif; endforeach; ?>
                        </span>
                    </div>
                </div>

                <div class="student-card-footer">
                    <a href="student_profile.php?id=<?php echo $student['student_id']; ?>" class="btn-student-action btn-student-primary">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <a href="message_student.php?id=<?php echo $student['student_id']; ?>" class="btn-student-action btn-student-outline">
                        <i class="fas fa-envelope"></i> Message
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="empty-courses">
                <div class="empty-courses-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>No Students Found</h3>
                <p>No students are currently enrolled in your courses.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function applyStudentFilters() {
        const search = document.getElementById('searchStudents').value.toLowerCase();
        const level = document.getElementById('filterLevel').value;
        const gender = document.getElementById('filterGender').value;
        const dept = document.getElementById('filterDept').value;

        document.querySelectorAll('.student-item').forEach(item => {
            let show = true;
            const name = item.getAttribute('data-name');
            const matric = item.getAttribute('data-matric');

            if (search && !name.includes(search) && !matric.includes(search)) show = false;
            if (level && item.getAttribute('data-level') !== level) show = false;
            if (gender && item.getAttribute('data-gender') !== gender) show = false;
            if (dept && item.getAttribute('data-dept') !== dept) show = false;

            item.style.display = show ? '' : 'none';
        });
    }

    document.getElementById('searchStudents').addEventListener('input', applyStudentFilters);
</script>
 