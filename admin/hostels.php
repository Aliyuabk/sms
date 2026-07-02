<?php
// hostels.php - Fixed for null values
ob_start();
require_once 'includes/header.php';

$page_title = "Hostel Management";

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to safely format numbers
function safeNumberFormat($num, $decimals = 0) {
    if ($num === null || $num === '') {
        return '0';
    }
    return number_format((float)$num, $decimals);
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build WHERE clause
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(hostel_name LIKE ? OR hostel_code LIKE ? OR warden_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($gender_filter)) {
    $where[] = "gender = ?";
    $params[] = $gender_filter;
}
if (!empty($status_filter)) {
    $where[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$count_sql = "SELECT COUNT(*) FROM hostels $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total = $total !== false ? (int)$total : 0;
$total_pages = $total > 0 ? ceil($total / $limit) : 1;

// Get hostels
$sql = "SELECT * FROM hostels $where_clause ORDER BY hostel_name LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hostels = $stmt->fetchAll();

// Get statistics - with COALESCE to handle NULL values
$stats = [
    'total' => (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM hostels")->fetchColumn()),
    'male' => (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM hostels WHERE gender = 'Male'")->fetchColumn()),
    'female' => (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM hostels WHERE gender = 'Female'")->fetchColumn()),
    'mixed' => (int)($pdo->query("SELECT COALESCE(COUNT(*), 0) FROM hostels WHERE gender = 'Mixed'")->fetchColumn()),
    'total_rooms' => (int)($pdo->query("SELECT COALESCE(SUM(total_rooms), 0) FROM hostels")->fetchColumn()),
    'total_capacity' => (int)($pdo->query("SELECT COALESCE(SUM(total_rooms * capacity_per_room), 0) FROM hostels")->fetchColumn()),
    'occupied' => (int)($pdo->query("SELECT COALESCE(SUM(occupied_beds), 0) FROM hostels")->fetchColumn()),
    'available' => (int)($pdo->query("SELECT COALESCE(SUM(available_beds), 0) FROM hostels")->fetchColumn()),
];

// Handle Add Hostel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Add Hostel
    if ($_POST['action'] === 'add') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error'] = "Invalid security token";
        } else {
            $hostel_name = trim($_POST['hostel_name']);
            $hostel_code = strtoupper(trim($_POST['hostel_code']));
            $gender = $_POST['gender'];
            $total_rooms = (int)$_POST['total_rooms'];
            $capacity_per_room = (int)$_POST['capacity_per_room'];
            $warden_name = trim($_POST['warden_name'] ?? '');
            $warden_phone = trim($_POST['warden_phone'] ?? '');
            $warden_email = trim($_POST['warden_email'] ?? '');
            $monthly_rent = (float)$_POST['monthly_rent'];
            $amenities = trim($_POST['amenities'] ?? '');
            $rules = trim($_POST['rules'] ?? '');
            $status = $_POST['status'];
            
            $total_beds = $total_rooms * $capacity_per_room;
            
            // Validate
            $errors = [];
            if (empty($hostel_name)) $errors[] = "Hostel name is required";
            if (empty($hostel_code)) $errors[] = "Hostel code is required";
            if ($total_rooms < 1) $errors[] = "At least 1 room required";
            if ($capacity_per_room < 1) $errors[] = "Capacity per room must be at least 1";
            
            if (empty($errors)) {
                try {
                    // Check duplicate code
                    $check = $pdo->prepare("SELECT hostel_id FROM hostels WHERE hostel_code = ?");
                    $check->execute([$hostel_code]);
                    if ($check->fetch()) {
                        throw new Exception("Hostel code already exists");
                    }
                    
                    // Insert hostel
                    $insert = $pdo->prepare("
                        INSERT INTO hostels (
                            hostel_name, hostel_code, gender, total_rooms, capacity_per_room,
                            occupied_beds, available_beds, warden_name, warden_phone,
                            warden_email, monthly_rent, amenities, rules, status, created_date
                        ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insert->execute([
                        $hostel_name, $hostel_code, $gender, $total_rooms, $capacity_per_room,
                        $total_beds, $warden_name, $warden_phone, $warden_email,
                        $monthly_rent, $amenities, $rules, $status
                    ]);
                    
                    $_SESSION['success'] = "Hostel '$hostel_name' added successfully! Total capacity: $total_beds beds.";
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = $e->getMessage();
                }
            } else {
                $_SESSION['error'] = implode("<br>", $errors);
            }
        }
        header("Location: hostels.php");
        exit();
    }
    
    // Edit Hostel
    if ($_POST['action'] === 'edit') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error'] = "Invalid security token";
        } else {
            $hostel_id = (int)$_POST['hostel_id'];
            $hostel_name = trim($_POST['hostel_name']);
            $hostel_code = strtoupper(trim($_POST['hostel_code']));
            $gender = $_POST['gender'];
            $warden_name = trim($_POST['warden_name'] ?? '');
            $warden_phone = trim($_POST['warden_phone'] ?? '');
            $warden_email = trim($_POST['warden_email'] ?? '');
            $monthly_rent = (float)$_POST['monthly_rent'];
            $amenities = trim($_POST['amenities'] ?? '');
            $rules = trim($_POST['rules'] ?? '');
            $status = $_POST['status'];
            
            try {
                // Check duplicate code (excluding current)
                $check = $pdo->prepare("SELECT hostel_id FROM hostels WHERE hostel_code = ? AND hostel_id != ?");
                $check->execute([$hostel_code, $hostel_id]);
                if ($check->fetch()) {
                    throw new Exception("Hostel code already exists");
                }
                
                $update = $pdo->prepare("
                    UPDATE hostels SET 
                        hostel_name = ?, hostel_code = ?, gender = ?,
                        warden_name = ?, warden_phone = ?, warden_email = ?,
                        monthly_rent = ?, amenities = ?, rules = ?, status = ?
                    WHERE hostel_id = ?
                ");
                $update->execute([
                    $hostel_name, $hostel_code, $gender,
                    $warden_name, $warden_phone, $warden_email,
                    $monthly_rent, $amenities, $rules, $status, $hostel_id
                ]);
                
                $_SESSION['success'] = "Hostel updated successfully!";
                
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        header("Location: hostels.php");
        exit();
    }
    
    // Delete Hostel
    if ($_POST['action'] === 'delete') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error'] = "Invalid security token";
        } else {
            $hostel_id = (int)$_POST['hostel_id'];
            
            try {
                // Check if has allocations
                $check = $pdo->prepare("
                    SELECT COUNT(*) FROM hostel_allocations ha 
                    JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
                    WHERE hr.hostel_id = ?
                ");
                $check->execute([$hostel_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete hostel with existing allocations");
                }
                
                // Delete rooms first
                $pdo->prepare("DELETE FROM hostel_rooms WHERE hostel_id = ?")->execute([$hostel_id]);
                // Delete hostel
                $pdo->prepare("DELETE FROM hostels WHERE hostel_id = ?")->execute([$hostel_id]);
                
                $_SESSION['success'] = "Hostel deleted successfully!";
                
            } catch (Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        header("Location: hostels.php");
        exit();
    }
    
    // Bulk Action
    if ($_POST['action'] === 'bulk' && isset($_POST['selected'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error'] = "Invalid security token";
        } else {
            $selected = $_POST['selected'];
            $bulk_action = $_POST['bulk_action'];
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            
            switch ($bulk_action) {
                case 'activate':
                    $pdo->prepare("UPDATE hostels SET status = 'Available' WHERE hostel_id IN ($placeholders)")->execute($selected);
                    $_SESSION['success'] = count($selected) . " hostel(s) activated";
                    break;
                case 'deactivate':
                    $pdo->prepare("UPDATE hostels SET status = 'Closed' WHERE hostel_id IN ($placeholders)")->execute($selected);
                    $_SESSION['success'] = count($selected) . " hostel(s) deactivated";
                    break;
                case 'maintenance':
                    $pdo->prepare("UPDATE hostels SET status = 'Under Maintenance' WHERE hostel_id IN ($placeholders)")->execute($selected);
                    $_SESSION['success'] = count($selected) . " hostel(s) set to maintenance";
                    break;
            }
        }
        header("Location: hostels.php");
        exit();
    }
}

// Get single hostel for editing (AJAX)
if (isset($_GET['get_hostel'])) {
    $stmt = $pdo->prepare("SELECT * FROM hostels WHERE hostel_id = ?");
    $stmt->execute([$_GET['get_hostel']]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetch());
    exit();
}

// Display messages
if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin Portal</title>
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .hostel-card {
            transition: all 0.3s;
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .hostel-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .gender-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .gender-male { background: #d4edda; color: #155724; }
        .gender-female { background: #f8d7da; color: #721c24; }
        .gender-mixed { background: #d1ecf1; color: #0c5460; }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-available { background: #d4edda; color: #155724; }
        .status-full { background: #f8d7da; color: #721c24; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-closed { background: #e2e3e5; color: #383d41; }
        .progress-bar-custom {
            height: 6px;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .room-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }
        .room-tag {
            display: inline-block;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 4px 8px;
            margin: 3px;
            font-size: 11px;
        }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="fas fa-hotel text-primary me-2"></i>Hostel Management
        </h1>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus me-2"></i>Add Hostel
            </button> 
            <a href="hostel_allocations.php" class="btn btn-outline-success ms-2">
                <i class="fas fa-bed me-2"></i>Allocations
            </a>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Total Hostels</div>
                        <div class="h2 mb-0"><?php echo $stats['total']; ?></div>
                        <small><i class="fas fa-mars text-primary"></i> <?php echo $stats['male']; ?> Male | <i class="fas fa-venus text-danger"></i> <?php echo $stats['female']; ?> Female</small>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Total Capacity</div>
                        <div class="h2 mb-0"><?php echo safeNumberFormat($stats['total_capacity']); ?></div>
                        <small><?php echo safeNumberFormat($stats['total_rooms']); ?> rooms total</small>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-bed"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Occupied Beds</div>
                        <div class="h2 mb-0"><?php echo safeNumberFormat($stats['occupied']); ?></div>
                        <small><?php echo $stats['total_capacity'] > 0 ? round(($stats['occupied'] / $stats['total_capacity']) * 100) : 0; ?>% occupancy</small>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 4px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $stats['total_capacity'] > 0 ? ($stats['occupied'] / $stats['total_capacity']) * 100 : 0; ?>%"></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Available Beds</div>
                        <div class="h2 mb-0 text-success"><?php echo safeNumberFormat($stats['available']); ?></div>
                        <small>Vacancy rate: <?php echo $stats['total_capacity'] > 0 ? round(($stats['available'] / $stats['total_capacity']) * 100) : 0; ?>%</small>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search" placeholder="Search by name, code, warden..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="gender">
                        <option value="">All Genders</option>
                        <option value="Male" <?php echo $gender_filter == 'Male' ? 'selected' : ''; ?>>Male Only</option>
                        <option value="Female" <?php echo $gender_filter == 'Female' ? 'selected' : ''; ?>>Female Only</option>
                        <option value="Mixed" <?php echo $gender_filter == 'Mixed' ? 'selected' : ''; ?>>Mixed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="Available" <?php echo $status_filter == 'Available' ? 'selected' : ''; ?>>Available</option>
                        <option value="Full" <?php echo $status_filter == 'Full' ? 'selected' : ''; ?>>Full</option>
                        <option value="Under Maintenance" <?php echo $status_filter == 'Under Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="Closed" <?php echo $status_filter == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hostels Grid -->
    <div class="row g-4">
        <?php if (empty($hostels)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-hotel fa-3x mb-3 d-block text-muted"></i>
                    <h5>No hostels found</h5>
                    <p>Click "Add Hostel" to create your first hostel.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($hostels as $hostel): 
                $total_beds = ($hostel['total_rooms'] ?? 0) * ($hostel['capacity_per_room'] ?? 0);
                $occupancy = $total_beds > 0 ? (($hostel['occupied_beds'] ?? 0) / $total_beds) * 100 : 0;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card hostel-card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($hostel['hostel_name'] ?? 'N/A'); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($hostel['hostel_code'] ?? 'N/A'); ?></small>
                        </div>
                        <span class="gender-badge gender-<?php echo strtolower($hostel['gender'] ?? 'mixed'); ?>">
                            <i class="fas <?php echo ($hostel['gender'] ?? 'Mixed') == 'Male' ? 'fa-mars' : (($hostel['gender'] ?? 'Mixed') == 'Female' ? 'fa-venus' : 'fa-venus-mars'); ?> me-1"></i>
                            <?php echo $hostel['gender'] ?? 'Mixed'; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($hostel['warden_name'])): ?>
                        <div class="mb-3 small">
                            <i class="fas fa-user-tie text-muted me-1"></i>
                            <strong>Warden:</strong> <?php echo htmlspecialchars($hostel['warden_name']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row text-center mb-3">
                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0"><?php echo (int)($hostel['total_rooms'] ?? 0); ?></div>
                                    <small>Rooms</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0"><?php echo $total_beds; ?></div>
                                    <small>Total Beds</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2">
                                    <div class="h5 mb-0"><?php echo (int)($hostel['occupied_beds'] ?? 0); ?></div>
                                    <small>Occupied</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Occupancy</span>
                                <span><?php echo round($occupancy); ?>%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar <?php echo $occupancy > 90 ? 'bg-danger' : ($occupancy > 70 ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $occupancy; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <i class="fas fa-money-bill-wave text-success me-1"></i>
                            <strong>₦<?php echo number_format((float)($hostel['monthly_rent'] ?? 0), 2); ?></strong> / month
                        </div>
                        
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $hostel['status'] ?? 'closed')); ?>">
                            <i class="fas <?php echo ($hostel['status'] ?? 'Closed') == 'Available' ? 'fa-check-circle' : (($hostel['status'] ?? 'Closed') == 'Full' ? 'fa-ban' : 'fa-tools'); ?> me-1"></i>
                            <?php echo $hostel['status'] ?? 'Closed'; ?>
                        </span>
                    </div>
                    <div class="card-footer bg-white">
                        <div class="btn-group w-100">
                            <button class="btn btn-sm btn-outline-primary" onclick="viewHostel(<?php echo $hostel['hostel_id']; ?>)">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="editHostel(<?php echo $hostel['hostel_id']; ?>)">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                            <a href="hostel_rooms.php?hostel_id=<?php echo $hostel['hostel_id']; ?>" class="btn btn-sm btn-outline-info">
                                <i class="fas fa-door-open me-1"></i>Rooms
                            </a>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteHostel(<?php echo $hostel['hostel_id']; ?>, '<?php echo addslashes($hostel['hostel_name'] ?? ''); ?>')">
                                <i class="fas fa-trash me-1"></i>Del
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&gender=<?php echo urlencode($gender_filter); ?>&status=<?php echo urlencode($status_filter); ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&gender=<?php echo urlencode($gender_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&gender=<?php echo urlencode($gender_filter); ?>&status=<?php echo urlencode($status_filter); ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Hostel</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hostel Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="hostel_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hostel Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="hostel_code" required placeholder="e.g., MBH001">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select class="form-select" name="gender" required>
                                <option value="Male">Male Only</option>
                                <option value="Female">Female Only</option>
                                <option value="Mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Total Rooms <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="total_rooms" id="total_rooms" min="1" value="10" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Capacity per Room <span class="text-danger">*</span></label>
                            <select class="form-select" name="capacity_per_room" id="capacity_per_room">
                                <option value="2">2 Beds</option>
                                <option value="4" selected>4 Beds</option>
                                <option value="6">6 Beds</option>
                                <option value="8">8 Beds</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Monthly Rent (₦)</label>
                            <input type="number" class="form-control" name="monthly_rent" value="0" step="1000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Available">Available</option>
                                <option value="Full">Full</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Warden Name</label>
                            <input type="text" class="form-control" name="warden_name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warden Phone</label>
                            <input type="text" class="form-control" name="warden_phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warden Email</label>
                            <input type="email" class="form-control" name="warden_email">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Amenities</label>
                            <textarea class="form-control" name="amenities" rows="2" placeholder="24/7 electricity, WiFi, Water supply, Security..."></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Rules</label>
                            <textarea class="form-control" name="rules" rows="2" placeholder="No visitors after 10PM, Quiet hours..."></textarea>
                        </div>
                    </div>
                    <div class="alert alert-info" id="capacitySummary"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Hostel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="hostel_id" id="edit_id">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Hostel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hostel Name</label>
                            <input type="text" class="form-control" name="hostel_name" id="edit_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hostel Code</label>
                            <input type="text" class="form-control" name="hostel_code" id="edit_code" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender" id="edit_gender">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Monthly Rent (₦)</label>
                            <input type="number" class="form-control" name="monthly_rent" id="edit_rent">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="Available">Available</option>
                                <option value="Full">Full</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Warden Name</label>
                            <input type="text" class="form-control" name="warden_name" id="edit_warden">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warden Phone</label>
                            <input type="text" class="form-control" name="warden_phone" id="edit_phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warden Email</label>
                            <input type="email" class="form-control" name="warden_email" id="edit_email">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Amenities</label>
                            <textarea class="form-control" name="amenities" id="edit_amenities" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Rules</label>
                            <textarea class="form-control" name="rules" id="edit_rules" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Hostel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-hotel me-2"></i>Hostel Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="hostel_id" id="delete_id">
</form>

<script>
// Update capacity summary
function updateCapacity() {
    const rooms = document.getElementById('total_rooms')?.value || 0;
    const capacity = document.getElementById('capacity_per_room')?.value || 0;
    const total = rooms * capacity;
    const summary = document.getElementById('capacitySummary');
    if (summary) {
        summary.innerHTML = `<i class="fas fa-info-circle me-2"></i>Total capacity: ${rooms} rooms × ${capacity} beds = <strong>${total} beds</strong>`;
    }
}

document.getElementById('total_rooms')?.addEventListener('input', updateCapacity);
document.getElementById('capacity_per_room')?.addEventListener('change', updateCapacity);
updateCapacity();

// View hostel
function viewHostel(id) {
    fetch(`?get_hostel=${id}`)
        .then(res => res.json())
        .then(data => {
            const total_beds = (data.total_rooms || 0) * (data.capacity_per_room || 0);
            const occupancy = total_beds > 0 ? ((data.occupied_beds || 0) / total_beds * 100).toFixed(1) : 0;
            document.getElementById('viewContent').innerHTML = `
                <div class="row">
                    <div class="col-md-8">
                        <h4>${data.hostel_name || 'N/A'}</h4>
                        <p class="text-muted">Code: ${data.hostel_code || 'N/A'}</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge ${data.status == 'Available' ? 'bg-success' : (data.status == 'Full' ? 'bg-danger' : 'bg-warning')} p-2">${data.status || 'Unknown'}</span>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-venus-mars me-2"></i>Gender:</strong> ${data.gender || 'N/A'}</p>
                        <p><strong><i class="fas fa-user-tie me-2"></i>Warden:</strong> ${data.warden_name || 'Not assigned'}</p>
                        <p><strong><i class="fas fa-phone me-2"></i>Phone:</strong> ${data.warden_phone || 'N/A'}</p>
                        <p><strong><i class="fas fa-envelope me-2"></i>Email:</strong> ${data.warden_email || 'N/A'}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-door-open me-2"></i>Total Rooms:</strong> ${data.total_rooms || 0}</p>
                        <p><strong><i class="fas fa-bed me-2"></i>Capacity per Room:</strong> ${data.capacity_per_room || 0}</p>
                        <p><strong><i class="fas fa-users me-2"></i>Total Capacity:</strong> ${total_beds}</p>
                        <p><strong><i class="fas fa-user-check me-2"></i>Occupied Beds:</strong> ${data.occupied_beds || 0} (${occupancy}%)</p>
                        <p><strong><i class="fas fa-money-bill-wave me-2"></i>Monthly Rent:</strong> ₦${Number(data.monthly_rent || 0).toLocaleString()}</p>
                    </div>
                </div>
                <div class="mt-3">
                    <p><strong><i class="fas fa-cogs me-2"></i>Amenities:</strong></p>
                    <p class="text-muted">${data.amenities || 'No amenities listed'}</p>
                </div>
                <div class="mt-3">
                    <p><strong><i class="fas fa-gavel me-2"></i>Rules:</strong></p>
                    <p class="text-muted">${data.rules || 'No rules listed'}</p>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        });
}

// Edit hostel
function editHostel(id) {
    fetch(`?get_hostel=${id}`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('edit_id').value = data.hostel_id;
            document.getElementById('edit_name').value = data.hostel_name || '';
            document.getElementById('edit_code').value = data.hostel_code || '';
            document.getElementById('edit_gender').value = data.gender || 'Mixed';
            document.getElementById('edit_rent').value = data.monthly_rent || 0;
            document.getElementById('edit_status').value = data.status || 'Available';
            document.getElementById('edit_warden').value = data.warden_name || '';
            document.getElementById('edit_phone').value = data.warden_phone || '';
            document.getElementById('edit_email').value = data.warden_email || '';
            document.getElementById('edit_amenities').value = data.amenities || '';
            document.getElementById('edit_rules').value = data.rules || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        });
}

// Delete hostel
function deleteHostel(id, name) {
    if (confirm(`Delete hostel "${name || 'this hostel'}"? This action cannot be undone.`)) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>