<?php
require_once 'includes/header.php';

// Set page title
$page_title = 'Dashboard';

// Get dashboard statistics
try {
    // Total Students
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'Active'");
    $total_students = $stmt->fetch()['total'] ?? 0;
    
    // New students this month
    $stmt = $pdo->query("
        SELECT COUNT(*) as new_students 
        FROM students 
        WHERE status = 'Active' 
        AND MONTH(registration_date) = MONTH(CURRENT_DATE())
        AND YEAR(registration_date) = YEAR(CURRENT_DATE())
    ");
    $new_students = $stmt->fetch()['new_students'] ?? 0;
    
    // Total Staff
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM admin_users WHERE status = 'Active'");
    $total_staff = $stmt->fetch()['total'] ?? 0;
    
    // Total Departments
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM departments");
    $total_departments = $stmt->fetch()['total'] ?? 0;
    
    // Monthly Revenue - Fixed table name from 'payments' to 'student_fees'
    $stmt = $pdo->query("
        SELECT SUM(amount_paid) as total 
        FROM student_fees 
        WHERE MONTH(updated_date) = MONTH(CURRENT_DATE())
        AND YEAR(updated_date) = YEAR(CURRENT_DATE())
        AND status = 'Paid'
    ");
    $total_payments = $stmt->fetch()['total'] ?? 0;
    
    // Current Session
    $stmt = $pdo->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
    $current_session_data = $stmt->fetch();
    $current_session = $current_session_data['session_year'] ?? '2025/2026';
    $current_semester = $current_session_data['semester'] ?? 1;
    
    // Registration Stats - Using results table for course registrations
    $registration_stats = ['approved_registrations' => 0, 'pending_approvals' => 0];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM results 
        WHERE session_year = ? AND semester = ? AND is_published = 1
    ");
    $stmt->execute([$current_session, $current_semester]);
    $registration_stats['approved_registrations'] = $stmt->fetch()['count'] ?? 0;
    
    // Get admin's last login
    $admin_id = $_SESSION['admin_id'] ?? 0;
    $last_login = null;
    if ($admin_id) {
        $stmt = $pdo->prepare("SELECT last_login FROM admin_users WHERE admin_id = ?");
        $stmt->execute([$admin_id]);
        $admin_data = $stmt->fetch();
        $last_login = $admin_data['last_login'] ?? null;
    }
    
    // Quick stats queries - Adjusted to match your schema
    $quick_stats = [
        ['title' => 'Pending Fee Payments', 'sql' => "SELECT COUNT(*) FROM student_fees WHERE status IN ('Pending','Partial')", 'icon'=>'fas fa-clock','color'=>'warning'],
        ['title' => 'Unverified Payments', 'sql' => "SELECT COUNT(*) FROM payments WHERE status='Pending'", 'icon'=>'fas fa-hourglass-half','color'=>'info'],
        ['title' => "Today's Logins", 'sql'=>"SELECT COUNT(*) FROM students WHERE DATE(last_login)=CURDATE()", 'icon'=>'fas fa-users','color'=>'success'],
        ['title' => 'Active Hostels', 'sql'=>"SELECT COUNT(*) FROM hostels WHERE status='Available'", 'icon'=>'fas fa-building','color'=>'primary'],
        ['title' => 'Available Rooms', 'sql'=>"SELECT COUNT(*) FROM hostel_rooms WHERE status='Available'", 'icon'=>'fas fa-door-open','color'=>'success'],
        ['title' => 'Pending Transcripts', 'sql'=>"SELECT COUNT(*) FROM transcripts WHERE status='Pending'", 'icon'=>'fas fa-file-alt','color'=>'danger']
    ];
    
    // Recent Activities - Using admin_logs table
    $stmt = $pdo->query("
        SELECT al.*, au.full_name 
        FROM admin_logs al 
        LEFT JOIN admin_users au ON al.admin_id = au.admin_id 
        ORDER BY al.created_at DESC 
        LIMIT 5
    ");
    $recent_activities = $stmt->fetchAll();
    
    // Recent Notifications
    $stmt = $pdo->query("
        SELECT * FROM notifications 
        ORDER BY sent_date DESC 
        LIMIT 5
    ");
    $recent_notifications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // Default values
    $total_students = $total_staff = $total_departments = $total_payments = 0;
    $new_students = 0;
    $current_session = '2025/2026';
    $current_semester = 1;
    $registration_stats = ['approved_registrations' => 0, 'pending_approvals' => 0];
    $last_login = null;
    $quick_stats = [];
    $recent_activities = [];
    $recent_notifications = [];
}
?>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="app-page-title mb-0">Dashboard</h1>
                <div class="app-card alert alert-dismissible shadow-sm border-left-decoration d-inline-block" role="alert">
                    <div class="app-card-body p-2 p-lg-3">
                        <span class="badge bg-info me-2">Session: <?php echo htmlspecialchars($current_session); ?></span>
                        <span class="badge bg-warning">Semester: <?php echo htmlspecialchars($current_semester); ?></span>
                        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            </div>

            <!-- Welcome Alert -->
            <div class="app-card alert alert-dismissible shadow-sm mb-4 border-left-decoration" role="alert">
                <div class="inner">
                    <div class="app-card-body p-3 p-lg-4">
                        <div class="row align-items-center">
                            <div class="col-md-9">
                                <h3 class="mb-2">Welcome back, <?php echo htmlspecialchars($admin_name); ?>!</h3>
                                <p class="mb-0">
                                    Last login: <?php echo isset($last_login) ? date('F j, Y', strtotime($last_login)) . ' at ' . date('h:i A', strtotime($last_login)) : 'Never'; ?> | 
                                    Role: <?php echo htmlspecialchars($admin_role); ?>
                                </p>
                            </div>
                            <div class="col-md-3 text-md-end mt-2 mt-md-0">
                                <button class="btn app-btn-primary" data-bs-toggle="modal" data-bs-target="#quickStatsModal">
                                    <svg width="16" height="16" fill="currentColor" class="bi bi-bar-chart me-2" viewBox="0 0 16 16">
                                        <path d="M4 11H2v3h2v-3zm5-4H7v7h2V7zm5-5h-2v12h2V2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1h-2zM6 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7zm-5 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1v-3z"/>
                                    </svg>
                                    Quick Stats
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row g-4 mb-4">
                <!-- Total Students -->
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat shadow-sm h-100">
                        <div class="app-card-body p-3 p-lg-4">
                            <h4 class="stats-type mb-1">Total Students</h4>
                            <div class="stats-figure"><?php echo number_format($total_students); ?></div>
                            <div class="stats-meta text-success">
                                <svg width="1em" height="1em" viewBox="0 0 16 16" class="bi bi-arrow-up" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5z"/>
                                </svg>
                                <?php echo number_format($new_students); ?> new this month
                            </div>
                            <div class="stats-detail mt-2">Active: <?php echo number_format($total_students); ?></div>
                        </div>
                        <a class="app-card-link-mask" href="manage_students.php"></a>
                    </div>
                </div>

                <!-- Total Staff -->
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat shadow-sm h-100">
                        <div class="app-card-body p-3 p-lg-4">
                            <h4 class="stats-type mb-1">Total Staff</h4>
                            <div class="stats-figure"><?php echo number_format($total_staff); ?></div>
                            <div class="stats-meta text-info">
                                <i class="fas fa-chalkboard-teacher"></i> All Active
                            </div>
                            <div class="stats-detail mt-2">Manage staff records</div>
                        </div>
                        <a class="app-card-link-mask" href="manage_staffs.php"></a>
                    </div>
                </div>

                <!-- Total Departments -->
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat shadow-sm h-100">
                        <div class="app-card-body p-3 p-lg-4">
                            <h4 class="stats-type mb-1">Departments</h4>
                            <div class="stats-figure"><?php echo number_format($total_departments); ?></div>
                            <div class="stats-meta">
                                <span class="badge bg-success">All Active</span>
                            </div>
                            <div class="stats-detail mt-2">Manage academic structure</div>
                        </div>
                        <a class="app-card-link-mask" href="departments.php"></a>
                    </div>
                </div>

                <!-- Total Payments -->
                <div class="col-6 col-lg-3">
                    <div class="app-card app-card-stat shadow-sm h-100">
                        <div class="app-card-body p-3 p-lg-4">
                            <h4 class="stats-type mb-1">Monthly Revenue</h4>
                            <div class="stats-figure">₦<?php echo number_format($total_payments, 2); ?></div>
                            <div class="stats-meta text-info"><?php echo date('F Y'); ?></div>
                            <div class="stats-detail mt-2">Verified payments only</div>
                        </div>
                        <a class="app-card-link-mask" href="payments.php"></a>
                    </div>
                </div>
            </div>

            <!-- Registration Progress & Quick Stats -->
            <div class="row g-4 mb-4">
                <!-- Registration Progress -->
                <div class="col-12 col-lg-6">
                    <div class="app-card app-card-chart h-100 shadow-sm">
                        <div class="app-card-header p-3 border-bottom-0">
                            <div class="row justify-content-between align-items-center">
                                <div class="col-auto">
                                    <h4 class="app-card-title">Course Results Progress</h4>
                                </div>
                                <div class="col-auto">
                                    <span class="badge bg-primary">Semester <?php echo htmlspecialchars($current_semester); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="app-card-body p-3 p-lg-4">
                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="text-muted small">Total Students</div>
                                    <div class="h5 mb-0"><?php echo number_format($total_students); ?></div>
                                </div>
                                <div class="col-6 text-end">
                                    <div class="text-muted small">Results Published</div>
                                    <div class="h5 mb-0"><?php echo number_format($registration_stats['approved_registrations']); ?></div>
                                </div>
                            </div>

                            <div class="progress-list">
                                <?php 
                                $overall_percent = $total_students > 0 ? round(($registration_stats['approved_registrations'] / $total_students) * 100) : 0;
                                ?>
                                <!-- Overall Progress -->
                                <div class="progress-item mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="small">Results Publication</span>
                                        <span class="small"><?php echo $overall_percent; ?>%</span>
                                    </div>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width:<?php echo $overall_percent; ?>%"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center mt-4">
                                <a href="upload_results.php" class="btn app-btn-outline-primary btn-sm">
                                    <i class="fas fa-upload me-1"></i>Upload Results
                                </a>
                                <a href="approve_results.php" class="btn app-btn-outline-warning btn-sm ms-2">
                                    <i class="fas fa-check-circle me-1"></i>Approve Results
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Table -->
                <div class="col-12 col-lg-6">
                    <div class="app-card app-card-stats-table h-100 shadow-sm">
                        <div class="app-card-header p-3">
                            <div class="row justify-content-between align-items-center">
                                <div class="col-auto">
                                    <h4 class="app-card-title">Quick Statistics</h4>
                                </div>
                                <div class="col-auto">
                                    <span class="badge bg-info">Live Data</span>
                                </div>
                            </div>
                        </div>
                        <div class="app-card-body p-3 p-lg-4">
                            <div class="table-responsive">
                                <table class="table table-borderless mb-0">
                                    <tbody>
                                        <?php foreach($quick_stats as $stat): ?>
                                            <?php 
                                            try {
                                                $value = getQuickStat($pdo, $stat['sql']); 
                                            } catch (Exception $e) {
                                                $value = 0;
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <i class="<?php echo $stat['icon']; ?> me-2 text-<?php echo $stat['color']; ?>"></i>
                                                    <?php echo htmlspecialchars($stat['title']); ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-<?php echo $stat['color']; ?>">
                                                        <?php echo number_format($value); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Quick Actions -->
                            <div class="mt-4 pt-3 border-top">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <a href="generate_invoices.php" class="btn app-btn-outline-primary w-100 btn-sm">
                                            <i class="fas fa-file-invoice me-1"></i>Generate Invoices
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="notifications.php" class="btn app-btn-outline-success w-100 btn-sm">
                                            <i class="fas fa-bullhorn me-1"></i>Send Notification
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions & Recent Activity -->
            <div class="row g-4 mb-4">
                <!-- Quick Actions -->
                <div class="col-12 col-lg-4">
                    <div class="app-card app-card-basic d-flex flex-column align-items-start shadow-sm h-100">
                        <div class="app-card-header p-3 border-bottom-0">
                            <div class="row align-items-center gx-3">
                                <div class="col-auto">
                                    <div class="app-icon-holder icon-holder-lg bg-primary">
                                        <i class="fas fa-bolt text-white"></i>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <h4 class="app-card-title">Quick Actions</h4>
                                </div>
                            </div>
                        </div>
                        <div class="app-card-body px-4 py-3">
                            <div class="list-group list-group-flush">
                                <a href="add_student.php" class="list-group-item list-group-item-action border-0 py-2">
                                    <i class="fas fa-user-plus me-2 text-primary"></i>
                                    Add New Student
                                </a>
                                <a href="add_staff.php" class="list-group-item list-group-item-action border-0 py-2">
                                    <i class="fas fa-chalkboard-teacher me-2 text-info"></i>
                                    Add New Staff
                                </a>
                                <a href="courses.php" class="list-group-item list-group-item-action border-0 py-2">
                                    <i class="fas fa-book me-2 text-success"></i>
                                    Manage Courses
                                </a>
                                <a href="fee_structure.php" class="list-group-item list-group-item-action border-0 py-2">
                                    <i class="fas fa-money-bill-wave me-2 text-warning"></i>
                                    Update Fee Structure
                                </a>
                                <a href="hostels.php" class="list-group-item list-group-item-action border-0 py-2">
                                    <i class="fas fa-building me-2 text-secondary"></i>
                                    Manage Hostels
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="col-12 col-lg-8">
                    <div class="app-card app-card-activity shadow-sm h-100">
                        <div class="app-card-header p-3">
                            <div class="row justify-content-between align-items-center">
                                <div class="col-auto">
                                    <h4 class="app-card-title">Recent Activities</h4>
                                </div>
                                <div class="col-auto">
                                    <a href="admin_logs.php" class="btn btn-sm app-btn-outline-primary">View All</a>
                                </div>
                            </div>
                        </div>
                        <div class="app-card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity):
                                        $time_ago = timeAgo($activity['created_at']);
                                    ?>
                                    <div class="list-group-item border-0 py-3">
                                        <div class="d-flex align-items-center">
                                            <div class="app-icon-holder icon-holder-sm bg-info me-3">
                                                <i class="fas fa-history text-white"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($activity['action']); ?></h6>
                                                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($activity['description'] ?? 'No description'); ?></p>
                                                <span class="small text-muted">
                                                    By <?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?> • 
                                                    <?php echo $time_ago; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="list-group-item border-0 py-3 text-center text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        No recent activities found.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Notifications -->
            <div class="row g-4">
                <div class="col-12">
                    <div class="app-card app-card-events shadow-sm">
                        <div class="app-card-header p-3 border-bottom-0">
                            <div class="row justify-content-between align-items-center">
                                <div class="col-auto">
                                    <h4 class="app-card-title">Recent Notifications</h4>
                                </div>
                                <div class="col-auto">
                                    <a href="notifications.php" class="btn btn-sm app-btn-outline-primary">View All</a>
                                </div>
                            </div>
                        </div>
                        <div class="app-card-body p-0">
                            <div class="table-responsive">
                                <table class="table app-table-hover mb-0 text-left">
                                    <thead>
                                        <tr>
                                            <th class="cell">Notification</th>
                                            <th class="cell">Type</th>
                                            <th class="cell">Date</th>
                                            <th class="cell">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_notifications)): ?>
                                            <?php foreach ($recent_notifications as $notification):
                                                $sent_date = date('M d, Y', strtotime($notification['sent_date']));
                                                $status_class = $notification['is_read'] ? 'bg-success' : 'bg-warning';
                                                $status_text = $notification['is_read'] ? 'Read' : 'Unread';
                                            ?>
                                            <tr>
                                                <td class="cell"><?php echo htmlspecialchars($notification['title']); ?></td>
                                                <td class="cell">
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($notification['notification_type']); ?></span>
                                                </td>
                                                <td class="cell"><?php echo $sent_date; ?></td>
                                                <td class="cell">
                                                    <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-4 text-muted">
                                                    <i class="fas fa-bell-slash me-1"></i>
                                                    No recent notifications.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

<!-- Quick Stats Modal -->
<div class="modal fade" id="quickStatsModal" tabindex="-1" aria-labelledby="quickStatsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickStatsModalLabel">System Statistics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">Student Statistics</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                try {
                                    $stmt = $pdo->query("
                                        SELECT 
                                            COUNT(CASE WHEN gender = 'Male' THEN 1 END) as male_students,
                                            COUNT(CASE WHEN gender = 'Female' THEN 1 END) as female_students,
                                            COUNT(CASE WHEN current_level = 100 THEN 1 END) as level_100,
                                            COUNT(CASE WHEN current_level = 200 THEN 1 END) as level_200,
                                            COUNT(CASE WHEN current_level = 300 THEN 1 END) as level_300,
                                            COUNT(CASE WHEN current_level = 400 THEN 1 END) as level_400
                                        FROM students WHERE status = 'Active'
                                    ");
                                    $student_stats = $stmt->fetch();
                                ?>
                                <p><strong>Gender Distribution:</strong></p>
                                <p>Male: <?php echo number_format($student_stats['male_students'] ?? 0); ?></p>
                                <p>Female: <?php echo number_format($student_stats['female_students'] ?? 0); ?></p>
                                
                                <p class="mt-3"><strong>By Level:</strong></p>
                                <p>100 Level: <?php echo number_format($student_stats['level_100'] ?? 0); ?></p>
                                <p>200 Level: <?php echo number_format($student_stats['level_200'] ?? 0); ?></p>
                                <p>300 Level: <?php echo number_format($student_stats['level_300'] ?? 0); ?></p>
                                <p>400 Level: <?php echo number_format($student_stats['level_400'] ?? 0); ?></p>
                                <?php } catch (Exception $e) { ?>
                                    <p class="text-danger">Error loading student statistics</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">Financial Overview</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                try {
                                    $stmt = $pdo->query("
                                        SELECT 
                                            SUM(amount_paid) as total_revenue,
                                            SUM(CASE WHEN MONTH(updated_date) = MONTH(CURRENT_DATE()) AND YEAR(updated_date) = YEAR(CURRENT_DATE()) THEN amount_paid ELSE 0 END) as monthly_revenue,
                                            COUNT(*) as total_transactions
                                        FROM student_fees WHERE status = 'Paid'
                                    ");
                                    $finance_stats = $stmt->fetch();
                                ?>
                                <p><strong>Total Revenue:</strong> ₦<?php echo number_format($finance_stats['total_revenue'] ?? 0, 2); ?></p>
                                <p><strong>This Month:</strong> ₦<?php echo number_format($finance_stats['monthly_revenue'] ?? 0, 2); ?></p>
                                <p><strong>Total Transactions:</strong> <?php echo number_format($finance_stats['total_transactions'] ?? 0); ?></p>
                                
                                <?php 
                                    $stmt = $pdo->query("SELECT COUNT(*) as pending_fees FROM student_fees WHERE status IN ('Pending', 'Partial')");
                                    $pending = $stmt->fetch();
                                ?>
                                <p class="mt-3"><strong>Pending Fees:</strong> <?php echo number_format($pending['pending_fees'] ?? 0); ?></p>
                                <?php } catch (Exception $e) { ?>
                                    <p class="text-danger">Error loading financial statistics</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="reports.php" class="btn btn-primary">View Full Reports</a>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>