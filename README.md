# 📚 Madrasatu Masjid Abdullahi Ibnu Abbas - School Management System

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-purple.svg)](https://getbootstrap.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> **Live Demo:** [https://madrasjid.kowagurutech.ng/](https://madrasjid.kowagurutech.ng/)

A comprehensive School Management System designed for **Madrasatu Masjid Abdullahi Ibnu Abbas**, an Islamic and secular educational institution located in Tumfure, Gombe State, Nigeria.

---

## 🌟 Overview

This system provides a complete digital solution for managing:
- **Students** - Enrollment, courses, grades, attendance
- **Staff** - Teaching assignments, schedules, performance
- **Administration** - Reports, analytics, system settings
- **Islamic Curriculum** - Quran, Hadith, Fiqh, Arabic language tracks

Built with modern web technologies, the system bridges traditional Islamic values with contemporary educational management.

---

## 📸 Screenshots

### Landing Page
![Landing Page](screenshots/landing.png)

### Dashboard
![Dashboard](screenshots/dashboard.png)

### Student Management
![Student Management](screenshots/students.png)

### Analytics
![Analytics](screenshots/analytics.png)

---

## ✨ Features

### 🎓 Student Portal
- **Dashboard** - Overview of courses, grades, and attendance
- **Course Registration** - Register for available courses
- **Results** - View grades and academic performance
- **Attendance** - Track attendance records
- **Profile** - Manage personal information
- **Settings** - Notification preferences and account settings

### 👨‍🏫 Staff Portal
- **Dashboard** - Teaching overview and statistics
- **Course Management** - View assigned courses and student lists
- **Attendance Management** - Take and track attendance
- **Results Management** - Enter and publish grades
- **Student Profiles** - View detailed student information
- **Analytics** - Performance insights and reports
- **Notifications** - Send messages to students

### 🔐 Admin Portal
- **User Management** - Create and manage staff/student accounts
- **Course Management** - Create, edit, and assign courses
- **Academic Sessions** - Define semesters and academic years
- **Fee Structure** - Manage tuition and fees
- **Reports** - Generate comprehensive reports
- **System Settings** - Configure system parameters

### 📊 Analytics & Reports
- **Real-time Statistics** - Student count, staff count, courses
- **Grade Distribution** - Visual breakdown of student grades
- **Attendance Trends** - Monthly attendance patterns
- **Course Performance** - Rankings and pass rates
- **Student Performance** - Top performing students

### 📱 Responsive Design
- Mobile-first approach
- Works on all devices (desktop, tablet, mobile)
- Touch-friendly interface

---

## 🛠️ Technology Stack

### Backend
- **PHP 7.4+** - Server-side scripting
- **MySQL 5.7+** - Database management
- **PDO** - Secure database connections
- **Session Management** - User authentication

### Frontend
- **Bootstrap 5** - Responsive UI framework
- **Font Awesome 6** - Icon library
- **Chart.js** - Data visualization
- **Inter Font** - Modern typography
- **Noto Kufi Arabic** - Arabic font support

### Security
- **Password Hashing** - bcrypt encryption
- **Prepared Statements** - SQL injection protection
- **Session Security** - Secure session handling
- **XSS Protection** - Output sanitization

---

## 📋 Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Composer (optional)
- Git (optional)

---

## 🔧 Installation

### 1. Clone the Repository
```bash
git clone https://github.com/yourusername/madrasatu-masjid-sms.git
cd madrasatu-masjid-sms
```

### 2. Database Setup
```bash
# Import the database schema
mysql -u your_username -p your_database_name < sql/database.sql
```

### 3. Configuration
```php
// config/database.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
```

### 4. File Permissions
```bash
chmod -R 755 uploads/
chmod -R 755 assets/
chmod 644 config/database.php
```

### 5. Web Server Configuration

#### Apache (.htaccess)
```apache
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### Nginx
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 6. Access the Application
- **Landing Page:** `https://yourdomain.com/`
- **Student Portal:** `https://yourdomain.com/student/`
- **Staff Portal:** `https://yourdomain.com/staff/`
- **Admin Portal:** `https://yourdomain.com/admin/`

### Default Credentials
```
Student Login:
Email: student@example.com
Password: password

Staff Login:
Email: staff@example.com
Password: password

Admin Login:
Email: admin@example.com
Password: password
```

---

## 📁 Project Structure

```
madrasatu-masjid-sms/
├── admin/                 # Admin portal
│   ├── dashboard.php
│   ├── users.php
│   ├── courses.php
│   └── ...
├── staff/                 # Staff portal
│   ├── dashboard.php
│   ├── students.php
│   ├── attendance.php
│   ├── results.php
│   ├── analytics.php
│   └── ...
├── student/               # Student portal
│   ├── dashboard.php
│   ├── courses.php
│   ├── results.php
│   └── ...
├── assets/                # Static assets
│   ├── css/
│   ├── js/
│   ├── images/
│   └── uploads/
├── config/                # Configuration
│   └── database.php
├── includes/              # Reusable components
│   ├── header.php
│   ├── sidebar.php
│   ├── footer.php
│   └── functions.php
├── sql/                   # Database
│   └── database.sql
├── .htaccess
├── index.php              # Landing page
├── login.php              # Login page
└── README.md
```

---

## 🗄️ Database Schema

### Core Tables
- `staff` - Staff information and credentials
- `students` - Student information and enrollment
- `courses` - Course catalog
- `course_assignments` - Staff-course assignments
- `course_registrations` - Student course enrollments
- `attendance` - Attendance records
- `results` - Student grades
- `notifications` - System notifications
- `payments` - Fee payments
- `academic_sessions` - Semesters and academic years
- `departments` - Academic departments
- `faculties` - Faculty information
- `programs` - Academic programs

---

## 🎯 Key Features Detail

### Student Management
```php
// Add new student
$data = [
    'matric_number' => 'KGT2026001',
    'first_name' => 'Muhammad',
    'last_name' => 'Abdullahi',
    'email' => 'student@example.com',
    'phone' => '08012345678',
    'department_id' => 1,
    'program_id' => 1,
    'current_level' => 100
];
dbInsert('students', $data);
```

### Attendance Management
```php
// Record attendance
$attendance = [
    'student_id' => 1,
    'course_id' => 5,
    'class_date' => date('Y-m-d'),
    'status' => 'Present',
    'recorded_by' => $staff_id
];
dbInsert('attendance', $attendance);
```

### Results Management
```php
// Enter results
$result = [
    'student_id' => 1,
    'course_id' => 5,
    'session_year' => '2025/2026',
    'semester' => 1,
    'ca_score' => 35.5,
    'exam_score' => 52.0,
    'total_score' => 87.5,
    'grade' => 'A',
    'grade_points' => 5.00
];
dbInsert('results', $result);
```

---

## 📈 Analytics Dashboard

### Visual Statistics
- **Grade Distribution** - Bar chart showing grade breakdown
- **Attendance Trends** - Line chart showing monthly patterns
- **Gender Distribution** - Donut chart showing male/female ratio
- **Level Distribution** - Progress bars by academic level
- **Course Performance** - Rankings with pass rates
- **Top Students** - Leaderboard of top performers

---

## 🤝 Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

### Coding Standards
- Follow PSR-12 coding standards
- Use meaningful variable names
- Add comments for complex logic
- Keep functions small and focused
- Use prepared statements for database queries

---

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## 👥 Team

| Name | Role | Contact |
|------|------|---------|
| Aliyu Abubakar | Lead Developer | aliyuabubakar11117@gmail.com |
| Support Team | Technical Support | support@madrasjid.kowagurutech.ng |

---

## 📞 Contact

**Madrasatu Masjid Abdullahi Ibnu Abbas**
- 📍 Investment Quarters, Tumfure
- 🏛️ Opposite Chief Magistrate Court
- 📍 Gombe State, Nigeria
- 📞 09037814903
- 📞 07036135512
- 🌐 https://madrasjid.kowagurutech.ng/

---

## 🙏 Acknowledgments

- All staff and students of Madrasatu Masjid Abdullahi Ibnu Abbas
- The community of Tumfure, Gombe State
- Open source community for the amazing tools and libraries

---

## 📝 Changelog

### v1.0.0 (2026)
- Initial release
- Student portal
- Staff portal
- Admin portal
- Attendance management
- Results management
- Analytics dashboard
- Notification system
- Responsive design

### v1.1.0 (Upcoming)
- Mobile app integration
- SMS notifications
- Payment gateway integration
- Advanced reporting
- Multi-language support

---

## 🐛 Bug Reports

If you find any bugs, please create an issue in the repository or contact support directly.

---

**Made with ❤️ for the community of Madrasatu Masjid Abdullahi Ibnu Abbas**
