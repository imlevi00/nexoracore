<?php

function medicalCenterNormalizePatientGender(string $gender): ?string
{
    $gender = strtolower(trim($gender));

    if ($gender === 'male' || $gender === 'female') {
        return $gender;
    }

    return null;
}

function medicalCenterPatientGenderLabel(?string $gender, string $locale = 'ku'): string
{
    if ($locale === 'en') {
        return match ($gender) {
            'male' => 'Male',
            'female' => 'Female',
            default => '—',
        };
    }

    return match ($gender) {
        'male' => 'نێر',
        'female' => 'مێ',
        default => '—',
    };
}

/**
 * Optional free-text profession. Trimmed and capped at the column length (100).
 * Returns null when empty so the DB stores NULL rather than an empty string.
 */
function medicalCenterNormalizePatientProfession(string $profession): ?string
{
    $profession = trim($profession);
    if ($profession === '') {
        return null;
    }

    return mb_substr($profession, 0, 100);
}

/**
 * Optional free-text address. Trimmed and capped at the column length (255).
 * Returns null when empty so the DB stores NULL rather than an empty string.
 */
function medicalCenterNormalizePatientAddress(string $address): ?string
{
    $address = trim($address);
    if ($address === '') {
        return null;
    }

    return mb_substr($address, 0, 255);
}

/**
 * Blood-type options offered in the dropdown. Kept in one place so the form and
 * the validator stay in sync.
 *
 * @return string[]
 */
function medicalCenterBloodTypes(): array
{
    return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
}

/**
 * Optional blood type. Accepts only one of the known ABO/Rh values; anything
 * else (including empty) becomes null.
 */
function medicalCenterNormalizePatientBloodType(string $bloodType): ?string
{
    $bloodType = strtoupper(trim($bloodType));
    if ($bloodType === '') {
        return null;
    }

    return in_array($bloodType, medicalCenterBloodTypes(), true) ? $bloodType : null;
}

function medicalCenterNormalizePatientAgeMonths(string $ageMonths): ?int
{
    $ageMonths = trim($ageMonths);

    if ($ageMonths === '') {
        return null;
    }

    if (!ctype_digit($ageMonths)) {
        return null;
    }

    $value = (int)$ageMonths;

    if ($value < 0 || $value > 11) {
        return null;
    }

    return $value;
}

function medicalCenterPatientAgeLabel(int $age, ?int $ageMonths, string $locale = 'ku'): string
{
    $parts = [];

    if ($locale === 'en') {
        if ($age > 0) {
            $parts[] = $age . ($age === 1 ? ' year' : ' years');
        }

        if ($ageMonths !== null && $ageMonths > 0) {
            $parts[] = $ageMonths . ($ageMonths === 1 ? ' month' : ' months');
        }

        if ($parts === []) {
            return '0';
        }

        return implode(' and ', $parts);
    }

    if ($age > 0) {
        $parts[] = $age . ' ساڵ';
    }

    if ($ageMonths !== null && $ageMonths > 0) {
        $parts[] = $ageMonths . ' مانگ';
    }

    if ($parts === []) {
        return '0';
    }

    return implode(' و ', $parts);
}

function medicalCenterPatientVisitStatusLabel(string $status, string $locale = 'ku'): string
{
    if ($locale === 'en') {
        return match ($status) {
            'waiting' => 'Waiting',
            'with_doctor' => 'With doctor',
            'completed' => 'Completed',
            default => '—',
        };
    }

    return match ($status) {
        'waiting' => 'چاوەڕوان',
        'with_doctor' => 'لای دکتۆر',
        'completed' => 'تەواو بوو',
        default => '—',
    };
}

function medicalCenterPatientVisitStatusBadgeClass(string $status): string
{
    return match ($status) {
        'waiting' => 'text-bg-warning',
        'with_doctor' => 'text-bg-primary',
        'completed' => 'text-bg-success',
        default => 'text-bg-secondary',
    };
}

/**
 * @param array<string, mixed> $patient
 */
