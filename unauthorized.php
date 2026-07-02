<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
        }
        
        .container {
            max-width: 800px;
            width: 100%;
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin: 20px;
        }
        
        .header {
            background: linear-gradient(to right, #d32f2f, #b71c1c);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .header p {
            font-size: 1.2rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .content {
            padding: 40px;
        }
        
        .error-details {
            background-color: #f9f9f9;
            border-left: 5px solid #d32f2f;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 0 8px 8px 0;
        }
        
        .error-details h3 {
            color: #d32f2f;
            margin-bottom: 10px;
            font-size: 1.3rem;
        }
        
        .error-code {
            display: inline-block;
            background-color: #e8e8e8;
            padding: 5px 15px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .possible-reasons {
            margin-bottom: 30px;
        }
        
        .possible-reasons h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .reason-list {
            list-style-type: none;
        }
        
        .reason-list li {
            padding: 10px 0 10px 35px;
            position: relative;
            border-bottom: 1px solid #eee;
        }
        
        .reason-list li:last-child {
            border-bottom: none;
        }
        
        .reason-list li i {
            position: absolute;
            left: 0;
            top: 10px;
            color: #d32f2f;
        }
        
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 1rem;
            border: none;
        }
        
        .btn-primary {
            background-color: #d32f2f;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #b71c1c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(183, 28, 28, 0.2);
        }
        
        .btn-secondary {
            background-color: #f0f0f0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }
        
        .contact-info {
            margin-top: 40px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        .contact-info h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .contact-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .contact-item i {
            color: #d32f2f;
            font-size: 1.2rem;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: #777;
            font-size: 0.9rem;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        
        .logo span {
            color: #d32f2f;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2.2rem;
            }
            
            .header p {
                font-size: 1.1rem;
            }
            
            .content {
                padding: 30px 25px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .header {
                padding: 25px 20px;
            }
            
            .header h1 {
                font-size: 1.8rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .header p {
                font-size: 1rem;
            }
            
            .content {
                padding: 25px 20px;
            }
            
            .contact-details {
                flex-direction: column;
                gap: 15px;
            }
        }
        
        /* Animation for the lock icon */
        @keyframes lockShake {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-10deg); }
            75% { transform: rotate(10deg); }
        }
        
        .fa-lock {
            animation: lockShake 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-lock"></i> Access Denied</h1>
            <p>You don't have permission to access this page. Please contact the administrator if you believe this is an error.</p>
        </div>
        
        <div class="content">
            <div class="error-details">
                <h3>Error Details</h3>
                <p>The server denied your request to access the requested resource. This could be due to insufficient permissions or an authentication error.</p>
                <p class="error-code">Error Code: 403 Forbidden</p>
            </div>
            
            <div class="possible-reasons">
                <h3>Possible Reasons</h3>
                <ul class="reason-list">
                    <li><i class="fas fa-exclamation-circle"></i> You are not logged in or your session has expired.</li>
                    <li><i class="fas fa-exclamation-circle"></i> Your account does not have the required permissions.</li>
                    <li><i class="fas fa-exclamation-circle"></i> The resource is restricted to specific user roles.</li>
                    <li><i class="fas fa-exclamation-circle"></i> The page has been moved or deleted.</li>
                    <li><i class="fas fa-exclamation-circle"></i> There may be a technical issue with the server configuration.</li>
                </ul>
            </div>
            
            <div class="actions">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Return to Homepage
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Login Again
                </a>
            </div>
            
            <div class="contact-info">
                <h3>Contact Administrator</h3>
                <div class="contact-details">
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>admin@example.com</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span>+1 (555) 123-4567</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <span>Monday - Friday, 9am - 5pm EST</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <div class="logo">Secure<span>Access</span></div>
        <p>&copy; 2023 SecureAccess System. All rights reserved.</p>
        <p>Error ID: #AD-403-<?php echo rand(100000, 999999); ?></p>
    </div>

    <script>
        // Add functionality to buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Homepage button
            document.querySelectorAll('.btn-secondary')[0].addEventListener('click', function(e) {
                e.preventDefault();
                alert('Redirecting to homepage...');
                // In a real implementation, this would redirect to the homepage
                // window.location.href = '/';
            });
             
            
            // Generate a random error ID for display
            const errorIdElement = document.querySelector('.footer p:last-child');
            if (errorIdElement) {
                const randomId = Math.floor(100000 + Math.random() * 900000);
                errorIdElement.textContent = `Error ID: #AD-403-${randomId}`;
            }
            
            // Animate the lock icon on hover
            const lockIcon = document.querySelector('.fa-lock');
            lockIcon.addEventListener('mouseenter', function() {
                this.style.animation = 'lockShake 0.5s ease-in-out';
            });
            
            lockIcon.addEventListener('mouseleave', function() {
                this.style.animation = 'lockShake 2s ease-in-out infinite';
            });
        });
    </script>
</body>
</html>