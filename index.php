<?php
/**
 * Madrasatu Masjid Landing Page
 * Dynamic statistics from database
 */

// Start session and output buffering
ob_start();
session_start();

// Error reporting (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once 'includes/database.php';

// ============================================================
// FETCH STATISTICS FROM DATABASE
// ============================================================

try {
    // Get total students count
    $studentStmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'Active'");
    $total_students = $studentStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get total staff count
    $staffStmt = $pdo->query("SELECT COUNT(*) as total FROM staff WHERE status = 'Active'");
    $total_staff = $staffStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get total classes/courses count
    $courseStmt = $pdo->query("SELECT COUNT(*) as total FROM courses");
    $total_courses = $courseStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get total departments
    $deptStmt = $pdo->query("SELECT COUNT(*) as total FROM departments");
    $total_departments = $deptStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get total programs
    $programStmt = $pdo->query("SELECT COUNT(*) as total FROM programs WHERE is_active = 1");
    $total_programs = $programStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get recent activity (last 5 students)
    $recentStmt = $pdo->query("
        SELECT student_id, first_name, last_name, registration_date 
        FROM students 
        WHERE status = 'Active' 
        ORDER BY registration_date DESC 
        LIMIT 5
    ");
    $recent_students = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Landing Page Error: " . $e->getMessage());
    $total_students = 0;
    $total_staff = 0;
    $total_courses = 0;
    $total_departments = 0;
    $total_programs = 0;
    $recent_students = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>مدرسة مسجد عبد الله بن عباس · Madrasatu masjid</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="shortcut icon" href="logo.png" type="image/x-icon">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1e4b6b;
            --primary-light: #3f749c;
            --primary-dark: #0f2e44;
            --gold: #d4af37;
            --gold-light: #f5e6b8;
            --green: #2e7d32;
            --cream: #fcf8f0;
            --white: #ffffff;
            --gray-100: #f5f3ef;
            --gray-200: #e8e4dc;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 15px 40px rgba(0, 0, 0, 0.12);
            --radius: 24px;
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Inter', 'Noto Kufi Arabic', sans-serif;
            background: var(--cream);
            color: #1f2a3a;
            line-height: 1.6;
            direction: ltr;
        }

        /* ===== HEADER ===== */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            z-index: 1000;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 3rem;
            transition: var(--transition);
        }

        .header.scrolled {
            box-shadow: var(--shadow);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .logo-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(145deg, var(--primary), var(--primary-light));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gold);
            box-shadow: 0 4px 12px rgba(30, 75, 107, 0.3);
            font-family: 'Noto Kufi Arabic', sans-serif;
            overflow: hidden;
        }

        .logo-icon img {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: -0.3px;
        }
        .logo-text span { color: var(--gold); }
        .logo-sub {
            font-size: 0.7rem;
            font-weight: 400;
            color: var(--primary-light);
            display: block;
            margin-top: -4px;
            letter-spacing: 0.3px;
        }

        .nav-links {
            display: flex;
            gap: 2.5rem;
            list-style: none;
            align-items: center;
        }
        .nav-links a {
            text-decoration: none;
            color: #3d4f5e;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            position: relative;
        }
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gold);
            transition: var(--transition);
        }
        .nav-links a:hover { color: var(--primary); }
        .nav-links a:hover::after { width: 100%; }

        .header-cta { display: flex; gap: 1rem; align-items: center; }

        .btn {
            padding: 0.6rem 1.6rem;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
        }
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30, 75, 107, 0.2);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 16px rgba(30, 75, 107, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(30, 75, 107, 0.4);
        }
        .btn-gold {
            background: linear-gradient(135deg, var(--gold), #b8962e);
            color: #1f2a3a;
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.4);
        }
        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.5);
        }
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.6rem;
            color: var(--primary);
            cursor: pointer;
        }

        /* ===== COUNTER ANIMATION ===== */
        .counter {
            display: inline-block;
            transition: all 0.3s ease;
        }

        /* ===== ARABIC HERO ===== */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: calc(80px + 2rem) 3rem 4rem;
            background: linear-gradient(145deg, var(--cream) 0%, #f0ede7 100%);
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 700px;
            height: 700px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero-container {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 4rem;
            align-items: center;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .hero-content h1 {
            font-size: 3.6rem;
            font-weight: 800;
            line-height: 1.15;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }
        .hero-content h1 .highlight {
            background: linear-gradient(135deg, var(--gold), #b8962e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .arabic-title {
            font-family: 'Noto Kufi Arabic', sans-serif;
            font-size: 2.4rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1rem;
            line-height: 1.3;
            direction: rtl;
        }
        .arabic-title span {
            color: var(--gold);
        }
        .hero-content p {
            font-size: 1.15rem;
            color: #3d5a6c;
            margin-bottom: 2rem;
            max-width: 520px;
            line-height: 1.8;
        }
        .madrasa-badge {
            display: inline-block;
            background: var(--gold-light);
            padding: 0.5rem 1.6rem;
            border-radius: 60px;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        .hero-stats {
            display: flex;
            gap: 2.5rem;
            margin-top: 2.5rem;
            flex-wrap: wrap;
        }
        .stat-item .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            display: block;
        }
        .stat-item .stat-label {
            font-size: 0.85rem;
            color: #5a6f7e;
        }

        /* Hero Cards */
        .hero-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .portal-card {
            background: white;
            border-radius: var(--radius);
            padding: 2rem 1.5rem;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            border: 1px solid rgba(212, 175, 55, 0.15);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .portal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--primary-light));
            opacity: 0;
            transition: var(--transition);
        }
        .portal-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.08);
            border-color: var(--gold);
        }
        .portal-card:hover::before { opacity: 1; }
        .portal-card .icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .portal-card.student .icon-wrap {
            background: #e3f0fa;
            color: var(--primary);
        }
        .portal-card.staff .icon-wrap {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .portal-card h3 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        .portal-card p {
            font-size: 0.85rem;
            color: #5a6f7e;
            margin-bottom: 1.2rem;
        }
        .portal-card .card-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
        }
        .portal-card .card-link i { transition: var(--transition); }
        .portal-card:hover .card-link i { transform: translateX(4px); }

        /* ===== FEATURES ===== */
        .features {
            padding: 6rem 3rem;
            background: white;
        }
        .section-header {
            text-align: center;
            max-width: 650px;
            margin: 0 auto 4rem;
        }
        .section-tag {
            display: inline-block;
            padding: 0.3rem 1.2rem;
            background: var(--gold-light);
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 0.8rem;
            border-radius: 50px;
            margin-bottom: 1rem;
            letter-spacing: 1px;
        }
        .section-header h2 {
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--primary-dark);
        }
        .section-header h2 span { color: var(--gold); }
        .section-header p {
            color: #5a6f7e;
            font-size: 1.1rem;
        }

        .features-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }
        .feature-card {
            background: var(--cream);
            border-radius: var(--radius);
            padding: 2.5rem 2rem;
            border: 1px solid rgba(212, 175, 55, 0.1);
            transition: var(--transition);
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--gold);
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            background: white;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            color: var(--primary);
        }
        .feature-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .feature-card p {
            color: #5a6f7e;
            font-size: 0.95rem;
        }

        /* ===== PORTALS ===== */
        .portals {
            padding: 6rem 3rem;
            background: var(--cream);
        }
        .portals-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
        }
        .portal-block {
            background: white;
            border-radius: var(--radius);
            padding: 2.5rem 1.5rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid transparent;
        }
        .portal-block:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--gold);
        }
        .portal-block .portal-avatar {
            width: 80px;
            height: 80px;
            border-radius: 30px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            background: var(--gold-light);
            color: var(--primary);
        }
        .portal-block h3 { font-size: 1.3rem; font-weight: 700; }
        .portal-block .role-desc {
            font-size: 0.9rem;
            color: #5a6f7e;
            margin-bottom: 1.5rem;
        }
        .portal-block .login-btn {
            width: 100%;
            padding: 0.8rem;
            border-radius: 40px;
            border: none;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 75, 107, 0.25);
        }
        .portal-block .login-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        /* ===== RECENT STUDENTS ===== */
        .recent-students {
            background: white;
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-top: 3rem;
        }
        .recent-students h4 {
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .recent-student-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.9rem;
        }
        .recent-student-item:last-child { border-bottom: none; }
        .recent-student-item .name { font-weight: 600; }
        .recent-student-item .date { color: var(--text-light); font-size: 0.8rem; }

        /* ===== FOOTER ===== */
        .footer {
            background: #0f2a3a;
            color: #cfdde8;
            padding: 4rem 3rem 2rem;
        }
        .footer-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }
        .footer-brand .logo-text {
            color: white;
            margin-bottom: 0.5rem;
            display: inline-block;
        }
        .footer-brand p {
            font-size: 0.95rem;
            line-height: 1.8;
            opacity: 0.8;
        }
        .footer-brand .arabic-footer {
            font-family: 'Noto Kufi Arabic', sans-serif;
            font-size: 1.2rem;
            color: var(--gold);
            margin: 0.5rem 0 1rem;
            direction: rtl;
        }
        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: var(--transition);
        }
        .social-links a:hover {
            background: var(--gold);
            color: #0f2a3a;
            transform: translateY(-3px);
        }
        .footer-column h4 {
            color: white;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        .footer-column ul { list-style: none; }
        .footer-column ul li { margin-bottom: 0.7rem; }
        .footer-column ul li a {
            color: #cfdde8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
        }
        .footer-column ul li a:hover {
            color: var(--gold);
            padding-left: 6px;
        }
        .footer-bottom {
            max-width: 1400px;
            margin: 0 auto;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.06);
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            opacity: 0.7;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .footer-bottom .contact-info {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .hero-container { grid-template-columns: 1fr; text-align: center; }
            .hero-content p { margin-left: auto; margin-right: auto; }
            .hero-stats { justify-content: center; }
            .hero-cards { max-width: 560px; margin: 0 auto; }
            .portals-grid { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .header { padding: 0 1.5rem; }
            .nav-links, .header-cta { display: none; }
            .mobile-menu-btn { display: block; }
            .hero { padding: calc(80px + 1.5rem) 1.5rem 3rem; }
            .hero-content h1 { font-size: 2.4rem; }
            .arabic-title { font-size: 1.8rem; }
            .hero-cards { grid-template-columns: 1fr; }
            .portals-grid { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; }
            .footer-bottom { flex-direction: column; align-items: center; text-align: center; }
        }

        /* mobile menu overlay */
        .mobile-menu {
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.98);
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2rem;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            padding: 2rem;
        }
        .mobile-menu.active { transform: translateX(0); }
        .mobile-menu a {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            text-decoration: none;
        }
        .mobile-menu .close-btn {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: none;
            border: none;
            font-size: 2rem;
            color: var(--primary);
            cursor: pointer;
        }
    </style>
</head>
<body>
    
<?php include 'preloader.php';?>
<!-- HEADER -->
<header class="header" id="header">
    <a href="#" class="logo">
        <div class="logo-icon"><img src="logo.png" alt="Logo"></div>
        <div>
            <div class="logo-text">Madrasatu<span> masjid</span></div>
            <span class="logo-sub">Abdullahi Ibnu Abbas</span>
        </div>
    </a>
    <nav>
        <ul class="nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#portals">Portals</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
    </nav>
    <div class="header-cta">
        <a href="#portals" class="btn btn-outline">Sign In</a>
        <a href="#contact" class="btn btn-gold">Get Started</a>
    </div>
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fas fa-bars"></i></button>
</header>

<!-- MOBILE MENU -->
<div class="mobile-menu" id="mobileMenu">
    <button class="close-btn" onclick="toggleMobileMenu()"><i class="fas fa-times"></i></button>
    <a href="#features" onclick="toggleMobileMenu()">Features</a>
    <a href="#portals" onclick="toggleMobileMenu()">Portals</a>
    <a href="#contact" onclick="toggleMobileMenu()">Contact</a>
    <a href="#portals" class="btn btn-primary" onclick="toggleMobileMenu()">Sign In</a>
</div>

<!-- HERO -->
<section class="hero">
    <div class="hero-container">
        <div class="hero-content">
            <div class="madrasa-badge">
                <i class="fas fa-mosque" style="margin-right: 8px;"></i> Madrasatu Masjid Abdullahi Ibnu Abbas
            </div>
            <div class="arabic-title">
                <span>العلم</span> دواء العال
            </div>
            <h1>Knowledge is the <span class="highlight">cure</span> for ailments</h1>
            <p>
                <strong>Investment Quarters, Tumfure — Opposite Chief Magistrate Court, Gombe State.</strong> 
                A modern Islamic &amp; secular learning environment built on tradition and excellence.
            </p>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <span style="background: var(--gold-light); padding: 0.3rem 1.2rem; border-radius: 40px; font-weight: 600; color: var(--primary-dark);">
                    <i class="fas fa-phone-alt"></i> 09037814903
                </span>
                <span style="background: var(--gold-light); padding: 0.3rem 1.2rem; border-radius: 40px; font-weight: 600; color: var(--primary-dark);">
                    <i class="fas fa-phone-alt"></i> 07036135512
                </span>
            </div>
            <div>
                <a href="#portals" class="btn btn-gold" style="padding: 0.9rem 2.2rem; font-size: 1rem;">
                    <i class="fas fa-sign-in-alt"></i> Access Portal
                </a>
            </div>
            <div class="hero-stats">
                <div class="stat-item">
                    <span class="stat-number counter" data-target="<?php echo $total_students; ?>">0</span>
                    <span class="stat-label">Students</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number counter" data-target="<?php echo $total_staff; ?>">0</span>
                    <span class="stat-label">Staff</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number counter" data-target="<?php echo $total_courses; ?>">0</span>
                    <span class="stat-label">Courses</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number counter" data-target="<?php echo $total_departments; ?>">0</span>
                    <span class="stat-label">Departments</span>
                </div>
            </div>
        </div>

        <div class="hero-cards">
            <div class="portal-card student" onclick="window.location.href='login.php'">
                <div class="icon-wrap"><i class="fas fa-user-graduate"></i></div>
                <h3>Student Portal</h3>
                <p>Assignments, results, schedules &amp; progress.</p>
                <a href="login.php" class="card-link">Login <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="portal-card staff" onclick="window.location.href='staff/'">
                <div class="icon-wrap"><i class="fas fa-chalkboard-teacher"></i></div>
                <h3>Staff Portal</h3>
                <p>Attendance, grading, &amp; class management.</p>
                <a href="staff/" class="card-link">Login <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="section-header">
        <span class="section-tag">مميزات · Features</span>
        <h2>Built for <span>Islamic</span> &amp; Academic Excellence</h2>
        <p>Tools that bridge traditional values with modern education management.</p>
    </div>
    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-quran"></i></div>
            <h3>Islamic Curriculum</h3>
            <p>Qur'an, Hadith, Fiqh, and Arabic language tracks integrated with national curriculum.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-chart-pie"></i></div>
            <h3>Performance Analytics</h3>
            <p>Track academic and religious studies progress with real-time dashboards.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-comments"></i></div>
            <h3>Parent Communication</h3>
            <p>Direct messaging and reports to keep families informed and involved.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
            <h3>Secure &amp; Private</h3>
            <p>Role-based access, SSL encryption, and data protection for all users.</p>
        </div>
    </div>
    
    <!-- Recent Students -->
    <?php if (!empty($recent_students)): ?>
    <div class="recent-students" style="max-width: 1400px; margin: 3rem auto 0;">
        <h4><i class="fas fa-user-plus" style="color: var(--gold);"></i> Recently Enrolled Students</h4>
        <?php foreach ($recent_students as $student): ?>
        <div class="recent-student-item">
            <span class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
            <span class="date">Joined: <?php echo date('M d, Y', strtotime($student['registration_date'])); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- PORTALS -->
<section class="portals" id="portals">
    <div class="section-header">
        <span class="section-tag">الدخول · Access</span>
        <h2>Choose Your <span>Portal</span></h2>
        <p>Select your role to enter the Madrasatu masjid digital ecosystem.</p>
    </div>
    <div class="portals-grid">
        <div class="portal-block">
            <div class="portal-avatar"><i class="fas fa-user-graduate"></i></div>
            <h3>Students</h3>
            <p class="role-desc">Lessons, assignments, results &amp; attendance.</p>
            <a href="login.php" class="login-btn"><i class="fas fa-sign-in-alt"></i> Student Login</a>
        </div>
        <div class="portal-block">
            <div class="portal-avatar"><i class="fas fa-chalkboard-teacher"></i></div>
            <h3>Staff</h3>
            <p class="role-desc">Manage classes, grades, and student progress.</p>
            <a href="staff/" class="login-btn"><i class="fas fa-sign-in-alt"></i> Staff Login</a>
        </div>
        <div class="portal-block">
            <div class="portal-avatar"><i class="fas fa-user-cog"></i></div>
            <h3>Admin</h3>
            <p class="role-desc">Oversee operations, reports &amp; settings.</p>
            <a href="login.php" class="login-btn"><i class="fas fa-sign-in-alt"></i> Admin Login</a>
        </div>
        <div class="portal-block">
            <div class="portal-avatar"><i class="fas fa-phone-alt"></i></div>
            <h3>Support</h3>
            <p class="role-desc">Get help and assistance when you need it.</p>
            <a href="#contact" class="login-btn" style="background: var(--gold); color: #1f2a3a;">
                <i class="fas fa-headset"></i> Contact Support
            </a>
        </div>
    </div>
</section>

<!-- CONTACT / CTA -->
<section class="features" id="contact" style="background: var(--cream); padding-bottom: 4rem;">
    <div class="section-header">
        <span class="section-tag">تواصل · Contact</span>
        <h2>Visit <span>Madrasatu masjid</span></h2>
        <p>We are located in the heart of Tumfure, Gombe State.</p>
    </div>
    <div style="max-width: 900px; margin: 0 auto; background: white; border-radius: var(--radius); padding: 3rem; box-shadow: var(--shadow-lg); border: 1px solid rgba(212,175,55,0.15);">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; text-align: center;">
            <div>
                <i class="fas fa-map-marker-alt" style="font-size: 2rem; color: var(--gold);"></i>
                <h4 style="margin: 0.5rem 0 0.2rem;">Address</h4>
                <p style="color: #3d5a6c;">Investment Quarters, Tumfure<br />Opposite Chief Magistrate Court<br />Gombe State, Nigeria</p>
            </div>
            <div>
                <i class="fas fa-phone-alt" style="font-size: 2rem; color: var(--gold);"></i>
                <h4 style="margin: 0.5rem 0 0.2rem;">Phone</h4>
                <p style="color: #3d5a6c;">09037814903<br />07036135512</p>
            </div>
        </div>
        <div style="margin-top: 2rem; text-align: center; border-top: 1px solid var(--gray-200); padding-top: 2rem;">
            <span class="arabic-title" style="font-size: 1.5rem; direction: rtl;">العلم دواء العال</span>
            <p style="font-style: italic; color: var(--primary-light);">“Knowledge is the cure for ailments.”</p>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <div class="logo-text" style="font-size: 1.8rem;">Madras<span>jid</span></div>
            <div class="arabic-footer">مدرسة مسجد عبد الله بن عباس</div>
            <p>Empowering the next generation with Islamic &amp; academic excellence. Located in Tumfure, Gombe.</p>
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook-f"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
        </div>
        <div class="footer-column">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="#features">Features</a></li>
                <li><a href="#portals">Portals</a></li>
                <li><a href="#contact">Contact</a></li>
                <li><a href="#">About Us</a></li>
            </ul>
        </div>
        <div class="footer-column">
            <h4>Portals</h4>
            <ul>
                <li><a href="login.php">Student Login</a></li>
                <li><a href="staff/">Staff Login</a></li>
                <li><a href="admin/">Admin Login</a></li> 
            </ul>
        </div>
        <div class="footer-column">
            <h4>Support</h4>
            <ul>
                <li><a href="#">Help Center</a></li>
                <li><a href="#">Documentation</a></li>
                <li><a href="#">Contact Support</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <span>&copy; <?php echo date('Y'); ?> Madrasatu Masjid Abdullahi Ibnu Abbas. All rights reserved.</span>
        <small>Designed and Developed by <a href="https://freemanicthub.com.ng/" target="_blank">FreeMan ICT Hub</a></small>
        <div class="contact-info">
            <span><i class="fas fa-phone"></i> 09037814903</span>
            <span><i class="fas fa-phone"></i> 07036135512</span>
            <span>Gombe State, Nigeria</span>
        </div>
    </div>
</footer>

<!-- SCRIPTS -->
<script>
    // ===== HEADER SCROLL =====
    window.addEventListener('scroll', function() {
        document.getElementById('header').classList.toggle('scrolled', window.scrollY > 50);
    });

    // ===== MOBILE MENU =====
    function toggleMobileMenu() {
        const menu = document.getElementById('mobileMenu');
        menu.classList.toggle('active');
        document.body.style.overflow = menu.classList.contains('active') ? 'hidden' : '';
    }

    // ===== SMOOTH ANCHOR =====
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) target.scrollIntoView({ behavior: 'smooth' });
        });
    });

    // ===== COUNTER ANIMATION =====
    document.addEventListener('DOMContentLoaded', function() {
        const counters = document.querySelectorAll('.counter');
        
        counters.forEach(counter => {
            const target = parseInt(counter.getAttribute('data-target'));
            const duration = 2000; // 2 seconds
            const step = Math.max(1, Math.floor(target / 50));
            let current = 0;
            
            const updateCounter = () => {
                current += step;
                if (current >= target) {
                    counter.textContent = target.toLocaleString();
                    return;
                }
                counter.textContent = current.toLocaleString();
                requestAnimationFrame(() => {
                    setTimeout(updateCounter, duration / 50);
                });
            };
            
            // Start counter when in viewport
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        updateCounter();
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            
            observer.observe(counter);
        });
    });
</script>
</body>
</html>
