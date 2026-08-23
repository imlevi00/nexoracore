<?php
/**
 * Image attachments for a consultation's clinical-note sections
 * (History / Examination / Diagnoses).
 *
 * Each attachment belongs to a medical_prescriptions row (a draft while the
 * consultation is in progress, or a finalized prescription) and to one of the
 * three sections. Files go to the unified object storage — local
 * assets/uploads on dev, DigitalOcean Spaces on production — under
 * img/medical/prescriptions/<prescription_id>/…; the DB keeps only the object
 * key so the public URL is resolved per-environment at render time.
 *
 * Storage helpers (spaces_put_object / spaces_delete_object /
 * spaces_public_url_for_object_key / kasher_storage_is_local) come from
 * config/security.php → config/product_images.php, already loaded by bootstrap.
 */

require_once dirname(__DIR__, 4) . '/config/product_images.php';

if (!defined('MEDICAL_RX_ATTACHMENT_PREFIX')) {
    define('MEDICAL_RX_ATTACHMENT_PREFIX', 'img/medical/prescriptions');
}

if (!function_exists('medicalRxAttachmentSections')) {
    /** The three clinical-note sections that accept image attachments. */
    function medicalRxAttachmentSections(): array
    {
        return ['history', 'examination', 'diagnosis'];
    }
}

if (!function_exists('medicalRxAttachmentIsValidSection')) {
    function medicalRxAttachmentIsValidSection(string $section): bool
    {
        return in_array($section, medicalRxAttachmentSections(), true);
    }
}

if (!function_exists('medicalRxAttachmentPublicUrl')) {
    /** Resolve the public URL for a stored object key (null when unknown). */
    function medicalRxAttachmentPublicUrl(?string $objectKey): ?string
    {
        if ($objectKey === null || $objectKey === '') {
            return null;
        }
        return spaces_public_url_for_object_key($objectKey);
    }
}

if (!function_exists('medicalRxAttachmentStore')) {
    /**
     * Validate + store one uploaded image against a prescription section.
     *
     * @param array $file A single $_FILES entry.
     * @return array{success:bool, attachment?:array<string,mixed>, error?:string}
     */
    function medicalRxAttachmentStore(
        mysqli $conn,
        int $prescriptionId,
        string $section,
        array $file
    ): array {
        if ($prescriptionId <= 0 || !medicalRxAttachmentIsValidSection($section)) {
            return ['success' => false, 'error' => 'Invalid attachment target'];
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }
        if (!SecureFileUpload::validateFileType($file['name'] ?? '', ALLOWED_IMAGE_TYPES)) {
            return ['success' => false, 'error' => 'Only image files are allowed (jpg, jpeg, png, gif, webp)'];
        }
        if (!SecureFileUpload::validateFileSize((int)($file['size'] ?? 0))) {
            return ['success' => false, 'error' => 'Image is too large (max 8MB)'];
        }
        // A real image, per its bytes — not just its extension.
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => 'The uploaded file is not a valid image'];
        }

        $filename = SecureFileUpload::generateSafeFilename($file['name'] ?? 'image.jpg');
        $objectKey = MEDICAL_RX_ATTACHMENT_PREFIX . '/' . $prescriptionId . '/' . $filename;

        // Compress with GD when available (falls back to the original bytes on
        // dev boxes without GD, matching product-image behaviour).
        $tmpOut = ($file['tmp_name'] ?? '') . '_rx_opt_' . bin2hex(random_bytes(4));
        $compressed = SecureFileUpload::compressImage($file['tmp_name'], $tmpOut);
        $sourcePath = ($compressed && is_file($tmpOut)) ? $tmpOut : $file['tmp_name'];
        $body = @file_get_contents($sourcePath);
        $mime = (string)($imageInfo['mime'] ?? ($file['type'] ?? 'application/octet-stream'));
        if (is_file($tmpOut)) {
            @unlink($tmpOut);
        }
        if ($body === false) {
            return ['success' => false, 'error' => 'Could not read the uploaded image'];
        }

        try {
            spaces_put_object($objectKey, $body, $mime);
        } catch (Throwable $e) {
            if (function_exists('writeLog')) {
                writeLog('Rx attachment store: ' . $e->getMessage());
            }
            return ['success' => false, 'error' => 'Could not save the image'];
        }

        // Next sort order within this (prescription, section).
        $sortOrder = 0;
        $orderStmt = $conn->prepare("
            SELECT COALESCE(MAX(sort_order), -1) + 1 AS next
            FROM medical_prescription_attachments
            WHERE prescription_id = ? AND section = ?
        ");
        if ($orderStmt) {
            $orderStmt->bind_param('is', $prescriptionId, $section);
            $orderStmt->execute();
            $sortOrder = (int)($orderStmt->get_result()->fetch_assoc()['next'] ?? 0);
            $orderStmt->close();
        }

        $originalName = mb_substr((string)($file['name'] ?? ''), 0, 255);
        $fileSize = (int)($file['size'] ?? 0);
        $insertStmt = $conn->prepare("
            INSERT INTO medical_prescription_attachments
            (prescription_id, section, object_key, original_name, mime, file_size, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$insertStmt) {
            spaces_delete_object($objectKey);
            return ['success' => false, 'error' => 'Could not save the image'];
        }
        $insertStmt->bind_param(
            'issssii',
            $prescriptionId,
            $section,
            $objectKey,
            $originalName,
            $mime,
            $fileSize,
            $sortOrder
        );
        if (!$insertStmt->execute()) {
            $insertStmt->close();
            spaces_delete_object($objectKey);
            return ['success' => false, 'error' => 'Could not save the image'];
        }
        $attachmentId = (int)$insertStmt->insert_id;
        $insertStmt->close();

        return [
            'success' => true,
            'attachment' => [
                'id' => $attachmentId,
                'section' => $section,
                'object_key' => $objectKey,
                'original_name' => $originalName,
                'url' => medicalRxAttachmentPublicUrl($objectKey),
            ],
        ];
    }
}

