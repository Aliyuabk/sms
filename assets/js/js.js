document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                const icon = this.querySelector('i');
                if (type === 'password') {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            });
        }
        
        // Form submission
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        if (loginForm && loginBtn) {
            loginForm.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!username || !password) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    return false;
                }
                
                // Show loading state
                const icon = loginBtn.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-sign-in-alt');
                    icon.classList.add('fa-spinner', 'fa-spin');
                }
                loginBtn.disabled = true;
                loginBtn.querySelector('span').textContent = 'Signing In...';
                
                return true;
            });
        }
        
        // Check for mobile device
        function isMobile() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }
        
        if (isMobile()) {
            // Add touch-friendly styles
            document.querySelectorAll('input, button').forEach(el => {
                el.style.minHeight = '44px';
            });
        }
        
        // Auto-focus on username field
        setTimeout(() => {
            const usernameField = document.getElementById('username');
            if (usernameField) {
                usernameField.focus();
            }
        }, 300);
    });
    // Password strength indicator (optional)
function checkPasswordStrength(password) {
    let strength = 0;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    return strength;
}

// Auto logout after inactivity (optional)
let inactivityTime = function() {
    let time;
    window.onload = resetTimer;
    document.onmousemove = resetTimer;
    document.onkeypress = resetTimer;
    
    function logout() {
        window.location.href = 'logout.php';
    }
    
    function resetTimer() {
        clearTimeout(time);
        time = setTimeout(logout, 30 * 60 * 1000); // 30 minutes
    }
};

// Initialize inactivity timer
if (window.location.pathname.includes('dashboard.php')) {
    inactivityTime();
}