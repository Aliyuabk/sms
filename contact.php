<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sms');

$error = '';
$success = '';
$submitted = false;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $matric_number = trim($_POST['matric_number'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $category = $_POST['category'] ?? 'General';

        if (empty($name) || empty($email) || empty($subject) || empty($message)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($message) < 10) {
            $error = 'Message must be at least 10 characters long.';
        } else {
            try {
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if ($conn->connect_error) throw new Exception('DB connection failed');

                // Insert into a contact_messages table (create if not exists)
                $createTable = "CREATE TABLE IF NOT EXISTS `contact_messages` (
                    `message_id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL,
                    `email` varchar(100) NOT NULL,
                    `matric_number` varchar(20) DEFAULT NULL,
                    `phone` varchar(20) DEFAULT NULL,
                    `category` enum('General','School ID Issue','Login Problem','Password Reset','Fee Payment','Course Registration','Results','Hostel','Other') DEFAULT 'General',
                    `subject` varchar(200) NOT NULL,
                    `message` text NOT NULL,
                    `status` enum('New','In Progress','Resolved','Closed') DEFAULT 'New',
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    `resolved_at` datetime DEFAULT NULL,
                    `resolved_by` int(11) DEFAULT NULL,
                    PRIMARY KEY (`message_id`),
                    KEY `email` (`email`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $conn->query($createTable);

                $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, matric_number, phone, category, subject, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssss', $name, $email, $matric_number, $phone, $category, $subject, $message);
                $stmt->execute();
                $stmt->close();
                $conn->close();

                // Send notification email to admin (optional)
                $admin_email = 'aliyuabubakar11117@gmail.com'; // Your admin email
                $email_subject = "New Contact Form Submission - " . $subject;
                $email_body = "Name: $name\nEmail: $email\nMatric: $matric_number\nPhone: $phone\nCategory: $category\n\nMessage:\n$message";
                @mail($admin_email, $email_subject, $email_body);

                $success = 'Your message has been sent successfully! We will get back to you within 24-48 hours.';
                $submitted = true;

            } catch (Exception $e) {
                error_log('Contact form error: ' . $e->getMessage());
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
    <title>Contact Us | 5G E-GURUSCHOOL</title>
    <link rel="shortcut icon" href="assets/images/logo.jpeg">
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
            max-width: 900px;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(63, 116, 156, 0.15);
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            min-height: 600px;
        }

        /* Left Side - Info */
        .info-side {
            background: var(--blue);
            padding: 48px 40px;
            color: #fff;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .info-side::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 250px;
            height: 250px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .info-side::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 200px;
            height: 200px;
            background: rgba(197, 234, 79, 0.15);
            border-radius: 50%;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }

        .logo-img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.3);
            background: #fff;
        }

        .logo-text h2 {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.3;
        }

        .logo-text span {
            font-size: 12px;
            opacity: 0.85;
            font-weight: 400;
        }

        .info-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .info-desc {
            font-size: 15px;
            line-height: 1.7;
            opacity: 0.9;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }

        .contact-info-list {
            list-style: none;
            position: relative;
            z-index: 1;
        }

        .contact-info-list li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px;
        }

        .contact-info-list li i {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .contact-info-list li div strong {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .contact-info-list li div span,
        .contact-info-list li div a {
            font-size: 14px;
            opacity: 0.85;
            line-height: 1.5;
            color: #fff;
            text-decoration: none;
        }

        .contact-info-list li div a:hover {
            text-decoration: underline;
            opacity: 1;
        }

        .social-links {
            display: flex;
            gap: 12px;
            margin-top: auto;
            position: relative;
            z-index: 1;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background: var(--lime);
            color: var(--blue-dark);
            transform: translateY(-3px);
        }

        /* Right Side - Form */
        .form-side {
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            max-height: 90vh;
        }

        .form-header {
            margin-bottom: 28px;
        }

        .form-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-header h2 i {
            color: var(--blue);
        }

        .form-header p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 6px;
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

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .form-label .required {
            color: var(--danger);
            margin-left: 2px;
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
            font-size: 15px;
            transition: color 0.2s;
            z-index: 2;
        }

        .textarea-icon {
            top: 14px;
            transform: none;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s;
            background: #fff;
            font-family: 'Inter', sans-serif;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px var(--blue-light);
        }

        .form-input:focus + .input-icon,
        .input-wrapper:focus-within .input-icon {
            color: var(--blue);
        }

        .form-input::placeholder,
        .form-textarea::placeholder {
            color: #9ca3af;
        }

        .form-select {
            padding-left: 42px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .form-textarea {
            padding: 12px 14px 12px 42px;
            min-height: 120px;
            resize: vertical;
            line-height: 1.6;
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
            margin-top: 8px;
        }

        .submit-btn:hover {
            background: var(--blue-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(63, 116, 156, 0.35);
        }

        /* Success State */
        .success-state {
            text-align: center;
            padding: 40px 20px;
        }

        .success-state i {
            font-size: 72px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .success-state h3 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 12px;
        }

        .success-state p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .ticket-info {
            background: var(--blue-light);
            border: 1px solid var(--blue);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 24px;
            display: inline-block;
        }

        .ticket-info strong {
            color: var(--blue-dark);
            font-size: 14px;
        }

        /* Back Link */
        .back-link {
            text-align: center;
            margin-top: 20px;
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            .info-side {
                padding: 32px 28px;
                min-height: auto;
            }
            .form-side {
                padding: 32px 28px;
                max-height: none;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .social-links {
                margin-top: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Side - Contact Info -->
        <div class="info-side">
            <div class="logo-section">
                <img src="assets/images/logo.jpeg" alt="School Logo" class="logo-img"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22><circle cx=%2228%22 cy=%2228%22 r=%2226%22 fill=%22%23c5ea4f%22/><text x=%2228%22 y=%2234%22 text-anchor=%22middle%22 fill=%22%233f749c%22 font-size=%2218%22 font-weight=%22bold%22>5G</text></svg>'">
                <div class="logo-text">
                    <h2>5G E-GURUSCHOOL</h2>
                    <span>Student Portal Support</span>
                </div>
            </div>

            <h1 class="info-title"><i class="fas fa-headset"></i> Get in Touch</h1>
            <p class="info-desc">
                Need help finding your School ID or have other concerns? Our support team is here to assist you. Fill out the form and we'll respond within 24-48 hours.
            </p>

            <ul class="contact-info-list">
                <li>
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <strong>School Address</strong>
                        <span>Faculty of Computing Building Room 3<br>Birnin Kudu, Jigawa State, Nigeria</span>
                    </div>
                </li>
                <li>
                    <i class="fas fa-envelope"></i>
                    <div>
                        <strong>Email Us</strong>
                        <a href="mailto:aliyuabubakar11117@gmail.com">aliyuabubakar11117@gmail.com</a>
                    </div>
                </li>
                <li>
                    <i class="fas fa-phone-alt"></i>
                    <div>
                        <strong>Call Us</strong>
                        <a href="tel:+2348034897634">+234 803 489 7634</a>
                    </div>
                </li>
                <li>
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>Working Hours</strong>
                        <span>Monday - Friday: 8:00 AM - 4:00 PM<br>Saturday: 9:00 AM - 12:00 PM</span>
                    </div>
                </li>
            </ul>

            <div class="social-links">
                <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
            </div>
        </div>

        <!-- Right Side - Form -->
        <div class="form-side">
            <?php if ($submitted): ?>
                <!-- Success State -->
                <div class="success-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>Message Sent Successfully!</h3>
                    <p><?php echo htmlspecialchars($success); ?></p>
                    <div class="ticket-info">
                        <strong><i class="fas fa-ticket-alt"></i> Ticket Reference: #<?php echo time(); ?></strong>
                    </div>
                    <a href="index.php" class="submit-btn" style="text-decoration: none; max-width: 250px; margin: 0 auto;">
                        <i class="fas fa-sign-in-alt"></i> Back to Login
                    </a>
                    <div class="back-link">
                        <a href="contact.php">
                            <i class="fas fa-redo"></i> Send Another Message
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Contact Form -->
                <div class="form-header">
                    <h2><i class="fas fa-paper-plane"></i> Send a Message</h2>
                    <p>Fill out the form below and we'll get back to you as soon as possible.</p>
                </div>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="contactForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="form-grid">
                        <!-- Full Name -->
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" name="name" class="form-input" placeholder="Your full name" 
                                       required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label class="form-label">Email Address <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" class="form-input" placeholder="your@email.com" 
                                       required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Matric Number -->
                        <div class="form-group">
                            <label class="form-label">School ID / Matric Number</label>
                            <div class="input-wrapper">
                                <i class="fas fa-id-card input-icon"></i>
                                <input type="text" name="matric_number" class="form-input" placeholder="e.g., KGT001" 
                                       value="<?php echo isset($_POST['matric_number']) ? htmlspecialchars($_POST['matric_number']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="tel" name="phone" class="form-input" placeholder="e.g., 08012345678" 
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="form-group full-width">
                            <label class="form-label">Issue Category <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-tag input-icon"></i>
                                <select name="category" class="form-select" required>
                                    <option value="General" <?php echo (isset($_POST['category']) && $_POST['category'] == 'General') ? 'selected' : ''; ?>>General Inquiry</option>
                                    <option value="School ID Issue" <?php echo (isset($_POST['category']) && $_POST['category'] == 'School ID Issue') ? 'selected' : ''; ?>>Can't Find School ID</option>
                                    <option value="Login Problem" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Login Problem') ? 'selected' : ''; ?>>Login Problem</option>
                                    <option value="Password Reset" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Password Reset') ? 'selected' : ''; ?>>Password Reset</option>
                                    <option value="Fee Payment" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Fee Payment') ? 'selected' : ''; ?>>Fee Payment Issue</option>
                                    <option value="Course Registration" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Course Registration') ? 'selected' : ''; ?>>Course Registration</option>
                                    <option value="Results" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Results') ? 'selected' : ''; ?>>Results Issue</option>
                                    <option value="Hostel" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Hostel') ? 'selected' : ''; ?>>Hostel Allocation</option>
                                    <option value="Other" <?php echo (isset($_POST['category']) && $_POST['category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <!-- Subject -->
                        <div class="form-group full-width">
                            <label class="form-label">Subject <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-heading input-icon"></i>
                                <input type="text" name="subject" class="form-input" placeholder="Brief description of your issue" 
                                       required value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Message -->
                        <div class="form-group full-width">
                            <label class="form-label">Message <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-comment-alt textarea-icon input-icon"></i>
                                <textarea name="message" class="form-textarea" placeholder="Describe your issue in detail... Please include any relevant information that might help us assist you faster." 
                                          required minlength="10"><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>

                <div class="back-link">
                    <a href="index.php">
                        <i class="fas fa-arrow-left"></i> Back to Login Page
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Form loading state
        document.getElementById('contactForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<span class="spinner"></span> Sending...';
            btn.disabled = true;
        });
    </script>
</body>
</html>