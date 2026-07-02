 <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                   <img src="../assets/images/logo.jpeg" width="50" alt="Logo">
                </div>
                <div class="logo-text">E-GURU - <span>Portal</span></div>
            </div>
            <div class="close-sidebar" onclick="closeMobileSidebar()">
                <svg viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </div>
        </div>

        <div class="student-info">
                    <div class="student-avatar">
                <?php 
                $profilePic = !empty($student['profile_image']) ? $student['profile_image'] : (!empty($student['photo']) ? $student['photo'] : null);
                if ($profilePic): 
                ?>
                    <img src="<?php echo htmlspecialchars($profilePic); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <?php else: ?>
                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="student-details">
                <div class="student-name"><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></div>
                <div class="student-meta"><?php echo $student['matric_number']; ?></div>
                <div class="student-level"><?php echo $student['current_level'] . ' Level'; ?></div>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item">
                <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'my-home.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                    </svg>
                    <span>My Home</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="fees.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'fees.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M11.5 1L8 7h7l-3.5-6zm0 22L8 17h7l-3.5 6zM12 10.5l-3 5h6l-3-5z"/>
                    </svg>
                    <span>Fees</span>
                    <?php
                    $fee_check = $conn->query("SELECT * FROM student_fees WHERE student_id = {$_SESSION['student_id']} AND status != 'Paid' LIMIT 1");
                    if($fee_check && $fee_check->num_rows > 0):
                    ?>
                    <span class="nav-badge">!</span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="transactions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'transactions.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <span>Transactions</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="courses.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v4h-2v-4H9V9h4V5h2v4h4v2z"/>
                    </svg>
                    <span>Courses</span>
                </a>
            </li> 
            <li class="nav-item">
                <a href="result.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'result.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H7v-4h4v4zm0-6H7V7h4v4zm6 6h-4v-4h4v4zm0-6h-4V7h4v4z"/>
                    </svg>
                    <span>Result</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="logout.php" class="nav-link">
                    <svg viewBox="0 0 24 24">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.59L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                    </svg>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </aside>
    