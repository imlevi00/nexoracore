<?php
/**
 * Demo seed for the Medical Center module (LOCAL DEVELOPMENT ONLY).
 *
 * Inserts realistic sample data so the doctor "Visit History" mind-map has
 * something to show: sample patients, prescriptions (pharmacy) with medications,
 * and lab orders with tests (plus filled results for completed ones).
 *
 * Idempotent: every sample patient is keyed by a sentinel mobile prefix
 * (0799...) so re-running wipes the previous sample set (and its child rows)
 * before re-inserting. It never touches real patients.
 *
 * Usage (from project root):
 *   C:/xampp/php/php.exe database/seed_medical_center_demo.php
 */

require_once __DIR__ . '/../config/kasher_platform/config.php';

if (!defined('KASHER_PLATFORM_DB_NAME') || KASHER_PLATFORM_DB_NAME === '') {
    fwrite(STDERR, "kasher_platform database is not configured.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(
    KASHER_PLATFORM_DB_HOST,
    KASHER_PLATFORM_DB_USERNAME,
    KASHER_PLATFORM_DB_PASSWORD,
    KASHER_PLATFORM_DB_NAME
);
$conn->set_charset(KASHER_PLATFORM_DB_CHARSET);

// --- Scope: the demo tenant / staff / lab that already exist locally ---------
const SEED_USER_ID              = 1;
const SEED_SENTINEL_MOBILE_LIKE = '0799%'; // sample patients live behind this prefix (real patients use other prefixes)
const SEED_LAB_ID               = 1;        // تاقیگەی نموونە

// Local lab catalog references (from lab_test_columns / lab_test_rows):
//   Test 1 = CBC       result column id 1, rows 1..5 (WBC,RBC,HGB,HCT,PLT)
//   Test 2 = گلوکۆز    result column id 4, row 6
//   Test 3 = کراتینین  result column id 7 (ordered as pending — no results)
$labTestName        = [1 => 'CBC — ژمارەی خوێن', 2 => 'گلوکۆز', 3 => 'کراتینین'];
$resultColumnByTest = [1 => 1, 2 => 4, 3 => 7];

/**
 * Sample patients and their full history. Dates are explicit so visits group
 * into distinct days in the mind-map. results: [test_id => [row_id => [value, flag]]].
 */
$patients = [
    [
        'name' => 'ئارام کەریم مستەفا', 'mobile' => '07990010001', 'age' => 54, 'gender' => 'male',
        'doctor_id' => 1, 'secretary_id' => 1,
        'prescriptions' => [
            ['at' => '2026-07-20 09:30:00', 'status' => 'completed', 'diagnosis' => 'شەکرەی جۆری ٢ (Type 2 Diabetes)',
             'items' => [['Metformin 500mg', 2, 'tab'], ['Gliclazide 80mg', 1, 'tab']]],
            ['at' => '2026-05-02 11:00:00', 'status' => 'completed', 'diagnosis' => 'فشاری خوێنی بەرز (Hypertension)',
             'items' => [['Amlodipine 5mg', 1, 'tab']]],
        ],
        'labs' => [
            ['at' => '2026-07-20 09:35:00', 'status' => 'completed', 'notes' => 'نموونەی بەتاڵ (fasting)', 'tests' => [2],
             'results' => [2 => [6 => ['180', 'high']]]],
            ['at' => '2026-06-15 10:10:00', 'status' => 'completed', 'notes' => '', 'tests' => [1],
             'results' => [1 => [1 => ['7.2', 'normal'], 2 => ['4.8', 'normal'], 3 => ['14.1', 'normal'], 4 => ['42', 'normal'], 5 => ['250', 'normal']]]],
        ],
    ],
    [
        'name' => 'شنە عەبدوڵا حەمە', 'mobile' => '07990010002', 'age' => 31, 'gender' => 'female',
        'doctor_id' => 1, 'secretary_id' => 1,
        'prescriptions' => [
            ['at' => '2026-07-28 14:15:00', 'status' => 'pending', 'diagnosis' => 'هەوکردنی سەرووی هەناسە (URTI)',
             'items' => [['Amoxicillin 500mg', 1, 'cap'], ['Paracetamol 500mg', 2, 'tab']]],
        ],
        'labs' => [
            ['at' => '2026-07-28 14:20:00', 'status' => 'pending', 'notes' => '', 'tests' => [1], 'results' => []],
        ],
    ],
    [
        'name' => 'دیار ئیبراهیم عومەر', 'mobile' => '07990010003', 'age' => 5, 'gender' => 'male',
        'doctor_id' => 1, 'secretary_id' => 1,
        'prescriptions' => [
            ['at' => '2026-07-30 12:05:00', 'status' => 'completed', 'diagnosis' => 'هەوکردنی لووزە (Tonsillitis)',
             'items' => [['Augmentin 156mg/5ml شەربەت', 1, 'شووشە'], ['Ibuprofen شەربەت', 1, 'شووشە']]],
        ],
        'labs' => [],
    ],
    [
        'name' => 'لانا هەردی ڕەشید', 'mobile' => '07990010004', 'age' => 52, 'gender' => 'female',
        'doctor_id' => 2, 'secretary_id' => 2,
        'prescriptions' => [
            ['at' => '2026-07-25 10:40:00', 'status' => 'completed', 'diagnosis' => 'کەمیی گلاندی سپی (Hypothyroidism)',
             'items' => [['Levothyroxine 50mcg', 1, 'tab']]],
        ],
        'labs' => [
            ['at' => '2026-07-25 10:45:00', 'status' => 'sample_collected', 'notes' => 'پشکنینی گلاند', 'tests' => [3], 'results' => []],
            ['at' => '2026-06-10 09:00:00', 'status' => 'completed', 'notes' => '', 'tests' => [2],
             'results' => [2 => [6 => ['95', 'normal']]]],
        ],
    ],
    [
        'name' => 'کارزان ئازاد سەعید', 'mobile' => '07990010005', 'age' => 61, 'gender' => 'male',
        'doctor_id' => 2, 'secretary_id' => 2,
        'prescriptions' => [
            ['at' => '2026-07-18 16:00:00', 'status' => 'completed', 'diagnosis' => 'بەرزی چەوری خوێن (Dyslipidemia)',
             'items' => [['Atorvastatin 20mg', 1, 'tab']]],
        ],
        'labs' => [
            ['at' => '2026-07-18 16:05:00', 'status' => 'completed', 'notes' => '', 'tests' => [1],
             'results' => [1 => [1 => ['6.5', 'normal'], 2 => ['5.1', 'normal'], 3 => ['15.0', 'normal'], 4 => ['45', 'normal'], 5 => ['300', 'normal']]]],
        ],
    ],
];

$fakeProductId = 900000; // synthetic ids for snapshot-only medication rows
$uid           = SEED_USER_ID;
$labId         = SEED_LAB_ID;

$conn->begin_transaction();
try {
    // --- Idempotent cleanup of any previous sample set -----------------------
    $ids  = [];
    $like = SEED_SENTINEL_MOBILE_LIKE;
    $sel  = $conn->prepare("SELECT id FROM medical_center_patients WHERE user_id = ? AND mobile LIKE ?");
    $sel->bind_param('is', $uid, $like);
    $sel->execute();
    $res = $sel->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $sel->close();

    if ($ids !== []) {
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        // prescriptions -> items cascade; lab_orders -> tests -> results cascade
        foreach ([
            "DELETE FROM medical_prescriptions WHERE patient_id IN ($ph)",
            "DELETE FROM lab_orders WHERE patient_id IN ($ph)",
            "DELETE FROM medical_center_patients WHERE id IN ($ph)",
        ] as $delSql) {
            $del = $conn->prepare($delSql);
            $del->bind_param($types, ...$ids);
            $del->execute();
            $del->close();
        }
    }

    // --- Prepared inserts (reused across the loop) ---------------------------
    $insPatient = $conn->prepare("
        INSERT INTO medical_center_patients
            (user_id, doctor_id, created_by_secretary_id, name, mobile, age, gender, visit_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
    ");
    $insRx = $conn->prepare("
        INSERT INTO medical_prescriptions (user_id, doctor_id, patient_id, diagnosis, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insItem = $conn->prepare("
        INSERT INTO medical_prescription_items
            (prescription_id, product_id, product_name_snapshot, quantity, unit_name_snapshot, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insOrder = $conn->prepare("
        INSERT INTO lab_orders
            (user_id, lab_id, order_source, doctor_id, patient_id, status, notes, created_at, updated_at, completed_at)
        VALUES (?, ?, 'doctor_referral', ?, ?, ?, ?, ?, ?, ?)
    ");
    $insOrderTest = $conn->prepare("
        INSERT INTO lab_order_tests (order_id, test_id, test_name_snapshot, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insResult = $conn->prepare("
        INSERT INTO lab_order_results (order_test_id, row_id, column_id, value, flag, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $counters = ['patients' => 0, 'prescriptions' => 0, 'items' => 0, 'orders' => 0, 'tests' => 0, 'results' => 0];

    foreach ($patients as $p) {
        $createdAt = $p['prescriptions'][0]['at'] ?? ($p['labs'][0]['at'] ?? '2026-07-01 09:00:00');
        $insPatient->bind_param(
            'iiississs',
            $uid, $p['doctor_id'], $p['secretary_id'], $p['name'], $p['mobile'], $p['age'], $p['gender'], $createdAt, $createdAt
        );
        $insPatient->execute();
        $patientId = (int)$insPatient->insert_id;
        $counters['patients']++;

        // Prescriptions (pharmacy) + medications
        foreach ($p['prescriptions'] as $rx) {
            $insRx->bind_param('iiissss', $uid, $p['doctor_id'], $patientId, $rx['diagnosis'], $rx['status'], $rx['at'], $rx['at']);
            $insRx->execute();
            $rxId = (int)$insRx->insert_id;
            $counters['prescriptions']++;

            foreach ($rx['items'] as [$name, $qty, $unit]) {
                $pid = ++$fakeProductId;
                $q   = (float)$qty;
                $insItem->bind_param('iisdss', $rxId, $pid, $name, $q, $unit, $rx['at']);
                $insItem->execute();
                $counters['items']++;
            }
        }

        // Lab orders + tests + results
        foreach ($p['labs'] as $order) {
            $completedAt = $order['status'] === 'completed' ? $order['at'] : null;
            $notes       = $order['notes'] !== '' ? $order['notes'] : null;
            $insOrder->bind_param(
                'iiiissss' . 's',
                $uid, $labId, $p['doctor_id'], $patientId, $order['status'], $notes, $order['at'], $order['at'], $completedAt
            );
            $insOrder->execute();
            $orderId = (int)$insOrder->insert_id;
            $counters['orders']++;

            $sort = 0;
            foreach ($order['tests'] as $testId) {
                $snapshot = $labTestName[$testId] ?? ('Test #' . $testId);
                $insOrderTest->bind_param('iisis', $orderId, $testId, $snapshot, $sort, $order['at']);
                $insOrderTest->execute();
                $orderTestId = (int)$insOrderTest->insert_id;
                $counters['tests']++;
                $sort++;

                foreach (($order['results'][$testId] ?? []) as $rowId => [$value, $flag]) {
                    $colId = $resultColumnByTest[$testId] ?? 1;
                    $insResult->bind_param('iiissss', $orderTestId, $rowId, $colId, $value, $flag, $order['at'], $order['at']);
                    $insResult->execute();
                    $counters['results']++;
                }
            }
        }
    }

    $insPatient->close();
    $insRx->close();
    $insItem->close();
    $insOrder->close();
    $insOrderTest->close();
    $insResult->close();

    $conn->commit();

    echo "Sample medical-center data seeded successfully:\n";
    foreach ($counters as $k => $v) {
        echo "  - {$k}: {$v}\n";
    }
    echo "Sentinel mobile prefix: " . SEED_SENTINEL_MOBILE_LIKE . " (re-run is safe / idempotent).\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Seed failed, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
