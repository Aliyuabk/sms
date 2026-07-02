class StudentModalManager {
    constructor() {
        this.initModals();
        this.initEventListeners();
        this.allStudents = []; // Store all valid students
    }
    
    initModals() {
        // Modals are already created in the HTML
        this.setupModalDependencies();
    }
    
    setupModalDependencies() {
        // Setup faculty-department-program cascade
        const facultySelect = document.getElementById('modalFaculty');
        const departmentSelect = document.getElementById('modalDepartment');
        const programSelect = document.getElementById('modalProgram');
        
        if (facultySelect) {
            facultySelect.addEventListener('change', (e) => {
                const facultyId = e.target.value;
                this.loadDepartmentsByFaculty(facultyId);
            });
        }
        
        if (departmentSelect) {
            departmentSelect.addEventListener('change', (e) => {
                const deptId = e.target.value;
                this.loadProgramsByDepartment(deptId);
            });
        }
    }
    
    initEventListeners() {
        // Single student modal navigation
        document.addEventListener('click', (e) => {
            if (e.target.closest('#nextStepBtn')) this.nextStep();
            if (e.target.closest('#prevStepBtn')) this.prevStep();
            if (e.target.closest('#browseFileBtn')) this.triggerFileInput();
            if (e.target.closest('#removeFileBtn')) this.removeFile();
        });
        
        // File upload drag & drop
        const dropZone = document.getElementById('csvDropZone');
        if (dropZone) {
            dropZone.addEventListener('click', () => this.triggerFileInput());
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.handleFileSelect(files[0]);
                }
            });
        }
        
        // File input change
        const fileInput = document.querySelector('input[name="csv_file"]');
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    this.handleFileSelect(e.target.files[0]);
                }
            });
        }
        
        // Form submissions
        const singleForm = document.getElementById('singleStudentForm');
        if (singleForm) {
            singleForm.addEventListener('submit', (e) => this.submitSingleStudent(e));
        }
        
        const csvForm = document.getElementById('csvUploadForm');
        if (csvForm) {
            csvForm.addEventListener('submit', (e) => this.submitCSV(e));
        }
        
        // Initialize data on modal show
        const addStudentModal = document.getElementById('addStudentModal');
        if (addStudentModal) {
            addStudentModal.addEventListener('show.bs.modal', () => {
                this.loadInitialData();
            });
        }
    }
    
    loadInitialData() {
        // Load initial data for forms
        this.loadFaculties();
    }
    
    loadFaculties() {
        fetch('ajax/get_faculties.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const facultySelect = document.getElementById('modalFaculty');
                    if (facultySelect) {
                        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
                        data.faculties.forEach(faculty => {
                            const option = new Option(faculty.faculty_name, faculty.faculty_id);
                            facultySelect.add(option);
                        });
                    }
                }
            })
            .catch(error => console.error('Error loading faculties:', error));
    }
    
    loadDepartmentsByFaculty(facultyId) {
        const departmentSelect = document.getElementById('modalDepartment');
        if (!departmentSelect) return;
        
        if (!facultyId) {
            departmentSelect.innerHTML = '<option value="">Select Department</option>';
            departmentSelect.disabled = true;
            this.clearPrograms();
            return;
        }
        
        fetch(`ajax/get_departments.php?faculty_id=${facultyId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    departmentSelect.innerHTML = '<option value="">Select Department</option>';
                    data.departments.forEach(dept => {
                        const option = new Option(dept.department_name, dept.department_id);
                        departmentSelect.add(option);
                    });
                    departmentSelect.disabled = false;
                    this.clearPrograms();
                }
            })
            .catch(error => console.error('Error loading departments:', error));
    }
    
    loadProgramsByDepartment(deptId) {
        const programSelect = document.getElementById('modalProgram');
        if (!programSelect) return;
        
        if (!deptId) {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            programSelect.disabled = true;
            return;
        }
        
        fetch(`ajax/get_programs.php?department_id=${deptId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    programSelect.innerHTML = '<option value="">Select Program</option>';
                    data.programs.forEach(program => {
                        const option = new Option(program.program_name, program.program_id);
                        programSelect.add(option);
                    });
                    programSelect.disabled = false;
                }
            })
            .catch(error => console.error('Error loading programs:', error));
    }
    
    clearPrograms() {
        const programSelect = document.getElementById('modalProgram');
        if (programSelect) {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            programSelect.disabled = true;
        }
    }
    
    nextStep() {
        const currentStep = this.getCurrentStep();
        const nextStep = currentStep + 1;
        
        if (this.validateStep(currentStep)) {
            this.showStep(nextStep);
            this.updateStepIndicator(nextStep);
            this.updateNavigationButtons(nextStep);
        }
    }
    
    prevStep() {
        const currentStep = this.getCurrentStep();
        const prevStep = currentStep - 1;
        
        this.showStep(prevStep);
        this.updateStepIndicator(prevStep);
        this.updateNavigationButtons(prevStep);
    }
    
    getCurrentStep() {
        const activeStep = document.querySelector('.form-step.active');
        return parseInt(activeStep.dataset.step);
    }
    
    validateStep(step) {
        const stepElement = document.querySelector(`.form-step[data-step="${step}"]`);
        const inputs = stepElement.querySelectorAll('[required]');
        
        let isValid = true;
        inputs.forEach(input => {
            if (!input.value.trim()) {
                isValid = false;
                this.showError(input, 'This field is required');
            } else {
                this.clearError(input);
            }
        });
        
        // Additional validations
        if (step === 1) {
            // Validate date of birth
            const dobInput = document.querySelector('input[name="date_of_birth"]');
            if (dobInput && dobInput.value) {
                const dob = new Date(dobInput.value);
                const today = new Date();
                const age = today.getFullYear() - dob.getFullYear();
                if (age < 16 || age > 60) {
                    isValid = false;
                    this.showError(dobInput, 'Age must be between 16 and 60 years');
                }
            }
        }
        
       // Auto-generate username from matric number
            if (step === 2) {
                const matricInput = document.querySelector('input[name="matric_number"]');
                const usernameInput = document.querySelector('input[name="username"]');
                
                if (matricInput && usernameInput) {
                    const updateUsernameFromMatric = () => {
                        if (matricInput.value) {
                            // Clean matric number for username
                            let username = matricInput.value.toLowerCase();
                            username = username.replace(/[^a-z0-9._-]/g, '');
                            username = username.replace(/[\/\\\s]/g, '.');
                            username = username.replace(/\.+/g, '.');
                            username = username.replace(/^\.+|\.+$/g, '');
                            usernameInput.value = username;
                        } else {
                            usernameInput.value = '';
                        }
                    };
                    
                    matricInput.addEventListener('input', updateUsernameFromMatric);
                    
                    // Also trigger on page load if matric already has value
                    if (matricInput.value) {
                        updateUsernameFromMatric();
                    }
                }
            }
        
        if (step === 3) {
            const emailInput = document.querySelector('input[name="email"]');
            const passwordInput = document.querySelector('input[name="password"]');
            const confirmPasswordInput = document.querySelector('input[name="confirm_password"]');
            
            if (emailInput && !this.isValidEmail(emailInput.value)) {
                isValid = false;
                this.showError(emailInput, 'Please enter a valid email address');
            }
            
            if (passwordInput && passwordInput.value.length < 8) {
                isValid = false;
                this.showError(passwordInput, 'Password must be at least 8 characters');
            }
            
            if (passwordInput && confirmPasswordInput && 
                passwordInput.value !== confirmPasswordInput.value) {
                isValid = false;
                this.showError(confirmPasswordInput, 'Passwords do not match');
            }
        }
        
        return isValid;
    }
    
    showStep(step) {
        document.querySelectorAll('.form-step').forEach(el => {
            el.classList.remove('active');
        });
        
        const stepElement = document.querySelector(`.form-step[data-step="${step}"]`);
        if (stepElement) {
            stepElement.classList.add('active');
        }
    }
    
    updateStepIndicator(step) {
        document.querySelectorAll('.step').forEach(el => {
            el.classList.remove('active', 'completed');
        });
        
        for (let i = 1; i <= step; i++) {
            const stepEl = document.querySelector(`.step[data-step="${i}"]`);
            if (stepEl) {
                if (i === step) {
                    stepEl.classList.add('active');
                } else {
                    stepEl.classList.add('completed');
                }
            }
        }
    }
    
    updateNavigationButtons(step) {
        const prevBtn = document.getElementById('prevStepBtn');
        const nextBtn = document.getElementById('nextStepBtn');
        const submitBtn = document.getElementById('submitStudentBtn');
        
        if (prevBtn) prevBtn.style.display = step > 1 ? 'inline-block' : 'none';
        if (nextBtn) nextBtn.style.display = step < 3 ? 'inline-block' : 'none';
        if (submitBtn) submitBtn.style.display = step === 3 ? 'inline-block' : 'none';
    }
    
    triggerFileInput() {
        const fileInput = document.querySelector('input[name="csv_file"]');
        if (fileInput) fileInput.click();
    }
    
    handleFileSelect(file) {
        if (!file.name.toLowerCase().endsWith('.csv')) {
            this.showToast('Please select a CSV file', 'error');
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) { // 5MB
            this.showToast('File size must be less than 5MB', 'error');
            return;
        }
        
        const fileName = document.getElementById('fileName');
        const selectedFile = document.getElementById('selectedFile');
        
        if (fileName) fileName.textContent = `${file.name} (${this.formatFileSize(file.size)})`;
        if (selectedFile) selectedFile.style.display = 'block';
        
        // Store file reference
        this.selectedFile = file;
    }
    
    removeFile() {
        const fileInput = document.querySelector('input[name="csv_file"]');
        const selectedFile = document.getElementById('selectedFile');
        
        if (fileInput) fileInput.value = '';
        if (selectedFile) selectedFile.style.display = 'none';
        this.selectedFile = null;
    }
    
    submitSingleStudent(e) {
        e.preventDefault();
        
        if (!this.validateStep(3)) return;
        
        const form = e.target;
        const formData = new FormData(form);
        
        this.showProgress('Adding Student', 'Please wait while we save the student record...');
        
        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            this.hideProgress();
            if (data.success) {
                this.showToast(data.message, 'success');
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addStudentModal'));
                    if (modal) modal.hide();
                    location.reload();
                }, 1500);
            } else {
                this.showToast(data.message || 'Failed to add student', 'error');
            }
        })
        .catch(error => {
            this.hideProgress();
            this.showToast('An error occurred. Please try again.', 'error');
            console.error('Error:', error);
        });
    }
    
    submitCSV(e) {
        e.preventDefault();
        
        if (!this.selectedFile) {
            this.showToast('Please select a CSV file first', 'warning');
            return;
        }
        
        const form = e.target;
        const formData = new FormData(form);
        formData.append('csv_file', this.selectedFile);
        
        this.showProgress('Processing CSV', 'Uploading and parsing CSV file...');
        
        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            this.hideProgress();
            
            if (data.success) {
                // Store all students for later import
                this.allStudents = data.all_students || data.students || [];
                this.showPreview(data.preview || [], data.total || this.allStudents.length, data.errors || []);
            } else {
                this.showToast(data.message || 'Failed to process CSV file', 'error');
            }
        })
        .catch(error => {
            this.hideProgress();
            this.showToast('An error occurred. Please try again.', 'error');
            console.error('Error:', error);
        });
    }
    
    showPreview(previewData, totalCount, errors = []) {
    const previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
    const previewContent = document.getElementById('previewContent');
    
    let html = `
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        Found ${totalCount} valid students to import
        <br>
        <small class="text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Username will be generated from matric number<br>
            Default password for all students: <code>password</code>
        </small>
    </div>`;
    
    if (errors.length > 0) {
        html += `
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            ${errors.length} validation error(s) found
            <button class="btn btn-sm btn-outline-warning float-end" type="button" 
                    data-bs-toggle="collapse" data-bs-target="#errorDetails">
                Show Details
            </button>
            <div class="collapse mt-2" id="errorDetails">
                <div class="card card-body small">
                    <ul class="mb-0">`;
        
        // Show only first 5 errors in preview
        const displayErrors = errors.slice(0, 5);
        displayErrors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        
        if (errors.length > 5) {
            html += `<li>...and ${errors.length - 5} more errors</li>`;
        }
        
        html += `
                    </ul>
                </div>
            </div>
        </div>`;
    }
    
    if (previewData.length > 0) {
        html += `
        <div class="preview-table">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Matric No</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Password</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>`;
        
        previewData.forEach((student, index) => {
            // Generate preview username from matric
            let previewUsername = student.matric_number.toLowerCase();
            previewUsername = previewUsername.replace(/[^a-z0-9._-]/g, '');
            previewUsername = previewUsername.replace(/[\/\\\s]/g, '.');
            previewUsername = previewUsername.replace(/\.+/g, '.');
            previewUsername = previewUsername.replace(/^\.+|\.+$/g, '');
            
            html += `
            <tr>
                <td>${index + 1}</td>
                <td><strong>${student.matric_number}</strong></td>
                <td><code>${previewUsername}</code></td>
                <td>${student.first_name} ${student.last_name}</td>
                <td>${student.email}</td>
                <td><code>password</code></td>
                <td><span class="badge bg-success">Ready to Import</span></td>
            </tr>`;
        });
        
        html += `
                </tbody>
            </table>
        </div>`;
        
        if (totalCount > previewData.length) {
            html += `<div class="text-center text-muted mt-2">
                Showing first ${previewData.length} of ${totalCount} students
            </div>`;
        }
    }
    
    html += `
    <div class="alert alert-info mt-3">
        <i class="fas fa-exclamation-circle me-2"></i>
        <strong>Login Credentials:</strong><br>
        • Username: Generated from matric number (e.g., ALQ/2024/001 → alq2024001)<br>
        • Password: <code>password</code> (for all students)<br>
        • Students should change their password on first login
    </div>
    
    <div class="alert alert-primary">
        <i class="fas fa-check-circle me-2"></i>
        Click "Confirm Import" to add ${totalCount} students to the database
    </div>`;
    
    previewContent.innerHTML = html;
    previewModal.show();
    
    // Handle confirm import - pass ALL students, not just preview
    document.getElementById('confirmImportBtn').onclick = () => {
        this.confirmImport(this.allStudents);
    };
}
    
    confirmImport(students) {
        if (!students || students.length === 0) {
            this.showToast('No students to import', 'error');
            return;
        }
        
        this.showProgress('Importing Students', 'Please wait while we import the students...');
        
        // Update progress bar during import
        const progressBar = document.getElementById('progressBar');
        const progressDetails = document.getElementById('progressDetails');
        const processedCount = document.getElementById('processedCount');
        const totalCount = document.getElementById('totalCount');
        
        if (progressBar) progressBar.style.width = '0%';
        if (progressDetails) progressDetails.style.display = 'block';
        if (totalCount) totalCount.textContent = students.length;
        if (processedCount) processedCount.textContent = '0';
        
        fetch('ajax/confirm_import.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                students: students,
                csrf_token: this.getCsrfToken()
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            this.hideProgress();
            
            if (data.success) {
                let message = `${data.count} students imported successfully`;
                
                // Show detailed log if available
                if (data.log && data.log.length > 0) {
                    message += '\n\nImport Log:\n' + data.log.join('\n');
                }
                
                // Show success modal with details
                this.showSuccess('Import Successful', message, data.count, students.length);
                
                // Close preview modal
                const previewModal = bootstrap.Modal.getInstance(document.getElementById('previewModal'));
                if (previewModal) previewModal.hide();
                
                // Close CSV upload modal
                const uploadModal = bootstrap.Modal.getInstance(document.getElementById('uploadCSVModal'));
                if (uploadModal) uploadModal.hide();
                
                // Reload page after 3 seconds
                setTimeout(() => {
                    location.reload();
                }, 3000);
                
            } else {
                let errorMessage = data.message || 'Import failed';
                
                // Add error details if available
                if (data.errors && data.errors.length > 0) {
                    errorMessage += '\n\nErrors:\n' + data.errors.slice(0, 5).join('\n');
                    if (data.errors.length > 5) {
                        errorMessage += `\n...and ${data.errors.length - 5} more errors`;
                    }
                }
                
                if (data.error_details) {
                    errorMessage += `\n\nTechnical Details: ${JSON.stringify(data.error_details, null, 2)}`;
                }
                
                // Show error in modal for better visibility
                this.showDetailedError('Import Failed', errorMessage);
            }
        })
        .catch(error => {
            this.hideProgress();
            console.error('Import error:', error);
            
            let errorMessage = 'An error occurred during import';
            if (error.message.includes('NetworkError') || error.message.includes('Failed to fetch')) {
                errorMessage = 'Network error. Please check your connection and try again.';
            }
            
            this.showDetailedError('Import Error', errorMessage + '\n\nError: ' + error.message);
        });
    }
    
    showSuccess(title, message, successCount, totalCount, successStudents = []) {
    const successModalHTML = `
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>${title}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h4>${successCount} of ${totalCount} students imported successfully</h4>
                        <div class="alert alert-light mt-3">
                            <strong>Default Login Credentials:</strong><br>
                            • Username: Generated from matric number<br>
                            • Password: <code>password</code><br>
                            <small class="text-muted">Students should change password on first login</small>
                        </div>
                    </div>`;
    
    // Show sample imported students if available
    if (successStudents.length > 0) {
        successModalHTML += `
                    <div class="mt-4">
                        <h6>Sample Imported Students:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Matric No</th>
                                        <th>Username</th>
                                        <th>Name</th>
                                        <th>Password</th>
                                    </tr>
                                </thead>
                                <tbody>`;
        
        // Show first 5 imported students as sample
        const sampleStudents = successStudents.slice(0, 5);
        sampleStudents.forEach(student => {
            successModalHTML += `
                                    <tr>
                                        <td><strong>${student.matric}</strong></td>
                                        <td><code>${student.username}</code></td>
                                        <td>${student.student}</td>
                                        <td><code>password</code></td>
                                    </tr>`;
        });
        
        successModalHTML += `
                                </tbody>
                            </table>
                        </div>
                    </div>`;
    }
    
    successModalHTML += `
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        The page will refresh in 5 seconds to show the imported students.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="window.location.reload()">
                        <i class="fas fa-redo me-1"></i>Refresh Now
                    </button>
                </div>
            </div>
        </div>
    </div>`;
    
    // Remove existing success modal if any
    const existingModal = document.getElementById('successModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    document.body.insertAdjacentHTML('beforeend', successModalHTML);
    
    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
    successModal.show();
}
    
    showDetailedError(title, message) {
        const errorModalHTML = `
        <div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle me-2"></i>${title}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <pre class="mb-0" style="white-space: pre-wrap; word-wrap: break-word; max-height: 400px; overflow-y: auto;">${message}</pre>
                        </div>
                        <div class="mt-3">
                            <h6>Possible Solutions:</h6>
                            <ul>
                                <li>Check if all required database tables exist</li>
                                <li>Verify that column names in CSV match the template</li>
                                <li>Ensure matric numbers are unique</li>
                                <li>Check server error logs for more details</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" onclick="window.location.reload()">
                            <i class="fas fa-redo me-1"></i>Reload Page
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
        
        // Remove existing error modal if any
        const existingModal = document.getElementById('errorModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        document.body.insertAdjacentHTML('beforeend', errorModalHTML);
        
        const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
        errorModal.show();
    }
    
    showProgress(title, message) {
        const progressModal = new bootstrap.Modal(document.getElementById('progressModal'), {
            backdrop: 'static',
            keyboard: false
        });
        
        document.getElementById('progressTitle').textContent = title;
        document.getElementById('progressMessage').textContent = message;
        
        // Reset progress bar
        const progressBar = document.getElementById('progressBar');
        if (progressBar) progressBar.style.width = '0%';
        
        const progressDetails = document.getElementById('progressDetails');
        if (progressDetails) progressDetails.style.display = 'none';
        
        progressModal.show();
    }
    
    hideProgress() {
        const progressModal = bootstrap.Modal.getInstance(document.getElementById('progressModal'));
        if (progressModal) progressModal.hide();
    }
    
    updateProgress(percentage, processed, total) {
        const progressBar = document.getElementById('progressBar');
        const processedCount = document.getElementById('processedCount');
        const totalCount = document.getElementById('totalCount');
        const progressDetails = document.getElementById('progressDetails');
        
        if (progressBar) progressBar.style.width = `${percentage}%`;
        if (processedCount) processedCount.textContent = processed;
        if (totalCount) totalCount.textContent = total;
        if (progressDetails) progressDetails.style.display = 'block';
    }
    
    showError(input, message) {
        this.clearError(input);
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback d-block';
        errorDiv.textContent = message;
        
        input.classList.add('is-invalid');
        input.parentNode.appendChild(errorDiv);
    }
    
    clearError(input) {
        input.classList.remove('is-invalid');
        
        const errorDiv = input.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) errorDiv.remove();
    }
    
    showToast(message, type = 'info') {
        const toastContainer = document.querySelector('.toast-container') || this.createToastContainer();
        const toastId = 'toast-' + Date.now();
        
        const typeIcons = {
            'success': 'check-circle',
            'error': 'exclamation-circle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        
        const toastHTML = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-${typeIcons[type] || 'info-circle'} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement);
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    createToastContainer() {
        const container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        document.body.appendChild(container);
        return container;
    }
    
    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.studentModalManager = new StudentModalManager();
});

