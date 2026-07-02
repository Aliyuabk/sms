<?php
/**
 * Staff Login Page - Fixed Header Issues
 * NO whitespace, BOM, or output before this <?php tag
 */

// ============================================================
// ERROR REPORTING - Remove in production
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================================
// START OUTPUT BUFFERING - Prevents header errors
// ============================================================
ob_start();

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

// ============================================================
// HANDLE LOGIN
// ============================================================
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT staff_id, staff_number, first_name, last_name, email, 
                                   password_hash, role, status, profile_image, can_login 
                                   FROM staff WHERE email = ?");
            $stmt->execute([$email]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$staff) {
                $error = "No account found with this email address.";
            } 
            elseif ($staff['status'] !== 'Active') {
                $error = "Your account is currently " . $staff['status'] . ". Please contact support.";
            }
            elseif ($staff['can_login'] != 1) {
                $error = "Your account does not have login permissions. Please contact an administrator.";
            }
            else {
                $passwordVerified = false;
                
                if (password_verify($password, $staff['password_hash'])) {
                    $passwordVerified = true;
                } 
                elseif ($staff['password_hash'] === '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' && $password === 'password') {
                    $passwordVerified = true;
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE staff SET password_hash = ? WHERE staff_id = ?");
                    $updateStmt->execute([$newHash, $staff['staff_id']]);
                }

                if ($passwordVerified) {
                    $updateStmt = $pdo->prepare("UPDATE staff SET last_login = NOW() WHERE staff_id = ?");
                    $updateStmt->execute([$staff['staff_id']]);

                    $_SESSION['staff_id'] = $staff['staff_id'];
                    $_SESSION['staff_name'] = $staff['first_name'] . ' ' . $staff['last_name'];
                    $_SESSION['staff_role'] = $staff['role'];
                    $_SESSION['staff_email'] = $staff['email'];
                    $_SESSION['staff_number'] = $staff['staff_number'];
                    $_SESSION['staff_image'] = $staff['profile_image'];
                    $_SESSION['staff_last_login'] = date('Y-m-d H:i:s');

                    // Clear output buffer before redirect
                    ob_end_clean();
                    
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Invalid password. Please try again.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error. Please try again.";
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

// ============================================================
// ALREADY LOGGED IN?
// ============================================================
if (isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login | eGuru</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../assets/images/logo.jpeg" type="image/x-icon">
    <style>
        :root {
            --primary-color: #3f749c;
            --primary-dark: #2a5a7a;
            --primary-light: #5a9bc4;
            --primary-soft: #e8f2f8;
            --secondary-color: #c5ea4f;
            --accent-color: #d4f07a;
            --danger-color: #f44336;
            --warning-color: #ff9800;
            --success-color: #7cb342;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 40px rgba(63, 116, 156, 0.15);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 50%, var(--primary-light) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -10%;
            right: -5%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(197, 234, 79, 0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -10%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-card {
            background: var(--white);
            border-radius: 24px;
            box-shadow: var(--shadow-xl);
            padding: 48px;
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
            border: 1px solid var(--gray-200);
            animation: fadeInUp 0.6s ease forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(63, 116, 156, 0.3);
            transition: var(--transition);
            overflow: hidden;
        }

        .login-icon img {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            object-fit: cover;
        }

        .login-card:hover .login-icon {
            transform: scale(1.05) rotate(-3deg);
        }

        .login-header h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .login-header p {
            color: var(--text-light);
            font-size: 0.9rem;
            margin: 0;
        }

        .form-floating { 
            margin-bottom: 18px; 
        }

        .form-floating .form-control {
            border: 2px solid var(--gray-200);
            border-radius: 14px;
            padding: 1rem 1rem 0.5rem 3rem;
            height: 58px;
            font-size: 0.95rem;
            color: var(--text-dark);
            transition: var(--transition);
            background-color: var(--gray-100);
        }

        .form-floating .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(63, 116, 156, 0.1);
            background-color: var(--white);
        }

        .form-floating label {
            padding-left: 3rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-floating label i {
            position: absolute;
            left: -2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 1rem;
        }

        .password-wrapper { 
            position: relative; 
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray-500);
            z-index: 10;
            padding: 8px;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            padding: 14px;
            font-weight: 700;
            font-size: 1rem;
            border-radius: 14px;
            color: var(--white);
            transition: var(--transition);
            box-shadow: 0 4px 16px rgba(63, 116, 156, 0.3);
            letter-spacing: 0.3px;
            width: 100%;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(63, 116, 156, 0.4);
            filter: brightness(1.05);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            margin-right: 8px;
        }

        .form-check-input {
            border: 2px solid var(--gray-300);
            width: 1.1em;
            height: 1.1em;
            border-radius: 6px;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .forgot-link {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: var(--transition);
        }

        .forgot-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .alert-danger {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border: 1px solid rgba(244, 67, 54, 0.2);
            color: #c62828;
            border-radius: 14px;
            padding: 14px 18px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .alert-danger i {
            color: var(--danger-color);
            margin-right: 10px;
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border: 1px solid rgba(124, 179, 66, 0.2);
            color: #2e7d32;
            border-radius: 14px;
            padding: 14px 18px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .alert-success i {
            color: var(--success-color);
            margin-right: 10px;
        }

        .portal-links {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
            text-align: center;
        }

        .portal-links p {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 12px;
            font-weight: 500;
        }

        .portal-links a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            margin: 0 4px;
        }

        .portal-links a.student-link {
            background: var(--primary-soft);
            color: var(--primary-color);
        }

        .portal-links a.student-link:hover {
            background: var(--primary-color);
            color: var(--white);
        }

        .portal-links a.admin-link {
            background: #fff3e0;
            color: var(--warning-color);
        }

        .portal-links a.admin-link:hover {
            background: var(--warning-color);
            color: var(--white);
        }

        .demo-credentials {
            margin-top: 16px;
            padding: 16px;
            background: var(--gray-100);
            border-radius: 12px;
            font-size: 0.85rem;
        }

        .demo-credentials strong {
            color: var(--primary-color);
        }

        @media (max-width: 576px) {
            .login-card {
                padding: 32px 24px;
                margin: 16px;
                border-radius: 20px;
            }

            .login-icon {
                width: 64px;
                height: 64px;
            }

            .login-icon img {
                width: 48px;
                height: 48px;
            }

            .login-header h3 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">
                <img src="../assets/images/logo.jpeg" alt="Logo">
            </div>
            <h3>Staff Sign In</h3>
            <p>Sign in to access your dashboard</p>
        </div>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size: 0.7rem;"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-floating">
                <input type="email" class="form-control" id="email" name="email" placeholder="Email" 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                <label for="email"><i class="fas fa-envelope"></i>Email Address</label>
            </div>

            <div class="form-floating password-wrapper">
                <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                <label for="password"><i class="fas fa-lock"></i>Password</label>
                <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>Sign In
            </button>
        </form>

        <!-- Demo Credentials -->
        <div class="demo-credentials">
            <strong>Demo Credentials:</strong><br>
            Email: <span id="demoEmail" style="cursor: pointer; color: var(--primary-color);" onclick="fillDemoCredentials()">aliyuabubakar11117@gmail.com</span><br>
            Password: <span id="demoPassword" style="cursor: pointer; color: var(--primary-color);" onclick="fillDemoCredentials()">Click to fill</span>
        </div>

        <div class="portal-links">
            <p>Access other portals:</p>
            <a href="../student/index.php" class="student-link">
                <i class="fas fa-user-graduate"></i> Student Portal
            </a>
            <a href="../admin/index.php" class="admin-link">
                <i class="fas fa-user-shield"></i> Admin Portal
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const pwd = document.getElementById('password');
            const icon = event.target;
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function fillDemoCredentials() {
            document.getElementById('email').value = 'aliyuabubakar11117@gmail.com';
            document.getElementById('password').value = 'password';
        }
    </script>
</body>
</html>