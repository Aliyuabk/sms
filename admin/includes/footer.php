<?php
// includes/footer.php
?>

        </div><!--//container-xl-->
    </div><!--//app-content-->
</div><!--//app-wrapper-->

<footer class="app-footer">
    <div class="container text-center py-3">
        <small class="copyright"> 
           &copy; <?php echo date('Y'); ?> Madarasatu Masjib Ibn Abbas . All rights reserved. V.1.0 <br>
            <!-- Designed by <a class="app-link" href="https://kowagurutech.ng" target="_blank">Kowaguru Tech LTD</a> -->
        </small>
       
            
    </div>
</footer><!--//app-footer-->

<!-- Javascript -->          
<script src="../assets/plugins/popper.min.js"></script>
<script src="../assets/plugins/bootstrap/js/bootstrap.min.js"></script>  

<!-- Charts JS -->
<script src="../assets/plugins/chart.js/chart.min.js"></script> 
<script src="../assets/js/index-charts.js"></script> 

<!-- Page Specific JS -->
<script src="../assets/js/app.js"></script> 

<script>
// Dashboard specific JavaScript
document.addEventListener("DOMContentLoaded", function() {
    
    // Animate progress bars on load
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.transition = 'width 1.5s ease-in-out';
            bar.style.width = width;
        }, 200);
    });
    
    // Add click animations to cards
    const statCards = document.querySelectorAll('.app-card-stat');
    statCards.forEach(card => {
        card.addEventListener('click', function() {
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = '';
            }, 200);
        });
    });
    
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Auto-refresh dashboard every 5 minutes (optional)
    setTimeout(function() {
        window.location.reload();
    }, 300000); // 5 minutes
});
</script>

</body>
</html>