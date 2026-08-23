<?php
/**
 * دەستپێکی هاوبەشی وەسڵ (A4 یان ٨٠مم) بۆ سەنتەر یان دکتۆر.
 *
 * پێش include: $COSMETIC_RECEIPT_PORTAL = 'center'|'doctor'
 *              $COSMETIC_RECEIPT_FORMAT = 'a4'|'thermal'
 */
declare(strict_types=1);

$kasherRoot = dirname(__DIR__, 3);
require_once $kasherRoot . '/config/config.php';
require_once $kasherRoot . '/config/security.php';
require_once $kasherRoot . '/config/database.php';
require_once $kasherRoot . '/config/kasher_platform/database.php';
require_once __DIR__ . '/cosmetic_receipt_resolve.php';

$portal = isset($COSMETIC_RECEIPT_PORTAL) && $COSMETIC_RECEIPT_PORTAL === 'doctor' ? 'doctor' : 'center';
$format = isset($COSMETIC_RECEIPT_FORMAT) && $COSMETIC_RECEIPT_FORMAT === 'thermal' ? 'thermal' : 'a4';

if (!($conn_kasher_platform instanceof mysqli)) {
    http_response_code(503);
    echo 'داتابەیس بەردەست نییە';
    exit;
}

$preview = isset($_GET['preview']) && (int)$_GET['preview'] === 1;
$doctorAccountIdParam = (int)($_GET['doctor_account_id'] ?? 0);
$centerAccountIdParam = (int)($_GET['center_account_id'] ?? 0);
$caseIdParam = (int)($_GET['case_id'] ?? 0);
/** @deprecated هێشتا قبووڵ دەکرێت */
$legacyVisitIdParam = (int)($_GET['visit_id'] ?? 0);
if ($caseIdParam <= 0 && $legacyVisitIdParam > 0) {
    $caseIdParam = $legacyVisitIdParam;
}
$sessionIdParam = (int)($_GET['session_id'] ?? 0);
$sessionIdParam = $sessionIdParam > 0 ? $sessionIdParam : null;

$brandRow = null;
/** @var array<string, mixed>|null $caseRow */
$caseRow = null;
/** @var array<string, mixed>|null $sessionRow */
$sessionRow = null;
$receiptAccent = $portal === 'center' ? '#db2777' : '#7c3aed';

if ($preview) {
    $resolvedByPortalSession = false;
    if ($portal === 'center') {
        require_once dirname(__DIR__) . '/center/includes/bootstrap.php';
        if (function_exists('isCosmeticCenterLoggedIn') && isCosmeticCenterLoggedIn()) {
            $portalSess = cosmeticCenterSession();
            $uid = (int)$portalSess['user_id'];
            $brandCtx = cosmetic_current_branding_context_center();
            if ($brandCtx !== null) {
                $brandRow = cosmetic_fetch_branding_row($conn_kasher_platform, $uid, $brandCtx['role'], $brandCtx['account_id']);
                if ($brandRow !== null) {
                    $receiptAccent = '#db2777';
                    $resolvedByPortalSession = true;
                }
            }
        }
    } else {
        require_once dirname(__DIR__) . '/doctor/includes/bootstrap.php';
        if (function_exists('isCosmeticDoctorLoggedIn') && isCosmeticDoctorLoggedIn()) {
            $portalSess = cosmeticDoctorSession();
            $uid = (int)$portalSess['user_id'];
            $brandCtx = cosmetic_current_branding_context_doctor();
            if ($brandCtx !== null) {
                $brandRow = cosmetic_fetch_branding_row($conn_kasher_platform, $uid, $brandCtx['role'], $brandCtx['account_id']);
                if ($brandRow !== null) {
                    $receiptAccent = '#7c3aed';
                    $resolvedByPortalSession = true;
                }
            }
        }
    }

    if (!$resolvedByPortalSession) {
        if (!function_exists('isUser') || !isUser()) {
            redirect(url('user/auth/login.php'));
        }
        SessionManager::requireAuth('user');
        $uid = (int)getCurrentUser()['id'];
        if ($centerAccountIdParam > 0) {
            $st = $conn_kasher_platform->prepare(
                'SELECT id, user_id, name, receipt_header, receipt_logo_url FROM cosmetic_center_accounts WHERE id = ? AND user_id = ? LIMIT 1'
            );
            if (!$st) {
                http_response_code(500);
                echo 'هەڵە';
                exit;
            }
            $st->bind_param('ii', $centerAccountIdParam, $uid);
            $st->execute();
            $acc = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$acc) {
                http_response_code(404);
                echo 'نەدۆزرایەوە';
                exit;
            }
            $brandRow = ['name' => $acc['name'], 'receipt_header' => $acc['receipt_header'] ?? '', 'receipt_logo_url' => $acc['receipt_logo_url'] ?? ''];
            $receiptAccent = '#db2777';
        } elseif ($doctorAccountIdParam > 0) {
            $st = $conn_kasher_platform->prepare(
                'SELECT id, user_id, name, receipt_header, receipt_logo_url FROM cosmetic_doctor_accounts WHERE id = ? AND user_id = ? LIMIT 1'
            );
            if (!$st) {
                http_response_code(500);
                echo 'هەڵە';
                exit;
            }
            $st->bind_param('ii', $doctorAccountIdParam, $uid);
            $st->execute();
            $acc = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$acc) {
                http_response_code(404);
                echo 'نەدۆزرایەوە';
                exit;
            }
            $brandRow = ['name' => $acc['name'], 'receipt_header' => $acc['receipt_header'] ?? '', 'receipt_logo_url' => $acc['receipt_logo_url'] ?? ''];
            $receiptAccent = '#7c3aed';
        } else {
            http_response_code(400);
            echo 'پێویستە center_account_id یان doctor_account_id دیاری بکرێت';
            exit;
        }
    }

    $caseRow = [
        'client_name' => 'نموونە — ناوی سەردانیکەر',
        'mobile' => '0750 000 0000',
        'age' => 30,
        'sessions_planned' => 6,
        'work_type' => 'خزمەتگوزاری نموونە',
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $sessionRow = [
        'session_number' => 1,
        'session_date' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
        'price' => '50000.00',
        'discount' => '5000.00',
    ];
} else {
    if ($portal === 'center') {
        require_once dirname(__DIR__) . '/center/includes/bootstrap.php';
        if (!function_exists('isCosmeticCenterLoggedIn') || !isCosmeticCenterLoggedIn()) {
            redirect(url('professions/cosmetic-center/center/auth/login.php'));
        }
        $portalSess = cosmeticCenterSession();
        $uid = (int)$portalSess['user_id'];
        $brandCtx = cosmetic_current_branding_context_center();
    } else {
        require_once dirname(__DIR__) . '/doctor/includes/bootstrap.php';
        if (!function_exists('isCosmeticDoctorLoggedIn') || !isCosmeticDoctorLoggedIn()) {
            redirect(url('professions/cosmetic-center/doctor/auth/login.php'));
        }
        $portalSess = cosmeticDoctorSession();
        $uid = (int)$portalSess['user_id'];
        $brandCtx = cosmetic_current_branding_context_doctor();
    }

    if ($caseIdParam <= 0) {
        http_response_code(400);
        echo 'case_id پێویستە';
        exit;
    }

    if ($brandCtx === null) {
        http_response_code(403);
        exit;
    }

    $bundle = cosmetic_fetch_case_and_session(
        $conn_kasher_platform,
        $uid,
        $caseIdParam,
        $sessionIdParam,
        $brandCtx['role'],
        $brandCtx['account_id']
    );
    if ($bundle === null) {
        http_response_code(404);
        echo 'تۆمار نەدۆزرایەوە';
        exit;
    }

    $caseRow = $bundle['case'];
    $sessionRow = $bundle['session'];
    $brandRow = cosmetic_fetch_branding_row($conn_kasher_platform, $uid, $brandCtx['role'], $brandCtx['account_id']);
    if ($brandRow === null) {
        http_response_code(404);
        echo 'ئەکاونتی وەسڵ نەدۆزرایەوە';
        exit;
    }
    $receiptAccent = $portal === 'center' ? '#db2777' : '#7c3aed';
}

$headerText = (string)($brandRow['receipt_header'] ?? '');
$headerLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $headerText)), static function ($l) {
    return $l !== '';
}));
$logoUrl = trim((string)($brandRow['receipt_logo_url'] ?? ''));
$docName = (string)($brandRow['name'] ?? '');
$price = (float)($sessionRow['price'] ?? 0);
$discount = (float)($sessionRow['discount'] ?? 0);
$total = max(0.0, $price - $discount);
$sessionsPlanned = (int)$caseRow['sessions_planned'];
$sessionNumber = (int)$sessionRow['session_number'];
$sessionDateStr = (string)$sessionRow['session_date'];
$receiptDateLabel = $sessionDateStr !== '' ? $sessionDateStr : (string)$sessionRow['created_at'];

if ($format === 'thermal') {
    require __DIR__ . '/receipt_thermal_markup.php';
} else {
    require __DIR__ . '/receipt_a4_markup.php';
}
