// Replace the downloadPDF function in results.php with this:
function downloadPDF() {
    // Show loading state
    const downloadBtn = document.querySelector('.btn-download');
    const originalText = downloadBtn.innerHTML;
    downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    downloadBtn.disabled = true;
    
    // Create a new window with printable content
    const printWindow = window.open('', '_blank');
    
    // Get current page content and modify it for printing
    const originalContent = document.querySelector('.results-container').innerHTML;
    const headerContent = document.querySelector('.header').innerHTML;
    const studentInfoContent = document.querySelector('.student-info').innerHTML;
    
    // Create printable HTML
    const printableHTML = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>Academic Result - <?php echo htmlspecialchars($selected_session); ?></title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                margin: 20px; 
                line-height: 1.4; 
                font-size: 12px;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                padding-bottom: 10px; 
                border-bottom: 3px solid #000; 
            }
            .university { 
                font-size: 18px; 
                font-weight: bold; 
                text-transform: uppercase; 
                margin-bottom: 5px;
            }
            .faculty { 
                font-size: 14px; 
                font-weight: bold; 
                margin-bottom: 5px;
            }
            .department { 
                font-size: 13px; 
                font-weight: bold; 
                margin-bottom: 5px;
            }
            .title { 
                font-size: 14px; 
                margin-top: 10px; 
                font-weight: bold; 
            }
            .session { 
                font-size: 13px; 
                margin-top: 5px; 
            }
            .student-info { 
                margin: 20px 0; 
            }
            .info-row { 
                margin: 5px 0; 
                font-size: 12px; 
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
                border: 1px solid #000; 
                font-size: 11px;
            }
            th { 
                background-color: #f2f2f2; 
                font-weight: bold; 
                padding: 8px; 
                border: 1px solid #000; 
                text-align: left; 
            }
            td { 
                padding: 8px; 
                border: 1px solid #000; 
            }
            .summary { 
                margin-top: 30px; 
            }
            .summary-row { 
                display: flex; 
                justify-content: space-between; 
                margin: 10px 0; 
            }
            .summary-col { 
                width: 32%; 
                padding: 10px; 
                border: 1px solid #000; 
                font-size: 11px;
            }
            .summary-title { 
                font-weight: bold; 
                margin-bottom: 5px; 
            }
            .footer { 
                margin-top: 40px; 
                text-align: center; 
                font-size: 10px; 
                color: #666; 
            }
            @media print {
                body { margin: 0; padding: 10px; }
                .header { margin-bottom: 20px; }
                table { margin: 15px 0; }
                @page { margin: 0.5cm; }
            }
            .no-print { display: none !important; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="university">AL-QALAM UNIVERSITY, KATSINA</div>
            <div class="faculty"><?php echo htmlspecialchars($faculty); ?></div>
            <div class="department">DEPARTMENT OF <?php echo strtoupper(htmlspecialchars($department)); ?></div>
            <div class="title">STUDENT SEMESTER RESULT</div>
            <div class="session"><?php echo getSemesterName($selected_semester); ?> <?php echo htmlspecialchars($selected_session); ?> SESSION</div>
        </div>
        
        <div class="student-info">
            <div class="info-row"><strong>Mat. Number:</strong> <?php echo htmlspecialchars($matric_number); ?></div>
            <div class="info-row"><strong>Name:</strong> <?php echo htmlspecialchars($student_name); ?></div>
            <div class="info-row"><strong>Level:</strong> <?php echo $current_level; ?></div>
            <div class="info-row"><strong>Programme:</strong> <?php echo htmlspecialchars($programme); ?></div>
        </div>
        
        ${document.querySelector('.results-table').outerHTML}
        
        <div class="summary">
            <div style="margin-bottom: 10px; font-weight: bold; font-size: 13px;">
                Remarks: <?php echo getAcademicStanding($cumulative_gpa); ?>
            </div>
            
            <div class="summary-row">
                <div class="summary-col">
                    <div class="summary-title">Current</div>
                    <div>
                        CUR: <?php echo $total_credits; ?><br>
                        CUE: <?php echo $total_credits; ?><br>
                        WGP: <?php echo number_format($total_weighted_gp, 1); ?><br>
                        GPA: <?php echo number_format($cumulative_gpa, 2); ?>
                    </div>
                </div>
                
                <div class="summary-col">
                    <div class="summary-title">Previous</div>
                    <div>
                        TCUR: -<br>
                        TCUE: -<br>
                        TWGP: -<br>
                        CGPA: -
                    </div>
                </div>
                
                <div class="summary-col">
                    <div class="summary-title">Cumulative</div>
                    <div>
                        TCUR: <?php echo $overall_credits ?? 0; ?><br>
                        TCUE: <?php echo $overall_credits ?? 0; ?><br>
                        TWGP: <?php echo number_format(($overall_weighted_gp ?? 0), 1); ?><br>
                        CGPA: <?php echo number_format(($overall_cgpa ?? 0), 2); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <div>No alteration on this document</div>
            <div>Student's copy</div>
            <div>Printed on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</div>
            <div class="no-print">
                <button onclick="window.print()" style="margin-top: 20px; padding: 10px 20px; background: #1a237e; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Print / Save as PDF
                </button>
            </div>
        </div>
        
        <script>
            // Auto-trigger print dialog
            window.onload = function() {
                setTimeout(() => {
                    window.print();
                }, 500);
            };
        </script>
    </body>
    </html>`;
    
    printWindow.document.write(printableHTML);
    printWindow.document.close();
    
    // Restore button after 3 seconds
    setTimeout(() => {
        downloadBtn.innerHTML = originalText;
        downloadBtn.disabled = false;
    }, 3000);
}