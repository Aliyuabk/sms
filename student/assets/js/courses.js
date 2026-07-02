/**
 * Courses Page JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initCourseRegistration();
    initMaterialDownloads();
    initSearchFilter();
});

/**
 * Initialize course registration functionality
 */
function initCourseRegistration() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const courseCheckboxes = document.querySelectorAll('.course-checkbox');
    const registerBtn = document.getElementById('registerBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            courseCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedCount();
        });
    }
    
    courseCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.course-checkbox:checked');
        const count = checked.length;
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = count;
        }
        
        if (registerBtn) {
            registerBtn.disabled = count === 0;
            
            // Update button text based on selection
            if (count > 0) {
                registerBtn.innerHTML = `<i class="fas fa-save"></i> Register ${count} Selected Course${count > 1 ? 's' : ''}`;
            } else {
                registerBtn.innerHTML = '<i class="fas fa-save"></i> Register Selected Courses';
            }
        }
        
        // Calculate total credits
        calculateTotalCredits();
    }
    
    function calculateTotalCredits() {
        const checked = document.querySelectorAll('.course-checkbox:checked');
        let totalCredits = 0;
        
        checked.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const creditsCell = row.querySelector('td:nth-child(4)');
            if (creditsCell) {
                totalCredits += parseInt(creditsCell.textContent) || 0;
            }
        });
        
        // Show credit warning if needed
        const creditWarning = document.getElementById('creditWarning');
        if (creditWarning) {
            if (totalCredits > 24) {
                creditWarning.style.display = 'block';
                if (registerBtn) registerBtn.disabled = true;
            } else {
                creditWarning.style.display = 'none';
            }
        }
    }
    
    // Initialize on page load
    updateSelectedCount();
}

/**
 * Initialize material downloads
 */
function initMaterialDownloads() {
    const downloadBtns = document.querySelectorAll('.download-material');
    
    downloadBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const courseId = this.dataset.courseId;
            const materialType = this.dataset.type;
            
            downloadMaterial(courseId, materialType);
        });
    });
}

/**
 * Initialize search and filter
 */
function initSearchFilter() {
    const searchInput = document.getElementById('courseSearch');
    const filterSelect = document.getElementById('courseFilter');
    const courseRows = document.querySelectorAll('.courses-table tbody tr');
    
    if (searchInput) {
        searchInput.addEventListener('input', filterCourses);
    }
    
    if (filterSelect) {
        filterSelect.addEventListener('change', filterCourses);
    }
    
    function filterCourses() {
        const searchTerm = searchInput?.value.toLowerCase() || '';
        const filterValue = filterSelect?.value || '';
        
        courseRows.forEach(row => {
            const code = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
            const title = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
            const department = row.querySelector('td:nth-child(5)')?.textContent.toLowerCase() || '';
            
            const matchesSearch = searchTerm === '' || 
                code.includes(searchTerm) || 
                title.includes(searchTerm);
            
            const matchesFilter = filterValue === '' || 
                (filterValue === 'core' && row.querySelector('.badge-core')) ||
                (filterValue === 'elective' && row.querySelector('.badge-elective'));
            
            row.style.display = matchesSearch && matchesFilter ? '' : 'none';
        });
    }
}

/**
 * View course details
 */
function viewCourseDetails(courseId) {
    const modal = document.getElementById('courseModal');
    const modalBody = document.getElementById('courseModalBody');
    
    if (!modal || !modalBody) return;
    
    modalBody.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-3x"></i><p class="mt-2">Loading course details...</p></div>';
    modal.classList.add('active');
    
    fetch('ajax/get-course-details.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'course_id=' + courseId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayCourseDetails(data.course);
        } else {
            modalBody.innerHTML = '<p class="text-danger">Error loading course details</p>';
        }
    })
    .catch(error => {
        modalBody.innerHTML = '<p class="text-danger">An error occurred</p>';
        console.error('Error:', error);
    });
}

/**
 * Display course details in modal
 */
