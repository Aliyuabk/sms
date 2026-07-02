<?php
/**
 * 5G E-GURUSCHOOL - Chatbot API
 * Real-time student data detection & response
 * Endpoint: chatbot_api.php
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Response structure
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'suggestions' => []
];

try {
    // Validate request
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['message'])) {
        throw new Exception('No message provided');
    }

    $userMessage = strtolower(trim($input['message']));
    $studentId = isset($input['student_id']) ? intval($input['student_id']) : null;

    // Verify student is logged in
    if (!$studentId || !isset($_SESSION['student_id']) || $_SESSION['student_id'] != $studentId) {
        throw new Exception('Please log in to use the chatbot');
    }

    // Connect to database
    require_once '../config/db.php';

    if (!isset($conn)) {
        throw new Exception('Database connection not available');
    }

    // Process message
    $result = processStudentQuery($conn, $userMessage, $studentId);

    $response['success'] = true;
    $response['message'] = $result['message'];
    $response['data'] = $result['data'] ?? null;
    $response['suggestions'] = $result['suggestions'] ?? [];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);

/**
 * Main query processor - detects intent and fetches real student data
 */
function processStudentQuery($conn, $message, $studentId) {

    // RESULTS / GRADES
    if (containsAny($message, ['result', 'grade', 'score', 'gpa', 'cgpa', 'performance', 'mark', 'exam'])) {
        return getRealResults($conn, $studentId);
    }

    // FEES / PAYMENTS
    if (containsAny($message, ['fee', 'payment', 'balance', 'dues', 'invoice', 'money', 'pay', 'owe', 'debt'])) {
        return getRealFees($conn, $studentId);
    }

    // COURSES
    if (containsAny($message, ['course', 'subject', 'class', 'registered', 'enrolled', 'registered course'])) {
        return getRealCourses($conn, $studentId);
    }

    // PROFILE
    if (containsAny($message, ['profile', 'info', 'about me', 'my details', 'personal', 'who am i', 'my data'])) {
        return getRealProfile($conn, $studentId);
    }

    // HOSTEL
    if (containsAny($message, ['hostel', 'accommodation', 'room', 'bed', 'dormitory', ' lodge'])) {
        return getRealHostel($conn, $studentId);
    }

    // SESSION / CALENDAR
    if (containsAny($message, ['session', 'semester', 'calendar', 'academic year', 'when', 'date', 'registration period', 'exam date'])) {
        return getRealSession($conn);
    }

    // TRANSCRIPT
    if (containsAny($message, ['transcript', 'record', 'academic history', 'statement of result'])) {
        return getRealTranscript($conn, $studentId);
    }

    // NOTIFICATIONS
    if (containsAny($message, ['notification', 'alert', 'message', 'news', 'announcement', 'unread'])) {
        return getRealNotifications($conn, $studentId);
    }

    // ATTENDANCE
    if (containsAny($message, ['attendance', 'present', 'absent', 'class attendance'])) {
        return getRealAttendance($conn, $studentId);
    }

    // MEDICAL
    if (containsAny($message, ['medical', 'health', 'blood', 'allergy', 'genotype', 'hospital'])) {
        return getRealMedical($conn, $studentId);
    }

    // ADVISOR
    if (containsAny($message, ['advisor', 'counselor', 'mentor', 'supervisor'])) {
        return getRealAdvisor($conn, $studentId);
    }

    // PAYMENT / MAKE PAYMENT
    if (containsAny($message, ['make payment', 'pay now', 'how to pay', 'payment method'])) {
        return [
            'message' => 'Here are the available payment methods:',
            'data' => [
                'methods' => [
                    ['name' => 'Bank Transfer', 'details' => 'Transfer to school account'],
                    ['name' => 'Online Payment', 'details' => 'Pay via portal'],
                    ['name' => 'Cash', 'details' => 'Pay at bursary office'],
                    ['name' => 'Bank Draft', 'details' => 'Submit at bursary']
                ]
            ],
            'suggestions' => ['My Fees', 'Payment History', 'Generate Invoice']
        ];
    }

    // TIMETABLE
    if (containsAny($message, ['timetable', 'schedule', 'class time', 'lecture time'])) {
        return [
            'message' => 'Your class timetable for the current semester:',
            'data' => [
                'note' => 'Timetable feature coming soon. Please check your student portal for the full schedule.'
            ],
            'suggestions' => ['My Courses', 'Session Dates', 'Help']
        ];
    }

    // HELP
    if (containsAny($message, ['help', 'command', 'what can you do', 'assist', 'support'])) {
        return getHelpMessage();
    }

    // GREETINGS
    if (containsAny($message, ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening'])) {
        $hour = date('H');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $student = getStudentBasic($conn, $studentId);
        $name = $student ? explode(' ', $student['first_name'])[0] : 'there';

        return [
            'message' => "{$greeting}, {$name}! 👋\n\nI'm your E-Guru Assistant. I can help you with results, fees, courses, and more. What would you like to know?",
            'suggestions' => ['My Results', 'My Fees', 'My Courses', 'Profile', 'Help']
        ];
    }

    // THANK YOU
    if (containsAny($message, ['thank', 'thanks', 'appreciate', 'grateful'])) {
        return [
            'message' => "You're welcome! 😊\n\nI'm always here to help. Feel free to ask if you need anything else.",
            'suggestions' => ['My Results', 'My Fees', 'Help']
        ];
    }

    // DEFAULT / UNKNOWN
    return [
        'message' => "I'm not sure I understood that. 🤔\n\nHere are some things I can help you with:",
        'suggestions' => ['My Results', 'My Fees', 'My Courses', 'Profile', 'Notifications', 'Help']
    ];
}

/* ============================================
   REAL DATA FETCHERS - Connects to your DB
   ============================================ */

function getRealResults($conn, $studentId) {
    try {
        // Get current session
        $sessionRes = $conn->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
        $session = $sessionRes->fetch_assoc() ?: ['session_year' => '2025/2026', 'semester' => 1];

        // Get published results with course details
        $stmt = $conn->prepare("
            SELECT r.course_id, c.course_code, c.course_title, c.credit_units,
                   r.ca_score, r.exam_score, r.total_score, r.grade, r.grade_points, r.grade_remark
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ? AND r.session_year = ? AND r.semester = ? AND r.is_published = 1
            ORDER BY c.course_code
        ");
        $stmt->bind_param("isi", $studentId, $session['session_year'], $session['semester']);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get academic record
        $stmt = $conn->prepare("
            SELECT gpa, total_units, total_points 
            FROM academic_records 
            WHERE student_id = ? AND session_year = ? AND semester = ?
        ");
        $stmt->bind_param("isi", $studentId, $session['session_year'], $session['semester']);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get student CGPA
        $stmt = $conn->prepare("SELECT cgpa, current_level FROM students WHERE student_id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (empty($courses)) {
            return [
                'message' => "No published results found for {$session['session_year']} " . ($session['semester'] == 1 ? 'First' : 'Second') . " Semester.",
                'suggestions' => ['My Courses', 'Session Info', 'Help']
            ];
        }

        return [
            'message' => "Here are your published results for {$session['session_year']} " . ($session['semester'] == 1 ? 'First' : 'Second') . " Semester:",
            'data' => [
                'session' => $session['session_year'],
                'semester' => $session['semester'] == 1 ? 'First' : 'Second',
                'level' => $student['current_level'] ?? 'N/A',
                'gpa' => $record['gpa'] ?? 'N/A',
                'cgpa' => $student['cgpa'] ?? 'N/A',
                'total_units' => $record['total_units'] ?? 0,
                'courses' => $courses
            ],
            'suggestions' => ['Previous Results', 'Transcript', 'My Courses']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch results. Please try again.', 'suggestions' => ['Help']];
    }
}

function getRealFees($conn, $studentId) {
    try {
        $sessionRes = $conn->query("SELECT session_year FROM academic_sessions WHERE is_current = 1 LIMIT 1");
        $session = $sessionRes->fetch_assoc();
        $sessionYear = $session['session_year'] ?? '2025/2026';

        // Get fee summary
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_invoices,
                SUM(amount) as total_fees,
                SUM(amount_paid) as total_paid,
                SUM(balance) as total_balance,
                GROUP_CONCAT(DISTINCT fee_type) as fee_types
            FROM student_fees 
            WHERE student_id = ? AND session_year = ?
        ");
        $stmt->bind_param("is", $studentId, $sessionYear);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get detailed fees
        $stmt = $conn->prepare("
            SELECT fee_type, amount, amount_paid, balance, status, due_date
            FROM student_fees 
            WHERE student_id = ? AND session_year = ?
            ORDER BY due_date
        ");
        $stmt->bind_param("is", $studentId, $sessionYear);
        $stmt->execute();
        $fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $balance = floatval($summary['total_balance'] ?? 0);

        return [
            'message' => "Your fee summary for {$sessionYear}:",
            'data' => [
                'session' => $sessionYear,
                'total_fees' => floatval($summary['total_fees'] ?? 0),
                'total_paid' => floatval($summary['total_paid'] ?? 0),
                'total_balance' => $balance,
                'status' => $balance > 0 ? 'Pending' : 'Paid',
                'fees' => $fees
            ],
            'suggestions' => ['Make Payment', 'Payment History', 'Fee Structure']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch fee information.', 'suggestions' => ['Help']];
    }
}

function getRealCourses($conn, $studentId) {
    try {
        $sessionRes = $conn->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
        $session = $sessionRes->fetch_assoc() ?: ['session_year' => '2025/2026', 'semester' => 1];

        $stmt = $conn->prepare("
            SELECT cr.course_id, c.course_code, c.course_title, c.credit_units, 
                   cr.registration_status, cr.grade, cr.score
            FROM course_registrations cr
            JOIN courses c ON cr.course_id = c.course_id
            WHERE cr.student_id = ? AND cr.session_year = ? AND cr.semester = ?
            ORDER BY c.course_code
        ");
        $stmt->bind_param("isi", $studentId, $session['session_year'], $session['semester']);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totalUnits = array_sum(array_column($courses, 'credit_units'));

        return [
            'message' => "Your registered courses for {$session['session_year']} " . ($session['semester'] == 1 ? 'First' : 'Second') . " Semester:",
            'data' => [
                'session' => $session['session_year'],
                'semester' => $session['semester'] == 1 ? 'First' : 'Second',
                'total_courses' => count($courses),
                'total_units' => $totalUnits,
                'courses' => $courses
            ],
            'suggestions' => ['My Results', 'Timetable', 'Session Info']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch course information.', 'suggestions' => ['Help']];
    }
}

function getRealProfile($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT s.student_id, s.matric_number, s.first_name, s.last_name, 
                   s.email, s.phone, s.date_of_birth, s.gender, s.nationality,
                   s.state_of_origin, s.lga, s.address, s.current_level, s.cgpa,
                   s.status, s.admission_year, s.mode_of_entry, s.current_session,
                   d.department_name, p.program_name, p.duration_years, p.degree_type
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$profile) {
            return ['message' => 'Student profile not found.', 'suggestions' => ['Help']];
        }

        return [
            'message' => 'Your student profile:',
            'data' => [
                'name' => $profile['first_name'] . ' ' . $profile['last_name'],
                'matric_number' => $profile['matric_number'],
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'department' => $profile['department_name'],
                'program' => $profile['program_name'],
                'level' => $profile['current_level'],
                'cgpa' => $profile['cgpa'],
                'admission_year' => $profile['admission_year'],
                'status' => $profile['status'],
                'gender' => $profile['gender'],
                'nationality' => $profile['nationality'],
                'state' => $profile['state_of_origin']
            ],
            'suggestions' => ['My Results', 'My Fees', 'Edit Profile']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch profile.', 'suggestions' => ['Help']];
    }
}

function getRealHostel($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT ha.*, h.hostel_name, h.hostel_code, hr.room_number, hr.room_type, h.monthly_rent
            FROM hostel_allocations ha
            JOIN hostels h ON ha.hostel_id = h.hostel_id
            JOIN hostel_rooms hr ON ha.room_id = hr.room_id
            WHERE ha.student_id = ? AND ha.status = 'Active'
            ORDER BY ha.allocation_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $allocation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($allocation) {
            return [
                'message' => 'Your current hostel allocation:',
                'data' => [
                    'status' => 'Allocated',
                    'hostel' => $allocation['hostel_name'],
                    'room' => $allocation['room_number'],
                    'bed' => $allocation['bed_number'],
                    'room_type' => $allocation['room_type'],
                    'rent' => $allocation['monthly_rent'],
                    'payment_status' => $allocation['payment_status'],
                    'start_date' => $allocation['start_date'],
                    'end_date' => $allocation['end_date']
                ],
                'suggestions' => ['Hostel Fees', 'Room Details', 'Check Out']
            ];
        } else {
            return [
                'message' => 'You currently have no active hostel allocation.',
                'data' => ['status' => 'Not Allocated'],
                'suggestions' => ['Apply for Hostel', 'Hostel Fees', 'Available Rooms']
            ];
        }

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch hostel information.', 'suggestions' => ['Help']];
    }
}

function getRealSession($conn) {
    try {
        $result = $conn->query("
            SELECT * FROM academic_sessions 
            WHERE status = 'Active' 
            ORDER BY is_current DESC, session_year DESC, semester ASC
            LIMIT 2
        ");
        $sessions = $result->fetch_all(MYSQLI_ASSOC);

        return [
            'message' => 'Current academic sessions:',
            'data' => ['sessions' => $sessions],
            'suggestions' => ['Registration Dates', 'Exam Dates', 'My Courses']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch session information.', 'suggestions' => ['Help']];
    }
}

function getRealTranscript($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM transcripts 
            WHERE student_id = ? 
            ORDER BY request_date DESC 
            LIMIT 3
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $transcripts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $msg = count($transcripts) > 0 
            ? "Your recent transcript requests:" 
            : "You have no transcript requests. Would you like to request one?";

        return [
            'message' => $msg,
            'data' => ['transcripts' => $transcripts],
            'suggestions' => ['Request Transcript', 'Download', 'View All']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch transcript information.', 'suggestions' => ['Help']];
    }
}

function getRealNotifications($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE student_id = ? 
            ORDER BY sent_date DESC 
            LIMIT 5
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Count unread
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE student_id = ? AND is_read = 0");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $unread = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
        $stmt->close();

        $msg = $unread > 0 
            ? "You have {$unread} unread notification(s):" 
            : "Your recent notifications:";

        return [
            'message' => $msg,
            'data' => [
                'unread_count' => $unread,
                'notifications' => $notifications
            ],
            'suggestions' => ['Mark All Read', 'View All', 'Settings']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch notifications.', 'suggestions' => ['Help']];
    }
}

function getRealAttendance($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT a.*, c.course_code, c.course_title
            FROM attendance a
            JOIN courses c ON a.course_id = c.course_id
            WHERE a.student_id = ?
            ORDER BY a.class_date DESC
            LIMIT 10
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $total = count($attendance);
        $present = count(array_filter($attendance, fn($a) => $a['status'] === 'Present'));
        $percentage = $total > 0 ? round(($present / $total) * 100, 1) : 0;

        return [
            'message' => 'Your attendance summary:',
            'data' => [
                'total_classes' => $total,
                'present' => $present,
                'absent' => $total - $present,
                'percentage' => $percentage,
                'records' => $attendance
            ],
            'suggestions' => ['Detailed Report', 'Course-wise', 'My Courses']
        ];

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch attendance information.', 'suggestions' => ['Help']];
    }
}

function getRealMedical($conn, $studentId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM medical_records WHERE student_id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $medical = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($medical) {
            return [
                'message' => 'Your medical records:',
                'data' => [
                    'blood_group' => $medical['blood_group'],
                    'genotype' => $medical['genotype'],
                    'allergies' => $medical['allergies'],
                    'conditions' => $medical['conditions'],
                    'disability' => $medical['disability'],
                    'emergency_contact' => $medical['emergency_contact'],
                    'emergency_name' => $medical['emergency_name']
                ],
                'suggestions' => ['Update Medical', 'Emergency Contact', 'Profile']
            ];
        } else {
            return [
                'message' => 'No medical records found. Please update your medical information.',
                'suggestions' => ['Update Medical', 'Profile', 'Help']
            ];
        }

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch medical records.', 'suggestions' => ['Help']];
    }
}

function getRealAdvisor($conn, $studentId) {
    try {
        $stmt = $conn->prepare("
            SELECT sa.*, aa.first_name, aa.last_name, aa.email, aa.phone, aa.max_students
            FROM student_advisors sa
            JOIN academic_advisors aa ON sa.advisor_id = aa.advisor_id
            WHERE sa.student_id = ? AND sa.status = 'Active'
            ORDER BY sa.assigned_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $advisor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($advisor) {
            return [
                'message' => 'Your academic advisor:',
                'data' => [
                    'name' => $advisor['first_name'] . ' ' . $advisor['last_name'],
                    'email' => $advisor['email'],
                    'phone' => $advisor['phone'],
                    'assigned_date' => $advisor['assigned_date'],
                    'status' => $advisor['status']
                ],
                'suggestions' => ['Schedule Meeting', 'Contact Advisor', 'Help']
            ];
        } else {
            return [
                'message' => 'No academic advisor assigned yet.',
                'suggestions' => ['Request Advisor', 'Help']
            ];
        }

    } catch (Exception $e) {
        return ['message' => 'Unable to fetch advisor information.', 'suggestions' => ['Help']];
    }
}

function getStudentBasic($conn, $studentId) {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

function getHelpMessage() {
    return [
        'message' => "Here are the things I can help you with:",
        'data' => [
            'commands' => [
                ['icon' => '📊', 'name' => 'Results', 'desc' => 'View grades, GPA & CGPA'],
                ['icon' => '💰', 'name' => 'Fees', 'desc' => 'Check balance & payments'],
                ['icon' => '📚', 'name' => 'Courses', 'desc' => 'Registered courses & units'],
                ['icon' => '👤', 'name' => 'Profile', 'desc' => 'Personal information'],
                ['icon' => '🏠', 'name' => 'Hostel', 'desc' => 'Room allocation'],
                ['icon' => '📅', 'name' => 'Session', 'desc' => 'Academic calendar'],
                ['icon' => '📄', 'name' => 'Transcript', 'desc' => 'Request transcripts'],
                ['icon' => '🔔', 'name' => 'Notifications', 'desc' => 'Alerts & messages'],
                ['icon' => '📋', 'name' => 'Attendance', 'desc' => 'Class attendance'],
                ['icon' => '🏥', 'name' => 'Medical', 'desc' => 'Health records'],
                ['icon' => '👨‍🏫', 'name' => 'Advisor', 'desc' => 'Academic advisor']
            ]
        ],
        'suggestions' => ['My Results', 'My Fees', 'My Courses']
    ];
}

function containsAny($message, $keywords) {
    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) {
            return true;
        }
    }
    return false;
}