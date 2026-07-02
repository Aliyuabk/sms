<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Fetch detailed staff info
$stmt = $pdo->prepare("
    SELECT s.*, d.department_name, r.role_name, r.permissions
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN staff_roles r ON s.staff_role_id = r.role_id
    WHERE s.staff_id = ?
");
$stmt->execute([$staff_id]);
$staff_detail = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch staff dashboard summary
$stmt2 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt2->execute([$staff_id]);
$staff = $stmt2->fetch(PDO::FETCH_ASSOC);

// Fetch recent activity
$stmt3 = $pdo->prepare("
    SELECT * FROM staff_activity_log 
    WHERE staff_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt3->execute([$staff_id]);
$activities = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// Fetch courses taught
$stmt4 = $pdo->prepare("
    SELECT c.course_code, c.course_title, ca.session_year, ca.semester, ca.assigned_date
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE ca.staff_id = ?
    ORDER BY ca.assigned_date DESC
    LIMIT 5
");
$stmt4->execute([$staff_id]);
$recent_courses = $stmt4->fetchAll(PDO::FETCH_ASSOC);

// Handle profile update
$update_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $upd = $pdo->prepare("
            UPDATE staff SET 
                phone = ?, 
                email = ?,
                office_location = ?,
                office_hours = ?,
                qualification = ?,
                specialization = ?,
                notes = ?,
                updated_at = NOW()
            WHERE staff_id = ?
        ");
        $upd->execute([
            $_POST['phone'] ?? $staff_detail['phone'],
            $_POST['email'] ?? $staff_detail['email'],
            $_POST['office_location'] ?? $staff_detail['office_location'],
            $_POST['office_hours'] ?? $staff_detail['office_hours'],
            $_POST['qualification'] ?? $staff_detail['qualification'],
            $_POST['specialization'] ?? $staff_detail['specialization'],
            $_POST['notes'] ?? $staff_detail['notes'],
            $staff_id
        ]);
        $update_msg = 'Profile updated successfully!';

        // Refresh data
        $stmt->execute([$staff_id]);
        $staff_detail = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $update_msg = 'Error: ' . $e->getMessage();
    }
}

// Page variables
$page_title = 'My Profile';
$page_icon = 'fas fa-user';
$active_page = 'profile';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Profile']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .profile-layout {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 25px;
    }
    .profile-sidebar-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.6s ease;
    }
    .profile-cover {
        height: 120px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    }
    .profile-avatar-wrap {
        text-align: center;
        margin-top: -50px;
        padding: 0 20px 20px;
    }
    .profile-avatar-big {
        width: 100px;
        height: 100px;
        border-radius: 24px;
        background: var(--white);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        border: 4px solid var(--white);
        overflow: hidden;
    }
    .profile-avatar-big img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .profile-avatar-big i {
        font-size: 2.5rem;
        color: var(--primary-color);
    }
    .profile-name-sidebar {
        font-size: 1.3rem;
        font-weight: 700;
        margin-top: 15px;
        color: var(--text-dark);
    }
    .profile-role-sidebar {
        color: var(--text-light);
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    .profile-dept-sidebar {
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
    }
    .profile-contact-list {
        padding: 20px;
        border-top: 1px solid var(--gray-200);
    }
    .profile-contact-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid var(--gray-100);
    }
    .profile-contact-item:last-child { border-bottom: none; }
    .profile-contact-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: var(--primary-soft);
        color: var(--primary-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
    }
    .profile-contact-text {
        font-size: 0.9rem;
    }
    .profile-contact-text small {
        display: block;
        color: var(--text-light);
        font-size: 0.75rem;
    }

    .profile-main-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.7s ease;
    }
    .profile-tabs {
        display: flex;
        border-bottom: 1px solid var(--gray-200);
        padding: 0 25px;
    }
    .profile-tab {
        padding: 18px 25px;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-light);
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: var(--transition);
        background: none;
        border-top: none;
        border-left: none;
        border-right: none;
    }
    .profile-tab:hover { color: var(--primary-color); }
    .profile-tab.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
    }
    .profile-tab-content {
        padding: 25px;
        display: none;
    }
    .profile-tab-content.active { display: block; }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    .info-item {
        padding: 15px;
        background: var(--gray-100);
        border-radius: 12px;
    }
    .info-label {
        font-size: 0.8rem;
        color: var(--text-light);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
        font-weight: 600;
    }
    .info-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .form-group-custom {
        margin-bottom: 20px;
    }
    .form-label-custom {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 8px;
    }
    .form-input-custom {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 12px;
        font-size: 0.95rem;
        transition: var(--transition);
    }
    .form-input-custom:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .form-textarea-custom {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 12px;
        font-size: 0.95rem;
        transition: var(--transition);
        min-height: 100px;
        resize: vertical;
    }
    .form-textarea-custom:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }

    .activity-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .activity-item {
        display: flex;
        gap: 15px;
        padding: 15px 0;
        border-bottom: 1px solid var(--gray-100);
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: var(--primary-soft);
        color: var(--primary-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .activity-content h5 {
        font-size: 0.95rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .activity-content p {
        font-size: 0.85rem;
        color: var(--text-light);
        margin: 0;
    }
    .activity-time {
        font-size: 0.75rem;
        color: var(--gray-500);
        margin-left: auto;
        white-space: nowrap;
    }

    .course-history-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        background: var(--gray-100);
        border-radius: 12px;
        margin-bottom: 10px;
        transition: var(--transition);
    }
    .course-history-item:hover {
        background: var(--primary-soft);
        transform: translateX(5px);
    }
    .course-history-info h5 {
        font-size: 0.95rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .course-history-info p {
        font-size: 0.8rem;
        color: var(--text-light);
        margin: 0;
    }
    .course-history-badge {
        background: var(--primary-soft);
        color: var(--primary-color);
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .btn-update {
        padding: 14px 30px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: var(--transition);
    }
    .btn-update:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(63, 116, 156, 0.3);
    }

    .alert-profile {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    .alert-profile-success {
        background: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid var(--success-color);
    }

    @media (max-width: 991px) {
        .profile-layout { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .info-grid { grid-template-columns: 1fr; }
        .profile-tabs { overflow-x: auto; }
    }
</style>

<?php if ($update_msg): ?>
<div class="alert-profile alert-profile-success">
    <i class="fas fa-check-circle me-2"></i><?php echo $update_msg; ?>
</div>
<?php endif; ?>

<div class="profile-layout">
    <!-- Sidebar -->
    <div class="profile-sidebar-card">
        <div class="profile-cover"></div>
        <div class="profile-avatar-wrap">
            <div class="profile-avatar-big">
                <?php if (!empty($staff_detail['profile_image'])): ?>
                    <img src="<?php echo htmlspecialchars($staff_detail['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <i class="fas fa-user-tie"></i>
                <?php endif; ?>
            </div>
            <div class="profile-name-sidebar"><?php echo htmlspecialchars($staff_detail['first_name'] . ' ' . $staff_detail['last_name']); ?></div>
            <div class="profile-role-sidebar"><?php echo htmlspecialchars($staff_detail['role_name'] ?? $staff_detail['role']); ?></div>
            <div class="profile-dept-sidebar"><?php echo htmlspecialchars($staff_detail['department_name'] ?? 'N/A'); ?></div>
        </div>

        <div class="profile-contact-list">
            <div class="profile-contact-item">
                <div class="profile-contact-icon"><i class="fas fa-id-badge"></i></div>
                <div class="profile-contact-text">
                    <small>Staff Number</small>
                    <?php echo htmlspecialchars($staff_detail['staff_number']); ?>
                </div>
            </div>
            <div class="profile-contact-item">
                <div class="profile-contact-icon"><i class="fas fa-envelope"></i></div>
                <div class="profile-contact-text">
                    <small>Email</small>
                    <?php echo htmlspecialchars($staff_detail['email']); ?>
                </div>
            </div>
            <div class="profile-contact-item">
                <div class="profile-contact-icon"><i class="fas fa-phone"></i></div>
                <div class="profile-contact-text">
                    <small>Phone</small>
                    <?php echo htmlspecialchars($staff_detail['phone'] ?? 'N/A'); ?>
                </div>
            </div>
            <div class="profile-contact-item">
                <div class="profile-contact-icon"><i class="fas fa-calendar"></i></div>
                <div class="profile-contact-text">
                    <small>Employment Date</small>
                    <?php echo $staff_detail['employment_date'] ? date('M d, Y', strtotime($staff_detail['employment_date'])) : 'N/A'; ?>
                </div>
            </div>
            <div class="profile-contact-item">
                <div class="profile-contact-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="profile-contact-text">
                    <small>Contract Status</small>
                    <span style="color: <?php echo ($staff_detail['contract_status'] ?? '') === 'Active' ? 'var(--success-color)' : 'var(--danger-color)'; ?>; font-weight: 700;">
                        <?php echo htmlspecialchars($staff_detail['contract_status'] ?? 'N/A'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="profile-main-card">
        <div class="profile-tabs">
            <button class="profile-tab active" onclick="switchTab('overview', this)">
                <i class="fas fa-user me-2"></i>Overview
            </button>
            <button class="profile-tab" onclick="switchTab('edit', this)">
                <i class="fas fa-edit me-2"></i>Edit Profile
            </button>
            <button class="profile-tab" onclick="switchTab('activity', this)">
                <i class="fas fa-history me-2"></i>Activity
            </button>
            <button class="profile-tab" onclick="switchTab('courses', this)">
                <i class="fas fa-book me-2"></i>Courses
            </button>
        </div>

        <!-- Overview Tab -->
        <div class="profile-tab-content active" id="tab-overview">
            <h4 class="mb-4 fw-bold"><i class="fas fa-info-circle text-primary me-2"></i>Personal Information</h4>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['first_name'] . ' ' . ($staff_detail['middle_name'] ?? '') . ' ' . $staff_detail['last_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Gender</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['gender'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Date of Birth</div>
                    <div class="info-value"><?php echo $staff_detail['date_of_birth'] ? date('F d, Y', strtotime($staff_detail['date_of_birth'])) : 'N/A'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Employment Type</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['employment_type'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Designation</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['designation'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Qualification</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['qualification'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Specialization</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['specialization'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Office Location</div>
                    <div class="info-value"><?php echo htmlspecialchars($staff_detail['office_location'] ?? 'N/A'); ?></div>
                </div>
            </div>

            <h4 class="mb-4 mt-5 fw-bold"><i class="fas fa-chart-bar text-primary me-2"></i>Statistics</h4>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Total Courses</div>
                    <div class="info-value"><?php echo $staff['total_courses'] ?? 0; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Total Students</div>
                    <div class="info-value"><?php echo $staff['total_students'] ?? 0; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Last Login</div>
                    <div class="info-value"><?php echo $staff_detail['last_login'] ? date('M d, Y H:i', strtotime($staff_detail['last_login'])) : 'Never'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Member Since</div>
                    <div class="info-value"><?php echo date('F Y', strtotime($staff_detail['created_at'])); ?></div>
                </div>
            </div>
        </div>

        <!-- Edit Tab -->
        <div class="profile-tab-content" id="tab-edit">
            <h4 class="mb-4 fw-bold"><i class="fas fa-edit text-primary me-2"></i>Edit Profile</h4>
            <form method="POST" action="">
                <div class="info-grid">
                    <div class="form-group-custom">
                        <label class="form-label-custom">Phone Number</label>
                        <input type="text" class="form-input-custom" name="phone" value="<?php echo htmlspecialchars($staff_detail['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group-custom">
                        <label class="form-label-custom">Email Address</label>
                        <input type="email" class="form-input-custom" name="email" value="<?php echo htmlspecialchars($staff_detail['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group-custom">
                        <label class="form-label-custom">Office Location</label>
                        <input type="text" class="form-input-custom" name="office_location" value="<?php echo htmlspecialchars($staff_detail['office_location'] ?? ''); ?>">
                    </div>
                    <div class="form-group-custom">
                        <label class="form-label-custom">Office Hours</label>
                        <input type="text" class="form-input-custom" name="office_hours" value="<?php echo htmlspecialchars($staff_detail['office_hours'] ?? ''); ?>">
                    </div>
                    <div class="form-group-custom">
                        <label class="form-label-custom">Qualification</label>
                        <input type="text" class="form-input-custom" name="qualification" value="<?php echo htmlspecialchars($staff_detail['qualification'] ?? ''); ?>">
                    </div>
                    <div class="form-group-custom">
                        <label class="form-label-custom">Specialization</label>
                        <input type="text" class="form-input-custom" name="specialization" value="<?php echo htmlspecialchars($staff_detail['specialization'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group-custom mt-3">
                    <label class="form-label-custom">Notes / Bio</label>
                    <textarea class="form-textarea-custom" name="notes" placeholder="Enter any additional notes..."><?php echo htmlspecialchars($staff_detail['notes'] ?? ''); ?></textarea>
                </div>
                <div class="mt-4">
                    <button type="submit" name="update_profile" class="btn-update">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Activity Tab -->
        <div class="profile-tab-content" id="tab-activity">
            <h4 class="mb-4 fw-bold"><i class="fas fa-history text-primary me-2"></i>Recent Activity</h4>
            <div class="activity-list">
                <?php if (count($activities) > 0): ?>
                    <?php foreach ($activities as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-<?php 
                                echo match($activity['activity_type'] ?? '') {
                                    'Login' => 'sign-in-alt',
                                    'Logout' => 'sign-out-alt',
                                    'Grade Entry' => 'edit',
                                    'Attendance' => 'clipboard-check',
                                    default => 'circle'
                                };
                            ?>"></i>
                        </div>
                        <div class="activity-content">
                            <h5><?php echo htmlspecialchars($activity['activity_type'] ?? 'Activity'); ?></h5>
                            <p><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                        </div>
                        <div class="activity-time">
                            <i class="far fa-clock me-1"></i>
                            <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No recent activity recorded.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Courses Tab -->
        <div class="profile-tab-content" id="tab-courses">
            <h4 class="mb-4 fw-bold"><i class="fas fa-book text-primary me-2"></i>Course History</h4>
            <?php if (count($recent_courses) > 0): ?>
                <?php foreach ($recent_courses as $rc): ?>
                <div class="course-history-item">
                    <div class="course-history-info">
                        <h5><?php echo htmlspecialchars($rc['course_code'] . ' - ' . $rc['course_title']); ?></h5>
                        <p><i class="fas fa-calendar me-1"></i><?php echo $rc['session_year']; ?> - Semester <?php echo $rc['semester']; ?></p>
                    </div>
                    <span class="course-history-badge">
                        <i class="fas fa-clock me-1"></i>
                        <?php echo date('M Y', strtotime($rc['assigned_date'])); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No course assignments found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function switchTab(tabName, btn) {
        document.querySelectorAll('.profile-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.profile-tab-content').forEach(c => c.classList.remove('active'));

        btn.classList.add('active');
        document.getElementById('tab-' + tabName).classList.add('active');
    }
</script> 