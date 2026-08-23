<?php
/**
 * دەستڕاگەیشتنی Gmail بۆ فرۆشگای ئۆنلاین — user/website/shop_gmail_access.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../web/auth/shop_google_access.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];

if (isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub') {
    header('Location: ' . url('user/dashboard/index.php'));
    exit;
}

shop_google_ensure_db_schema($conn);

$success = '';
$error = '';

$stmt = $conn->prepare('SELECT * FROM website_settings WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$websiteSettings = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$websiteSettings) {
    header('Location: ' . url('user/website/index.php'));
    exit;
}

$csrf_token = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_shop_google_restrict') {
            $v = isset($_POST['shop_google_restrict']) ? 1 : 0;
            $u = $conn->prepare('UPDATE website_settings SET shop_google_restrict = ? WHERE user_id = ?');
            $u->bind_param('ii', $v, $userId);
            if ($u->execute()) {
                $websiteSettings['shop_google_restrict'] = $v;
                $success = $v ? 'سنووردان بە Gmail چالاککرا' : 'سنووردان بە Gmail ناچالاککرا';
                writeLog("shop_google_restrict=" . $v . " by user {$currentUser['email']}");
            } else {
                $error = 'هەڵە لە نوێکردنەوە';
            }
            $u->close();
        } elseif ($action === 'save_customer_shop_gmail') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $gmail = strtolower(trim($_POST['gmail'] ?? ''));
            $expiresRaw = trim($_POST['access_expires_at'] ?? '');

            if ($customerId <= 0) {
                $error = 'کڕیار دیاری نەکراوە';
            } elseif ($gmail === '' || !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'تکایە ئیمەیڵێکی دروست بنووسە';
            } else {
                $own = $conn->prepare('SELECT id, name FROM customers WHERE id = ? AND user_id = ?');
                $own->bind_param('ii', $customerId, $userId);
                $own->execute();
                $custRow = $own->get_result()->fetch_assoc();
                $own->close();
                if (!$custRow) {
                    $error = 'کڕیار نەدۆزرایەوە';
                } else {
                    $dup = $conn->prepare('SELECT customer_id FROM customer_gmail_links WHERE user_id = ? AND LOWER(TRIM(gmail)) = ? AND customer_id != ? LIMIT 1');
                    $dup->bind_param('isi', $userId, $gmail, $customerId);
                    $dup->execute();
                    $dupRow = $dup->get_result()->fetch_assoc();
                    $dup->close();
                    if ($dupRow) {
                        $error = 'ئەم Gmail ـە پێشتر بۆ کڕیارێکی تر تۆمارکراوە';
                    } else {
                        $conn->begin_transaction();
                        try {
                            $expSql = null;
                            if ($expiresRaw !== '') {
                                $ts = strtotime($expiresRaw . ' 23:59:59');
                                if ($ts === false) {
                                    throw new RuntimeException('بەروار نادروستە');
                                }
                                $expSql = date('Y-m-d H:i:s', $ts);
                            }

                            $ex = $conn->prepare('SELECT id FROM customer_gmail_links WHERE user_id = ? AND customer_id = ? LIMIT 1');
                            $ex->bind_param('ii', $userId, $customerId);
                            $ex->execute();
                            $linkRow = $ex->get_result()->fetch_assoc();
                            $ex->close();

                            if ($linkRow) {
                                $lid = (int) $linkRow['id'];
                                if ($expSql === null) {
                                    $up = $conn->prepare('UPDATE customer_gmail_links SET gmail = ?, access_expires_at = NULL WHERE id = ? AND user_id = ?');
                                    $up->bind_param('sii', $gmail, $lid, $userId);
                                } else {
                                    $up = $conn->prepare('UPDATE customer_gmail_links SET gmail = ?, access_expires_at = ? WHERE id = ? AND user_id = ?');
                                    $up->bind_param('ssii', $gmail, $expSql, $lid, $userId);
                                }
                                $up->execute();
                                $up->close();
                            } elseif ($expSql === null) {
                                $ins = $conn->prepare('INSERT INTO customer_gmail_links (user_id, customer_id, gmail) VALUES (?, ?, ?)');
                                $ins->bind_param('iis', $userId, $customerId, $gmail);
                                $ins->execute();
                                $ins->close();
                            } else {
                                $ins = $conn->prepare('INSERT INTO customer_gmail_links (user_id, customer_id, gmail, access_expires_at) VALUES (?, ?, ?, ?)');
                                $ins->bind_param('iiss', $userId, $customerId, $gmail, $expSql);
                                $ins->execute();
                                $ins->close();
                            }

                            $conn->commit();
                            $success = 'زانیارییەکان پاشکەوتکران';
                            writeLog("shop gmail access saved customer_id=$customerId user_id=$userId by {$currentUser['email']}");
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $error = $e instanceof RuntimeException ? $e->getMessage() : 'هەڵەیەک ڕوویدا';
                        }
                    }
                }
            }
        }
    }
}

$listSearch = cleanInput($_GET['search'] ?? '');
$listWhere = 'WHERE c.user_id = ?';
$listParams = [$userId];
$listTypes = 'i';

if ($listSearch !== '') {
    $listWhere .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR cgl.gmail LIKE ?)';
    $listTerm = '%' . $listSearch . '%';
    $listParams = array_merge($listParams, [$listTerm, $listTerm, $listTerm]);
    $listTypes .= 'sss';
}

$listStmt = $conn->prepare("
    SELECT c.id, c.name, c.phone, c.status,
           cgl.gmail AS customer_gmail,
           cgl.access_expires_at
    FROM customers c
    LEFT JOIN customer_gmail_links cgl ON cgl.customer_id = c.id AND cgl.user_id = c.user_id
    $listWhere
    ORDER BY c.name ASC
");
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$customerRows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$restrictOn = !empty($websiteSettings['shop_google_restrict']) && (int) $websiteSettings['shop_google_restrict'] === 1;
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>دەستڕاگەیشتنی Gmail — فرۆشگا</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="website-module-page website-gmail-page bg-light">
    <?php include_once '../../includes/navigation.php'; ?>

    <div class="container py-4 hub-page-content">
        <h2 class="mb-3"><i class="bi bi-google text-danger"></i> دەستڕاگەیشتنی Gmail بۆ فرۆشگای ئۆنلاین</h2>
        <p class="text-muted">کاتێک چالاک بکەیت، تەنها کڕیارانی تۆمارکراو بە Gmail دەتوانن فرۆشگا ببینن. بۆ هەر کڕیارێک Gmail و (ئەگەر دەتەوێت) بەرواری بەسەرچوون دابنێ.</p>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <form method="post" class="d-flex flex-wrap align-items-center gap-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="toggle_shop_google_restrict">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="shop_google_restrict" id="sgr" value="1" <?php echo $restrictOn ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="sgr">تەنها Gmail ڕێگەپێدراو بتوانێت فرۆشگا ببینێت</label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">پاشکەوتکردن</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>لیستی کڕیاران</span>
                <form method="get" class="d-flex gap-2" style="max-width: 360px; width: 100%;">
                    <input type="text" class="form-control form-control-sm" name="search"
                           value="<?php echo htmlspecialchars($listSearch); ?>"
                           placeholder="گەڕان بە ناو، تەلەفۆن یان Gmail..."
                           autocomplete="off">
                    <button type="submit" class="btn btn-sm btn-secondary text-nowrap">
                        <i class="bi bi-search"></i> گەڕان
                    </button>
                    <?php if ($listSearch !== ''): ?>
                        <a href="<?php echo url('user/website/shop_gmail_access.php'); ?>" class="btn btn-sm btn-outline-secondary" title="پاککردنەوە">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle shop-gmail-table">
                    <thead>
                        <tr>
                            <th>ناو</th>
                            <th>تەلەفۆن</th>
                            <th>Gmail</th>
                            <th>بەرواری بەسەرچوون</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customerRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['phone'] ?? '')); ?></td>
                                <td><?php echo $row['customer_gmail'] ? htmlspecialchars($row['customer_gmail']) : '—'; ?></td>
                                <td><?php echo !empty($row['access_expires_at']) ? htmlspecialchars($row['access_expires_at']) : 'بێ سنوور'; ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#gmailModal"
                                        data-cid="<?php echo (int) $row['id']; ?>"
                                        data-cname="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-gmail="<?php echo htmlspecialchars((string) ($row['customer_gmail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-exp="<?php echo !empty($row['access_expires_at']) ? htmlspecialchars(substr($row['access_expires_at'], 0, 10), ENT_QUOTES, 'UTF-8') : ''; ?>">
                                        <i class="bi bi-pencil"></i> دەستکاری
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="gmailModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Gmail و بەرواری بەسەرچوون</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_customer_shop_gmail">
                    <input type="hidden" name="customer_id" id="modal_customer_id" value="">
                    <p class="text-muted small" id="modal_customer_label"></p>
                    <div class="mb-3">
                        <label class="form-label">Gmail</label>
                        <input type="email" class="form-control" name="gmail" id="modal_gmail" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">بەرواری بەسەرچوون (بەتاڵ = بێ سنوور)</label>
                        <input type="date" class="form-control" name="access_expires_at" id="modal_exp">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                    <button type="submit" class="btn btn-primary">پاشکەوتکردن</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('gmailModal').addEventListener('show.bs.modal', function (ev) {
        const btn = ev.relatedTarget;
        if (!btn) return;
        document.getElementById('modal_customer_id').value = btn.getAttribute('data-cid') || '';
        document.getElementById('modal_customer_label').textContent = btn.getAttribute('data-cname') || '';
        document.getElementById('modal_gmail').value = btn.getAttribute('data-gmail') || '';
        document.getElementById('modal_exp').value = btn.getAttribute('data-exp') || '';
    });
    </script>
</body>
</html>
