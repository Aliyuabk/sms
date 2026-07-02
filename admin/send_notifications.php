<?php
// send_notifications.php
ob_start();

require_once 'includes/header.php';

$page_title = "Send Notifications";

// Fetch available recipients for dropdowns
try {
    // Get departments for filtering
    $depts_stmt = $pdo->query("
        SELECT department_id, department_name, department_code 
        FROM departments 
        WHERE department_id IN (SELECT DISTINCT department_id FROM students WHERE status = 'Active')
        ORDER BY department_name
    ");
    $departments = $depts_stmt->fetchAll();

    // Get levels
    $levels_stmt = $pdo->query("
        SELECT DISTINCT current_level 
        FROM students 
        WHERE status = 'Active' 
        ORDER BY current_level
    ");
    $levels = $levels_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get programs
    $programs_stmt = $pdo->query("
        SELECT program_id, program_code, program_name 
        FROM programs 
        WHERE program_id IN (SELECT DISTINCT program_id FROM students WHERE status = 'Active')
        ORDER BY program_name
    ");
    $programs = $programs_stmt->fetchAll();

    // Get email templates
    $templates_stmt = $pdo->query("
        SELECT template_id, template_name, subject, template_type 
        FROM email_templates 
        WHERE is_active = 1 
        ORDER BY template_name
    ");
    $templates = $templates_stmt->fetchAll();

} catch (Exception $e) {
    error_log("Error fetching data: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $notification_type = $_POST['notification_type'];
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $priority = $_POST['priority'];
    $recipient_type = $_POST['recipient_type'];
    $schedule_date = !empty($_POST['schedule_date']) ? $_POST['schedule_date'] : null;
    $action_url = !empty($_POST['action_url']) ? trim($_POST['action_url']) : null;
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

    $errors = [];
    $recipient_ids = [];

    // Validation
    if (empty($title)) {
        $errors[] = "Notification title is required";
    }
    if (empty($message)) {
        $errors[] = "Notification message is required";
    }
    if (strlen($message) > 5000) {
        $errors[] = "Message too long (max 5000 characters)";
    }

    // Build recipient list based on selection
    try {
        switch ($recipient_type) {
            case 'all':
                $stmt = $pdo->query("SELECT student_id FROM students WHERE status = 'Active'");
                $recipient_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                break;

            case 'department':
                $dept_id = (int)$_POST['department_id'];
                if ($dept_id <= 0) {
                    $errors[] = "Please select a department";
                } else {
                    $stmt = $pdo->prepare("
                        SELECT student_id FROM students 
                        WHERE department_id = ? AND status = 'Active'
                    ");
                    $stmt->execute([$dept_id]);
                    $recipient_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                break;

            case 'level':
                $level = (int)$_POST['level'];
                if ($level <= 0) {
                    $errors[] = "Please select a level";
                } else {
                    $stmt = $pdo->prepare("
                        SELECT student_id FROM students 
                        WHERE current_level = ? AND status = 'Active'
                    ");
                    $stmt->execute([$level]);
                    $recipient_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                break;

            case 'program':
                $program_id = (int)$_POST['program_id'];
                if ($program_id <= 0) {
                    $errors[] = "Please select a program";
                } else {
                    $stmt = $pdo->prepare("
                        SELECT student_id FROM students 
                        WHERE program_id = ? AND status = 'Active'
                    ");
                    $stmt->execute([$program_id]);
                    $recipient_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                break;

            case 'specific':
                $student_ids = $_POST['student_ids'] ?? [];
                if (empty($student_ids)) {
                    $errors[] = "Please select at least one student";
                } else {
                    $recipient_ids = $student_ids;
                }
                break;

            case 'staff':
                $staff_type = $_POST['staff_type'] ?? 'all';
                // Handle staff notifications (if needed)
                break;
        }
    } catch (Exception $e) {
        $errors[] = "Error fetching recipients: " . $e->getMessage();
    }

    if (empty($recipient_ids) && $recipient_type != 'staff') {
        $errors[] = "No recipients found matching your criteria";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $success_count = 0;
            $failed_count = 0;

            // Determine if it's a push notification or email
            $send_email = isset($_POST['send_email']);
            $send_push = isset($_POST['send_push']);
            $send_sms = isset($_POST['send_sms']);

            // Insert notifications
            if ($send_push && !empty($recipient_ids)) {
                $insert_sql = "INSERT INTO notifications (
                    student_id, title, message, notification_type, priority, 
                    action_url, expires_date, sent_date, is_read
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)";

                $insert_stmt = $pdo->prepare($insert_sql);

                foreach ($recipient_ids as $student_id) {
                    try {
                        $insert_stmt->execute([
                            $student_id, $title, $message, $notification_type, $priority,
                            $action_url, $expiry_date
                        ]);
                        $success_count++;
                    } catch (Exception $e) {
                        $failed_count++;
                        error_log("Failed to send notification to student $student_id: " . $e->getMessage());
                    }
                }
            }

            // Queue emails if requested
            if ($send_email && !empty($recipient_ids)) {
                $email_sql = "INSERT INTO email_queue (
                    student_id, subject, message, priority, scheduled_time, status
                ) VALUES (?, ?, ?, ?, ?, 'Pending')";

                $email_stmt = $pdo->prepare($email_sql);
                $schedule_time = $schedule_date ?: date('Y-m-d H:i:s');

                foreach ($recipient_ids as $student_id) {
                    $email_stmt->execute([
                        $student_id, $title, $message, $priority, $schedule_time
                    ]);
                }
            }

            // Queue SMS if requested (if SMS functionality exists)
            if ($send_sms && !empty($recipient_ids)) {
                // Implement SMS queue if needed
            }

            // Log the notification batch
            $log_stmt = $pdo->prepare("
                INSERT INTO admin_logs (admin_id, action, description, table_name) 
                VALUES (?, 'Send Notification', ?, 'notifications')
            ");
            $log_stmt->execute([
                $_SESSION['admin_id'],
                "Sent {$notification_type} notification '{$title}' to {$success_count} recipients"
            ]);

            $pdo->commit();

            $_SESSION['success_message'] = "Notification sent successfully to {$success_count} recipients!";
            if ($failed_count > 0) {
                $_SESSION['warning_message'] = "Failed to send to {$failed_count} recipients.";
            }

            header("Location: notification_logs.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error sending notifications: " . $e->getMessage());
            $errors[] = "Error sending notifications: " . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="notification_logs.php">Notifications</a></li>
                <li class="breadcrumb-item active" aria-current="page">Send Notification</li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">Send Notification</h1>
    </div>
    <div class="app-actions">
        <a href="email_templates.php" class="btn btn-outline-primary me-2">
            <i class="fas fa-template me-2"></i>Manage Templates
        </a>
        <a href="notification_logs.php" class="btn btn-secondary">
            <i class="fas fa-history me-2"></i>View Logs
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['warning_message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['warning_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['warning_message']); ?>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-paper-plane me-2"></i>Compose Notification
                </h5>
            </div>
            <div class="app-card-body p-4">
                <form method="POST" action="" id="notificationForm">
                    <!-- Notification Type -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Notification Type</label>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="notification_type" 
                                           id="typeAcademic" value="Academic" checked>
                                    <label class="form-check-label" for="typeAcademic">
                                        <i class="fas fa-graduation-cap text-primary me-1"></i>Academic
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="notification_type" 
                                           id="typeFinancial" value="Financial">
                                    <label class="form-check-label" for="typeFinancial">
                                        <i class="fas fa-coins text-success me-1"></i>Financial
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="notification_type" 
                                           id="typeHostel" value="Hostel">
                                    <label class="form-check-label" for="typeHostel">
                                        <i class="fas fa-building text-info me-1"></i>Hostel
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="notification_type" 
                                           id="typeGeneral" value="General">
                                    <label class="form-check-label" for="typeGeneral">
                                        <i class="fas fa-bullhorn text-warning me-1"></i>General
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="notification_type" 
                                           id="typeUrgent" value="Urgent">
                                    <label class="form-check-label" for="typeUrgent">
                                        <i class="fas fa-exclamation-circle text-danger me-1"></i>Urgent
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Priority -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Priority Level</label>
                        <select class="form-select" name="priority">
                            <option value="Normal">Normal</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>

                    <!-- Title -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Notification Title</label>
                        <input type="text" class="form-control" name="title" 
                               placeholder="e.g., Course Registration Deadline" required maxlength="200">
                    </div>

                    <!-- Message -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Message</label>
                        <textarea class="form-control" name="message" rows="6" 
                                  placeholder="Enter your notification message here..." required></textarea>
                        <div class="form-text">
                            <span id="charCount">0</span>/5000 characters
                        </div>
                    </div>

                    <!-- Action URL (optional) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Action URL (Optional)</label>
                        <input type="url" class="form-control" name="action_url" 
                               placeholder="https://example.com/action">
                        <div class="form-text">Link to more information or action page</div>
                    </div>

                    <!-- Delivery Options -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Delivery Channels</label>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_push" 
                                           id="sendPush" checked>
                                    <label class="form-check-label" for="sendPush">
                                        <i class="fas fa-bell me-1"></i>In-App Notification
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_email" 
                                           id="sendEmail">
                                    <label class="form-check-label" for="sendEmail">
                                        <i class="fas fa-envelope me-1"></i>Email
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_sms" 
                                           id="sendSMS">
                                    <label class="form-check-label" for="sendSMS">
                                        <i class="fas fa-sms me-1"></i>SMS
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule (Optional) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Schedule (Optional)</label>
                        <input type="datetime-local" class="form-control" name="schedule_date">
                        <div class="form-text">Leave empty to send immediately</div>
                    </div>

                    <!-- Expiry Date (Optional) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Expiry Date (Optional)</label>
                        <input type="date" class="form-control" name="expiry_date">
                        <div class="form-text">Notification will expire after this date</div>
                    </div>

                    <!-- Template Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Use Template (Optional)</label>
                        <select class="form-select" id="templateSelect">
                            <option value="">Select a template...</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['template_id']; ?>" 
                                        data-subject="<?php echo htmlspecialchars($template['subject']); ?>"
                                        data-content="<?php echo htmlspecialchars($template['content'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($template['template_name']); ?> 
                                    (<?php echo $template['template_type']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr class="my-4">

                    <!-- Recipients Section -->
                    <h5 class="mb-3"><i class="fas fa-users me-2"></i>Select Recipients</h5>

                    <div class="mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recAll" value="all" checked>
                                    <label class="form-check-label" for="recAll">
                                        All Students
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recDept" value="department">
                                    <label class="form-check-label" for="recDept">
                                        By Department
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recLevel" value="level">
                                    <label class="form-check-label" for="recLevel">
                                        By Level
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recProgram" value="program">
                                    <label class="form-check-label" for="recProgram">
                                        By Program
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recSpecific" value="specific">
                                    <label class="form-check-label" for="recSpecific">
                                        Specific Students
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input recipient-type" type="radio" 
                                           name="recipient_type" id="recStaff" value="staff">
                                    <label class="form-check-label" for="recStaff">
                                        Staff
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic recipient options -->
                    <div id="recipientOptions">
                        <!-- All Students - no additional options -->
                        
                        <!-- Department Option -->
                        <div class="recipient-option" id="option-department" style="display: none;">
                            <label class="form-label">Select Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">Choose Department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Level Option -->
                        <div class="recipient-option" id="option-level" style="display: none;">
                            <label class="form-label">Select Level</label>
                            <select class="form-select" name="level">
                                <option value="">Choose Level...</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?php echo $level; ?>">Level <?php echo $level; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Program Option -->
                        <div class="recipient-option" id="option-program" style="display: none;">
                            <label class="form-label">Select Program</label>
                            <select class="form-select" name="program_id">
                                <option value="">Choose Program...</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['program_id']; ?>">
                                        <?php echo htmlspecialchars($program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Specific Students Option -->
                        <div class="recipient-option" id="option-specific" style="display: none;">
                            <label class="form-label">Select Students</label>
                            <div class="mb-2">
                                <input type="text" class="form-control" id="studentSearch" 
                                       placeholder="Search students...">
                            </div>
                            <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                <div id="studentList">
                                    <!-- Populated via AJAX -->
                                    <div class="text-center py-3">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading students...
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Staff Option -->
                        <div class="recipient-option" id="option-staff" style="display: none;">
                            <label class="form-label">Staff Type</label>
                            <select class="form-select" name="staff_type">
                                <option value="all">All Staff</option>
                                <option value="academic">Academic Staff</option>
                                <option value="admin">Admin Staff</option>
                                <option value="support">Support Staff</option>
                            </select>
                        </div>
                    </div>

                    <!-- Recipient Count Preview -->
                    <div class="alert alert-info mt-4" id="recipientPreview" style="display: none;">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="recipientCount">0</span> recipients will receive this notification
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="notification_logs.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" name="send_notification" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Send Notification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Quick Stats Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Quick Stats
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php
                $stats = $pdo->query("
                    SELECT 
                        COUNT(DISTINCT student_id) as total_students,
                        COUNT(CASE WHEN status = 'Active' THEN 1 END) as active_students,
                        (SELECT COUNT(*) FROM email_templates WHERE is_active = 1) as active_templates
                    FROM students
                ")->fetch();
                ?>
                
                <table class="table table-borderless">
                    <tr>
                        <td><i class="fas fa-users text-primary me-2"></i>Total Students:</td>
                        <td class="text-end fw-bold"><?php echo number_format($stats['total_students']); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-user-check text-success me-2"></i>Active Students:</td>
                        <td class="text-end fw-bold"><?php echo number_format($stats['active_students']); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-template text-info me-2"></i>Templates:</td>
                        <td class="text-end fw-bold"><?php echo $stats['active_templates']; ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Recent Templates Card -->
        <?php if (!empty($templates)): ?>
        <div class="app-card shadow-sm">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-history me-2"></i>Recent Templates
                </h5>
            </div>
            <div class="app-card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($templates, 0, 5) as $template): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($template['template_name']); ?></h6>
                                <small class="text-muted"><?php echo $template['template_type']; ?></small>
                            </div>
                            <button class="btn btn-sm btn-outline-primary use-template" 
                                    data-id="<?php echo $template['template_id']; ?>">
                                Use
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Character counter
document.querySelector('textarea[name="message"]').addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('charCount').textContent = count;
    
    if (count > 5000) {
        this.classList.add('is-invalid');
    } else {
        this.classList.remove('is-invalid');
    }
});

// Template selector
document.getElementById('templateSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    if (selected.value) {
        const subject = selected.dataset.subject;
        const content = selected.dataset.content;
        
        if (subject) {
            document.querySelector('input[name="title"]').value = subject;
        }
        if (content) {
            document.querySelector('textarea[name="message"]').value = content;
            // Trigger character count update
            document.querySelector('textarea[name="message"]').dispatchEvent(new Event('input'));
        }
    }
});

// Use template buttons
document.querySelectorAll('.use-template').forEach(btn => {
    btn.addEventListener('click', function() {
        const templateId = this.dataset.id;
        document.getElementById('templateSelect').value = templateId;
        document.getElementById('templateSelect').dispatchEvent(new Event('change'));
    });
});

// Recipient type toggle
document.querySelectorAll('.recipient-type').forEach(radio => {
    radio.addEventListener('change', function() {
        // Hide all options
        document.querySelectorAll('.recipient-option').forEach(opt => {
            opt.style.display = 'none';
        });
        
        // Show selected option
        if (this.value !== 'all') {
            document.getElementById(`option-${this.value}`).style.display = 'block';
        }
        
        // Update recipient preview
        updateRecipientCount();
    });
});

// Update recipient count
function updateRecipientCount() {
    const type = document.querySelector('input[name="recipient_type"]:checked')?.value;
    if (!type) return;
    
    let url = 'get_recipient_count.php?type=' + type;
    
    // Add parameters based on type
    if (type === 'department') {
        const deptId = document.querySelector('select[name="department_id"]')?.value;
        if (deptId) url += '&department_id=' + deptId;
    } else if (type === 'level') {
        const level = document.querySelector('select[name="level"]')?.value;
        if (level) url += '&level=' + level;
    } else if (type === 'program') {
        const programId = document.querySelector('select[name="program_id"]')?.value;
        if (programId) url += '&program_id=' + programId;
    } else if (type === 'specific') {
        const selected = document.querySelectorAll('input[name="student_ids[]"]:checked').length;
        document.getElementById('recipientCount').textContent = selected;
        document.getElementById('recipientPreview').style.display = selected > 0 ? 'block' : 'none';
        return;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.count > 0) {
                document.getElementById('recipientCount').textContent = data.count;
                document.getElementById('recipientPreview').style.display = 'block';
            } else {
                document.getElementById('recipientPreview').style.display = 'none';
            }
        });
}

// Load students for specific selection
function loadStudents() {
    fetch('get_students_list.php?simple=1')
        .then(response => response.json())
        .then(students => {
            let html = '';
            students.forEach(student => {
                html += `
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" 
                               name="student_ids[]" value="${student.student_id}"
                               id="student_${student.student_id}">
                        <label class="form-check-label" for="student_${student.student_id}">
                            ${student.matric_number} - ${student.first_name} ${student.last_name}
                            <small class="text-muted">(Level ${student.current_level})</small>
                        </label>
                    </div>
                `;
            });
            document.getElementById('studentList').innerHTML = html;
            
            // Add change listeners to update count
            document.querySelectorAll('input[name="student_ids[]"]').forEach(cb => {
                cb.addEventListener('change', updateRecipientCount);
            });
        });
}

// Student search
document.getElementById('studentSearch')?.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const checkboxes = document.querySelectorAll('input[name="student_ids[]"]');
    
    checkboxes.forEach(cb => {
        const label = cb.nextElementSibling.textContent.toLowerCase();
        const div = cb.closest('.form-check');
        if (label.includes(searchTerm)) {
            div.style.display = '';
        } else {
            div.style.display = 'none';
        }
    });
});

// Initialize when specific option is selected
document.getElementById('recSpecific').addEventListener('change', function() {
    if (this.checked) {
        loadStudents();
    }
});

// Load students if specific is pre-selected
if (document.getElementById('recSpecific').checked) {
    loadStudents();
}

// Department change updates count
document.querySelector('select[name="department_id"]')?.addEventListener('change', updateRecipientCount);
document.querySelector('select[name="level"]')?.addEventListener('change', updateRecipientCount);
document.querySelector('select[name="program_id"]')?.addEventListener('change', updateRecipientCount);

// Form validation
document.getElementById('notificationForm').addEventListener('submit', function(e) {
    const message = this.querySelector('textarea[name="message"]').value;
    const type = document.querySelector('input[name="recipient_type"]:checked')?.value;
    
    if (message.length > 5000) {
        e.preventDefault();
        alert('Message exceeds 5000 characters');
        return false;
    }
    
    if (type === 'department' && !document.querySelector('select[name="department_id"]')?.value) {
        e.preventDefault();
        alert('Please select a department');
        return false;
    }
    
    if (type === 'level' && !document.querySelector('select[name="level"]')?.value) {
        e.preventDefault();
        alert('Please select a level');
        return false;
    }
    
    if (type === 'program' && !document.querySelector('select[name="program_id"]')?.value) {
        e.preventDefault();
        alert('Please select a program');
        return false;
    }
    
    if (type === 'specific') {
        const selected = document.querySelectorAll('input[name="student_ids[]"]:checked').length;
        if (selected === 0) {
            e.preventDefault();
            alert('Please select at least one student');
            return false;
        }
    }
    
    return confirm(`Send this notification to ${document.getElementById('recipientCount').textContent} recipients?`);
});
</script>

<?php
require_once 'includes/footer.php';
?>