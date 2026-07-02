<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_password = '';
$db_name = 'student_portal_db';

// Initialize variables
$student_data = [];
$academic_sessions = [];
$current_session = [];
$academic_calendar = [];
$selected_session = '';
$selected_semester = '';
$important_dates = [];

try {
    // Create database connection
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Get student ID from session
    $student_id = $_SESSION['student_id'];
    
    // Fetch student information
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $student_data = $result->fetch_assoc();
        $stmt->close();
        
        // Get all academic sessions
        $sessions_sql = "SELECT session_year, semester, session_name, is_current 
                        FROM academic_sessions 
                        ORDER BY session_year DESC, semester DESC";
        $sessions_result = $conn->query($sessions_sql);
        
        while ($session = $sessions_result->fetch_assoc()) {
            $academic_sessions[] = $session;
        }
        
        // Get current session
        $current_session_sql = "SELECT * FROM academic_sessions WHERE is_current = TRUE LIMIT 1";
        $current_session_result = $conn->query($current_session_sql);
        if ($current_session_result && $current_session_result->num_rows > 0) {
            $current_session = $current_session_result->fetch_assoc();
            $selected_session = $current_session['session_year'];
            $selected_semester = $current_session['semester'];
        }
        
        // Check if session is selected via GET
        if (isset($_GET['session']) && !empty($_GET['session'])) {
            $session_data = explode('|', $_GET['session']);
            if (count($session_data) === 2) {
                $selected_session = htmlspecialchars($session_data[0]);
                $selected_semester = intval($session_data[1]);
            }
        }
        
        // Get calendar events for selected session
        if ($selected_session && $selected_semester) {
            // First, let's check what format the session_year is in the database
            $check_sql = "SELECT session_year FROM academic_sessions WHERE semester = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $selected_semester);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $sample_row = $check_result->fetch_assoc();
            $check_stmt->close();
            
            // Based on the sample, determine the format
            $session_year_format = '';
            if ($sample_row && isset($sample_row['session_year'])) {
                $session_year_format = $sample_row['session_year'];
            }
            
            // If session_year contains semester in it (like "2023/2024-1"), use it directly
            if (strpos($session_year_format, '-') !== false) {
                $calendar_sql = "SELECT * FROM academic_sessions 
                                WHERE session_year LIKE ? AND semester = ?";
                $session_year_param = $selected_session . '-%';
                $calendar_stmt = $conn->prepare($calendar_sql);
                $calendar_stmt->bind_param("si", $session_year_param, $selected_semester);
            } else {
                // Otherwise, use exact match
                $calendar_sql = "SELECT * FROM academic_sessions 
                                WHERE session_year = ? AND semester = ?";
                $calendar_stmt = $conn->prepare($calendar_sql);
                $calendar_stmt->bind_param("si", $selected_session, $selected_semester);
            }
            
            $calendar_stmt->execute();
            $calendar_result = $calendar_stmt->get_result();
            
            if ($calendar_result->num_rows > 0) {
                $academic_calendar = $calendar_result->fetch_assoc();
            } else {
                // Try alternative format
                $alt_sql = "SELECT * FROM academic_sessions WHERE session_year = ?";
                $alt_stmt = $conn->prepare($alt_sql);
                $alt_param = $selected_session . '-' . $selected_semester;
                $alt_stmt->bind_param("s", $alt_param);
                $alt_stmt->execute();
                $alt_result = $alt_stmt->get_result();
                
                if ($alt_result->num_rows > 0) {
                    $academic_calendar = $alt_result->fetch_assoc();
                }
                $alt_stmt->close();
            }
            $calendar_stmt->close();
            
            // Get important dates and deadlines - SIMPLIFIED VERSION
            $important_dates_sql = "SELECT 
                                    'Registration Period' as event_name,
                                    registration_start as start_date,
                                    registration_end as end_date,
                                    'Academic Registration' as category,
                                    'Registration must be completed by this date' as description
                                FROM academic_sessions 
                                WHERE session_year = ? AND semester = ?
                                
                                UNION ALL
                                
                                SELECT 
                                    'Add/Drop Period',
                                    add_drop_start,
                                    add_drop_end,
                                    'Course Management',
                                    'Add or drop courses during this period'
                                FROM academic_sessions 
                                WHERE session_year = ? AND semester = ?
                                
                                UNION ALL
                                
                                SELECT 
                                    'Lectures Period',
                                    lectures_start,
                                    lectures_end,
                                    'Academic',
                                    'Regular lecture sessions'
                                FROM academic_sessions 
                                WHERE session_year = ? AND semester = ?
                                
                                UNION ALL
                                
                                SELECT 
                                    'Examinations',
                                    exams_start,
                                    exams_end,
                                    'Examinations',
                                    'Final examinations period'
                                FROM academic_sessions 
                                WHERE session_year = ? AND semester = ?
                                
                                ORDER BY start_date";
            
            $important_stmt = $conn->prepare($important_dates_sql);
            
            // Check database format and use appropriate parameter
            if (strpos($session_year_format, '-') !== false) {
                $session_param = $selected_session . '-' . $selected_semester;
                $important_stmt->bind_param("ssssssss", 
                    $session_param, $selected_semester,
                    $session_param, $selected_semester,
                    $session_param, $selected_semester,
                    $session_param, $selected_semester
                );
            } else {
                $important_stmt->bind_param("sisisisis", 
                    $selected_session, $selected_semester,
                    $selected_session, $selected_semester,
                    $selected_session, $selected_semester,
                    $selected_session, $selected_semester
                );
            }
            
            $important_stmt->execute();
            $important_result = $important_stmt->get_result();
            
            $important_dates = [];
            while ($date = $important_result->fetch_assoc()) {
                if (!empty($date['start_date'])) {
                    $important_dates[] = $date;
                }
            }
            $important_stmt->close();
        }
        
    } else {
        throw new Exception("Student record not found.");
    }
    
    $conn->close();
    
} catch (Exception $e) {
    // Fallback data for demo
    $student_data = [
        'first_name' => $_SESSION['student_name'] ?? 'Student',
        'last_name' => 'User',
        'matric_number' => $_SESSION['matric_number'] ?? 'CSC/2023/001',
        'current_level' => 300,
        'department_name' => 'Computer Science',
        'program_name' => 'B.Sc. Computer Science'
    ];
    
    $academic_sessions = [
        ['session_year' => '2023/2024-2', 'semester' => 2, 'session_name' => 'Second Semester 2023/2024', 'is_current' => 1],
        ['session_year' => '2023/2024-1', 'semester' => 1, 'session_name' => 'First Semester 2023/2024', 'is_current' => 0],
        ['session_year' => '2022/2023-2', 'semester' => 2, 'session_name' => 'Second Semester 2022/2023', 'is_current' => 0],
    ];
    
    $current_session = [
        'session_year' => '2023/2024-2',
        'semester' => 2,
        'session_name' => 'Second Semester 2023/2024',
        'start_date' => '2024-01-08',
        'end_date' => '2024-04-26',
        'registration_start' => '2023-12-18',
        'registration_end' => '2024-01-19',
        'add_drop_start' => '2024-01-22',
        'add_drop_end' => '2024-02-02',
        'lectures_start' => '2024-01-08',
        'lectures_end' => '2024-03-29',
        'exams_start' => '2024-04-01',
        'exams_end' => '2024-04-26',
        'break_start' => '2024-03-11',
        'break_end' => '2024-03-15',
        'results_deadline' => '2024-05-17'
    ];
    
    $selected_session = '2023/2024';
    $selected_semester = 2;
    
    $important_dates = [
        [
            'event_name' => 'Registration Period',
            'start_date' => '2023-12-18',
            'end_date' => '2024-01-19',
            'category' => 'Academic Registration',
            'description' => 'Registration must be completed by this date'
        ],
        [
            'event_name' => 'Add/Drop Period',
            'start_date' => '2024-01-22',
            'end_date' => '2024-02-02',
            'category' => 'Course Management',
            'description' => 'Add or drop courses during this period'
        ],
        [
            'event_name' => 'Lectures Begin',
            'start_date' => '2024-01-08',
            'end_date' => '2024-03-29',
            'category' => 'Academic',
            'description' => 'Regular lecture sessions'
        ],
        [
            'event_name' => 'Examinations',
            'start_date' => '2024-04-01',
            'end_date' => '2024-04-26',
            'category' => 'Examinations',
            'description' => 'Final examinations period'
        ]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar | Al-Qalam University</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #3B82F6;
            --light: #f8f9fa;
            --dark: #1f2937;
            --dark-gray: #374151;
            --gray: #6c757d;
            --light-gray: #e5e7eb;
            --border-radius: 12px;
            --border-radius-sm: 8px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            color: var(--dark);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header */
        .header {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: var(--gray);
            font-size: 15px;
        }
        
        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: var(--border-radius-sm);
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Student Info Banner */
        .student-banner {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 600;
        }
        
        /* Current Session Card */
        .current-session-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-left: 6px solid var(--primary);
        }
        
        .session-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .session-title i {
            font-size: 24px;
        }
        
        .session-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .session-info-item {
            background: var(--light);
            padding: 15px;
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--info);
        }
        
        .session-info-item.warning {
            border-left-color: var(--warning);
        }
        
        .session-info-item.success {
            border-left-color: var(--success);
        }
        
        .session-info-item.danger {
            border-left-color: var(--danger);
        }
        
        .session-info-label {
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .session-info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .session-info-note {
            font-size: 12px;
            color: var(--gray);
            margin-top: 5px;
            font-style: italic;
        }
        
        /* Session Selector */
        .session-selector {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .session-header {
            margin-bottom: 20px;
        }
        
        .session-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .session-info {
            color: var(--gray);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .session-info i {
            font-size: 12px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 15px;
        }
        
        .select-wrapper {
            position: relative;
            width: 100%;
            max-width: 400px;
        }
        
        .form-select {
            width: 100%;
            padding: 12px 45px 12px 16px;
            font-size: 15px;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            background-color: white;
            color: var(--dark);
            appearance: none;
            cursor: pointer;
            transition: all 0.2s ease;
            line-height: 1.5;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .form-select:hover {
            border-color: #cbd5e1;
        }
        
        .select-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #94a3b8;
            font-size: 14px;
        }
        
        /* Timeline */
        .timeline-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .section-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .section-header p {
            color: var(--gray);
        }
        
        .timeline {
            position: relative;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .timeline::after {
            content: '';
            position: absolute;
            width: 6px;
            background-color: var(--primary);
            top: 0;
            bottom: 0;
            left: 50%;
            margin-left: -3px;
        }
        
        .timeline-item {
            padding: 10px 40px;
            position: relative;
            width: 50%;
            box-sizing: border-box;
        }
        
        .timeline-item:nth-child(odd) {
            left: 0;
        }
        
        .timeline-item:nth-child(even) {
            left: 50%;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            width: 25px;
            height: 25px;
            right: -17px;
            background-color: white;
            border: 4px solid var(--primary);
            top: 15px;
            border-radius: 50%;
            z-index: 1;
        }
        
        .timeline-item:nth-child(even)::after {
            left: -16px;
        }
        
        .timeline-content {
            padding: 20px;
            background-color: white;
            position: relative;
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--light-gray);
            transition: all 0.3s;
        }
        
        .timeline-content:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }
        
        .timeline-date {
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .timeline-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .timeline-category {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .category-academic {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .category-registration {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .category-exam {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .category-break {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .timeline-description {
            color: var(--gray);
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* Calendar Grid */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .calendar-card {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 20px;
            border: 1px solid var(--light-gray);
            transition: all 0.3s;
        }
        
        .calendar-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .calendar-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .calendar-card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .calendar-card-date {
            font-size: 14px;
            color: var(--primary);
            font-weight: 600;
        }
        
        .calendar-card-body {
            font-size: 14px;
            color: var(--gray);
            line-height: 1.5;
        }
        
        .calendar-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .status-upcoming {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }
        
        .status-ongoing {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .status-completed {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .header-right {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .session-header {
                text-align: center;
            }
            
            .timeline::after {
                left: 31px;
            }
            
            .timeline-item {
                width: 100%;
                padding-left: 70px;
                padding-right: 25px;
            }
            
            .timeline-item:nth-child(even) {
                left: 0;
            }
            
            .timeline-item::after {
                left: 18px;
            }
            
            .timeline-item:nth-child(odd)::after,
            .timeline-item:nth-child(even)::after {
                left: 18px;
            }
            
            .form-select {
                font-size: 14px;
                padding: 10px 40px 10px 14px;
            }
            
            .select-icon {
                right: 12px;
            }
        }
        
        @media print {
            .header-right,
            .session-selector,
            .btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/preloader.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Academic Calendar</h1>
                <p>View important dates and deadlines for your academic session</p>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="academic-results.php" class="btn btn-secondary">
                    <i class="fas fa-chart-line"></i> Results
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Calendar
                </button>
            </div>
        </div>

        <!-- Student Info Banner -->
        <div class="student-banner">
            <div class="student-info-grid">
                <div class="info-item">
                    <div class="info-label">Student ID</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['matric_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars(($student_data['first_name'] ?? '') . ' ' . ($student_data['last_name'] ?? '')); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Program</div>
                    <div class="info-value"><?php echo htmlspecialchars($student_data['program_name'] ?? 'Not Available'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Current Level</div>
                    <div class="info-value">Level <?php echo htmlspecialchars($student_data['current_level'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Current Session Information -->
        <?php if (!empty($current_session)): ?>
        <div class="current-session-card">
            <div class="session-title">
                <i class="fas fa-calendar-check"></i>
                Current Academic Session
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                <div style="padding: 8px 16px; background: rgba(67, 97, 238, 0.1); border-radius: var(--border-radius-sm);">
                    <span style="font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($current_session['session_name'] ?? ''); ?></span>
                </div>
                <div style="font-size: 14px; color: var(--gray);">
                    <i class="far fa-calendar-alt"></i> 
                    <?php 
                    if (!empty($current_session['start_date']) && !empty($current_session['end_date'])) {
                        echo date('F j, Y', strtotime($current_session['start_date'])) . ' - ' . date('F j, Y', strtotime($current_session['end_date']));
                    } else {
                        echo 'Dates not available';
                    }
                    ?>
                </div>
            </div>
            
            <div class="session-info-grid">
                <div class="session-info-item">
                    <div class="session-info-label">Session Duration</div>
                    <div class="session-info-value">
                        <?php 
                        if (!empty($current_session['start_date']) && !empty($current_session['end_date'])) {
                            try {
                                $start = new DateTime($current_session['start_date']);
                                $end = new DateTime($current_session['end_date']);
                                $interval = $start->diff($end);
                                echo $interval->format('%m months, %d days');
                            } catch (Exception $e) {
                                echo 'N/A';
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div class="session-info-note">Total academic period</div>
                </div>
                
                <div class="session-info-item warning">
                    <div class="session-info-label">Registration Deadline</div>
                    <div class="session-info-value">
                        <?php 
                        if (!empty($current_session['registration_end'])) {
                            echo date('F j, Y', strtotime($current_session['registration_end']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div class="session-info-note">Late registration attracts penalties</div>
                </div>
                
                <div class="session-info-item">
                    <div class="session-info-label">Lectures Period</div>
                    <div class="session-info-value">
                        <?php 
                        if (!empty($current_session['lectures_start']) && !empty($current_session['lectures_end'])) {
                            echo date('M j', strtotime($current_session['lectures_start'])) . ' - ' . date('M j, Y', strtotime($current_session['lectures_end']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div class="session-info-note">Regular teaching period</div>
                </div>
                
                <div class="session-info-item danger">
                    <div class="session-info-label">Examinations</div>
                    <div class="session-info-value">
                        <?php 
                        if (!empty($current_session['exams_start']) && !empty($current_session['exams_end'])) {
                            echo date('M j', strtotime($current_session['exams_start'])) . ' - ' . date('M j, Y', strtotime($current_session['exams_end']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div class="session-info-note">Final examinations schedule</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Session Selector -->
        <?php if (!empty($academic_sessions)): ?>
        <div class="session-selector">
            <div class="session-header">
                <h2>Select Academic Session</h2>
                <div class="session-info">
                    <i class="fas fa-info-circle"></i> Choose a session to view calendar
                </div>
            </div>
            
            <form method="GET" action="" class="session-form" id="sessionForm">
                <div class="form-group">
                    <label for="sessionSelect" class="form-label">Academic Session</label>
                    <div class="select-wrapper">
                        <select name="session" id="sessionSelect" class="form-select" required>
                            <option value="">-- Please select a session --</option>
                            <?php 
                            foreach ($academic_sessions as $session): 
                                $session_value = $session['session_year'] . '|' . $session['semester'];
                                $display_text = htmlspecialchars($session['session_year']) . ' - Semester ' . $session['semester'];
                                if (!empty($session['session_name'])) {
                                    $display_text .= ' (' . htmlspecialchars($session['session_name']) . ')';
                                }
                                $is_selected = false;
                                
                                // Check if this session is selected
                                if ($session['session_year'] == $selected_session && $session['semester'] == $selected_semester) {
                                    $is_selected = true;
                                }
                            ?>
                            <option value="<?php echo htmlspecialchars($session_value); ?>" 
                                    <?php echo $is_selected ? 'selected' : ''; ?>>
                                <?php echo $display_text; ?>
                                <?php if ($session['is_current']): ?> (Current) <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="select-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="form-hint">Calendar will load automatically after selection</div>
                </div>
                
                <!-- Optional: Add a submit button for non-JS users -->
                <noscript>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-eye"></i> View Calendar
                    </button>
                </noscript>
            </form>
        </div>
        <?php endif; ?>

        <!-- Important Dates Timeline -->
        <?php if ($selected_session && $selected_semester): ?>
        <div class="timeline-section">
            <div class="section-header">
                <h2>Important Dates & Deadlines</h2>
                <p>Academic schedule for <?php echo htmlspecialchars($selected_session); ?> (Semester <?php echo $selected_semester; ?>)</p>
            </div>

            <?php if (!empty($important_dates)): ?>
            <div class="timeline">
                <?php foreach ($important_dates as $index => $date): 
                    if (empty($date['start_date'])) continue;
                    
                    try {
                        $start_date = new DateTime($date['start_date']);
                        $end_date = new DateTime($date['end_date']);
                        $today = new DateTime();
                        
                        // Determine status
                        if ($today > $end_date) {
                            $status = 'completed';
                            $status_text = 'Completed';
                            $status_class = 'status-completed';
                        } elseif ($today >= $start_date && $today <= $end_date) {
                            $status = 'ongoing';
                            $status_text = 'Ongoing';
                            $status_class = 'status-ongoing';
                        } else {
                            $status = 'upcoming';
                            $status_text = 'Upcoming';
                            $status_class = 'status-upcoming';
                        }
                        
                        // Determine category class
                        $category_class = 'category-academic';
                        if (strpos(strtolower($date['category']), 'registration') !== false) {
                            $category_class = 'category-registration';
                        } elseif (strpos(strtolower($date['category']), 'exam') !== false) {
                            $category_class = 'category-exam';
                        } elseif (strpos(strtolower($date['category']), 'break') !== false) {
                            $category_class = 'category-break';
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                ?>
                <div class="timeline-item <?php echo ($index % 2 == 0) ? 'left' : 'right'; ?>">
                    <div class="timeline-content">
                        <div class="timeline-date">
                            <i class="far fa-calendar"></i>
                            <?php echo $start_date->format('F j, Y'); ?>
                            <?php if ($start_date->format('Y-m-d') != $end_date->format('Y-m-d')): ?>
                            - <?php echo $end_date->format('F j, Y'); ?>
                            <?php endif; ?>
                        </div>
                        <h3 class="timeline-title"><?php echo htmlspecialchars($date['event_name']); ?></h3>
                        <span class="timeline-category <?php echo $category_class; ?>">
                            <?php echo htmlspecialchars($date['category']); ?>
                        </span>
                        <p class="timeline-description"><?php echo htmlspecialchars($date['description']); ?></p>
                        <span class="calendar-status <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 50px 20px; color: var(--gray);">
                <i class="fas fa-calendar-times" style="font-size: 64px; margin-bottom: 20px; color: var(--light-gray);"></i>
                <h3>No Calendar Data Available</h3>
                <p>Academic calendar for this session has not been published yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Key Dates Summary -->
        <?php if (!empty($important_dates)): ?>
        <div class="timeline-section">
            <div class="section-header">
                <h2>Key Dates at a Glance</h2>
                <p>Quick reference for important deadlines</p>
            </div>
            
            <div class="calendar-grid">
                <?php 
                $key_dates = array_filter($important_dates, function($date) {
                    return !empty($date['start_date']);
                });
                
                // Sort by date
                usort($key_dates, function($a, $b) {
                    return strtotime($a['start_date']) - strtotime($b['start_date']);
                });
                
                foreach ($key_dates as $date): 
                    if (empty($date['start_date'])) continue;
                    
                    try {
                        $start_date = new DateTime($date['start_date']);
                        $today = new DateTime();
                        $days_remaining = $today->diff($start_date)->days;
                        
                        $icon = 'fa-calendar-alt';
                        $bg_color = 'rgba(67, 97, 238, 0.1)';
                        
                        if (strpos(strtolower($date['category']), 'registration') !== false) {
                            $icon = 'fa-file-signature';
                            $bg_color = 'rgba(16, 185, 129, 0.1)';
                        } elseif (strpos(strtolower($date['category']), 'exam') !== false) {
                            $icon = 'fa-file-alt';
                            $bg_color = 'rgba(239, 68, 68, 0.1)';
                        } elseif (strpos(strtolower($date['category']), 'break') !== false) {
                            $icon = 'fa-umbrella-beach';
                            $bg_color = 'rgba(245, 158, 11, 0.1)';
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                ?>
                <div class="calendar-card">
                    <div class="calendar-card-header">
                        <div class="calendar-card-title"><?php echo htmlspecialchars($date['event_name']); ?></div>
                        <div class="calendar-card-date">
                            <i class="far <?php echo $icon; ?>"></i>
                            <?php echo $start_date->format('M j, Y'); ?>
                        </div>
                    </div>
                    <div class="calendar-card-body">
                        <div style="margin-bottom: 10px;"><?php echo htmlspecialchars($date['description']); ?></div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <span style="font-size: 12px; color: var(--gray);">
                                <i class="far fa-clock"></i> 
                                <?php if ($today > $start_date): ?>
                                    <?php echo $days_remaining; ?> days ago
                                <?php else: ?>
                                    In <?php echo $days_remaining; ?> days
                                <?php endif; ?>
                            </span>
                            <span style="font-size: 12px; font-weight: 600; color: var(--primary);">
                                <?php echo htmlspecialchars($date['category']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print Calendar
            </button>
            <a href="student-support.php" class="btn btn-secondary">
                <i class="fas fa-question-circle"></i> Get Support
            </a>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Session selector auto-submit
        const sessionSelect = document.getElementById('sessionSelect');
        if (sessionSelect) {
            sessionSelect.addEventListener('change', function() {
                if (this.value) {
                    this.form.submit();
                }
            });
        }
        
        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Hide unnecessary elements when printing
            document.querySelectorAll('.header-right, .session-selector, .btn').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restore elements after printing
            document.querySelectorAll('.header-right, .session-selector, .btn').forEach(el => {
                el.style.display = '';
            });
        });
        
        // Add countdown to upcoming events
        const today = new Date();
        const eventCards = document.querySelectorAll('.calendar-card');
        
        eventCards.forEach(card => {
            const dateText = card.querySelector('.calendar-card-date').textContent;
            const dateMatch = dateText.match(/[A-Za-z]{3} \d{1,2}, \d{4}/);
            
            if (dateMatch) {
                const eventDate = new Date(dateMatch[0]);
                const timeDiff = eventDate.getTime() - today.getTime();
                const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                
                if (daysDiff > 0) {
                    const countdownEl = document.createElement('div');
                    countdownEl.innerHTML = `<div style="margin-top: 10px; padding: 8px; background: rgba(16, 185, 129, 0.1); border-radius: 6px; text-align: center; font-size: 12px; font-weight: 600; color: #10B981;">
                        <i class="fas fa-hourglass-half"></i> ${daysDiff} days remaining
                    </div>`;
                    card.querySelector('.calendar-card-body').appendChild(countdownEl);
                }
            }
        });
    });
    </script>
</body>
</html>