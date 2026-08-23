<?php
/**
 * فایلەکانی کاتی و بەروار بۆ timezone ی عێراق
 * includes/timezone.php
 */

// دڵنیابوونەوە لە timezone ی دروست
if (!defined('DEFAULT_TIMEZONE')) {
    define('DEFAULT_TIMEZONE', 'Asia/Baghdad');
}

// دانانی timezone ی PHP
date_default_timezone_set(DEFAULT_TIMEZONE);

/**
 * کلاسی بەڕێوەبردنی کات و بەروار
 */
class TimezoneManager {
    
    private static $timezone = DEFAULT_TIMEZONE;
    
    /**
     * دانانی timezone
     */
    public static function setTimezone($timezone) {
        self::$timezone = $timezone;
        date_default_timezone_set($timezone);
    }
    
    /**
     * وەرگرتنی timezone ی ئێستا
     */
    public static function getTimezone() {
        return self::$timezone;
    }
    
    /**
     * دروستکردنی DateTime بە timezone ی دروست
     */
    public static function createDateTime($time = 'now') {
        return new DateTime($time, new DateTimeZone(self::$timezone));
    }
    
    /**
     * وەرگرتنی کاتی ئێستا
     */
    public static function now($format = 'Y-m-d H:i:s') {
        $now = self::createDateTime();
        return $now->format($format);
    }
    
    /**
     * گۆڕینی کات بۆ timezone ی جیاواز
     */
    public static function convertToTimezone($datetime, $targetTimezone) {
        if (is_string($datetime)) {
            $datetime = new DateTime($datetime, new DateTimeZone(self::$timezone));
        }
        
        $datetime->setTimezone(new DateTimeZone($targetTimezone));
        return $datetime;
    }
    
    /**
     * فۆرماتکردنی کات بۆ کوردی
     */
    public static function formatKurdish($datetime, $includeTime = false) {
        if (is_string($datetime)) {
            $datetime = new DateTime($datetime, new DateTimeZone(self::$timezone));
        }
        
        $months = [
            1 => 'کانونی دووەم', 2 => 'شوبات', 3 => 'ئازار',
            4 => 'نیسان', 5 => 'ئایار', 6 => 'حوزەیران',
            7 => 'تەمووز', 8 => 'ئاب', 9 => 'ئەیلوول',
            10 => 'تشرینی یەکەم', 11 => 'تشرینی دووەم', 12 => 'کانونی یەکەم'
        ];
        
        $day = $datetime->format('d');
        $month = $months[(int)$datetime->format('n')];
        $year = $datetime->format('Y');
        
        $formatted = "$day $month $year";
        
        if ($includeTime) {
            $time = $datetime->format('H:i');
            $formatted .= " - کاتژمێر $time";
        }
        
        return $formatted;
    }
    
    /**
     * حیسابکردنی جیاوازی کات
     */
    public static function timeDiff($from, $to = null) {
        if ($to === null) {
            $to = self::createDateTime();
        } else {
            $to = new DateTime($to, new DateTimeZone(self::$timezone));
        }
        
        $from = new DateTime($from, new DateTimeZone(self::$timezone));
        return $from->diff($to);
    }
    
    /**
     * تاقیکردنەوەی کاتی بەروار
     */
    public static function isValidDate($date, $format = 'Y-m-d H:i:s') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

/**
 * فانکشنە یارمەتیدەرەکان
 */

/**
 * وەرگرتنی کاتی ئێستا
 */
function now($format = 'Y-m-d H:i:s') {
    return TimezoneManager::now($format);
}

/**
 * دروستکردنی DateTime
 */
function createDateTime($time = 'now') {
    return TimezoneManager::createDateTime($time);
}
 
/**
 * فۆرماتکردنی کات بۆ کوردی (wrapper for existing formatKurdishDate)
 */
function formatKurdishDateTime($datetime, $includeTime = false) {
    return formatKurdishDate($datetime, $includeTime);
}

/**
 * گۆڕینی کات بۆ timezone ی جیاواز
 */
function convertTimezone($datetime, $targetTimezone) {
    return TimezoneManager::convertToTimezone($datetime, $targetTimezone);
}

/**
 * حیسابکردنی جیاوازی کات
 */
function timeDifference($from, $to = null) {
    return TimezoneManager::timeDiff($from, $to);
}

/**
 * تاقیکردنەوەی کاتی بەروار
 */
function isValidDateTime($date, $format = 'Y-m-d H:i:s') {
    return TimezoneManager::isValidDate($date, $format);
}

/**
 * وەرگرتنی کاتی ئێستا بۆ JavaScript
 */
function getCurrentTimeForJS() {
    return TimezoneManager::now('Y-m-d H:i:s');
}

/**
 * وەرگرتنی timezone ی ئێستا بۆ JavaScript
 */
function getTimezoneForJS() {
    return TimezoneManager::getTimezone();
}

?>
