/**
 * Dashboard JavaScript for NexoraCore
 * Author: Amir Technology
 * Website: AmirTechOne.com
 */

// پەیکەری سەرەکی
const DashboardConfig = {
    refreshInterval: 300000, // 5 minutes
    animationDuration: 300,
    notificationDuration: 5000,
    chartColors: {
        sales: '#28a745',
        products: '#007bff', 
        customers: '#17a2b8',
        debt: '#ffc107',
        reports: '#6f42c1',
        settings: '#6c757d'
    }
};

// کلاسی سەرەکی داشبۆرد
class Dashboard {
    constructor() {
        this.init();
        this.bindEvents();
        this.startAutoRefresh();
        this.loadNotifications();
    }

    // دەستپێکردن
    init() {
        this.animateCards();
        this.initTooltips();
        this.initCharts();
        this.updateClock();
        this.checkConnectivity();
    }

    // بەستنەوەی ڕووداوەکان
    bindEvents() {
        // کلیک لەسەر کارتەکان
        document.querySelectorAll('.dashboard-card').forEach(card => {
            card.addEventListener('click', this.handleCardClick.bind(this));
        });

        // ڕەفرێش دووگمە
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', this.refreshData.bind(this));
        }

        // فۆرمی گەڕان
        const searchInput = document.getElementById('dashboard-search');
        if (searchInput) {
            searchInput.addEventListener('input', this.handleSearch.bind(this));
        }

        // کورتی تەختەکلیل
        document.addEventListener('keydown', this.handleKeyboardShortcuts.bind(this));

