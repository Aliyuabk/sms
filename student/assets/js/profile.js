/**
 * Profile Page JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initProfileTabs();
    initImagePreview();
    initFormValidation();
});

/**
 * Initialize profile tabs
 */
function initProfileTabs() {
    const tabs = document.querySelectorAll('.profile-tab');
    const contents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const target = this.dataset.tab;
            
            // Update active tab
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show target content
            contents.forEach(content => {
                content.style.display = content.id === target ? 'block' : 'none';
            });
        });
    });
}

/**
 * Initialize image preview for profile picture
 */
function initImagePreview() {
    const imageInput = document.getElementById('profileImage');
    const avatar = document.querySelector('.profile-avatar img, .profile-avatar span');
    
    if (imageInput && avatar) {
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    showAlert('danger', 'Please select a valid image file (JPEG, PNG, or GIF)');
                    this.value = '';
                    return;
                }
                
                // Validate file size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    showAlert('danger', 'File size must be less than 2MB');
                    this.value = '';
                    return;
                }
                
                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (avatar.tagName === 'IMG') {
                        avatar.src = e.target.result;
                    } else {
                        // Replace span with img
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        avatar.parentNode.replaceChild(img, avatar);
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
}

/**
 * Initialize form validation for profile forms
 */
function initFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const phoneInput = this.querySelector('input[name="phone"]');
            const emergencyInput = this.querySelector('input[name="emergency_contact"]');
            
            // Validate phone numbers
            if (phoneInput && phoneInput.value) {
                const phoneRegex = /^[0-9]{11}$/;
                if (!phoneRegex.test(phoneInput.value)) {
                    e.preventDefault();
                    showInputError(phoneInput, 'Please enter a valid 11-digit phone number');
                }
            }
            
            if (emergencyInput && emergencyInput.value) {
                const phoneRegex = /^[0-9]{11}$/;
                if (!phoneRegex.test(emergencyInput.value)) {
                    e.preventDefault();
                    showInputError(emergencyInput, 'Please enter a valid 11-digit phone number');
                }
            }
        });
    });
}

/**
 * Edit profile section
 */
function editSection(sectionId) {
    const viewSection = document.getElementById(sectionId + '-view');
    const editSection = document.getElementById(sectionId + '-edit');
    
    if (viewSection && editSection) {
        viewSection.style.display = 'none';
        editSection.style.display = 'block';
        
        // Scroll to edit section
        editSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

/**
 * Cancel edit
 */
function cancelEdit(sectionId) {
    const viewSection = document.getElementById(sectionId + '-view');
    const editSection = document.getElementById(sectionId + '-edit');
    
    if (viewSection && editSection) {
        viewSection.style.display = 'grid';
        editSection.style.display = 'none';
    }
}

/**
 * Show change password modal
 */
function showChangePasswordModal() {
    const modal = document.getElementById('changePasswordModal');
    if (modal) {
        modal.classList.add('active');
    }
}

/**
 * Show sessions modal
 */
function showSessionsModal() {
    const modal = document.getElementById('sessionsModal');
    if (modal) {
        modal.classList.add('active');
        loadSessions();
    }
}

/**
 * Show activity modal
 */
function showActivityModal() {
    const modal = document.getElementById('activityModal');
    if (modal) {
        modal.classList.add('active');
        loadActivity();
    }
}

/**
 * Close modal
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Change password
 */
function changePassword(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Validate passwords match
    const newPassword = formData.get('new_password');
    const confirmPassword = formData.get('confirm_password');
    
    if (newPassword !== confirmPassword) {
        showAlert('danger', 'New passwords do not match');
        return;
    }
    
    // Validate password strength
    if (newPassword.length < 8) {
        showAlert('danger', 'Password must be at least 8 characters long');
        return;
    }
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    submitBtn.disabled = true;
    
    fetch('ajax/change-password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Password changed successfully');
            closeModal('changePasswordModal');
            form.reset();
        } else {
            showAlert('danger', data.message || 'Failed to change password');
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
 * Load active sessions
 */
function loadSessions() {
    const sessionsList = document.querySelector('.sessions-list');
    
    fetch('ajax/get-sessions.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.sessions) {
                updateSessionsList(data.sessions);
            }
        })
        .catch(error => console.error('Error loading sessions:', error));
}

/**
 * Update sessions list
 */
function updateSessionsList(sessions) {
    const sessionsList = document.querySelector('.sessions-list');
    if (!sessionsList) return;
    
    let html = '';
    sessions.forEach(session => {
        html += `
            <div class="session-item ${session.is_current ? 'current' : ''}">
                <div class="session-info">
                    <i class="fas ${session.device === 'mobile' ? 'fa-mobile-alt' : 'fa-laptop'}"></i>
                    <div>
                        <h4>${session.is_current ? 'Current Session' : session.device}</h4>
                        <p>${session.ip_address}</p>
                        <small>${session.location || 'Unknown location'}</small>
                        <small>Last active: ${session.last_activity}</small>
                    </div>
                </div>
                ${!session.is_current ? '<span class="badge badge-secondary">' + session.last_active + '</span>' : ''}
            </div>
        `;
    });
    
    sessionsList.innerHTML = html;
}

/**
 * Load activity timeline
 */
function loadActivity() {
    const timeline = document.querySelector('.activity-timeline');
    
    fetch('ajax/get-activity.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.activities) {
                updateActivityTimeline(data.activities);
            }
        })
        .catch(error => console.error('Error loading activity:', error));
}

