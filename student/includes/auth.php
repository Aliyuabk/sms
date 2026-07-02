<?php
require_once 'config.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $this->conn = getDB();
    }
    
    public function login($matric_number, $password) {
        if (!$this->conn) {
            return ['success' => false, 'message' => 'Database connection failed'];
        }
        
        // Updated query to match your students table structure
        $sql = "SELECT s.*, d.department_name, p.program_name 
                FROM students s
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN programs p ON s.program_id = p.program_id
                WHERE s.matric_number = ? AND s.status = 'Active'";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $matric_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $student = $result->fetch_assoc();
            
            // Check password (using plain text as per your table structure)
            if ($password === $student['password_hash']) {
                // Set session variables
                $_SESSION['student_id'] = $student['student_id'];
                $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
                $_SESSION['matric_number'] = $student['matric_number'];
                $_SESSION['email'] = $student['email'];
                $_SESSION['department'] = $student['department_name'] ?? '';
                $_SESSION['program'] = $student['program_name'] ?? '';
                $_SESSION['level'] = $student['current_level'] ?? 100;
                
                // Update last login
                $update_sql = "UPDATE students SET last_login = NOW() WHERE student_id = ?";
                $update_stmt = $this->conn->prepare($update_sql);
                $update_stmt->bind_param("i", $student['student_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                $stmt->close();
                return ['success' => true, 'message' => 'Login successful'];
            } else {
                $stmt->close();
                return ['success' => false, 'message' => 'Invalid password'];
            }
        } else {
            $stmt->close();
            return ['success' => false, 'message' => 'Matric number not found'];
        }
    }
    
    public function logout() {
        session_unset();
        session_destroy();
        return true;
    }
    
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
?>