        // ڕووداوی گۆڕینی پەنجەرە
        window.addEventListener('resize', this.handleResize.bind(this));
        window.addEventListener('online', this.handleOnline.bind(this));
        window.addEventListener('offline', this.handleOffline.bind(this));
    }

    // ئینیمەیشنی کارتەکان
    animateCards() {
        const cards = document.querySelectorAll('.dashboard-card');
        cards.forEach((card, index) => {
            setTimeout(() => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                card.style.transition = 'all 0.6s ease-out';
                
                requestAnimationFrame(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                });
            }, index * 100);
        });
    }

    // دەستپێکردنی tooltip
    initTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // دەستپێکردنی چارتەکان
    initCharts() {
        this.createSalesChart();
        this.createStockChart();
        this.createRevenueChart();
    }

    // چارتی فرۆشتن
    createSalesChart() {
        const ctx = document.getElementById('salesChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['شەممە', 'یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی'],
                datasets: [{
                    label: 'فرۆشتن',
                    data: [12, 19, 3, 5, 2, 3, 10],
                    borderColor: DashboardConfig.chartColors.sales,
                    backgroundColor: DashboardConfig.chartColors.sales + '20',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // چارتی کۆگا
    createStockChart() {
        const ctx = document.getElementById('stockChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['بەدەست', 'کەم', 'بەسەرچوو'],
                datasets: [{
                    data: [70, 20, 10],
                    backgroundColor: [
                        DashboardConfig.chartColors.sales,
                        DashboardConfig.chartColors.debt,
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // چارتی داهات
    createRevenueChart() {
        const ctx = document.getElementById('revenueChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['کانونی دووەم', 'شوبات', 'ئادار', 'نیسان', 'ئایار', 'حوزەیران'],
                datasets: [{
                    label: 'داهات (هەزار دینار)',
                    data: [1200, 1900, 800, 1500, 2000, 1700],
                    backgroundColor: DashboardConfig.chartColors.reports,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // نوێکردنەوەی کاتژمێر
    updateClock() {
        const clockElement = document.getElementById('current-time');
        if (!clockElement) return;

        // Use timezone utility if available, otherwise fallback to original method
        if (typeof timezoneManager !== 'undefined') {
            updateClock('current-time');
        } else {
            const updateTime = () => {
                const now = new Date();
                const timeString = now.toLocaleString('ku-IQ', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    timeZone: 'Asia/Baghdad'
                });
                clockElement.textContent = timeString;
            };

            updateTime();
            setInterval(updateTime, 1000);
        }
    }

    // پشکنینی پەیوەندی ئینتەرنێت
    checkConnectivity() {
        const indicator = document.getElementById('connection-status');
        if (!indicator) return;

        const updateStatus = () => {
            if (navigator.onLine) {
                indicator.className = 'badge bg-success';
                indicator.innerHTML = '<i class="bi bi-wifi"></i> پەیوەستە';
            } else {
                indicator.className = 'badge bg-danger';
                indicator.innerHTML = '<i class="bi bi-wifi-off"></i> پەیوەندی نییە';
            }
        };

        updateStatus();
        window.addEventListener('online', updateStatus);
        window.addEventListener('offline', updateStatus);
    }

    // کلیک لەسەر کارت
    handleCardClick(event) {
        const card = event.currentTarget;
        const link = card.querySelector('a');
        
        if (link && !event.target.closest('a')) {
            // ئینیمەیشنی کلیک
            card.style.transform = 'scale(0.98)';
            setTimeout(() => {
                card.style.transform = '';
                window.location.href = link.href;
            }, 150);
        }
    }

    // نوێکردنەوەی داتا
    async refreshData() {
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
            refreshBtn.disabled = true;
        }

        try {
            // ناردنی داواکاری AJAX بۆ نوێکردنەوە
            const response = await fetch(window.location.href, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok) {
                // نوێکردنەوەی بەشە دیاریکراوەکان
                const newContent = await response.text();
                this.updateStatistics(newContent);
                this.showNotification('داتاکان نوێکرانەوە', 'success');
            }
        } catch (error) {
            this.showNotification('هەڵەیەک ڕوویدا لە نوێکردنەوەدا', 'error');
        } finally {
            if (refreshBtn) {
                refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
                refreshBtn.disabled = false;
            }
        }
    }

    // نوێکردنەوەی ئامارەکان
    updateStatistics(newContent) {
        const parser = new DOMParser();
        const newDoc = parser.parseFromString(newContent, 'text/html');
        
        // نوێکردنەوەی ژمارەکان
        document.querySelectorAll('.stat-number').forEach((element, index) => {
            const newElement = newDoc.querySelectorAll('.stat-number')[index];
            if (newElement) {
                this.animateNumber(element, newElement.textContent);
            }
        });

        // نوێکردنەوەی خشتەکان
        const tables = document.querySelectorAll('.recent-items table tbody');
        tables.forEach((table, index) => {
            const newTable = newDoc.querySelectorAll('.recent-items table tbody')[index];
            if (newTable) {
                table.innerHTML = newTable.innerHTML;
            }
        });
    }

    // ئینیمەیشنی ژمارە
    animateNumber(element, newValue) {
        const currentValue = parseInt(element.textContent.replace(/[^\d]/g, '')) || 0;
        const targetValue = parseInt(newValue.replace(/[^\d]/g, '')) || 0;
        
        if (currentValue === targetValue) return;

        const duration = 1000;
        const startTime = Date.now();
        
        const animate = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            const current = Math.round(currentValue + (targetValue - currentValue) * progress);
            element.textContent = current.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };
        
        animate();
    }

    // گەڕان
    handleSearch(event) {
        const searchTerm = event.target.value.toLowerCase();
        const cards = document.querySelectorAll('.dashboard-card');
        
        cards.forEach(card => {
            const title = card.querySelector('h4').textContent.toLowerCase();
            const isVisible = title.includes(searchTerm);
            
            card.style.display = isVisible ? 'block' : 'none';
            
            if (isVisible) {
                card.style.animation = 'fadeInUp 0.3s ease-out';
            }
        });
    }

    // کورتی تەختەکلیل
    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + R = نوێکردنەوە
        if ((event.ctrlKey || event.metaKey) && event.key === 'r') {
            event.preventDefault();
            this.refreshData();
        }
        
        // Ctrl/Cmd + N = فرۆشتنی نوێ
        if ((event.ctrlKey || event.metaKey) && event.key === 'n') {
            event.preventDefault();
            window.location.href = '/user/pos/index.php';
        }
        
        // Ctrl/Cmd + P = کاڵای نوێ
        if ((event.ctrlKey || event.metaKey) && event.key === 'p') {
            event.preventDefault();
            window.location.href = '/user/products/add.php';
        }
    }

    // گۆڕینی قەبارەی پەنجەرە
    handleResize() {
        // نوێکردنەوەی چارتەکان
        Chart.instances.forEach(chart => {
            chart.resize();
        });
    }

    // ئۆنلاین بوون
    handleOnline() {
        this.showNotification('پەیوەندی ئینتەرنێت گەڕایەوە', 'success');
        this.refreshData();
    }

    // ئۆفلاین بوون
    handleOffline() {
        this.showNotification('پەیوەندی ئینتەرنێت بڕا', 'warning');
    }

    // نیشاندانی ئاگاداری
    showNotification(message, type = 'info') {
        // لابردنی ئاگاداریە کۆنەکان
        const existingNotifications = document.querySelectorAll('.dashboard-notification');
        existingNotifications.forEach(notification => notification.remove());

        // درووستکردنی ئاگاداریی نوێ
        const notification = document.createElement('div');
        notification.className = `dashboard-notification alert alert-${this.getBootstrapAlertClass(type)} alert-dismissible fade show position-fixed`;
        notification.style.cssText = `
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        `;
        
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);

        // لابردنی خۆکار
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, DashboardConfig.notificationDuration);
    }

    // گۆڕینی جۆری ئاگاداری بۆ کلاسی Bootstrap
    getBootstrapAlertClass(type) {
        switch (type) {
            case 'error': return 'danger';
            case 'warning': return 'warning';
            case 'success': return 'success';
            default: return 'info';
        }
    }

    // بارکردنی ئاگاداریەکان
    loadNotifications() {
        // پشکنینی کاڵا کەمەکان
        const lowStockBadge = document.querySelector('.notification-badge');
        if (lowStockBadge && parseInt(lowStockBadge.textContent) > 0) {
            this.showNotification(
                `${lowStockBadge.textContent} کاڵا کەمە. تکایە کۆگا نوێ بکەرەوە.`,
                'warning'
            );
        }

        // پشکنینی کاڵا بەسەرچووەکان
        this.checkExpiredProducts();
    }

    // پشکنینی کاڵا بەسەرچووەکان
    async checkExpiredProducts() {
        try {
            const response = await fetch('/api/check-expired-products.php');
            const data = await response.json();
            
            if (data.expired > 0) {
                this.showNotification(
                    `${data.expired} کاڵا بەسەرچووە. تکایە بیانسڕەوە.`,
                    'error'
                );
            }
        } catch (error) {
   
        }
    }

    // دەستپێکردنی نوێکردنەوەی خۆکار
    startAutoRefresh() {
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refreshData();
            }
        }, DashboardConfig.refreshInterval);
    }

    // وەستاندنی نوێکردنەوەی خۆکار
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
    }
}

// کلاسی بەڕێوەبردنی چالاکی
class ActivityManager {
    constructor() {
        this.activities = [];
        this.init();
    }

    init() {
        this.trackUserActivity();
        this.saveActivity();
    }

    trackUserActivity() {
        // بەدواداچوونی کلیکەکان
        document.addEventListener('click', (event) => {
            if (event.target.closest('.dashboard-card, .action-btn')) {
                this.logActivity('click', event.target.closest('.dashboard-card, .action-btn'));
            }
        });

        // بەدواداچوونی گەڕان
        const searchInput = document.getElementById('dashboard-search');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                this.logActivity('search', searchInput.value);
            });
        }
    }

    logActivity(type, data) {
        const activity = {
            type: type,
            data: data,
            timestamp: new Date().toISOString(),
            url: window.location.href
        };

        this.activities.push(activity);
        
        // ڕاگرتنی تەنها ١٠٠ چالاکیی دوایی
        if (this.activities.length > 100) {
            this.activities.shift();
        }
    }

    saveActivity() {
        // ڕاگرتن لە localStorage
        try {
            localStorage.setItem('dashboard_activities', JSON.stringify(this.activities));
        } catch (error) {

        }
    }

    getActivities() {
        return this.activities;
    }
}

