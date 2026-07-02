<?php
// email_templates.php - Email Templates Management
ob_start();
require_once 'includes/header.php';

$page_title = "Email Templates";

// ==================== DATABASE CONNECTION ====================
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'student_portal_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// ==================== CSRF PROTECTION ====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== TEMPLATE TYPES ====================
$template_types = [
    'Academic' => 'Academic',
    'Financial' => 'Financial',
    'Hostel' => 'Hostel',
    'General' => 'General',
    'Urgent' => 'Urgent',
    'Reminder' => 'Reminder',
    'Welcome' => 'Welcome'
];

// Available variables for templates
$available_variables = [
    '{student_name}' => 'Student Full Name',
    '{matric_number}' => 'Matriculation Number',
    '{email}' => 'Student Email',
    '{phone}' => 'Student Phone',
    '{department}' => 'Department Name',
    '{program}' => 'Program Name',
    '{level}' => 'Current Level',
    '{session}' => 'Current Academic Session',
    '{semester}' => 'Current Semester',
    '{gpa}' => 'Student GPA',
    '{cgpa}' => 'Student CGPA',
    '{fee_amount}' => 'Fee Amount',
    '{fee_balance}' => 'Fee Balance',
    '{due_date}' => 'Payment Due Date',
    '{hostel_name}' => 'Hostel Name',
    '{room_number}' => 'Room Number',
    '{bed_number}' => 'Bed Number',
    '{result_status}' => 'Result Status',
    '{course_code}' => 'Course Code',
    '{course_title}' => 'Course Title',
    '{grade}' => 'Grade',
    '{total_score}' => 'Total Score',
    '{admin_name}' => 'Admin Name',
    '{date}' => 'Current Date',
    '{time}' => 'Current Time',
    '{portal_link}' => 'Portal Login Link',
    '{support_email}' => 'Support Email',
    '{support_phone}' => 'Support Phone'
];

