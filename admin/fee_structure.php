<?php
require_once 'includes/header.php';

$page_title = "Fee Structure Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_fee_item'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO fee_structure 
                (session_year, level, program_id, fee_type, description, amount, due_date, is_mandatory, applicable_to)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $_POST['session_year'],
                $_POST['level'] ?: null,
                $_POST['program_id'] ?: null,
                $_POST['fee_type'],
                $_POST['description'],
                $_POST['amount'],
                $_POST['due_date'] ?: null,
                isset($_POST['is_mandatory']) ? 1 : 0,
                $_POST['applicable_to'] ?: 'All'
            ]);
            
            $_SESSION['success_message'] = "Fee structure item added successfully!";
            header("Location: fee_structure.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding fee item: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['update_fee_item'])) {
        try {
            $fee_structure_id = $_POST['fee_structure_id'];
            
            $stmt = $pdo->prepare("UPDATE fee_structure SET 
                session_year = ?,
                level = ?,
                program_id = ?,
                fee_type = ?,
                description = ?,
                amount = ?,
                due_date = ?,
                is_mandatory = ?,
                applicable_to = ?
                WHERE fee_structure_id = ?");
            
            $stmt->execute([
                $_POST['session_year'],
                $_POST['level'] ?: null,
                $_POST['program_id'] ?: null,
                $_POST['fee_type'],
                $_POST['description'],
                $_POST['amount'],
                $_POST['due_date'] ?: null,
                isset($_POST['is_mandatory']) ? 1 : 0,
                $_POST['applicable_to'] ?: 'All',
                $fee_structure_id
            ]);
            
            $_SESSION['success_message'] = "Fee structure item updated successfully!";
            header("Location: fee_structure.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating fee item: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['delete_item'])) {
        try {
            $fee_structure_id = $_POST['fee_structure_id'];
            
            // Check if fee structure is being used
            $check_sql = "SELECT COUNT(*) FROM student_fees WHERE fee_structure_id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$fee_structure_id]);
            $count = $check_stmt->fetchColumn();
            
            if ($count > 0) {
                $_SESSION['error_message'] = "Cannot delete fee item. It is being used by existing invoices.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM fee_structure WHERE fee_structure_id = ?");
                $stmt->execute([$fee_structure_id]);
                $_SESSION['success_message'] = "Fee structure item deleted successfully!";
            }
            
            header("Location: fee_structure.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error deleting fee item: " . $e->getMessage();
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$item_id = $_GET['id'] ?? 0;

// Get all fee structure items with related info
$fee_items = $pdo->query("
    SELECT fs.*, 
           p.program_name,
           p.department_id,
           d.department_name
    FROM fee_structure fs
    LEFT JOIN programs p ON fs.program_id = p.program_id
    LEFT JOIN departments d ON p.department_id = d.department_id
    ORDER BY fs.session_year DESC, fs.level, fs.fee_type
")->fetchAll();

// Get specific item for edit/view
$current_item = null;
if ($item_id && ($action === 'edit' || $action === 'view')) {
    $stmt = $pdo->prepare("
        SELECT fs.*, p.program_name, d.department_name
        FROM fee_structure fs
        LEFT JOIN programs p ON fs.program_id = p.program_id
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE fs.fee_structure_id = ?
    ");
    $stmt->execute([$item_id]);
    $current_item = $stmt->fetch();
}

// Get filter options
$programs = $pdo->query("SELECT * FROM programs ORDER BY program_name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

// Group fee items by session and level
$grouped_fees = [];
foreach ($fee_items as $item) {
    $key = $item['session_year'] . '|' . $item['level'];
    if (!isset($grouped_fees[$key])) {
        $grouped_fees[$key] = [
            'session_year' => $item['session_year'],
            'level' => $item['level'],
            'items' => []
        ];
    }
    $grouped_fees[$key]['items'][] = $item;
}
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Fee Structure Management</h1>
            <p class="text-muted">Define and manage fee structure for different programs and levels</p>
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

<?php if ($action === 'list' || $action === 'view'): ?>
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="app-card-title mb-0">Fee Structure</h5>
                    <a href="?action=add" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Fee Item
                    </a>
                </div>
                
                <?php if (empty($grouped_fees)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                    <h5>No fee structure defined</h5>
                    <p class="text-muted">Add fee items to create a fee structure</p>
                    <a href="?action=add" class="btn btn-primary mt-2">
                        <i class="fas fa-plus me-2"></i>Add First Fee Item
                    </a>
                </div>
                <?php else: ?>
                    <?php foreach ($grouped_fees as $group): ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">
                                Session: <?php echo htmlspecialchars($group['session_year']); ?> | 
                                Level: <?php echo $group['level'] ? $group['level'] . ' Level' : 'All Levels'; ?>
                            </h6>
                            <small class="text-muted"><?php echo count($group['items']); ?> items</small>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-4">
                                <thead class="table-light">
                                    <tr>
                                        <th>Fee Type</th>
                                        <th>Description</th>
                                        <th>Program</th>
                                        <th>Amount (₦)</th>
                                        <th>Due Date</th>
                                        <th>Mandatory</th>
                                        <th>Applicable To</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['items'] as $item): 
                                        $total_amount = array_sum(array_column($group['items'], 'amount'));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['fee_type']); ?></strong>
                                            <?php if (!$item['is_mandatory']): ?>
                                                <span class="badge bg-info ms-1">Optional</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                        <td>
                                            <?php if ($item['program_name']): ?>
                                                <?php echo htmlspecialchars($item['program_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['department_name']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">All Programs</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-primary">₦<?php echo number_format($item['amount'], 2); ?></strong><br>
                                            <small class="text-muted"><?php echo number_format(($item['amount'] / $total_amount) * 100, 1); ?>% of total</small>
                                        </td>
                                        <td>
                                            <?php echo $item['due_date'] ? date('M d, Y', strtotime($item['due_date'])) : 'Not set'; ?>
                                        </td>
                                        <td>
                                            <?php if ($item['is_mandatory']): ?>
                                                <span class="badge bg-success">Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['applicable_to']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?action=view&id=<?php echo $item['fee_structure_id']; ?>" 
                                                   class="btn btn-outline-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $item['fee_structure_id']; ?>" 
                                                   class="btn btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="confirmDelete(<?php echo $item['fee_structure_id']; ?>)" 
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Delete Form -->
                                            <form method="POST" id="delete-form-<?php echo $item['fee_structure_id']; ?>" class="d-none">
                                                <input type="hidden" name="fee_structure_id" value="<?php echo $item['fee_structure_id']; ?>">
                                                <input type="hidden" name="delete_item" value="1">
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-active">
                                        <td colspan="3" class="text-end"><strong>Total for this group:</strong></td>
                                        <td><strong>₦<?php echo number_format($total_amount, 2); ?></strong></td>
                                        <td colspan="4"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'view' && $current_item): ?>
<div class="row">
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm p-4">
            <div class="app-card-header">
                <h3 class="app-card-title">Fee Structure Details</h3>
            </div>
            <div class="app-card-body">
                <dl class="row">
                    <dt class="col-sm-3">Fee Type:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($current_item['fee_type']); ?></dd>
                    
                    <dt class="col-sm-3">Description:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($current_item['description']); ?></dd>
                    
                    <dt class="col-sm-3">Amount:</dt>
                    <dd class="col-sm-9">
                        <h4 class="text-primary mb-0">₦<?php echo number_format($current_item['amount'], 2); ?></h4>
                    </dd>
                    
                    <dt class="col-sm-3">Session Year:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($current_item['session_year']); ?></dd>
                    
                    <dt class="col-sm-3">Level:</dt>
                    <dd class="col-sm-9"><?php echo $current_item['level'] ? $current_item['level'] . ' Level' : 'All Levels'; ?></dd>
                    
                    <dt class="col-sm-3">Program:</dt>
                    <dd class="col-sm-9">
                        <?php if ($current_item['program_name']): ?>
                            <?php echo htmlspecialchars($current_item['program_name']); ?><br>
                            <small class="text-muted"><?php echo htmlspecialchars($current_item['department_name']); ?></small>
                        <?php else: ?>
                            <span class="text-muted">All Programs</span>
                        <?php endif; ?>
                    </dd>
                    
                    <dt class="col-sm-3">Due Date:</dt>
                    <dd class="col-sm-9">
                        <?php echo $current_item['due_date'] ? date('F j, Y', strtotime($current_item['due_date'])) : 'Not specified'; ?>
                    </dd>
                    
                    <dt class="col-sm-3">Mandatory:</dt>
                    <dd class="col-sm-9">
                        <?php if ($current_item['is_mandatory']): ?>
                            <span class="badge bg-success">Yes - Required for all students</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No - Optional</span>
                        <?php endif; ?>
                    </dd>
                    
                    <dt class="col-sm-3">Applicable To:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($current_item['applicable_to']); ?></dd>
                    
                    <dt class="col-sm-3">Created Date:</dt>
                    <dd class="col-sm-9"><?php echo date('F j, Y', strtotime($current_item['created_date'])); ?></dd>
                </dl>
                
                <div class="d-flex justify-content-between mt-4">
                    <a href="fee_structure.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                    <a href="?action=edit&id=<?php echo $current_item['fee_structure_id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Item
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Stats -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Usage Statistics</h6>
                <?php
                // Count how many students this applies to
                $applicable_sql = "
                    SELECT COUNT(*) as student_count
                    FROM students s
                    WHERE s.status = 'Active'
                    AND s.current_session = ?
                    AND s.current_level = ?
                ";
                
                $applicable_stmt = $pdo->prepare($applicable_sql);
                $applicable_stmt->execute([$current_item['session_year'], $current_item['level']]);
                $applicable_count = $applicable_stmt->fetchColumn();
                
                // Calculate potential revenue
                $potential_revenue = $applicable_count * $current_item['amount'];
                ?>
                
                <div class="stats-figure"><?php echo $applicable_count; ?></div>
                <p class="stats-detail mb-3">Active students applicable</p>
                
                <div class="stats-figure">₦<?php echo number_format($potential_revenue, 2); ?></div>
                <p class="stats-detail mb-3">Potential revenue</p>
            </div>
        </div>
        
        <!-- Related Actions -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Related Actions</h6>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="generate_invoices.php?template_id=<?php echo $current_item['fee_structure_id']; ?>" 
                       class="btn btn-outline-primary">
                        <i class="fas fa-file-invoice me-2"></i>Generate Invoices
                    </a>
                    <a href="manage_fees.php?fee_type=<?php echo urlencode($current_item['fee_type']); ?>" 
                       class="btn btn-outline-info">
                        <i class="fas fa-list me-2"></i>View Related Invoices
                    </a>
                    <a href="payment_reports.php?fee_type=<?php echo urlencode($current_item['fee_type']); ?>" 
                       class="btn btn-outline-success">
                        <i class="fas fa-chart-bar me-2"></i>View Payment Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-settings shadow-sm p-4">
            <div class="app-card-header">
                <h3 class="app-card-title">
                    <?php echo $action === 'add' ? 'Add New Fee Item' : 'Edit Fee Item'; ?>
                </h3>
            </div>
            <div class="app-card-body">
                <form method="POST" id="feeForm">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="fee_structure_id" value="<?php echo $current_item['fee_structure_id']; ?>">
                        <input type="hidden" name="update_fee_item" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_fee_item" value="1">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="fee_type" class="form-label">Fee Type *</label>
                                <input type="text" class="form-control" id="fee_type" name="fee_type" 
                                       value="<?php echo htmlspecialchars($current_item['fee_type'] ?? ''); ?>" 
                                       required placeholder="e.g., Tuition Fee, Medical Fee, etc.">
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description *</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="2" required><?php echo htmlspecialchars($current_item['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount (₦) *</label>
                                <input type="number" class="form-control" id="amount" name="amount" 
                                       value="<?php echo htmlspecialchars($current_item['amount'] ?? ''); ?>" 
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="session_year" class="form-label">Session Year *</label>
                                <select class="form-select" id="session_year" name="session_year" required>
                                    <option value="">Select Session</option>
                                    <?php 
                                    $current_year = date('Y');
                                    for ($i = $current_year - 5; $i <= $current_year + 1; $i++): 
                                        $session = ($i) . '/' . ($i + 1);
                                    ?>
                                    <option value="<?php echo $session; ?>" 
                                            <?php echo ($current_item['session_year'] ?? '') === $session ? 'selected' : ''; ?>>
                                        <?php echo $session; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="level" class="form-label">Level (Optional)</label>
                                    <select class="form-select" id="level" name="level">
                                        <option value="">All Levels</option>
                                        <?php for ($i = 100; $i <= 600; $i += 100): ?>
                                        <option value="<?php echo $i; ?>" 
                                                <?php echo ($current_item['level'] ?? '') == $i ? 'selected' : ''; ?>>
                                            <?php echo $i; ?> Level
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="program_id" class="form-label">Program (Optional)</label>
                                    <select class="form-select" id="program_id" name="program_id">
                                        <option value="">All Programs</option>
                                        <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo $program['program_id']; ?>" 
                                                <?php echo ($current_item['program_id'] ?? '') == $program['program_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($program['program_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="due_date" class="form-label">Due Date (Optional)</label>
                                <input type="date" class="form-control" id="due_date" name="due_date" 
                                       value="<?php echo $current_item['due_date'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="applicable_to" class="form-label">Applicable To *</label>
                                <select class="form-select" id="applicable_to" name="applicable_to" required>
                                    <option value="All" <?php echo ($current_item['applicable_to'] ?? 'All') === 'All' ? 'selected' : ''; ?>>All Students</option>
                                    <option value="New Students" <?php echo ($current_item['applicable_to'] ?? '') === 'New Students' ? 'selected' : ''; ?>>New Students Only</option>
                                    <option value="Returning Students" <?php echo ($current_item['applicable_to'] ?? '') === 'Returning Students' ? 'selected' : ''; ?>>Returning Students Only</option>
                                    <option value="Final Year" <?php echo ($current_item['applicable_to'] ?? '') === 'Final Year' ? 'selected' : ''; ?>>Final Year Students Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_mandatory" name="is_mandatory" 
                                           value="1" <?php echo ($current_item['is_mandatory'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_mandatory">Mandatory Fee (Required for all applicable students)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="fee_structure.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?php echo $action === 'add' ? 'Create Fee Item' : 'Update Fee Item'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function confirmDelete(itemId) {
    if (confirm('Are you sure you want to delete this fee structure item? This action cannot be undone.')) {
        document.getElementById(`delete-form-${itemId}`).submit();
    }
}

// Form validation
document.getElementById('feeForm')?.addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value);
    
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount greater than 0.');
        document.getElementById('amount').focus();
        return false;
    }
    
    return true;
});
</script>

<?php
require_once 'includes/footer.php';
?>