// دەستپێکردن کاتێک پەڕە بارکراو
document.addEventListener('DOMContentLoaded', function() {
    // دەستپێکردنی داشبۆرد
    window.dashboard = new Dashboard();
    
    // دەستپێکردنی بەڕێوەبردنی چالاکی
    window.activityManager = new ActivityManager();
    
    // زیادکردنی CSS ی تایبەتی بۆ ئینیمەیشنەکان
    const style = document.createElement('style');
    style.textContent = `
        .spin {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    // پاککردنەوەی console لە production
    // console.log('🎉 داشبۆردی NexoraCore بە سەرکەوتووی بارکرا!');
});

// فەنکشنە پاڵپشتیکراوەکان
window.DashboardUtils = {
    // فۆرماتکردنی ژمارە
    formatNumber: function(number) {
        return new Intl.NumberFormat('en-US').format(number);
    },
    
    // فۆرماتکردنی کات
    formatDate: function(date) {
        if (typeof timezoneManager !== 'undefined') {
            return timezoneManager.format(date, 'date');
        }
        return new Date(date).toLocaleDateString('ku-IQ', {
            timeZone: 'Asia/Baghdad'
        });
    },
    
    // رەنگی ڕاندۆم
    getRandomColor: function() {
        const colors = Object.values(DashboardConfig.chartColors);
        return colors[Math.floor(Math.random() * colors.length)];
    },
    
    // پشکنینی پشتگیری وێبگەڕ
    checkBrowserSupport: function() {
        const features = {
            localStorage: typeof(Storage) !== "undefined",
            flexbox: CSS.supports('display', 'flex'),
            grid: CSS.supports('display', 'grid')
        };
        
        return features;
    }
};