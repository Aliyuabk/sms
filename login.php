<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'utgoohwm_kowaguru');
define('DB_PASS', 'Jiddaahh@1');
define('DB_NAME', 'utgoohwm_sms');

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$student_error = $admin_error = '';
$login_attempts = $_SESSION['login_attempts'] ?? 0;

// Admin access control
$admin_access_key = 'kowaguru2026';
$show_admin = false;

if (isset($_GET['admin']) && $_GET['admin'] === $admin_access_key) {
    $show_admin = true;
    $_SESSION['admin_access_granted'] = true;
} elseif (isset($_SESSION['admin_access_granted']) && $_SESSION['admin_access_granted'] === true) {
    $show_admin = true;
}

$active_tab = $show_admin ? 'admin' : 'student';

// ==================== STUDENT LOGIN ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'student') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $student_error = 'Invalid security token.';
    } else {
        if ($login_attempts >= 5) {
            $student_error = 'Too many failed attempts. Wait 15 minutes.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                $student_error = 'Please enter both School ID and password.';
                $_SESSION['login_attempts'] = $login_attempts + 1;
            } else {
                try {
                    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                    if ($conn->connect_error) throw new Exception('DB connection failed');

                    $sql = "SELECT student_id, matric_number, email, password_hash, first_name, last_name, 
                            current_level, department_id, program_id, status 
                            FROM students 
                            WHERE (email = ? OR matric_number = ?) AND status = 'Active'";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('ss', $username, $username);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 1) {
                        $student = $result->fetch_assoc();
                        $login_successful = password_verify($password, $student['password_hash']);
                        if (!$login_successful && $password === $student['password_hash']) {
                            $login_successful = true;
                        }

                        if ($login_successful) {
                            $_SESSION['student_id'] = $student['student_id'];
                            $_SESSION['matric_number'] = $student['matric_number'];
                            $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
                            $_SESSION['email'] = $student['email'];
                            $_SESSION['level'] = $student['current_level'];
                            $_SESSION['login_attempts'] = 0;

                            $conn->query("UPDATE students SET last_login = CURRENT_TIMESTAMP WHERE student_id = " . $student['student_id']);
                            $stmt->close();
                            $conn->close();
                            header('Location: student/');
                            exit();
                        } else {
                            $student_error = 'Invalid School ID or password.';
                            $_SESSION['login_attempts'] = $login_attempts + 1;
                        }
                    } else {
                        $student_error = 'Invalid School ID or password.';
                        $_SESSION['login_attempts'] = $login_attempts + 1;
                    }
                    $stmt->close();
                    $conn->close();
                } catch (Exception $e) {
                    error_log('Login error: ' . $e->getMessage());
                    $student_error = 'System error. Please try again.';
                }
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== ADMIN LOGIN ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    require_once 'includes/database.php';
    $username = trim($_POST['admin_username'] ?? '');
    $password = $_POST['admin_password'] ?? '';

    try {
        $checkLock = $pdo->prepare("
            SELECT * FROM admin_users 
            WHERE (username = ? OR email = ?) 
            AND status = 'Active' 
            AND (locked_until IS NULL OR locked_until < NOW())
        ");
        $checkLock->execute([$username, $username]);
        $admin = $checkLock->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $pdo->prepare("UPDATE admin_users SET failed_attempts = 0 WHERE admin_id = ?")
                ->execute([$admin['admin_id']]);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_role'] = $admin['role'];
            header('Location: admin/dashboard.php');
            exit();
        } else {
            $admin_error = 'Access denied.';
        }
    } catch (PDOException $e) {
        error_log("Admin login error: " . $e->getMessage());
        $admin_error = 'System error.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | 5G E-GURUSCHOOL</title>
    <link rel="shortcut icon" href="assets/images/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue: #3f749c;
            --blue-dark: #2d5a7a;
            --blue-light: #e8f2f8;
            --lime: #c5ea4f;
            --lime-light: #f0f9d8;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border: #d1d5db;
            --bg: #f1f5f9;
            --white: #ffffff;
            --danger: #ef4444;
            --success: #10b981;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Main Container */
        .login-wrapper {
            width: 100%;
            max-width: 900px;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(63, 116, 156, 0.15);
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 520px;
        }

        /* Left Side - Blue with curved shapes */
        .login-left {
            background: var(--blue);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        /* Top curved white shape with logo */
        .curve-top {
            position: absolute;
            top: -50px;
            right: -30px;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .school-logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--blue);
        }

        .school-name {
            position: absolute;
            top: 100px;
            right: 25px;
            text-align: center;
            color: var(--blue-dark);
            font-size: 12px;
            font-weight: 700;
            line-height: 1.6;
        }

        /* Bottom curved white shape with illustration */
        .curve-bottom {
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 180px;
            height: 180px;
            background: rgba(255,255,255,0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        /* Right Side - White with form */
        .login-right {
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-title {
            font-size: 32px;
            font-weight: 400;
            color: var(--text-dark);
            margin-bottom: 36px;
            text-align: center;
        }

        /* Alert */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-danger {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        /* Form with icons */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .input-icon-wrapper {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            z-index: 2;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s;
            background: #fff;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px var(--blue-light);
        }
        .form-input:focus + .input-icon,
        .form-input:focus ~ .input-icon {
            color: var(--blue);
        }
        .form-input::placeholder {
            color: #9ca3af;
        }

        /* Password toggle button */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.2s;
            z-index: 2;
        }
        .password-toggle:hover {
            color: var(--blue);
            background: var(--blue-light);
        }

        /* Forgot password */
        .forgot-link {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            font-size: 13px;
            color: var(--text-dark);
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.2s;
        }
        .forgot-link i {
            font-size: 12px;
        }
        .forgot-link:hover {
            color: var(--blue);
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 14px;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .login-btn:hover {
            background: var(--blue-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(63, 116, 156, 0.35);
        }
        .login-btn i {
            font-size: 16px;
        }

        /* Help text */
        .help-text {
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .help-text i {
            font-size: 14px;
            color: var(--blue);
        }
        .help-text a {
            color: var(--blue);
            text-decoration: underline;
            font-weight: 600;
        }

        /* Social Icons */
        .social-links {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .social-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--blue);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(63, 116, 156, 0.3);
        }
        .social-btn:hover {
            background: var(--lime);
            color: var(--blue-dark);
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 16px rgba(197, 234, 79, 0.4);
        }

        /* Tab Switcher (hidden by default) */
        .tab-switcher {
            display: none;
            gap: 8px;
            margin-bottom: 20px;
        }
        .tab-switcher.visible {
            display: flex;
        }
        .tab-btn {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .tab-btn i {
            font-size: 14px;
        }
        .tab-btn.active {
            background: var(--blue);
            color: #fff;
            border-color: var(--blue);
        }
        .tab-btn:not(.active):hover {
            border-color: var(--blue);
            color: var(--blue);
            background: var(--blue-light);
        }

        .form-panel {
            display: none;
        }
        .form-panel.active {
            display: block;
        }

        /* Hidden admin trigger */
        .admin-trigger {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 30px;
            height: 30px;
            background: transparent;
            border: none;
            cursor: pointer;
            z-index: 100;
            opacity: 0;
        }

        /* Remember me checkbox */
        .remember-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .remember-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--blue);
            cursor: pointer;
        }
        .remember-wrapper label {
            cursor: pointer;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 420px;
            }
            .login-left {
                min-height: 180px;
                padding: 30px;
            }
            .curve-top {
                width: 150px;
                height: 150px;
                top: -35px;
                right: -25px;
            }
            .school-logo {
                width: 90px;
                height: 90px;
            }
            .school-name {
                top: 90px;
                right: 15px;
                font-size: 10px;
            }
            .curve-bottom {
                width: 130px;
                height: 130px;
                bottom: -25px;
                left: -25px;
            }
            .login-right {
                padding: 32px 28px;
            }
        }

        /* Loading spinner */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }
    </style>
</head>
<body>
    <!-- Hidden admin trigger -->
    <button class="admin-trigger" onclick="toggleAdminAccess()"></button>

    <div class="login-wrapper">
        <!-- Left Side -->
        <div class="login-left">
            <!-- Top curve with logo -->
            <div class="curve-top">
                <img src="assets/images/logo.png" alt="School Logo" class="school-logo"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22><circle cx=%2260%22 cy=%2260%22 r=%2255%22 fill=%22%23c5ea4f%22/><text x=%2260%22 y=%2270%22 text-anchor=%22middle%22 fill=%22%233f749c%22 font-size=%2240%22 font-weight=%22bold%22>5G</text></svg>'">
            </div>
            <div class="school-name">
                5G E-GURUSCHOOL<br>
                <span style="font-size: 9px; font-weight: 400; opacity: 0.8;">Student Portal</span>
            </div>

            <!-- Bottom curve with illustration -->
            <div class="curve-bottom">
                <svg width="140" height="140" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                    <!-- Books stack -->
                    <rect x="45" y="125" width="110" height="18" rx="3" fill="#3f749c" opacity="0.3"/>
                    <rect x="50" y="107" width="100" height="18" rx="3" fill="#3f749c" opacity="0.5"/>
                    <rect x="55" y="89" width="90" height="18" rx="3" fill="#c5ea4f" opacity="0.8"/>
                    <!-- Students -->
                    <circle cx="75" cy="65" r="14" fill="#fff"/>
                    <rect x="62" y="79" width="26" height="32" rx="4" fill="#fff"/>
                    <circle cx="125" cy="65" r="14" fill="#fff"/>
                    <rect x="112" y="79" width="26" height="32" rx="4" fill="#fff"/>
                    <!-- Graduation cap -->
                    <polygon points="100,30 88,40 112,40" fill="#c5ea4f"/>
                    <rect x="96" y="40" width="8" height="7" fill="#c5ea4f"/>
                    <!-- Sparkles -->
                    <circle cx="35" cy="50" r="3" fill="#c5ea4f" opacity="0.7"/>
                    <circle cx="165" cy="45" r="2" fill="#c5ea4f" opacity="0.7"/>
                    <circle cx="170" cy="100" r="3" fill="#fff" opacity="0.6"/>
                    <circle cx="38" cy="105" r="2" fill="#fff" opacity="0.6"/>
                </svg>
            </div>
        </div>

        <!-- Right Side -->
        <div class="login-right">
            <?php if ($show_admin): ?>
            <div class="tab-switcher visible">
                <button type="button" class="tab-btn <?php echo $active_tab === 'student' ? 'active' : ''; ?>" data-tab="student">
                    <i class="fas fa-user-graduate"></i> Student
                </button>
                <button type="button" class="tab-btn <?php echo $active_tab === 'admin' ? 'active' : ''; ?>" data-tab="admin">
                    <i class="fas fa-user-shield"></i> Admin
                </button>
            </div>
            <?php endif; ?>

            <!-- Student Panel -->
            <div class="form-panel <?php echo $active_tab === 'student' ? 'active' : ''; ?>" id="student-panel">
                <h1 class="login-title"><i class="fas fa-sign-in-alt" style="color: var(--blue); margin-right: 10px;"></i>Login</h1>

                <?php if (!empty($student_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($student_error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="studentForm">
                    <input type="hidden" name="login_type" value="student">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- School ID with icon -->
                    <div class="form-group">
                        <div class="input-icon-wrapper">
                            <i class="fas fa-id-card input-icon"></i>
                            <input type="text" name="username" class="form-input" 
                                   placeholder="School ID / Matric Number" required autofocus>
                        </div>
                    </div>

                    <!-- Password with icon and toggle -->
                    <div class="form-group">
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" class="form-input" 
                                   id="student-password" placeholder="Password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('student-password', this)" title="Show/Hide Password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember me -->
                    <div class="remember-wrapper">
                        <input type="checkbox" name="remember" id="remember">
                        <label for="remember"><i class="fas fa-check-circle" style="color: var(--blue); margin-right: 4px;"></i> Remember me</label>
                    </div>

                    <!-- Forgot password with icon -->
                    <a href="forgot-password.php" class="forgot-link">
                        <i class="fas fa-key"></i> forgot Password?
                    </a>

                    <!-- Login button with icon -->
                    <button type="submit" class="login-btn" id="student-submit">
                        <i class="fas fa-sign-in-alt"></i> Log In
                    </button>
                </form>

                <!-- Help text with icon -->
                <p class="help-text">
                    <i class="fas fa-question-circle"></i>
                    Can't find your School ID? <a href="contact.php">Contact US</a>
                </p>

               
            </div>

            <!-- Admin Panel (hidden) -->
            <div class="form-panel <?php echo $active_tab === 'admin' ? 'active' : ''; ?>" id="admin-panel">
                <h1 class="login-title" style="font-size: 24px;">
                    <i class="fas fa-lock" style="color: var(--blue);"></i> Admin Access
                </h1>

                <?php if (!empty($admin_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($admin_error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="adminForm">
                    <input type="hidden" name="login_type" value="admin">

                    <div class="form-group">
                        <div class="input-icon-wrapper">
                            <i class="fas fa-user-shield input-icon"></i>
                            <input type="text" name="admin_username" class="form-input" 
                                   placeholder="Admin Username" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="admin_password" class="form-input" 
                                   id="admin-password" placeholder="Admin Password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('admin-password', this)" title="Show/Hide Password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-btn" id="admin-submit">
                        <i class="fas fa-sign-in-alt"></i> Access Dashboard
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.form-panel').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(tab + '-panel').classList.add('active');
            });
        });

        // Password toggle function
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                btn.title = 'Hide Password';
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                btn.title = 'Show Password';
            }
        }

        // Form loading state
        document.getElementById('studentForm').addEventListener('submit', function() {
            const btn = document.getElementById('student-submit');
            btn.innerHTML = '<span class="spinner"></span> Signing In...';
            btn.disabled = true;
        });

        // Admin access
        function toggleAdminAccess() {
            window.location.href = '?admin=kowaguru2026';
        }

        // Keyboard shortcut: Ctrl+Shift+A
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                window.location.href = '?admin=kowaguru2026';
            }
        });
    </script>
    <?php include 'preloader.php'; ?>
</body>
</html>