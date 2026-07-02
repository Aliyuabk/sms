-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 02, 2026 at 01:27 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `student_portal_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_advisors`
--

CREATE TABLE `academic_advisors` (
  `advisor_id` int(11) NOT NULL,
  `staff_id` varchar(20) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `max_students` int(11) DEFAULT 20,
  `current_students` int(11) DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `academic_records`
--

CREATE TABLE `academic_records` (
  `record_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `session_year` varchar(20) NOT NULL,
  `semester` tinyint(4) NOT NULL,
  `level` int(11) NOT NULL,
  `total_units` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_points` decimal(8,2) NOT NULL DEFAULT 0.00,
  `gpa` decimal(4,2) NOT NULL DEFAULT 0.00,
  `calculated_by` int(11) DEFAULT NULL,
  `calculated_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `academic_sessions`
--

CREATE TABLE `academic_sessions` (
  `session_id` int(11) NOT NULL,
  `session_year` varchar(15) NOT NULL,
  `semester` int(11) DEFAULT NULL CHECK (`semester` in (1,2)),
  `session_name` varchar(50) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `registration_start` date DEFAULT NULL,
  `registration_end` date DEFAULT NULL,
  `add_drop_start` date DEFAULT NULL,
  `add_drop_end` date DEFAULT NULL,
  `lectures_start` date DEFAULT NULL,
  `lectures_end` date DEFAULT NULL,
  `exams_start` date DEFAULT NULL,
  `exams_end` date DEFAULT NULL,
  `break_start` date DEFAULT NULL,
  `break_end` date DEFAULT NULL,
  `results_deadline` date DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `status` enum('Planning','Active','Completed','Archived') DEFAULT 'Planning'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `log_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` text DEFAULT NULL COMMENT 'JSON encoded old data',
  `new_data` text DEFAULT NULL COMMENT 'JSON encoded new data',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `session_id` varchar(128) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` text NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `role` enum('Super Admin','Admin','Registrar','Bursar','Academic','Hostel') NOT NULL DEFAULT 'Admin',
  `department_id` int(11) DEFAULT NULL,
  `permissions` text DEFAULT NULL COMMENT 'JSON encoded permissions',
  `last_login` timestamp NULL DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(32) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended','Pending') DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`admin_id`, `username`, `email`, `password_hash`, `full_name`, `phone`, `profile_image`, `role`, `department_id`, `permissions`, `last_login`, `last_ip`, `failed_attempts`, `locked_until`, `two_factor_enabled`, `two_factor_secret`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'aliyuabk', 'aliyuabubakar11117@gmail.com', '$2y$10$hx76IM8FtQwoMrKEii/8DuBWRlsdy9WppgFxmLsfZ22ki6xuTB0ca', 'Aliyu Abubakar', '08011112222', 'admin_1_1777670728.jpg', 'Super Admin', NULL, '{\"all\":true}', '2026-05-01 23:02:36', '::1', 0, NULL, 0, NULL, 'Active', NULL, '2026-02-14 12:15:18', '2026-05-01 23:02:36');

-- --------------------------------------------------------

--
-- Table structure for table `advisor_meetings`
--

CREATE TABLE `advisor_meetings` (
  `meeting_id` int(11) NOT NULL,
  `advisor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time DEFAULT NULL,
  `duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes',
  `meeting_type` enum('In Person','Online','Phone') DEFAULT 'In Person',
  `agenda` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `follow_up_needed` tinyint(1) DEFAULT 0,
  `follow_up_date` date DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled','Rescheduled') DEFAULT 'Scheduled',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `session_year` varchar(10) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `class_date` date DEFAULT NULL,
  `status` enum('Present','Absent','Late','Excused') DEFAULT NULL,
  `hours_attended` decimal(4,2) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `bed_availability`
-- (See below for the actual view)
--

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `course_id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_title` varchar(150) NOT NULL,
  `credit_units` int(11) DEFAULT 3,
  `department_id` int(11) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL CHECK (`semester` in (1,2)),
  `prerequisite_course_id` int(11) DEFAULT NULL,
  `is_core` tinyint(1) DEFAULT 1,
  `is_elective` tinyint(1) DEFAULT 0,
  `elective_type` enum('University','Faculty','Department') DEFAULT NULL,
  `course_description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_date` date DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_programs`
--

CREATE TABLE `course_programs` (
  `cp_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_registrations`
--

CREATE TABLE `course_registrations` (
  `registration_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `session_year` varchar(10) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `registration_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `grade` varchar(2) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `grade_points` decimal(3,2) DEFAULT NULL,
  `attendance_percentage` decimal(5,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_code` varchar(10) NOT NULL,
  `faculty` varchar(100) DEFAULT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `department_name` varchar(100) NOT NULL,
  `hod_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_date` date DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_queue`
--

CREATE TABLE `email_queue` (
  `queue_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `recipient_name` varchar(100) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `scheduled_time` timestamp NULL DEFAULT current_timestamp(),
  `sent_time` timestamp NULL DEFAULT NULL,
  `status` enum('Pending','Sent','Failed','Cancelled') DEFAULT 'Pending',
  `retry_count` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `attachments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `template_id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` enum('Academic','Financial','Hostel','General','Urgent','Reminder','Welcome') DEFAULT 'General',
  `subject` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `variables` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculties`
--

CREATE TABLE `faculties` (
  `faculty_id` int(11) NOT NULL,
  `faculty_code` varchar(10) NOT NULL,
  `faculty_name` varchar(100) NOT NULL,
  `dean_name` varchar(100) DEFAULT NULL,
  `dean_email` varchar(100) DEFAULT NULL,
  `dean_phone` varchar(20) DEFAULT NULL,
  `office_location` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Active','Inactive','Merged') DEFAULT 'Active',
  `description` varchar(225) NOT NULL,
  `established_year` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_structure`
--

CREATE TABLE `fee_structure` (
  `fee_structure_id` int(11) NOT NULL,
  `session_year` varchar(10) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `fee_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `applicable_to` enum('All','New Students','Returning Students','Final Year') DEFAULT NULL,
  `created_date` date DEFAULT curdate()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_entries`
--

CREATE TABLE `grade_entries` (
  `entry_id` int(11) NOT NULL,
  `scale_id` int(11) NOT NULL,
  `grade_symbol` varchar(10) NOT NULL,
  `grade_name` varchar(50) DEFAULT NULL,
  `grade_point` decimal(3,2) NOT NULL,
  `lower_bound` decimal(5,2) DEFAULT NULL,
  `upper_bound` decimal(5,2) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_scale`
--

CREATE TABLE `grade_scale` (
  `grade_id` int(11) NOT NULL,
  `grade` varchar(2) NOT NULL,
  `min_score` int(11) NOT NULL,
  `max_score` int(11) NOT NULL,
  `grade_points` decimal(3,2) NOT NULL,
  `remark` varchar(50) DEFAULT NULL,
  `is_pass` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_scales`
--

CREATE TABLE `grade_scales` (
  `scale_id` int(11) NOT NULL,
  `scale_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hostels`
--

CREATE TABLE `hostels` (
  `hostel_id` int(11) NOT NULL,
  `hostel_name` varchar(100) NOT NULL,
  `hostel_code` varchar(10) NOT NULL,
  `gender` enum('Male','Female','Mixed') NOT NULL,
  `total_rooms` int(11) DEFAULT 0,
  `capacity_per_room` int(11) DEFAULT 4,
  `occupied_beds` int(11) DEFAULT 0,
  `available_beds` int(11) DEFAULT 0,
  `warden_name` varchar(100) DEFAULT NULL,
  `warden_phone` varchar(20) DEFAULT NULL,
  `warden_email` varchar(100) DEFAULT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `amenities` text DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `status` enum('Available','Full','Under Maintenance','Closed') DEFAULT 'Available',
  `created_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hostel_allocations`
--

CREATE TABLE `hostel_allocations` (
  `allocation_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `hostel_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `bed_number` int(11) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_status` enum('Paid','Pending','Partial','Overdue') DEFAULT 'Pending',
  `status` enum('Active','Checked Out','Cancelled','Pending') DEFAULT 'Active',
  `allocation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `hostel_allocations`
--
DELIMITER $$
CREATE TRIGGER `after_allocation_insert` AFTER INSERT ON `hostel_allocations` FOR EACH ROW BEGIN
    IF NEW.status = 'Active' THEN
        UPDATE hostels 
        SET occupied_beds = (
            SELECT COUNT(*) 
            FROM hostel_allocations ha 
            JOIN hostel_rooms hr ON ha.room_id = hr.room_id
            WHERE hr.hostel_id = NEW.hostel_id AND ha.status = 'Active'
        ),
        available_beds = (
            SELECT SUM(hr.bed_count) - COUNT(ha.allocation_id)
            FROM hostel_rooms hr
            LEFT JOIN hostel_allocations ha ON hr.room_id = ha.room_id AND ha.status = 'Active'
            WHERE hr.hostel_id = NEW.hostel_id
        )
        WHERE hostel_id = NEW.hostel_id;
        
        UPDATE hostel_rooms 
        SET status = 'Occupied'
        WHERE room_id = NEW.room_id 
          AND status = 'Available';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_allocation_update` AFTER UPDATE ON `hostel_allocations` FOR EACH ROW BEGIN
    UPDATE hostels 
    SET occupied_beds = (
        SELECT COUNT(*) 
        FROM hostel_allocations ha 
        JOIN hostel_rooms hr ON ha.room_id = hr.room_id
        WHERE hr.hostel_id = NEW.hostel_id AND ha.status = 'Active'
    ),
    available_beds = (
        SELECT SUM(hr.bed_count) - COUNT(ha.allocation_id)
        FROM hostel_rooms hr
        LEFT JOIN hostel_allocations ha ON hr.room_id = ha.room_id AND ha.status = 'Active'
        WHERE hr.hostel_id = NEW.hostel_id
    )
    WHERE hostel_id = NEW.hostel_id;
    
    UPDATE hostel_rooms hr
    SET status = CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM hostel_allocations 
            WHERE room_id = hr.room_id AND status = 'Active'
        ) > 0 THEN 'Occupied'
        WHEN hr.status = 'Occupied' THEN 'Available'
        ELSE hr.status
    END
    WHERE hr.room_id = NEW.room_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `hostel_maintenance`
--

CREATE TABLE `hostel_maintenance` (
  `maintenance_id` int(11) NOT NULL,
  `hostel_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `category` enum('Plumbing','Electrical','Carpentry','Painting','Furniture','Sanitary','Structural','Other') NOT NULL,
  `priority` enum('Emergency','High','Medium','Low') DEFAULT 'Medium',
  `reported_by` varchar(100) NOT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `actual_cost` decimal(10,2) DEFAULT NULL,
  `estimated_completion` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed','Cancelled') DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `created_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hostel_rooms`
--

CREATE TABLE `hostel_rooms` (
  `room_id` int(11) NOT NULL,
  `hostel_id` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `room_name` varchar(100) DEFAULT NULL,
  `floor_number` int(11) DEFAULT 1,
  `room_type` enum('Standard','VIP','Executive','Deluxe','Economy') DEFAULT 'Standard',
  `bed_count` int(11) DEFAULT 4,
  `facilities` text DEFAULT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `status` enum('Available','Occupied','Under Maintenance','Reserved') DEFAULT 'Available',
  `created_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `hostel_rooms`
--
DELIMITER $$
CREATE TRIGGER `after_room_insert` AFTER INSERT ON `hostel_rooms` FOR EACH ROW BEGIN
    UPDATE hostels 
    SET total_rooms = (
        SELECT COUNT(*) 
        FROM hostel_rooms 
        WHERE hostel_id = NEW.hostel_id
    ),
    available_beds = (
        SELECT SUM(bed_count) 
        FROM hostel_rooms 
        WHERE hostel_id = NEW.hostel_id AND status = 'Available'
    )
    WHERE hostel_id = NEW.hostel_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
--

CREATE TABLE `medical_records` (
  `record_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `genotype` varchar(10) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `conditions` text DEFAULT NULL,
  `disability` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `next_of_kin`
--

CREATE TABLE `next_of_kin` (
  `kin_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `sponsor_name` varchar(100) DEFAULT NULL,
  `sponsor_relationship` varchar(50) DEFAULT NULL,
  `sponsor_occupation` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `notification_type` enum('Academic','Financial','Hostel','General','Urgent') DEFAULT NULL,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `is_read` tinyint(1) DEFAULT 0,
  `sent_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_date` timestamp NULL DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `expires_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `parent_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `father_occupation` varchar(100) DEFAULT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_occupation` varchar(100) DEFAULT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `fee_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','Online','Card','Bank Draft','Cheque') DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `payer_name` varchar(100) DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `receipt_number` varchar(50) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verification_date` date DEFAULT NULL,
  `status` enum('Pending','Verified','Failed','Refunded') DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `proof_of_payment` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(150) NOT NULL,
  `grade_scale_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `duration_years` int(11) DEFAULT NULL,
  `total_credits` int(11) DEFAULT NULL,
  `degree_type` enum('Undergraduate','Postgraduate','Diploma','Certificate') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `result_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `session_year` varchar(10) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `ca_score` decimal(5,2) DEFAULT 0.00,
  `exam_score` decimal(5,2) DEFAULT 0.00,
  `total_score` decimal(5,2) DEFAULT 0.00,
  `grade` varchar(2) DEFAULT NULL,
  `grade_points` decimal(3,2) DEFAULT NULL,
  `grade_remark` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `published_date` date DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `calculated_by` int(11) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `rejection_reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `result_uploads`
--

CREATE TABLE `result_uploads` (
  `upload_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `session_year` varchar(20) NOT NULL,
  `semester` int(11) NOT NULL,
  `level` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `upload_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `total_records` int(11) DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL,
  `staff_number` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `employment_type` enum('Full-time','Part-time','Contract','Visiting') DEFAULT 'Full-time',
  `employment_date` date DEFAULT NULL,
  `qualification` varchar(200) DEFAULT NULL,
  `specialization` varchar(200) DEFAULT NULL,
  `office_location` varchar(100) DEFAULT NULL,
  `office_hours` varchar(100) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive','On Leave','Retired','Terminated') DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `matric_number` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT 'password',
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `state_of_origin` varchar(50) DEFAULT NULL,
  `lga` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `disability` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `admission_year` year(4) DEFAULT NULL,
  `current_level` int(11) DEFAULT 100,
  `cgpa` decimal(4,2) DEFAULT 0.00,
  `current_session` varchar(10) DEFAULT NULL,
  `mode_of_entry` enum('UTME','Direct Entry','Transfer','Remedial') DEFAULT NULL,
  `jamb_reg_number` varchar(20) DEFAULT NULL,
  `student_type` enum('Regular','Part-time','Distance Learning') DEFAULT NULL,
  `marital_status` enum('Single','Married','Divorced','Widowed') DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended','Graduated','Withdrawn') DEFAULT 'Active',
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `student_academic_performance`
-- (See below for the actual view)
--

-- --------------------------------------------------------

--
-- Table structure for table `student_advisors`
--

CREATE TABLE `student_advisors` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `advisor_id` int(11) NOT NULL,
  `assigned_date` date DEFAULT curdate(),
  `assignment_reason` varchar(200) DEFAULT NULL,
  `status` enum('Active','Changed','Completed') DEFAULT 'Active',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `student_dashboard`
-- (See below for the actual view)
--

-- --------------------------------------------------------

--
-- Table structure for table `student_fees`
--

CREATE TABLE `student_fees` (
  `fee_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `fee_structure_id` int(11) DEFAULT NULL,
  `session_year` varchar(100) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `fee_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) GENERATED ALWAYS AS (`amount` - `amount_paid`) STORED,
  `due_date` date DEFAULT NULL,
  `payment_deadline` date DEFAULT NULL,
  `status` enum('Pending','Partial','Paid','Overdue','Waived') DEFAULT 'Pending',
  `invoice_number` varchar(50) DEFAULT NULL,
  `created_date` date DEFAULT curdate(),
  `updated_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `student_financial_summary`
-- (See below for the actual view)
--

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) DEFAULT 'general',
  `setting_type` enum('text','number','boolean','textarea','select') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transcripts`
--

CREATE TABLE `transcripts` (
  `transcript_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `request_date` date DEFAULT curdate(),
  `purpose` varchar(200) DEFAULT NULL,
  `status` enum('Pending','Processing','Ready','Collected','Mailed') DEFAULT 'Pending',
  `processed_date` date DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `collection_date` date DEFAULT NULL,
  `tracking_number` varchar(50) DEFAULT NULL,
  `number_of_copies` int(11) DEFAULT 1,
  `delivery_method` enum('Pickup','Mail','Email') DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `payment_status` enum('Pending','Paid','Free') DEFAULT 'Pending',
  `download_link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `email_notifications` tinyint(1) DEFAULT 1,
  `sms_notifications` tinyint(1) DEFAULT 0,
  `push_notifications` tinyint(1) DEFAULT 1,
  `results_notifications` tinyint(1) DEFAULT 1,
  `payment_notifications` tinyint(1) DEFAULT 1,
  `course_updates` tinyint(1) DEFAULT 1,
  `announcements` tinyint(1) DEFAULT 1,
  `dark_mode` tinyint(1) DEFAULT 0,
  `language` varchar(10) DEFAULT 'en',
  `timezone` varchar(50) DEFAULT 'Africa/Lagos',
  `date_format` varchar(20) DEFAULT 'Y-m-d',
  `profile_visibility` enum('public','students_only','private') DEFAULT 'private',
  `show_email` tinyint(1) DEFAULT 0,
  `show_phone` tinyint(1) DEFAULT 0,
  `show_results` tinyint(1) DEFAULT 0,
  `data_sharing` tinyint(1) DEFAULT 0,
  `marketing_emails` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Structure for view `bed_availability`
--

DROP TABLE IF EXISTS `bed_availability`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `bed_availability` AS 
SELECT 
    `hr`.`room_id` AS `room_id`, 
    `hr`.`hostel_id` AS `hostel_id`, 
    `hr`.`bed_count` AS `bed_count`, 
    `hr`.`status` AS `room_status`, 
    COALESCE(SUM(CASE WHEN `ha`.`status` = 'Active' THEN 1 ELSE 0 END), 0) AS `occupied_beds_count`, 
    `hr`.`bed_count` - COALESCE(SUM(CASE WHEN `ha`.`status` = 'Active' THEN 1 ELSE 0 END), 0) AS `available_beds_count` 
FROM (`hostel_rooms` `hr` 
LEFT JOIN `hostel_allocations` `ha` ON (`hr`.`room_id` = `ha`.`room_id`)) 
GROUP BY `hr`.`room_id`, `hr`.`hostel_id`, `hr`.`bed_count`, `hr`.`status`;

-- --------------------------------------------------------

--
-- Structure for view `student_academic_performance`
--

DROP TABLE IF EXISTS `student_academic_performance`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `student_academic_performance` AS 
SELECT 
    `s`.`student_id` AS `student_id`, 
    `s`.`matric_number` AS `matric_number`, 
    CONCAT(`s`.`first_name`, ' ', `s`.`last_name`) AS `student_name`, 
    `r`.`session_year` AS `session_year`, 
    `r`.`semester` AS `semester`, 
    `r`.`level` AS `level`, 
    COUNT(`r`.`result_id`) AS `courses_taken`, 
    AVG(`r`.`grade_points`) AS `semester_gpa`, 
    SUM(CASE WHEN `r`.`grade` = 'F' THEN 1 ELSE 0 END) AS `failed_courses`, 
    SUM(`c`.`credit_units`) AS `total_credits` 
FROM ((`students` `s` 
JOIN `results` `r` ON (`s`.`student_id` = `r`.`student_id`)) 
JOIN `courses` `c` ON (`r`.`course_id` = `c`.`course_id`)) 
WHERE `r`.`is_published` = 1 
GROUP BY `s`.`student_id`, `r`.`session_year`, `r`.`semester`;

-- --------------------------------------------------------

--
-- Structure for view `student_dashboard`
--

DROP TABLE IF EXISTS `student_dashboard`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `student_dashboard` AS 
SELECT 
    `s`.`student_id` AS `student_id`, 
    `s`.`matric_number` AS `matric_number`, 
    CONCAT(`s`.`first_name`, ' ', COALESCE(`s`.`middle_name`, ''), ' ', `s`.`last_name`) AS `full_name`, 
    `s`.`email` AS `email`, 
    `s`.`phone` AS `phone`, 
    `d`.`department_name` AS `department_name`, 
    `p`.`program_name` AS `program_name`, 
    `s`.`current_level` AS `current_level`, 
    `s`.`current_session` AS `current_session`, 
    `s`.`status` AS `status`, 
    (SELECT COUNT(0) FROM `course_registrations` `cr` WHERE `cr`.`student_id` = `s`.`student_id` AND `cr`.`session_year` = `s`.`current_session`) AS `current_courses`, 
    (SELECT COALESCE(SUM(`sf`.`balance`), 0) FROM `student_fees` `sf` WHERE `sf`.`student_id` = `s`.`student_id` AND `sf`.`status` IN ('Pending', 'Partial')) AS `total_balance`, 
    (SELECT COUNT(0) FROM `notifications` `n` WHERE `n`.`student_id` = `s`.`student_id` AND `n`.`is_read` = 0) AS `unread_notifications`, 
    (SELECT COUNT(0) FROM `hostel_allocations` `ha` WHERE `ha`.`student_id` = `s`.`student_id` AND `ha`.`status` = 'Active') AS `has_hostel` 
FROM ((`students` `s` 
LEFT JOIN `departments` `d` ON (`s`.`department_id` = `d`.`department_id`)) 
LEFT JOIN `programs` `p` ON (`s`.`program_id` = `p`.`program_id`));

-- --------------------------------------------------------

--
-- Structure for view `student_financial_summary`
--

DROP TABLE IF EXISTS `student_financial_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `student_financial_summary` AS 
SELECT 
    `s`.`student_id` AS `student_id`, 
    `s`.`matric_number` AS `matric_number`, 
    CONCAT(`s`.`first_name`, ' ', `s`.`last_name`) AS `student_name`, 
    `sf`.`session_year` AS `session_year`, 
    `sf`.`semester` AS `semester`, 
    COUNT(`sf`.`fee_id`) AS `total_invoices`, 
    SUM(`sf`.`amount`) AS `total_fees`, 
    SUM(`sf`.`amount_paid`) AS `total_paid`, 
    SUM(`sf`.`balance`) AS `total_balance`, 
    GROUP_CONCAT(DISTINCT `sf`.`fee_type` SEPARATOR ', ') AS `fee_types`, 
    MIN(CASE WHEN `sf`.`status` IN ('Pending', 'Partial') THEN `sf`.`due_date` END) AS `next_due_date` 
FROM (`students` `s` 
JOIN `student_fees` `sf` ON (`s`.`student_id` = `sf`.`student_id`)) 
GROUP BY `s`.`student_id`, `sf`.`session_year`, `sf`.`semester`;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_advisors`
--
ALTER TABLE `academic_advisors`
  ADD PRIMARY KEY (`advisor_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `academic_records`
--
ALTER TABLE `academic_records`
  ADD PRIMARY KEY (`record_id`),
  ADD UNIQUE KEY `unique_record` (`student_id`,`session_year`,`semester`),
  ADD KEY `calculated_by` (`calculated_by`),
  ADD KEY `idx_student_session` (`student_id`,`session_year`,`semester`),
  ADD KEY `idx_session` (`session_year`,`semester`);

--
-- Indexes for table `academic_sessions`
--
ALTER TABLE `academic_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `session_year` (`session_year`);

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `last_activity` (`last_activity`);

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `role` (`role`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `advisor_meetings`
--
ALTER TABLE `advisor_meetings`
  ADD PRIMARY KEY (`meeting_id`),
  ADD KEY `advisor_id` (`advisor_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `meeting_date` (`meeting_date`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`course_id`),
  ADD UNIQUE KEY `course_code` (`course_code`),
  ADD UNIQUE KEY `idx_course_code` (`course_code`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `prerequisite_course_id` (`prerequisite_course_id`);

--
-- Indexes for table `course_programs`
--
ALTER TABLE `course_programs`
  ADD PRIMARY KEY (`cp_id`),
  ADD UNIQUE KEY `unique_course_program` (`course_id`,`program_id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `course_registrations`
--
ALTER TABLE `course_registrations`
  ADD PRIMARY KEY (`registration_id`),
  ADD UNIQUE KEY `unique_reg` (`student_id`,`course_id`,`session_year`,`semester`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `email_queue`
--
ALTER TABLE `email_queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `status` (`status`),
  ADD KEY `scheduled_time` (`scheduled_time`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `faculties`
--
ALTER TABLE `faculties`
  ADD PRIMARY KEY (`faculty_id`),
  ADD UNIQUE KEY `faculty_code` (`faculty_code`);

--
-- Indexes for table `fee_structure`
--
ALTER TABLE `fee_structure`
  ADD PRIMARY KEY (`fee_structure_id`);

--
-- Indexes for table `grade_entries`
--
ALTER TABLE `grade_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `scale_id` (`scale_id`);

--
-- Indexes for table `grade_scale`
--
ALTER TABLE `grade_scale`
  ADD PRIMARY KEY (`grade_id`),
  ADD UNIQUE KEY `unique_grade` (`grade`);

--
-- Indexes for table `grade_scales`
--
ALTER TABLE `grade_scales`
  ADD PRIMARY KEY (`scale_id`);

--
-- Indexes for table `hostels`
--
ALTER TABLE `hostels`
  ADD PRIMARY KEY (`hostel_id`),
  ADD UNIQUE KEY `hostel_code` (`hostel_code`);

--
-- Indexes for table `hostel_allocations`
--
ALTER TABLE `hostel_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `unique_active_bed_allocation` (`room_id`,`bed_number`,`status`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `hostel_id` (`hostel_id`);

--
-- Indexes for table `hostel_maintenance`
--
ALTER TABLE `hostel_maintenance`
  ADD PRIMARY KEY (`maintenance_id`),
  ADD KEY `hostel_id` (`hostel_id`),
  ADD KEY `room_id` (`room_id`);

--
-- Indexes for table `hostel_rooms`
--
ALTER TABLE `hostel_rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `hostel_id` (`hostel_id`,`room_number`);

--
-- Indexes for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD PRIMARY KEY (`record_id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `next_of_kin`
--
ALTER TABLE `next_of_kin`
  ADD PRIMARY KEY (`kin_id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`parent_id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `fee_id` (`fee_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD UNIQUE KEY `program_code` (`program_code`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `grade_scale_id` (`grade_scale_id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`result_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `idx_results_student_session` (`student_id`,`session_year`,`semester`,`is_published`);

--
-- Indexes for table `result_uploads`
--
ALTER TABLE `result_uploads`
  ADD PRIMARY KEY (`upload_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `staff_number` (`staff_number`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `matric_number` (`matric_number`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `student_advisors`
--
ALTER TABLE `student_advisors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `advisor_id` (`advisor_id`);

--
-- Indexes for table `student_fees`
--
ALTER TABLE `student_fees`
  ADD PRIMARY KEY (`fee_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `fee_structure_id` (`fee_structure_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `transcripts`
--
ALTER TABLE `transcripts`
  ADD PRIMARY KEY (`transcript_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_advisors`
--
ALTER TABLE `academic_advisors`
  MODIFY `advisor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_records`
--
ALTER TABLE `academic_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_sessions`
--
ALTER TABLE `academic_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `advisor_meetings`
--
ALTER TABLE `advisor_meetings`
  MODIFY `meeting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_programs`
--
ALTER TABLE `course_programs`
  MODIFY `cp_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_registrations`
--
ALTER TABLE `course_registrations`
  MODIFY `registration_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_queue`
--
ALTER TABLE `email_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculties`
--
ALTER TABLE `faculties`
  MODIFY `faculty_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_structure`
--
ALTER TABLE `fee_structure`
  MODIFY `fee_structure_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_entries`
--
ALTER TABLE `grade_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_scale`
--
ALTER TABLE `grade_scale`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_scales`
--
ALTER TABLE `grade_scales`
  MODIFY `scale_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hostels`
--
ALTER TABLE `hostels`
  MODIFY `hostel_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hostel_allocations`
--
ALTER TABLE `hostel_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hostel_maintenance`
--
ALTER TABLE `hostel_maintenance`
  MODIFY `maintenance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hostel_rooms`
--
ALTER TABLE `hostel_rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_records`
--
ALTER TABLE `medical_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `next_of_kin`
--
ALTER TABLE `next_of_kin`
  MODIFY `kin_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `parent_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `result_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `result_uploads`
--
ALTER TABLE `result_uploads`
  MODIFY `upload_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_advisors`
--
ALTER TABLE `student_advisors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_fees`
--
ALTER TABLE `student_fees`
  MODIFY `fee_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transcripts`
--
ALTER TABLE `transcripts`
  MODIFY `transcript_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academic_advisors`
--
ALTER TABLE `academic_advisors`
  ADD CONSTRAINT `academic_advisors_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `academic_records`
--
ALTER TABLE `academic_records`
  ADD CONSTRAINT `academic_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `academic_records_ibfk_2` FOREIGN KEY (`calculated_by`) REFERENCES `admin_users` (`admin_id`);

--
-- Constraints for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL;

--
-- Constraints for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`admin_id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD CONSTRAINT `admin_users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `advisor_meetings`
--
ALTER TABLE `advisor_meetings`
  ADD CONSTRAINT `advisor_meetings_ibfk_1` FOREIGN KEY (`advisor_id`) REFERENCES `academic_advisors` (`advisor_id`),
  ADD CONSTRAINT `advisor_meetings_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `courses_ibfk_2` FOREIGN KEY (`prerequisite_course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `course_programs`
--
ALTER TABLE `course_programs`
  ADD CONSTRAINT `fk_course_programs_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_course_programs_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE;

--
-- Constraints for table `course_registrations`
--
ALTER TABLE `course_registrations`
  ADD CONSTRAINT `course_registrations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `course_registrations_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`faculty_id`) ON DELETE SET NULL;

--
-- Constraints for table `grade_entries`
--
ALTER TABLE `grade_entries`
  ADD CONSTRAINT `grade_entries_ibfk_1` FOREIGN KEY (`scale_id`) REFERENCES `grade_scales` (`scale_id`) ON DELETE CASCADE;

--
-- Constraints for table `hostel_allocations`
--
ALTER TABLE `hostel_allocations`
  ADD CONSTRAINT `hostel_allocations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hostel_allocations_ibfk_2` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`hostel_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hostel_allocations_ibfk_3` FOREIGN KEY (`room_id`) REFERENCES `hostel_rooms` (`room_id`) ON DELETE CASCADE;

--
-- Constraints for table `hostel_maintenance`
--
ALTER TABLE `hostel_maintenance`
  ADD CONSTRAINT `hostel_maintenance_ibfk_1` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`hostel_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hostel_maintenance_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `hostel_rooms` (`room_id`) ON DELETE SET NULL;

--
-- Constraints for table `hostel_rooms`
--
ALTER TABLE `hostel_rooms`
  ADD CONSTRAINT `hostel_rooms_ibfk_1` FOREIGN KEY (`hostel_id`) REFERENCES `hostels` (`hostel_id`) ON DELETE CASCADE;

--
-- Constraints for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD CONSTRAINT `medical_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `next_of_kin`
--
ALTER TABLE `next_of_kin`
  ADD CONSTRAINT `next_of_kin_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `parents`
--
ALTER TABLE `parents`
  ADD CONSTRAINT `parents_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`fee_id`) REFERENCES `student_fees` (`fee_id`);

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `programs_ibfk_2` FOREIGN KEY (`grade_scale_id`) REFERENCES `grade_scales` (`scale_id`);

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `results_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `results_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `staff`
--
ALTER TABLE `staff`
  ADD CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`);

--
-- Constraints for table `student_advisors`
--
ALTER TABLE `student_advisors`
  ADD CONSTRAINT `student_advisors_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `student_advisors_ibfk_2` FOREIGN KEY (`advisor_id`) REFERENCES `academic_advisors` (`advisor_id`);

--
-- Constraints for table `student_fees`
--
ALTER TABLE `student_fees`
  ADD CONSTRAINT `student_fees_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `student_fees_ibfk_2` FOREIGN KEY (`fee_structure_id`) REFERENCES `fee_structure` (`fee_structure_id`);

--
-- Constraints for table `transcripts`
--
ALTER TABLE `transcripts`
  ADD CONSTRAINT `transcripts_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;