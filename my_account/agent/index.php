<?php
/**
 * Agent self portal (read-only).
 * Login through Google and view referral stats.
 */

require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$googleUser = $_SESSION['google_user'] ?? null;
$agent = null;
$registrations = [];
$stats = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['google_user'], $_SESSION['oauth2_state']);
    redirect(url('my_account/agent/index.php'));
}

if (isset($_GET['login']) && $_GET['login'] === 'google') {
    redirect(url('videos/google-login.php?return_to=my_account/agent/index.php'));
}

if (!empty($googleUser['email']) && $conn instanceof mysqli) {
    $gmail = strtolower(trim((string)$googleUser['email']));

    $agentStmt = $conn->prepare("
        SELECT id, name, email, phone, agent_code, is_active, created_at
        FROM agents
        WHERE LOWER(email) = ?
        LIMIT 1
    ");
    if ($agentStmt) {
        $agentStmt->bind_param('s', $gmail);
        $agentStmt->execute();
        $agentRes = $agentStmt->get_result();
        $agent = $agentRes ? $agentRes->fetch_assoc() : null;
        $agentStmt->close();
    } else {
        $error = 'هەڵە لە پشکنینی هەژماری مەندووب.';
    }

    if (!$error && !$agent) {
        $error = 'ئەم ئیمەیڵە وەک مەندووب تۆمار نەکراوە.';
    }

    if (!$error && (int)$agent['is_active'] !== 1) {
        $error = 'هەژماری مەندووبەکەت ناچالاکە.';
    }

    if (!$error) {
        $regStmt = $conn->prepare("
            SELECT
                ar.created_at AS registered_at,
                u.id AS user_id,
                u.business_name,
                u.phone,
                u.status,
                p.name AS package_name,
                u.expiration_date
            FROM agent_registrations ar
            INNER JOIN users u ON u.id = ar.registered_user_id
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE ar.agent_id = ?
            ORDER BY ar.created_at DESC
        ");

        if ($regStmt) {
            $agentId = (int)$agent['id'];
            $regStmt->bind_param('i', $agentId);
            $regStmt->execute();
            $registrations = $regStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $regStmt->close();
        }

        foreach ($registrations as $row) {
            $stats['total']++;
            $st = $row['status'] ?? '';
            if ($st === 'approved') {
                $stats['approved']++;
            } elseif ($st === 'pending') {
                $stats['pending']++;
            } elseif ($st === 'rejected') {
                $stats['rejected']++;
            }
        }
    }
}

function agentStatusLabel($status)
{
    if ($status === 'approved') {
        return ['پەسەندکراو', 'success'];
    }
    if ($status === 'pending') {
        return ['چاوەڕوان', 'warning'];
    }
    if ($status === 'rejected') {
        return ['ڕەتکراوە', 'danger'];
    }
    return ['نەناسراو', 'secondary'];
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>هەژماری مەندووب - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="bi bi-person-badge"></i> هەژماری مەندووب</h4>
                      
                    </div>

                    <?php if (empty($googleUser)): ?>
                        <p class="text-muted">بۆ بینینی زانیارییەکانی مەندووب، تکایە بە Google بچۆرە ژوورەوە.</p>
                        <a class="btn btn-danger" href="<?php echo url('my_account/agent/index.php?login=google'); ?>">
                            <i class="bi bi-google"></i> چوونەژوورەوە بە Google
                        </a>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <?php if (!empty($googleUser['picture'])): ?>
                                <img class="avatar" src="<?php echo htmlspecialchars($googleUser['picture']); ?>" alt="avatar">
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($googleUser['name'] ?? ''); ?></div>
                                <div class="text-muted"><?php echo htmlspecialchars($googleUser['email'] ?? ''); ?></div>
                            </div>
                            <div class="ms-auto">
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('my_account/agent/index.php?logout=1'); ?>">
                                    <i class="bi bi-box-arrow-left"></i> دەرچوون
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-warning mb-0"><?php echo htmlspecialchars($error); ?></div>
                        <?php else: ?>
                            <?php $refLink = url('user/auth/register.php?ref=' . urlencode($agent['agent_code'])); ?>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="border rounded p-3 bg-light h-100">
                                        <div class="small text-muted mb-2">لینکی بانگهێشتکردن</div>
                                        <input id="refLinkInput" type="text" class="form-control form-control-sm mb-2" value="<?php echo htmlspecialchars($refLink); ?>" readonly>
                                        <button class="btn btn-sm btn-primary" type="button" onclick="copyAgentLink()">
                                            <i class="bi bi-clipboard"></i> کۆپی
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded p-3 bg-light h-100">
                                        <div class="small text-muted">زانیاری مەندووب</div>
                                        <div><strong>ناو:</strong> <?php echo htmlspecialchars($agent['name']); ?></div>
                                        <div><strong>مۆبایل:</strong> <?php echo htmlspecialchars($agent['phone']); ?></div>
                                        <div><strong>کۆد:</strong> <code><?php echo htmlspecialchars($agent['agent_code']); ?></code></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-3"><div class="card border-0 bg-primary text-white"><div class="card-body"><div class="small">گشتی هاتووەکان</div><div class="h4 mb-0"><?php echo number_format($stats['total']); ?></div></div></div></div>
                                <div class="col-md-3"><div class="card border-0 bg-success text-white"><div class="card-body"><div class="small">پەسەندکراو</div><div class="h4 mb-0"><?php echo number_format($stats['approved']); ?></div></div></div></div>
                                <div class="col-md-3"><div class="card border-0 bg-warning text-dark"><div class="card-body"><div class="small">چاوەڕوان</div><div class="h4 mb-0"><?php echo number_format($stats['pending']); ?></div></div></div></div>
                                <div class="col-md-3"><div class="card border-0 bg-danger text-white"><div class="card-body"><div class="small">ڕەتکراوە</div><div class="h4 mb-0"><?php echo number_format($stats['rejected']); ?></div></div></div></div>
                            </div>

                            <h5 class="mb-3">ئەکاونتە نوێیەکان </h5>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>بەروار</th>
                                            <th>ناوی شوێن</th>
                                            <th>ژمارەی مۆبایل</th>
                                            <th>دۆخی ئەکاونت</th>
                                            <th>ناوی پاکێج</th>
                                            <th>بەرواری نوێکردنەوە ئەکاوەنت (بەسەرچوون)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($registrations)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">هیچ تۆماربوونێک نەدۆزرایەوە</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($registrations as $row): ?>
                                            <?php [$stText, $stClass] = agentStatusLabel($row['status'] ?? ''); ?>
                                            <tr>
                                                <td><?php echo date('Y/m/d H:i', strtotime($row['registered_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['business_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                                                <td><span class="badge bg-<?php echo $stClass; ?>"><?php echo $stText; ?></span></td>
                                                <td><?php echo htmlspecialchars($row['package_name'] ?? 'دیارینەکراو'); ?></td>
                                                <td>
                                                    <?php if (!empty($row['expiration_date'])): ?>
                                                        <?php echo date('Y/m/d H:i', strtotime($row['expiration_date'])); ?>
                                                    <?php else: ?>
                                                        دیارینەکراو
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyAgentLink() {
    const input = document.getElementById('refLinkInput');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    try {
        navigator.clipboard.writeText(input.value);
        alert('لینکەکە کۆپی کرا');
    } catch (e) {
        document.execCommand('copy');
        alert('لینکەکە کۆپی کرا');
    }
}
</script>
</body>
</html>
