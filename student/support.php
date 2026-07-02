<?php
/**
 * Support & Feedback Page
 * Allows students to submit fee-related inquiries
 */
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'] ?? 0;
if (!$student_id) {
    header('Location: login.php');
    exit;
}

// Get student details
$student_query = "SELECT s.*, d.department_name 
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get inquiry type from URL
$inquiry_type = $_GET['type'] ?? 'general';
$type_labels = [
    'fees' => 'Fee Payment Issue',
    'receipt' => 'Receipt Problem',
    'refund' => 'Refund Request',
    'general' => 'General Inquiry'
];
$current_type_label = $type_labels[$inquiry_type] ?? 'General Inquiry';

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = $_POST['subject'] ?? '';
    $category = $_POST['category'] ?? 'General';
    $description = $_POST['description'] ?? '';
    $priority = $_POST['priority'] ?? 'Normal';

    if (empty($subject) || empty($description)) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } else {
        // Insert into email_queue for staff notification
        $insert_query = "INSERT INTO email_queue 
            (student_id, recipient_email, recipient_name, subject, message, priority, status, created_at)
            VALUES (?, 'bursary@university.edu', 'Bursary Department', ?, ?, ?, 'Pending', NOW())";
        $stmt = $conn->prepare($insert_query);
        $full_subject = '[' . $category . '] ' . $subject . ' - Matric: ' . $student['matric_number'];
        $full_message = "Student: " . $student['first_name'] . ' ' . $student['last_name'] . "\n" .
                       "Matric: " . $student['matric_number'] . "\n" .
                       "Email: " . $student['email'] . "\n" .
                       "Phone: " . ($student['phone'] ?? 'N/A') . "\n\n" .
                       "Priority: " . $priority . "\n" .
                       "Description:\n" . $description;

        $stmt->bind_param("isss", $student_id, $full_subject, $full_message, $priority);

        if ($stmt->execute()) {
            $message = 'Your inquiry has been submitted successfully! We will respond within 24-48 hours.';
            $message_type = 'success';
            $stmt->close();

            // Also create a notification for the student
            $notif_query = "INSERT INTO notifications 
                (student_id, title, message, notification_type, priority, is_read, sent_date)
                VALUES (?, 'Inquiry Submitted', ?, 'General', ?, 0, NOW())";
            $stmt = $conn->prepare($notif_query);
            $notif_msg = "Your inquiry about '" . $subject . "' has been sent to the bursary department.";
            $stmt->bind_param("iss", $student_id, $notif_msg, $priority);
            $stmt->execute();
            $stmt->close();

        } else {
            $message = 'Failed to submit inquiry. Please try again.';
            $message_type = 'error';
            $stmt->close();
        }
    }
}

// Fetch previous inquiries
$history_query = "SELECT subject, message, priority, status, created_at 
                  FROM email_queue 
                  WHERE student_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 10";
