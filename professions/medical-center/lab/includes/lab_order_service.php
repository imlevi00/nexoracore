<?php
/**
 * Lab order service — shared helpers for the doctor (ordering) and lab
 * (result entry / receipt) sides. Tenant scoped; pass $conn_kasher_platform.
 */

if (!function_exists('labGetTenantLabId')) {
    /**
     * The single lab account id for a tenant, or 0 when none configured.
     */
    function labGetTenantLabId(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT id FROM medical_center_labs WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : 0;
    }
}

if (!function_exists('labOrderStatuses')) {
    /**
     * @return array<string,array{label:string,badge:string}>
     */
    function labOrderStatuses(): array
    {
        return [
            'pending'          => ['label' => 'چاوەڕوان', 'badge' => 'warning'],
            'sample_collected' => ['label' => 'نمونە وەرگیراوە', 'badge' => 'info'],
            'completed'        => ['label' => 'تەواوبوو', 'badge' => 'success'],
        ];
    }
}

if (!function_exists('labOrderStatusLabel')) {
    function labOrderStatusLabel(string $status, string $locale = 'ku'): string
    {
        if ($locale === 'en') {
            return match ($status) {
                'pending' => 'Pending',
                'sample_collected' => 'Sample collected',
                'completed' => 'Completed',
                default => $status,
            };
        }

        $map = labOrderStatuses();
        return $map[$status]['label'] ?? $status;
    }
}

if (!function_exists('labOrderStatusBadge')) {
    function labOrderStatusBadge(string $status): string
    {
        $map = labOrderStatuses();
        return $map[$status]['badge'] ?? 'secondary';
    }
}

if (!function_exists('labOrderBarcode')) {
    /**
     * CODE128 value printed on lab receipts (e.g. LAB0000123).
     */
    function labOrderBarcode(int $orderId): string
    {
        return 'LAB' . str_pad((string)$orderId, 7, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('labParseOrderBarcode')) {
    /**
     * Parse a scanned or typed receipt barcode into an order id, or null when not a barcode.
     */
    function labParseOrderBarcode(string $input): ?int
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($input)) ?? '');
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^LAB(\d+)$/', $normalized, $matches) === 1) {
            $orderId = (int)$matches[1];
            return $orderId > 0 ? $orderId : null;
        }

        if (preg_match('/^\d+$/', $normalized) === 1) {
            $orderId = (int)$normalized;
            return $orderId > 0 ? $orderId : null;
        }

        return null;
    }
}

if (!function_exists('labOrderIsDirect')) {
    function labOrderIsDirect(array $order): bool
    {
        return ($order['order_source'] ?? 'doctor_referral') === 'direct';
    }
}

if (!function_exists('labOrderDoctorDisplay')) {
    function labOrderDoctorDisplay(array $order): string
    {
        if (labOrderIsDirect($order)) {
            return 'نەخۆشی دەرەکی';
        }
        $name = trim((string)($order['doctor_name'] ?? ''));
        return $name !== '' ? $name : '—';
    }
}

