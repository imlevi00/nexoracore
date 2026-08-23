<?php

/**
 * @return array<int, array{id: int, name: string}>
 */
function getSecretaryAssignedDoctors(mysqli $conn, int $userId, int $secretaryId): array
{
    $stmt = $conn->prepare("
        SELECT d.id, d.name
        FROM doctor_secretary_doctors sd
        INNER JOIN doctors d ON d.id = sd.doctor_id AND d.user_id = sd.user_id
        WHERE sd.user_id = ? AND sd.secretary_id = ?
        ORDER BY d.name ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $userId, $secretaryId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $doctors = [];
    foreach ($rows as $row) {
        $doctors[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
        ];
    }

    if ($doctors === []) {
        $fallbackStmt = $conn->prepare("
            SELECT d.id, d.name
            FROM doctor_secretaries s
            INNER JOIN doctors d ON d.id = s.doctor_id AND d.user_id = s.user_id
            WHERE s.user_id = ? AND s.id = ?
            LIMIT 1
        ");
        if ($fallbackStmt) {
            $fallbackStmt->bind_param('ii', $userId, $secretaryId);
            $fallbackStmt->execute();
            $fallbackRow = $fallbackStmt->get_result()->fetch_assoc();
            $fallbackStmt->close();
            if ($fallbackRow) {
                $doctors[] = [
                    'id' => (int)$fallbackRow['id'],
                    'name' => (string)$fallbackRow['name'],
                ];
            }
        }
    }

    return $doctors;
}

/**
 * @return int[]
 */
function getSecretaryAssignedDoctorIds(mysqli $conn, int $userId, int $secretaryId): array
{
    return array_map(
        static fn(array $doctor): int => (int)$doctor['id'],
        getSecretaryAssignedDoctors($conn, $userId, $secretaryId)
    );
}

/**
 * For each given doctor, find the next free start time on $date — i.e. the end
 * time of the last appointment already booked for that doctor that day. This
 * lets the registration form pre-fill back-to-back slots so patient visits are
 * scheduled predictably without the secretary having to track the clock.
 *
 * @param int[] $doctorIds
 * @return array<int, string> map of doctor id => "HH:MM" suggested start time
 */
function getSecretaryDoctorsNextSlot(mysqli $conn, int $userId, array $doctorIds, string $date): array
{
    $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds), static fn(int $id): bool => $id > 0)));
    if ($doctorIds === [] || medicalCenterNormalizeAppointmentDate($date) === null) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
    $types = 'i' . str_repeat('i', count($doctorIds)) . 's';
    $params = array_merge([$userId], $doctorIds, [$date]);

    $stmt = $conn->prepare("
        SELECT doctor_id, MAX(appointment_end_time) AS last_end
        FROM medical_center_patients
        WHERE user_id = ?
          AND doctor_id IN ($placeholders)
          AND appointment_date = ?
          AND appointment_end_time IS NOT NULL
        GROUP BY doctor_id
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $slots = [];
    foreach ($rows as $row) {
        $label = medicalCenterFormatTimeLabel((string)($row['last_end'] ?? ''));
        if ($label !== '') {
            $slots[(int)$row['doctor_id']] = $label;
        }
    }

    return $slots;
}

function isDoctorAssignedToSecretary(mysqli $conn, int $userId, int $secretaryId, int $doctorId): bool
{
    if ($doctorId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM doctor_secretary_doctors
        WHERE user_id = ? AND secretary_id = ? AND doctor_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $userId, $secretaryId, $doctorId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        return true;
    }

    return in_array($doctorId, getSecretaryAssignedDoctorIds($conn, $userId, $secretaryId), true);
}

/**
 * @param int[] $doctorIds
 */
function syncSecretaryAssignedDoctors(mysqli $conn, int $userId, int $secretaryId, array $doctorIds): bool
{
    $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds), static fn(int $id): bool => $id > 0)));
    if ($doctorIds === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
    $types = 'i' . str_repeat('i', count($doctorIds));
    $params = array_merge([$userId], $doctorIds);
    $checkSql = "SELECT COUNT(*) AS cnt FROM doctors WHERE user_id = ? AND id IN ($placeholders)";
    $checkStmt = $conn->prepare($checkSql);
    if (!$checkStmt) {
        return false;
    }
    $checkStmt->bind_param($types, ...$params);
    $checkStmt->execute();
    $validCount = (int)$checkStmt->get_result()->fetch_assoc()['cnt'];
    $checkStmt->close();
    if ($validCount !== count($doctorIds)) {
        return false;
    }

    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare("
            DELETE FROM doctor_secretary_doctors
            WHERE user_id = ? AND secretary_id = ?
        ");
        if (!$deleteStmt) {
            throw new RuntimeException('Delete prepare failed');
        }
        $deleteStmt->bind_param('ii', $userId, $secretaryId);
        if (!$deleteStmt->execute()) {
            throw new RuntimeException('Delete execute failed');
        }
        $deleteStmt->close();

        $insertStmt = $conn->prepare("
            INSERT INTO doctor_secretary_doctors (user_id, secretary_id, doctor_id, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        if (!$insertStmt) {
            throw new RuntimeException('Insert prepare failed');
        }
        foreach ($doctorIds as $doctorId) {
            $insertStmt->bind_param('iii', $userId, $secretaryId, $doctorId);
            if (!$insertStmt->execute()) {
                throw new RuntimeException('Insert execute failed');
            }
        }
        $insertStmt->close();
        $conn->commit();

        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}