$stmt = $conn->prepare($history_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$history_result = $stmt->get_result();
$stmt->close();
?>

<div class="fade-in">
    <div class="page-header">
        <div class="header-content">
            <h1><i class="fas fa-headset"></i> Help & Support</h1>
            <p>Submit fee-related inquiries and track your requests</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="support-container">
        <!-- Contact Info Card -->
        <div class="card contact-card">
            <div class="card-header">
                <h3><i class="fas fa-address-book"></i> Bursary Department</h3>
            </div>
            <div class="card-body">
                <div class="contact-grid">
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <span class="label">Email</span>
                            <span class="value">bursary@university.edu</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <div>
                            <span class="label">Phone</span>
                            <span class="value">+234 000 000 0000</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <span class="label">Office Hours</span>
                            <span class="value">Mon - Fri, 8AM - 4PM</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <span class="label">Location</span>
                            <span class="value">Admin Block, Room 105</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inquiry Form -->
        <div class="card form-card">
            <div class="card-header">
                <h3><i class="fas fa-paper-plane"></i> Submit Inquiry</h3>
                <span class="type-badge"><?php echo htmlspecialchars($current_type_label); ?></span>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category">Category *</label>
                            <select name="category" id="category" class="form-control" required>
                                <option value="Fee Payment" <?php echo $inquiry_type === 'fees' ? 'selected' : ''; ?>>Fee Payment</option>
                                <option value="Receipt Issue" <?php echo $inquiry_type === 'receipt' ? 'selected' : ''; ?>>Receipt Issue</option>
                                <option value="Refund Request" <?php echo $inquiry_type === 'refund' ? 'selected' : ''; ?>>Refund Request</option>
                                <option value="Overpayment" <?php echo $inquiry_type === 'overpay' ? 'selected' : ''; ?>>Overpayment</option>
                                <option value="Fee Structure" <?php echo $inquiry_type === 'structure' ? 'selected' : ''; ?>>Fee Structure</option>
                                <option value="General" <?php echo $inquiry_type === 'general' ? 'selected' : ''; ?>>General</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="priority">Priority</label>
                            <select name="priority" id="priority" class="form-control">
                                <option value="Low">Low - General question</option>
                                <option value="Normal" selected>Normal - Need assistance</option>
                                <option value="High">High - Urgent issue</option>
                                <option value="Urgent">Urgent - Cannot proceed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject *</label>
                        <input type="text" name="subject" id="subject" class="form-control" 
                               placeholder="Brief description of your issue" 
                               value="<?php echo $inquiry_type === 'fees' ? 'Fee Payment Issue' : ''; ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea name="description" id="description" class="form-control" rows="6" 
                                  placeholder="Please provide detailed information about your issue..." required></textarea>
                        <small class="form-hint">Include transaction IDs, receipt numbers, or any relevant details.</small>
                    </div>

                    <div class="form-group">
                        <label>Your Information (Auto-filled)</label>
                        <div class="info-display">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                            <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['matric_number']); ?></span>
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Inquiry
                        </button>
                        <a href="fees.php" class="btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Fees
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Inquiry History -->
        <div class="card history-card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Previous Inquiries</h3>
            </div>
            <div class="card-body">
                <?php if ($history_result->num_rows > 0): ?>
                <div class="history-list">
                    <?php while ($item = $history_result->fetch_assoc()): 
                        $status_class = strtolower($item['status']);
                        $priority_class = strtolower($item['priority']);
                    ?>
                    <div class="history-item">
                        <div class="item-header">
                            <span class="item-subject"><?php echo htmlspecialchars($item['subject']); ?></span>
                            <span class="item-status status-<?php echo $status_class; ?>">
                                <?php echo $item['status']; ?>
                            </span>
                        </div>
                        <div class="item-meta">
                            <span class="priority priority-<?php echo $priority_class; ?>">
                                <?php echo $item['priority']; ?>
                            </span>
                            <span class="date">
                                <i class="fas fa-clock"></i> 
                                <?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No previous inquiries found.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
    /* Primary: Logo Blue */
    --primary-color: #3f749c;
    --primary-dark: #2a5a7a;
    --primary-light: #5a9bc4;
    --primary-soft: #e8f2f8;
    
    /* Secondary: Logo Lime/Yellow-Green */
    --secondary-color: #c5ea4f;
    --accent-color: #d4f07a;
    
    /* Functional colors */
    --danger-color: #f44336;
    --warning-color: #ff9800;
    --success-color: #7cb342;
    
    /* Text */
    --text-dark: #2c3e50;
    --text-light: #7f8c8d;
    
    /* Neutrals */
    --white: #ffffff;
    --gray-100: #f8f9fa;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    
    /* Shadows & effects */
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
    --shadow: 0 4px 6px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
    --transition: all 0.3s ease;
    
    /* Layout */
    --sidebar-width: 280px;
    --sidebar-collapsed: 80px;
    --header-height: 70px;
}

    .alert {
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
    }
    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border: 1px solid rgba(40, 167, 69, 0.2);
    }
    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid rgba(220, 53, 69, 0.2);
    }

    .support-container {
        display: grid;
        gap: 25px;
        max-width: 800px;
        margin: 0 auto;
    }

    .card {
        background: #fff;
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .card-header {
        padding: 20px 25px;
        border-bottom: 1px solid var(--gray-200);
        background: linear-gradient(to right, rgba(30, 86, 49, 0.05), transparent);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .card-header h3 {
        margin: 0;
        font-size: 18px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-dark);
    }
    .card-header h3 i {
        color: var(--primary-color);
    }
    .card-body {
        padding: 25px;
    }

    .contact-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }
    .contact-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        background: var(--gray-100);
        border-radius: 10px;
    }
    .contact-item i {
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        color: #fff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }
    .contact-item .label {
        display: block;
        font-size: 12px;
        color: var(--text-light);
        margin-bottom: 3px;
    }
    .contact-item .value {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
    }

    .type-badge {
        background: var(--primary-color);
        color: #fff;
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
    }
    .form-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid var(--gray-200);
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s;
        font-family: inherit;
    }
    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
    }
    .form-hint {
        display: block;
        margin-top: 5px;
        font-size: 12px;
        color: var(--text-light);
    }
    .info-display {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        padding: 15px;
        background: var(--gray-100);
        border-radius: 10px;
    }
    .info-display span {
        font-size: 13px;
        color: var(--text-dark);
    }
    .info-display i {
        color: var(--primary-color);
        margin-right: 5px;
    }

    .form-actions {
        display: flex;
        gap: 15px;
        margin-top: 30px;
    }
    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: #fff;
        border: none;
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        transition: all 0.3s;
        box-shadow: 0 4px 16px rgba(30, 86, 49, 0.3);
    }
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(30, 86, 49, 0.4);
    }
    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        padding: 14px 28px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        transition: all 0.3s;
    }
    .btn-outline:hover {
        background: var(--primary-color);
        color: #fff;
    }

    .history-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .history-item {
        padding: 15px;
        border: 1px solid var(--gray-200);
        border-radius: 10px;
        transition: all 0.3s;
    }
    .history-item:hover {
        background: var(--gray-100);
        border-color: var(--primary-color);
    }
    .item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .item-subject {
        font-weight: 600;
        color: var(--text-dark);
    }
    .item-status {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .status-pending {
        background: rgba(255, 193, 7, 0.15);
        color: #d97706;
    }
    .status-sent {
        background: rgba(23, 162, 184, 0.15);
        color: var(--info-color);
    }
    .status-failed {
        background: rgba(220, 53, 69, 0.15);
        color: var(--danger-color);
    }
    .item-meta {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }
    .priority {
        padding: 2px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .priority-low {
        background: rgba(108, 117, 125, 0.15);
        color: var(--text-light);
    }
    .priority-normal {
        background: rgba(23, 162, 184, 0.15);
        color: var(--info-color);
    }
    .priority-high {
        background: rgba(255, 193, 7, 0.15);
        color: #d97706;
    }
    .priority-urgent {
        background: rgba(220, 53, 69, 0.15);
        color: var(--danger-color);
    }
    .date {
        font-size: 12px;
        color: var(--text-light);
    }
    .date i {
        margin-right: 5px;
    }
    .empty-state {
        text-align: center;
        padding: 40px;
        color: var(--text-light);
    }
    .empty-state i {
        font-size: 40px;
        margin-bottom: 15px;
        display: block;
    }

    @media (max-width: 600px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .form-actions {
            flex-direction: column;
        }
        .contact-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php require_once 'includes/footer.php'; ?>