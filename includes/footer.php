<?php
/**
 * خوارەوەی پەڕەی گشتی - includes/footer.php
 */

$additionalJS = $additionalJS ?? [];
$currentYear = date('Y');

?>

    <!-- Page Content Ends Here -->

    <!-- Footer (if not disabled) -->
    <?php if (!isset($hideFooter) || !$hideFooter): ?>
        <footer class="theme-footer no-print">
            <div class="container">
                <div class="row align-items-center gy-2">
                    <div class="col-md-6">
                        <p class="text-muted small mb-0">
                            © <?php echo $currentYear; ?> <?php echo SITE_NAME; ?>. هەموو مافەکان پارێزراون.
                        </p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <p class="text-muted small mb-0">
                            <i class="bi bi-clock"></i>
                            کاتی سێرڤەر: <?php echo getCurrentDateTime('Y/m/d H:i:s'); ?>
                            
                            <?php if (isUser() || isAdmin()): ?>
                                |
                                <i class="bi bi-person-circle"></i>
                                <?php
                                $user = getCurrentUser() ?? getCurrentAdmin();
                                echo $user ? htmlspecialchars($user['business_name'] ?? $user['username']) : 'نامۆ';
                                ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </footer>
    <?php endif; ?>

    <!-- Back to Top Button -->
    <button type="button" class="btn btn-primary position-fixed bottom-0 end-0 m-3 rounded-circle no-print" 
            id="backToTop" style="display: none;" title="گەڕانەوە بۆ سەرەوە">
        <i class="bi bi-arrow-up"></i>
    </button>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo asset('js/timezone.js'); ?>"></script>
    <script src="<?php echo asset('js/main.js'); ?>"></script>
    
    <!-- Additional JavaScript -->
    <?php if (!empty($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo is_url($js) ? $js : asset("js/$js"); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Global JavaScript Functions -->
    <script>
        // Global configuration
        window.POS_CONFIG = {
            baseUrl: '<?php echo url(); ?>',
            assetUrl: '<?php echo asset(); ?>',
            currentUser: <?php 
                $user = getCurrentUser() ?? getCurrentAdmin();
                echo $user ? json_encode([
                    'id' => $user['id'],
                    'name' => $user['business_name'] ?? $user['username'],
                    'type' => getCurrentUser() ? 'user' : 'admin'
                ]) : 'null';
            ?>,
            csrf_token: '<?php echo Security::generateCSRFToken(); ?>',
            currency: '<?php echo DEFAULT_CURRENCY; ?>',
            dateFormat: 'Y-m-d',
            timeFormat: 'H:i:s'
        };

        // Initialize global functions
        document.addEventListener('DOMContentLoaded', function() {
            // Back to top button
            const backToTop = document.getElementById('backToTop');
            if (backToTop) {
                window.addEventListener('scroll', function() {
                    if (window.pageYOffset > 300) {
                        backToTop.style.display = 'block';
                    } else {
                        backToTop.style.display = 'none';
                    }
                });
                
                backToTop.addEventListener('click', function() {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }
            
            // Auto-hide flash messages
            const flashMessages = document.querySelectorAll('.notification .alert');
            flashMessages.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    if (bsAlert) {
                        bsAlert.close();
                    }
                }, 5000);
            });
            
            // Form validation enhancement
            const forms = document.querySelectorAll('.needs-validation');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                        
                        // Find first invalid field and focus
                        const firstInvalid = form.querySelector(':invalid');
                        if (firstInvalid) {
                            firstInvalid.focus();
                        }
                    }
                    form.classList.add('was-validated');
                }, false);
            });
            
            // Confirm delete buttons
            const deleteButtons = document.querySelectorAll('.btn-delete, .delete-btn');
            deleteButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    const message = this.dataset.confirmMessage || 'ئایا دڵنیایت لە سڕینەوەی ئەم بابەتە؟';
                    if (!confirm(message)) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
            
            // Auto-refresh for certain pages (every 5 minutes)
            if (document.body.classList.contains('auto-refresh')) {
                setInterval(function() {
                    if (document.visibilityState === 'visible') {
                        window.location.reload();
                    }
                }, 300000); // 5 minutes
            }
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize popovers
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function(popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
            
            // console.log('<?php echo SITE_NAME; ?> - System Loaded Successfully');
        });
        
        // Global error handler
        window.addEventListener('error', function(e) {
            
            // Show user-friendly error message
            if (window.POS && window.POS.showNotification) {
                window.POS.showNotification('هەڵەیەکی نەخوازراو ڕوویدا. تکایە پەڕەکە نوێ بکەرەوە', 'error');
            }
        });
        
        // Handle unhandled promise rejections
        window.addEventListener('unhandledrejection', function(e) {
            e.preventDefault();
        });
        
        // Prevent right-click context menu in production (optional)
        <?php if (Config::get('app.debug') === false): ?>
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });
        <?php endif; ?>
        
        // Check if user is still logged in (every 10 minutes)
        <?php if (isUser() || isAdmin()): ?>
        setInterval(function() {
            fetch('<?php echo url("api/auth-check.php"); ?>')
                .then(response => response.json())
                .then(data => {
                    if (!data.authenticated) {
                        alert('ئەیلەتەکەت بەسەرچووە. دووبارە داخڵ دەبیت');
                        window.location.href = '<?php echo url(isUser() ? "user/auth/login.php" : "adminKx9mZpQa7WvRt4Ny6Lb3/login.php"); ?>';
                    }
                })
                .catch(error => {
                    // console.log('Auth check failed:', error);
                });
        }, 600000); // 10 minutes
        <?php endif; ?>
    </script>
    
    <!-- Custom Page JavaScript -->
    <?php if (isset($pageJS)): ?>
        <script><?php echo $pageJS; ?></script>
    <?php endif; ?>
    
    <!-- Development Tools (only in debug mode) -->
    <?php if (Config::get('app.debug', false)): ?>
        <script>
            console.log('Debug Mode: ON');
            console.log('PHP Version:', '<?php echo phpversion(); ?>');
            console.log('Memory Usage:', '<?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB');
            console.log('Peak Memory:', '<?php echo round(memory_get_peak_usage() / 1024 / 1024, 2); ?> MB');
            console.log('Execution Time:', '<?php echo round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2); ?> ms');
        </script>
    <?php endif; ?>

</body>
</html>