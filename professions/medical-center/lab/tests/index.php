<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';
require_once dirname(__DIR__) . '/includes/lab_order_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $group = trim((string)($_POST['group_name'] ?? ''));
            if ($name === '') {
                $errors[] = 'ناوی فەحس پێویستە';
            } else {
                $stmt = $conn_kasher_platform->prepare("
                    INSERT INTO lab_tests (user_id, lab_id, name, group_name, sort_order, is_active, show_on_receipt, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 0, 1, 1, NOW(), NOW())
                ");
                if ($stmt) {
                    $groupValue = $group !== '' ? $group : null;
                    $stmt->bind_param('iiss', $userId, $labId, $name, $groupValue);
                    if ($stmt->execute()) {
                        $newId = (int)$stmt->insert_id;
                        $stmt->close();
                        // Seed a sensible default table: 3 columns + 1 row
                        labSeedDefaultTestLayout($conn_kasher_platform, $newId);
                        setMessage('فەحس دروستکرا، ئێستا ستوون و ڕیزەکانی دیاری بکە', 'success');
                        redirect(url('professions/medical-center/lab/tests/designer.php?id=' . $newId));
                    }
                    $stmt->close();
                    $errors[] = 'هەڵەیەک ڕوویدا لە دروستکردنی فەحس';
                }
            }
        } elseif ($action === 'delete') {
            $testId = (int)($_POST['test_id'] ?? 0);
            if (labFetchTest($conn_kasher_platform, $userId, $labId, $testId)) {
                if (labTestHasActiveOrders($conn_kasher_platform, $testId)) {
                    $activeCount = labCountActiveOrdersForTest($conn_kasher_platform, $testId);
                    setMessage("ناتوانرێت بسڕدرێتەوە — {$activeCount} داواکاری چالاک هەیە", 'warning');
                } else {
                    $stmt = $conn_kasher_platform->prepare("DELETE FROM lab_tests WHERE id = ? AND user_id = ? AND lab_id = ?");
                    $stmt->bind_param('iii', $testId, $userId, $labId);
                    $stmt->execute();
                    $stmt->close();
                    setMessage('فەحس سڕایەوە', 'success');
                }
            }
            redirect(url('professions/medical-center/lab/tests/index.php'));
        } elseif ($action === 'toggle_active' || $action === 'toggle_receipt') {
            $testId = (int)($_POST['test_id'] ?? 0);
            if (labFetchTest($conn_kasher_platform, $userId, $labId, $testId)) {
                $column = $action === 'toggle_active' ? 'is_active' : 'show_on_receipt';
                $sql = "UPDATE lab_tests SET {$column} = 1 - {$column}, updated_at = NOW() WHERE id = ? AND user_id = ? AND lab_id = ?";
                $stmt = $conn_kasher_platform->prepare($sql);
                $stmt->bind_param('iii', $testId, $userId, $labId);
                $stmt->execute();
                $stmt->close();
            }
            redirect(url('professions/medical-center/lab/tests/index.php'));
        }
    }
}

$tests = labFetchTests($conn_kasher_platform, $userId, $labId);

// Group for display
$grouped = [];
foreach ($tests as $test) {
    $key = trim((string)($test['group_name'] ?? ''));
    $grouped[$key][] = $test;
}

$pageTitle = 'فەحسەکانی تاقیگە';
$activeNav = 'tests';
require dirname(__DIR__) . '/includes/layout_start.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0">فەحسەکان</h4>
        <p class="text-body-secondary mb-0 small">جۆرەکانی فەحس دروست بکە و خشتەی ئەنجامەکانیان دیزاین بکە</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTestModal">
        <i class="bi bi-plus-circle"></i> فەحسی نوێ
    </button>
</div>

<?php if (empty($tests)): ?>
    <div class="lab-empty-hint">
        <i class="bi bi-grid-3x3-gap text-primary lab-dash-icon"></i>
        <h6 class="mt-2 mb-1">هیچ فەحسێک نییە</h6>
        <p class="mb-3">یەکەم فەحس دروست بکە بۆ دەستپێکردن</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTestModal">
            <i class="bi bi-plus-circle"></i> فەحسی نوێ
        </button>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $groupName => $groupTests): ?>
        <div class="mb-4">
            <?php if ($groupName !== ''): ?>
                <div class="lab-group-title mb-2"><i class="bi bi-folder2"></i> <?php echo htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="row g-3">
                <?php foreach ($groupTests as $test): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="lab-test-card h-100 p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h6 class="mb-0"><?php echo htmlspecialchars((string)$test['name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <div class="d-flex gap-1">
                                    <?php if (!(int)$test['is_active']): ?>
                                        <span class="badge text-bg-secondary">ناچالاک</span>
                                    <?php endif; ?>
                                    <?php if (!(int)$test['show_on_receipt']): ?>
                                        <span class="badge text-bg-warning" title="لەسەر وەسڵ دەرناکەوێت"><i class="bi bi-eye-slash"></i></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?php echo url('professions/medical-center/lab/tests/designer.php?id=' . (int)$test['id']); ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil-square"></i> دیزاین
                                </a>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="test_id" value="<?php echo (int)$test['id']; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        <i class="bi bi-<?php echo (int)$test['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                        <?php echo (int)$test['is_active'] ? 'چالاک' : 'ناچالاک'; ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle_receipt">
                                    <input type="hidden" name="test_id" value="<?php echo (int)$test['id']; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="نیشاندان لەسەر وەسڵ" aria-label="نیشاندان لەسەر وەسڵ">
                                        <i class="bi bi-<?php echo (int)$test['show_on_receipt'] ? 'eye' : 'eye-slash'; ?>"></i>
                                    </button>
                                </form>
                                <form method="post" class="d-inline lab-delete-test-form" data-test-id="<?php echo (int)$test['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="test_id" value="<?php echo (int)$test['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="سڕینەوەی فەحس"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Create test modal -->
<div class="modal fade" id="createTestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">فەحسی نوێ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ناوی فەحس <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="نموونە: CBC، FBS، Lipid Profile">
                </div>
                <div class="mb-2">
                    <label class="form-label">گرووپ <small class="text-body-secondary">(ئارەزوومەندانە)</small></label>
                    <input type="text" name="group_name" class="form-control" placeholder="نموونە: خوێن، میز، هۆرمۆن">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> دروستکردن</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.lab-delete-test-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!confirm('دڵنیایت لە سڕینەوەی ئەم فەحسە؟ ئەگەر داواکاری چالاک هەبێت، سڕینەوە ڕێگەپێندراو نییە.')) {
            e.preventDefault();
        }
    });
});
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
