<?php
/**
 * Staff Footer Include
 * Include at the bottom of every staff page before closing body/html tags
 * Usage: require_once 'includes/footer.php';
 */

// Prevent double inclusion
if (!defined('FOOTER_INCLUDED')) {
    define('FOOTER_INCLUDED', true);
}
?>
        </div><!-- /.content-wrapper -->
    </div><!-- /.main-content -->

    <!-- FOOTER -->
    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-text">
                <i class="fas fa-copyright me-1"></i>
                <?php echo date('Y'); ?> KowaGuru Technology Limited. All rights reserved. V.1.0
            </div> 
            <div class="footer-version">
                <i class="fas fa-code-branch me-1"></i> Designed by <a class="app-link" href="https://kowagurutech.ng" style="color: var(--primary-color); text-decoration: underline;" target="_blank">Kowaguru Tech LTD</a>
            </div>
        </div>
    </footer>

    <style>
        .main-footer {
            margin-left: var(--sidebar-width);
            padding: 20px 30px;
            background: var(--white);
            border-top: 1px solid var(--gray-200);
            transition: var(--transition);
            animation: fadeInUp 0.5s ease;
        }
        .sidebar.collapsed ~ .main-footer {
            margin-left: var(--sidebar-collapsed);
        }
        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .footer-text {
            font-size: 0.85rem;
            color: var(--text-light);
        }
        .footer-version {
            font-size: 0.8rem;
            color: var(--gray-500);
            background: var(--gray-100);
            padding: 4px 12px;
            border-radius: 8px;
        }
        .footer-version a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        .footer-version a:hover {
            text-decoration: underline;
        }
        @media (max-width: 768px) {
            .main-footer {
                padding: 15px 20px;
                margin-left: 0 !important;
            }
            .footer-content {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Mobile menu toggle
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                sidebar.classList.toggle('show');
            });
        }

        // Restore sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 991 && sidebar.classList.contains('show')) {
                if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Animate elements on scroll
        const animateOnScroll = () => {
            const elements = document.querySelectorAll('.stat-card, .course-card, .section-header');
            elements.forEach(el => {
                const rect = el.getBoundingClientRect();
                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }
            });
        };

        window.addEventListener('scroll', animateOnScroll);
        // Initial call
        setTimeout(animateOnScroll, 100);

        // Search functionality
        const searchInput = document.getElementById('globalSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    const query = this.value.trim();
                    if (query.length > 0) {
                        window.location.href = 'search.php?q=' + encodeURIComponent(query);
                    }
                }
            });
        }

        // Notification click handler
        document.getElementById('notifBtn')?.addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });

        // Message click handler
        document.getElementById('msgBtn')?.addEventListener('click', function() {
            window.location.href = 'messages.php';
        });

        console.log('Staff portal loaded successfully');
    </script>
</body>
</html>