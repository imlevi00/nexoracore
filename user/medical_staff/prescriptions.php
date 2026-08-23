<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/security.php';
require_once '../../config/kasher_platform/database.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';

SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
    $db = new Database();
    $GLOBALS['conn'] = $db->connect();
}
$conn = $GLOBALS['conn'];

$isMedicalCenterMode = false;
$settingsStmt = $conn->prepare("
    SELECT s.business_type_id, bt.code AS business_type_code
    FROM settings s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    WHERE s.user_id = ?
    LIMIT 1
");
if ($settingsStmt) {
    $settingsStmt->bind_param('i', $userId);
    $settingsStmt->execute();
    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();
    $businessTypeId = (int)($settingsRow['business_type_id'] ?? 0);
    $businessTypeCode = trim((string)($settingsRow['business_type_code'] ?? ''));
    $isMedicalCenterMode = ($businessTypeId === 3 || $businessTypeCode === 'pharmacy_and_medical_center');
}
if (!$isMedicalCenterMode) {
    setMessage('ئەم بەشە تەنها بۆ دەرمانخانە و سەنتەری پزیشکییە', 'warning');
    redirect(url('user/dashboard/index.php'));
}

requireMedicalStaffModuleAccess();

if (!($conn_kasher_platform instanceof mysqli)) {
    setMessage('داتابەیسی kasher_platform بەردەست نییە', 'danger');
    redirect(url('user/medical_staff/main.php'));
}

$csrfToken = Security::generateCSRFToken();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'update_status') {
            $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'pending'));
            if ($prescriptionId <= 0 || !in_array($status, ['pending', 'completed'], true)) {
                $errors[] = 'زانیاری نادروست';
            } else {
                $updateStmt = $conn_kasher_platform->prepare("
                    UPDATE medical_prescriptions
                    SET status = ?, updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");
                if ($updateStmt) {
                    $updateStmt->bind_param('sii', $status, $prescriptionId, $userId);
                    if ($updateStmt->execute()) {
                        setMessage('دۆخی ڕەچەتە نوێکرایەوە', 'success');
                        $updateStmt->close();
                        redirect(url('user/medical_staff/prescriptions.php'));
                    }
                    $updateStmt->close();
                }
                $errors[] = 'هەڵەیەک ڕوویدا لە نوێکردنەوەی دۆخ';
            }
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$prescriptions = [];

$sql = "
    SELECT mp.id, mp.status, mp.diagnosis, mp.created_at,
           p.name AS patient_name, p.mobile AS patient_mobile,
           d.name AS doctor_name
    FROM medical_prescriptions mp
    INNER JOIN medical_center_patients p ON p.id = mp.patient_id
    INNER JOIN doctors d ON d.id = mp.doctor_id
    WHERE mp.user_id = ? AND mp.status IN ('pending', 'completed')
";
$types = 'i';
$params = [$userId];
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.mobile LIKE ?)";
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'completed'], true)) {
    $sql .= " AND mp.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY mp.id DESC";
$stmt = $conn_kasher_platform->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $prescriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$itemsByPrescription = [];
if (!empty($prescriptions)) {
    $prescriptionIds = array_map(static fn($row) => (int)$row['id'], $prescriptions);
    $placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));
    $typesItems = str_repeat('i', count($prescriptionIds));
    $itemSql = "
        SELECT prescription_id, product_id, product_name_snapshot, quantity, unit_name_snapshot
        FROM medical_prescription_items
        WHERE prescription_id IN ($placeholders)
        ORDER BY id ASC
    ";
    $itemsStmt = $conn_kasher_platform->prepare($itemSql);
    if ($itemsStmt) {
        $itemsStmt->bind_param($typesItems, ...$prescriptionIds);
        $itemsStmt->execute();
        $itemRows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
        foreach ($itemRows as $itemRow) {
            $pid = (int)$itemRow['prescription_id'];
            if (!isset($itemsByPrescription[$pid])) {
                $itemsByPrescription[$pid] = [];
            }
            $itemsByPrescription[$pid][] = [
                'product_id' => (int)$itemRow['product_id'],
                'product_name_snapshot' => (string)$itemRow['product_name_snapshot'],
                'quantity' => (float)$itemRow['quantity'],
                'unit_name_snapshot' => (string)($itemRow['unit_name_snapshot'] ?? '')
            ];
        }
    }
}

$flashMessage = getMessage();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>بەڕێوەبردنی ڕەچەتەکان - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/medical_staff/medical-staff-dark.css'); ?>" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/medical_staff/main.php'); ?>"><i class="bi bi-hospital"></i> بەڕێوەبردنی ڕەچەتەکان</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?php echo url('user/medical_staff/main.php'); ?>"><i class="bi bi-arrow-right"></i> گەڕانەوە</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">گەڕان بە ناوی نەخۆش یان مۆبایل</label>
                    <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">دۆخ</label>
                    <select name="status" class="form-select">
                        <option value="">هەموو</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>چاوەڕوان</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>تەواوکراو</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> گەڕان</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">لیستی ڕەچەتەکان</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>ناسنامە</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>جۆری نەخۆشی</th>
                        <th>دەرمانەکان</th>
                        <th>دۆخ</th>
                        <th>کردار</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($prescriptions)): ?>
                        <tr><td colspan="7" class="text-center text-body-secondary py-4">هیچ ڕەچەتەێک نەدۆزرایەوە</td></tr>
                    <?php else: ?>
                        <?php foreach ($prescriptions as $prescription): ?>
                            <?php $pid = (int)$prescription['id']; $items = $itemsByPrescription[$pid] ?? []; ?>
                            <tr>
                                <td>#<?php echo $pid; ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars((string)$prescription['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <small class="text-body-secondary"><?php echo htmlspecialchars((string)$prescription['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars((string)$prescription['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$prescription['diagnosis'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (empty($items)): ?>
                                        <span class="text-body-secondary">-</span>
                                    <?php else: ?>
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                                $qtyLabel = rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.');
                                                if ($qtyLabel === '') { $qtyLabel = '1'; }
                                                $unitLabel = trim((string)($item['unit_name_snapshot'] ?? ''));
                                            ?>
                                            <div class="small">
                                                <?php echo htmlspecialchars($item['product_name_snapshot'], ENT_QUOTES, 'UTF-8'); ?>
                                                <span class="badge text-bg-light border text-body ms-1">
                                                    <?php echo htmlspecialchars($qtyLabel, ENT_QUOTES, 'UTF-8'); ?><?php echo $unitLabel !== '' ? ' ' . htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') : ''; ?>
                                                </span>
                                                <span class="text-body-secondary">(<?php echo (int)$item['product_id']; ?>)</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($prescription['status'] === 'completed'): ?>
                                        <span class="badge text-bg-success">ڕەچەتەی تەواوکراو</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">چاوەڕوان</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="prescription_id" value="<?php echo $pid; ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="pending" <?php echo $prescription['status'] === 'pending' ? 'selected' : ''; ?>>چاوەڕوان</option>
                                            <option value="completed" <?php echo $prescription['status'] === 'completed' ? 'selected' : ''; ?>>تەواوکراو</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">نوێکردنەوە</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
