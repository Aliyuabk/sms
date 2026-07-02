 // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleLink = event.target.closest('a');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleLink.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Hide Password';
            } else {
                passwordField.type = 'password';
                toggleLink.innerHTML = '<i class="fas fa-eye me-1"></i>Show Password';
            }
            
            return false;
        }
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                if (!username) {
                    document.getElementById('username').classList.add('is-invalid');
                }
                if (!password) {
                    document.getElementById('password').classList.add('is-invalid');
                }
                return false;
            }
            
            // Add loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in...';
            submitBtn.disabled = true;
        });
        
        // Remove invalid class on input
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
        
        // Auto-focus username on load
        document.getElementById('username').focus();
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }