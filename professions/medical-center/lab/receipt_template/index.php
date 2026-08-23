<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(__DIR__) . '/includes/lab_catalog_service.php';
require_once dirname(__DIR__, 4) . '/config/upload_config.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalLabSession();
$labId = (int)$session['lab_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();
$errors = [];

/**
 * Handle one optional image upload field. Returns a public URL on success,
 * null when no file submitted, throws on validation error.
 */
function labHandleImageUpload(string $field): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $validation = validateUploadedFile($_FILES[$field]);
    if (!$validation['valid']) {
        throw new RuntimeException(implode('، ', $validation['errors']));
    }
    $destDir = UPLOADS_PATH . '/lab';
    $result = secureFileUpload($_FILES[$field], $destDir, 'lab' . $field);
    if (!$result['success']) {
        throw new RuntimeException(implode('، ', $result['errors']));
    }
    return upload('lab/' . $result['filename']);
}

$settings = labFetchReceiptSettings($conn_kasher_platform, $userId, $labId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی. دووبارە هەوڵ بدەرەوە';
    } else {
        $action = trim((string)($_POST['action'] ?? 'save'));

        if ($action === 'remove_banner' || $action === 'remove_footer_banner') {
            $column = $action === 'remove_banner' ? 'banner_url' : 'stamp_url';
            $stmt = $conn_kasher_platform->prepare("UPDATE lab_receipt_settings SET {$column} = NULL, updated_at = NOW() WHERE lab_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $labId, $userId);
            $stmt->execute();
            $stmt->close();
            setMessage('وێنە لابرا', 'success');
            redirect(url('professions/medical-center/lab/receipt_template/index.php'));
        }

        $bannerUrl = $settings['banner_url'];
        $footerBannerUrl = $settings['stamp_url'];
        try {
            $newBanner = labHandleImageUpload('banner_image');
            if ($newBanner !== null) {
                $bannerUrl = $newBanner;
            }
            $newFooterBanner = labHandleImageUpload('footer_banner_image');
            if ($newFooterBanner !== null) {
                $footerBannerUrl = $newFooterBanner;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (empty($errors)) {
            $headerText = trim((string)($_POST['header_text'] ?? ''));
            $footerText = trim((string)($_POST['footer_text'] ?? ''));
            $headerValue = $headerText !== '' ? $headerText : null;
            $footerValue = $footerText !== '' ? $footerText : null;

            $infoFields = [];
            foreach (labDefaultInfoFields() as $key => $meta) {
                $infoFields[$key] = isset($_POST['info_fields'][$key]);
            }
            $infoFieldsJson = json_encode($infoFields, JSON_UNESCAPED_UNICODE);

            $stmt = $conn_kasher_platform->prepare("
                INSERT INTO lab_receipt_settings (user_id, lab_id, banner_url, stamp_url, header_text, footer_text, info_fields, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    banner_url = VALUES(banner_url),
                    stamp_url = VALUES(stamp_url),
                    header_text = VALUES(header_text),
                    footer_text = VALUES(footer_text),
                    info_fields = VALUES(info_fields),
                    updated_at = NOW()
            ");
            $stmt->bind_param('iisssss', $userId, $labId, $bannerUrl, $footerBannerUrl, $headerValue, $footerValue, $infoFieldsJson);
            if ($stmt->execute()) {
                $stmt->close();
                setMessage('ڕێکخستنی وەسڵ پاشەکەوتکرا', 'success');
                redirect(url('professions/medical-center/lab/receipt_template/index.php'));
            }
            $stmt->close();
            $errors[] = 'هەڵەیەک ڕوویدا لە پاشەکەوتکردن';
        }

        // keep submitted values on error
        $settings['banner_url'] = $bannerUrl;
        $settings['stamp_url'] = $footerBannerUrl;
        $settings['header_text'] = $_POST['header_text'] ?? $settings['header_text'];
        $settings['footer_text'] = $_POST['footer_text'] ?? $settings['footer_text'];
        foreach (labDefaultInfoFields() as $key => $meta) {
            $settings['info_fields'][$key] = isset($_POST['info_fields'][$key]);
        }
    }
}

$infoFieldsMeta = labDefaultInfoFields();

$pageTitle = 'ڕێکخستنی وەسڵ';
$activeNav = 'receipt_template';
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

<div class="mb-3">
    <h4 class="mb-0">ڕێکخستنی وەسڵی تاقیگە</h4>
    <p class="text-body-secondary mb-0 small">سەرپەڕە، ژێرپەڕە و ئەو زانیاریانەی لەسەر وەسڵ دەردەکەون دیاری بکە</p>
</div>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="save">

    <div class="row g-3">
        <!-- Banner -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-image"></i> سەرپەڕەی وەسڵ (banner)</h6>
                    <p class="text-body-secondary small">وێنەیەک کە لە سەرەوەی وەسڵ بە تەواوی پانی دەردەکەوێت. ئەگەر وێنەکە بەرز بێت، بە شێوەی ستاندارد دەبڕدرێت (وەک strip ـی سەرپەڕە).</p>
                    <?php if (!empty($settings['banner_url'])): ?>
                        <div class="lab-template-banner-preview-wrap">
                            <img src="<?php echo htmlspecialchars((string)$settings['banner_url'], ENT_QUOTES, 'UTF-8'); ?>" class="lab-template-banner-preview" alt="سەرپەڕەی وەسڵ">
                        </div>
                        <div class="mb-2">
                            <button type="submit" name="action" value="remove_banner" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> لابردنی سەرپەڕە</button>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="banner_image" class="form-control" accept="image/*">
                </div>
            </div>
        </div>

        <!-- Footer banner -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-image"></i> ژێرپەڕەی وەسڵ (footer banner)</h6>
                    <p class="text-body-secondary small">وێنەیەک کە لە خوارەوەی وەسڵ بە تەواوی پانی دەردەکەوێت. ئەگەر وێنەکە بەرز بێت، بە شێوەی ستاندارد دەبڕدرێت (وەک strip ـی ژێرپەڕە).</p>
                    <?php if (!empty($settings['stamp_url'])): ?>
                        <div class="lab-template-footer-banner-preview-wrap mb-2">
                            <img src="<?php echo htmlspecialchars((string)$settings['stamp_url'], ENT_QUOTES, 'UTF-8'); ?>" class="lab-template-footer-banner-preview" alt="ژێرپەڕەی وەسڵ">
                        </div>
                        <div class="mb-2">
                            <button type="submit" name="action" value="remove_footer_banner" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> لابردنی ژێرپەڕە</button>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="footer_banner_image" class="form-control" accept="image/*">
                </div>
            </div>
        </div>

        <!-- Texts -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-textarea-t"></i> دەقی سەرەوە و خوارەوە</h6>
                    <div class="mb-3">
                        <label class="form-label">دەقی سەرەوە <small class="text-body-secondary">(ئەگەر بانەر نەبێت)</small></label>
                        <textarea name="header_text" class="form-control" rows="2" placeholder="ناوی تاقیگە / ناونیشان / تەلەفۆن"><?php echo htmlspecialchars((string)($settings['header_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">دەقی پێ (footer)</label>
                        <textarea name="footer_text" class="form-control" rows="2" placeholder="نموونە: سوپاس بۆ متمانەتان"><?php echo htmlspecialchars((string)($settings['footer_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info fields -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="mb-2"><i class="bi bi-card-checklist"></i> زانیاری نەخۆش لەسەر وەسڵ</h6>
                    <p class="text-body-secondary small">دیاری بکە کام زانیاری لەسەر وەسڵ دەربکەوێت</p>
                    <div class="row">
                        <?php foreach ($infoFieldsMeta as $key => $meta): ?>
                            <div class="col-6">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="info_<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                           name="info_fields[<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>]"
                                           <?php echo !empty($settings['info_fields'][$key]) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="info_<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> پاشەکەوتکردن</button>
    </div>
</form>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
