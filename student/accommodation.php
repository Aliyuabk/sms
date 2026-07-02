<?php
require_once 'includes/header.php';

$student_id = $_SESSION['student_id'];
$current_session = "2025/2026";

// Get student data
$student_query = "SELECT s.*, d.department_name, p.program_name 
                  FROM students s
                  LEFT JOIN departments d ON s.department_id = d.department_id
                  LEFT JOIN programs p ON s.program_id = p.program_id
                  WHERE s.student_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

$student_gender = $student['gender'];

// Check existing active allocation
$allocation_query = "SELECT ha.*, h.hostel_name, h.hostel_code, h.monthly_rent, 
                            h.warden_name, h.warden_phone, h.warden_email, h.amenities,
                            h.total_rooms, h.capacity_per_room
                     FROM hostel_allocations ha
                     JOIN hostels h ON ha.hostel_id = h.hostel_id
                     WHERE ha.student_id = ? AND ha.status = 'Active' AND ha.academic_year = ?";
$stmt = $conn->prepare($allocation_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$allocation = $stmt->get_result()->fetch_assoc();

// Check pending application
$pending_query = "SELECT ha.*, h.hostel_name, h.total_rooms, h.capacity_per_room
                  FROM hostel_allocations ha
                  JOIN hostels h ON ha.hostel_id = h.hostel_id
                  WHERE ha.student_id = ? AND ha.status = 'Pending' AND ha.academic_year = ?";
$stmt = $conn->prepare($pending_query);
$stmt->bind_param("is", $student_id, $current_session);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();

// Get available hostels based on gender
$hostels_query = "SELECT * FROM hostels 
                  WHERE status = 'Available' AND gender = ?
                  ORDER BY hostel_name";
$stmt = $conn->prepare($hostels_query);
$stmt->bind_param("s", $student_gender);
$stmt->execute();
$available_hostels = $stmt->get_result();

// Function to get available rooms for a hostel (generated dynamically)
function getAvailableRooms($conn, $hostel_id, $total_rooms) {
    // Get already allocated rooms for this hostel
    $allocated_query = "SELECT DISTINCT room_number FROM hostel_allocations 
                        WHERE hostel_id = ? AND status = 'Active'";
    $stmt = $conn->prepare($allocated_query);
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $allocated_rooms = [];
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $allocated_rooms[] = $row['room_number'];
    }
    
    // Generate available rooms (1 to total_rooms)
    $available_rooms = [];
    for($i = 1; $i <= $total_rooms; $i++) {
        $room_name = "Room " . $i;
        if(!in_array($room_name, $allocated_rooms)) {
            $available_rooms[] = [
                'room_number' => $i,
                'room_name' => $room_name
            ];
        }
    }
    return $available_rooms;
}

// Function to get available beds for a room
function getAvailableBeds($conn, $hostel_id, $room_number, $capacity_per_room) {
    // Get already allocated beds for this specific room
    $allocated_query = "SELECT bed_number FROM hostel_allocations 
                        WHERE hostel_id = ? AND room_number = ? AND status = 'Active'";
    $stmt = $conn->prepare($allocated_query);
    $stmt->bind_param("is", $hostel_id, $room_number);
    $stmt->execute();
    $allocated_beds = [];
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()) {
        $allocated_beds[] = $row['bed_number'];
    }
    
    // Generate available beds (1 to capacity_per_room)
    $available_beds = [];
    for($i = 1; $i <= $capacity_per_room; $i++) {
        if(!in_array($i, $allocated_beds)) {
            $available_beds[] = $i;
        }
    }
    return $available_beds;
}

// Get total available beds for a hostel
function getTotalAvailableBeds($conn, $hostel_id, $total_rooms, $capacity_per_room) {
    $total_beds = 0;
    for($room = 1; $room <= $total_rooms; $room++) {
        $room_name = "Room " . $room;
        $available_beds = getAvailableBeds($conn, $hostel_id, $room_name, $capacity_per_room);
        $total_beds += count($available_beds);
    }
    return $total_beds;
}

// AJAX: Get rooms for a hostel
if(isset($_GET['get_rooms']) && isset($_GET['hostel_id'])) {
    $hostel_id = $_GET['hostel_id'];
    // Get hostel details
    $hostel_query = "SELECT total_rooms FROM hostels WHERE hostel_id = ?";
    $stmt = $conn->prepare($hostel_query);
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $hostel = $stmt->get_result()->fetch_assoc();
    
    $rooms = getAvailableRooms($conn, $hostel_id, $hostel['total_rooms']);
    echo json_encode($rooms);
    exit();
}

