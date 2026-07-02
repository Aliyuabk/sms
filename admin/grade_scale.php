<?php
require_once 'includes/header.php';

$page_title = "Grade Scale Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_grade_scale'])) {
        // Add new grade scale
        try {
            $stmt = $pdo->prepare("INSERT INTO grade_scales 
                (scale_name, description, is_active, created_by) 
                VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_POST['scale_name'],
                $_POST['description'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $admin_id
            ]);
            
            $scale_id = $pdo->lastInsertId();
            
            // Add grade entries
            if (isset($_POST['grades']) && is_array($_POST['grades'])) {
                foreach ($_POST['grades'] as $grade) {
                    if (!empty($grade['grade_symbol']) && !empty($grade['grade_point'])) {
                        $stmt = $pdo->prepare("INSERT INTO grade_entries 
                            (scale_id, grade_symbol, grade_name, grade_point, lower_bound, upper_bound, remarks)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $scale_id,
                            $grade['grade_symbol'],
                            $grade['grade_name'] ?? null,
                            $grade['grade_point'],
                            $grade['lower_bound'] ?? null,
                            $grade['upper_bound'] ?? null,
                            $grade['remarks'] ?? null
                        ]);
                    }
                }
            }
            
            $_SESSION['success_message'] = "Grade scale added successfully!";
            header("Location: grade_scale.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding grade scale: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['update_grade_scale'])) {
        // Update grade scale
        try {
            $scale_id = $_POST['scale_id'];
            
            $stmt = $pdo->prepare("UPDATE grade_scales SET 
                scale_name = ?,
                description = ?,
                is_active = ?
                WHERE scale_id = ?");
            $stmt->execute([
                $_POST['scale_name'],
                $_POST['description'] ?? null,
                isset($_POST['is_active']) ? 1 : 0,
                $scale_id
            ]);
            
            // Update existing grades
            if (isset($_POST['grades']) && is_array($_POST['grades'])) {
                // Delete existing grades first
                $stmt = $pdo->prepare("DELETE FROM grade_entries WHERE scale_id = ?");
                $stmt->execute([$scale_id]);
                
                // Insert new grades
                foreach ($_POST['grades'] as $grade) {
                    if (!empty($grade['grade_symbol']) && !empty($grade['grade_point'])) {
                        $stmt = $pdo->prepare("INSERT INTO grade_entries 
                            (scale_id, grade_symbol, grade_name, grade_point, lower_bound, upper_bound, remarks)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $scale_id,
                            $grade['grade_symbol'],
                            $grade['grade_name'] ?? null,
                            $grade['grade_point'],
                            $grade['lower_bound'] ?? null,
                            $grade['upper_bound'] ?? null,
                            $grade['remarks'] ?? null
                        ]);
                    }
                }
            }
            
            $_SESSION['success_message'] = "Grade scale updated successfully!";
            header("Location: grade_scale.php");
            exit();
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error updating grade scale: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['delete_scale'])) {
        // Delete grade scale
        try {
            $scale_id = $_POST['scale_id'];
            
            // Check if scale is in use
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE grade_scale_id = ?");
            $stmt->execute([$scale_id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $_SESSION['error_message'] = "Cannot delete grade scale. It is being used by programs.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM grade_entries WHERE scale_id = ?");
                $stmt->execute([$scale_id]);
                
                $stmt = $pdo->prepare("DELETE FROM grade_scales WHERE scale_id = ?");
                $stmt->execute([$scale_id]);
                
                $_SESSION['success_message'] = "Grade scale deleted successfully!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error deleting grade scale: " . $e->getMessage();
        }
        
        header("Location: grade_scale.php");
        exit();
    }
    elseif (isset($_POST['set_default'])) {
        // Set default grade scale
        try {
            $scale_id = $_POST['scale_id'];
            
            // First, unset all as default
            $stmt = $pdo->prepare("UPDATE grade_scales SET is_default = 0");
            $stmt->execute();
            
            // Set this as default
            $stmt = $pdo->prepare("UPDATE grade_scales SET is_default = 1 WHERE scale_id = ?");
            $stmt->execute([$scale_id]);
            
            $_SESSION['success_message'] = "Default grade scale updated!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error setting default grade scale: " . $e->getMessage();
        }
        
        header("Location: grade_scale.php");
        exit();
    }
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$scale_id = $_GET['id'] ?? 0;

// Get all grade scales - UPDATED QUERY to use full_name instead of first_name/last_name
$grade_scales = $pdo->query("
    SELECT gs.*, 
           COUNT(ge.entry_id) as grade_count,
           a.full_name as created_by_name
    FROM grade_scales gs
    LEFT JOIN grade_entries ge ON gs.scale_id = ge.scale_id
    LEFT JOIN admin_users a ON gs.created_by = a.admin_id
    GROUP BY gs.scale_id
    ORDER BY gs.is_default DESC, gs.scale_name
")->fetchAll();

// Get specific grade scale for edit/view
$current_scale = null;
$grade_entries = [];
if ($scale_id && ($action === 'edit' || $action === 'view')) {
    $stmt = $pdo->prepare("SELECT * FROM grade_scales WHERE scale_id = ?");
    $stmt->execute([$scale_id]);
    $current_scale = $stmt->fetch();
    
    if ($current_scale) {
        $stmt = $pdo->prepare("SELECT * FROM grade_entries WHERE scale_id = ? ORDER BY grade_point DESC");
        $stmt->execute([$scale_id]);
        $grade_entries = $stmt->fetchAll();
    }
}

// Get programs using each scale
$programs_by_scale = [];
foreach ($grade_scales as $scale) {
    $stmt = $pdo->prepare("
        SELECT p.program_name, d.department_name 
        FROM programs p
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.grade_scale_id = ?
    ");
    $stmt->execute([$scale['scale_id']]);
    $programs_by_scale[$scale['scale_id']] = $stmt->fetchAll();
}
?>

<div class="row">
    <div class="col-12">
        <div class="page-header mb-4">
            <h1 class="page-title">Grade Scale Management</h1>
            <p class="text-muted">Manage grade scales used for calculating GPA and CGPA</p>
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

<?php if ($action === 'list'): ?>
<div class="row">
    <div class="col-md-12">
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="app-card-title mb-0">Grade Scales</h5>
                    <a href="?action=add" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add New Scale
                    </a>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover app-table-hover">
                        <thead>
                            <tr>
                                <th>Scale Name</th>
                                <th>Grades</th>
                                <th>Used by Programs</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grade_scales as $scale): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($scale['scale_name']); ?></strong>
                                    <?php if ($scale['is_default']): ?>
                                        <span class="badge bg-success ms-2">Default</span>
                                    <?php endif; ?>
                                    <?php if ($scale['is_active']): ?>
                                        <span class="badge bg-primary ms-2">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary ms-2">Inactive</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($scale['description'] ?? ''); ?></small>
                                </td>
                                <td><?php echo $scale['grade_count']; ?> grades</td>
                                <td>
                                    <?php if (!empty($programs_by_scale[$scale['scale_id']])): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" 
                                                    data-bs-toggle="dropdown">
                                                <?php echo count($programs_by_scale[$scale['scale_id']]); ?> programs
                                            </button>
                                            <div class="dropdown-menu">
                                                <?php foreach ($programs_by_scale[$scale['scale_id']] as $program): ?>
                                                <span class="dropdown-item-text">
                                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($program['department_name']); ?></small>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Not used</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="scale_id" value="<?php echo $scale['scale_id']; ?>">
                                        <?php if (!$scale['is_default']): ?>
                                            <button type="submit" name="set_default" class="btn btn-sm btn-outline-success">
                                                Set as Default
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($scale['created_by_name'] ?? 'System'); ?><br>
                                    <small class="text-muted"><?php echo date('M d, Y', strtotime($scale['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="?action=view&id=<?php echo $scale['scale_id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?action=edit&id=<?php echo $scale['scale_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmDelete(<?php echo $scale['scale_id']; ?>)" 
                                                title="Delete" <?php echo !empty($programs_by_scale[$scale['scale_id']]) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Delete Form -->
                                    <form method="POST" id="delete-form-<?php echo $scale['scale_id']; ?>" class="d-none">
                                        <input type="hidden" name="scale_id" value="<?php echo $scale['scale_id']; ?>">
                                        <input type="hidden" name="delete_scale" value="1">
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'view' && $current_scale): ?>
<div class="row">
    <div class="col-md-8">
        <div class="app-card app-card-settings shadow-sm p-4">
            <div class="app-card-header">
                <h3 class="app-card-title"><?php echo htmlspecialchars($current_scale['scale_name']); ?></h3>
                <div class="text-muted"><?php echo htmlspecialchars($current_scale['description'] ?? ''); ?></div>
            </div>
            <div class="app-card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Grade Symbol</th>
                                <th>Grade Name</th>
                                <th>Grade Point</th>
                                <th>Score Range</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grade_entries as $grade): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($grade['grade_symbol']); ?></strong></td>
                                <td><?php echo htmlspecialchars($grade['grade_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($grade['grade_point']); ?></td>
                                <td>
                                    <?php if ($grade['lower_bound'] !== null && $grade['upper_bound'] !== null): ?>
                                        <?php echo $grade['lower_bound']; ?> - <?php echo $grade['upper_bound']; ?>%
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($grade['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4">
                    <h6>Scale Information</h6>
                    <dl class="row">
                        <dt class="col-sm-3">Status:</dt>
                        <dd class="col-sm-9">
                            <?php echo $current_scale['is_active'] ? 'Active' : 'Inactive'; ?>
                            <?php echo $current_scale['is_default'] ? ' (Default Scale)' : ''; ?>
                        </dd>
                        
                        <dt class="col-sm-3">Created:</dt>
                        <dd class="col-sm-9"><?php echo date('F j, Y, g:i a', strtotime($current_scale['created_at'])); ?></dd>
                        
                        <dt class="col-sm-3">Last Updated:</dt>
                        <dd class="col-sm-9"><?php echo $current_scale['updated_at'] ? date('F j, Y, g:i a', strtotime($current_scale['updated_at'])) : 'Never'; ?></dd>
                    </dl>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <a href="grade_scale.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                    <a href="?action=edit&id=<?php echo $current_scale['scale_id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Scale
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Quick Info -->
        <div class="app-card app-card-stat shadow-sm mb-4">
            <div class="app-card-body p-3">
                <h6 class="stats-type mb-3">Scale Statistics</h6>
                <div class="stats-figure"><?php echo count($grade_entries); ?></div>
                <p class="stats-detail mb-3">Total Grades in this scale</p>
                
                <h6 class="stats-type mb-3">GPA Calculation</h6>
                <p class="small">
                    <strong>Formula:</strong> GPA = Σ(Course Units × Grade Points) ÷ Σ(Course Units)
                </p>
                <p class="small">
                    Grade points are multiplied by course units to calculate weighted points.
                </p>
            </div>
        </div>
        
        <!-- Programs using this scale -->
        <div class="app-card app-card-details shadow-sm">
            <div class="app-card-header p-3">
                <h6 class="app-card-title">Programs Using This Scale</h6>
            </div>
            <div class="app-card-body p-3">
                <?php if (!empty($programs_by_scale[$current_scale['scale_id']])): ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($programs_by_scale[$current_scale['scale_id']] as $program): ?>
                        <li class="mb-2">
                            <i class="fas fa-graduation-cap text-primary me-2"></i>
                            <strong><?php echo htmlspecialchars($program['program_name']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($program['department_name']); ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0">No programs are currently using this grade scale.</p>
                <?php endif; ?>
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
                    <?php echo $action === 'add' ? 'Add New Grade Scale' : 'Edit Grade Scale'; ?>
                </h3>
            </div>
            <div class="app-card-body">
                <form method="POST" id="gradeScaleForm">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="scale_id" value="<?php echo $current_scale['scale_id']; ?>">
                        <input type="hidden" name="update_grade_scale" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_grade_scale" value="1">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="scale_name" class="form-label">Scale Name *</label>
                                <input type="text" class="form-control" id="scale_name" name="scale_name" 
                                       value="<?php echo htmlspecialchars($current_scale['scale_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($current_scale['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               value="1" <?php echo (($current_scale['is_active'] ?? 1) ? 'checked' : ''); ?>>
                                        <label class="form-check-label" for="is_active">Active Scale</label>
                                    </div>
                                </div>
                                <?php if ($action === 'edit' && !($current_scale['is_default'] ?? false)): ?>
                                <div class="col-md-6">
                                    <button type="submit" name="set_default" class="btn btn-sm btn-outline-success">
                                        Set as Default Scale
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="app-card app-card-stat shadow-sm">
                                <div class="app-card-body p-3">
                                    <h6 class="stats-type mb-3">Tips</h6>
                                    <ul class="small mb-0">
                                        <li>Grade points typically range from 0.0 to 5.0</li>
                                        <li>Include all possible grades (A-F or 1-5)</li>
                                        <li>Score ranges should be percentages (0-100%)</li>
                                        <li>One scale can be used by multiple programs</li>
                                        <li>Default scale is used for new programs</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Grade Entries Section -->
                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5>Grade Entries</h5>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addGradeEntry()">
                                <i class="fas fa-plus me-1"></i>Add Grade
                            </button>
                        </div>
                        
                        <div id="gradeEntries" class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th width="15%">Grade Symbol *</th>
                                        <th width="20%">Grade Name</th>
                                        <th width="15%">Grade Point *</th>
                                        <th width="20%">Score Range</th>
                                        <th width="25%">Remarks</th>
                                        <th width="5%">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="gradeEntriesBody">
                                    <?php if ($action === 'edit' && !empty($grade_entries)): ?>
                                        <?php foreach ($grade_entries as $index => $grade): ?>
                                        <tr>
                                            <td>
                                                <input type="text" class="form-control" 
                                                       name="grades[<?php echo $index; ?>][grade_symbol]" 
                                                       value="<?php echo htmlspecialchars($grade['grade_symbol']); ?>" 
                                                       required>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" 
                                                       name="grades[<?php echo $index; ?>][grade_name]" 
                                                       value="<?php echo htmlspecialchars($grade['grade_name'] ?? ''); ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control" step="0.1" min="0" max="10"
                                                       name="grades[<?php echo $index; ?>][grade_point]" 
                                                       value="<?php echo htmlspecialchars($grade['grade_point']); ?>" 
                                                       required>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" placeholder="From" 
                                                           name="grades[<?php echo $index; ?>][lower_bound]" 
                                                           value="<?php echo $grade['lower_bound']; ?>" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" placeholder="To" 
                                                           name="grades[<?php echo $index; ?>][upper_bound]" 
                                                           value="<?php echo $grade['upper_bound']; ?>" min="0" max="100">
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" 
                                                       name="grades[<?php echo $index; ?>][remarks]" 
                                                       value="<?php echo htmlspecialchars($grade['remarks'] ?? ''); ?>">
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Default grade entries for new scale -->
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[0][grade_symbol]" value="A" required></td>
                                            <td><input type="text" class="form-control" name="grades[0][grade_name]" value="Excellent"></td>
                                            <td><input type="number" class="form-control" name="grades[0][grade_point]" value="5.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[0][lower_bound]" value="70" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[0][upper_bound]" value="100" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[0][remarks]" value="Excellent Performance"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[1][grade_symbol]" value="B" required></td>
                                            <td><input type="text" class="form-control" name="grades[1][grade_name]" value="Very Good"></td>
                                            <td><input type="number" class="form-control" name="grades[1][grade_point]" value="4.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[1][lower_bound]" value="60" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[1][upper_bound]" value="69" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[1][remarks]" value="Very Good Performance"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[2][grade_symbol]" value="C" required></td>
                                            <td><input type="text" class="form-control" name="grades[2][grade_name]" value="Good"></td>
                                            <td><input type="number" class="form-control" name="grades[2][grade_point]" value="3.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[2][lower_bound]" value="50" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[2][upper_bound]" value="59" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[2][remarks]" value="Good Performance"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[3][grade_symbol]" value="D" required></td>
                                            <td><input type="text" class="form-control" name="grades[3][grade_name]" value="Pass"></td>
                                            <td><input type="number" class="form-control" name="grades[3][grade_point]" value="2.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[3][lower_bound]" value="45" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[3][upper_bound]" value="49" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[3][remarks]" value="Satisfactory Performance"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[4][grade_symbol]" value="E" required></td>
                                            <td><input type="text" class="form-control" name="grades[4][grade_name]" value="Poor"></td>
                                            <td><input type="number" class="form-control" name="grades[4][grade_point]" value="1.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[4][lower_bound]" value="40" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[4][upper_bound]" value="44" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[4][remarks]" value="Poor Performance"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <tr>
                                            <td><input type="text" class="form-control" name="grades[5][grade_symbol]" value="F" required></td>
                                            <td><input type="text" class="form-control" name="grades[5][grade_name]" value="Fail"></td>
                                            <td><input type="number" class="form-control" name="grades[5][grade_point]" value="0.0" step="0.1" required></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="grades[5][lower_bound]" value="0" min="0" max="100">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" name="grades[5][upper_bound]" value="39" min="0" max="100">
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control" name="grades[5][remarks]" value="Fail - Repeat Course"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="grade_scale.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?php echo $action === 'add' ? 'Create Grade Scale' : 'Update Grade Scale'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let gradeIndex = <?php echo $action === 'edit' && !empty($grade_entries) ? count($grade_entries) : 6; ?>;

function addGradeEntry() {
    const tbody = document.getElementById('gradeEntriesBody');
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td><input type="text" class="form-control" name="grades[${gradeIndex}][grade_symbol]" required></td>
        <td><input type="text" class="form-control" name="grades[${gradeIndex}][grade_name]"></td>
        <td><input type="number" class="form-control" name="grades[${gradeIndex}][grade_point]" step="0.1" min="0" max="10" required></td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" class="form-control" name="grades[${gradeIndex}][lower_bound]" placeholder="From" min="0" max="100">
                <span class="input-group-text">-</span>
                <input type="number" class="form-control" name="grades[${gradeIndex}][upper_bound]" placeholder="To" min="0" max="100">
            </div>
        </td>
        <td><input type="text" class="form-control" name="grades[${gradeIndex}][remarks]"></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeGradeEntry(this)"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(newRow);
    gradeIndex++;
}

function removeGradeEntry(button) {
    const row = button.closest('tr');
    if (document.querySelectorAll('#gradeEntriesBody tr').length > 1) {
        row.remove();
        // Re-index the remaining rows
        const rows = document.querySelectorAll('#gradeEntriesBody tr');
        rows.forEach((row, index) => {
            row.querySelectorAll('input').forEach(input => {
                const name = input.name.replace(/\[\d+\]/, `[${index}]`);
                input.name = name;
            });
        });
        gradeIndex = rows.length;
    } else {
        alert('At least one grade entry is required.');
    }
}

function confirmDelete(scaleId) {
    if (confirm('Are you sure you want to delete this grade scale? This action cannot be undone.')) {
        document.getElementById(`delete-form-${scaleId}`).submit();
    }
}

// Validate form before submission
document.getElementById('gradeScaleForm')?.addEventListener('submit', function(e) {
    const gradeInputs = document.querySelectorAll('input[name*="[grade_symbol]"]');
    const pointInputs = document.querySelectorAll('input[name*="[grade_point]"]');
    let hasError = false;
    
    // Check for duplicate grade symbols
    const symbols = [];
    gradeInputs.forEach(input => {
        const symbol = input.value.trim().toUpperCase();
        if (symbol && symbols.includes(symbol)) {
            alert(`Duplicate grade symbol: ${symbol}. Each grade symbol must be unique.`);
            hasError = true;
            input.focus();
        } else if (symbol) {
            symbols.push(symbol);
        }
    });
    
    // Check for valid grade points
    pointInputs.forEach(input => {
        const point = parseFloat(input.value);
        if (isNaN(point) || point < 0 || point > 10) {
            alert('Grade points must be numbers between 0.0 and 10.0');
            hasError = true;
            input.focus();
        }
    });
    
    if (hasError) {
        e.preventDefault();
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>