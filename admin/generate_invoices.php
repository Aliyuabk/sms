<?php
require_once 'includes/header.php';

$page_title = "Generate Invoices";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $students = $_POST['students'] ?? [];
        $session_year = $_POST['session_year'];
        $semester = $_POST['semester'];
        $fee_type = $_POST['fee_type'];
        $amount = $_POST['amount'];
        $due_date = $_POST['due_date'];
        $description = $_POST['description'] ?? '';
        
        $generated_count = 0;
        $errors = [];
        
        foreach ($students as $student_id) {
            $student_id = (int)$student_id;
            
            // Check if invoice already exists for this student, session, semester, and fee type
            $check_sql = "SELECT fee_id FROM student_fees 
                          WHERE student_id = ? 
                          AND session_year = ? 
                          AND semester = ? 
                          AND fee_type = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$student_id, $session_year, $semester, $fee_type]);
            
            if ($check_stmt->fetch()) {
                $errors[] = "Invoice already exists for student ID: $student_id";
                continue;
            }
            
            // Generate invoice number
            $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(substr($fee_type, 0, 3)) . '-' . str_pad($generated_count + 1, 4, '0', STR_PAD_LEFT);
            
            // Insert invoice
            $insert_sql = "INSERT INTO student_fees 
                (student_id, session_year, semester, fee_type, description, amount, 
                 amount_paid, due_date, payment_deadline, status, invoice_number, created_date)
                VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, 'Pending', ?, CURDATE())";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $student_id,
                $session_year,
                $semester,
                $fee_type,
                $description,
                $amount,
                $due_date,
                $due_date,
                $invoice_number
            ]);
            
            $generated_count++;
            
            // Add notification for student
            $student_info = $pdo->prepare("SELECT first_name, last_name, email FROM students WHERE student_id = ?");
            $student_info->execute([$student_id]);
            $student = $student_info->fetch();
            
            $notification_sql = "INSERT INTO notifications 
                (student_id, title, message, notification_type, priority)
                VALUES (?, ?, ?, 'Financial', 'High')";
            
            $notification_stmt = $pdo->prepare($notification_sql);
            $notification_stmt->execute([
                $student_id,
                "New Invoice Generated",
                "A new $fee_type invoice of ₦" . number_format($amount, 2) . " has been generated for you. Due date: " . date('M d, Y', strtotime($due_date)),
            ]);
        }
        
        if ($generated_count > 0) {
            $_SESSION['success_message'] = "Successfully generated $generated_count invoices!";
            if (!empty($errors)) {
                $_SESSION['info_message'] = "Some invoices were skipped: " . implode(', ', $errors);
            }
            header("Location: manage_fees.php");
            exit();
        } else {
            $_SESSION['error_message'] = "No invoices were generated. " . (implode('<br>', $errors) ?? '');
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error generating invoices: " . $e->getMessage();
    }
}

