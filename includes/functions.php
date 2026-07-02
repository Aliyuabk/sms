<?php
// Get current academic session
function getCurrentSession() {
    global $pdo;
    
    try {
        $sql = "SELECT * FROM academic_sessions WHERE is_current = 1 AND status = 'Active' LIMIT 1";
        $stmt = $pdo->query($sql);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            // Fallback to most recent session
            $sql = "SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1";
            $stmt = $pdo->query($sql);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $session ?: ['session_id' => 0, 'session_name' => 'Not Available'];
    } catch (Exception $e) {
        error_log("Error getting current session: " . $e->getMessage());
        return ['session_id' => 0, 'session_name' => 'Not Available'];
    }
}

// Get current semester
function getCurrentSemester() {
    global $pdo;
    
    try {
        $sql = "SELECT s.* 
                FROM semesters s
                JOIN academic_sessions a ON s.session_id = a.session_id
                WHERE s.is_current = 1 AND s.status = 'Active' 
                ORDER BY s.start_date DESC LIMIT 1";
        $stmt = $pdo->query($sql);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$semester) {
            $currentSession = getCurrentSession();
            $sql = "SELECT * FROM semesters 
                    WHERE session_id = ? 
                    ORDER BY semester_id DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$currentSession['session_id']]);
            $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $semester ?: ['semester_id' => 0, 'semester_name' => 'Not Available'];
    } catch (Exception $e) {
        error_log("Error getting current semester: " . $e->getMessage());
        return ['semester_id' => 0, 'semester_name' => 'Not Available'];
    }
}

// Get recent announcements for student
function getRecentAnnouncements($student) {
    global $pdo;
    
    try {
        $params = [':publish_date' => date('Y-m-d H:i:s', strtotime('-30 days'))];
        
        $sql = "SELECT a.* 
                FROM announcements a
                WHERE a.is_published = 1 
                AND a.publish_date >= :publish_date
                AND (
                    a.target_audience = 'All' 
                    OR a.target_audience = 'Students'
                    OR (a.target_audience = 'Specific Department' AND a.target_department_id = :dept_id)
                    OR (a.target_audience = 'Specific Level' AND a.target_level_id = :level_id)
                )
                ORDER BY a.publish_date DESC 
                LIMIT 5";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':publish_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
            ':dept_id' => $student['department_id'] ?? 0,
            ':level_id' => $student['level_id'] ?? 0
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting announcements: " . $e->getMessage());
        return [];
    }
}

// Get upcoming deadlines
function getUpcomingDeadlines($student_id, $session_id) {
    global $pdo;
    
    $deadlines = [];
    $today = date('Y-m-d');
    $nextMonth = date('Y-m-d', strtotime('+30 days'));
    
    try {
        // Course registration deadlines
        $sql = "SELECT csr.registration_start, csr.registration_end 
                FROM course_registration_schedule csr
                WHERE csr.session_id = ? 
                AND csr.registration_end >= ? 
                AND csr.registration_end <= ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$session_id, $today, $nextMonth]);
        $registrationDeadline = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($registrationDeadline) {
            $days = ceil((strtotime($registrationDeadline['registration_end']) - strtotime($today)) / (60 * 60 * 24));
            $deadlines[] = [
                'title' => 'Course Registration',
                'due_date' => $registrationDeadline['registration_end'],
                'days_remaining' => $days,
                'urgency' => $days <= 7 ? 'danger' : ($days <= 14 ? 'warning' : 'info')
            ];
        }
        
        // Fee payment deadlines (from invoices)
        $sql = "SELECT due_date, invoice_number 
                FROM student_invoices 
                WHERE student_id = ? 
                AND session_id = ?
                AND due_date >= ? 
                AND due_date <= ?
                AND payment_status IN ('Pending', 'Partial')
                ORDER BY due_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $session_id, $today, $nextMonth]);
        $feeDeadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($feeDeadlines as $deadline) {
            $days = ceil((strtotime($deadline['due_date']) - strtotime($today)) / (60 * 60 * 24));
            $deadlines[] = [
                'title' => 'Fee Payment - Invoice ' . $deadline['invoice_number'],
                'due_date' => $deadline['due_date'],
                'days_remaining' => $days,
                'urgency' => $days <= 3 ? 'danger' : ($days <= 7 ? 'warning' : 'info')
            ];
        }
        
        // Sort by due date
        usort($deadlines, function($a, $b) {
            return strtotime($a['due_date']) - strtotime($b['due_date']);
        });
        
        return array_slice($deadlines, 0, 5); // Return top 5
    } catch (Exception $e) {
        error_log("Error getting deadlines: " . $e->getMessage());
        return [];
    }
}

