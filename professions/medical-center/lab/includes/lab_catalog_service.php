<?php
/**
 * Lab catalog service — helpers for the lab test catalog, designer grid,
 * receipt template settings and result flagging.
 *
 * All functions are tenant/lab scoped. Pass the kasher_platform connection
 * ($conn_kasher_platform) explicitly.
 */

if (!function_exists('labColumnTypes')) {
    /**
     * @return array<string,string> column type => default label
     */
    function labColumnTypes(): array
    {
        return [
            'result'    => 'Result',
            'reference' => 'Reference Range',
            'unit'      => 'Unit',
            'status'    => 'Status',
            'text'      => 'Text',
            'choice'    => 'Choose',
        ];
    }
}

if (!function_exists('labParseColumnOptions')) {
    /**
     * Decode stored column options JSON into a list of non-empty strings.
     *
     * @return list<string>
     */
    function labParseColumnOptions(?string $storedJson): array
    {
        if ($storedJson === null || $storedJson === '') {
            return [];
        }
        $decoded = json_decode($storedJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $options = [];
        foreach ($decoded as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $options[] = mb_substr($value, 0, 100, 'UTF-8');
            }
        }
        return array_values(array_unique($options));
    }
}

if (!function_exists('labParseColumnOptionsInput')) {
    /**
     * Parse designer textarea input (one option per line, or comma-separated).
     *
     * @return list<string>
     */
    function labParseColumnOptionsInput(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\R/u', $raw) ?: [];
        if (count($lines) === 1 && str_contains($lines[0], ',')) {
            $lines = explode(',', $lines[0]);
        }
        $options = [];
        foreach ($lines as $line) {
            $value = trim((string)$line);
            if ($value !== '') {
                $options[] = mb_substr($value, 0, 100, 'UTF-8');
            }
        }
        return array_values(array_unique(array_slice($options, 0, 50)));
    }
}