// Get filter parameters
$level = $_GET['level'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$program_id = $_GET['program_id'] ?? '';
$status = $_GET['status'] ?? 'Active';

// Get fee structure
$fee_structure = $pdo->query("
    SELECT fs.*, p.program_name, d.department_name
    FROM fee_structure fs
    LEFT JOIN programs p ON fs.program_id = p.program_id
    LEFT JOIN departments d ON p.department_id = d.department_id
    ORDER BY fs.level, fs.fee_type
")->fetchAll();

// Get available filter options
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();

// Get students based on filters
$where_conditions = ["s.status = 'Active'"];
$params = [];

if (!empty($level)) {
    $where_conditions[] = "s.current_level = ?";
    $params[] = $level;
}

if (!empty($department_id)) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $department_id;
}

if (!empty($program_id)) {
    $where_conditions[] = "s.program_id = ?";
    $params[] = $program_id;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$students = $pdo->prepare("
    SELECT s.*, d.department_name, p.program_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    $where_sql
    ORDER BY s.matric_number
    LIMIT 100
");

$students->execute($params);
$student_list = $students->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Generate Invoices</h1>
            <p class="text-muted">Generate fee invoices for students</p>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <!-- Student Filters -->
        <div class="app-card app-card-settings shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Filter Students</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="GET" id="filterForm">
                    <div class="mb-3">
                        <label class="form-label">Level</label>
                        <select class="form-select" name="level">
                            <option value="">All Levels</option>
                            <?php for ($i = 100; $i <= 600; $i += 100): ?>
                            <option value="<?php echo $i; ?>" <?php echo $level == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> Level
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_id">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>" 
                                    <?php echo $department_id == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <select class="form-select" name="program_id">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['program_id']; ?>" 
                                    <?php echo $program_id == $prog['program_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['program_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Quick Templates -->
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Fee Templates</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="list-group">
                    <?php foreach ($fee_structure as $fee): ?>
                    <a href="#" class="list-group-item list-group-item-action fee-template" 
                       data-fee-type="<?php echo htmlspecialchars($fee['fee_type']); ?>"
                       data-amount="<?php echo $fee['amount']; ?>"
                       data-description="<?php echo htmlspecialchars($fee['description']); ?>">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($fee['fee_type']); ?></h6>
                            <small>₦<?php echo number_format($fee['amount'], 2); ?></small>
                        </div>
                        <small class="text-muted">
                            Level <?php echo $fee['level']; ?> • 
                            <?php echo htmlspecialchars($fee['program_name'] ?? 'All Programs'); ?>
                        </small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <!-- Invoice Form -->
        <div class="app-card app-card-settings shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Invoice Details</h6>
            </div>
            <div class="app-card-body p-3">
                <form method="POST" id="invoiceForm">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Fee Type *</label>
                            <input type="text" class="form-control" name="fee_type" required 
                                   placeholder="e.g., Tuition Fee, Medical Fee, etc.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount (₦) *</label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" required 
                                   placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Session Year *</label>
                            <select class="form-select" name="session_year" required>
                                <option value="">Select Session</option>
                                <?php 
                                $current_year = date('Y');
                                for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                    $session = ($i) . '/' . ($i + 1);
                                ?>
                                <option value="<?php echo $session; ?>" <?php echo $session === date('Y') . '/' . (date('Y') + 1) ? 'selected' : ''; ?>>
                                    <?php echo $session; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="semester" required>
                                <option value="1">First Semester</option>
                                <option value="2">Second Semester</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Due Date *</label>
                            <input type="date" class="form-control" name="due_date" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" 
                                  placeholder="Optional description for this invoice"></textarea>
                    </div>
                    
                    <!-- Student Selection -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6>Select Students</h6>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllStudents">
                                    Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAll">
                                    Deselect All
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th width="50"></th>
                                        <th>Student</th>
                                        <th>Matric No.</th>
                                        <th>Level</th>
                                        <th>Department</th>
                                    </tr>
                                </thead>
                                <tbody id="studentsTable">
                                    <?php foreach ($student_list as $student): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="student-checkbox" 
                                                   name="students[]" value="<?php echo $student['student_id']; ?>">
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['matric_number']); ?></td>
                                        <td><?php echo $student['current_level']; ?></td>
                                        <td><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <span id="selectedStudentsCount">0</span> students selected
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="manage_fees.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary" id="generateBtn">
                            <i class="fas fa-file-invoice me-2"></i>Generate Invoices
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Preview -->
        <div class="app-card app-card-settings shadow-sm mt-4">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Invoice Preview</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="border p-4 bg-light">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <h5 class="mb-1"><span id="previewFeeType">Tuition Fee</span></h5>
                            <p class="text-muted mb-0" id="previewDescription">Academic session tuition fee</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <h3 class="text-primary mb-0">₦<span id="previewAmount">0.00</span></h3>
                            <small class="text-muted">per student</small>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted">Session:</small><br>
                            <span id="previewSession">2023/2024</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Semester:</small><br>
                            <span id="previewSemester">First Semester</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted">Due Date:</small><br>
                            <span id="previewDueDate">Not set</span>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <small class="text-muted">Total for <span id="previewStudentCount">0</span> students: </small>
                        <h4 class="text-success">₦<span id="previewTotal">0.00</span></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update preview when form changes
document.querySelectorAll('#invoiceForm input, #invoiceForm select, #invoiceForm textarea').forEach(element => {
    element.addEventListener('change', updatePreview);
    element.addEventListener('input', updatePreview);
});

// Template selection
document.querySelectorAll('.fee-template').forEach(template => {
    template.addEventListener('click', function(e) {
        e.preventDefault();
        
        const feeType = this.dataset.feeType;
        const amount = this.dataset.amount;
        const description = this.dataset.description;
        
        document.querySelector('input[name="fee_type"]').value = feeType;
        document.querySelector('input[name="amount"]').value = amount;
        document.querySelector('textarea[name="description"]').value = description;
        
        updatePreview();
    });
});

// Student selection
document.getElementById('selectAllStudents').addEventListener('click', function() {
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.checked = true;
    });
    updateStudentSelection();
});

document.getElementById('deselectAll').addEventListener('click', function() {
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateStudentSelection();
});

document.querySelectorAll('.student-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateStudentSelection);
});

function updateStudentSelection() {
    const selected = document.querySelectorAll('.student-checkbox:checked').length;
    document.getElementById('selectedStudentsCount').textContent = selected;
    document.getElementById('previewStudentCount').textContent = selected;
    updatePreview();
}

function updatePreview() {
    // Get form values
    const feeType = document.querySelector('input[name="fee_type"]').value || 'Tuition Fee';
    const amount = parseFloat(document.querySelector('input[name="amount"]').value) || 0;
    const description = document.querySelector('textarea[name="description"]').value || 'Academic session fee';
    const session = document.querySelector('select[name="session_year"]').value || '2023/2024';
    const semester = document.querySelector('select[name="semester"]').value === '1' ? 'First Semester' : 'Second Semester';
    const dueDate = document.querySelector('input[name="due_date"]').value;
    const selectedStudents = document.querySelectorAll('.student-checkbox:checked').length;
    
    // Update preview elements
    document.getElementById('previewFeeType').textContent = feeType;
    document.getElementById('previewDescription').textContent = description;
    document.getElementById('previewAmount').textContent = amount.toFixed(2);
    document.getElementById('previewSession').textContent = session;
    document.getElementById('previewSemester').textContent = semester;
    document.getElementById('previewDueDate').textContent = dueDate ? new Date(dueDate).toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    }) : 'Not set';
    document.getElementById('previewStudentCount').textContent = selectedStudents;
    document.getElementById('previewTotal').textContent = (amount * selectedStudents).toFixed(2);
    
    // Update generate button
    const generateBtn = document.getElementById('generateBtn');
    generateBtn.disabled = !(feeType && amount > 0 && session && semester && dueDate && selectedStudents > 0);
}

// Form validation
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    const selectedStudents = document.querySelectorAll('.student-checkbox:checked').length;
    const amount = parseFloat(document.querySelector('input[name="amount"]').value);
    
    if (selectedStudents === 0) {
        e.preventDefault();
        alert('Please select at least one student.');
        return;
    }
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount greater than 0.');
        return;
    }
    
    if (!confirm(`Generate invoices for ${selectedStudents} students? Total amount: ₦${(amount * selectedStudents).toFixed(2)}`)) {
        e.preventDefault();
    }
});

// Initialize preview
updatePreview();
</script>

<?php
require_once 'includes/footer.php';
?>