/**
 * Update activity timeline
 */
function updateActivityTimeline(activities) {
    const timeline = document.querySelector('.activity-timeline');
    if (!timeline) return;
    
    let html = '';
    activities.forEach(activity => {
        html += `
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fas ${getActivityIcon(activity.type)}"></i>
                </div>
                <div class="activity-details">
                    <p>${activity.description}</p>
                    <small>${activity.time}</small>
                </div>
            </div>
        `;
    });
    
    timeline.innerHTML = html;
}

/**
 * Get activity icon based on type
 */
function getActivityIcon(type) {
    const icons = {
        'login': 'fa-sign-in-alt',
        'logout': 'fa-sign-out-alt',
        'profile_update': 'fa-user-edit',
        'password_change': 'fa-key',
        'payment': 'fa-credit-card',
        'result_view': 'fa-chart-line',
        'course_reg': 'fa-book-open'
    };
    
    return icons[type] || 'fa-info-circle';
}

/**
 * Logout all devices
 */
function logoutAllDevices() {
    if (confirm('This will log you out from all other devices. Continue?')) {
        fetch('ajax/logout-all.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', 'Logged out from all other devices');
                closeModal('sessionsModal');
            } else {
                showAlert('danger', data.message || 'Failed to logout other devices');
            }
        })
        .catch(error => {
            showAlert('danger', 'An error occurred');
            console.error('Error:', error);
        });
    }
}

/**
 * Toggle two-factor authentication
 */
function toggleTwoFactor(checkbox) {
    const enabled = checkbox.checked;
    
    fetch('ajax/toggle-2fa.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ enabled: enabled })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', enabled ? 'Two-factor authentication enabled' : 'Two-factor authentication disabled');
            if (enabled && data.setup_required) {
                showTwoFactorSetup();
            }
        } else {
            checkbox.checked = !enabled;
            showAlert('danger', data.message || 'Failed to update two-factor authentication');
        }
    })
    .catch(error => {
        checkbox.checked = !enabled;
        showAlert('danger', 'An error occurred');
        console.error('Error:', error);
    });
}

/**
 * Show two-factor setup modal
 */
function showTwoFactorSetup() {
    // Implementation for 2FA setup
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Setup Two-Factor Authentication</h2>
                <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Scan this QR code with your authenticator app:</p>
                <div class="qr-code"></div>
                <p>Or enter this code manually: <strong>XXXX XXXX XXXX XXXX</strong></p>
                <div class="form-group">
                    <label class="form-label">Verification Code</label>
                    <input type="text" class="form-control" placeholder="Enter 6-digit code">
                </div>
                <button class="btn btn-primary btn-block">Verify and Enable</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

// Export functions for use in HTML
window.editSection = editSection;
window.cancelEdit = cancelEdit;
window.showChangePasswordModal = showChangePasswordModal;
window.showSessionsModal = showSessionsModal;
window.showActivityModal = showActivityModal;
window.closeModal = closeModal;
window.changePassword = changePassword;
window.logoutAllDevices = logoutAllDevices;
window.toggleTwoFactor = toggleTwoFactor;