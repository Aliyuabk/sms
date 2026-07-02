<?php
/**
 * Staff Messages Page
 * Send messages to students
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
$send_all = $_GET['all'] ?? 0;

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

// Get courses for dropdown
$courseStmt = $pdo->prepare("
    SELECT DISTINCT c.course_id, c.course_code, c.course_title
    FROM course_assignments ca
    JOIN courses c ON ca.course_id = c.course_id
    WHERE ca.staff_id = ? AND ca.status = 'Active'
");
$courseStmt->execute([$staff_id]);
$courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Get students for dropdown
$studentStmt = $pdo->prepare("
    SELECT DISTINCT s.student_id, s.matric_number, s.first_name, s.last_name, s.email
    FROM students s
    JOIN course_registrations cr ON s.student_id = cr.student_id
    JOIN course_assignments ca ON cr.course_id = ca.course_id
    WHERE ca.staff_id = ? AND cr.registration_status = 'Approved'
    ORDER BY s.last_name, s.first_name
");
$studentStmt->execute([$staff_id]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get specific student if selected
$selected_student = null;
if ($student_id > 0) {
    foreach ($students as $s) {
        if ($s['student_id'] == $student_id) {
            $selected_student = $s;
            break;
        }
    }
}

// Get course students if sending to all in course
$course_students = [];
if ($course_id > 0 && $send_all) {
    $csStmt = $pdo->prepare("
        SELECT s.student_id, s.matric_number, s.first_name, s.last_name, s.email
        FROM students s
        JOIN course_registrations cr ON s.student_id = cr.student_id
        WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
    ");
    $csStmt->execute([$course_id]);
    $course_students = $csStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $recipient_type = $_POST['recipient_type'] ?? 'student';
    $recipient_id = $_POST['recipient_id'] ?? 0;
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $type = $_POST['type'] ?? 'general';
    
    $errors = [];
    $success_count = 0;
    
    if (empty($subject)) $errors[] = "Subject is required";
    if (empty($message)) $errors[] = "Message is required";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            if ($recipient_type === 'all_students') {
                // Send to all students in a course
                $recipient_course = $_POST['course_id'] ?? 0;
                if ($recipient_course > 0) {
                    $allStmt = $pdo->prepare("
                        SELECT s.student_id, s.first_name, s.last_name, s.email
                        FROM students s
                        JOIN course_registrations cr ON s.student_id = cr.student_id
                        WHERE cr.course_id = ? AND cr.registration_status = 'Approved'
                    ");
                    $allStmt->execute([$recipient_course]);
                    $all_students = $allStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($all_students as $stu) {
                        $insertStmt = $pdo->prepare("
                            INSERT INTO notifications (student_id, title, message, notification_type, priority, sent_date)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $insertStmt->execute([$stu['student_id'], $subject, $message, $type, $priority]);
                        $success_count++;
                    }
                }
            } elseif ($recipient_type === 'student' && $recipient_id > 0) {
                // Send to individual student
                $insertStmt = $pdo->prepare("
                    INSERT INTO notifications (student_id, title, message, notification_type, priority, sent_date)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([$recipient_id, $subject, $message, $type, $priority]);
                $success_count++;
            }
            
            $pdo->commit();
            
            // Log the message
            $logStmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, created_at)
                VALUES (?, 'Send Message', ?, NOW())
            ");
            $logStmt->execute([$staff_id, "Sent message to $success_count recipient(s): $subject"]);
            
            $_SESSION['message_success'] = "Message sent to $success_count recipient(s) successfully!";
            header('Location: message.php');
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error sending message: " . $e->getMessage();
            error_log("Message Error: " . $e->getMessage());
        }
    }
}

$success_message = $_SESSION['message_success'] ?? null;
unset($_SESSION['message_success']);

$page_title = 'Send Message';
$page_icon = 'fas fa-envelope';
$active_page = 'messages';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'Send Message']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .message-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .message-card-header {
        padding: 25px 30px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
    }
    .message-card-header h5 {
        font-weight: 700;
        margin-bottom: 0;
    }
    .message-card-body {
        padding: 30px;
    }
    .message-form .form-label {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-dark);
    }
    .message-form .form-control,
    .message-form .form-select {
        border-radius: 12px;
        border: 1.5px solid var(--gray-200);
        padding: 10px 15px;
        font-size: 0.95rem;
        transition: var(--transition);
    }
    .message-form .form-control:focus,
    .message-form .form-select:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .message-form textarea {
        resize: vertical;
        min-height: 150px;
    }
    .btn-send-message {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border: none;
        padding: 12px 40px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-send-message:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(63, 116, 156, 0.4);
        color: var(--white);
    }
    .recipient-info {
        background: var(--gray-100);
        border-radius: 12px;
        padding: 15px 20px;
        margin: 10px 0;
        border-left: 4px solid var(--primary-color);
    }
    .recipient-info .label {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-light);
        font-weight: 600;
    }
    .recipient-info .value {
        font-weight: 600;
        color: var(--text-dark);
    }
    .char-counter {
        text-align: right;
        font-size: 0.8rem;
        color: var(--text-light);
        margin-top: 5px;
    }
    .char-counter.limit { color: var(--warning-color); }
    .char-counter.over { color: var(--danger-color); }
    
    @media (max-width: 576px) {
        .message-card-body { padding: 20px; }
    }
</style>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="message-card">
            <div class="message-card-header">
                <h5><i class="fas fa-envelope me-2"></i> Send Message</h5>
            </div>
            <div class="message-card-body">
                <form method="POST" action="" class="message-form" id="messageForm">
                    <!-- Recipient Selection -->
                    <div class="mb-3">
                        <label class="form-label">Send To</label>
                        <select name="recipient_type" class="form-select" id="recipientType" onchange="toggleRecipientFields()">
                            <option value="student" <?php echo $student_id > 0 ? 'selected' : ''; ?>>Individual Student</option>
                            <option value="all_students" <?php echo $send_all ? 'selected' : ''; ?>>All Students in Course</option>
                        </select>
                    </div>
                    
                    <!-- Individual Student -->
                    <div id="studentField" class="mb-3">
                        <label class="form-label">Select Student</label>
                        <select name="recipient_id" class="form-select">
                            <option value="">-- Select Student --</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?php echo $s['student_id']; ?>" <?php echo ($student_id == $s['student_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['matric_number'] . ' - ' . $s['first_name'] . ' ' . $s['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Course Selection for All Students -->
                    <div id="courseField" class="mb-3" style="display: none;">
                        <label class="form-label">Select Course</label>
                        <select name="course_id" class="form-select">
                            <option value="">-- Select Course --</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?php echo $c['course_id']; ?>" <?php echo ($course_id == $c['course_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_title']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($selected_student): ?>
                    <div class="recipient-info">
                        <div class="label">Recipient</div>
                        <div class="value">
                            <i class="fas fa-user me-2"></i>
                            <?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?>
                            <span class="text-muted ms-2">(<?php echo htmlspecialchars($selected_student['matric_number']); ?>)</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($send_all && $course_id > 0 && !empty($course_students)): ?>
                    <div class="recipient-info">
                        <div class="label">Recipients</div>
                        <div class="value">
                            <i class="fas fa-users me-2"></i>
                            All <?php echo count($course_students); ?> students in 
                            <?php 
                            $course_name = '';
                            foreach ($courses as $c) {
                                if ($c['course_id'] == $course_id) {
                                    $course_name = $c['course_code'] . ' - ' . $c['course_title'];
                                    break;
                                }
                            }
                            echo htmlspecialchars($course_name); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Message Details -->
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter message subject..." required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" placeholder="Type your message here..." required></textarea>
                        <div class="char-counter" id="charCounter">0 characters</div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="general">General</option>
                                <option value="academic">Academic</option>
                                <option value="financial">Financial</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" name="send_message" class="btn-send-message">
                            <i class="fas fa-paper-plane me-2"></i> Send Message
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleRecipientFields() {
        const type = document.getElementById('recipientType').value;
        const studentField = document.getElementById('studentField');
        const courseField = document.getElementById('courseField');
        
        if (type === 'student') {
            studentField.style.display = 'block';
            courseField.style.display = 'none';
        } else {
            studentField.style.display = 'none';
            courseField.style.display = 'block';
        }
    }
    
    // Character counter
    const textarea = document.querySelector('textarea[name="message"]');
    const charCounter = document.getElementById('charCounter');
    
    if (textarea && charCounter) {
        textarea.addEventListener('input', function() {
            const length = this.value.length;
            charCounter.textContent = length + ' characters';
            charCounter.classList.remove('limit', 'over');
            if (length > 500) charCounter.classList.add('limit');
            if (length > 1000) charCounter.classList.add('over');
        });
    }
    
    // Auto-show recipient fields based on initial selection
    document.addEventListener('DOMContentLoaded', function() {
        toggleRecipientFields();
    });
</script>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>