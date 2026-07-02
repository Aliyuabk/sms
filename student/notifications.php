<?php
ob_start();
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_date = NOW() WHERE notification_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $_GET['mark_read'], $student_id);
    $stmt->execute();
    header("Location: notifications.php" . (isset($_GET['type']) || isset($_GET['status']) ? '?' . http_build_query(array_intersect_key($_GET, ['type' => 1, 'status' => 1])) : ''));
    exit();
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_date = NOW() WHERE student_id = ? AND is_read = 0");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $_GET['delete'], $student_id);
    $stmt->execute();
    header("Location: notifications.php" . (isset($_GET['type']) || isset($_GET['status']) ? '?' . http_build_query(array_intersect_key($_GET, ['type' => 1, 'status' => 1])) : ''));
    exit();
}

$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

// Build query
$query = "SELECT * FROM notifications WHERE student_id = ?";
$params = [$student_id];
$types = "i";

if ($filter_type != 'all') {
    $query .= " AND notification_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($filter_status == 'unread') {
    $query .= " AND is_read = 0";
} elseif ($filter_status == 'read') {
    $query .= " AND is_read = 1";
}

$query .= " ORDER BY sent_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$notifications = $stmt->get_result();

// Get counts
$count_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
    SUM(CASE WHEN notification_type = 'Academic' THEN 1 ELSE 0 END) as academic,
    SUM(CASE WHEN notification_type = 'Financial' THEN 1 ELSE 0 END) as financial,
    SUM(CASE WHEN notification_type = 'Hostel' THEN 1 ELSE 0 END) as hostel,
    SUM(CASE WHEN notification_type = 'Urgent' THEN 1 ELSE 0 END) as urgent
    FROM notifications WHERE student_id = ?";
$stmt = $conn->prepare($count_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();

// Time ago function - fixed for PHP 8.2+
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' week' . (floor($diff / 604800) > 1 ? 's' : '') . ' ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' month' . (floor($diff / 2592000) > 1 ? 's' : '') . ' ago';
    return floor($diff / 31536000) . ' year' . (floor($diff / 31536000) > 1 ? 's' : '') . ' ago';
}

// Icon map
$icons = [
    'Academic' => 'fa-book',
    'Financial' => 'fa-credit-card',
    'Hostel' => 'fa-home',
    'Urgent' => 'fa-exclamation-circle',
    'General' => 'fa-bell'
];

$colors = [
    'Academic' => 'academic',
    'Financial' => 'financial', 
    'Hostel' => 'hostel',
    'Urgent' => 'urgent',
    'General' => 'general'
];
?>

