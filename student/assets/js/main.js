/**
 * Main JavaScript File
 * Handles common functionality across all pages
 */

document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initUserDropdown();
    initNotifications();
    initTooltips();
    initFormValidation();
    initAjaxRequests();
});

/**
 * Sidebar functionality
 */
function initSidebar() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.add('mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.style.display = 'block';
            }
        });
    }
    
    if (sidebarClose && sidebar) {
        sidebarClose.addEventListener('click', function() {
            closeSidebar(sidebar, sidebarOverlay);
        });
    }
    
    if (sidebarOverlay && sidebar) {
        sidebarOverlay.addEventListener('click', function() {
            closeSidebar(sidebar, sidebarOverlay);
        });
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar) {
            sidebar.classList.remove('mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.style.display = 'none';
            }
        }
    });
}

function closeSidebar(sidebar, overlay) {
    sidebar.classList.remove('mobile-open');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

/**
 * User dropdown functionality
 */
function initUserDropdown() {
    const userDropdown = document.getElementById('userDropdown');
    const dropdownMenu = document.getElementById('dropdownMenu');
    
    if (userDropdown && dropdownMenu) {
        // For mobile, toggle on click
        if (window.innerWidth <= 768) {
            userDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdownMenu.style.display = dropdownMenu.style.display === 'block' ? 'none' : 'block';
            });
            
            document.addEventListener('click', function() {
                dropdownMenu.style.display = 'none';
            });
        }
    }
}

/**
 * Notifications functionality
 */
function initNotifications() {
    const notificationBell = document.getElementById('notificationBell');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBell && notificationDropdown) {
        // For mobile, toggle on click
        if (window.innerWidth <= 768) {
            notificationBell.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            });
            
            document.addEventListener('click', function() {
                notificationDropdown.style.display = 'none';
            });
        }
    }
    
    // Load notifications via AJAX
    loadNotifications();
}

function loadNotifications() {
    // Simulate AJAX call
    setTimeout(() => {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            // Update badge count if needed
        }
    }, 2000);
}

/**
 * Tooltips initialization
 */
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', showTooltip);
        element.addEventListener('mouseleave', hideTooltip);
    });
}

function showTooltip(e) {
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.textContent = e.target.dataset.tooltip;
    
    document.body.appendChild(tooltip);
    
    const rect = e.target.getBoundingClientRect();
    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
}

function hideTooltip() {
    const tooltip = document.querySelector('.tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

/**
 * Form validation
 */
function initFormValidation() {
    const forms = document.querySelectorAll('form[data-validate]');
    
    forms.forEach(form => {
        form.addEventListener('submit', validateForm);
    });
}

function validateForm(e) {
    const form = e.target;
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            isValid = false;
            showInputError(input, 'This field is required');
        } else {
            clearInputError(input);
        }
        
        // Email validation
        if (input.type === 'email' && input.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(input.value)) {
                isValid = false;
                showInputError(input, 'Please enter a valid email address');
            }
        }
        
        // Password validation
        if (input.type === 'password' && input.hasAttribute('data-min-length')) {
            const minLength = parseInt(input.dataset.minLength) || 6;
            if (input.value.length < minLength) {
                isValid = false;
                showInputError(input, `Password must be at least ${minLength} characters`);
            }
        }
    });
    
    // Password match validation
    const password = form.querySelector('input[name="new_password"]');
    const confirmPassword = form.querySelector('input[name="confirm_password"]');
    
    if (password && confirmPassword && password.value && confirmPassword.value) {
        if (password.value !== confirmPassword.value) {
            isValid = false;
            showInputError(confirmPassword, 'Passwords do not match');
        }
    }
    
    if (!isValid) {
        e.preventDefault();
    }
}

function showInputError(input, message) {
    input.classList.add('error');
    
    let errorDiv = input.parentNode.querySelector('.form-error');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'form-error';
        input.parentNode.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

function clearInputError(input) {
    input.classList.remove('error');
    const errorDiv = input.parentNode.querySelector('.form-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

/**
 * AJAX request handler
 */
function initAjaxRequests() {
    // Handle all AJAX forms
    document.querySelectorAll('form[data-ajax]').forEach(form => {
        form.addEventListener('submit', handleAjaxSubmit);
    });
}

function handleAjaxSubmit(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const url = form.action || window.location.href;
    const method = form.method || 'POST';
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    submitBtn.disabled = true;
    
    fetch(url, {
        method: method,
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Handle success
        if (data.success) {
            showAlert('success', data.message || 'Operation completed successfully');
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1500);
            }
        } else {
            showAlert('danger', data.message || 'An error occurred');
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred. Please try again.');
        console.error('Error:', error);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

/**
 * Show alert message
 */
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    const contentWrapper = document.querySelector('.content-wrapper');
    if (contentWrapper) {
        contentWrapper.insertBefore(alertDiv, contentWrapper.firstChild);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

/**
 * Format currency
 */
function formatCurrency(amount) {
    return '₦' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

/**
 * Format date
 */
function formatDate(dateString, format = 'short') {
    const date = new Date(dateString);
    const options = format === 'long' 
        ? { year: 'numeric', month: 'long', day: 'numeric' }
        : { year: 'numeric', month: 'short', day: 'numeric' };
    
    return date.toLocaleDateString('en-NG', options);
}

/**
 * Get grade color
 */
function getGradeColor(grade) {
    grade = grade.toUpperCase();
    if (['A', 'A+', 'A-'].includes(grade)) return '#10b981';
    if (['B+', 'B', 'B-'].includes(grade)) return '#3b82f6';
    if (['C+', 'C', 'C-'].includes(grade)) return '#f59e0b';
    if (['D', 'E'].includes(grade)) return '#ef4444';
    return '#6b7280';
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Export functions to global scope
 */
window.showAlert = showAlert;
window.formatCurrency = formatCurrency;
window.formatDate = formatDate;
window.getGradeColor = getGradeColor;