// Get recent results
function getRecentResults($student_id) {
    global $pdo;
    
    try {
        $currentSession = getCurrentSession();
        
        $sql = "SELECT 
                    COUNT(r.result_id) as course_count,
                    AVG(r.grade_point) as avg_gpa,
                    MAX(r.upload_date) as latest_update
                FROM results r
                WHERE r.student_id = ? 
                AND r.session_id = ?
                AND r.approval_status = 'Approved'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $currentSession['session_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get individual course results for current semester
        $currentSemester = getCurrentSemester();
        
        $sql = "SELECT c.course_code, c.course_title, r.score, r.grade, r.grade_point
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? 
                AND r.semester_id = ?
                AND r.session_id = ?
                AND r.approval_status = 'Approved'
                ORDER BY r.upload_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $currentSemester['semester_id'], $currentSession['session_id']]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate CGPA if available
        $sql = "SELECT 
                    SUM(c.credit_units * r.grade_point) / SUM(c.credit_units) as cgpa
                FROM results r
                JOIN courses c ON r.course_id = c.course_id
                WHERE r.student_id = ? 
                AND r.approval_status = 'Approved'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id]);
        $cgpaData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'course_count' => $result['course_count'] ?? 0,
            'current_gpa' => $result['avg_gpa'] ? number_format($result['avg_gpa'], 2) : 'N/A',
            'cgpa' => $cgpaData['cgpa'] ? number_format($cgpaData['cgpa'], 2) : 'N/A',
            'latest_update' => $result['latest_update'] ?? null,
            'courses' => $courses
        ];
    } catch (Exception $e) {
        error_log("Error getting results: " . $e->getMessage());
        return [
            'course_count' => 0,
            'current_gpa' => 'N/A',
            'cgpa' => 'N/A',
            'latest_update' => null,
            'courses' => []
        ];
    }
}

// Get fee summary
function getFeeSummary($student_id, $session_id) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    COUNT(*) as invoice_count,
                    SUM(total_amount) as total_billed,
                    SUM(amount_paid) as total_paid,
                    SUM(balance) as total_balance,
                    MAX(due_date) as next_due_date
                FROM student_invoices 
                WHERE student_id = ? 
                AND session_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $session_id]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($summary && $summary['total_billed'] > 0) {
            $payment_percentage = round(($summary['total_paid'] / $summary['total_billed']) * 100, 2);
            
            // Get recent payment
            $sql = "SELECT amount, transaction_date 
                    FROM payments 
                    WHERE student_id = ? 
                    AND verification_status = 'Verified'
                    ORDER BY transaction_date DESC 
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$student_id]);
            $recentPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'invoice_count' => $summary['invoice_count'],
                'total_billed' => $summary['total_billed'] ?? 0,
                'total_paid' => $summary['total_paid'] ?? 0,
                'total_balance' => $summary['total_balance'] ?? 0,
                'balance' => $summary['total_balance'] ?? 0,
                'payment_percentage' => $payment_percentage,
                'next_due_date' => $summary['next_due_date'] ?? null,
                'recent_payment' => $recentPayment['transaction_date'] ?? null,
                'recent_payment_amount' => $recentPayment['amount'] ?? 0
            ];
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error getting fee summary: " . $e->getMessage());
        return null;
    }
}