<div class="dashboard">
    <!-- Header -->
    <div class="dash-header">
        <div>
            <h1>Notifications</h1>
            <p>Stay updated with important announcements</p>
        </div>
        <?php if ($counts['unread'] > 0): ?>
        <a href="?mark_all_read=1" class="btn-mark" onclick="return confirm('Mark all as read?')">
            <i class="fas fa-check-double"></i> Mark All Read
        </a>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <a href="notifications.php" class="stat-box <?php echo $filter_type == 'all' && $filter_status == 'all' ? 'active' : ''; ?>">
            <div class="stat-icon blue"><i class="fas fa-bell"></i></div>
            <div><div class="stat-num"><?php echo $counts['total'] ?: 0; ?></div><div class="stat-label">All</div></div>
        </a>
        <a href="?status=unread" class="stat-box <?php echo $filter_status == 'unread' ? 'active' : ''; ?>">
            <div class="stat-icon orange"><i class="fas fa-envelope"></i></div>
            <div><div class="stat-num"><?php echo $counts['unread'] ?: 0; ?></div><div class="stat-label">Unread</div></div>
        </a>
        <a href="?type=Urgent" class="stat-box <?php echo $filter_type == 'Urgent' ? 'active' : ''; ?>">
            <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
            <div><div class="stat-num"><?php echo $counts['urgent'] ?: 0; ?></div><div class="stat-label">Urgent</div></div>
        </a>
        <a href="?type=Academic" class="stat-box <?php echo $filter_type == 'Academic' ? 'active' : ''; ?>">
            <div class="stat-icon blue"><i class="fas fa-book"></i></div>
            <div><div class="stat-num"><?php echo $counts['academic'] ?: 0; ?></div><div class="stat-label">Academic</div></div>
        </a>
        <a href="?type=Financial" class="stat-box <?php echo $filter_type == 'Financial' ? 'active' : ''; ?>">
            <div class="stat-icon green"><i class="fas fa-wallet"></i></div>
            <div><div class="stat-num"><?php echo $counts['financial'] ?: 0; ?></div><div class="stat-label">Financial</div></div>
        </a>
        <a href="?type=Hostel" class="stat-box <?php echo $filter_type == 'Hostel' ? 'active' : ''; ?>">
            <div class="stat-icon purple"><i class="fas fa-home"></i></div>
            <div><div class="stat-num"><?php echo $counts['hostel'] ?: 0; ?></div><div class="stat-label">Hostel</div></div>
        </a>
    </div>

    <!-- Filters -->
    <div class="filters">
        <select id="typeFilter" onchange="applyFilter()">
            <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
            <option value="Academic" <?php echo $filter_type == 'Academic' ? 'selected' : ''; ?>>Academic</option>
            <option value="Financial" <?php echo $filter_type == 'Financial' ? 'selected' : ''; ?>>Financial</option>
            <option value="Hostel" <?php echo $filter_type == 'Hostel' ? 'selected' : ''; ?>>Hostel</option>
            <option value="Urgent" <?php echo $filter_type == 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
            <option value="General" <?php echo $filter_type == 'General' ? 'selected' : ''; ?>>General</option>
        </select>
        <select id="statusFilter" onchange="applyFilter()">
            <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="unread" <?php echo $filter_status == 'unread' ? 'selected' : ''; ?>>Unread</option>
            <option value="read" <?php echo $filter_status == 'read' ? 'selected' : ''; ?>>Read</option>
        </select>
        <?php if ($filter_type != 'all' || $filter_status != 'all'): ?>
        <a href="notifications.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>

    <!-- Notifications List -->
    <div class="notif-list">
        <?php if ($notifications->num_rows > 0): while ($n = $notifications->fetch_assoc()): 
            $is_unread = $n['is_read'] == 0;
            $type = $n['notification_type'] ?: 'General';
            $icon = $icons[$type] ?? 'fa-bell';
            $color = $colors[$type] ?? 'general';
        ?>
        <div class="notif-item <?php echo $is_unread ? 'unread' : ''; ?> <?php echo $color; ?>">
            <div class="notif-icon"><i class="fas <?php echo $icon; ?>"></i></div>
            <div class="notif-body">
                <div class="notif-top">
                    <h4><?php echo htmlspecialchars($n['title']); ?></h4>
                    <span class="notif-time"><?php echo timeAgo($n['sent_date']); ?></span>
                </div>
                <p><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>
                <div class="notif-meta">
                    <span class="tag <?php echo $color; ?>"><?php echo $type; ?></span>
                    <?php if ($n['priority'] == 'Urgent'): ?><span class="tag urgent">Urgent</span><?php endif; ?>
                    <?php if ($n['action_url']): ?>
                    <a href="<?php echo htmlspecialchars($n['action_url']); ?>" class="link">View Details <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="notif-actions">
                <?php if ($is_unread): ?>
                <a href="?mark_read=<?php echo $n['notification_id']; ?>&<?php echo http_build_query(array_intersect_key($_GET, ['type' => 1, 'status' => 1])); ?>" class="btn-action" title="Mark as read"><i class="fas fa-check"></i></a>
                <?php endif; ?>
                <a href="?delete=<?php echo $n['notification_id']; ?>&<?php echo http_build_query(array_intersect_key($_GET, ['type' => 1, 'status' => 1])); ?>" class="btn-action delete" title="Delete" onclick="return confirm('Delete this notification?')"><i class="fas fa-trash"></i></a>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            <h3>No notifications</h3>
            <p>You're all caught up!</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
:root {
    --blue: #3f749c; --blue-light: #e8f2f8;
    --green: #4caf50; --green-light: #e8f5e9;
    --orange: #ff9800; --orange-light: #fff3e0;
    --red: #f44336; --red-light: #ffebee;
    --purple: #9c27b0; --purple-light: #f3e5f5;
    --text: #2c3e50; --text-light: #6c757d;
    --bg: #f5f7fa; --white: #fff; --border: #e9ecef;
    --radius: 12px;
}

.dashboard { max-width: 900px; margin: 0 auto; padding: 20px; }

/* Header */
.dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.dash-header h1 { font-size: 26px; color: var(--text); margin: 0; }
.dash-header p { color: var(--text-light); margin: 4px 0 0; }
.btn-mark { 
    display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
    background: var(--blue); color: white; text-decoration: none; border-radius: 10px;
    font-size: 14px; font-weight: 500; transition: 0.2s;
}
.btn-mark:hover { background: var(--blue-dark); }

