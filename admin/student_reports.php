<?php
// student_reports.php - Student Demographics and Statistics
ob_start();
require_once 'includes/header.php';
$page_title = "Student Reports";

if (!isset($pdo)) { $pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", ""); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();
$genders = ['Male', 'Female'];
$statuses = ['Active', 'Inactive', 'Suspended', 'Graduated'];
$levels = [100, 200, 300, 400, 500, 600];

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$gender = isset($_GET['gender']) ? $_GET['gender'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'demographics';

$filters = []; $params = [];
if ($department_id > 0) { $filters[] = "s.department_id = ?"; $params[] = $department_id; }
if ($program_id > 0) { $filters[] = "s.program_id = ?"; $params[] = $program_id; }
if (!empty($gender)) { $filters[] = "s.gender = ?"; $params[] = $gender; }
if (!empty($status)) { $filters[] = "s.status = ?"; $params[] = $status; }
if ($level > 0) { $filters[] = "s.current_level = ?"; $params[] = $level; }
$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

if ($report_type == 'demographics') {
    $sql = "SELECT s.gender, s.current_level, s.status, COUNT(*) as count FROM students s $where_clause GROUP BY s.gender, s.current_level, s.status ORDER BY s.current_level, s.gender";
    $reports = $pdo->prepare($sql); $reports->execute($params); $reports = $reports->fetchAll();
    $total = $pdo->prepare("SELECT COUNT(*) as total FROM students s $where_clause"); $total->execute($params); $total = $total->fetch()['total'];
} else {
    $sql = "SELECT s.matric_number, CONCAT(s.first_name,' ',s.last_name) as student_name, s.gender, s.current_level, s.status, s.admission_year, s.email, s.phone, d.department_name, p.program_name FROM students s LEFT JOIN departments d ON s.department_id = d.department_id LEFT JOIN programs p ON s.program_id = p.program_id $where_clause ORDER BY s.matric_number LIMIT 200";
    $reports = $pdo->prepare($sql); $reports->execute($params); $reports = $reports->fetchAll();
}
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4"><div><h1><i class="fas fa-users me-2 text-primary"></i>Student Reports</h1><p class="text-muted">Demographics, enrollment and distribution reports</p></div><div><button class="btn btn-success" onclick="exportReport()"><i class="fas fa-file-excel me-2"></i>Export</button><button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button></div></div>
    
    <ul class="nav nav-tabs mb-4"><li class="nav-item"><a class="nav-link <?php echo $report_type == 'demographics' ? 'active' : ''; ?>" href="?report_type=demographics"><i class="fas fa-chart-pie me-2"></i>Demographics</a></li><li class="nav-item"><a class="nav-link <?php echo $report_type == 'list' ? 'active' : ''; ?>" href="?report_type=list"><i class="fas fa-list me-2"></i>Student List</a></li></ul>
    
    <div class="filter-section bg-light p-3 rounded mb-4"><form method="GET" class="row g-3"><input type="hidden" name="report_type" value="<?php echo $report_type; ?>"><div class="col-md-2"><label>Department</label><select class="form-select" name="department_id" onchange="this.form.submit()"><option value="0">All</option><?php foreach($departments as $d): ?><option value="<?php echo $d['department_id']; ?>" <?php echo $department_id == $d['department_id'] ? 'selected' : ''; ?>><?php echo $d['department_code']; ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label>Program</label><select class="form-select" name="program_id" onchange="this.form.submit()"><option value="0">All</option><?php foreach($programs as $p): ?><option value="<?php echo $p['program_id']; ?>" <?php echo $program_id == $p['program_id'] ? 'selected' : ''; ?>><?php echo $p['program_code']; ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label>Gender</label><select class="form-select" name="gender" onchange="this.form.submit()"><option value="">All</option><option value="Male" <?php echo $gender == 'Male' ? 'selected' : ''; ?>>Male</option><option value="Female" <?php echo $gender == 'Female' ? 'selected' : ''; ?>>Female</option></select></div><div class="col-md-2"><label>Level</label><select class="form-select" name="level" onchange="this.form.submit()"><option value="0">All</option><?php foreach($levels as $l): ?><option value="<?php echo $l; ?>" <?php echo $level == $l ? 'selected' : ''; ?>>Level <?php echo $l; ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label>Status</label><select class="form-select" name="status" onchange="this.form.submit()"><option value="">All</option><?php foreach($statuses as $st): ?><option value="<?php echo $st; ?>" <?php echo $status == $st ? 'selected' : ''; ?>><?php echo $st; ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-flex align-items-end"><a href="student_reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-outline-secondary w-100">Reset</a></div></form></div>
    
    <?php if($report_type == 'demographics'): ?>
    <div class="row g-3 mb-4"><div class="col-md-4"><div class="card bg-primary text-white"><div class="card-body"><h6>Total Students</h6><h2><?php echo number_format($total); ?></h2></div></div></div><?php $male = array_sum(array_column(array_filter($reports, fn($r)=>$r['gender']=='Male'), 'count')); $female = array_sum(array_column(array_filter($reports, fn($r)=>$r['gender']=='Female'), 'count')); ?><div class="col-md-4"><div class="card bg-info text-white"><div class="card-body"><h6>Male / Female</h6><h2><?php echo $male; ?> / <?php echo $female; ?></h2></div></div></div><div class="col-md-4"><div class="card bg-success text-white"><div class="card-body"><h6>Active Students</h6><h2><?php echo array_sum(array_column(array_filter($reports, fn($r)=>$r['status']=='Active'), 'count')); ?></h2></div></div></div></div>
    <div class="card shadow-sm"><div class="card-body"><h5><i class="fas fa-chart-bar me-2"></i>Student Distribution by Level</h5><canvas id="levelChart" height="100"></canvas><hr><h5><i class="fas fa-chart-pie me-2"></i>Gender Distribution</h5><canvas id="genderChart" height="100"></canvas></div></div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>new Chart(document.getElementById('levelChart'),{type:'bar',data:{labels:[<?php for($l=100;$l<=600;$l+=100): ?>'Level <?php echo $l; ?>',<?php endfor; ?>],datasets:[{label:'Number of Students',data:[<?php for($l=100;$l<=600;$l+=100): ?><?php echo array_sum(array_column(array_filter($reports, fn($r)=>$r['current_level']==$l), 'count')); ?>,<?php endfor; ?>],backgroundColor:'#4361ee'}]}});new Chart(document.getElementById('genderChart'),{type:'pie',data:{labels:['Male','Female'],datasets:[{data:[<?php echo $male; ?>,<?php echo $female; ?>],backgroundColor:['#4361ee','#f5576c']}]}});</script>
    <?php else: ?><div class="card shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Matric No</th><th>Student Name</th><th>Gender</th><th>Level</th><th>Department</th><th>Program</th><th>Status</th><th>Admission</th><th>Contact</th></tr></thead><tbody><?php foreach($reports as $r): ?><tr><td><strong><?php echo $r['matric_number']; ?></strong></td><td><a href="view_student_results.php?student_id=<?php echo $r['student_id'] ?? 0; ?>"><?php echo $r['student_name']; ?></a></td><td><?php echo $r['gender']; ?></td><td><?php echo $r['current_level']; ?></td><td><?php echo $r['department_name']; ?></td><td><?php echo $r['program_name']; ?></td><td><span class="badge bg-<?php echo $r['status'] == 'Active' ? 'success' : 'danger'; ?>"><?php echo $r['status']; ?></span></td><td><?php echo $r['admission_year']; ?></td><td><small><?php echo $r['email']; ?><br><?php echo $r['phone']; ?></small></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
    <?php if(empty($reports)): ?><div class="alert alert-info text-center py-5"><i class="fas fa-info-circle fa-2x mb-2 d-block"></i><h5>No student data found</h5></div><?php endif; ?>
</div>
<script>function exportReport(){window.location.href='export_student_report.php?'+new URLSearchParams(window.location.search).toString();}</script>
<?php require_once 'includes/footer.php'; ?>