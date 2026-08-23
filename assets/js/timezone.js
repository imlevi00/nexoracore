/**
 * فایلەکانی کاتی و بەروار بۆ JavaScript
 * assets/js/timezone.js
 */

class TimezoneManager {
    constructor() {
        this.timezone = 'Asia/Baghdad';
        this.locale = 'ku-IQ';
    }
    
    /**
     * وەرگرتنی کاتی ئێستا بە فۆرماتی دروست
     */
    now(format = 'full') {
        const now = new Date();
        
        switch (format) {
            case 'full':
                return now.toLocaleString(this.locale, {
                    timeZone: this.timezone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            case 'date':
                return now.toLocaleDateString(this.locale, {
                    timeZone: this.timezone
                });
            case 'time':
                return now.toLocaleTimeString(this.locale, {
                    timeZone: this.timezone
                });
            case 'iso':
                return now.toISOString();
            default:
                return now.toLocaleString(this.locale, {
                    timeZone: this.timezone
                });
        }
    }
    
    /**
     * فۆرماتکردنی کات بە فۆرماتی دیاریکراو
     */
    format(date, format = 'full') {
        if (typeof date === 'string') {
            date = new Date(date);
        }
        
        switch (format) {
            case 'full':
                return date.toLocaleString(this.locale, {
                    timeZone: this.timezone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            case 'date':
                return date.toLocaleDateString(this.locale, {
                    timeZone: this.timezone
                });
            case 'time':
                return date.toLocaleTimeString(this.locale, {
                    timeZone: this.timezone
                });
            case 'short':
                return date.toLocaleDateString(this.locale, {
                    timeZone: this.timezone,
                    year: '2-digit',
                    month: '2-digit',
                    day: '2-digit'
                });
            default:
                return date.toLocaleString(this.locale, {
                    timeZone: this.timezone
                });
        }
    }
    
    /**
     * حیسابکردنی جیاوازی کات
     */
    timeDiff(from, to = null) {
        if (to === null) {
            to = new Date();
        } else if (typeof to === 'string') {
            to = new Date(to);
        }
        
        if (typeof from === 'string') {
            from = new Date(from);
        }
        
        const diff = to - from;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        return {
            total: diff,
            days: days,
            hours: hours % 24,
            minutes: minutes % 60,
            seconds: seconds % 60
        };
    }
    
    /**
     * تاقیکردنەوەی کاتی بەروار
     */
    isValidDate(dateString) {
        const date = new Date(dateString);
        return date instanceof Date && !isNaN(date);
    }
    
    /**
     * گۆڕینی کات بۆ timezone ی جیاواز
     */
    convertToTimezone(date, targetTimezone) {
        if (typeof date === 'string') {
            date = new Date(date);
        }
        
        return new Date(date.toLocaleString('en-US', {
            timeZone: targetTimezone
        }));
    }
    
    /**
     * دروستکردنی کات بۆ فۆرماتی MySQL
     */
    toMySQLFormat(date = null) {
        if (date === null) {
            date = new Date();
        } else if (typeof date === 'string') {
            date = new Date(date);
        }
        
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }
    
    /**
     * وەرگرتنی کاتی ئێستا بۆ فۆرماتی MySQL
     */
    nowMySQL() {
        return this.toMySQLFormat();
    }
}

// دروستکردنی instance ی گشتی
const timezoneManager = new TimezoneManager();

// فانکشنە یارمەتیدەرەکان بۆ بەکارهێنانی ئاسان
function formatDate(date, format = 'full') {
    return timezoneManager.format(date, format);
}

function formatTime(date, format = 'time') {
    return timezoneManager.format(date, format);
}

function getCurrentTime(format = 'full') {
    return timezoneManager.now(format);
}

function timeDifference(from, to = null) {
    return timezoneManager.timeDiff(from, to);
}

function isValidDate(dateString) {
    return timezoneManager.isValidDate(dateString);
}

function toMySQLFormat(date = null) {
    return timezoneManager.toMySQLFormat(date);
}

// دانانی timezone ی گشتی بۆ هەموو فانکشنەکانی کات
Date.prototype.toIraqTime = function(format = 'full') {
    return timezoneManager.format(this, format);
};

// فانکشنی نوێکردنەوەی کاتژمێر بۆ dashboard
function updateClock(selector = '#current-time') {
    const clockElement = document.querySelector(selector);
    if (!clockElement) return;
    
    const updateTime = () => {
        clockElement.textContent = timezoneManager.now('full');
    };
    
    updateTime();
    setInterval(updateTime, 1000);
}

// فانکشنی نوێکردنەوەی کاتژمێر بۆ POS
function updatePOSClock(selector = '#pos-clock') {
    const clockElement = document.querySelector(selector);
    if (!clockElement) return;
    
    const updateTime = () => {
        clockElement.textContent = timezoneManager.now('time');
    };
    
    updateTime();
    setInterval(updateTime, 1000);
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        TimezoneManager,
        timezoneManager,
        formatDate,
        formatTime,
        getCurrentTime,
        timeDifference,
        isValidDate,
        toMySQLFormat,
        updateClock,
        updatePOSClock
    };
}
