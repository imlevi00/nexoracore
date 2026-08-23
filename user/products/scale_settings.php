<?php
/**
 * ڕێکخستنەکانی قەپانی زیرەک - user/products/scale_settings.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';
require_once '../../includes/scale_barcode_parser.php';
require_once '../../includes/scale_product_sync.php';

SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];
$userPermissions = getUserPermissions($userId);
$isSubUser = isset($currentUser['user_type']) && $currentUser['user_type'] === 'sub';

if ($isSubUser && empty($userPermissions['products'])) {
    setMessage('دەسەڵاتی بەڕێوەبردنی کاڵاکانت نییە', 'error');
    redirect(url('user/products/main.php'));
}

requireSmartScaleAccess();

$errors = [];
$success = '';
$settings = getScaleBarcodeSettingsForUser($conn, $userId);
$tablesReady = scaleTablesReady($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tablesReady) {
        $errors[] = 'خشتەکانی قەپانی زیرەک لە داتابەیسدا نییە.';
    } elseif (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە.';
    } else {
        $inputSettings = [
            'prefix' => cleanInput($_POST['prefix'] ?? ''),
            'total_digits' => (int)($_POST['total_digits'] ?? 13),
            'product_code_digits' => (int)($_POST['product_code_digits'] ?? 5),
            'price_digits' => (int)($_POST['price_digits'] ?? 5),
            'validate_check_digit' => !empty($_POST['validate_check_digit']) ? 1 : 0,
            'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
        ];

        $errors = validateScaleBarcodeSettings($inputSettings);

        if (empty($errors)) {
            if (saveScaleBarcodeSettingsForUser($conn, $userId, $inputSettings)) {
                $success = 'ڕێکخستنەکان بە سەرکەوتوویی پاشەکەوت کران.';
                $settings = getScaleBarcodeSettingsForUser($conn, $userId);
            } else {
                $errors[] = 'هەڵەیەک ڕوویدا لە پاشەکەوتکردنی ڕێکخستنەکان.';
            }
        } else {
            $settings = normalizeScaleBarcodeSettings($inputSettings);
        }
    }
}

$previewBarcode = buildScaleBarcodePreview($settings, '00002', 112);
$checkDigitLength = getScaleCheckDigitLength($settings);
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ڕێکخستنەکانی قەپانی زیرەک - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('user/products/css/products-responsive.css'); ?>" rel="stylesheet">
    <style>
        .preview-box {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 1.35rem;
            letter-spacing: 0.08em;
            direction: ltr;
            text-align: center;
        }
        .preview-part {
            padding: 0.15rem 0.35rem;
            border-radius: 0.35rem;
        }
        .preview-prefix { background: rgba(13, 110, 253, 0.15); }
        .preview-product { background: rgba(25, 135, 84, 0.15); }
        .preview-price { background: rgba(255, 193, 7, 0.2); }
        .preview-check { background: rgba(108, 117, 125, 0.15); }
    </style>
</head>
<body class="products-module-page products-scale-page bg-body-secondary">
    <?php
    $productsNavId = 'productsScaleSettingsNav';
    $productsNavLinks = [
        ['href' => url('user/products/scale_products.php'), 'icon' => 'bi-speedometer2', 'text' => 'کاڵاکانی قەپان'],
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
    ];
    include __DIR__ . '/partials/products_nav.php';
    ?>

    <div class="container py-4 products-page-content">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-sliders text-primary"></i>
                            ڕێکخستنەکانی قەپانی زیرەک
                        </h1>
                        <p class="text-muted mb-0">شێوازی بارکۆدی قەپان دیاری بکە بۆ ناسینەوەی خۆکار لە فرۆشتن</p>
                    </div>
                    <a href="<?php echo url('user/products/scale_products.php'); ?>" class="btn btn-outline-primary">
                        <i class="bi bi-box-seam"></i> کاڵاکانی قەپان
                    </a>
                </div>

                <?php if (!$tablesReady): ?>
                    <div class="alert alert-danger">
                        خشتەکانی قەپانی زیرەک لە داتابەیسدا نییە. تکایە
                        <code>database/migrations/2026_07_07_smart_scale.sql</code>
                        جێبەجێ بکە.
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-body">
                        <strong>نموونەی بارکۆد</strong>
                    </div>
                    <div class="card-body">
                        <div class="preview-box mb-2" id="barcodePreview"><?php echo htmlspecialchars($previewBarcode, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="small text-muted text-center" id="barcodePreviewLegend">
                            <span class="preview-part preview-prefix">قەپان</span>
                            <span class="preview-part preview-product">کۆدی کاڵا</span>
                            <span class="preview-part preview-price">کۆی نرخ</span>
                            <?php if ($checkDigitLength > 0): ?>
                                <span class="preview-part preview-check">چێک</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" id="scaleSettingsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                            <div class="mb-3">
                                <label class="form-label">کۆدی دەستپێکی بارکۆدی قەپان</label>
                                <input type="text" name="prefix" id="prefix" class="form-control" inputmode="numeric"
                                       value="<?php echo htmlspecialchars($settings['prefix'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                <div class="form-text">نموونە: 21</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ژمارەی گشتی بارکۆد</label>
                                <input type="number" name="total_digits" id="total_digits" class="form-control" min="4" max="32"
                                       value="<?php echo (int)$settings['total_digits']; ?>" required>
                                <div class="form-text">نموونە: 13</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ژمارەی کۆدی کاڵا (دوای کۆدی قەپان)</label>
                                <input type="number" name="product_code_digits" id="product_code_digits" class="form-control" min="1" max="20"
                                       value="<?php echo (int)$settings['product_code_digits']; ?>" required>
                                <div class="form-text">نموونە: 5 → 00002</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ژمارەی کۆی گشتی نرخ</label>
                                <input type="number" name="price_digits" id="price_digits" class="form-control" min="1" max="20"
                                       value="<?php echo (int)$settings['price_digits']; ?>" required>
                                <div class="form-text">نموونە: 5 → 00112 (112 دینار)</div>
                            </div>

                            <div class="mb-3">
                                <div class="form-text mb-2">
                                    ژمارەی چێک (خۆکار): <strong id="checkDigitInfo"><?php echo (int)$checkDigitLength; ?></strong>
                                </div>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="validate_check_digit" id="validate_check_digit" value="1"
                                    <?php echo !empty($settings['validate_check_digit']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="validate_check_digit">پشکنینی ژمارەی چێکی EAN-13</label>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="is_enabled" id="is_enabled" value="1"
                                    <?php echo !empty($settings['is_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_enabled">چالاککردنی قەپانی زیرەک لە فرۆشتن</label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> پاشەکەوتکردن
                                </button>
                                <a href="<?php echo url('user/products/main.php'); ?>" class="btn btn-secondary">گەڕانەوە</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function padLeft(value, length) {
            const digits = String(value).replace(/\D/g, '');
            return digits.padStart(length, '0').slice(-length);
        }

        function calcEanCheckDigit(withoutCheck) {
            const digits = withoutCheck.split('').map(Number);
            let sum = 0;
            for (let i = digits.length - 1, pos = 0; i >= 0; i--, pos++) {
                sum += digits[i] * (pos % 2 === 0 ? 3 : 1);
            }
            return String((10 - (sum % 10)) % 10);
        }

        function updatePreview() {
            const prefix = (document.getElementById('prefix').value || '').replace(/\D/g, '');
            const totalDigits = Math.max(4, parseInt(document.getElementById('total_digits').value || '13', 10));
            const productDigits = Math.max(1, parseInt(document.getElementById('product_code_digits').value || '5', 10));
            const priceDigits = Math.max(1, parseInt(document.getElementById('price_digits').value || '5', 10));
            const checkLen = Math.max(0, totalDigits - prefix.length - productDigits - priceDigits);

            document.getElementById('checkDigitInfo').textContent = checkLen;

            const productPart = padLeft('2', productDigits);
            const pricePart = padLeft('112', priceDigits);
            let partial = (prefix + productPart + pricePart).slice(0, Math.max(0, totalDigits - checkLen));

            if (checkLen === 1) {
                partial += calcEanCheckDigit(partial);
            } else if (checkLen > 1) {
                partial = partial.padEnd(totalDigits, '0');
            }

            document.getElementById('barcodePreview').textContent = partial.slice(0, totalDigits);
        }

        ['prefix', 'total_digits', 'product_code_digits', 'price_digits'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', updatePreview);
            }
        });
    </script>
</body>
</html>