// ==================== HANDLE CREATE/UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid security token. Please refresh the page.";
        header("Location: email_templates.php");
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    $admin_id = $_SESSION['admin_id'] ?? 1;
    
    try {
        if ($action === 'create') {
            $template_name = trim($_POST['template_name']);
            $template_type = $_POST['template_type'];
            $subject = trim($_POST['subject']);
            $content = $_POST['content'];
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Check if template name exists
            $check_stmt = $pdo->prepare("SELECT template_id FROM email_templates WHERE template_name = ?");
            $check_stmt->execute([$template_name]);
            if ($check_stmt->fetch()) {
                throw new Exception("Template name already exists. Please use a different name.");
            }
            
            $insert_stmt = $pdo->prepare("
                INSERT INTO email_templates (template_name, template_type, subject, content, description, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->execute([$template_name, $template_type, $subject, $content, $description, $is_active, $admin_id]);
            
            $_SESSION['success_message'] = "Email template created successfully!";
            
        } elseif ($action === 'update') {
            $template_id = (int)$_POST['template_id'];
            $template_name = trim($_POST['template_name']);
            $template_type = $_POST['template_type'];
            $subject = trim($_POST['subject']);
            $content = $_POST['content'];
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $update_stmt = $pdo->prepare("
                UPDATE email_templates SET 
                    template_name = ?, template_type = ?, subject = ?, 
                    content = ?, description = ?, is_active = ?, updated_at = NOW()
                WHERE template_id = ?
            ");
            $update_stmt->execute([$template_name, $template_type, $subject, $content, $description, $is_active, $template_id]);
            
            $_SESSION['success_message'] = "Email template updated successfully!";
            
        } elseif ($action === 'delete') {
            $template_id = (int)$_POST['template_id'];
            
            $delete_stmt = $pdo->prepare("DELETE FROM email_templates WHERE template_id = ?");
            $delete_stmt->execute([$template_id]);
            
            $_SESSION['success_message'] = "Email template deleted successfully!";
            
        } elseif ($action === 'duplicate') {
            $template_id = (int)$_POST['template_id'];
            
            // Get original template
            $orig_stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_id = ?");
            $orig_stmt->execute([$template_id]);
            $original = $orig_stmt->fetch();
            
            if ($original) {
                $new_name = $original['template_name'] . " (Copy)";
                $insert_stmt = $pdo->prepare("
                    INSERT INTO email_templates (template_name, template_type, subject, content, description, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->execute([
                    $new_name, $original['template_type'], $original['subject'],
                    $original['content'], $original['description'], 0, $admin_id
                ]);
                $_SESSION['success_message'] = "Template duplicated successfully!";
            } else {
                throw new Exception("Template not found.");
            }
        } elseif ($action === 'toggle_status') {
            $template_id = (int)$_POST['template_id'];
            $current_status = (int)$_POST['current_status'];
            $new_status = $current_status ? 0 : 1;
            
            $update_stmt = $pdo->prepare("UPDATE email_templates SET is_active = ?, updated_at = NOW() WHERE template_id = ?");
            $update_stmt->execute([$new_status, $template_id]);
            
            $_SESSION['success_message'] = "Template status updated successfully!";
        } elseif ($action === 'test_send') {
            $template_id = (int)$_POST['template_id'];
            $test_email = trim($_POST['test_email']);
            
            if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Please enter a valid email address.");
            }
            
            // Get template
            $template_stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_id = ?");
            $template_stmt->execute([$template_id]);
            $template = $template_stmt->fetch();
            
            if (!$template) {
                throw new Exception("Template not found.");
            }
            
            // Replace variables with test data
            $test_data = [
                '{student_name}' => 'Test Student',
                '{matric_number}' => 'TEST/2024/001',
                '{email}' => $test_email,
                '{phone}' => '08012345678',
                '{department}' => 'Computer Science',
                '{program}' => 'B.Sc Computer Science',
                '{level}' => '200',
                '{session}' => '2024/2025',
                '{semester}' => 'First Semester',
                '{gpa}' => '4.25',
                '{cgpa}' => '4.10',
                '{fee_amount}' => '100,000',
                '{fee_balance}' => '25,000',
                '{due_date}' => date('d/m/Y', strtotime('+30 days')),
                '{hostel_name}' => 'Silver Hostel',
                '{room_number}' => 'A101',
                '{bed_number}' => '3',
                '{result_status}' => 'Published',
                '{course_code}' => 'CSC101',
                '{course_title}' => 'Introduction to Programming',
                '{grade}' => 'A',
                '{total_score}' => '85%',
                '{admin_name}' => $admin_name,
                '{date}' => date('d/m/Y'),
                '{time}' => date('h:i A'),
                '{portal_link}' => 'https://portal.university.edu',
                '{support_email}' => 'support@university.edu',
                '{support_phone}' => '08012345678'
            ];
            
            $subject = str_replace(array_keys($test_data), array_values($test_data), $template['subject']);
            $content = str_replace(array_keys($test_data), array_values($test_data), $template['content']);
            
            // Send test email (simulated - in production use mail() or PHPMailer)
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . $admin_email . "\r\n";
            
            $mail_sent = mail($test_email, $subject, $content, $headers);
            
            if ($mail_sent) {
                $_SESSION['success_message'] = "Test email sent successfully to " . $test_email;
            } else {
                throw new Exception("Failed to send test email. Please check mail configuration.");
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header("Location: email_templates.php");
    exit();
}

// ==================== GET TEMPLATES ====================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$template_type = isset($_GET['template_type']) ? $_GET['template_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT * FROM email_templates WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (template_name LIKE ? OR subject LIKE ? OR description LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if (!empty($template_type)) {
    $query .= " AND template_type = ?";
    $params[] = $template_type;
}

if ($status === 'active') {
    $query .= " AND is_active = 1";
} elseif ($status === 'inactive') {
    $query .= " AND is_active = 0";
}

$query .= " ORDER BY created_at DESC";

$templates_stmt = $pdo->prepare($query);
$templates_stmt->execute($params);
$templates = $templates_stmt->fetchAll();

// Get template for editing
$edit_template = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_id = ?");
    $edit_stmt->execute([(int)$_GET['edit']]);
    $edit_template = $edit_stmt->fetch();
}

// Get statistics
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        template_type,
        COUNT(*) as type_count
    FROM email_templates
    GROUP BY template_type WITH ROLLUP
");
$stats = $stats_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        .card { border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; }
        .template-card { transition: transform 0.2s; cursor: pointer; }
        .template-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .filter-section { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .variable-badge { cursor: pointer; transition: all 0.2s; }
        .variable-badge:hover { transform: scale(1.05); background-color: #0d6efd !important; color: white !important; }
        .ql-container { min-height: 200px; }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; border: none; }
        .nav-tabs .nav-link.active { color: #4361ee; border-bottom: 2px solid #4361ee; background: transparent; }
        .preview-content { max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 15px; border-radius: 8px; font-size: 14px; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="app-page-title mb-1"><i class="fas fa-envelope me-2 text-primary"></i>Email Templates</h1>
            <p class="text-muted mb-0">Create, manage and customize email templates for automated communications</p>
        </div>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal" onclick="resetForm()">
                <i class="fas fa-plus me-2"></i>Create Template
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='send_notifications.php'">
                <i class="fas fa-paper-plane me-2"></i>Send Notification
            </button>
        </div>
    </div>
    
    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card bg-primary text-white stat-card"><div class="card-body"><h6>Total Templates</h6><h2><?php echo array_sum(array_column($stats, 'total')) ?? 0; ?></h2></div></div></div>
        <div class="col-md-3"><div class="card bg-success text-white stat-card"><div class="card-body"><h6>Active Templates</h6><h2><?php echo $stats[0]['active'] ?? 0; ?></h2></div></div></div>
        <div class="col-md-3"><div class="card bg-secondary text-white stat-card"><div class="card-body"><h6>Inactive</h6><h2><?php echo $stats[0]['inactive'] ?? 0; ?></h2></div></div></div>
        <div class="col-md-3"><div class="card bg-info text-white stat-card"><div class="card-body"><h6>Template Types</h6><h2><?php echo count(array_filter($stats, fn($s) => $s['template_type'] !== null)); ?></h2></div></div></div>
    </div>
    
    <!-- Filters -->
    <div class="filter-section">
        <h6 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Templates</h6>
        <form method="GET" class="row g-3">
            <div class="col-md-4"><label class="form-label">Search</label><input type="text" class="form-control" name="search" placeholder="Template name, subject..." value="<?php echo htmlspecialchars($search); ?>"></div>
            <div class="col-md-3"><label class="form-label">Template Type</label><select class="form-select" name="template_type"><option value="">All Types</option><?php foreach($template_types as $key => $type): ?><option value="<?php echo $key; ?>" <?php echo $template_type == $key ? 'selected' : ''; ?>><?php echo $type; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option><option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
            <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary me-2"><i class="fas fa-search me-1"></i>Filter</button><a href="email_templates.php" class="btn btn-outline-secondary">Reset</a></div>
        </form>
    </div>
    
    <!-- Templates Grid -->
    <div class="row">
        <?php foreach ($templates as $template): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card template-card h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <div><i class="fas fa-envelope-open-text me-2 text-primary"></i><strong><?php echo htmlspecialchars($template['template_name']); ?></strong></div>
                    <span class="badge bg-<?php echo $template['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?></span>
                </div>
                <div class="card-body">
                    <div class="mb-2"><span class="badge bg-info"><?php echo $template['template_type']; ?></span></div>
                    <div class="small text-muted mb-2"><i class="fas fa-tag me-1"></i>Subject: <?php echo htmlspecialchars(substr($template['subject'], 0, 50)) . (strlen($template['subject']) > 50 ? '...' : ''); ?></div>
                    <?php if ($template['description']): ?><div class="small text-muted mb-2"><i class="fas fa-align-left me-1"></i><?php echo htmlspecialchars(substr($template['description'], 0, 80)); ?></div><?php endif; ?>
                    <div class="small text-muted"><i class="fas fa-clock me-1"></i>Created: <?php echo date('d/m/Y', strtotime($template['created_at'])); ?></div>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="btn-group w-100">
                        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate(<?php echo htmlspecialchars(json_encode($template)); ?>)"><i class="fas fa-edit me-1"></i>Edit</button>
                        <button class="btn btn-sm btn-outline-info" onclick="previewTemplate(<?php echo htmlspecialchars(json_encode($template)); ?>)"><i class="fas fa-eye me-1"></i>Preview</button>
                        <button class="btn btn-sm btn-outline-success" onclick="testTemplate(<?php echo $template['template_id']; ?>, '<?php echo addslashes($template['template_name']); ?>')"><i class="fas fa-paper-plane me-1"></i>Test</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="duplicateTemplate(<?php echo $template['template_id']; ?>)"><i class="fas fa-copy me-1"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(<?php echo $template['template_id']; ?>, '<?php echo addslashes($template['template_name']); ?>')"><i class="fas fa-trash me-1"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($templates)): ?>
        <div class="col-12"><div class="alert alert-info text-center py-5"><i class="fas fa-inbox fa-3x mb-3 d-block"></i><h5>No email templates found</h5><p>Click "Create Template" to add your first email template.</p></div></div>
        <?php endif; ?>
    </div>
</div>

<!-- Template Modal (Create/Edit) -->
<div class="modal fade" id="templateModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i><span id="modalTitle">Create Email Template</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="templateForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="template_id" id="templateId" value="0">
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" id="formTabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#basicTab" type="button"><i class="fas fa-info-circle me-1"></i>Basic Information</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#contentTab" type="button"><i class="fas fa-edit me-1"></i>Email Content</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#variablesTab" type="button"><i class="fas fa-code me-1"></i>Available Variables</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="basicTab">
                            <div class="row">
                                <div class="col-md-6 mb-3"><label class="form-label fw-bold">Template Name *</label><input type="text" class="form-control" name="template_name" id="templateName" required placeholder="e.g., Welcome Email, Fee Reminder"></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-bold">Template Type *</label><select class="form-select" name="template_type" id="templateType" required><?php foreach($template_types as $key => $type): ?><option value="<?php echo $key; ?>"><?php echo $type; ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-12 mb-3"><label class="form-label fw-bold">Email Subject *</label><input type="text" class="form-control" name="subject" id="templateSubject" required placeholder="Email subject line"></div>
                                <div class="col-md-12 mb-3"><label class="form-label fw-bold">Description</label><textarea class="form-control" name="description" id="templateDescription" rows="2" placeholder="Brief description of when to use this template"></textarea></div>
                                <div class="col-md-12 mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="templateActive" value="1" checked><label class="form-check-label fw-bold">Active</label><div class="form-text">Inactive templates won't be available for sending notifications</div></div></div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="contentTab">
                            <label class="form-label fw-bold">Email Content *</label>
                            <div id="editor" style="min-height: 300px;"></div>
                            <input type="hidden" name="content" id="templateContent">
                        </div>
                        <div class="tab-pane fade" id="variablesTab">
                            <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i><strong>Available Variables</strong><br>Click on any variable to insert it into your email content.</div>
                            <div class="row">
                                <?php foreach ($available_variables as $var => $desc): ?>
                                <div class="col-md-3 mb-2"><span class="badge bg-secondary variable-badge p-2 d-block text-center" onclick="insertVariable('<?php echo $var; ?>')"><code><?php echo $var; ?></code><br><small><?php echo $desc; ?></small></span></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white"><h5 class="modal-title"><i class="fas fa-eye me-2"></i>Template Preview</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><h6 id="previewSubject"></h6><div id="previewContent"></div></div>
        </div>
    </div>
</div>

<!-- Test Email Modal -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send Test Email</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="test_send">
                <input type="hidden" name="template_id" id="testTemplateId">
                <div class="modal-body"><label class="form-label fw-bold">Send test email to:</label><input type="email" class="form-control" name="test_email" placeholder="recipient@example.com" required><div class="form-text mt-2">A test email will be sent using sample data for all variables.</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-2"></i>Send Test</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
// Initialize Quill editor
var quill = new Quill('#editor', { theme: 'snow', placeholder: 'Write your email content here...' });

function insertVariable(variable) {
    const range = quill.getSelection();
    if (range) { quill.insertText(range.index, variable); }
}

function getEditorContent() { document.getElementById('templateContent').value = quill.root.innerHTML; }

document.getElementById('templateForm').addEventListener('submit', function(e) { getEditorContent(); });

function resetForm() {
    document.getElementById('modalTitle').innerText = 'Create Email Template';
    document.getElementById('formAction').value = 'create';
    document.getElementById('templateId').value = '0';
    document.getElementById('templateName').value = '';
    document.getElementById('templateType').value = 'General';
    document.getElementById('templateSubject').value = '';
    document.getElementById('templateDescription').value = '';
    document.getElementById('templateActive').checked = true;
    quill.root.innerHTML = '';
    document.querySelector('#formTabs button[data-bs-target="#basicTab"]').click();
}

function editTemplate(template) {
    document.getElementById('modalTitle').innerText = 'Edit Email Template';
    document.getElementById('formAction').value = 'update';
    document.getElementById('templateId').value = template.template_id;
    document.getElementById('templateName').value = template.template_name;
    document.getElementById('templateType').value = template.template_type;
    document.getElementById('templateSubject').value = template.subject;
    document.getElementById('templateDescription').value = template.description || '';
    document.getElementById('templateActive').checked = template.is_active == 1;
    quill.root.innerHTML = template.content;
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}

function previewTemplate(template) {
    document.getElementById('previewSubject').innerHTML = '<strong>Subject:</strong> ' + template.subject;
    document.getElementById('previewContent').innerHTML = template.content;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}

function testTemplate(id, name) {
    document.getElementById('testTemplateId').value = id;
    new bootstrap.Modal(document.getElementById('testModal')).show();
}

function duplicateTemplate(id) {
    if (confirm('Duplicate this template?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="template_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteTemplate(id, name) {
    if (confirm('Delete template "' + name + '"? This action cannot be undone.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleStatus(id, currentStatus) {
    if (confirm(currentStatus == 1 ? 'Deactivate this template?' : 'Activate this template?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="template_id" value="' + id + '"><input type="hidden" name="current_status" value="' + currentStatus + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
.ql-editor { min-height: 250px; background: white; }
.variable-badge { cursor: pointer; transition: all 0.2s; font-family: monospace; }
.variable-badge:hover { transform: scale(1.02); background-color: #0d6efd !important; color: white !important; }
.template-card { transition: transform 0.2s, box-shadow 0.2s; }
.template-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
</style>

<?php require_once 'includes/footer.php'; ?>