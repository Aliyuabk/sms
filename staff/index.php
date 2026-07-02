<?php
/**
 * Staff Login Page - Professional UI
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../assets/images/logo.jpeg" type="image/x-icon">
    <style>
        /* ===== CSS VARIABLES ===== */
        :root {
            --primary-color: #3f749c;
            --primary-dark: #1a3a5c;
            --primary-light: #6aafd4;
            --primary-soft: #e8f2f8;
            --secondary-color: #c5ea4f;
            --accent-color: #d4f07a;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --success-color: #10b981;
            --text-dark: #0f172a;
            --text-light: #64748b;
            --white: #ffffff;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,0.25);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 50%, var(--primary-light) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* ===== ANIMATED BACKGROUND ELEMENTS ===== */
        body::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(197, 234, 79, 0.12) 0%, transparent 70%);
            border-radius: 50%;
            animation: floatBubble 20s ease-in-out infinite;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -20%;
            left: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            animation: floatBubble 25s ease-in-out infinite reverse;
        }

        @keyframes floatBubble {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.05); }
            66% { transform: translate(-20px, 20px) scale(0.95); }
        }

        /* Floating particles */
        .particles {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }

        .particle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            animation: particleFloat linear infinite;
        }

        .particle:nth-child(1) { left: 10%; animation-duration: 12s; animation-delay: 0s; width: 8px; height: 8px; }
        .particle:nth-child(2) { left: 20%; animation-duration: 15s; animation-delay: 2s; width: 4px; height: 4px; }
        .particle:nth-child(3) { left: 30%; animation-duration: 10s; animation-delay: 4s; width: 6px; height: 6px; }
        .particle:nth-child(4) { left: 50%; animation-duration: 18s; animation-delay: 1s; width: 10px; height: 10px; }
        .particle:nth-child(5) { left: 60%; animation-duration: 14s; animation-delay: 3s; width: 5px; height: 5px; }
        .particle:nth-child(6) { left: 70%; animation-duration: 11s; animation-delay: 5s; width: 7px; height: 7px; }
        .particle:nth-child(7) { left: 80%; animation-duration: 16s; animation-delay: 2s; width: 4px; height: 4px; }
        .particle:nth-child(8) { left: 90%; animation-duration: 13s; animation-delay: 4s; width: 8px; height: 8px; }

        @keyframes particleFloat {
            0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-10vh) rotate(720deg); opacity: 0; }
        }

        /* ===== LOGIN CARD ===== */
        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            padding: 20px;
        }

        .login-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 32px;
            box-shadow: var(--shadow-2xl);
            padding: 48px 40px;
            border: 1px solid rgba(255,255,255,0.2);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            transform: translateY(30px);
            opacity: 0;
        }

        @keyframes slideUp {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* ===== LOGO / HEADER ===== */
        .login-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .logo-container {
            position: relative;
            width: 88px;
            height: 88px;
            margin: 0 auto 20px;
        }

        .logo-ring {
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: conic-gradient(from 0deg, var(--primary-color), var(--secondary-color), var(--primary-color));
            animation: spin 4s linear infinite;
            opacity: 0.3;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .login-icon {
            position: relative;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(63, 116, 156, 0.35);
            transition: var(--transition);
            overflow: hidden;
            margin: 0 auto;
        }

        .login-icon img {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            object-fit: cover;
        }

        .login-card:hover .login-icon {
            transform: scale(1.05);
            box-shadow: 0 12px 40px rgba(63, 116, 156, 0.45);
        }

        .login-header h3 {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            color: var(--text-light);
            font-size: 0.95rem;
            font-weight: 400;
        }

        /* ===== FORM ELEMENTS ===== */
        .form-floating {
            margin-bottom: 16px;
        }

        .form-floating .form-control {
            border: 2px solid var(--gray-200);
            border-radius: 16px;
            padding: 1rem 1rem 0.5rem 3.2rem;
            height: 60px;
            font-size: 0.95rem;
            color: var(--text-dark);
            transition: var(--transition);
            background-color: var(--gray-50);
            font-weight: 500;
        }

        .form-floating .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(63, 116, 156, 0.12);
            background-color: var(--white);
        }

        .form-floating .form-control::placeholder {
            color: var(--gray-400);
            font-weight: 400;
        }

        .form-floating label {
            padding-left: 3.2rem;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-floating label i {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .form-floating .form-control:focus + label i {
            color: var(--primary-dark);
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
            color: var(--gray-400);
            z-index: 10;
            padding: 8px;
            transition: var(--transition);
            font-size: 1.1rem;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .password-toggle .tooltip-text {
            position: absolute;
            top: -30px;
            right: 0;
            background: var(--text-dark);
            color: var(--white);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            white-space: nowrap;
            opacity: 0;
            transform: translateY(5px);
            transition: var(--transition);
            pointer-events: none;
        }

        .password-toggle:hover .tooltip-text {
            opacity: 1;
            transform: translateY(0);
        }

        /* ===== BUTTON ===== */
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            padding: 16px;
            font-weight: 700;
            font-size: 1rem;
            border-radius: 16px;
            color: var(--white);
            transition: var(--transition);
            box-shadow: 0 4px 20px rgba(63, 116, 156, 0.35);
            letter-spacing: 0.3px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: 0.6s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(63, 116, 156, 0.45);
            filter: brightness(1.05);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .btn-login .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        .btn-login.loading .btn-text {
            display: none;
        }
        .btn-login.loading .spinner {
            display: block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ===== CHECKBOX & LINKS ===== */
        .form-check-input {
            border: 2px solid var(--gray-300);
            width: 1.1em;
            height: 1.1em;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.2);
        }

        .form-check-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
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

        /* ===== ALERTS ===== */
        .alert-danger {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #991b1b;
            border-radius: 16px;
            padding: 14px 18px;
            font-weight: 500;
            font-size: 0.9rem;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-10px); }
            40% { transform: translateX(10px); }
            60% { transform: translateX(-6px); }
            80% { transform: translateX(6px); }
        }

        .alert-danger i {
            color: var(--danger-color);
            margin-right: 10px;
        }

        .alert-success {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #065f46;
            border-radius: 16px;
            padding: 14px 18px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .alert-success i {
            color: var(--success-color);
            margin-right: 10px;
        }

        /* ===== DEMO CREDENTIALS ===== */
        .demo-credentials {
            margin-top: 20px;
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--gray-50), var(--gray-100));
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            font-size: 0.85rem;
        }

        .demo-credentials .demo-label {
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .demo-credentials .demo-label i {
            color: var(--warning-color);
            font-size: 0.9rem;
        }

        .demo-credentials .demo-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }

        .demo-credentials .demo-row .key {
            color: var(--text-light);
            font-weight: 500;
            min-width: 50px;
        }

        .demo-credentials .demo-row .value {
            color: var(--primary-color);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            padding: 2px 8px;
            border-radius: 6px;
            background: transparent;
        }

        .demo-credentials .demo-row .value:hover {
            background: var(--primary-soft);
            transform: scale(1.02);
        }

        .demo-credentials .demo-row .value i {
            font-size: 0.7rem;
            margin-left: 4px;
            opacity: 0.5;
        }

        .demo-fill-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: 10px;
            background: var(--primary-color);
            color: var(--white);
            border: none;
            font-weight: 600;
            font-size: 0.8rem;
            transition: var(--transition);
            cursor: pointer;
            margin-top: 8px;
        }

        .demo-fill-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(63, 116, 156, 0.3);
        }

        /* ===== PORTAL LINKS ===== */
        .portal-links {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
            text-align: center;
        }

        .portal-links p {
            color: var(--text-light);
            font-size: 0.8rem;
            margin-bottom: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .portal-links .link-group {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .portal-links a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
        }

        .portal-links a.student-link {
            background: var(--primary-soft);
            color: var(--primary-color);
        }

        .portal-links a.student-link:hover {
            background: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(63, 116, 156, 0.3);
        }

        .portal-links a.admin-link {
            background: #fef3c7;
            color: var(--warning-color);
        }

        .portal-links a.admin-link:hover {
            background: var(--warning-color);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .login-wrapper { padding: 12px; }
            .login-card { padding: 32px 24px; border-radius: 24px; }
            .login-header h3 { font-size: 1.4rem; }
            .logo-container { width: 72px; height: 72px; }
            .login-icon { width: 64px; height: 64px; }
            .login-icon img { width: 40px; height: 40px; }
            .form-floating .form-control { height: 54px; font-size: 0.9rem; }
            .demo-credentials { padding: 14px 16px; }
        }

        @media (max-width: 380px) {
            .login-card { padding: 24px 16px; }
            .portal-links .link-group { flex-direction: column; align-items: center; }
            .portal-links a { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- ===== ANIMATED PARTICLES ===== -->
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="login-wrapper">
        <div class="login-card">
            <!-- ===== HEADER ===== -->
            <div class="login-header">
                <div class="logo-container">
                    <div class="logo-ring"></div>
                    <div class="login-icon">
                        <img src="../assets/images/logo.png" alt="Logo">
                    </div>
                </div>
                <h3>Welcome Back</h3>
                <p>Sign in to access your staff dashboard</p>
            </div>

            <!-- ===== ALERTS ===== -->
            <?php if ($error !== null): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size: 0.7rem;"></button>
                </div>
            <?php endif; ?>

            <!-- ===== LOGIN FORM ===== -->
            <form method="POST" action="" id="loginForm">
                <div class="form-floating">
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="name@example.com" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           required autocomplete="email">
                    <label for="email"><i class="fas fa-envelope"></i>Email Address</label>
                </div>

                <div class="form-floating password-wrapper">
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Enter your password" required autocomplete="current-password">
                    <label for="password"><i class="fas fa-lock"></i>Password</label>
                    <i class="fas fa-eye password-toggle" onclick="togglePassword()">
                        <span class="tooltip-text">Show/Hide</span>
                    </i>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="btn-text"><i class="fas fa-sign-in-alt"></i>Sign In</span>
                    <span class="spinner"></span>
                </button>
            </form>

            <!-- ===== DEMO CREDENTIALS ===== -->
            <div class="demo-credentials">
                <div class="demo-label">
                    <i class="fas fa-terminal"></i> Demo Credentials
                </div>
                <div class="demo-row">
                    <span class="key">Email:</span>
                    <span class="value" onclick="fillField('email', 'aliyuabubakar11117@gmail.com')">
                        aliyuabubakar11117@gmail.com <i class="fas fa-copy"></i>
                    </span>
                </div>
                <div class="demo-row">
                    <span class="key">Password:</span>
                    <span class="value" onclick="fillField('password', 'password')">
                        •••••••• <i class="fas fa-copy"></i>
                    </span>
                </div>
                <button class="demo-fill-btn" onclick="fillDemoCredentials()">
                    <i class="fas fa-arrow-right"></i> Auto-fill & Login
                </button>
            </div>

            <!-- ===== PORTAL LINKS ===== -->
            <div class="portal-links">
                <p>Access other portals</p>
                <div class="link-group">
                    <a href="../" class="student-link">
                        <i class="fas fa-user-graduate"></i> Student Portal
                    </a>
                    <a href="../" class="admin-link">
                        <i class="fas fa-user-shield"></i> Admin Portal
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ===== TOGGLE PASSWORD VISIBILITY =====
        function togglePassword() {
            const pwd = document.getElementById('password');
            const icon = document.querySelector('.password-toggle');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // ===== FILL SINGLE FIELD =====
        function fillField(fieldId, value) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = value;
                field.focus();
                field.style.borderColor = 'var(--success-color)';
                field.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.15)';
                setTimeout(() => {
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                }, 2000);
                
                // Show feedback
                const el = event.target;
                const originalText = el.innerHTML;
                el.innerHTML = '✓ Copied! <i class="fas fa-check"></i>';
                setTimeout(() => {
                    el.innerHTML = originalText;
                }, 1500);
            }
        }

        // ===== AUTO-FILL DEMO CREDENTIALS =====
        function fillDemoCredentials() {
            document.getElementById('email').value = 'aliyuabubakar11117@gmail.com';
            document.getElementById('password').value = 'password';
            
            // Highlight fields
            ['email', 'password'].forEach(id => {
                const field = document.getElementById(id);
                field.style.borderColor = 'var(--success-color)';
                field.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.15)';
                setTimeout(() => {
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                }, 1500);
            });
            
            // Auto-submit after brief delay
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.disabled = true;
            
            setTimeout(() => {
                document.getElementById('loginForm').submit();
            }, 800);
        }

        // ===== FORM SUBMIT LOADING STATE =====
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.disabled = true;
        });

        // ===== KEYBOARD SHORTCUT: CTRL + ENTER TO LOGIN =====
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });

        // ===== ENTER KEY IN PASSWORD FIELD TRIGGERS LOGIN =====
        document.getElementById('password').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        });

        // ===== AUTO-FOCUS ON EMAIL FIELD =====
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
    </script>
</body>
</html>