function displayCourseDetails(course) {
    const modalBody = document.getElementById('courseModalBody');
    
    const prerequisites = course.prerequisites ? course.prerequisites.join(', ') : 'None';
    
    modalBody.innerHTML = `
        <div class="course-details">
            <h3>${course.course_code}: ${course.course_title}</h3>
            
            <div class="detail-row">
                <div class="detail-label">Credit Units</div>
                <div class="detail-value">${course.credit_units}</div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Level</div>
                <div class="detail-value">${course.level} Level</div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Semester</div>
                <div class="detail-value">${course.semester == 1 ? 'First' : 'Second'} Semester</div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Department</div>
                <div class="detail-value">${course.department_name}</div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Course Type</div>
                <div class="detail-value">
                    <span class="badge badge-${course.is_core ? 'primary' : 'secondary'}">
                        ${course.is_core ? 'Core' : 'Elective'}
                    </span>
                </div>
            </div>
            
            <div class="detail-row">
                <div class="detail-label">Prerequisites</div>
                <div class="detail-value">${prerequisites}</div>
            </div>
            
            <div class="description">
                <h4>Course Description</h4>
                <p>${course.description || 'No description available.'}</p>
            </div>
            
            <div class="form-actions mt-3">
                <button class="btn btn-primary" onclick="addToSelection(${course.course_id})">
                    <i class="fas fa-plus"></i> Add to Selection
                </button>
            </div>
        </div>
    `;
}

/**
 * Add course to selection
 */
function addToSelection(courseId) {
    const checkbox = document.querySelector(`.course-checkbox[value="${courseId}"]`);
    if (checkbox) {
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change'));
        closeCourseModal();
        
        // Scroll to registration section
        document.getElementById('available-courses')?.scrollIntoView({ behavior: 'smooth' });
    }
}

/**
 * Close course modal
 */
function closeCourseModal() {
    const modal = document.getElementById('courseModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Download course material
 */
function downloadMaterial(courseId, type) {
    // Show loading state
    const btn = event?.target.closest('button');
    if (btn) {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;
        
        fetch('ajax/get-download-link.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `course_id=${courseId}&type=${type}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.url) {
                window.open(data.url, '_blank');
            } else {
                showAlert('danger', 'File not available for download');
            }
        })
        .catch(error => {
            showAlert('danger', 'Download failed');
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    } else {
        window.location.href = `download-material.php?course_id=${courseId}&type=${type}`;
    }
}

/**
 * Print course schedule
 */
function printSchedule() {
    const printContent = document.querySelector('.courses-table').cloneNode(true);
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
            <head>
                <title>Course Schedule</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                    th { background: #f5f5f5; }
                </style>
            </head>
            <body>
                <h2>Course Schedule - ${new Date().toLocaleDateString()}</h2>
                ${printContent.outerHTML}
            </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

/**
 * Export course list
 */
function exportCourses() {
    const courses = [];
    const rows = document.querySelectorAll('.courses-table tbody tr');
    
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const cells = row.querySelectorAll('td');
            courses.push({
                code: cells[1]?.textContent.trim(),
                title: cells[2]?.textContent.trim(),
                credits: cells[3]?.textContent.trim(),
                status: cells[4]?.textContent.trim()
            });
        }
    });
    
    const csv = convertToCSV(courses);
    downloadCSV(csv, 'courses.csv');
}

/**
 * Convert array to CSV
 */
function convertToCSV(data) {
    const headers = ['Course Code', 'Course Title', 'Credit Units', 'Status'];
    const rows = data.map(course => [
        course.code,
        course.title,
        course.credits,
        course.status
    ]);
    
    return [headers, ...rows]
        .map(row => row.join(','))
        .join('\n');
}

/**
 * Download CSV file
 */
function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    
    a.href = url;
    a.download = filename;
    a.click();
    
    window.URL.revokeObjectURL(url);
}

// Export functions for HTML
window.viewCourseDetails = viewCourseDetails;
window.closeCourseModal = closeCourseModal;
window.downloadMaterial = downloadMaterial;
window.printSchedule = printSchedule;
window.exportCourses = exportCourses;