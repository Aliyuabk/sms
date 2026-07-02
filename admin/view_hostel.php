<?php
// view_hostel.php
ob_start();

require_once 'includes/header.php';

// Get hostel ID from URL
$hostel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($hostel_id <= 0) {
    $_SESSION['error_message'] = "Invalid hostel ID";
    header("Location: hostels.php");
    exit();
}

// Fetch hostel details
try {
    // Main hostel info
    $stmt = $pdo->prepare("
        SELECT 
            h.*,
            (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = h.hostel_id) as total_rooms,
            (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = h.hostel_id AND status = 'Available') as available_rooms,
            (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = h.hostel_id AND status = 'Occupied') as occupied_rooms,
            (SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = h.hostel_id AND status = 'Under Maintenance') as maintenance_rooms,
            (SELECT SUM(bed_count) FROM hostel_rooms WHERE hostel_id = h.hostel_id) as total_beds,
            (SELECT COUNT(*) FROM hostel_allocations ha 
             JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
             WHERE hr.hostel_id = h.hostel_id AND ha.status = 'Active') as occupied_beds,
            (SELECT COUNT(*) FROM hostel_allocations ha 
             JOIN hostel_rooms hr ON ha.room_id = hr.room_id 
             WHERE hr.hostel_id = h.hostel_id AND ha.status = 'Pending') as pending_allocations,
            (SELECT COUNT(*) FROM hostel_maintenance 
             WHERE hostel_id = h.hostel_id AND status IN ('Pending', 'In Progress')) as pending_maintenance,
            (SELECT COUNT(*) FROM hostel_maintenance 
             WHERE hostel_id = h.hostel_id AND status = 'Completed') as completed_maintenance
        FROM hostels h
        WHERE h.hostel_id = ?
    ");
    $stmt->execute([$hostel_id]);
    $hostel = $stmt->fetch();

    if (!$hostel) {
        $_SESSION['error_message'] = "Hostel not found";
        header("Location: hostels.php");
        exit();
    }

    // Set page title
    $page_title = htmlspecialchars($hostel['hostel_name']) . " - Hostel Details";

    // Fetch recent allocations
    $allocations_stmt = $pdo->prepare("
        SELECT 
            ha.*,
            s.matric_number,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.phone as student_phone,
            s.email as student_email,
            s.current_level,
            s.program_id,
            p.program_name,
            hr.room_number,
            hr.floor_number,
            hr.bed_count as room_capacity,
            DATEDIFF(ha.end_date, CURDATE()) as days_remaining
        FROM hostel_allocations ha
        JOIN students s ON ha.student_id = s.student_id
        JOIN hostel_rooms hr ON ha.room_id = hr.room_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE ha.hostel_id = ?
        ORDER BY ha.start_date DESC
        LIMIT 10
    ");
    $allocations_stmt->execute([$hostel_id]);
    $recent_allocations = $allocations_stmt->fetchAll();

    // Fetch rooms with occupancy
    $rooms_stmt = $pdo->prepare("
        SELECT 
            hr.*,
            (SELECT COUNT(*) FROM hostel_allocations ha 
             WHERE ha.room_id = hr.room_id AND ha.status = 'Active') as occupied_beds,
            (SELECT GROUP_CONCAT(CONCAT(s.first_name, ' ', s.last_name) SEPARATOR ', ') 
             FROM hostel_allocations ha 
             JOIN students s ON ha.student_id = s.student_id 
             WHERE ha.room_id = hr.room_id AND ha.status = 'Active') as occupants
        FROM hostel_rooms hr
        WHERE hr.hostel_id = ?
        ORDER BY hr.room_number
        LIMIT 20
    ");
    $rooms_stmt->execute([$hostel_id]);
    $rooms = $rooms_stmt->fetchAll();

    // Fetch maintenance requests
    $maintenance_stmt = $pdo->prepare("
        SELECT 
            hm.*,
            hr.room_number,
            CONCAT(a.full_name, ' (', a.username, ')') as assigned_to_name
        FROM hostel_maintenance hm
        LEFT JOIN hostel_rooms hr ON hm.room_id = hr.room_id
        LEFT JOIN admin_users a ON hm.assigned_to = a.admin_id
        WHERE hm.hostel_id = ?
        ORDER BY 
            CASE hm.priority 
                WHEN 'Emergency' THEN 1
                WHEN 'High' THEN 2
                WHEN 'Medium' THEN 3
                WHEN 'Low' THEN 4
            END,
            hm.created_date DESC
        LIMIT 10
    ");
    $maintenance_stmt->execute([$hostel_id]);
    $maintenance_requests = $maintenance_stmt->fetchAll();

    // Fetch floor statistics
    $floor_stats_stmt = $pdo->prepare("
        SELECT 
            hr.floor_number,
            COUNT(DISTINCT hr.room_id) as total_rooms,
            SUM(hr.bed_count) as total_beds,
            COUNT(DISTINCT CASE WHEN ha.status = 'Active' THEN ha.allocation_id END) as occupied_beds,
            COUNT(DISTINCT CASE WHEN hr.status = 'Under Maintenance' THEN hr.room_id END) as maintenance_rooms
        FROM hostel_rooms hr
        LEFT JOIN hostel_allocations ha ON hr.room_id = ha.room_id AND ha.status = 'Active'
        WHERE hr.hostel_id = ?
        GROUP BY hr.floor_number
        ORDER BY hr.floor_number
    ");
    $floor_stats_stmt->execute([$hostel_id]);
    $floor_stats = $floor_stats_stmt->fetchAll();

    // Get monthly revenue data
    $revenue_stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(ha.start_date, '%Y-%m') as month,
            COUNT(*) as allocations,
            SUM(h.monthly_rent) as revenue
        FROM hostel_allocations ha
        JOIN hostels h ON ha.hostel_id = h.hostel_id
        WHERE ha.hostel_id = ? AND ha.status = 'Active'
        GROUP BY DATE_FORMAT(ha.start_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $revenue_stmt->execute([$hostel_id]);
    $monthly_revenue = $revenue_stmt->fetchAll();

} catch (Exception $e) {
    error_log("Error fetching hostel details: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading hostel details";
    header("Location: hostels.php");
    exit();
}

// Calculate statistics
$total_beds = $hostel['total_beds'] ?? 0;
$occupied_beds = $hostel['occupied_beds'] ?? 0;
$available_beds = $total_beds - $occupied_beds;
$occupancy_rate = $total_beds > 0 ? round(($occupied_beds / $total_beds) * 100, 1) : 0;

// Status colors and icons
$status_config = [
    'Available' => ['bg-success', 'fa-check-circle', 'text-success'],
    'Full' => ['bg-warning', 'fa-exclamation-circle', 'text-warning'],
    'Under Maintenance' => ['bg-danger', 'fa-tools', 'text-danger'],
    'Closed' => ['bg-secondary', 'fa-lock', 'text-secondary']
];

$gender_config = [
    'Male' => ['primary', 'fa-mars'],
    'Female' => ['danger', 'fa-venus'],
    'Mixed' => ['success', 'fa-transgender']
];

// Display messages
if (isset($_SESSION['success_message'])): ?>
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

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="hostels.php">Hostels</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($hostel['hostel_name']); ?></li>
            </ol>
        </nav>
        <h1 class="app-page-title mb-0">
            <?php echo htmlspecialchars($hostel['hostel_name']); ?>
            <small class="text-muted fs-6">(<?php echo htmlspecialchars($hostel['hostel_code']); ?>)</small>
        </h1>
    </div>
    <div class="app-actions">
        <a href="edit_hostel.php?id=<?php echo $hostel_id; ?>" class="btn btn-warning me-2">
            <i class="fas fa-edit me-2"></i>Edit Hostel
        </a>
        <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-primary me-2">
            <i class="fas fa-door-closed me-2"></i>Manage Rooms
        </a>
        <div class="btn-group">
            <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-plus-circle me-2"></i>New Allocation
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="allocate_room.php?hostel_id=<?php echo $hostel_id; ?>">
                        <i class="fas fa-user-plus me-2"></i>Allocate Room to Student
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="bulk_allocate.php?hostel_id=<?php echo $hostel_id; ?>">
                        <i class="fas fa-users me-2"></i>Bulk Allocation
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="hostel_maintenance.php?hostel_id=<?php echo $hostel_id; ?>&action=new">
                        <i class="fas fa-tools me-2"></i>Report Maintenance
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- Quick Stats Row -->
<div class="row g-3 mb-4">
    <!-- Status Card -->
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Status</div>
                        <div class="stats-figure">
                            <?php 
                            $status_info = $status_config[$hostel['status']] ?? ['bg-secondary', 'fa-question', 'text-secondary'];
                            ?>
                            <span class="badge bg-<?php echo $status_info[0]; ?> fs-6">
                                <i class="fas <?php echo $status_info[1]; ?> me-1"></i>
                                <?php echo $hostel['status']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="app-icon-holder icon-holder-lg bg-opacity-10 bg-<?php echo $status_info[0]; ?>">
                        <i class="fas <?php echo $status_info[1]; ?> fa-2x text-<?php echo $status_info[0]; ?>"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <span class="text-<?php echo $status_info[0]; ?>">
                        <i class="fas fa-circle small me-1"></i>
                        <?php echo $hostel['status'] == 'Available' ? 'Accepting allocations' : 'Currently ' . strtolower($hostel['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Occupancy Card -->
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Occupancy</div>
                        <div class="stats-figure"><?php echo $occupancy_rate; ?>%</div>
                    </div>
                    <div class="app-icon-holder icon-holder-lg bg-opacity-10 bg-info">
                        <i class="fas fa-bed fa-2x text-info"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-info" role="progressbar" 
                             style="width: <?php echo $occupancy_rate; ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1 small">
                        <span><i class="fas fa-user-check me-1"></i><?php echo $occupied_beds; ?> occupied</span>
                        <span><i class="fas fa-door-open me-1"></i><?php echo $available_beds; ?> available</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rooms Card -->
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Rooms</div>
                        <div class="stats-figure"><?php echo $hostel['total_rooms']; ?></div>
                    </div>
                    <div class="app-icon-holder icon-holder-lg bg-opacity-10 bg-primary">
                        <i class="fas fa-door-closed fa-2x text-primary"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <span class="text-success me-2">
                        <i class="fas fa-check-circle"></i> <?php echo $hostel['available_rooms']; ?> Available
                    </span>
                    <span class="text-warning">
                        <i class="fas fa-tools"></i> <?php echo $hostel['maintenance_rooms']; ?> Maintenance
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Revenue Card -->
    <div class="col-6 col-md-3">
        <div class="app-card app-card-stat shadow-sm h-100">
            <div class="app-card-body p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stats-type">Monthly Revenue</div>
                        <div class="stats-figure">₦<?php echo number_format($occupied_beds * $hostel['monthly_rent'], 0); ?></div>
                    </div>
                    <div class="app-icon-holder icon-holder-lg bg-opacity-10 bg-success">
                        <i class="fas fa-naira-sign fa-2x text-success"></i>
                    </div>
                </div>
                <div class="stats-meta mt-2">
                    <span class="text-success">
                        <i class="fas fa-arrow-up me-1"></i>
                        ₦<?php echo number_format($hostel['monthly_rent'], 0); ?>/bed
                    </span>
                    <span class="text-muted ms-2">per month</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Row -->
<div class="row">
    <!-- Left Column -->
    <div class="col-lg-8">
        <!-- Floor Statistics -->
        <?php if (!empty($floor_stats)): ?>
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-layer-group me-2"></i>Floor-wise Statistics
                </h5>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="cell">Floor</th>
                                <th class="cell">Rooms</th>
                                <th class="cell">Total Beds</th>
                                <th class="cell">Occupied</th>
                                <th class="cell">Available</th>
                                <th class="cell">Occupancy Rate</th>
                                <th class="cell">Maintenance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($floor_stats as $floor): 
                                $floor_occupied = $floor['occupied_beds'] ?? 0;
                                $floor_available = $floor['total_beds'] - $floor_occupied;
                                $floor_rate = $floor['total_beds'] > 0 ? round(($floor_occupied / $floor['total_beds']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="cell">
                                    <span class="fw-bold">Floor <?php echo $floor['floor_number']; ?></span>
                                </td>
                                <td class="cell"><?php echo $floor['total_rooms']; ?> rooms</td>
                                <td class="cell"><?php echo $floor['total_beds']; ?> beds</td>
                                <td class="cell">
                                    <span class="text-primary fw-bold"><?php echo $floor_occupied; ?></span>
                                </td>
                                <td class="cell">
                                    <span class="text-success fw-bold"><?php echo $floor_available; ?></span>
                                </td>
                                <td class="cell">
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1" style="height: 5px;">
                                            <div class="progress-bar bg-info" style="width: <?php echo $floor_rate; ?>%"></div>
                                        </div>
                                        <span class="ms-2 small"><?php echo $floor_rate; ?>%</span>
                                    </div>
                                </td>
                                <td class="cell">
                                    <?php if ($floor['maintenance_rooms'] > 0): ?>
                                    <span class="badge bg-warning"><?php echo $floor['maintenance_rooms']; ?> rooms</span>
                                    <?php else: ?>
                                    <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Rooms List -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="app-card-title mb-0">
                            <i class="fas fa-door-closed me-2"></i>Rooms Overview
                        </h5>
                    </div>
                    <div class="col-auto">
                        <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-sm btn-primary">
                            View All Rooms <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="app-card-body p-3">
                <div class="row g-3">
                    <?php foreach ($rooms as $room): 
                        $room_occupancy_rate = $room['bed_count'] > 0 ? round(($room['occupied_beds'] / $room['bed_count']) * 100, 1) : 0;
                        $room_status_class = [
                            'Available' => 'success',
                            'Occupied' => 'primary',
                            'Under Maintenance' => 'warning',
                            'Reserved' => 'info'
                        ][$room['status']] ?? 'secondary';
                    ?>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-0">
                                            Room <?php echo htmlspecialchars($room['room_number']); ?>
                                            <small class="text-muted">Floor <?php echo $room['floor_number']; ?></small>
                                        </h6>
                                        <span class="badge bg-<?php echo $room_status_class; ?> mt-1">
                                            <?php echo $room['status']; ?>
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-primary">
                                            <?php echo $room['occupied_beds']; ?>/<?php echo $room['bed_count']; ?>
                                        </div>
                                        <small class="text-muted">beds</small>
                                    </div>
                                </div>
                                
                                <!-- Bed occupancy progress -->
                                <div class="progress mb-2" style="height: 5px;">
                                    <div class="progress-bar bg-<?php echo $room_status_class; ?>" 
                                         style="width: <?php echo $room_occupancy_rate; ?>%"></div>
                                </div>
                                
                                <?php if ($room['occupants']): ?>
                                <div class="small text-muted mb-2">
                                    <i class="fas fa-users me-1"></i>
                                    <?php echo htmlspecialchars(substr($room['occupants'], 0, 50)); ?>
                                    <?php if (strlen($room['occupants']) > 50): ?>...<?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="fas fa-tag me-1"></i><?php echo ucfirst($room['room_type']); ?>
                                    </small>
                                    <a href="view_room.php?id=<?php echo $room['room_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (count($rooms) >= 20): ?>
                    <div class="col-12 text-center mt-2">
                        <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-outline-primary">
                            View All <?php echo $hostel['total_rooms']; ?> Rooms
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($rooms)): ?>
                    <div class="col-12 text-center py-4">
                        <i class="fas fa-door-closed fa-3x text-muted mb-3"></i>
                        <h5>No rooms found</h5>
                        <p class="text-muted">This hostel has no rooms configured yet.</p>
                        <a href="hostel_rooms.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>Add Rooms
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Allocations -->
        <?php if (!empty($recent_allocations)): ?>
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="app-card-title mb-0">
                            <i class="fas fa-bed me-2"></i>Recent Allocations
                        </h5>
                    </div>
                    <div class="col-auto">
                        <a href="hostel_allocations.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-sm btn-outline-primary">
                            View All
                        </a>
                    </div>
                </div>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="cell">Student</th>
                                <th class="cell">Room</th>
                                <th class="cell">Period</th>
                                <th class="cell">Payment Status</th>
                                <th class="cell">Days Left</th>
                                <th class="cell"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_allocations as $alloc): ?>
                            <tr>
                                <td class="cell">
                                    <div class="fw-bold"><?php echo htmlspecialchars($alloc['student_name']); ?></div>
                                    <div class="small text-muted"><?php echo $alloc['matric_number']; ?></div>
                                </td>
                                <td class="cell">
                                    <span class="badge bg-secondary">Rm <?php echo $alloc['room_number']; ?></span>
                                    <div class="small">Bed #<?php echo $alloc['bed_number']; ?></div>
                                </td>
                                <td class="cell">
                                    <div><?php echo date('M d, Y', strtotime($alloc['start_date'])); ?></div>
                                    <div class="small text-muted">to <?php echo date('M d, Y', strtotime($alloc['end_date'])); ?></div>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $payment_class = [
                                        'Paid' => 'success',
                                        'Pending' => 'warning',
                                        'Partial' => 'info',
                                        'Overdue' => 'danger'
                                    ][$alloc['payment_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $payment_class; ?>">
                                        <?php echo $alloc['payment_status']; ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <?php if ($alloc['days_remaining'] > 0): ?>
                                    <span class="<?php echo $alloc['days_remaining'] < 30 ? 'text-warning' : 'text-success'; ?>">
                                        <?php echo $alloc['days_remaining']; ?> days
                                    </span>
                                    <?php else: ?>
                                    <span class="text-danger">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell text-end">
                                    <a href="view_allocation.php?id=<?php echo $alloc['allocation_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Maintenance Requests -->
        <?php if (!empty($maintenance_requests)): ?>
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="app-card-title mb-0">
                            <i class="fas fa-tools me-2"></i>Maintenance Requests
                        </h5>
                    </div>
                    <div class="col-auto">
                        <a href="hostel_maintenance.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-sm btn-outline-primary">
                            View All
                        </a>
                    </div>
                </div>
            </div>
            <div class="app-card-body p-0">
                <div class="table-responsive">
                    <table class="table app-table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="cell">Issue</th>
                                <th class="cell">Room</th>
                                <th class="cell">Priority</th>
                                <th class="cell">Status</th>
                                <th class="cell">Reported</th>
                                <th class="cell"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance_requests as $request): ?>
                            <tr>
                                <td class="cell">
                                    <div class="fw-bold"><?php echo htmlspecialchars($request['title']); ?></div>
                                    <div class="small text-muted"><?php echo $request['category']; ?></div>
                                </td>
                                <td class="cell">
                                    <?php if ($request['room_number']): ?>
                                    Room <?php echo $request['room_number']; ?>
                                    <?php else: ?>
                                    <span class="text-muted">Common Area</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $priority_class = [
                                        'Emergency' => 'danger',
                                        'High' => 'warning',
                                        'Medium' => 'info',
                                        'Low' => 'secondary'
                                    ][$request['priority']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $priority_class; ?>">
                                        <?php echo $request['priority']; ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <?php 
                                    $status_class = [
                                        'Pending' => 'warning',
                                        'In Progress' => 'info',
                                        'Completed' => 'success',
                                        'Cancelled' => 'secondary'
                                    ][$request['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>">
                                        <?php echo $request['status']; ?>
                                    </span>
                                </td>
                                <td class="cell">
                                    <div><?php echo date('M d, Y', strtotime($request['created_date'])); ?></div>
                                    <?php if ($request['assigned_to_name']): ?>
                                    <div class="small text-muted">Assigned to: <?php echo $request['assigned_to_name']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="cell text-end">
                                    <a href="view_maintenance.php?id=<?php echo $request['maintenance_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4">
        <!-- Hostel Information Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Hostel Information
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="text-center mb-4">
                    <div class="app-icon-holder icon-holder-lg bg-opacity-10 bg-<?php echo $gender_config[$hostel['gender']][0] ?? 'secondary'; ?> mx-auto" 
                         style="width: 80px; height: 80px; border-radius: 50%;">
                        <i class="fas <?php echo $gender_config[$hostel['gender']][1] ?? 'fa-building'; ?> fa-3x mt-3"></i>
                    </div>
                    <h4 class="mt-3 mb-0"><?php echo htmlspecialchars($hostel['hostel_name']); ?></h4>
                    <div class="text-muted">Code: <?php echo htmlspecialchars($hostel['hostel_code']); ?></div>
                </div>

                <table class="table table-borderless">
                    <tr>
                        <td width="40%"><i class="fas fa-venus-mars me-2 text-muted"></i>Gender:</td>
                        <td>
                            <span class="badge bg-<?php echo $gender_config[$hostel['gender']][0] ?? 'secondary'; ?>">
                                <i class="fas <?php echo $gender_config[$hostel['gender']][1] ?? 'fa-building'; ?> me-1"></i>
                                <?php echo $hostel['gender']; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-door-closed me-2 text-muted"></i>Total Rooms:</td>
                        <td class="fw-bold"><?php echo $hostel['total_rooms']; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-bed me-2 text-muted"></i>Total Beds:</td>
                        <td class="fw-bold"><?php echo $total_beds; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-user-check me-2 text-muted"></i>Occupied Beds:</td>
                        <td class="fw-bold text-primary"><?php echo $occupied_beds; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-door-open me-2 text-muted"></i>Available Beds:</td>
                        <td class="fw-bold text-success"><?php echo $available_beds; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-tools me-2 text-muted"></i>Maintenance Rooms:</td>
                        <td class="fw-bold text-warning"><?php echo $hostel['maintenance_rooms']; ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-naira-sign me-2 text-muted"></i>Monthly Rent:</td>
                        <td class="fw-bold">₦<?php echo number_format($hostel['monthly_rent'], 2); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-clock me-2 text-muted"></i>Created:</td>
                        <td><?php echo date('M d, Y', strtotime($hostel['created_date'])); ?></td>
                    </tr>
                </table>

                <?php if ($hostel['warden_name']): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <h6 class="mb-3"><i class="fas fa-user-tie me-2"></i>Warden Information</h6>
                    <div class="mb-2"><strong><?php echo htmlspecialchars($hostel['warden_name']); ?></strong></div>
                    <?php if ($hostel['warden_phone']): ?>
                    <div class="small mb-1">
                        <i class="fas fa-phone me-2 text-muted"></i><?php echo htmlspecialchars($hostel['warden_phone']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($hostel['warden_email']): ?>
                    <div class="small">
                        <i class="fas fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($hostel['warden_email']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="d-grid gap-2">
                    <a href="allocate_room.php?hostel_id=<?php echo $hostel_id; ?>" class="btn btn-primary">
                        <i class="fas fa-user-plus me-2"></i>Allocate Room
                    </a>
                    <a href="hostel_maintenance.php?hostel_id=<?php echo $hostel_id; ?>&action=new" class="btn btn-warning">
                        <i class="fas fa-tools me-2"></i>Report Maintenance
                    </a>
                    <a href="export_hostel_data.php?id=<?php echo $hostel_id; ?>" class="btn btn-success">
                        <i class="fas fa-download me-2"></i>Export Data
                    </a>
                    <a href="print_hostel_report.php?id=<?php echo $hostel_id; ?>" class="btn btn-info" target="_blank">
                        <i class="fas fa-print me-2"></i>Print Report
                    </a>
                </div>
            </div>
        </div>

        <!-- Monthly Revenue Chart -->
        <?php if (!empty($monthly_revenue)): ?>
        <div class="app-card shadow-sm mb-4">
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-chart-line me-2"></i>Monthly Revenue
                </h5>
            </div>
            <div class="app-card-body p-3">
                <canvas id="revenueChart" height="200"></canvas>
                <div class="mt-3">
                    <?php 
                    $total_revenue = array_sum(array_column($monthly_revenue, 'revenue'));
                    $avg_revenue = count($monthly_revenue) > 0 ? $total_revenue / count($monthly_revenue) : 0;
                    ?>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Total (6 months):</span>
                        <span class="fw-bold">₦<?php echo number_format($total_revenue, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Monthly Average:</span>
                        <span class="fw-bold">₦<?php echo number_format($avg_revenue, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_revenue, 'month')); ?>,
                    datasets: [{
                        label: 'Revenue (₦)',
                        data: <?php echo json_encode(array_column($monthly_revenue, 'revenue')); ?>,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₦' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });
        </script>
        <?php endif; ?>

        <!-- Amenities & Rules -->
        <?php if ($hostel['amenities'] || $hostel['rules']): ?>
        <div class="app-card shadow-sm mb-4">
            <?php if ($hostel['amenities']): ?>
            <div class="app-card-header p-3">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-star me-2"></i>Amenities
                </h5>
            </div>
            <div class="app-card-body p-3">
                <?php 
                $amenities = explode(',', $hostel['amenities']);
                foreach ($amenities as $amenity): 
                    $amenity = trim($amenity);
                    if (!empty($amenity)):
                ?>
                <span class="badge bg-light text-dark me-2 mb-2 p-2">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    <?php echo htmlspecialchars($amenity); ?>
                </span>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <?php endif; ?>

            <?php if ($hostel['rules']): ?>
            <div class="app-card-header p-3 border-top">
                <h5 class="app-card-title mb-0">
                    <i class="fas fa-gavel me-2"></i>Rules & Regulations
                </h5>
            </div>
            <div class="app-card-body p-3">
                <div class="bg-light p-3 rounded">
                    <?php echo nl2br(htmlspecialchars($hostel['rules'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Allocation Modal -->
<div class="modal fade" id="allocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Room Allocation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="allocationForm" action="process_allocation.php" method="POST">
                    <input type="hidden" name="hostel_id" value="<?php echo $hostel_id; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Student</label>
                        <select class="form-select" name="student_id" required>
                            <option value="">Search for student...</option>
                            <!-- Will be populated via AJAX -->
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Room</label>
                        <select class="form-select" name="room_id" required>
                            <option value="">Choose room...</option>
                            <?php foreach ($rooms as $room): ?>
                                <?php if ($room['occupied_beds'] < $room['bed_count']): ?>
                                <option value="<?php echo $room['room_id']; ?>">
                                    Room <?php echo $room['room_number']; ?> (Floor <?php echo $room['floor_number']; ?>) - 
                                    <?php echo $room['occupied_beds']; ?>/<?php echo $room['bed_count']; ?> occupied
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bed Number</label>
                        <select class="form-select" name="bed_number" id="bedNumberSelect" required>
                            <option value="">Select bed number</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Status</label>
                        <select class="form-select" name="payment_status" required>
                            <option value="Pending">Pending</option>
                            <option value="Paid">Paid</option>
                            <option value="Partial">Partial</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="allocationForm" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Create Allocation
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Room selection handler for bed numbers
document.querySelector('select[name="room_id"]').addEventListener('change', function() {
    const roomId = this.value;
    const bedSelect = document.getElementById('bedNumberSelect');
    
    if (roomId) {
        // Fetch available beds for this room
        fetch(`get_available_beds.php?room_id=${roomId}`)
            .then(response => response.json())
            .then(data => {
                bedSelect.innerHTML = '<option value="">Select bed number</option>';
                data.beds.forEach(bed => {
                    bedSelect.innerHTML += `<option value="${bed}">Bed ${bed}</option>`;
                });
            });
    } else {
        bedSelect.innerHTML = '<option value="">Select bed number</option>';
    }
});

// Bulk actions
function submitBulkAction(action) {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one item.');
        return false;
    }
    
    let confirmMessage = '';
    switch (action) {
        case 'checkout':
            confirmMessage = `Checkout ${selectedIds.length} selected students?`;
            break;
        case 'send_reminder':
            confirmMessage = `Send payment reminders to ${selectedIds.length} students?`;
            break;
        case 'extend':
            confirmMessage = `Extend accommodation for ${selectedIds.length} students?`;
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
}

// Helper function to get selected IDs
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.select-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

// Print hostel report
function printReport() {
    window.open('print_hostel_report.php?id=<?php echo $hostel_id; ?>', '_blank');
}

// Export data
function exportData(format) {
    window.location.href = `export_hostel_data.php?id=<?php echo $hostel_id; ?>&format=${format}`;
}
</script>

<style>
.app-card-stat {
    transition: transform 0.2s;
}
.app-card-stat:hover {
    transform: translateY(-5px);
}
.card {
    transition: all 0.2s;
}
.card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
}
.badge {
    padding: 0.5em 0.8em;
}
.table td {
    vertical-align: middle;
}
.progress {
    border-radius: 10px;
}
.floor-progress {
    width: 100px;
}
</style>

<?php
require_once 'includes/footer.php';
?>