if (!function_exists('labEncodeColumnOptions')) {
    /** Encode option list for storage; returns null when empty. */
    function labEncodeColumnOptions(array $options): ?string
    {
        $clean = [];
        foreach ($options as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $clean[] = mb_substr($value, 0, 100, 'UTF-8');
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return null;
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : null;
    }
}

if (!function_exists('labColumnOptionsDisplay')) {
    /** Format options for the designer textarea (one per line). */
    function labColumnOptionsDisplay(?string $storedJson): string
    {
        return implode("\n", labParseColumnOptions($storedJson));
    }
}

if (!function_exists('labCellContent')) {
    /** Read text content from a cell map entry (supports legacy string values). */
    function labCellContent(array $cells, int $rowId, int $colId): string
    {
        $cell = $cells[$rowId][$colId] ?? null;
        if (is_array($cell)) {
            return (string)($cell['content'] ?? '');
        }
        return (string)$cell;
    }
}

if (!function_exists('labCellOptionsJson')) {
    /** Read stored choice options JSON for a cell, or null when unset. */
    function labCellOptionsJson(array $cells, int $rowId, int $colId): ?string
    {
        $cell = $cells[$rowId][$colId] ?? null;
        if (!is_array($cell)) {
            return null;
        }
        $options = $cell['options'] ?? null;
        if ($options === null || $options === '') {
            return null;
        }
        return (string)$options;
    }
}

if (!function_exists('labResolveChoiceOptions')) {
    /**
     * Resolve choice options: per-row cell first, then column fallback for legacy tests.
     *
     * @return list<string>
     */
    function labResolveChoiceOptions(?string $cellOptionsJson, ?string $columnOptionsJson): array
    {
        $cellOptions = labParseColumnOptions($cellOptionsJson);
        if ($cellOptions !== []) {
            return $cellOptions;
        }
        return labParseColumnOptions($columnOptionsJson);
    }
}

if (!function_exists('labDefaultInfoFields')) {
    /**
     * Patient-info toggles shown on the receipt. value = enabled by default.
     *
     * @return array<string,array{label:string,default:bool}>
     */
    function labDefaultInfoFields(): array
    {
        return [
            'name'      => ['label' => 'ناوی نەخۆش', 'default' => true],
            'age'       => ['label' => 'تەمەن', 'default' => true],
            'gender'    => ['label' => 'ڕەگەز', 'default' => true],
            'doctor'    => ['label' => 'دکتۆر', 'default' => true],
            'date'      => ['label' => 'بەروار', 'default' => true],
            'order_no'  => ['label' => 'ژمارەی داواکاری', 'default' => true],
            'mobile'    => ['label' => 'مۆبایل', 'default' => false],
        ];
    }
}

if (!function_exists('labResolveInfoFields')) {
    /**
     * Merge stored info_fields JSON with defaults.
     *
     * @return array<string,bool>
     */
    function labResolveInfoFields(?string $storedJson): array
    {
        $stored = [];
        if ($storedJson !== null && $storedJson !== '') {
            $decoded = json_decode($storedJson, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        $resolved = [];
        foreach (labDefaultInfoFields() as $key => $meta) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? (bool)$stored[$key]
                : (bool)$meta['default'];
        }
        return $resolved;
    }
}

if (!function_exists('labFetchTests')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function labFetchTests(mysqli $conn, int $userId, int $labId, bool $activeOnly = false): array
    {
        $sql = "
            SELECT id, name, group_name, sort_order, is_active, show_on_receipt
            FROM lab_tests
            WHERE user_id = ? AND lab_id = ?
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY COALESCE(group_name, ''), sort_order, id";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $labId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('labFetchTest')) {
    /**
     * Fetch a single test scoped to user+lab, or null if not found/not owned.
     */
    function labFetchTest(mysqli $conn, int $userId, int $labId, int $testId): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, name, group_name, sort_order, is_active, show_on_receipt
            FROM lab_tests
            WHERE id = ? AND user_id = ? AND lab_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iii', $testId, $userId, $labId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('labFetchColumns')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function labFetchColumns(mysqli $conn, int $testId): array
    {
        $stmt = $conn->prepare("
            SELECT id, label, col_type, sort_order, width, options
            FROM lab_test_columns
            WHERE test_id = ?
            ORDER BY sort_order, id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('labFetchRows')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function labFetchRows(mysqli $conn, int $testId): array
    {
        $stmt = $conn->prepare("
            SELECT id, name, sort_order, result_type,
                   normal_min, normal_max,
                   normal_min_male, normal_max_male, normal_min_female, normal_max_female,
                   normal_text, unit
            FROM lab_test_rows
            WHERE test_id = ?
            ORDER BY sort_order, id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Attach optional age (± gender) brackets so every consumer of a row
        // automatically sees them for reference display and flag resolution.
        $rangesMap = labFetchRowRanges($conn, $testId);
        foreach ($rows as &$row) {
            $row['ranges'] = $rangesMap[(int)$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('labFetchRowRanges')) {
    /**
     * Fetch all age/gender brackets for a test, grouped by row id (ordered).
     *
     * @return array<int,list<array<string,mixed>>> map[row_id] = list of brackets
     */
    function labFetchRowRanges(mysqli $conn, int $testId): array
    {
        $stmt = $conn->prepare("
            SELECT id, row_id, sort_order, label, gender,
                   age_min_value, age_min_unit, age_max_value, age_max_unit,
                   normal_min, normal_max
            FROM lab_test_row_ranges
            WHERE test_id = ?
            ORDER BY row_id, sort_order, id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $map[(int)$r['row_id']][] = $r;
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('labAgeUnitToDays')) {
    /** Convert an (value, unit) age bound to an integer number of days. */
    function labAgeUnitToDays($value, ?string $unit): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $factor = match ($unit) {
            'day'   => 1,
            'month' => 30,
            'year'  => 365,
            default => 365,
        };
        return (int)round((float)$value * $factor);
    }
}

if (!function_exists('labPatientAgeInDays')) {
    /** Approximate a patient's age in days from stored years + months. Null when unknown. */
    function labPatientAgeInDays(?int $ageYears, ?int $ageMonths): ?int
    {
        $years = $ageYears !== null ? max(0, $ageYears) : 0;
        $months = $ageMonths !== null ? max(0, $ageMonths) : 0;
        if ($years === 0 && $months === 0 && $ageYears === null && $ageMonths === null) {
            return null;
        }
        return $years * 365 + $months * 30;
    }
}

if (!function_exists('labMatchRowRange')) {
    /**
     * Pick the bracket that fits a patient's age (and gender when set).
     * Gender-specific brackets win over 'any' brackets for the same age.
     *
     * @param list<array<string,mixed>> $ranges
     * @return array<string,mixed>|null
     */
    function labMatchRowRange(array $ranges, ?int $ageDays, ?string $gender = null): ?array
    {
        if ($ranges === [] || $ageDays === null) {
            return null;
        }
        $gender = $gender !== null ? strtolower(trim($gender)) : null;

        $ageFits = static function (array $r) use ($ageDays): bool {
            $minDays = labAgeUnitToDays($r['age_min_value'] ?? null, $r['age_min_unit'] ?? null);
            $maxDays = labAgeUnitToDays($r['age_max_value'] ?? null, $r['age_max_unit'] ?? null);
            if ($minDays !== null && $ageDays < $minDays) {
                return false;
            }
            if ($maxDays !== null && $ageDays >= $maxDays) {
                return false;
            }
            return true;
        };

        $anyMatch = null;
        foreach ($ranges as $r) {
            if (!$ageFits($r)) {
                continue;
            }
            $rowGender = strtolower((string)($r['gender'] ?? 'any'));
            if ($gender !== null && ($rowGender === 'male' || $rowGender === 'female')) {
                if ($rowGender === $gender) {
                    return $r; // gender-specific match wins immediately
                }
                continue;
            }
            if ($anyMatch === null && ($rowGender === 'any' || $rowGender === '')) {
                $anyMatch = $r;
            }
        }
        return $anyMatch;
    }
}

if (!function_exists('labFetchCells')) {
    /**
     * @return array<int,array<int,array{content:string,options:?string}>> map[row_id][column_id]
     */
    function labFetchCells(mysqli $conn, int $testId): array
    {
        $stmt = $conn->prepare("
            SELECT row_id, column_id, content, options
            FROM lab_test_cells
            WHERE test_id = ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $options = $row['options'] ?? null;
            $map[(int)$row['row_id']][(int)$row['column_id']] = [
                'content' => (string)$row['content'],
                'options' => ($options !== null && $options !== '') ? (string)$options : null,
            ];
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('labFormatNumericRange')) {
    /**
     * Human-readable min/max pair (e.g. "10 - 20", "≤ 5", "≥ 3").
     */
    function labFormatNumericRange($min, $max): string
    {
        $hasMin = $min !== null && $min !== '';
        $hasMax = $max !== null && $max !== '';
        if ($hasMin && $hasMax) {
            return labTrimNumber($min) . ' - ' . labTrimNumber($max);
        }
        if ($hasMax) {
            return '≤ ' . labTrimNumber($max);
        }
        if ($hasMin) {
            return '≥ ' . labTrimNumber($min);
        }
        return '';
    }
}

if (!function_exists('labResolveNormalRange')) {
    /**
     * Resolve min/max for a row, optionally by patient gender and age.
     *
     * Priority: matching age bracket → gender range → flat normal range.
     *
     * @return array{min: float|string|null, max: float|string|null}
     */
    function labResolveNormalRange(array $row, ?string $gender = null, ?int $ageDays = null): array
    {
        $type = (string)($row['result_type'] ?? 'numeric');
        if ($type !== 'numeric') {
            return ['min' => null, 'max' => null];
        }

        // Age brackets take precedence when the row defines them and we know the age.
        $ranges = $row['ranges'] ?? [];
        if (is_array($ranges) && $ranges !== []) {
            $match = labMatchRowRange($ranges, $ageDays, $gender);
            if ($match !== null) {
                return ['min' => $match['normal_min'] ?? null, 'max' => $match['normal_max'] ?? null];
            }
        }

        $gender = $gender !== null ? strtolower(trim($gender)) : null;
        $min = null;
        $max = null;

        if ($gender === 'male') {
            $min = $row['normal_min_male'] ?? null;
            $max = $row['normal_max_male'] ?? null;
        } elseif ($gender === 'female') {
            $min = $row['normal_min_female'] ?? null;
            $max = $row['normal_max_female'] ?? null;
        }

        $hasGenderMin = $min !== null && $min !== '';
        $hasGenderMax = $max !== null && $max !== '';

        if (!$hasGenderMin && !$hasGenderMax) {
            $min = $row['normal_min'] ?? null;
            $max = $row['normal_max'] ?? null;
        }

        return ['min' => $min, 'max' => $max];
    }
}

if (!function_exists('labAgeUnitLabel')) {
    /** Short English label for an age unit (singular/plural aware). */
    function labAgeUnitLabel(?string $unit, bool $plural = true): string
    {
        $base = match ($unit) {
            'day'   => 'day',
            'month' => 'month',
            'year'  => 'year',
            default => '',
        };
        if ($base === '') {
            return '';
        }
        return $plural ? $base . 's' : $base;
    }
}

if (!function_exists('labFormatAgeBand')) {
    /**
     * Human-readable age band, e.g. "0-10 days", "10 days - 2 years",
     * "> 90 years", "< 30 days". Empty when no bounds are set.
     */
    function labFormatAgeBand(array $range): string
    {
        $minVal = $range['age_min_value'] ?? null;
        $maxVal = $range['age_max_value'] ?? null;
        $hasMin = $minVal !== null && $minVal !== '';
        $hasMax = $maxVal !== null && $maxVal !== '';
        if (!$hasMin && !$hasMax) {
            return '';
        }
        $minUnit = (string)($range['age_min_unit'] ?? '');
        $maxUnit = (string)($range['age_max_unit'] ?? '');
        $min = labTrimNumber($minVal);
        $max = labTrimNumber($maxVal);

        if ($hasMin && $hasMax) {
            if ($minUnit === $maxUnit) {
                return $min . '-' . $max . ' ' . labAgeUnitLabel($maxUnit);
            }
            return $min . ' ' . labAgeUnitLabel($minUnit) . ' - ' . $max . ' ' . labAgeUnitLabel($maxUnit);
        }
        if ($hasMin) {
            return '> ' . $min . ' ' . labAgeUnitLabel($minUnit);
        }
        return '< ' . $max . ' ' . labAgeUnitLabel($maxUnit);
    }
}

if (!function_exists('labReferenceDisplayHtml')) {
    /**
     * Safe HTML for a row's reference cell.
     *
     * When the row carries age brackets, renders each bracket on its own line
     * (optional group label, age band, value range) as shown on the receipt.
     * Otherwise falls back to the plain gender/flat display (escaped).
     */
    function labReferenceDisplayHtml(array $row, ?string $gender = null): string
    {
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $type = (string)($row['result_type'] ?? 'numeric');
        $ranges = $row['ranges'] ?? [];

        if ($type === 'numeric' && is_array($ranges) && $ranges !== []) {
            $out = '<div class="lab-ref-ages">';
            foreach ($ranges as $range) {
                $label = trim((string)($range['label'] ?? ''));
                if ($label !== '') {
                    $out .= '<div class="lab-ref-grp">' . $h($label) . '</div>';
                }
                $band = labFormatAgeBand($range);
                $value = labFormatNumericRange($range['normal_min'] ?? null, $range['normal_max'] ?? null);
                $rowGender = strtolower((string)($range['gender'] ?? 'any'));
                $genderTag = '';
                if ($rowGender === 'male') {
                    $genderTag = ' <span class="lab-ref-g">(M)</span>';
                } elseif ($rowGender === 'female') {
                    $genderTag = ' <span class="lab-ref-g">(F)</span>';
                }
                $out .= '<div class="lab-ref-age">'
                    . '<span class="lab-ref-band">' . $h($band) . $genderTag . '</span>'
                    . '<span class="lab-ref-val">' . $h($value) . '</span>'
                    . '</div>';
            }
            $out .= '</div>';
            return $out;
        }

        return $h(labReferenceDisplay($row, $gender));
    }
}

if (!function_exists('labReferenceDisplay')) {
    /**
     * Human-readable normal range for a row (used in the reference column).
     *
     * @param string|null $gender 'male'|'female' for patient-specific display; null shows both genders in designer.
     */
    function labReferenceDisplay(array $row, ?string $gender = null): string
    {
        $type = (string)($row['result_type'] ?? 'numeric');

        if ($type === 'numeric') {
            if ($gender !== null && $gender !== '') {
                $range = labResolveNormalRange($row, $gender);
                return labFormatNumericRange($range['min'], $range['max']);
            }

            $maleDisplay = labFormatNumericRange(
                $row['normal_min_male'] ?? null,
                $row['normal_max_male'] ?? null
            );
            $femaleDisplay = labFormatNumericRange(
                $row['normal_min_female'] ?? null,
                $row['normal_max_female'] ?? null
            );

            if ($maleDisplay === '' && $femaleDisplay === '') {
                return labFormatNumericRange($row['normal_min'] ?? null, $row['normal_max'] ?? null);
            }

            if ($maleDisplay !== '' && $femaleDisplay !== '' && $maleDisplay === $femaleDisplay) {
                return $maleDisplay;
            }

            $parts = [];
            if ($maleDisplay !== '') {
                $parts[] = 'نێر: ' . $maleDisplay;
            }
            if ($femaleDisplay !== '') {
                $parts[] = 'مێ: ' . $femaleDisplay;
            }
            if ($parts !== []) {
                return implode(' | ', $parts);
            }

            return labFormatNumericRange($row['normal_min'] ?? null, $row['normal_max'] ?? null);
        }

        if (!empty($row['normal_text'])) {
            return (string)$row['normal_text'];
        }
        return '';
    }
}

if (!function_exists('labTrimNumber')) {
    /**
     * Trim trailing zeros from a decimal string (12.5000 -> 12.5).
     */
    function labTrimNumber($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $str = (string)$value;
        if (strpos($str, '.') !== false) {
            $str = rtrim(rtrim($str, '0'), '.');
        }
        return $str;
    }
}

if (!function_exists('labComputeResultFlag')) {
    /**
     * Compare an entered value to a row's normal range.
     *
     * @return string|null 'high'|'low'|'normal'|'abnormal'|null (null = no judgement)
     */
    function labComputeResultFlag(array $row, $value, ?string $gender = null, ?int $ageDays = null): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || $value === null) {
            return null;
        }

        $type = (string)($row['result_type'] ?? 'numeric');

        if ($type === 'numeric') {
            $normalized = str_replace(['٫', ','], ['.', '.'], (string)$value);
            if (!is_numeric($normalized)) {
                return null;
            }
            $num = (float)$normalized;
            $range = labResolveNormalRange($row, $gender, $ageDays);
            $min = $range['min'];
            $max = $range['max'];
            $hasMin = $min !== null && $min !== '';
            $hasMax = $max !== null && $max !== '';
            if (!$hasMin && !$hasMax) {
                return null;
            }
            if ($hasMin && $num < (float)$min) {
                return 'low';
            }
            if ($hasMax && $num > (float)$max) {
                return 'high';
            }
            return 'normal';
        }

        // text / qualitative
        $expected = trim((string)($row['normal_text'] ?? ''));
        if ($expected === '') {
            return null;
        }
        return mb_strtolower($expected) === mb_strtolower((string)$value) ? 'normal' : 'abnormal';
    }
}

if (!function_exists('labFlagMarkup')) {
    /**
     * Bootstrap-icon markup for a computed flag (red up/down arrows, green ↔, warning).
     */
    function labFlagMarkup(?string $flag): string
    {
        switch ($flag) {
            case 'high':
                return '<i class="bi bi-arrow-up lab-flag lab-flag-high" title="بەرزتر لە نۆرمەڵ"></i>';
            case 'low':
                return '<i class="bi bi-arrow-down lab-flag lab-flag-low" title="نزمتر لە نۆرمەڵ"></i>';
            case 'normal':
                return '<i class="bi bi-arrow-left-right lab-flag lab-flag-normal" title="ئاسایی"></i>';
            case 'abnormal':
                return '<i class="bi bi-exclamation-triangle-fill lab-flag lab-flag-abnormal" title="نائاسایی"></i>';
            default:
                return '';
        }
    }
}

if (!function_exists('labResultFlagClass')) {
    /** CSS class for flag-colored result/choice inputs. */
    function labResultFlagClass(?string $flag): string
    {
        if ($flag === 'high' || $flag === 'low') {
            return 'lab-result-' . $flag;
        }
        if ($flag === 'abnormal') {
            return 'lab-result-abnormal';
        }
        return '';
    }
}

if (!function_exists('labColumnWidthStyle')) {
    /** Inline CSS width/min-width from a column's stored width value. */
    function labColumnWidthStyle(?string $width): string
    {
        $safe = labSanitizeWidth((string)($width ?? ''));
        if ($safe === null) {
            return '';
        }
        return 'width:' . $safe . ';min-width:' . $safe . ';';
    }
}

if (!function_exists('labHasStatusColumn')) {
    function labHasStatusColumn(array $columns): bool
    {
        foreach ($columns as $col) {
            if (($col['col_type'] ?? '') === 'status') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('labEnsureStatusColumn')) {
    /**
     * Guarantee a dedicated status column exists (separate from result).
     * Appends «دۆخ» at the end when missing.
     */
    function labEnsureStatusColumn(mysqli $conn, int $testId): void
    {
        $columns = labFetchColumns($conn, $testId);
        if (labHasStatusColumn($columns)) {
            return;
        }
        $next = labNextSortOrder($conn, 'lab_test_columns', $testId);
        $label = 'دۆخ';
        $type = 'status';
        $width = '42px';
        $stmt = $conn->prepare("
            INSERT INTO lab_test_columns (test_id, label, col_type, sort_order, width, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('issis', $testId, $label, $type, $next, $width);
        $stmt->execute();
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
    }
}

if (!function_exists('labFirstResultColumnId')) {
    function labFirstResultColumnId(array $columns): ?int
    {
        foreach ($columns as $col) {
            if (($col['col_type'] ?? '') === 'result') {
                return (int)$col['id'];
            }
        }
        return null;
    }
}

if (!function_exists('labFirstFlagSourceColumnId')) {
    /** First result or choice column used for status flag resolution. */
    function labFirstFlagSourceColumnId(array $columns): ?int
    {
        foreach ($columns as $col) {
            $type = (string)($col['col_type'] ?? '');
            if ($type === 'result' || $type === 'choice') {
                return (int)$col['id'];
            }
        }
        return null;
    }
}

if (!function_exists('labColumnComputesFlag')) {
    function labColumnComputesFlag(string $colType): bool
    {
        return $colType === 'result' || $colType === 'choice';
    }
}

if (!function_exists('labRowResultFlag')) {
    /**
     * Resolve the flag for a row from stored results (first result column).
     */
    function labRowResultFlag(array $columns, array $results, int $rowId): ?string
    {
        $resultColId = labFirstFlagSourceColumnId($columns);
        if ($resultColId === null) {
            return null;
        }
        $cell = $results[$rowId][$resultColId] ?? null;
        if (!is_array($cell)) {
            return null;
        }
        $flag = $cell['flag'] ?? null;
        return $flag !== null && $flag !== '' ? (string)$flag : null;
    }
}

if (!function_exists('labRenderResultTable')) {
    /**
     * Render a test's result table (read-only) for the doctor view or receipt.
     *
     * @param array $columns  rows from lab_test_columns
     * @param array $rows     rows from lab_test_rows
     * @param array $cells    map[row_id][column_id] = content (text columns)
     * @param array $results  map[row_id][column_id] = ['value'=>..,'flag'=>..]
     */
    function labRenderResultTable(array $columns, array $rows, array $cells, array $results, bool $forReceipt = false, ?string $gender = null): string
    {
        $tableClass = $forReceipt ? 'lab-receipt-table' : 'table table-bordered table-sm align-middle mb-0';
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $tableDir = $forReceipt ? ' dir="ltr"' : '';
        $out = '<table class="' . $tableClass . '"' . $tableDir . '><thead><tr>';
        $out .= '<th class="lab-col-parameter">Name</th>';
        foreach ($columns as $col) {
            $type = (string)$col['col_type'];
            $widthStyle = labColumnWidthStyle($col['width'] ?? null);
            $typeClass = 'lab-col-' . $type;
            $out .= '<th class="' . $typeClass . '"';
            if ($widthStyle !== '') {
                $out .= ' style="' . $h($widthStyle) . '"';
            }
            $out .= '>' . $h($col['label']) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $rowFlag = labRowResultFlag($columns, $results, $rowId);
            $out .= '<tr><td class="lab-col-parameter">' . $h($row['name']) . '</td>';
            foreach ($columns as $col) {
                $colId = (int)$col['id'];
                $type = (string)$col['col_type'];
                $widthStyle = labColumnWidthStyle($col['width'] ?? null);
                $typeClass = 'lab-col-' . $type;
                $tdAttr = ' class="' . $typeClass . '"';
                if ($widthStyle !== '') {
                    $tdAttr = ' class="' . $typeClass . '" style="' . $h($widthStyle) . '"';
                }

                if ($type === 'result' || $type === 'choice') {
                    $cell = $results[$rowId][$colId] ?? ['value' => '', 'flag' => null];
                    $value = (string)$cell['value'];
                    $flag = $cell['flag'] ?? null;
                    $cls = '';
                    if ($flag === 'high' || $flag === 'low') {
                        $cls = 'lab-result-' . $flag;
                    } elseif ($flag === 'abnormal') {
                        $cls = 'lab-result-abnormal';
                    }
                    $out .= '<td' . $tdAttr . '><span class="' . $cls . '">' . $h($value) . '</span></td>';
                } elseif ($type === 'status') {
                    $out .= '<td' . $tdAttr . '>' . labFlagMarkup($rowFlag) . '</td>';
                } elseif ($type === 'reference') {
                    $out .= '<td' . $tdAttr . '>' . labReferenceDisplayHtml($row, $gender) . '</td>';
                } elseif ($type === 'unit') {
                    $out .= '<td' . $tdAttr . '>' . $h($row['unit'] ?? '') . '</td>';
                } elseif ($type === 'text') {
                    $value = isset($results[$rowId][$colId])
                        ? (string)$results[$rowId][$colId]['value']
                        : labCellContent($cells, $rowId, $colId);
                    $out .= '<td' . $tdAttr . '>' . $h($value) . '</td>';
                } else {
                    $out .= '<td' . $tdAttr . '>' . $h(labCellContent($cells, $rowId, $colId)) . '</td>';
                }
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }
}

if (!function_exists('labSeedDefaultTestLayout')) {
    /**
     * Give a freshly created test a sensible starting grid:
     * columns [Result, Unit, Reference, Status] + one empty parameter row.
     */
    function labSeedDefaultTestLayout(mysqli $conn, int $testId): void
    {
        $columns = [
            ['Result', 'result'],
            ['Unit', 'unit'],
            ['Reference Range', 'reference'],
            ['Status', 'status'],
        ];
        $colStmt = $conn->prepare("
            INSERT INTO lab_test_columns (test_id, label, col_type, sort_order, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($colStmt) {
            foreach ($columns as $i => [$label, $type]) {
                $colStmt->bind_param('issi', $testId, $label, $type, $i);
                $colStmt->execute();
            }
            $colStmt->close();
        }

        $rowStmt = $conn->prepare("
            INSERT INTO lab_test_rows (test_id, name, sort_order, result_type, created_at)
            VALUES (?, '', 0, 'numeric', NOW())
        ");
        if ($rowStmt) {
            $rowStmt->bind_param('i', $testId);
            $rowStmt->execute();
            $rowStmt->close();
        }
    }
}

if (!function_exists('labFetchReceiptSettings')) {
    /**
     * Fetch the lab's receipt template settings, or a default array if none saved.
     */
    function labFetchReceiptSettings(mysqli $conn, int $userId, int $labId): array
    {
        $stmt = $conn->prepare("
            SELECT id, banner_url, stamp_url, header_text, footer_text, info_fields
            FROM lab_receipt_settings
            WHERE lab_id = ? AND user_id = ?
            LIMIT 1
        ");
        $settings = null;
        if ($stmt) {
            $stmt->bind_param('ii', $labId, $userId);
            $stmt->execute();
            $settings = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
        if ($settings === null) {
            return [
                'id' => 0,
                'banner_url' => null,
                'stamp_url' => null,
                'header_text' => '',
                'footer_text' => '',
                'info_fields' => labResolveInfoFields(null),
            ];
        }
        $settings['info_fields'] = labResolveInfoFields($settings['info_fields'] ?? null);
        return $settings;
    }
}

if (!function_exists('labSanitizeWidth')) {
    /** Whitelist a CSS width value (e.g. "120px", "20%"); returns null if invalid. */
    function labSanitizeWidth(string $width): ?string
    {
        $width = trim($width);
        if ($width === '') {
            return null;
        }
        return preg_match('/^\d{1,4}(px|%|em|rem)?$/', $width) ? $width : null;
    }
}

if (!function_exists('labColumnBelongs')) {
    function labColumnBelongs(mysqli $conn, int $testId, int $columnId): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM lab_test_columns WHERE id = ? AND test_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $columnId, $testId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('labRowBelongs')) {
    function labRowBelongs(mysqli $conn, int $testId, int $rowId): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM lab_test_rows WHERE id = ? AND test_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $rowId, $testId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('labNextSortOrder')) {
    function labNextSortOrder(mysqli $conn, string $table, int $testId): int
    {
        $allowed = ['lab_test_columns', 'lab_test_rows'];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM {$table} WHERE test_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['n'] ?? 0);
    }
}

if (!function_exists('labReorder')) {
    /** Move an entity up/down within a test by rewriting sequential sort_order. */
    function labReorder(mysqli $conn, string $table, int $testId, int $id, string $direction): void
    {
        $allowed = ['lab_test_columns', 'lab_test_rows'];
        if (!in_array($table, $allowed, true)) {
            return;
        }
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE test_id = ? ORDER BY sort_order, id");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($r = $result->fetch_assoc()) {
            $ids[] = (int)$r['id'];
        }
        $stmt->close();

        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return;
        }
        $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
        if ($swapWith < 0 || $swapWith >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$swapWith]] = [$ids[$swapWith], $ids[$pos]];

        $upd = $conn->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ? AND test_id = ?");
        if (!$upd) {
            return;
        }
        foreach ($ids as $order => $entityId) {
            $upd->bind_param('iii', $order, $entityId, $testId);
            $upd->execute();
        }
        $upd->close();
    }
}

if (!function_exists('labDuplicateTestRow')) {
    function labDuplicateTestRow(mysqli $conn, int $testId, int $rowId): int
    {
        $src = $conn->prepare("
            SELECT name, result_type,
                   normal_min, normal_max,
                   normal_min_male, normal_max_male, normal_min_female, normal_max_female,
                   normal_text, unit
            FROM lab_test_rows WHERE id = ? AND test_id = ? LIMIT 1
        ");
        if (!$src) {
            return 0;
        }
        $src->bind_param('ii', $rowId, $testId);
        $src->execute();
        $srcRow = $src->get_result()->fetch_assoc();
        $src->close();
        if (!$srcRow) {
            return 0;
        }

        $next = labNextSortOrder($conn, 'lab_test_rows', $testId);
        $ins = $conn->prepare("
            INSERT INTO lab_test_rows (
                test_id, name, sort_order, result_type,
                normal_min, normal_max,
                normal_min_male, normal_max_male, normal_min_female, normal_max_female,
                normal_text, unit, created_at
            )
            SELECT test_id, name, ?, result_type,
                   normal_min, normal_max,
                   normal_min_male, normal_max_male, normal_min_female, normal_max_female,
                   normal_text, unit, NOW()
            FROM lab_test_rows WHERE id = ? AND test_id = ?
        ");
        if (!$ins) {
            return 0;
        }
        $ins->bind_param('iii', $next, $rowId, $testId);
        $ins->execute();
        $newRowId = (int)$ins->insert_id;
        $ins->close();
        if ($newRowId <= 0) {
            return 0;
        }

        $cellIns = $conn->prepare("
            INSERT INTO lab_test_cells (test_id, row_id, column_id, content, options, created_at, updated_at)
            SELECT test_id, ?, column_id, content, options, NOW(), NOW()
            FROM lab_test_cells WHERE test_id = ? AND row_id = ?
        ");
        if ($cellIns) {
            $cellIns->bind_param('iii', $newRowId, $testId, $rowId);
            $cellIns->execute();
            $cellIns->close();
        }

        $rangeIns = $conn->prepare("
            INSERT INTO lab_test_row_ranges
                (test_id, row_id, sort_order, label, gender,
                 age_min_value, age_min_unit, age_max_value, age_max_unit,
                 normal_min, normal_max, created_at)
            SELECT test_id, ?, sort_order, label, gender,
                   age_min_value, age_min_unit, age_max_value, age_max_unit,
                   normal_min, normal_max, NOW()
            FROM lab_test_row_ranges WHERE test_id = ? AND row_id = ?
        ");
        if ($rangeIns) {
            $rangeIns->bind_param('iii', $newRowId, $testId, $rowId);
            $rangeIns->execute();
            $rangeIns->close();
        }

        return $newRowId;
    }
}

if (!function_exists('labParseRowRangesInput')) {
    /**
     * Decode the designer's JSON payload of age brackets into a clean list.
     * Drops empty brackets (no value range and no age bounds).
     *
     * @return list<array{label:?string,gender:string,age_min_value:?float,age_min_unit:?string,age_max_value:?float,age_max_unit:?string,normal_min:?float,normal_max:?float}>
     */
    function labParseRowRangesInput(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $allowedUnits = ['day', 'month', 'year'];
        $num = static function ($v): ?float {
            if ($v === null) {
                return null;
            }
            $v = str_replace(['٫', ','], ['.', '.'], trim((string)$v));
            return ($v !== '' && is_numeric($v)) ? (float)$v : null;
        };
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $minVal = $num($item['age_min_value'] ?? null);
            $maxVal = $num($item['age_max_value'] ?? null);
            $normMin = $num($item['normal_min'] ?? null);
            $normMax = $num($item['normal_max'] ?? null);

            // Ignore fully empty brackets.
            if ($minVal === null && $maxVal === null && $normMin === null && $normMax === null) {
                continue;
            }

            $minUnit = (string)($item['age_min_unit'] ?? 'year');
            $maxUnit = (string)($item['age_max_unit'] ?? 'year');
            $gender = (string)($item['gender'] ?? 'any');
            if (!in_array($gender, ['any', 'male', 'female'], true)) {
                $gender = 'any';
            }
            $label = trim((string)($item['label'] ?? ''));

            $out[] = [
                'label'         => $label !== '' ? mb_substr($label, 0, 120, 'UTF-8') : null,
                'gender'        => $gender,
                'age_min_value' => $minVal,
                'age_min_unit'  => $minVal !== null ? (in_array($minUnit, $allowedUnits, true) ? $minUnit : 'year') : null,
                'age_max_value' => $maxVal,
                'age_max_unit'  => $maxVal !== null ? (in_array($maxUnit, $allowedUnits, true) ? $maxUnit : 'year') : null,
                'normal_min'    => $normMin,
                'normal_max'    => $normMax,
            ];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('labSaveRowRanges')) {
    /**
     * Replace all age brackets for a row with the supplied normalized list.
     *
     * @param list<array<string,mixed>> $brackets output of labParseRowRangesInput()
     */
    function labSaveRowRanges(mysqli $conn, int $testId, int $rowId, array $brackets): void
    {
        $del = $conn->prepare("DELETE FROM lab_test_row_ranges WHERE test_id = ? AND row_id = ?");
        if ($del) {
            $del->bind_param('ii', $testId, $rowId);
            $del->execute();
            $del->close();
        }
        if ($brackets === []) {
            return;
        }
        $ins = $conn->prepare("
            INSERT INTO lab_test_row_ranges
                (test_id, row_id, sort_order, label, gender,
                 age_min_value, age_min_unit, age_max_value, age_max_unit,
                 normal_min, normal_max, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$ins) {
            return;
        }
        $sort = 0;
        foreach ($brackets as $b) {
            $label = $b['label'];
            $gender = $b['gender'];
            $minVal = $b['age_min_value'];
            $minUnit = $b['age_min_unit'];
            $maxVal = $b['age_max_value'];
            $maxUnit = $b['age_max_unit'];
            $normMin = $b['normal_min'];
            $normMax = $b['normal_max'];
            $ins->bind_param(
                'iiissdsdsdd',
                $testId,
                $rowId,
                $sort,
                $label,
                $gender,
                $minVal,
                $minUnit,
                $maxVal,
                $maxUnit,
                $normMin,
                $normMax
            );
            $ins->execute();
            $sort++;
        }
        $ins->close();
    }
}

if (!function_exists('labTouchTestUpdated')) {
    function labTouchTestUpdated(mysqli $conn, int $testId): void
    {
        $stmt = $conn->prepare("UPDATE lab_tests SET updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $stmt->close();
    }
}
