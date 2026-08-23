<?php
/**
 * Lab catalog designer API (AJAX, JSON).
 * Actions for managing a test's columns, rows and cells.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_api_helpers.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';

header('Content-Type: application/json; charset=utf-8');

if (!($conn_kasher_platform instanceof mysqli)) {
    labJsonRespond(false, [], 'پەیوەندی داتابەیس بەردەست نییە', 500);
}
/** @var mysqli $conn_kasher_platform */
$conn = $conn_kasher_platform;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    labJsonRespond(false, [], 'ڕێگەی داواکاری نادروست', 405);
}
if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    labJsonRespond(false, [], 'نادروستی ئامنیەتی', 403);
}

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$action = trim((string)($_POST['action'] ?? ''));
$testId = (int)($_POST['test_id'] ?? 0);

$test = labFetchTest($conn, $userId, $labId, $testId);
if (!$test) {
    labJsonRespond(false, [], 'فەحس نەدۆزرایەوە', 404);
}

switch ($action) {
    case 'update_test':
        $name = trim((string)($_POST['name'] ?? ''));
        $group = trim((string)($_POST['group_name'] ?? ''));
        if ($name === '') {
            labJsonRespond(false, [], 'ناوی فەحس پێویستە');
        }
        $groupValue = $group !== '' ? $group : null;
        $stmt = $conn->prepare("UPDATE lab_tests SET name = ?, group_name = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND lab_id = ?");
        $stmt->bind_param('ssiii', $name, $groupValue, $testId, $userId, $labId);
        $stmt->execute();
        $stmt->close();
        labJsonRespond(true, [], 'پاشەکەوتکرا');
        break;

    case 'add_column':
        $label = trim((string)($_POST['label'] ?? ''));
        $type = (string)($_POST['col_type'] ?? 'text');
        if (!array_key_exists($type, labColumnTypes())) {
            $type = 'text';
        }
        if ($label === '') {
            $label = labColumnTypes()[$type];
        }
        $widthValue = labSanitizeWidth((string)($_POST['width'] ?? ''));
        $next = labNextSortOrder($conn, 'lab_test_columns', $testId);
        $stmt = $conn->prepare("INSERT INTO lab_test_columns (test_id, label, col_type, sort_order, width, options, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())");
        $stmt->bind_param('issis', $testId, $label, $type, $next, $widthValue);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true, ['column' => ['id' => $newId, 'label' => $label, 'col_type' => $type, 'sort_order' => $next]]);
        break;

    case 'update_column':
        $columnId = (int)($_POST['column_id'] ?? 0);
        if (!labColumnBelongs($conn, $testId, $columnId)) {
            labJsonRespond(false, [], 'ستوون نەدۆزرایەوە');
        }
        $label = trim((string)($_POST['label'] ?? ''));
        $type = (string)($_POST['col_type'] ?? 'text');
        if (!array_key_exists($type, labColumnTypes())) {
            $type = 'text';
        }
        if ($label === '') {
            $label = labColumnTypes()[$type];
        }
        $widthValue = labSanitizeWidth((string)($_POST['width'] ?? ''));
        $stmt = $conn->prepare("UPDATE lab_test_columns SET label = ?, col_type = ?, width = ? WHERE id = ? AND test_id = ?");
        $stmt->bind_param('sssii', $label, $type, $widthValue, $columnId, $testId);
        $stmt->execute();
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true, ['column' => ['id' => $columnId, 'label' => $label, 'col_type' => $type]]);
        break;

    case 'delete_column':
        $columnId = (int)($_POST['column_id'] ?? 0);
        if (!labColumnBelongs($conn, $testId, $columnId)) {
            labJsonRespond(false, [], 'ستوون نەدۆزرایەوە');
        }
        $stmt = $conn->prepare("DELETE FROM lab_test_columns WHERE id = ? AND test_id = ?");
        $stmt->bind_param('ii', $columnId, $testId);
        $stmt->execute();
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    case 'reorder_column':
        $columnId = (int)($_POST['column_id'] ?? 0);
        $direction = ($_POST['direction'] ?? 'up') === 'down' ? 'down' : 'up';
        if (!labColumnBelongs($conn, $testId, $columnId)) {
            labJsonRespond(false, [], 'ستوون نەدۆزرایەوە');
        }
        labReorder($conn, 'lab_test_columns', $testId, $columnId, $direction);
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    case 'add_row':
        $next = labNextSortOrder($conn, 'lab_test_rows', $testId);
        $stmt = $conn->prepare("INSERT INTO lab_test_rows (test_id, name, sort_order, result_type, created_at) VALUES (?, '', ?, 'numeric', NOW())");
        $stmt->bind_param('ii', $testId, $next);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true, ['row' => ['id' => $newId, 'sort_order' => $next]]);
        break;

    case 'update_row':
        $rowId = (int)($_POST['row_id'] ?? 0);
        if (!labRowBelongs($conn, $testId, $rowId)) {
            labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $resultType = ($_POST['result_type'] ?? 'numeric') === 'text' ? 'text' : 'numeric';
        $unit = trim((string)($_POST['unit'] ?? ''));
        $unitValue = $unit !== '' ? $unit : null;

        $normalMin = null;
        $normalMax = null;
        $normalMinMale = null;
        $normalMaxMale = null;
        $normalMinFemale = null;
        $normalMaxFemale = null;
        $normalText = null;

        $parseNumeric = static function (string $key): ?float {
            $raw = str_replace(',', '.', trim((string)($_POST[$key] ?? '')));
            return ($raw !== '' && is_numeric($raw)) ? (float)$raw : null;
        };

        if ($resultType === 'numeric') {
            $normalMinMale = $parseNumeric('normal_min_male');
            $normalMaxMale = $parseNumeric('normal_max_male');
            $normalMinFemale = $parseNumeric('normal_min_female');
            $normalMaxFemale = $parseNumeric('normal_max_female');

            if ($normalMinMale === $normalMinFemale && $normalMaxMale === $normalMaxFemale
                && ($normalMinMale !== null || $normalMaxMale !== null)) {
                $normalMin = $normalMinMale;
                $normalMax = $normalMaxMale;
            }
        } else {
            $txt = trim((string)($_POST['normal_text'] ?? ''));
            $normalText = $txt !== '' ? $txt : null;
        }

        $stmt = $conn->prepare("
            UPDATE lab_test_rows
            SET name = ?, result_type = ?,
                normal_min = ?, normal_max = ?,
                normal_min_male = ?, normal_max_male = ?,
                normal_min_female = ?, normal_max_female = ?,
                normal_text = ?, unit = ?
            WHERE id = ? AND test_id = ?
        ");
        $stmt->bind_param(
            'ssddddddssii',
            $name,
            $resultType,
            $normalMin,
            $normalMax,
            $normalMinMale,
            $normalMaxMale,
            $normalMinFemale,
            $normalMaxFemale,
            $normalText,
            $unitValue,
            $rowId,
            $testId
        );
        $stmt->execute();
        $stmt->close();

        // Optional age brackets. Only touched when the client sends `ranges`
        // (the modal always does; inline name autosave does not, preserving them).
        $savedRanges = [];
        if (array_key_exists('ranges', $_POST)) {
            $savedRanges = $resultType === 'numeric'
                ? labParseRowRangesInput((string)$_POST['ranges'])
                : [];
            labSaveRowRanges($conn, $testId, $rowId, $savedRanges);
        }

        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true, [
            'reference_display' => labReferenceDisplay([
                'result_type' => $resultType,
                'normal_min' => $normalMin,
                'normal_max' => $normalMax,
                'normal_min_male' => $normalMinMale,
                'normal_max_male' => $normalMaxMale,
                'normal_min_female' => $normalMinFemale,
                'normal_max_female' => $normalMaxFemale,
                'normal_text' => $normalText,
            ]),
        ], 'پاشەکەوتکرا');
        break;

    case 'delete_row':
        $rowId = (int)($_POST['row_id'] ?? 0);
        if (!labRowBelongs($conn, $testId, $rowId)) {
            labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
        }
        $stmt = $conn->prepare("DELETE FROM lab_test_rows WHERE id = ? AND test_id = ?");
        $stmt->bind_param('ii', $rowId, $testId);
        $stmt->execute();
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    case 'reorder_row':
        $rowId = (int)($_POST['row_id'] ?? 0);
        $direction = ($_POST['direction'] ?? 'up') === 'down' ? 'down' : 'up';
        if (!labRowBelongs($conn, $testId, $rowId)) {
            labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
        }
        labReorder($conn, 'lab_test_rows', $testId, $rowId, $direction);
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    case 'set_row_order':
    case 'set_column_order':
        $table = $action === 'set_row_order' ? 'lab_test_rows' : 'lab_test_columns';
        $checker = $action === 'set_row_order' ? 'labRowBelongs' : 'labColumnBelongs';
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? '')))));
        $upd = $conn->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ? AND test_id = ?");
        $position = 0;
        foreach ($ids as $entityId) {
            if (!$checker($conn, $testId, $entityId)) {
                continue;
            }
            $upd->bind_param('iii', $position, $entityId, $testId);
            $upd->execute();
            $position++;
        }
        $upd->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    case 'duplicate_row':
        $rowId = (int)($_POST['row_id'] ?? 0);
        if (!labRowBelongs($conn, $testId, $rowId)) {
            labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
        }
        $newRowId = labDuplicateTestRow($conn, $testId, $rowId);
        if ($newRowId <= 0) {
            labJsonRespond(false, [], 'ڕیز نەدۆزرایەوە');
        }
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true, ['row_id' => $newRowId]);
        break;

    case 'grid_html':
        $columns = labFetchColumns($conn, $testId);
        $rows = labFetchRows($conn, $testId);
        $cells = labFetchCells($conn, $testId);
        $columnTypes = labColumnTypes();
        ob_start();
        include dirname(__DIR__) . '/tests/_grid_partial.php';
        $html = ob_get_clean();
        labJsonRespond(true, ['html' => $html]);
        break;

    case 'update_cell':
        $rowId = (int)($_POST['row_id'] ?? 0);
        $columnId = (int)($_POST['column_id'] ?? 0);
        if (!labRowBelongs($conn, $testId, $rowId) || !labColumnBelongs($conn, $testId, $columnId)) {
            labJsonRespond(false, [], 'خانە نەدۆزرایەوە');
        }

        $hasOptions = array_key_exists('options', $_POST);
        if ($hasOptions) {
            $columns = labFetchColumns($conn, $testId);
            $columnType = null;
            foreach ($columns as $col) {
                if ((int)$col['id'] === $columnId) {
                    $columnType = (string)$col['col_type'];
                    break;
                }
            }
            if ($columnType !== 'choice') {
                labJsonRespond(false, [], 'هەڵبژاردن تەنها بۆ ستوونی Choose');
            }
            $parsedOptions = labParseColumnOptionsInput((string)($_POST['options'] ?? ''));
            if ($parsedOptions === []) {
                labJsonRespond(false, [], 'لانیکەم یەک هەڵبژاردن بنووسە');
            }
            $optionsJson = labEncodeColumnOptions($parsedOptions);
            $stmt = $conn->prepare("
                INSERT INTO lab_test_cells (test_id, row_id, column_id, content, options, created_at, updated_at)
                VALUES (?, ?, ?, '', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE options = VALUES(options), updated_at = NOW()
            ");
            $stmt->bind_param('iiis', $testId, $rowId, $columnId, $optionsJson);
            $stmt->execute();
            $stmt->close();
            labTouchTestUpdated($conn, $testId);
            labJsonRespond(true);
            break;
        }

        $content = (string)($_POST['content'] ?? '');
        $stmt = $conn->prepare("
            INSERT INTO lab_test_cells (test_id, row_id, column_id, content, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()
        ");
        $stmt->bind_param('iiis', $testId, $rowId, $columnId, $content);
        $stmt->execute();
        $stmt->close();
        labTouchTestUpdated($conn, $testId);
        labJsonRespond(true);
        break;

    default:
        labJsonRespond(false, [], 'کرداری نەناسراو', 400);
}
