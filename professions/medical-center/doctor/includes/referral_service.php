<?php
/**
 * Doctor-to-doctor referral service.
 *
 * A doctor can refer one of their patients to another doctor in the same tenant
 * (user_id). The target doctor sees referred patients in their Referrals list and
 * may write History / prescribe / order labs for them exactly like their own
 * patients. Access to a referred patient is granted by a row in medical_referrals;
 * the patient's original owner (medical_center_patients.doctor_id) never changes.
 *
 * All helpers are tenant-scoped: they take $userId and never cross tenants.
 */

if (!function_exists('medicalDoctorFetchAccessiblePatient')) {
    /**
     * Fetch a patient the given doctor is allowed to work on: either the patient
     * they own, or one that has been referred to them (pending referral).
     *
     * Returns the patient row augmented with:
     *   - owner_doctor_id      int    the doctor who owns the patient record
     *   - is_referred          bool   true when access is via a referral (not owned)
     *   - referral_id          int    the pending referral id (0 when owned)
     *   - referring_doctor_id  int    who referred (0 when owned)
     *   - referring_doctor_name string referring doctor's name ('' when owned)
     *
     * @return array<string,mixed>|null null when the patient does not exist in this
     *                                   tenant or the doctor may not access it.
     */
    function medicalDoctorFetchAccessiblePatient(
        mysqli $conn,
        int $userId,
        int $doctorId,
        int $patientId
    ): ?array {
        if ($patientId <= 0 || $doctorId <= 0) {
            return null;
        }

        // Patient must exist in this tenant. Ownership is checked afterwards so a
        // referred patient (owned by a different doctor) is still found here.
        $stmt = $conn->prepare("
            SELECT id, name, mobile, age, age_months, gender, profession, blood_type, address, doctor_id
            FROM medical_center_patients
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $patientId, $userId);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($patient === null) {
            return null;
        }

        $ownerDoctorId = (int)$patient['doctor_id'];
        $patient['owner_doctor_id'] = $ownerDoctorId;
        $patient['is_referred'] = false;
        $patient['referral_id'] = 0;
        $patient['referring_doctor_id'] = 0;
        $patient['referring_doctor_name'] = '';

        // Owned directly — full access.
        if ($ownerDoctorId === $doctorId) {
            return $patient;
        }

        // Otherwise access is only via a pending referral addressed to this doctor.
        $refStmt = $conn->prepare("
            SELECT r.id, r.from_doctor_id, d.name AS from_doctor_name
            FROM medical_referrals r
            INNER JOIN doctors d ON d.id = r.from_doctor_id
            WHERE r.user_id = ? AND r.patient_id = ? AND r.to_doctor_id = ? AND r.status = 'pending'
            ORDER BY r.id DESC
            LIMIT 1
        ");
        if (!$refStmt) {
            return null;
        }
        $refStmt->bind_param('iii', $userId, $patientId, $doctorId);
        $refStmt->execute();
        $ref = $refStmt->get_result()->fetch_assoc() ?: null;
        $refStmt->close();
        if ($ref === null) {
            return null;
        }

        $patient['is_referred'] = true;
        $patient['referral_id'] = (int)$ref['id'];
        $patient['referring_doctor_id'] = (int)$ref['from_doctor_id'];
        $patient['referring_doctor_name'] = (string)$ref['from_doctor_name'];
        return $patient;
    }
}

if (!function_exists('medicalDoctorCanAccessPatient')) {
    /**
     * Thin boolean wrapper over medicalDoctorFetchAccessiblePatient() for callers
     * (e.g. the draft auto-save endpoint) that only need a yes/no access check.
     */
    function medicalDoctorCanAccessPatient(
        mysqli $conn,
        int $userId,
        int $doctorId,
        int $patientId
    ): bool {
        return medicalDoctorFetchAccessiblePatient($conn, $userId, $doctorId, $patientId) !== null;
    }
}

if (!function_exists('medicalDoctorListReferralTargets')) {
    /**
     * All other doctors in this tenant that a patient may be referred to.
     * Any doctor can be a target, so this is simply every doctor except the
     * referring one.
     *
     * @return array<int,array{id:int,name:string}>
     */
    function medicalDoctorListReferralTargets(
        mysqli $conn,
        int $userId,
        int $excludeDoctorId
    ): array {
        $stmt = $conn->prepare("
            SELECT id, name
            FROM doctors
            WHERE user_id = ? AND id <> ?
            ORDER BY name ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $excludeDoctorId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $targets = [];
        foreach ($rows as $row) {
            $targets[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        return $targets;
    }
}

if (!function_exists('medicalDoctorListIncomingReferrals')) {
    /**
     * Pending referrals addressed to this doctor, most recent first, joined with
     * the patient details and the referring doctor's name.
     *
     * @return array<int,array<string,mixed>>
     */
    function medicalDoctorListIncomingReferrals(
        mysqli $conn,
        int $userId,
        int $doctorId
    ): array {
        $stmt = $conn->prepare("
            SELECT
                r.id AS referral_id,
                r.note AS referral_note,
                r.created_at AS referred_at,
                r.from_doctor_id,
                fd.name AS from_doctor_name,
                p.id AS patient_id,
                p.name AS patient_name,
                p.mobile AS patient_mobile,
                p.age AS patient_age,
                p.age_months AS patient_age_months,
                p.gender AS patient_gender
            FROM medical_referrals r
            INNER JOIN doctors fd ON fd.id = r.from_doctor_id
            INNER JOIN medical_center_patients p ON p.id = r.patient_id
            WHERE r.user_id = ? AND r.to_doctor_id = ? AND r.status = 'pending'
            ORDER BY r.created_at DESC, r.id DESC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $doctorId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('medicalDoctorCountIncomingReferrals')) {
    /**
     * Count of pending referrals addressed to this doctor (for the nav badge).
     */
    function medicalDoctorCountIncomingReferrals(
        mysqli $conn,
        int $userId,
        int $doctorId
    ): int {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM medical_referrals
            WHERE user_id = ? AND to_doctor_id = ? AND status = 'pending'
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ii', $userId, $doctorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }
}
