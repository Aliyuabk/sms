<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sms');

$error = '';
$success = '';
$token_valid = false;
$token = $_GET['token'] ?? '';

// Validate token
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) throw new Exception('DB connection failed');

        $stmt = $conn->prepare("
            SELECT pr.email, pr.expires_at, pr.used, s.student_id, s.first_name 
            FROM password_resets pr
            JOIN students s ON pr.email = s.email
            WHERE pr.token = ? AND s.status = 'Active'
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $reset_data = $result->fetch_assoc();

            if ($reset_data['used'] == 1) {
                $error = 'This reset link has already been used. Please request a new one.';
            } elseif (strtotime($reset_data['expires_at']) < time()) {
                $error = 'This reset link has expired. Please request a new one.';
            } else {
                $token_valid = true;
                $_SESSION['reset_email'] = $reset_data['email'];
                $_SESSION['reset_student_id'] = $reset_data['student_id'];
                $_SESSION['reset_token'] = $token;
            }
        } else {
            $error = 'Invalid reset token. Please request a new password reset.';
        }

        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        error_log('Token validation error: ' . $e->getMessage());
        $error = 'System error. Please try again later.';
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one number.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) throw new Exception('DB connection failed');

            // Hash new password
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            // Update student password
            $updStmt = $conn->prepare("UPDATE students SET password_hash = ? WHERE student_id = ?");
            $updStmt->bind_param('si', $password_hash, $_SESSION['reset_student_id']);
            $updStmt->execute();
            $updStmt->close();

            // Mark token as used
            $useStmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
            $useStmt->bind_param('s', $_SESSION['reset_token']);
            $useStmt->execute();
            $useStmt->close();

            $conn->close();

            // Clear session
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_student_id']);
            unset($_SESSION['reset_token']);

            $success = 'Your password has been reset successfully. You can now log in with your new password.';

        } catch (Exception $e) {
            error_log('Password reset error: ' . $e->getMessage());
            $error = 'System error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | 5G E-GURUSCHOOL</title>
    <link rel="shortcut icon" href="assets/images/logo.jpeg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue: #3f749c;
            --blue-dark: #2d5a7a;
            --blue-light: #e8f2f8;
            --lime: #c5ea4f;
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

        .container {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(63, 116, 156, 0.15);
        }

        .header {
            background: var(--blue);
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .logo-wrapper {
            width: 80px;
            height: 80px;
            background: #fff;
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            position: relative;
            z-index: 1;
        }

        .logo-wrapper img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .header h1 {
            color: #fff;
            font-size: 22px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .body {
            padding: 40px 32px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: var(--blue);
        }

        .page-desc {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.6;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
        }
        .alert i {
            margin-top: 2px;
            font-size: 16px;
            flex-shrink: 0;
        }
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-danger i { color: var(--danger); }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .alert-success i { color: var(--success); }

        .form-group {
            margin-bottom: 20px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            transition: color 0.2s;
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
            font-family: 'Inter', sans-serif;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px var(--blue-light);
        }

        .form-input:focus + .input-icon,
        .input-wrapper:focus-within .input-icon {
            color: var(--blue);
        }

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

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
            display: none;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: all 0.3s;
        }

        .strength-weak { background: var(--danger); width: 33%; }
        .strength-medium { background: var(--warning); width: 66%; }
        .strength-strong { background: var(--success); width: 100%; }

        .password-hints {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
            padding-left: 4px;
        }

        .password-hints li {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .password-hints li.valid {
            color: var(--success);
        }

        .password-hints li i {
            font-size: 10px;
        }

        .submit-btn {
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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: 'Inter', sans-serif;
        }

        .submit-btn:hover {
            background: var(--blue-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(63, 116, 156, 0.35);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .back-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .back-link a {
            color: var(--blue);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .success-illustration {
            text-align: center;
            margin-bottom: 24px;
        }

        .success-illustration i {
            font-size: 64px;
            color: var(--success);
        }

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

        @media (max-width: 480px) {
            .body { padding: 32px 24px; }
            .header { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-wrapper">
                <img src="assets/images/logo.jpeg" alt="School Logo"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22><circle cx=%2230%22 cy=%2230%22 r=%2228%22 fill=%22%23c5ea4f%22/><text x=%2230%22 y=%2238%22 text-anchor=%22middle%22 fill=%22%233f749c%22 font-size=%2220%22 font-weight=%22bold%22>5G</text></svg>'">
            </div>
            <h1><i class="fas fa-key"></i> Reset Password</h1>
        </div>

        <div class="body">
            <?php if (!empty($success)): ?>
                <!-- Success State -->
                <div class="success-illustration">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="page-title" style="justify-content: center;">Success!</h2>
                <p class="page-desc" style="text-align: center;"><?php echo htmlspecialchars($success); ?></p>
                <a href="index.php" class="submit-btn" style="text-decoration: none;">
                    <i class="fas fa-sign-in-alt"></i> Go to Login
                </a>

            <?php elseif (!$token_valid): ?>
                <!-- Error State -->
                <div class="success-illustration">
                    <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                </div>
                <h2 class="page-title" style="justify-content: center;">Link Invalid</h2>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <a href="forgot-password.php" class="submit-btn" style="text-decoration: none;">
                    <i class="fas fa-redo"></i> Request New Link
                </a>

            <?php else: ?>
                <!-- Reset Form -->
                <h2 class="page-title">
                    <i class="fas fa-lock"></i> Create New Password
                </h2>
                <p class="page-desc">
                    Enter a strong new password for your account. Make sure it's different from your previous password.
                </p>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="resetForm">
                    <!-- New Password -->
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" class="form-input" 
                                   id="password" placeholder="New Password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="strength-bar">
                            <div class="password-strength-bar" id="strength-bar-inner"></div>
                        </div>
                        <ul class="password-hints" id="password-hints">
                            <li id="hint-length"><i class="fas fa-circle"></i> At least 8 characters</li>
                            <li id="hint-upper"><i class="fas fa-circle"></i> One uppercase letter</li>
                            <li id="hint-lower"><i class="fas fa-circle"></i> One lowercase letter</li>
                            <li id="hint-number"><i class="fas fa-circle"></i> One number</li>
                        </ul>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="confirm_password" class="form-input" 
                                   id="confirm-password" placeholder="Confirm New Password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm-password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>

            <div class="back-link">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        // Password toggle
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strength-bar');
        const strengthBarInner = document.getElementById('strength-bar-inner');
        const submitBtn = document.getElementById('submitBtn');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const val = this.value;
                strengthBar.style.display = val.length > 0 ? 'block' : 'none';

                let strength = 0;
                document.getElementById('hint-length').classList.toggle('valid', val.length >= 8);
                document.getElementById('hint-upper').classList.toggle('valid', /[A-Z]/.test(val));
                document.getElementById('hint-lower').classList.toggle('valid', /[a-z]/.test(val));
                document.getElementById('hint-number').classList.toggle('valid', /[0-9]/.test(val));

                if (val.length >= 8) strength++;
                if (/[A-Z]/.test(val)) strength++;
                if (/[a-z]/.test(val)) strength++;
                if (/[0-9]/.test(val)) strength++;

                strengthBarInner.className = 'password-strength-bar';
                if (strength <= 1) strengthBarInner.classList.add('strength-weak');
                else if (strength <= 3) strengthBarInner.classList.add('strength-medium');
                else strengthBarInner.classList.add('strength-strong');
            });
        }

        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm-password').value;

            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match!');
                return;
            }

            submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>