// AJAX: Get beds for a room
if(isset($_GET['get_beds']) && isset($_GET['hostel_id']) && isset($_GET['room_number'])) {
    $hostel_id = $_GET['hostel_id'];
    $room_number = $_GET['room_number'];
    
    // Get hostel capacity
    $hostel_query = "SELECT capacity_per_room FROM hostels WHERE hostel_id = ?";
    $stmt = $conn->prepare($hostel_query);
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $hostel = $stmt->get_result()->fetch_assoc();
    
    $beds = getAvailableBeds($conn, $hostel_id, $room_number, $hostel['capacity_per_room']);
    echo json_encode($beds);
    exit();
}

// Handle application submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_application'])) {
    $hostel_id = $_POST['hostel_id'];
    $room_number = $_POST['room_number'];
    $bed_number = $_POST['bed_number'];
    $duration = $_POST['duration'];
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+$duration months"));
    
    // Get hostel details for validation
    $hostel_query = "SELECT capacity_per_room, total_rooms FROM hostels WHERE hostel_id = ?";
    $stmt = $conn->prepare($hostel_query);
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $hostel = $stmt->get_result()->fetch_assoc();
    
    // Verify bed still available
    $available_beds = getAvailableBeds($conn, $hostel_id, $room_number, $hostel['capacity_per_room']);
    
    if(in_array($bed_number, $available_beds)) {
        $insert = "INSERT INTO hostel_allocations (student_id, hostel_id, room_number, bed_number, academic_year, 
                    start_date, end_date, payment_status, status, allocation_date, notes) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'Pending', NOW(), ?)";
        $stmt = $conn->prepare($insert);
        $notes = "Duration: $duration months";
        $stmt->bind_param("iisiss s", $student_id, $hostel_id, $room_number, $bed_number, $current_session, 
                         $start_date, $end_date, $notes);
        
        if($stmt->execute()) {
            $success = "Application submitted successfully! You will be notified once processed.";
            echo "<meta http-equiv='refresh' content='2'>";
        } else {
            $error = "Submission failed: " . $conn->error;
        }
    } else {
        $error = "This bed is no longer available. Please select another.";
    }
}

