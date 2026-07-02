<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sms');

$error = '';
$success = '';
$email_sent = false;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if ($conn->connect_error) throw new Exception('DB connection failed');

                // Check if email exists in students table
                $stmt = $conn->prepare("SELECT student_id, first_name, email FROM students WHERE email = ? AND status = 'Active'");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $student = $result->fetch_assoc();

                    // Generate secure token
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // Delete any existing unused tokens for this email
                    $delStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ? AND used = 0");
                    $delStmt->bind_param('s', $email);
                    $delStmt->execute();
                    $delStmt->close();

                    // Insert new token
                    $insStmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                    $insStmt->bind_param('sss', $email, $token, $expires);
                    $insStmt->execute();
                    $insStmt->close();

                    // Build reset link
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $reset_link = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']) . '/reset-password.php?token=' . $token;

                    // Send email (using mail() function - replace with PHPMailer for production)
                    $to = $email;
                    $subject = 'Password Reset - 5G E-GURUSCHOOL';

                    $message = "
                    <html>
                    <head>
                        <style>
                            body { font-family: 'Inter', Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 20px; }
                            .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                            .header { background: #3f749c; padding: 30px; text-align: center; }
                            .header h1 { color: #fff; margin: 0; font-size: 22px; }
                            .content { padding: 40px 30px; }
                            .content p { color: #1e293b; line-height: 1.7; font-size: 15px; }
                            .btn { display: inline-block; background: #3f749c; color: #fff; padding: 14px 32px; text-decoration: none; border-radius: 10px; font-weight: 600; margin: 20px 0; }
                            .btn:hover { background: #2d5a7a; }
                            .footer { background: #f8fafc; padding: 20px 30px; text-align: center; font-size: 13px; color: #64748b; }
                            .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 20px 0; border-radius: 6px; font-size: 13px; color: #92400e; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1><i class='fas fa-graduation-cap'></i> 5G E-GURUSCHOOL</h1>
                            </div>
                            <div class='content'>
                                <p>Hello <strong>" . htmlspecialchars($student['first_name']) . "</strong>,</p>
                                <p>We received a request to reset your password for your student portal account. Click the button below to create a new password:</p>
                                <center><a href='" . $reset_link . "' class='btn'>Reset My Password</a></center>
                                <div class='warning'>
                                    <strong>⚠️ Important:</strong> This link will expire in <strong>1 hour</strong> and can only be used once.
                                </div>
                                <p>If you didn't request this password reset, please ignore this email. Your account remains secure.</p>
                                <p style='font-size: 13px; color: #64748b; margin-top: 30px;'>
                                    If the button doesn't work, copy and paste this link into your browser:<br>
                                    <a href='" . $reset_link . "' style='color: #3f749c; word-break: break-all;'>" . $reset_link . "</a>
                                </p>
                            </div>
                            <div class='footer'>
                                <p>5G E-GURUSCHOOL Student Portal</p>
                                <p style='font-size: 12px;'>This is an automated message. Please do not reply to this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";

                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: noreply@eguruschool.ng" . "\r\n";
                    $headers .= "Reply-To: support@eguruschool.ng" . "\r\n";

                    // For production, use PHPMailer or SMTP
                    // mail($to, $subject, $message, $headers);

                    // For demo/development: show the link (remove in production)
                    $success = 'Password reset instructions have been sent to your email address.';
                    $email_sent = true;

                    // Store reset link for demo display (remove in production)
                    $_SESSION['demo_reset_link'] = $reset_link;
                } else {
                    // Don't reveal if email exists or not for security
                    $success = 'If this email is registered, you will receive password reset instructions shortly.';
                    $email_sent = true;
                }

                $stmt->close();
                $conn->close();

            } catch (Exception $e) {
                error_log('Password reset error: ' . $e->getMessage());
                $error = 'System error. Please try again later.';
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | 5G E-GURUSCHOOL</title>
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
            --warning: #f59e0b;
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

        /* Header */
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

        .header::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 100px;
            height: 100px;
            background: rgba(197, 234, 79, 0.2);
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

        .header p {
            color: rgba(255,255,255,0.85);
            font-size: 13px;
            margin-top: 6px;
            position: relative;
            z-index: 1;
        }

        /* Body */
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

        /* Alerts */
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

        /* Form */
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

        .form-input::placeholder {
            color: #9ca3af;
        }

        /* Submit Button */
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

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn i {
            font-size: 16px;
        }

        /* Back to login */
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
            transition: color 0.2s;
        }

        .back-link a:hover {
            color: var(--blue-dark);
            text-decoration: underline;
        }

        /* Demo link box (remove in production) */
        .demo-box {
            margin-top: 20px;
            padding: 16px;
            background: #fefce8;
            border: 1px solid #fde047;
            border-radius: 10px;
            font-size: 13px;
        }

        .demo-box strong {
            color: #854d0e;
            display: block;
            margin-bottom: 8px;
        }

        .demo-box a {
            color: var(--blue);
            word-break: break-all;
            text-decoration: none;
        }

        .demo-box a:hover {
            text-decoration: underline;
        }

        /* Success illustration */
        .success-illustration {
            text-align: center;
            margin-bottom: 24px;
        }

        .success-illustration i {
            font-size: 64px;
            color: var(--success);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .body {
                padding: 32px 24px;
            }
            .header {
                padding: 32px 24px;
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
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-wrapper">
                <img src="assets/images/logo.jpeg" alt="School Logo"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22><circle cx=%2230%22 cy=%2230%22 r=%2228%22 fill=%22%23c5ea4f%22/><text x=%2230%22 y=%2238%22 text-anchor=%22middle%22 fill=%22%233f749c%22 font-size=%2220%22 font-weight=%22bold%22>5G</text></svg>'">
            </div>
            <h1><i class="fas fa-shield-alt"></i> Password Recovery</h1>
            <p>5G E-GURUSCHOOL Student Portal</p>
        </div>

        <!-- Body -->
        <div class="body">
            <?php if (!$email_sent): ?>
                <!-- Request Form -->
                <h2 class="page-title">
                    <i class="fas fa-envelope"></i> Forgot Password?
                </h2>
                <p class="page-desc">
                    Enter your registered email address below and we'll send you a secure link to reset your password.
                </p>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="email" class="form-input" 
                                   placeholder="Enter your email address" 
                                   required autofocus
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>

            <?php else: ?>
                <!-- Success State -->
                <div class="success-illustration">
                    <i class="fas fa-check-circle"></i>
                </div>

                <h2 class="page-title" style="justify-content: center;">
                    Check Your Email
                </h2>
                <p class="page-desc" style="text-align: center;">
                    <?php echo htmlspecialchars($success); ?>
                </p>

                <div class="alert alert-success">
                    <i class="fas fa-info-circle"></i>
                    <span>Please check your inbox (and spam folder) for the reset link. The link will expire in 1 hour.</span>
                </div>

                <!-- DEMO ONLY: Show reset link (Remove in production) -->
                <?php if (isset($_SESSION['demo_reset_link'])): ?>
                <div class="demo-box">
                    <strong><i class="fas fa-bug"></i> Development Mode - Reset Link:</strong>
                    <a href="<?php echo htmlspecialchars($_SESSION['demo_reset_link']); ?>" target="_blank">
                        <?php echo htmlspecialchars($_SESSION['demo_reset_link']); ?>
                    </a>
                </div>
                <?php endif; ?>

            <?php endif; ?>

            <!-- Back to Login -->
            <div class="back-link">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i> Back to Login Page
                </a>
            </div>
        </div>
    </div>

    <script>
        // Form loading state
        document.getElementById('resetForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<span class="spinner"></span> Sending...';
            btn.disabled = true;
        });
    </script>
</body>
</html>