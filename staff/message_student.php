<?php
session_start();
require_once 'config/database.php';

// Auth check
if (!isset($_SESSION['staff_id'])) {
    header('Location: index.php');
    exit;
}

$staff_id = $_SESSION['staff_id'];
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$student_id) {
    header('Location: students.php');
    exit;
}

// Verify student is in staff's course
$verify = $pdo->prepare("
    SELECT 1 FROM course_registrations cr
    JOIN course_assignments ca ON cr.course_id = ca.course_id AND cr.session_year = ca.session_year AND cr.semester = ca.semester
    WHERE cr.student_id = ? AND ca.staff_id = ? AND ca.status = 'Active' AND cr.registration_status = 'Approved'
    LIMIT 1
");
$verify->execute([$student_id, $staff_id]);
if (!$verify->fetch()) {
    header('Location: students.php');
    exit;
}

// Fetch student
$stmt = $pdo->prepare("SELECT student_id, first_name, last_name, email, matric_number, profile_image FROM students WHERE student_id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle message send
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    try {
        $ins = $pdo->prepare("
            INSERT INTO notifications (student_id, title, message, notification_type, priority, sent_date)
            VALUES (?, ?, ?, 'Academic', ?, NOW())
        ");
        $ins->execute([
            $student_id,
            $_POST['subject'],
            $_POST['message'],
            $_POST['priority'] ?? 'Normal'
        ]);

        // Also add to email queue
        $email = $pdo->prepare("
            INSERT INTO email_queue (student_id, recipient_email, recipient_name, subject, message, priority)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $email->execute([
            $student_id,
            $student['email'],
            $student['first_name'] . ' ' . $student['last_name'],
            $_POST['subject'],
            $_POST['message'],
            $_POST['priority'] ?? 'Normal'
        ]);

        $success_msg = 'Message sent successfully to ' . $student['first_name'] . '!';
    } catch (Exception $e) {
        $error_msg = 'Error: ' . $e->getMessage();
    }
}

// Get staff data
$stmt3 = $pdo->prepare("SELECT * FROM staff_dashboard WHERE staff_id = ?");
$stmt3->execute([$staff_id]);
$staff = $stmt3->fetch(PDO::FETCH_ASSOC);

$page_title = 'Message Student';
$page_icon = 'fas fa-envelope';
$active_page = 'students';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Students', 'url' => 'students.php'],
    ['title' => 'Message']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .message-hero {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        border-radius: 24px;
        padding: 30px;
        color: var(--white);
        margin-bottom: 25px;
        animation: fadeInUp 0.6s ease;
    }
    .message-hero h2 { font-size: 1.5rem; font-weight: 800; margin-bottom: 8px; }
    .message-hero p { opacity: 0.9; }

    .message-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        animation: fadeInUp 0.6s ease;
    }
    .message-card-header {
        padding: 25px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .message-avatar {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 800;
    }
    .message-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; }
    .message-recipient h4 { font-size: 1.2rem; font-weight: 700; margin: 0; }
    .message-recipient p { color: var(--text-light); margin: 0; font-size: 0.9rem; }

    .message-form { padding: 25px; }
    .form-row { margin-bottom: 20px; }
    .form-label-msg {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 8px;
    }
    .form-input-msg {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 12px;
        font-size: 0.95rem;
        transition: var(--transition);
    }
    .form-input-msg:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .form-textarea-msg {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid var(--gray-300);
        border-radius: 12px;
        font-size: 0.95rem;
        transition: var(--transition);
        min-height: 150px;
        resize: vertical;
    }
    .form-textarea-msg:focus {
        outline: none;
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .priority-options {
        display: flex;
        gap: 10px;
    }
    .priority-option {
        position: relative;
    }
    .priority-option input { position: absolute; opacity: 0; }
    .priority-option label {
        padding: 10px 20px;
        border-radius: 10px;
        border: 2px solid var(--gray-300);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .priority-option input:checked + label.priority-low { background: #e8f5e9; border-color: var(--success-color); color: var(--success-color); }
    .priority-option input:checked + label.priority-normal { background: #e3f2fd; border-color: var(--primary-light); color: var(--primary-color); }
    .priority-option input:checked + label.priority-high { background: #fff3e0; border-color: var(--warning-color); color: var(--warning-color); }
    .priority-option input:checked + label.priority-urgent { background: #ffebee; border-color: var(--danger-color); color: var(--danger-color); }

    .btn-send-msg {
        padding: 14px 35px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
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
    .btn-send-msg:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(63, 116, 156, 0.3);
    }
    .btn-cancel-msg {
        padding: 14px 30px;
        background: var(--gray-100);
        color: var(--text-dark);
        border: 1px solid var(--gray-300);
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: 10px;
        transition: var(--transition);
    }
    .btn-cancel-msg:hover { background: var(--gray-200); }

    .alert-msg {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-weight: 500;
        animation: fadeInUp 0.4s ease;
    }
    .alert-msg-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid var(--success-color); }
    .alert-msg-error { background: #ffebee; color: #c62828; border-left: 4px solid var(--danger-color); }
</style>

<?php if ($success_msg): ?>
<div class="alert-msg alert-msg-success"><i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert-msg alert-msg-error"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?></div>
<?php endif; ?>

<div class="message-hero">
    <h2><i class="fas fa-envelope me-2"></i>Send Message</h2>
    <p>Send a notification or email to <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
</div>

<div class="message-card">
    <div class="message-card-header">
        <div class="message-avatar">
            <?php if (!empty($student['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($student['profile_image']); ?>" alt="">
            <?php else: ?>
                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <div class="message-recipient">
            <h4><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></h4>
            <p><i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($student['matric_number']); ?> | 
               <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($student['email']); ?></p>
        </div>
    </div>

    <form method="POST" action="" class="message-form">
        <div class="form-row">
            <label class="form-label-msg"><i class="fas fa-heading me-1"></i>Subject</label>
            <input type="text" class="form-input-msg" name="subject" required 
                   placeholder="Enter message subject...">
        </div>

        <div class="form-row">
            <label class="form-label-msg"><i class="fas fa-flag me-1"></i>Priority</label>
            <div class="priority-options">
                <div class="priority-option">
                    <input type="radio" name="priority" id="priorityLow" value="Low">
                    <label for="priorityLow" class="priority-low"><i class="fas fa-arrow-down"></i> Low</label>
                </div>
                <div class="priority-option">
                    <input type="radio" name="priority" id="priorityNormal" value="Normal" checked>
                    <label for="priorityNormal" class="priority-normal"><i class="fas fa-minus"></i> Normal</label>
                </div>
                <div class="priority-option">
                    <input type="radio" name="priority" id="priorityHigh" value="High">
                    <label for="priorityHigh" class="priority-high"><i class="fas fa-arrow-up"></i> High</label>
                </div>
                <div class="priority-option">
                    <input type="radio" name="priority" id="priorityUrgent" value="Urgent">
                    <label for="priorityUrgent" class="priority-urgent"><i class="fas fa-exclamation"></i> Urgent</label>
                </div>
            </div>
        </div>

        <div class="form-row">
            <label class="form-label-msg"><i class="fas fa-comment me-1"></i>Message</label>
            <textarea class="form-textarea-msg" name="message" required 
                      placeholder="Type your message here..."></textarea>
        </div>

        <div class="form-row">
            <button type="submit" name="send_message" class="btn-send-msg">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>
            <a href="students.php" class="btn-cancel-msg">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
</div> 