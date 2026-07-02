<?php
/**
 * Staff Notifications Page
 * View and manage notifications
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
$action = $_GET['action'] ?? '';
$notification_id = $_GET['id'] ?? 0;

// Handle notification actions
if ($action === 'mark_read' && $notification_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_date = NOW() WHERE notification_id = ?");
    $stmt->execute([$notification_id]);
    header('Location: notifications.php');
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_date = NOW() WHERE student_id IS NULL OR student_id = ?");
    $stmt->execute([$staff_id]);
    header('Location: notifications.php');
    exit;
}

if ($action === 'delete' && $notification_id) {
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ?");
    $stmt->execute([$notification_id]);
    header('Location: notifications.php');
    exit;
}

// Get notifications - staff notifications (student_id IS NULL) or specific staff
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE student_id IS NULL 
    ORDER BY sent_date DESC 
    LIMIT 50
");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$unreadStmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE student_id IS NULL AND is_read = 0
");
$unreadStmt->execute();
$unread_count = $unreadStmt->fetch(PDO::FETCH_ASSOC)['count'];

$page_title = 'Notifications';
$page_icon = 'fas fa-bell';
$active_page = 'notifications';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Notifications']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .notification-card {
        background: var(--white);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 20px;
        transition: var(--transition);
        border: 1px solid var(--gray-200);
    }
    .notification-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    .notification-card.unread {
        border-left: 4px solid var(--primary-color);
        background: var(--primary-soft);
    }
    .notification-card .notification-body {
        padding: 20px 25px;
        display: flex;
        gap: 15px;
        align-items: flex-start;
    }
    .notification-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .notification-icon.academic { background: #e3f2fd; color: #1565c0; }
    .notification-icon.financial { background: #e8f5e9; color: #2e7d32; }
    .notification-icon.hostel { background: #fff3e0; color: #e65100; }
    .notification-icon.general { background: #f3e5f5; color: #6a1b9a; }
    .notification-icon.urgent { background: #ffebee; color: #c62828; }
    
    .notification-content { flex: 1; }
    .notification-title {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-dark);
        margin-bottom: 4px;
    }
    .notification-message {
        color: var(--text-light);
        font-size: 0.9rem;
        line-height: 1.5;
        margin-bottom: 8px;
    }
    .notification-meta {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        font-size: 0.8rem;
        color: var(--text-light);
    }
    .notification-meta i { margin-right: 4px; }
    .notification-actions {
        display: flex;
        gap: 8px;
        margin-top: 10px;
    }
    .notification-actions .btn {
        padding: 4px 14px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .notification-badge {
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    .badge-priority-high { background: #ffebee; color: #c62828; }
    .badge-priority-normal { background: #e3f2fd; color: #1565c0; }
    .badge-priority-low { background: #e8f5e9; color: #2e7d32; }
    
    .empty-notifications {
        text-align: center;
        padding: 80px 20px;
    }
    .empty-notifications .empty-icon {
        width: 120px;
        height: 120px;
        background: var(--primary-soft);
        border-radius: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 25px;
    }
    .empty-notifications .empty-icon i {
        font-size: 3rem;
        color: var(--primary-color);
    }
    
    .notification-filter {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .notification-filter .btn-filter {
        padding: 8px 20px;
        border-radius: 10px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        font-weight: 600;
        font-size: 0.85rem;
        transition: var(--transition);
        cursor: pointer;
    }
    .notification-filter .btn-filter:hover,
    .notification-filter .btn-filter.active {
        background: var(--primary-color);
        color: var(--white);
        border-color: var(--primary-color);
    }
    
    @media (max-width: 576px) {
        .notification-card .notification-body {
            flex-direction: column;
            align-items: stretch;
        }
        .notification-icon { align-self: center; }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">
        <i class="fas fa-bell me-2 text-primary"></i>
        Notifications
        <?php if ($unread_count > 0): ?>
            <span class="badge bg-danger ms-2"><?php echo $unread_count; ?> unread</span>
        <?php endif; ?>
    </h4>
    <div>
        <?php if ($unread_count > 0): ?>
            <a href="?action=mark_all_read" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-check-double me-1"></i> Mark All Read
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Notification Filters -->
<div class="notification-filter">
    <button class="btn-filter active" onclick="filterNotifications('all', this)">All</button>
    <button class="btn-filter" onclick="filterNotifications('academic', this)">Academic</button>
    <button class="btn-filter" onclick="filterNotifications('financial', this)">Financial</button>
    <button class="btn-filter" onclick="filterNotifications('urgent', this)">Urgent</button>
    <button class="btn-filter" onclick="filterNotifications('general', this)">General</button>
</div>

<?php if (count($notifications) > 0): ?>
    <?php foreach ($notifications as $notification): 
        $is_unread = $notification['is_read'] == 0;
        $type = strtolower($notification['notification_type'] ?? 'general');
        $priority = strtolower($notification['priority'] ?? 'normal');
    ?>
    <div class="notification-card <?php echo $is_unread ? 'unread' : ''; ?>" data-type="<?php echo $type; ?>">
        <div class="notification-body">
            <div class="notification-icon <?php echo $type; ?>">
                <?php 
                $icons = [
                    'academic' => 'fa-graduation-cap',
                    'financial' => 'fa-coins',
                    'hostel' => 'fa-hotel',
                    'urgent' => 'fa-exclamation-triangle',
                    'general' => 'fa-info-circle'
                ];
                $icon = $icons[$type] ?? 'fa-bell';
                ?>
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            
            <div class="notification-content">
                <div class="notification-title">
                    <?php echo htmlspecialchars($notification['title']); ?>
                    <?php if ($is_unread): ?>
                        <span class="badge bg-primary ms-2">New</span>
                    <?php endif; ?>
                    <span class="notification-badge badge-priority-<?php echo $priority; ?> ms-1">
                        <?php echo ucfirst($priority); ?>
                    </span>
                </div>
                
                <div class="notification-message">
                    <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                </div>
                
                <div class="notification-meta">
                    <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($notification['sent_date'])); ?></span>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($notification['notification_type'] ?? 'General'); ?></span>
                    <?php if (!empty($notification['expires_date'])): ?>
                        <span><i class="fas fa-clock"></i> Expires: <?php echo date('M d, Y', strtotime($notification['expires_date'])); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="notification-actions">
                    <?php if ($is_unread): ?>
                        <a href="?action=mark_read&id=<?php echo $notification['notification_id']; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-check me-1"></i> Mark as Read
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($notification['action_url'])): ?>
                        <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-arrow-right me-1"></i> View
                        </a>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?php echo $notification['notification_id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to delete this notification?')">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-notifications">
        <div class="empty-icon">
            <i class="fas fa-bell-slash"></i>
        </div>
        <h4>No Notifications</h4>
        <p class="text-muted">You don't have any notifications at the moment. Check back later for updates.</p>
    </div>
<?php endif; ?>

<script>
    function filterNotifications(type, btn) {
        // Update active button
        document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        // Filter notifications
        const cards = document.querySelectorAll('.notification-card');
        cards.forEach(card => {
            if (type === 'all' || card.dataset.type === type) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>