// Get course registration status
function getCourseRegistrationStatus($student_id, $semester_id) {
    global $pdo;
    
    try {
        $currentSession = getCurrentSession();
        
        // Check if registration is open
        $sql = "SELECT * FROM course_registration_schedule 
                WHERE semester_id = ? 
                AND registration_start <= NOW() 
                AND registration_end >= NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$semester_id]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule) {
            return null;
        }
        
        // Get student's registered courses for this semester
        $sql = "SELECT 
                    COUNT(cr.registration_id) as registered_count,
                    cr.approval_status
                FROM course_registrations cr
                WHERE cr.student_id = ? 
                AND cr.semester_id = ? 
                AND cr.session_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $semester_id, $currentSession['session_id']]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get required courses for student's level
        $sql = "SELECT COUNT(*) as required_count
                FROM courses c
                JOIN student_programs sp ON c.level_id = sp.level_id 
                    AND c.department_id = (SELECT department_id FROM programs WHERE program_id = sp.program_id)
                WHERE sp.student_id = ? 
                AND c.semester_id = ?
                AND c.is_active = 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $semester_id]);
        $required = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $registered = $registration['registered_count'] ?? 0;
        $required = $required['required_count'] ?? 0;
        
        if ($registered > 0) {
            $completion_percentage = $required > 0 ? round(($registered / $required) * 100) : 0;
            
            $status_map = [
                'Pending' => ['text' => 'Pending Approval', 'class' => 'warning', 'icon' => 'fa-clock'],
                'Approved by Level Coordinator' => ['text' => 'Partially Approved', 'class' => 'info', 'icon' => 'fa-check-circle'],
                'Approved by HOD' => ['text' => 'Fully Approved', 'class' => 'success', 'icon' => 'fa-check-circle'],
                'Rejected' => ['text' => 'Registration Rejected', 'class' => 'danger', 'icon' => 'fa-times-circle']
            ];
            
            $status = $status_map[$registration['approval_status'] ?? 'Pending'];
            
            return [
                'registered_count' => $registered,
                'required_count' => $required,
                'completion_percentage' => $completion_percentage,
                'status_text' => $status['text'],
                'status_class' => $status['class'],
                'status_icon' => $status['icon'],
                'approval_status' => $registration['approval_status'] ?? 'Pending',
                'message' => "{$registered} of {$required} courses registered",
                'can_register' => $registered < $required,
                'is_pending' => ($registration['approval_status'] ?? 'Pending') === 'Pending'
            ];
        } else {
            return [
                'registered_count' => 0,
                'required_count' => $required,
                'completion_percentage' => 0,
                'status_text' => 'Not Registered',
                'status_class' => 'danger',
                'status_icon' => 'fa-exclamation-triangle',
                'approval_status' => 'Not Started',
                'message' => 'No courses registered for this semester',
                'can_register' => true,
                'is_pending' => false
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting registration status: " . $e->getMessage());
        return null;
    }
}

// Get attendance summary
function getAttendanceSummary($student_id, $semester_id) {
    global $pdo;
    
    try {
        $currentSession = getCurrentSession();
        
        $sql = "SELECT 
                    c.course_code,
                    c.course_title,
                    COUNT(a.attendance_id) as total_classes,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as attended_classes,
                    ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)), 2) as attendance_percentage
                FROM attendance a
                JOIN courses c ON a.course_id = c.course_id
                WHERE a.student_id = ? 
                AND a.semester_id = ?
                AND a.session_id = ?
                GROUP BY a.course_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $semester_id, $currentSession['session_id']]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate overall attendance
        $total_classes = 0;
        $attended_classes = 0;
        
        foreach ($attendance as $record) {
            $total_classes += $record['total_classes'];
            $attended_classes += $record['attended_classes'];
        }
        
        $overall_percentage = $total_classes > 0 ? round(($attended_classes / $total_classes) * 100, 2) : 0;
        
        return [
            'records' => $attendance,
            'total_classes' => $total_classes,
            'attended_classes' => $attended_classes,
            'overall_percentage' => $overall_percentage,
            'status' => $overall_percentage >= 75 ? 'Good' : ($overall_percentage >= 50 ? 'Warning' : 'Danger')
        ];
    } catch (Exception $e) {
        error_log("Error getting attendance: " . $e->getMessage());
        return [
            'records' => [],
            'total_classes' => 0,
            'attended_classes' => 0,
            'overall_percentage' => 0,
            'status' => 'No Data'
        ];
    }
}

// Get announcement color based on type
function getAnnouncementColor($type) {
    $colors = [
        'General' => '#3498db',
        'Academic' => '#2ecc71',
        'Financial' => '#e74c3c',
        'Hostel' => '#9b59b6',
        'Emergency' => '#f39c12'
    ];
    
    return $colors[$type] ?? '#95a5a6';
}

// Check student authorization
function checkStudentAuth() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
        header('Location: ../login.php');
        exit();
    }
    
    // Verify session integrity
    if (!isset($_SESSION['student_id']) || !isset($_SESSION['matric_number'])) {
        // Try to get student info from database
        global $pdo;
        
        try {
            $sql = "SELECT s.student_id, s.matric_number 
                    FROM students s 
                    JOIN users u ON s.user_id = u.user_id 
                    WHERE u.user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id']]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                $_SESSION['student_id'] = $student['student_id'];
                $_SESSION['matric_number'] = $student['matric_number'];
            } else {
                // Invalid session
                session_destroy();
                header('Location: ../login.php');
                exit();
            }
        } catch (Exception $e) {
            error_log("Auth error: " . $e->getMessage());
            session_destroy();
            header('Location: ../login.php');
            exit();
        }
    }
}
?>