if (!function_exists('medicalRxAttachmentDelete')) {
    /**
     * Delete one attachment (row + stored object). The caller must have already
     * confirmed the parent prescription belongs to the acting doctor.
     */
    function medicalRxAttachmentDelete(mysqli $conn, int $prescriptionId, int $attachmentId): bool
    {
        if ($prescriptionId <= 0 || $attachmentId <= 0) {
            return false;
        }
        $lookup = $conn->prepare("
            SELECT object_key FROM medical_prescription_attachments
            WHERE id = ? AND prescription_id = ?
            LIMIT 1
        ");
        if (!$lookup) {
            return false;
        }
        $lookup->bind_param('ii', $attachmentId, $prescriptionId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$row) {
            return false;
        }
        $objectKey = (string)$row['object_key'];

        $del = $conn->prepare("
            DELETE FROM medical_prescription_attachments
            WHERE id = ? AND prescription_id = ?
        ");
        if (!$del) {
            return false;
        }
        $del->bind_param('ii', $attachmentId, $prescriptionId);
        $ok = $del->execute();
        $del->close();
        if (!$ok) {
            return false;
        }
        // Best effort: the DB row is the source of truth; a stray file is harmless.
        spaces_delete_object($objectKey);
        return true;
    }
}

if (!function_exists('medicalRxAttachmentsForPrescriptions')) {
    /**
     * Load attachments for one or more prescriptions, grouped for rendering.
     *
     * @param int[] $prescriptionIds
     * @return array<int, array<string, array<int, array<string,mixed>>>>
     *         [prescriptionId][section] => list of ['id','url','object_key','original_name']
     */
    function medicalRxAttachmentsForPrescriptions(mysqli $conn, array $prescriptionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $prescriptionIds), static fn($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT id, prescription_id, section, object_key, original_name
            FROM medical_prescription_attachments
            WHERE prescription_id IN ($placeholders)
            ORDER BY prescription_id ASC, section ASC, sort_order ASC, id ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $grouped = [];
        foreach ($rows as $row) {
            $pid = (int)$row['prescription_id'];
            $section = (string)$row['section'];
            $grouped[$pid][$section][] = [
                'id' => (int)$row['id'],
                'object_key' => (string)$row['object_key'],
                'original_name' => (string)($row['original_name'] ?? ''),
                'url' => medicalRxAttachmentPublicUrl((string)$row['object_key']),
            ];
        }
        return $grouped;
    }
}

if (!function_exists('medicalRxAttachmentsForPrescription')) {
    /**
     * Attachments for a single prescription, grouped by section.
     *
     * @return array<string, array<int, array<string,mixed>>>  [section] => list
     */
    function medicalRxAttachmentsForPrescription(mysqli $conn, int $prescriptionId): array
    {
        $all = medicalRxAttachmentsForPrescriptions($conn, [$prescriptionId]);
        return $all[$prescriptionId] ?? [];
    }
}