if (!function_exists('labFindWalkInPatientByMobile')) {
    /**
     * Find a walk-in patient by mobile within the tenant lab scope.
     */
    function labFindWalkInPatientByMobile(mysqli $conn, int $userId, int $labId, string $mobile): ?array
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT id, name, mobile, age, age_months, gender
            FROM lab_patients
            WHERE user_id = ? AND lab_id = ? AND mobile = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iis', $userId, $labId, $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('labUpsertWalkInPatient')) {
    /**
     * Insert or update a walk-in patient keyed by mobile within the lab scope.
     *
     * @return int patient id, or 0 on failure
     */
    function labUpsertWalkInPatient(
        mysqli $conn,
        int $userId,
        int $labId,
        string $name,
        string $mobile,
        int $age,
        ?int $ageMonths,
        ?string $gender
    ): int {
        $existing = labFindWalkInPatientByMobile($conn, $userId, $labId, $mobile);
        if ($existing !== null) {
            $patientId = (int)$existing['id'];
            $stmt = $conn->prepare("
                UPDATE lab_patients
                SET name = ?, age = ?, age_months = ?, gender = ?, updated_at = NOW()
                WHERE id = ? AND user_id = ? AND lab_id = ?
            ");
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('siisiii', $name, $age, $ageMonths, $gender, $patientId, $userId, $labId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok ? $patientId : 0;
        }

        $stmt = $conn->prepare("
            INSERT INTO lab_patients (user_id, lab_id, name, mobile, age, age_months, gender, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('iissiis', $userId, $labId, $name, $mobile, $age, $ageMonths, $gender);
        $ok = $stmt->execute();
        $patientId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $patientId;
    }
}

if (!function_exists('labSearchClinicPatients')) {
    /**
     * Search the clinic's registered patients (the secretary's patients) within
     * the tenant. Scoped by user_id only — a lab serves every doctor in the
     * center, so it must see all of the tenant's patients, not one doctor's.
     *
     * @return array<int,array<string,mixed>>
     */
    function labSearchClinicPatients(mysqli $conn, int $userId, string $q, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "
            SELECT p.id, p.name, p.mobile, p.age, p.age_months, p.gender, d.name AS doctor_name
            FROM medical_center_patients p
            LEFT JOIN doctors d ON d.id = p.doctor_id
            WHERE p.user_id = ?
        ";
        $types = 'i';
        $params = [$userId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= " AND (p.name LIKE ? OR p.mobile LIKE ?)";
            $types .= 'ss';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= " ORDER BY p.id DESC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('labFindClinicPatient')) {
    /**
     * Fetch one clinic patient by id within the tenant scope (user_id only),
     * or null when not found. Used to validate a lab-selected clinic patient.
     */
    function labFindClinicPatient(mysqli $conn, int $userId, int $patientId): ?array
    {
        if ($patientId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT id, name, mobile, age, age_months, gender
            FROM medical_center_patients
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $patientId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('labValidateOrderTestIds')) {
    /**
     * @param array<int,int> $testIds
     * @return array<int,string> map test_id => name for valid active tests
     */
    function labValidateOrderTestIds(mysqli $conn, int $userId, int $labId, array $testIds): array
    {
        $testIds = array_values(array_filter(array_unique(array_map('intval', $testIds)), static fn($id) => $id > 0));
        if ($testIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($testIds), '?'));
        $types = 'ii' . str_repeat('i', count($testIds));
        $params = array_merge([$userId, $labId], $testIds);
        $sql = "SELECT id, name FROM lab_tests WHERE user_id = ? AND lab_id = ? AND is_active = 1 AND id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $meta = [];
        foreach ($rows as $row) {
            $meta[(int)$row['id']] = (string)$row['name'];
        }
        return $meta;
    }
}

if (!function_exists('labCreateDirectOrder')) {
    /**
     * Create a direct (walk-in) lab order without doctor referral.
     *
     * @param array<int,int> $testIds
     * @param array<int,list<int>> $selectedRowIdsByTest optional row subset per test
     * @return int order id, or 0 on failure
     */
    function labCreateDirectOrder(
        mysqli $conn,
        int $userId,
        int $labId,
        int $labPatientId,
        array $testIds,
        ?string $notes = null,
        array $selectedRowIdsByTest = []
    ): int {
        $testMeta = labValidateOrderTestIds($conn, $userId, $labId, $testIds);
        if ($testMeta === []) {
            return 0;
        }

        $conn->begin_transaction();
        try {
            $insertOrder = $conn->prepare("
                INSERT INTO lab_orders
                    (user_id, lab_id, order_source, doctor_id, patient_id, lab_patient_id, status, notes, created_at, updated_at)
                VALUES (?, ?, 'direct', NULL, NULL, ?, 'pending', ?, NOW(), NOW())
            ");
            if (!$insertOrder) {
                throw new RuntimeException('Prepare order insert failed');
            }
            $insertOrder->bind_param('iiis', $userId, $labId, $labPatientId, $notes);
            if (!$insertOrder->execute()) {
                throw new RuntimeException('Insert order failed');
            }
            $orderId = (int)$insertOrder->insert_id;
            $insertOrder->close();

            $insertTest = $conn->prepare("
                INSERT INTO lab_order_tests (order_id, test_id, test_name_snapshot, sort_order, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            if (!$insertTest) {
                throw new RuntimeException('Prepare order test insert failed');
            }
            $sort = 0;
            foreach (array_keys($testMeta) as $testId) {
                $snapshot = $testMeta[$testId];
                $insertTest->bind_param('iisi', $orderId, $testId, $snapshot, $sort);
                if (!$insertTest->execute()) {
                    throw new RuntimeException('Insert order test failed');
                }
                $orderTestId = (int)$insertTest->insert_id;
                $sort++;

                $chosenRows = $selectedRowIdsByTest[$testId] ?? [];
                if ($chosenRows !== []) {
                    $allRows = labFetchRows($conn, $testId);
                    $allRowIds = [];
                    $rowNames = [];
                    foreach ($allRows as $r) {
                        $rid = (int)$r['id'];
                        $allRowIds[] = $rid;
                        $rowNames[$rid] = (string)$r['name'];
                    }
                    labSaveOrderTestRows($conn, $orderTestId, $rowNames, $allRowIds, $chosenRows);
                }
            }
            $insertTest->close();
            $conn->commit();
            return $orderId;
        } catch (Throwable $e) {
            $conn->rollback();
            return 0;
        }
    }
}

if (!function_exists('labCreateDirectClinicOrder')) {
    /**
     * Create a direct (lab-initiated) order for a clinic patient — one already
     * registered by the secretary (medical_center_patients). The order is still
     * 'direct' (no doctor placed it), but it hangs off the clinic patient so it
     * surfaces in that patient's history for the treating doctor.
     *
     * @param array<int,int> $testIds
     * @param array<int,list<int>> $selectedRowIdsByTest optional row subset per test
     * @return int order id, or 0 on failure
     */
    function labCreateDirectClinicOrder(
        mysqli $conn,
        int $userId,
        int $labId,
        int $patientId,
        array $testIds,
        ?string $notes = null,
        array $selectedRowIdsByTest = []
    ): int {
        $testMeta = labValidateOrderTestIds($conn, $userId, $labId, $testIds);
        if ($testMeta === []) {
            return 0;
        }

        $conn->begin_transaction();
        try {
            $insertOrder = $conn->prepare("
                INSERT INTO lab_orders
                    (user_id, lab_id, order_source, doctor_id, patient_id, lab_patient_id, status, notes, created_at, updated_at)
                VALUES (?, ?, 'direct', NULL, ?, NULL, 'pending', ?, NOW(), NOW())
            ");
            if (!$insertOrder) {
                throw new RuntimeException('Prepare order insert failed');
            }
            $insertOrder->bind_param('iiis', $userId, $labId, $patientId, $notes);
            if (!$insertOrder->execute()) {
                throw new RuntimeException('Insert order failed');
            }
            $orderId = (int)$insertOrder->insert_id;
            $insertOrder->close();

            $insertTest = $conn->prepare("
                INSERT INTO lab_order_tests (order_id, test_id, test_name_snapshot, sort_order, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            if (!$insertTest) {
                throw new RuntimeException('Prepare order test insert failed');
            }
            $sort = 0;
            foreach (array_keys($testMeta) as $testId) {
                $snapshot = $testMeta[$testId];
                $insertTest->bind_param('iisi', $orderId, $testId, $snapshot, $sort);
                if (!$insertTest->execute()) {
                    throw new RuntimeException('Insert order test failed');
                }
                $orderTestId = (int)$insertTest->insert_id;
                $sort++;

                $chosenRows = $selectedRowIdsByTest[$testId] ?? [];
                if ($chosenRows !== []) {
                    $allRows = labFetchRows($conn, $testId);
                    $allRowIds = [];
                    $rowNames = [];
                    foreach ($allRows as $r) {
                        $rid = (int)$r['id'];
                        $allRowIds[] = $rid;
                        $rowNames[$rid] = (string)$r['name'];
                    }
                    labSaveOrderTestRows($conn, $orderTestId, $rowNames, $allRowIds, $chosenRows);
                }
            }
            $insertTest->close();
            $conn->commit();
            return $orderId;
        } catch (Throwable $e) {
            $conn->rollback();
            return 0;
        }
    }
}

if (!function_exists('labOrderExistingTestIds')) {
    /**
     * Test ids already present in an order.
     *
     * @return list<int>
     */
    function labOrderExistingTestIds(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("SELECT test_id FROM lab_order_tests WHERE order_id = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['test_id'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('labAddTestsToOrder')) {
    /**
     * Add one or more tests to an existing order (walk-in or doctor referral).
     *
     * Tests already present in the order are skipped. Works the same way as
     * labCreateDirectOrder()'s test loop so results can be filled afterwards.
     *
     * @param array<int,int>        $testIds
     * @param array<int,list<int>>  $selectedRowIdsByTest optional row subset per test
     * @return int number of tests actually added
     */
    function labAddTestsToOrder(
        mysqli $conn,
        int $userId,
        int $labId,
        int $orderId,
        array $testIds,
        array $selectedRowIdsByTest = []
    ): int {
        $testMeta = labValidateOrderTestIds($conn, $userId, $labId, $testIds);
        if ($testMeta === []) {
            return 0;
        }

        $existing = array_fill_keys(labOrderExistingTestIds($conn, $orderId), true);
        $toAdd = array_filter(array_keys($testMeta), static fn($id) => !isset($existing[$id]));
        if ($toAdd === []) {
            return 0;
        }

        $sortStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM lab_order_tests WHERE order_id = ?");
        $sort = 0;
        if ($sortStmt) {
            $sortStmt->bind_param('i', $orderId);
            $sortStmt->execute();
            $sortRow = $sortStmt->get_result()->fetch_assoc();
            $sort = (int)($sortRow['max_sort'] ?? -1) + 1;
            $sortStmt->close();
        }

        $conn->begin_transaction();
        try {
            $insertTest = $conn->prepare("
                INSERT INTO lab_order_tests (order_id, test_id, test_name_snapshot, sort_order, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            if (!$insertTest) {
                throw new RuntimeException('Prepare order test insert failed');
            }

            $added = 0;
            foreach ($toAdd as $testId) {
                $snapshot = $testMeta[$testId];
                $insertTest->bind_param('iisi', $orderId, $testId, $snapshot, $sort);
                if (!$insertTest->execute()) {
                    throw new RuntimeException('Insert order test failed');
                }
                $orderTestId = (int)$insertTest->insert_id;
                $sort++;
                $added++;

                $chosenRows = $selectedRowIdsByTest[$testId] ?? [];
                if ($chosenRows !== []) {
                    $allRows = labFetchRows($conn, $testId);
                    $allRowIds = [];
                    $rowNames = [];
                    foreach ($allRows as $r) {
                        $rid = (int)$r['id'];
                        $allRowIds[] = $rid;
                        $rowNames[$rid] = (string)$r['name'];
                    }
                    labSaveOrderTestRows($conn, $orderTestId, $rowNames, $allRowIds, $chosenRows);
                }
            }
            $insertTest->close();

            $conn->query("UPDATE lab_orders SET updated_at = NOW() WHERE id = " . (int)$orderId);
            $conn->commit();
            return $added;
        } catch (Throwable $e) {
            $conn->rollback();
            return 0;
        }
    }
}

if (!function_exists('labRemoveOrderTest')) {
    /**
     * Remove one test (and its saved rows + results) from an order.
     *
     * @return bool true when a matching order-test was deleted.
     */
    function labRemoveOrderTest(mysqli $conn, int $orderId, int $orderTestId): bool
    {
        $check = $conn->prepare("SELECT id FROM lab_order_tests WHERE id = ? AND order_id = ? LIMIT 1");
        if (!$check) {
            return false;
        }
        $check->bind_param('ii', $orderTestId, $orderId);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc() !== null;
        $check->close();
        if (!$exists) {
            return false;
        }

        $conn->begin_transaction();
        try {
            foreach ([
                "DELETE FROM lab_order_results WHERE order_test_id = ?",
                "DELETE FROM lab_order_test_rows WHERE order_test_id = ?",
                "DELETE FROM lab_order_tests WHERE id = ? AND order_id = ?",
            ] as $index => $sql) {
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Prepare delete failed');
                }
                if ($index === 2) {
                    $stmt->bind_param('ii', $orderTestId, $orderId);
                } else {
                    $stmt->bind_param('i', $orderTestId);
                }
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Delete failed');
                }
                $stmt->close();
            }

            $conn->query("UPDATE lab_orders SET updated_at = NOW() WHERE id = " . (int)$orderId);
            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

if (!function_exists('labFetchOrder')) {
    /**
     * Fetch one order joined with patient + doctor, scoped to the tenant.
     * Optionally restrict to a doctor (doctor portal) — pass $doctorId > 0.
     */
    function labFetchOrder(mysqli $conn, int $userId, int $orderId, int $doctorId = 0): ?array
    {
        $sql = "
            SELECT o.id, o.user_id, o.lab_id, o.order_source, o.doctor_id, o.patient_id, o.lab_patient_id,
                   o.status, o.notes, o.created_at, o.updated_at, o.completed_at,
                   COALESCE(p.name, lp.name) AS patient_name,
                   COALESCE(p.mobile, lp.mobile) AS patient_mobile,
                   COALESCE(p.age, lp.age) AS age,
                   COALESCE(p.age_months, lp.age_months) AS age_months,
                   COALESCE(p.gender, lp.gender) AS gender,
                   d.name AS doctor_name
            FROM lab_orders o
            LEFT JOIN medical_center_patients p ON p.id = o.patient_id
            LEFT JOIN lab_patients lp ON lp.id = o.lab_patient_id
            LEFT JOIN doctors d ON d.id = o.doctor_id
            WHERE o.id = ? AND o.user_id = ?
        ";
        $types = 'ii';
        $params = [$orderId, $userId];
        if ($doctorId > 0) {
            $sql .= " AND o.doctor_id = ?";
            $types .= 'i';
            $params[] = $doctorId;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('labFetchOrderTests')) {
    /**
     * @return array<int,array<string,mixed>> the tests within an order.
     */
    function labFetchOrderTests(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("
            SELECT id, test_id, test_name_snapshot, sort_order
            FROM lab_order_tests
            WHERE order_id = ?
            ORDER BY sort_order, id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('labFetchOrderTestRowIds')) {
    /**
     * Row ids the ordering doctor restricted an order-test to.
     *
     * An empty list means "the whole test" — every row applies. This keeps
     * old orders (created before row selection existed) working unchanged.
     *
     * @return list<int>
     */
    function labFetchOrderTestRowIds(mysqli $conn, int $orderTestId): array
    {
        $stmt = $conn->prepare("
            SELECT row_id
            FROM lab_order_test_rows
            WHERE order_test_id = ?
            ORDER BY sort_order, id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderTestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['row_id'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('labFilterRowsBySelection')) {
    /**
     * Keep only the rows the doctor selected for an order-test.
     *
     * @param array<int,array<string,mixed>> $rows          rows from lab_test_rows
     * @param list<int>                      $selectedRowIds ids from labFetchOrderTestRowIds()
     * @return array<int,array<string,mixed>> filtered rows (original order preserved);
     *                                        the full list when nothing is selected.
     */
    function labFilterRowsBySelection(array $rows, array $selectedRowIds): array
    {
        if ($selectedRowIds === []) {
            return $rows;
        }
        $allowed = array_fill_keys($selectedRowIds, true);
        $filtered = [];
        foreach ($rows as $row) {
            if (isset($allowed[(int)$row['id']])) {
                $filtered[] = $row;
            }
        }
        // Fall back to the whole test if none of the stored ids still exist
        // (e.g. the lab deleted every selected row from the catalog).
        return $filtered === [] ? $rows : $filtered;
    }
}

if (!function_exists('labSaveOrderTestRows')) {
    /**
     * Persist the doctor's row selection for one order-test.
     *
     * Pass the FULL set of the test's row ids as $allRowIds and the doctor's
     * chosen subset as $selectedRowIds. When the selection is empty or covers
     * every row, nothing is stored (the order-test means "the whole test").
     *
     * @param array<int,string> $rowNames        map row_id => name (for snapshots)
     * @param list<int>          $allRowIds       every row id in the test
     * @param list<int>          $selectedRowIds  the doctor's chosen subset
     */
    function labSaveOrderTestRows(
        mysqli $conn,
        int $orderTestId,
        array $rowNames,
        array $allRowIds,
        array $selectedRowIds
    ): void {
        $allowed = array_fill_keys($allRowIds, true);
        $clean = [];
        foreach ($selectedRowIds as $rowId) {
            $rowId = (int)$rowId;
            if ($rowId > 0 && isset($allowed[$rowId])) {
                $clean[$rowId] = true;
            }
        }
        $clean = array_keys($clean);

        // Empty or "everything" => no restriction stored.
        if ($clean === [] || count($clean) >= count($allRowIds)) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO lab_order_test_rows (order_test_id, row_id, row_name_snapshot, sort_order, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            return;
        }
        $sort = 0;
        foreach ($clean as $rowId) {
            $name = (string)($rowNames[$rowId] ?? '');
            $stmt->bind_param('iisi', $orderTestId, $rowId, $name, $sort);
            $stmt->execute();
            $sort++;
        }
        $stmt->close();
    }
}

if (!function_exists('labFetchOrderResults')) {
    /**
     * @return array<int,array<int,array{value:string,flag:?string}>>
     *         map[row_id][column_id] = ['value'=>..,'flag'=>..] for one order_test.
     */
    function labFetchOrderResults(mysqli $conn, int $orderTestId): array
    {
        $stmt = $conn->prepare("
            SELECT row_id, column_id, value, flag
            FROM lab_order_results
            WHERE order_test_id = ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderTestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $map[(int)$r['row_id']][(int)$r['column_id']] = [
                'value' => (string)$r['value'],
                'flag' => $r['flag'] !== null ? (string)$r['flag'] : null,
            ];
        }
        $stmt->close();
        return $map;
    }
}

if (!function_exists('labFetchOrders')) {
    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    function labFetchOrders(
        mysqli $conn,
        int $userId,
        int $labId,
        ?string $status = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = 'o.user_id = ? AND o.lab_id = ?';
        $types = 'ii';
        $params = [$userId, $labId];

        if ($status !== null && $status !== '' && array_key_exists($status, labOrderStatuses())) {
            $where .= ' AND o.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $orderIdFromBarcode = labParseOrderBarcode($search);
            if ($orderIdFromBarcode !== null) {
                $where .= ' AND o.id = ?';
                $types .= 'i';
                $params[] = $orderIdFromBarcode;
            } else {
                $where .= ' AND (COALESCE(p.name, lp.name) LIKE ? OR COALESCE(p.mobile, lp.mobile) LIKE ?)';
                $types .= 'ss';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }
        }

        return labFetchOrdersQuery($conn, $where, $types, $params, $limit, $offset, true);
    }
}

if (!function_exists('labFetchDoctorOrders')) {
    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    function labFetchDoctorOrders(
        mysqli $conn,
        int $userId,
        int $doctorId,
        ?string $status = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = 'o.user_id = ? AND o.doctor_id = ? AND o.order_source = \'doctor_referral\'';
        $types = 'ii';
        $params = [$userId, $doctorId];

        if ($status !== null && $status !== '' && array_key_exists($status, labOrderStatuses())) {
            $where .= ' AND o.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $orderIdFromBarcode = labParseOrderBarcode($search);
            if ($orderIdFromBarcode !== null) {
                $where .= ' AND o.id = ?';
                $types .= 'i';
                $params[] = $orderIdFromBarcode;
            } else {
                $where .= ' AND (p.name LIKE ? OR p.mobile LIKE ?)';
                $types .= 'ss';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }
        }

        return labFetchOrdersQuery($conn, $where, $types, $params, $limit, $offset, false);
    }
}

if (!function_exists('labFetchOrdersQuery')) {
    /**
     * @param array<int,mixed> $params
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    function labFetchOrdersQuery(
        mysqli $conn,
        string $where,
        string $types,
        array $params,
        int $limit,
        int $offset,
        bool $includeDoctorName
    ): array {
        $countSql = "
            SELECT COUNT(*) AS total
            FROM lab_orders o
            LEFT JOIN medical_center_patients p ON p.id = o.patient_id
            LEFT JOIN lab_patients lp ON lp.id = o.lab_patient_id
            WHERE {$where}
        ";
        $countStmt = $conn->prepare($countSql);
        $total = 0;
        if ($countStmt) {
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $countRow = $countStmt->get_result()->fetch_assoc();
            $total = (int)($countRow['total'] ?? 0);
            $countStmt->close();
        }

        $doctorSelect = $includeDoctorName
            ? ", CASE WHEN o.order_source = 'direct' THEN NULL ELSE d.name END AS doctor_name, o.order_source"
            : ', o.order_source';

        $doctorJoin = $includeDoctorName ? 'LEFT JOIN doctors d ON d.id = o.doctor_id' : '';

        $sql = "
            SELECT o.id, o.status, o.created_at, o.completed_at,
                   COALESCE(p.name, lp.name) AS patient_name,
                   COALESCE(p.mobile, lp.mobile) AS patient_mobile
                   {$doctorSelect},
                   (SELECT COUNT(*) FROM lab_order_tests t WHERE t.order_id = o.id) AS test_count
            FROM lab_orders o
            LEFT JOIN medical_center_patients p ON p.id = o.patient_id
            LEFT JOIN lab_patients lp ON lp.id = o.lab_patient_id
            {$doctorJoin}
            WHERE {$where}
            ORDER BY o.id DESC
            LIMIT ? OFFSET ?
        ";
        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$limit, $offset]);
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['rows' => [], 'total' => 0];
        }
        $stmt->bind_param($listTypes, ...$listParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('labCountOrdersByStatus')) {
    /**
     * @return array{pending:int,sample_collected:int,completed:int,today_completed:int}
     */
    function labCountOrdersByStatus(mysqli $conn, int $userId, int $labId): array
    {
        $counts = [
            'pending' => 0,
            'sample_collected' => 0,
            'completed' => 0,
            'today_completed' => 0,
        ];
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) AS total
            FROM lab_orders
            WHERE user_id = ? AND lab_id = ?
            GROUP BY status
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $userId, $labId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $key = (string)$row['status'];
                if (array_key_exists($key, $counts)) {
                    $counts[$key] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        $todayStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM lab_orders
            WHERE user_id = ? AND lab_id = ? AND status = 'completed'
              AND DATE(completed_at) = CURDATE()
        ");
        if ($todayStmt) {
            $todayStmt->bind_param('ii', $userId, $labId);
            $todayStmt->execute();
            $todayRow = $todayStmt->get_result()->fetch_assoc();
            $counts['today_completed'] = (int)($todayRow['total'] ?? 0);
            $todayStmt->close();
        }

        return $counts;
    }
}

if (!function_exists('labOrderResultsComplete')) {
    /**
     * True when every order test has at least one filled result cell.
     * Partial fills are allowed: not every row/cell has to be filled.
     */
    function labOrderResultsComplete(mysqli $conn, int $orderId): bool
    {
        require_once __DIR__ . '/lab_catalog_service.php';

        $orderTests = labFetchOrderTests($conn, $orderId);
        if ($orderTests === []) {
            return false;
        }

        foreach ($orderTests as $orderTest) {
            $orderTestId = (int)$orderTest['id'];
            $testId = (int)$orderTest['test_id'];
            $columns = labFetchColumns($conn, $testId);
            $rows = labFetchRows($conn, $testId);
            $rows = labFilterRowsBySelection($rows, labFetchOrderTestRowIds($conn, $orderTestId));
            $results = labFetchOrderResults($conn, $orderTestId);

            $fillableColumns = array_filter($columns, static function ($c) {
                $type = (string)($c['col_type'] ?? '');
                return $type === 'result' || $type === 'choice';
            });
            if ($fillableColumns === [] || $rows === []) {
                continue;
            }

            $hasFilledCell = false;
            foreach ($rows as $row) {
                $rowId = (int)$row['id'];
                foreach ($fillableColumns as $col) {
                    $colId = (int)$col['id'];
                    $value = trim((string)($results[$rowId][$colId]['value'] ?? ''));
                    if ($value !== '') {
                        $hasFilledCell = true;
                        break 2;
                    }
                }
            }
            if (!$hasFilledCell) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('labTestHasActiveOrders')) {
    function labTestHasActiveOrders(mysqli $conn, int $testId): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM lab_order_tests ot
            JOIN lab_orders o ON o.id = ot.order_id
            WHERE ot.test_id = ? AND o.status IN ('pending', 'sample_collected')
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('labCountActiveOrdersForTest')) {
    function labCountActiveOrdersForTest(mysqli $conn, int $testId): int
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM lab_order_tests ot
            JOIN lab_orders o ON o.id = ot.order_id
            WHERE ot.test_id = ? AND o.status IN ('pending', 'sample_collected')
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $testId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }
}
