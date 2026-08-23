<?php
/**
 * Referrals inbox — patients other doctors have referred to the logged-in doctor.
 *
 * Same column style as the patient list, plus a "Referred by" column showing the
 * referring doctor. From here the doctor can open the patient's History, write a
 * prescription, order labs, or mark the referral complete.
 */
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_once dirname(dirname(__DIR__)) . '/includes/patient_helpers.php';
require_once dirname(__DIR__) . '/includes/referral_service.php';

if (!($conn_kasher_platform instanceof mysqli)) {
    throw new RuntimeException('kasher_platform connection is unavailable.');
}
/** @var mysqli $conn_kasher_platform */

$session = medicalDoctorSession();
$doctorId = (int)$session['doctor_id'];
$userId = (int)$session['user_id'];
$csrfToken = Security::generateCSRFToken();

$referrals = medicalDoctorListIncomingReferrals($conn_kasher_platform, $userId, $doctorId);
$referralCount = count($referrals);

$doctorUiLocale = 'en';
$pageTitle = 'Referrals';
$activeNav = 'referrals';
$bodyClass = 'doctor-smart-clinic-page';
$extraHead = '<link href="' . asset('css/medical_center/doctor.css') . '" rel="stylesheet">';
require dirname(__DIR__) . '/includes/layout_start.php';

$historyBase = url('professions/medical-center/doctor/patients/history.php?patient_id=');
$rxBase = url('professions/medical-center/doctor/prescriptions/create.php?patient_id=');
$labBase = url('professions/medical-center/doctor/lab-orders/create.php?patient_id=');
$completeUrl = url('professions/medical-center/doctor/referrals/complete.php');
?>

<div class="sc-page">
    <div class="sc-page-header">
        <div>
            <h1 class="sc-page-title">Referrals</h1>
            <p class="sc-page-subtitle">Patients referred to you by other doctors</p>
        </div>
    </div>

    <div class="sc-stat-bar">
        <div class="sc-stat-card sc-stat-active">
            <span class="sc-stat-value"><?php echo $referralCount; ?></span>
            <span class="sc-stat-label">Incoming</span>
        </div>
    </div>

    <div class="sc-content-card">
        <div class="sc-content-body">
            <?php if ($referrals === []): ?>
                <div class="sc-empty">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No referrals yet</p>
                </div>
            <?php else: ?>
                <div class="sc-table-wrap sc-table-desktop">
                    <table class="sc-table">
                        <thead>
                        <tr>
                            <th>Referred by</th>
                            <th>ID</th>
                            <th>Full name</th>
                            <th>Mobile</th>
                            <th>Age / Gender</th>
                            <th>Referred</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($referrals as $referral): ?>
                            <?php
                            $patientId = (int)$referral['patient_id'];
                            $patientName = (string)$referral['patient_name'];
                            $patientMobile = (string)$referral['patient_mobile'];
                            $fromDoctor = (string)$referral['from_doctor_name'];
                            $referralNote = trim((string)($referral['referral_note'] ?? ''));
                            ?>
                            <tr class="sc-row-clickable"
                                data-history-url="<?php echo htmlspecialchars($historyBase . $patientId, ENT_QUOTES, 'UTF-8'); ?>"
                                title="Double-click to open visit history">
                                <td>
                                    <span class="sc-referral-from">
                                        <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($fromDoctor, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if ($referralNote !== ''): ?>
                                        <span class="sc-referral-note" title="<?php echo htmlspecialchars($referralNote, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($referralNote, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>#<?php echo $patientId; ?></td>
                                <td><?php echo htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$referral['patient_age'], isset($referral['patient_age_months']) ? (int)$referral['patient_age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($referral['patient_gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="text-body-secondary small">
                                    <?php echo htmlspecialchars((string)($referral['referred_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <div class="sc-action-group">
                                        <a class="sc-action-btn sc-action-primary"
                                           href="<?php echo $rxBase . $patientId; ?>" title="Write prescription">
                                            <i class="bi bi-file-earmark-medical"></i> Pharmacy
                                        </a>
                                        <a class="sc-action-btn"
                                           href="<?php echo $labBase . $patientId; ?>" title="Lab order">
                                            <i class="bi bi-clipboard2-pulse"></i> Lab
                                        </a>
                                        <a class="sc-action-btn"
                                           href="<?php echo $historyBase . $patientId; ?>" title="History">
                                            <i class="bi bi-diagram-3"></i> History
                                        </a>
                                        <form method="post" action="<?php echo $completeUrl; ?>" class="sc-action-btn-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="referral_id" value="<?php echo (int)$referral['referral_id']; ?>">
                                            <button type="submit" class="sc-action-btn sc-action-success" title="Mark complete">
                                                <i class="bi bi-check-circle"></i> Done
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sc-mobile-cards">
                    <?php foreach ($referrals as $referral): ?>
                        <?php
                        $patientId = (int)$referral['patient_id'];
                        $referralNote = trim((string)($referral['referral_note'] ?? ''));
                        ?>
                        <div class="sc-mobile-card sc-row-clickable"
                             data-history-url="<?php echo htmlspecialchars($historyBase . $patientId, ENT_QUOTES, 'UTF-8'); ?>"
                             title="Double-click to open visit history">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <strong><?php echo htmlspecialchars((string)$referral['patient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small class="text-body-secondary">#<?php echo $patientId; ?></small>
                            </div>
                            <div class="sc-mobile-card-meta">
                                <span><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars((string)$referral['from_doctor_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars((string)$referral['patient_mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>
                                    <i class="bi bi-person"></i>
                                    <?php echo htmlspecialchars(medicalCenterPatientAgeLabel((int)$referral['patient_age'], isset($referral['patient_age_months']) ? (int)$referral['patient_age_months'] : null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                    · <?php echo htmlspecialchars(medicalCenterPatientGenderLabel($referral['patient_gender'] ?? null, 'en'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ($referralNote !== ''): ?>
                                    <span><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($referralNote, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="sc-action-group mt-3">
                                <a class="sc-action-btn sc-action-primary w-100 justify-content-center" href="<?php echo $rxBase . $patientId; ?>">
                                    <i class="bi bi-file-earmark-medical"></i> Write prescription
                                </a>
                                <a class="sc-action-btn w-100 justify-content-center" href="<?php echo $labBase . $patientId; ?>">
                                    <i class="bi bi-clipboard2-pulse"></i> Lab order
                                </a>
                                <a class="sc-action-btn w-100 justify-content-center" href="<?php echo $historyBase . $patientId; ?>">
                                    <i class="bi bi-diagram-3"></i> Visit history
                                </a>
                                <form method="post" action="<?php echo $completeUrl; ?>" class="sc-action-btn-form w-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="referral_id" value="<?php echo (int)$referral['referral_id']; ?>">
                                    <button type="submit" class="sc-action-btn sc-action-success w-100 justify-content-center">
                                        <i class="bi bi-check-circle"></i> Mark complete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .sc-row-clickable { cursor: pointer; }
</style>
<script>
    (function () {
        document.querySelectorAll('.sc-row-clickable').forEach(function (el) {
            el.addEventListener('dblclick', function (event) {
                if (event.target.closest('a, button, form')) {
                    return;
                }
                var url = el.getAttribute('data-history-url');
                if (url) {
                    window.getSelection().removeAllRanges();
                    window.location.href = url;
                }
            });
        });
    })();
</script>

<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