function renderDoctorQueuePatientMeta(array $patient, string $locale = 'ku'): string
{
    $age = htmlspecialchars(
        medicalCenterPatientAgeLabel(
            (int)$patient['age'],
            isset($patient['age_months']) ? (int)$patient['age_months'] : null,
            $locale
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    $gender = htmlspecialchars(
        medicalCenterPatientGenderLabel($patient['gender'] ?? null, $locale),
        ENT_QUOTES,
        'UTF-8'
    );
    $mobile = htmlspecialchars((string)$patient['mobile'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
        <div class="queue-patient-meta">
            <span><i class="bi bi-telephone"></i> {$mobile}</span>
            <span><i class="bi bi-person"></i> {$age} · {$gender}</span>
        </div>
    HTML;
}

function medicalCenterPrescriptionStatusLabel(string $status, string $locale = 'ku'): string
{
    if ($locale === 'en') {
        return match ($status) {
            'completed' => 'Completed',
            'pending' => 'Pending',
            'draft' => 'Draft',
            'consultation' => 'Consultation',
            default => '—',
        };
    }

    return match ($status) {
        'completed' => 'تەواوکراو',
        'pending' => 'چاوەڕوان',
        'draft' => 'ڕەشنووس',
        'consultation' => 'ڕاوێژ',
        default => '—',
    };
}

function medicalCenterPrescriptionStatusBadgeClass(string $status): string
{
    return match ($status) {
        'completed' => 'text-bg-success',
        'pending' => 'text-bg-warning',
        'draft' => 'text-bg-secondary',
        'consultation' => 'text-bg-info',
        default => 'text-bg-secondary',
    };
}

/**
 * Render a limited, safe subset of Markdown to HTML for clinical notes
 * (History / Examination / Diagnoses). Input is HTML-escaped BEFORE any markup
 * is applied, so user content can never inject tags.
 *
 * Supported: headings, bold, italic, inline code, links [text](url) with
 * http(s)/mailto/relative schemes only, unordered and ordered lists,
 * blockquotes and horizontal rules.
 */
function medicalCenterRenderMarkdown(?string $markdown): string
{
    $text = trim((string)$markdown);
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);

    $html = '';
    $listType = null; // 'ul' | 'ol'
    $inQuote = false;

    $closeList = static function () use (&$html, &$listType): void {
        if ($listType !== null) {
            $html .= '</' . $listType . '>';
            $listType = null;
        }
    };
    $closeQuote = static function () use (&$html, &$inQuote): void {
        if ($inQuote) {
            $html .= '</blockquote>';
            $inQuote = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $closeList();
            $closeQuote();
            continue;
        }

        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
            $closeList();
            $closeQuote();
            $html .= '<hr>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
            $closeList();
            $closeQuote();
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . medicalCenterRenderMarkdownInline($m[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
            $closeList();
            if (!$inQuote) {
                $html .= '<blockquote>';
                $inQuote = true;
            }
            $html .= medicalCenterRenderMarkdownInline($m[1]) . '<br>';
            continue;
        }
        $closeQuote();

        if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m)) {
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . medicalCenterRenderMarkdownInline($m[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . medicalCenterRenderMarkdownInline($m[1]) . '</li>';
            continue;
        }

        $closeList();
        $html .= '<p>' . medicalCenterRenderMarkdownInline($trimmed) . '</p>';
    }

    $closeList();
    $closeQuote();

    return $html;
}

/**
 * Inline Markdown for a single line. HTML-escapes first, then applies inline
 * markup. Kept in sync with the client-side preview renderer in doctor-ui.js.
 */
function medicalCenterRenderMarkdownInline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Inline code — protects its contents from further formatting.
    $text = preg_replace_callback('/`([^`]+)`/', static function (array $m): string {
        return '<code>' . $m[1] . '</code>';
    }, $text) ?? $text;

    // Links [text](url) — only allow safe schemes.
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $url = $m[2];
        if (!preg_match('#^(https?://|mailto:|/)#i', $url)) {
            return $m[0];
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text) ?? $text;

    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/(?<![a-zA-Z0-9])_([^_]+)_(?![a-zA-Z0-9])/', '<em>$1</em>', $text) ?? $text;

    return $text;
}

/**
 * Reduce Markdown to a clean single-line plain-text string for compact labels
 * (e.g. the diagnosis shown in the visit-history tree). Strips emphasis markers,
 * list bullets and heading hashes; collapses whitespace.
 */
function medicalCenterMarkdownToPlain(?string $markdown): string
{
    $text = (string)$markdown;
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;
    $text = preg_replace('/[*_#>`]+/', '', $text) ?? $text;
    $text = preg_replace('/^\s*[-+]\s+/m', '', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

/**
 * Allowed per-patient appointment slot lengths (in minutes) offered to the
 * secretary. Keeping this in one place lets the form, the validator and the
 * end-time calculation stay in sync.
 *
 * @return int[]
 */
function medicalCenterAppointmentDurations(): array
{
    return [10, 15, 20, 30, 45, 60];
}

function medicalCenterDefaultAppointmentDuration(): int
{
    return 10;
}

/**
 * Validate a date coming from an <input type="date"> (expects YYYY-MM-DD).
 * Returns the normalized date string or null when empty/invalid.
 */
function medicalCenterNormalizeAppointmentDate(string $date): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return null;
    }

    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }

    return $date;
}

/**
 * Validate a time coming from an <input type="time"> (expects HH:MM, may also
 * carry seconds). Returns a normalized HH:MM:SS string or null when
 * empty/invalid.
 */
function medicalCenterNormalizeAppointmentTime(string $time): ?string
{
    $time = trim($time);
    if ($time === '') {
        return null;
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
        return null;
    }

    $hours = (int)$m[1];
    $minutes = (int)$m[2];
    $seconds = isset($m[3]) ? (int)$m[3] : 0;

    if ($hours > 23 || $minutes > 59 || $seconds > 59) {
        return null;
    }

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

/**
 * Clamp a raw duration value to one of the allowed slot lengths, falling back
 * to the default when the value is not offered.
 */
function medicalCenterNormalizeAppointmentDuration(string $duration): int
{
    $value = (int)trim($duration);
    if (in_array($value, medicalCenterAppointmentDurations(), true)) {
        return $value;
    }

    return medicalCenterDefaultAppointmentDuration();
}

/**
 * Compute the end time of a slot from a normalized start time (HH:MM:SS) and a
 * duration in minutes. The result is capped at the end of the same day so a
 * slot can never spill past midnight into an unrelated date.
 */
function medicalCenterComputeAppointmentEndTime(string $startTime, int $durationMinutes): string
{
    $parts = explode(':', $startTime);
    $startSeconds = ((int)($parts[0] ?? 0)) * 3600
        + ((int)($parts[1] ?? 0)) * 60
        + ((int)($parts[2] ?? 0));

    $endSeconds = $startSeconds + max(0, $durationMinutes) * 60;
    $endSeconds = min($endSeconds, 24 * 3600 - 60); // cap at 23:59

    return sprintf(
        '%02d:%02d:%02d',
        intdiv($endSeconds, 3600),
        intdiv($endSeconds % 3600, 60),
        $endSeconds % 60
    );
}

/**
 * Derive the slot length (minutes) from a stored start/end pair so the edit
 * form can pre-select the matching duration. Falls back to the default when
 * the stored gap is not one of the offered lengths (or data is missing).
 */
function medicalCenterAppointmentDurationFromRange(?string $startTime, ?string $endTime): int
{
    $start = medicalCenterNormalizeAppointmentTime((string)$startTime);
    $end = medicalCenterNormalizeAppointmentTime((string)$endTime);
    if ($start === null || $end === null) {
        return medicalCenterDefaultAppointmentDuration();
    }

    $toMinutes = static function (string $t): int {
        $p = explode(':', $t);
        return ((int)($p[0] ?? 0)) * 60 + ((int)($p[1] ?? 0));
    };

    $diff = $toMinutes($end) - $toMinutes($start);
    if (in_array($diff, medicalCenterAppointmentDurations(), true)) {
        return $diff;
    }

    return medicalCenterDefaultAppointmentDuration();
}

/**
 * Reduce a stored TIME (HH:MM:SS) to a compact clock label (HH:MM). Returns an
 * empty string when there is no time.
 */
function medicalCenterFormatTimeLabel(?string $time): string
{
    $time = trim((string)$time);
    if ($time === '' || $time === '00:00:00') {
        // 00:00:00 is the SQL zero-time; treat a bare zero as "no time" only
        // when it is literally the default. A genuine midnight appointment is
        // not something this clinic workflow schedules.
        if ($time === '') {
            return '';
        }
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
        return '';
    }

    return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
}

/**
 * Format a start/end pair into a readable range, e.g. "15:00 – 15:10". Falls
 * back to just the start time when there is no end, or an empty string when
 * there is no appointment time at all.
 */
function medicalCenterFormatAppointmentRangeLabel(?string $startTime, ?string $endTime): string
{
    $start = medicalCenterFormatTimeLabel($startTime);
    if ($start === '') {
        return '';
    }

    $end = medicalCenterFormatTimeLabel($endTime);
    if ($end === '' || $end === $start) {
        return $start;
    }

    return $start . ' – ' . $end;
}

/**
 * Human-friendly date label for an appointment. Marks today/tomorrow specially
 * so the queue reads naturally.
 */
function medicalCenterFormatAppointmentDateLabel(?string $date, string $locale = 'ku'): string
{
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    if ($date === $today) {
        return $locale === 'en' ? 'Today' : 'ئەمڕۆ';
    }
    if ($date === $tomorrow) {
        return $locale === 'en' ? 'Tomorrow' : 'سبەینێ';
    }

    return $date;
}

/**
 * Current clock time rounded up to the next $step-minute boundary, as "HH:MM".
 * Used as the fallback suggested start time when a doctor has no appointments
 * booked yet for the day.
 */
function medicalCenterRoundedNowTime(int $step = 5): string
{
    $step = max(1, $step);
    $minutes = (int)date('G') * 60 + (int)date('i');
    $rounded = (int)(ceil($minutes / $step) * $step);
    $rounded = min($rounded, 23 * 60 + 55); // never roll into the next day
    return sprintf('%02d:%02d', intdiv($rounded, 60), $rounded % 60);
}

function medicalCenterIsValidVisitStatusTransition(string $from, string $to, string $role): bool
{
    if ($role === 'secretary') {
        return $from === 'waiting' && $to === 'with_doctor';
    }

    if ($role === 'doctor') {
        return $from === 'with_doctor' && $to === 'completed';
    }

    return false;
}