// Handle clearance download
if(isset($_GET['download_clearance']) && $allocation) {
    require_once('../fpdf/fpdf.php');
    
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(30, 86, 49);
    $pdf->Cell(0, 15, 'HOSTEL CLEARANCE CERTIFICATE', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'OFFICIAL HOSTEL CLEARANCE DOCUMENT', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Border
    $pdf->SetDrawColor(30, 86, 49);
    $pdf->Rect(10, 30, 190, 250);
    
    // Student Details
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'STUDENT INFORMATION', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 8, 'Student Name:', 0, 0);
    $pdf->Cell(0, 8, strtoupper($student['first_name'] . ' ' . $student['last_name']), 0, 1);
    $pdf->Cell(50, 8, 'Matric Number:', 0, 0);
    $pdf->Cell(0, 8, $student['matric_number'], 0, 1);
    $pdf->Cell(50, 8, 'Department:', 0, 0);
    $pdf->Cell(0, 8, $student['department_name'], 0, 1);
    $pdf->Cell(50, 8, 'Program:', 0, 0);
    $pdf->Cell(0, 8, $student['program_name'], 0, 1);
    $pdf->Cell(50, 8, 'Level:', 0, 0);
    $pdf->Cell(0, 8, $student['current_level'] . ' Level', 0, 1);
    $pdf->Ln(5);
    
    // Hostel Details
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'HOSTEL ALLOCATION DETAILS', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 8, 'Hostel Name:', 0, 0);
    $pdf->Cell(0, 8, $allocation['hostel_name'] . ' (' . $allocation['hostel_code'] . ')', 0, 1);
    $pdf->Cell(50, 8, 'Room Number:', 0, 0);
    $pdf->Cell(0, 8, $allocation['room_number'], 0, 1);
    $pdf->Cell(50, 8, 'Bed Number:', 0, 0);
    $pdf->Cell(0, 8, 'Bed ' . $allocation['bed_number'], 0, 1);
    $pdf->Cell(50, 8, 'Monthly Rent:', 0, 0);
    $pdf->Cell(0, 8, '₦' . number_format($allocation['monthly_rent']), 0, 1);
    $pdf->Cell(50, 8, 'Allocation Period:', 0, 0);
    $pdf->Cell(0, 8, date('d M Y', strtotime($allocation['start_date'])) . ' to ' . date('d M Y', strtotime($allocation['end_date'])), 0, 1);
    $pdf->Ln(5);
    
    // Clearance Statement
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'CLEARANCE STATUS', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 8, "This is to certify that the above-named student has been duly allocated hostel accommodation for the $current_session academic session. The student is cleared to occupy the assigned hostel room subject to compliance with hostel rules and regulations.");
    $pdf->Ln(5);
    
    // Signatures
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(80, 30, '', 0, 0);
    $pdf->Cell(50, 30, '', 'T', 0);
    $pdf->Cell(60, 30, '', 'T', 1);
    $pdf->Cell(80, 0, '', 0, 0);
    $pdf->Cell(50, 0, 'Hall Warden\'s Signature', 0, 0, 'C');
    $pdf->Cell(60, 0, 'Dean of Students\' Signature', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(0, 8, 'Generated on: ' . date('F d, Y H:i:s'), 0, 1, 'C');
    $pdf->Cell(0, 8, 'Document ID: ' . $student['matric_number'] . '_' . date('Ymd'), 0, 1, 'C');
    $pdf->Cell(0, 8, 'This is a computer-generated clearance certificate.', 0, 1, 'C');
    
    $pdf->Output('D', 'Hostel_Clearance_' . $student['matric_number'] . '.pdf');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostel Accommodation - Student Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fb;
        }

        .fade-in {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #1a1a2e;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #666;
        }

        .gender-banner {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .gender-banner.male {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-left: 4px solid #3b82f6;
        }

        .gender-banner.female {
            background: linear-gradient(135deg, #fdf2f8, #fce7f3);
            border-left: 4px solid #ec4899;
        }

        .gender-banner i {
            font-size: 20px;
        }

        .gender-banner.male i { color: #3b82f6; }
        .gender-banner.female i { color: #ec4899; }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .allocation-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .allocation-card.active .card-header {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        }

        .allocation-card.pending .card-header {
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
        }

        .card-header h3 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        .status.active { background: #22c55e; color: white; }
        .status.pending { background: #f59e0b; color: white; }

        .allocation-details {
            padding: 25px;
        }

        .detail-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-group label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .detail-group p {
            font-size: 15px;
            font-weight: 500;
            color: #1f2937;
        }

        .amenities {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }

        .amenity-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .amenity-tags span {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }

        .action-buttons {
            padding: 20px 25px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            background: #f9fafb;
        }

        .pending-details {
            text-align: center;
            padding: 40px;
        }

        .pending-icon {
            width: 80px;
            height: 80px;
            background: #fef3c7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .pending-icon i {
            font-size: 40px;
            color: #f59e0b;
        }

        .app-info {
            background: #f9fafb;
            padding: 15px;
            border-radius: 12px;
            max-width: 350px;
            margin: 20px auto;
            text-align: left;
        }

        .app-info p {
            margin: 8px 0;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .info-card h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 12px;
        }

        .step-number {
            width: 30px;
            height: 30px;
            background: #166534;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .available-hostels h3 {
            margin-bottom: 20px;
            font-size: 20px;
        }

        .hostels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 25px;
        }

        .hostel-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }

        .hostel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .hostel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .hostel-header h4 {
            color: #166534;
            font-size: 18px;
        }

        .hostel-code {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .hostel-stats {
            margin-bottom: 15px;
        }

        .hostel-stats div {
            padding: 8px 0;
            border-bottom: 1px dashed #e5e7eb;
            display: flex;
            gap: 10px;
            font-size: 13px;
        }

        .hostel-stats i {
            width: 20px;
            color: #166534;
        }

        .btn-select, .btn-primary, .btn-outline {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-select, .btn-primary {
            background: #166534;
            color: white;
            width: 100%;
            justify-content: center;
            margin-top: 15px;
        }

        .btn-select:hover, .btn-primary:hover {
            background: #14532d;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #166534;
            color: #166534;
        }

        .btn-outline:hover {
            background: #166534;
            color: white;
        }

        .no-hostels {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }

        .no-hostels i {
            font-size: 60px;
            color: #ccc;
            margin-bottom: 15px;
        }

        .rules-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-top: 30px;
        }

        .rules-card h3 {
            margin-bottom: 20px;
        }

        .rules-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .rules-grid ul {
            list-style: none;
        }

        .rules-grid li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rules-grid li i {
            color: #166534;
            width: 18px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header .close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }

        .modal-body {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 13px;
        }

        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
        }

        .form-group select:focus {
            outline: none;
            border-color: #166534;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        @media (max-width: 768px) {
            .detail-row {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                flex-direction: column;
            }
            .rules-grid {
                grid-template-columns: 1fr;
            }
            .steps {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="fade-in">
    <div class="page-header">
        <h1><i class="fas fa-bed"></i> Hostel Accommodation</h1>
        <p>Apply for hostel, check allocation status, and download clearance</p>
    </div>

    <div class="gender-banner <?php echo strtolower($student_gender); ?>">
        <i class="fas <?php echo $student_gender == 'Male' ? 'fa-mars' : 'fa-venus'; ?>"></i>
        <span><strong><?php echo $student_gender; ?> Student</strong> - Viewing hostels for <?php echo $student_gender; ?> students only</span>
    </div>

    <?php if(isset($success)): ?>
    <div class="alert success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>

    <?php if(isset($error)): ?>
    <div class="alert error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <?php if($allocation): ?>
    <div class="allocation-card active">
        <div class="card-header">
            <h3><i class="fas fa-home"></i> Your Active Allocation</h3>
            <span class="status active">ACTIVE</span>
        </div>
        <div class="allocation-details">
            <div class="detail-row">
                <div class="detail-group">
                    <label><i class="fas fa-building"></i> Hostel</label>
                    <p><?php echo htmlspecialchars($allocation['hostel_name']); ?> (<?php echo htmlspecialchars($allocation['hostel_code']); ?>)</p>
                </div>
                <div class="detail-group">
                    <label><i class="fas fa-door-open"></i> Room</label>
                    <p><?php echo $allocation['room_number']; ?></p>
                </div>
                <div class="detail-group">
                    <label><i class="fas fa-bed"></i> Bed</label>
                    <p>Bed <?php echo $allocation['bed_number']; ?></p>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-group">
                    <label><i class="fas fa-calendar"></i> Period</label>
                    <p><?php echo date('d M Y', strtotime($allocation['start_date'])); ?> - <?php echo date('d M Y', strtotime($allocation['end_date'])); ?></p>
                </div>
                <div class="detail-group">
                    <label><i class="fas fa-money-bill"></i> Monthly Rent</label>
                    <p>₦<?php echo number_format($allocation['monthly_rent']); ?></p>
                </div>
                <div class="detail-group">
                    <label><i class="fas fa-credit-card"></i> Payment Status</label>
                    <p class="status <?php echo strtolower($allocation['payment_status'] ?? 'pending'); ?>"><?php echo $allocation['payment_status'] ?? 'Pending'; ?></p>
                </div>
            </div>
            <?php if($allocation['amenities']): ?>
            <div class="amenities">
                <label><i class="fas fa-concierge-bell"></i> Amenities</label>
                <div class="amenity-tags">
                    <?php foreach(explode(',', $allocation['amenities']) as $amenity): ?>
                    <span><i class="fas fa-check-circle"></i> <?php echo trim($amenity); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="action-buttons">
            <a href="?download_clearance=1" class="btn-primary" style="text-decoration: none;">
                <i class="fas fa-download"></i> Download Clearance
            </a>
            <?php if(($allocation['payment_status'] ?? 'Pending') != 'Paid'): ?>
            <button class="btn-outline" onclick="payNow(<?php echo $allocation['allocation_id']; ?>)">
                <i class="fas fa-credit-card"></i> Pay Rent
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif($pending): ?>
    <div class="allocation-card pending">
        <div class="card-header">
            <h3><i class="fas fa-hourglass-half"></i> Application Pending</h3>
            <span class="status pending">PENDING</span>
        </div>
        <div class="pending-details">
            <div class="pending-icon">
                <i class="fas fa-clock"></i>
            </div>
            <p>Your hostel application is being reviewed by the accommodation office.</p>
            <div class="app-info">
                <p><strong>Hostel:</strong> <?php echo htmlspecialchars($pending['hostel_name']); ?></p>
                <p><strong>Room:</strong> <?php echo $pending['room_number']; ?></p>
                <p><strong>Bed:</strong> Bed <?php echo $pending['bed_number']; ?></p>
                <p><strong>Applied on:</strong> <?php echo date('d M Y', strtotime($pending['allocation_date'])); ?></p>
            </div>
            <button class="btn-outline" onclick="cancelApplication(<?php echo $pending['allocation_id']; ?>)">
                <i class="fas fa-times"></i> Cancel Application
            </button>
        </div>
    </div>

    <?php else: ?>
    <div class="info-card">
        <h3><i class="fas fa-info-circle"></i> How to Apply for Accommodation</h3>
        <div class="steps">
            <div class="step"><div class="step-number">1</div> Browse available hostels</div>
            <div class="step"><div class="step-number">2</div> Select hostel, room, and bed</div>
            <div class="step"><div class="step-number">3</div> Submit application</div>
            <div class="step"><div class="step-number">4</div> Wait for approval</div>
            <div class="step"><div class="step-number">5</div> Pay fees once approved</div>
            <div class="step"><div class="step-number">6</div> Download clearance</div>
        </div>
    </div>

    <?php if($available_hostels->num_rows > 0): ?>
    <div class="available-hostels">
        <h3><i class="fas fa-building"></i> Available Hostels (<?php echo $student_gender; ?> Only)</h3>
        <div class="hostels-grid">
            <?php while($hostel = $available_hostels->fetch_assoc()): 
                $total_beds_available = getTotalAvailableBeds($conn, $hostel['hostel_id'], $hostel['total_rooms'], $hostel['capacity_per_room']);
                $available_rooms = getAvailableRooms($conn, $hostel['hostel_id'], $hostel['total_rooms']);
                $available_rooms_count = count($available_rooms);
            ?>
            <div class="hostel-card">
                <div class="hostel-header">
                    <h4><?php echo htmlspecialchars($hostel['hostel_name']); ?></h4>
                    <span class="hostel-code"><?php echo htmlspecialchars($hostel['hostel_code']); ?></span>
                </div>
                <div class="hostel-stats">
                    <div><i class="fas fa-bed"></i> <?php echo $total_beds_available; ?> beds available</div>
                    <div><i class="fas fa-door-open"></i> <?php echo $available_rooms_count; ?> rooms available</div>
                    <div><i class="fas fa-money-bill"></i> ₦<?php echo number_format($hostel['monthly_rent']); ?>/month</div>
                    <div><i class="fas fa-user-friends"></i> <?php echo $hostel['capacity_per_room']; ?> beds per room</div>
                </div>
                <?php if($hostel['amenities']): ?>
                <div class="amenity-tags" style="margin-top: 10px;">
                    <?php $amenities = array_slice(explode(',', $hostel['amenities']), 0, 3); ?>
                    <?php foreach($amenities as $a): ?>
                    <span><?php echo trim($a); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <button class="btn-select" onclick="openApplicationModal(<?php echo $hostel['hostel_id']; ?>, <?php echo $hostel['total_rooms']; ?>, <?php echo $hostel['capacity_per_room']; ?>)">
                    <i class="fas fa-paper-plane"></i> Apply Now
                </button>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="no-hostels">
        <i class="fas fa-bed"></i>
        <h3>No Hostels Available</h3>
        <p>No <?php echo strtolower($student_gender); ?> hostels are currently available for application.</p>
        <p>Please check back later or contact the accommodation office.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="rules-card">
        <h3><i class="fas fa-gavel"></i> Hostel Rules & Regulations</h3>
        <div class="rules-grid">
            <ul>
                <li><i class="fas fa-check"></i> Keep your room clean at all times</li>
                <li><i class="fas fa-check"></i> No visitors after 8:00 PM</li>
                <li><i class="fas fa-check"></i> No smoking or alcohol on premises</li>
                <li><i class="fas fa-check"></i> Report maintenance issues promptly</li>
            </ul>
            <ul>
                <li><i class="fas fa-check"></i> Quiet hours: 10 PM - 6 AM</li>
                <li><i class="fas fa-check"></i> No tampering with electrical fittings</li>
                <li><i class="fas fa-check"></i> Pay fees before 5th of each month</li>
                <li><i class="fas fa-check"></i> Damages will be charged to occupant</li>
            </ul>
        </div>
    </div>
</div>

<!-- Application Modal -->
<div id="appModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-paper-plane"></i> Apply for Accommodation</h3>
            <button class="close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Hostel</label>
                    <select id="app_hostel" name="hostel_id" required onchange="loadRooms()">
                        <option value="">Choose a hostel</option>
                        <?php 
                        $available_hostels->data_seek(0);
                        while($h = $available_hostels->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $h['hostel_id']; ?>" data-total-rooms="<?php echo $h['total_rooms']; ?>" data-capacity="<?php echo $h['capacity_per_room']; ?>">
                            <?php echo htmlspecialchars($h['hostel_name']); ?> - ₦<?php echo number_format($h['monthly_rent']); ?>/month
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Room</label>
                    <select id="app_room" name="room_number" required disabled onchange="loadBeds()">
                        <option value="">First select a hostel</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Bed</label>
                    <select id="app_bed" name="bed_number" required disabled>
                        <option value="">First select a room</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Duration</label>
                    <select name="duration" required>
                        <option value="6">6 Months (One Semester)</option>
                        <option value="12">12 Months (Full Session)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" required>
                        I agree to abide by all hostel rules and regulations
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" name="submit_application" class="btn-primary">Submit Application</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentHostelId = null;
let currentTotalRooms = null;
let currentCapacity = null;

function openApplicationModal(hostelId, totalRooms, capacity) {
    currentHostelId = hostelId;
    currentTotalRooms = totalRooms;
    currentCapacity = capacity;
    document.getElementById('app_hostel').value = hostelId;
    document.getElementById('appModal').classList.add('show');
    loadRooms();
}

function closeModal() {
    document.getElementById('appModal').classList.remove('show');
}

function loadRooms() {
    const hostelId = document.getElementById('app_hostel').value;
    const roomSelect = document.getElementById('app_room');
    const bedSelect = document.getElementById('app_bed');
    
    if(!hostelId) return;
    
    roomSelect.innerHTML = '<option value="">Loading rooms...</option>';
    roomSelect.disabled = true;
    bedSelect.innerHTML = '<option value="">Select bed</option>';
    bedSelect.disabled = true;
    
    fetch(`?get_rooms=1&hostel_id=${hostelId}`)
        .then(response => response.json())
        .then(rooms => {
            roomSelect.innerHTML = '<option value="">Select Room</option>';
            if(rooms.length === 0) {
                roomSelect.innerHTML = '<option value="">No rooms available</option>';
            } else {
                rooms.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.room_name;
                    option.textContent = room.room_name;
                    roomSelect.appendChild(option);
                });
            }
            roomSelect.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            roomSelect.innerHTML = '<option value="">Error loading rooms</option>';
            roomSelect.disabled = false;
        });
}

function loadBeds() {
    const hostelId = document.getElementById('app_hostel').value;
    const roomNumber = document.getElementById('app_room').value;
    const bedSelect = document.getElementById('app_bed');
    
    if(!roomNumber) return;
    
    bedSelect.innerHTML = '<option value="">Loading beds...</option>';
    bedSelect.disabled = true;
    
    fetch(`?get_beds=1&hostel_id=${hostelId}&room_number=${encodeURIComponent(roomNumber)}`)
        .then(response => response.json())
        .then(beds => {
            bedSelect.innerHTML = '<option value="">Select Bed</option>';
            if(beds.length === 0) {
                bedSelect.innerHTML = '<option value="">No beds available</option>';
            } else {
                beds.forEach(bed => {
                    const option = document.createElement('option');
                    option.value = bed;
                    option.textContent = `Bed ${bed}`;
                    bedSelect.appendChild(option);
                });
            }
            bedSelect.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            bedSelect.innerHTML = '<option value="">Error loading beds</option>';
            bedSelect.disabled = false;
        });
}

function payNow(allocationId) {
    window.location.href = `payment.php?type=hostel&id=${allocationId}`;
}

function cancelApplication(appId) {
    if(confirm('Are you sure you want to cancel your application?')) {
        alert('Cancellation request submitted. Please contact the accommodation office.');
    }
}

document.getElementById('app_room').addEventListener('change', loadBeds);
</script>

</body>
</html>

<?php require_once 'includes/footer.php'; ?>