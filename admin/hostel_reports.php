<?php
// hostel_reports.php - Hostel Management Reports
ob_start();
require_once 'includes/header.php';
$page_title = "Hostel Reports";

if (!isset($pdo)) { $pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", ""); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

$hostels = $pdo->query("SELECT * FROM hostels ORDER BY hostel_name")->fetchAll();
$genders = ['Male', 'Female', 'Mixed'];

$hostel_id = isset($_GET['hostel_id']) ? (int)$_GET['hostel_id'] : 0;
$gender = isset($_GET['gender']) ? $_GET['gender'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'occupancy';

$filters = []; $params = [];
if ($hostel_id > 0) { $filters[] = "h.hostel_id = ?"; $params[] = $hostel_id; }
if (!empty($gender)) { $filters[] = "h.gender = ?"; $params[] = $gender; }
$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

if ($report_type == 'occupancy') {
    $sql = "SELECT h.hostel_name, h.gender, h.total_rooms, h.capacity_per_room, h.occupied_beds, h.available_beds, (h.total_rooms * h.capacity_per_room) as total_capacity, ROUND(h.occupied_beds * 100.0 / (h.total_rooms * h.capacity_per_room),1) as occupancy_rate FROM hostels h $where_clause ORDER BY occupancy_rate DESC";
    $reports = $pdo->prepare($sql); $reports->execute($params); $reports = $reports->fetchAll();
} else {
    $sql = "SELECT ha.allocation_id, s.matric_number, CONCAT(s.first_name,' ',s.last_name) as student_name, s.department_id, h.hostel_name, hr.room_number, ha.bed_number, ha.academic_year, ha.start_date, ha.end_date, ha.status FROM hostel_allocations ha JOIN students s ON ha.student_id = s.student_id JOIN hostels h ON ha.hostel_id = h.hostel_id JOIN hostel_rooms hr ON ha.room_id = hr.room_id $where_clause ORDER BY ha.allocation_date DESC LIMIT 200";
    $reports = $pdo->prepare($sql); $reports->execute($params); $reports = $reports->fetchAll();
}
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4"><div><h1><i class="fas fa-building me-2 text-primary"></i>Hostel Reports</h1><p class="text-muted">Occupancy, allocation and maintenance reports</p></div><div><button class="btn btn-success" onclick="exportReport()"><i class="fas fa-file-excel me-2"></i>Export</button><button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button></div></div>
    
    <ul class="nav nav-tabs mb-4"><li class="nav-item"><a class="nav-link <?php echo $report_type == 'occupancy' ? 'active' : ''; ?>" href="?report_type=occupancy"><i class="fas fa-chart-pie me-2"></i>Occupancy Report</a></li><li class="nav-item"><a class="nav-link <?php echo $report_type == 'allocations' ? 'active' : ''; ?>" href="?report_type=allocations"><i class="fas fa-list me-2"></i>Allocations Report</a></li></ul>
    
    <div class="filter-section bg-light p-3 rounded mb-4"><form method="GET" class="row g-3"><input type="hidden" name="report_type" value="<?php echo $report_type; ?>"><div class="col-md-3"><label>Hostel</label><select class="form-select" name="hostel_id" onchange="this.form.submit()"><option value="0">All Hostels</option><?php foreach($hostels as $h): ?><option value="<?php echo $h['hostel_id']; ?>" <?php echo $hostel_id == $h['hostel_id'] ? 'selected' : ''; ?>><?php echo $h['hostel_name']; ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label>Gender</label><select class="form-select" name="gender" onchange="this.form.submit()"><option value="">All</option><?php foreach($genders as $g): ?><option value="<?php echo $g; ?>" <?php echo $gender == $g ? 'selected' : ''; ?>><?php echo $g; ?></option><?php endforeach; ?></select></div><div class="col-md-3 d-flex align-items-end"><a href="hostel_reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-outline-secondary">Reset Filters</a></div></form></div>
    
    <?php if($report_type == 'occupancy'): ?><div class="row g-3 mb-4"><?php foreach($reports as $r): ?><div class="col-md-4"><div class="card h-100"><div class="card-header bg-primary text-white"><?php echo $r['hostel_name']; ?> <span class="badge bg-light text-dark float-end"><?php echo $r['gender']; ?></span></div><div class="card-body"><div class="text-center mb-3"><div class="display-4"><?php echo $r['occupancy_rate']; ?>%</div><small>Occupancy Rate</small></div><div class="row text-center"><div class="col-6"><h5><?php echo $r['occupied_beds']; ?></h5><small>Occupied</small></div><div class="col-6"><h5><?php echo $r['available_beds']; ?></h5><small>Available</small></div></div><div class="progress mt-2" style="height:10px"><div class="progress-bar bg-success" style="width:<?php echo $r['occupancy_rate']; ?>%"></div></div><div class="small text-muted mt-2">Total Capacity: <?php echo $r['total_capacity']; ?> beds | Rooms: <?php echo $r['total_rooms']; ?></div></div></div></div><?php endforeach; ?></div><?php else: ?><div class="card shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Student</th><th>Hostel</th><th>Room</th><th>Bed</th><th>Acad Year</th><th>Start Date</th><th>End Date</th><th>Status</th></tr></thead><tbody><?php foreach($reports as $r): ?><tr><td><strong><?php echo $r['matric_number']; ?></strong><br><small><?php echo $r['student_name']; ?></small></td><td><?php echo $r['hostel_name']; ?></td><td><?php echo $r['room_number']; ?></td><td><?php echo $r['bed_number']; ?></td><td><?php echo $r['academic_year']; ?></td><td><?php echo date('d/m/Y', strtotime($r['start_date'])); ?></td><td><?php echo date('d/m/Y', strtotime($r['end_date'])); ?></td><td><span class="badge bg-<?php echo $r['status'] == 'Active' ? 'success' : 'danger'; ?>"><?php echo $r['status']; ?></span></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
</div>
<script>function exportReport(){window.location.href='export_hostel_report.php?'+new URLSearchParams(window.location.search).toString();}</script>
<?php require_once 'includes/footer.php'; ?>