/* Stats */
.stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 24px; }
.stat-box { 
    background: var(--white); border-radius: var(--radius); padding: 14px;
    display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05); transition: 0.2s; border: 2px solid transparent;
}
.stat-box:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.stat-box.active { border-color: var(--blue); }
.stat-icon { 
    width: 38px; height: 38px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; color: white; font-size: 16px; flex-shrink: 0;
}
.stat-icon.blue { background: var(--blue); }
.stat-icon.green { background: var(--green); }
.stat-icon.orange { background: var(--orange); }
.stat-icon.red { background: var(--red); }
.stat-icon.purple { background: var(--purple); }
.stat-num { font-size: 20px; font-weight: 700; color: var(--text); line-height: 1; }
.stat-label { font-size: 12px; color: var(--text-light); }

/* Filters */
.filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.filters select { 
    padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
    font-size: 14px; background: var(--white); min-width: 140px;
}
.filters select:focus { outline: none; border-color: var(--blue); }
.btn-clear { 
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px;
    background: var(--white); border: 1px solid var(--border); border-radius: 8px;
    color: var(--text-light); text-decoration: none; font-size: 14px;
}
.btn-clear:hover { border-color: var(--red); color: var(--red); }

/* Notifications */
.notif-list { display: flex; flex-direction: column; gap: 12px; }
.notif-item { 
    display: flex; gap: 16px; padding: 18px; background: var(--white);
    border-radius: var(--radius); box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    transition: 0.2s; border-left: 3px solid transparent;
}
.notif-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.notif-item.unread { background: var(--blue-light); border-left-color: var(--blue); }
.notif-item.academic { border-left-color: #2196f3; }
.notif-item.financial { border-left-color: var(--green); }
.notif-item.hostel { border-left-color: var(--orange); }
.notif-item.urgent { border-left-color: var(--red); }

.notif-icon { 
    width: 44px; height: 44px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
}
.notif-item.unread .notif-icon { background: var(--blue); color: white; }
.notif-item:not(.unread) .notif-icon { background: var(--bg); color: var(--text-light); }

.notif-body { flex: 1; min-width: 0; }
.notif-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 6px; }
.notif-top h4 { margin: 0; font-size: 15px; color: var(--text); }
.notif-time { font-size: 12px; color: var(--text-light); white-space: nowrap; }
.notif-body p { margin: 0 0 10px; font-size: 14px; color: var(--text-light); line-height: 1.5; }

.notif-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tag { 
    display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px;
    font-weight: 600; text-transform: uppercase;
}
.tag.academic { background: #e3f2fd; color: #1976d2; }
.tag.financial { background: var(--green-light); color: #2e7d32; }
.tag.hostel { background: var(--orange-light); color: #e65100; }
.tag.urgent { background: var(--red-light); color: #c62828; }
.tag.general { background: var(--blue-light); color: var(--blue); }
.link { color: var(--blue); text-decoration: none; font-size: 13px; font-weight: 500; }
.link:hover { text-decoration: underline; }

.notif-actions { display: flex; gap: 6px; opacity: 0; transition: 0.2s; }
.notif-item:hover .notif-actions { opacity: 1; }
.btn-action { 
    width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; color: var(--text-light); text-decoration: none; font-size: 14px;
    transition: 0.2s;
}
.btn-action:hover { background: var(--bg); color: var(--blue); }
.btn-action.delete:hover { color: var(--red); }

/* Empty */
.empty-state { text-align: center; padding: 60px 20px; background: var(--white); border-radius: var(--radius); }
.empty-state i { font-size: 48px; color: var(--border); margin-bottom: 16px; }
.empty-state h3 { margin: 0 0 8px; color: var(--text); }
.empty-state p { margin: 0; color: var(--text-light); }

/* Responsive */
@media (max-width: 768px) {
    .stats-row { grid-template-columns: repeat(3, 1fr); }
    .notif-item { flex-direction: column; gap: 12px; }
    .notif-actions { opacity: 1; justify-content: flex-end; }
    .notif-top { flex-direction: column; gap: 4px; }
}
@media (max-width: 480px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .dash-header { flex-direction: column; align-items: flex-start; }
}
</style>

<script>
function applyFilter() {
    const type = document.getElementById('typeFilter').value;
    const status = document.getElementById('statusFilter').value;
    const params = new URLSearchParams();
    if (type !== 'all') params.set('type', type);
    if (status !== 'all') params.set('status', status);
    window.location.href = 'notifications.php' + (params.toString() ? '?' + params.toString() : '');
}
</script>

<?php require_once 'includes/footer.php'; ?>