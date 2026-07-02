        <footer class="footer">
            <p>&copy; <?php echo date('Y'); ?> Madarasatu Masjib Ibn Abbas . All rights reserved. V.1.0 <br>
            Designed by <a class="app-link" href="https://kowagurutech.ng" target="_blank">Kowaguru Tech LTD</a></p>
        </footer>
    </main>
    <?php if(isset($_SESSION['student_id'])): ?>
    <?php include 'sms_chatbot.html'; ?>
<?php endif; ?>
    <script>
        // Toggle Sidebar (Desktop: collapse/expand, Mobile: open/close)
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                // Mobile: toggle sidebar open/close
                sidebar.classList.toggle('mobile-open');
                if (sidebar.classList.contains('mobile-open')) {
                    overlay.classList.add('active');
                    document.body.classList.add('sidebar-open');
                } else {
                    overlay.classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                }
            } else {
                // Desktop: toggle collapsed state
                document.body.classList.toggle('sidebar-collapsed');
                sidebar.classList.toggle('collapsed');
            }
        }

        // Close mobile sidebar
        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
                document.body.classList.remove('sidebar-open');
            }
        }

        // Toggle User Menu
        function toggleUserMenu(event) {
            event.stopPropagation();
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const userProfile = document.querySelector('.user-profile');
            
            if (dropdown && !userProfile.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth > 768) {
                // Desktop: remove mobile classes
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
                document.body.classList.remove('sidebar-open');
                
                // Restore collapsed state if previously set
                if (document.body.classList.contains('sidebar-collapsed')) {
                    sidebar.classList.add('collapsed');
                }
            } else {
                // Mobile: remove desktop collapsed state
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
            }
        });

        // Prevent clicks inside sidebar from closing it on mobile
        document.getElementById('sidebar').addEventListener('click', function(event) {
            event.stopPropagation();
        });

        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.card, .stats-card, .table-container, .step-card, .welcome-banner').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 0.5s ease';
            observer.observe(el);
        });

        // Initialize based on screen size
        window.dispatchEvent(new Event('resize'));
    </script>
</body>
</html>