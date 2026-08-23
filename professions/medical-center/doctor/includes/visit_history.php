<?php
/**
 * Patient visit history — a per-patient, collapsible "mind map" of everything the
 * clinic has recorded for a patient over time (prescriptions / pharmacy and lab
 * orders), grouped by visit day. This gives the treating doctor the full prior
 * history at a glance so past work is not repeated.
 *
 * Data comes only from what this codebase actually stores:
 *   - medical_prescriptions (+ items)  -> Pharmacy branch
 *   - lab_orders (+ tests)             -> Lab branch
 * Radiology / sonar and free clinical notes are not modelled yet, so they are
 * intentionally not fabricated here.
 *
 * Tenant + doctor scoped: pass $conn_kasher_platform, $userId, $doctorId.
 */

require_once __DIR__ . '/markdown_field.php';
require_once __DIR__ . '/prescription_attachments.php';

if (!function_exists('medicalDoctorFetchPatientVisitHistory')) {
    /**
     * Build the patient's visit history grouped by day (most recent first).
     *
     * @return array{
     *   patient: array<string,mixed>,
     *   visits: array<int,array<string,mixed>>,
     *   total_prescriptions: int,
     *   total_lab_orders: int
     * }|null  null when the patient is not found within this doctor's scope.
     */
    function medicalDoctorFetchPatientVisitHistory(
        mysqli $conn,
        int $userId,
        int $doctorId,
        int $patientId
    ): ?array {
        if ($patientId <= 0) {
            return null;
        }

        // Patient must exist within this tenant. Scoping is by user_id only (not
        // doctor_id): the clinical history is the patient's full record across every
        // treating doctor, and a referral specialist views a patient another doctor
        // owns. Callers gate *access* to the patient before rendering this
        // (medicalDoctorFetchAccessiblePatient); here we just load the record.
        $patientStmt = $conn->prepare("
            SELECT id, name, mobile, age, age_months, gender
            FROM medical_center_patients
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$patientStmt) {
            return null;
        }
        $patientStmt->bind_param('ii', $patientId, $userId);
        $patientStmt->execute();
        $patient = $patientStmt->get_result()->fetch_assoc() ?: null;
        $patientStmt->close();
        if ($patient === null) {
            return null;
        }

        // Visits keyed by calendar day: each day is one "visit" node in the tree.
        $visits = [];
        $ensureDay = static function (string $day) use (&$visits): void {
            if (!isset($visits[$day])) {
                $visits[$day] = [
                    'date' => $day,
                    'prescriptions' => [],
                    'lab_orders' => [],
                ];
            }
        };

        // --- Pharmacy: prescriptions + their medications ---
        // The clinical history is the patient's full record across every doctor
        // who has treated them (owner + any referral specialists), so it is scoped
        // to the patient within the tenant — not to a single doctor. Each entry
        // carries the treating doctor's name (joined below) so the timeline stays
        // clear when more than one doctor is involved.
        $prescriptions = [];
        // Drafts (in-progress consultations) are intentionally excluded — the
        // history tree only shows finalized visits (pending / completed).
        $rxStmt = $conn->prepare("
            SELECT mp.id, mp.doctor_id, d.name AS doctor_name,
                   mp.history, mp.examination, mp.diagnosis, mp.status, mp.created_at
            FROM medical_prescriptions mp
            INNER JOIN doctors d ON d.id = mp.doctor_id
            WHERE mp.user_id = ? AND mp.patient_id = ? AND mp.status <> 'draft'
            ORDER BY mp.created_at DESC, mp.id DESC
        ");
        if ($rxStmt) {
            $rxStmt->bind_param('ii', $userId, $patientId);
            $rxStmt->execute();
            $prescriptions = $rxStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $rxStmt->close();
        }

        $itemsByRx = [];
        if ($prescriptions !== []) {
            $rxIds = array_map(static fn($r) => (int)$r['id'], $prescriptions);
            $placeholders = implode(',', array_fill(0, count($rxIds), '?'));
            $itemStmt = $conn->prepare("
                SELECT prescription_id, product_name_snapshot, quantity, unit_name_snapshot
                FROM medical_prescription_items
                WHERE prescription_id IN ($placeholders)
                ORDER BY id ASC
            ");
            if ($itemStmt) {
                $itemStmt->bind_param(str_repeat('i', count($rxIds)), ...$rxIds);
                $itemStmt->execute();
                $itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $itemStmt->close();
                foreach ($itemRows as $row) {
                    $itemsByRx[(int)$row['prescription_id']][] = [
                        'name' => (string)$row['product_name_snapshot'],
                        'quantity' => (float)$row['quantity'],
                        'unit' => trim((string)($row['unit_name_snapshot'] ?? '')),
                    ];
                }
            }
        }

        // Image attachments for every prescription in the history, grouped by
        // prescription id and section.
        $attachmentsByRx = [];
        if ($prescriptions !== []) {
            $rxIds = array_map(static fn($r) => (int)$r['id'], $prescriptions);
            $attachmentsByRx = medicalRxAttachmentsForPrescriptions($conn, $rxIds);
        }

        foreach ($prescriptions as $rx) {
            $day = substr((string)$rx['created_at'], 0, 10);
            $ensureDay($day);
            $visits[$day]['prescriptions'][] = [
                'id' => (int)$rx['id'],
                'doctor_id' => (int)$rx['doctor_id'],
                'doctor_name' => (string)($rx['doctor_name'] ?? ''),
                'history' => trim((string)($rx['history'] ?? '')),
                'examination' => trim((string)($rx['examination'] ?? '')),
                'diagnosis' => (string)$rx['diagnosis'],
                'status' => (string)$rx['status'],
                'created_at' => (string)$rx['created_at'],
                'items' => $itemsByRx[(int)$rx['id']] ?? [],
                'attachments' => $attachmentsByRx[(int)$rx['id']] ?? [],
            ];
        }

        // --- Lab: doctor-referred lab orders + the tests requested ---
        $labOrders = [];
        $labStmt = $conn->prepare("
            SELECT lo.id, lo.doctor_id, d.name AS doctor_name, lo.status, lo.notes, lo.created_at
            FROM lab_orders lo
            INNER JOIN doctors d ON d.id = lo.doctor_id
            WHERE lo.user_id = ? AND lo.patient_id = ?
            ORDER BY lo.created_at DESC, lo.id DESC
        ");
        if ($labStmt) {
            $labStmt->bind_param('ii', $userId, $patientId);
            $labStmt->execute();
            $labOrders = $labStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $labStmt->close();
        }

        $testsByOrder = [];
        if ($labOrders !== []) {
            $orderIds = array_map(static fn($r) => (int)$r['id'], $labOrders);
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $testStmt = $conn->prepare("
                SELECT order_id, test_name_snapshot
                FROM lab_order_tests
                WHERE order_id IN ($placeholders)
                ORDER BY sort_order ASC, id ASC
            ");
            if ($testStmt) {
                $testStmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
                $testStmt->execute();
                $testRows = $testStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $testStmt->close();
                foreach ($testRows as $row) {
                    $testsByOrder[(int)$row['order_id']][] = (string)$row['test_name_snapshot'];
                }
            }
        }

        foreach ($labOrders as $order) {
            $day = substr((string)$order['created_at'], 0, 10);
            $ensureDay($day);
            $visits[$day]['lab_orders'][] = [
                'id' => (int)$order['id'],
                'doctor_id' => (int)$order['doctor_id'],
                'doctor_name' => (string)($order['doctor_name'] ?? ''),
                'status' => (string)$order['status'],
                'notes' => trim((string)($order['notes'] ?? '')),
                'created_at' => (string)$order['created_at'],
                'tests' => $testsByOrder[(int)$order['id']] ?? [],
            ];
        }

        // Most recent visit first.
        krsort($visits);

        // --- Direct lab orders (lab-initiated, no doctor) ---
        // These are tests the lab wrote for this clinic patient directly, without
        // a doctor placing them (order_source = 'direct', doctor_id NULL). They are
        // kept OUT of the day-grouped visit tree above and returned separately so
        // the UI can show them in their own section — they aren't the doctor's work.
        $directLabOrders = [];
        $directStmt = $conn->prepare("
            SELECT lo.id, lo.status, lo.notes, lo.created_at
            FROM lab_orders lo
            WHERE lo.user_id = ? AND lo.patient_id = ? AND lo.order_source = 'direct'
            ORDER BY lo.created_at DESC, lo.id DESC
        ");
        if ($directStmt) {
            $directStmt->bind_param('ii', $userId, $patientId);
            $directStmt->execute();
            $directRows = $directStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $directStmt->close();

            $directTestsByOrder = [];
            if ($directRows !== []) {
                $orderIds = array_map(static fn($r) => (int)$r['id'], $directRows);
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $dTestStmt = $conn->prepare("
                    SELECT order_id, test_name_snapshot
                    FROM lab_order_tests
                    WHERE order_id IN ($placeholders)
                    ORDER BY sort_order ASC, id ASC
                ");
                if ($dTestStmt) {
                    $dTestStmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
                    $dTestStmt->execute();
                    foreach ($dTestStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                        $directTestsByOrder[(int)$row['order_id']][] = (string)$row['test_name_snapshot'];
                    }
                    $dTestStmt->close();
                }
            }

            foreach ($directRows as $order) {
                $directLabOrders[] = [
                    'id' => (int)$order['id'],
                    'status' => (string)$order['status'],
                    'notes' => trim((string)($order['notes'] ?? '')),
                    'created_at' => (string)$order['created_at'],
                    'tests' => $directTestsByOrder[(int)$order['id']] ?? [],
                ];
            }
        }

        return [
            'patient' => $patient,
            'visits' => array_values($visits),
            'total_prescriptions' => count($prescriptions),
            'total_lab_orders' => count($labOrders),
            'direct_lab_orders' => $directLabOrders,
            'total_direct_lab_orders' => count($directLabOrders),
        ];
    }
}

if (!function_exists('medicalDoctorVisitQtyLabel')) {
    function medicalDoctorVisitQtyLabel(float $qty): string
    {
        $label = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        return $label === '' ? '1' : $label;
    }
}

if (!function_exists('medicalDoctorRenderVisitHistory')) {
    /**
     * Render the visit history as a nested, collapsible tree ("mind map").
     * Uses native <details>/<summary> so branches expand/collapse on click with
     * no JavaScript. Returns ready-to-echo HTML.
     *
     * @param array<string,mixed>|null $history result of medicalDoctorFetchPatientVisitHistory()
     */
    function medicalDoctorRenderVisitHistory(?array $history, string $locale = 'en', int $viewerDoctorId = 0): string
    {
        $enc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $isEn = $locale === 'en';

        $t = [
            'empty'      => $isEn ? 'No previous visits recorded for this patient.' : 'هیچ سەردانێکی پێشوو بۆ ئەم نەخۆشە تۆمار نەکراوە.',
            'pharmacy'   => $isEn ? 'Pharmacy' : 'دەرمانخانە',
            'lab'        => $isEn ? 'Lab' : 'تاقیگە',
            'history'    => $isEn ? 'History' : 'مێژوو',
            'examination'=> $isEn ? 'Examination' : 'پشکنین',
            'diagnosis'  => $isEn ? 'Diagnosis' : 'دەستنیشانکردن',
            'no_meds'    => $isEn ? 'No medications recorded' : 'هیچ دەرمانێک تۆمار نەکراوە',
            'no_tests'   => $isEn ? 'No tests recorded' : 'هیچ پشکنینێک تۆمار نەکراوە',
            'view'       => $isEn ? 'View' : 'بینین',
            'note'       => $isEn ? 'Note' : 'تێبینی',
            'images'     => $isEn ? 'Images' : 'وێنەکان',
            'tests'      => $isEn ? 'tests' : 'پشکنین',
            'by'         => $isEn ? 'Dr.' : 'د.',
        ];

        // Small "Dr. Name" chip shown on every entry so a cross-doctor history
        // (owner + referral specialists) makes clear who did each item.
        $doctorChip = static function (string $name) use ($enc, $t): string {
            $name = trim($name);
            if ($name === '') {
                return '';
            }
            return '<span class="sc-vh-doctor"><i class="bi bi-person-badge"></i> '
                . $enc($t['by'] . ' ' . $name) . '</span>';
        };
        // The "View" link is offered for every entry in the patient's history,
        // including ones created by another doctor (e.g. the doctor who referred
        // this patient in). view.php / lab-orders/view.php open those read-only for
        // any doctor who can access the patient, so the referral specialist can
        // review the referring doctor's prior work. The $viewerDoctorId parameter is
        // kept for signature compatibility with existing callers.
        $canView = static function (int $entryDoctorId) use ($viewerDoctorId): bool {
            return true;
        };

        if ($history === null || $history['visits'] === []) {
            return '<div class="sc-vh-empty"><i class="bi bi-diagram-3"></i><p class="mb-0">' . $enc($t['empty']) . '</p></div>';
        }

        $rxViewBase = url('professions/medical-center/doctor/prescriptions/view.php?id=');
        $labViewBase = url('professions/medical-center/doctor/lab-orders/view.php?id=');

        $html = '<div class="sc-vh">';
        foreach ($history['visits'] as $index => $visit) {
            $rxList = $visit['prescriptions'];
            $labList = $visit['lab_orders'];
            $rxCount = count($rxList);
            $labCount = count($labList);

            $ts = strtotime((string)$visit['date'] . ' 00:00:00');
            $dateLabel = $ts ? date('D, d M Y', $ts) : (string)$visit['date'];

            // First (most recent) visit is expanded by default.
            $openVisit = $index === 0 ? ' open' : '';
            $html .= '<details class="sc-vh-visit"' . $openVisit . '>';
            $html .= '<summary class="sc-vh-visit-head">';
            $html .= '<span class="sc-vh-caret"><i class="bi bi-chevron-right"></i></span>';
            $html .= '<span class="sc-vh-date"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
            $html .= '<span class="sc-vh-pills">';
            if ($rxCount > 0) {
                $html .= '<span class="sc-vh-pill sc-vh-pill-rx">' . $rxCount . ' ' . $enc($t['pharmacy']) . '</span>';
            }
            if ($labCount > 0) {
                $html .= '<span class="sc-vh-pill sc-vh-pill-lab">' . $labCount . ' ' . $enc($t['lab']) . '</span>';
            }
            $html .= '</span>';
            $html .= '</summary>';
            $html .= '<div class="sc-vh-visit-body">';

            // Pharmacy branch
            if ($rxCount > 0) {
                $html .= '<details class="sc-vh-branch sc-vh-branch-rx" open>';
                $html .= '<summary class="sc-vh-branch-head">';
                $html .= '<span class="sc-vh-caret"><i class="bi bi-chevron-right"></i></span>';
                $html .= '<i class="bi bi-capsule sc-vh-branch-icon"></i> ' . $enc($t['pharmacy']);
                $html .= '<span class="sc-vh-count">' . $rxCount . '</span>';
                $html .= '</summary>';
                $html .= '<div class="sc-vh-branch-body">';
                foreach ($rxList as $rx) {
                    $timeLabel = ($rt = strtotime((string)$rx['created_at'])) ? date('h:i A', $rt) : '';
                    $html .= '<div class="sc-vh-entry">';
                    $html .= '<div class="sc-vh-entry-head">';
                    $html .= '<span class="sc-vh-entry-title">' . $enc($t['diagnosis']) . ': ' . $enc(medicalCenterMarkdownToPlain($rx['diagnosis'])) . '</span>';
                    $html .= '<span class="badge ' . medicalCenterPrescriptionStatusBadgeClass((string)$rx['status']) . '">'
                        . $enc(medicalCenterPrescriptionStatusLabel((string)$rx['status'], $locale)) . '</span>';
                    $html .= $doctorChip((string)($rx['doctor_name'] ?? ''));
                    if ($timeLabel !== '') {
                        $html .= '<span class="sc-vh-time"><i class="bi bi-clock"></i> ' . $enc($timeLabel) . '</span>';
                    }
                    if ($canView((int)($rx['doctor_id'] ?? 0))) {
                        $html .= '<a class="sc-vh-view" href="' . $enc($rxViewBase . (int)$rx['id']) . '"><i class="bi bi-eye"></i> ' . $enc($t['view']) . '</a>';
                    }
                    $html .= '</div>';
                    $historyPlain = medicalCenterMarkdownToPlain($rx['history']);
                    if ($historyPlain !== '') {
                        $html .= '<div class="sc-vh-note"><span class="sc-vh-note-label">' . $enc($t['history']) . ':</span> ' . $enc($historyPlain) . '</div>';
                    }
                    $examinationPlain = medicalCenterMarkdownToPlain($rx['examination']);
                    if ($examinationPlain !== '') {
                        $html .= '<div class="sc-vh-note"><span class="sc-vh-note-label">' . $enc($t['examination']) . ':</span> ' . $enc($examinationPlain) . '</div>';
                    }
                    // Image attachments, grouped by section (History / Examination /
                    // Diagnoses). Each thumbnail opens in the shared lightbox.
                    $rxAttachments = is_array($rx['attachments'] ?? null) ? $rx['attachments'] : [];
                    $sectionLabels = [
                        'history' => $t['history'],
                        'examination' => $t['examination'],
                        'diagnosis' => $t['diagnosis'],
                    ];
                    foreach ($sectionLabels as $sectionKey => $sectionLabel) {
                        $sectionImages = $rxAttachments[$sectionKey] ?? [];
                        if ($sectionImages === []) {
                            continue;
                        }
                        $html .= '<div class="sc-vh-images">';
                        $html .= '<span class="sc-vh-images-label"><i class="bi bi-images"></i> ' . $enc($sectionLabel) . '</span>';
                        $html .= medicalDoctorRenderAttachmentStrip($sectionImages);
                        $html .= '</div>';
                    }
                    if ($rx['items'] === []) {
                        $html .= '<div class="sc-vh-muted">' . $enc($t['no_meds']) . '</div>';
                    } else {
                        $html .= '<ul class="sc-vh-meds">';
                        foreach ($rx['items'] as $item) {
                            $qty = medicalDoctorVisitQtyLabel((float)$item['quantity']);
                            $unit = $item['unit'] !== '' ? ' ' . $item['unit'] : '';
                            $html .= '<li><i class="bi bi-dot"></i><span class="sc-vh-med-name">' . $enc($item['name']) . '</span>'
                                . '<span class="sc-vh-med-qty">' . $enc($qty . $unit) . '</span></li>';
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div></details>';
            }

            // Lab branch
            if ($labCount > 0) {
                $html .= '<details class="sc-vh-branch sc-vh-branch-lab" open>';
                $html .= '<summary class="sc-vh-branch-head">';
                $html .= '<span class="sc-vh-caret"><i class="bi bi-chevron-right"></i></span>';
                $html .= '<i class="bi bi-clipboard2-pulse sc-vh-branch-icon"></i> ' . $enc($t['lab']);
                $html .= '<span class="sc-vh-count">' . $labCount . '</span>';
                $html .= '</summary>';
                $html .= '<div class="sc-vh-branch-body">';
                foreach ($labList as $order) {
                    $timeLabel = ($ot = strtotime((string)$order['created_at'])) ? date('h:i A', $ot) : '';
                    $html .= '<div class="sc-vh-entry">';
                    $html .= '<div class="sc-vh-entry-head">';
                    $html .= '<span class="sc-vh-entry-title">' . count($order['tests']) . ' ' . $enc($t['tests']) . '</span>';
                    $html .= '<span class="badge text-bg-' . labOrderStatusBadge((string)$order['status']) . '">'
                        . $enc(labOrderStatusLabel((string)$order['status'], $locale)) . '</span>';
                    $html .= $doctorChip((string)($order['doctor_name'] ?? ''));
                    if ($timeLabel !== '') {
                        $html .= '<span class="sc-vh-time"><i class="bi bi-clock"></i> ' . $enc($timeLabel) . '</span>';
                    }
                    if ($canView((int)($order['doctor_id'] ?? 0))) {
                        $html .= '<a class="sc-vh-view" href="' . $enc($labViewBase . (int)$order['id']) . '"><i class="bi bi-eye"></i> ' . $enc($t['view']) . '</a>';
                    }
                    $html .= '</div>';
                    if ($order['tests'] === []) {
                        $html .= '<div class="sc-vh-muted">' . $enc($t['no_tests']) . '</div>';
                    } else {
                        $html .= '<div class="sc-vh-tests">';
                        foreach ($order['tests'] as $testName) {
                            $html .= '<span class="sc-vh-test-chip">' . $enc($testName) . '</span>';
                        }
                        $html .= '</div>';
                    }
                    if ($order['notes'] !== '') {
                        $html .= '<div class="sc-vh-muted"><i class="bi bi-chat-left-text"></i> ' . $enc($t['note']) . ': ' . $enc($order['notes']) . '</div>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div></details>';
            }

            $html .= '</div></details>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('medicalDoctorRenderVisitTree')) {
    /**
     * Build the visit history as a master–detail view, returned as two separate
     * fragments so the caller can place them in different columns: a clickable
     * tree ('nav' — grouped Clinic visit → Pharmacy / Lab leaf nodes) meant for
     * the right rail, and a detail region ('detail') meant for the middle column
     * that shows the full, nicely-rendered record of whichever node is clicked —
     * the same look the doctor sees when filling in a consultation.
     *
     * All entry details are rendered up-front into hidden panels; doctor-ui.js
     * (initVisitTree) reveals the one matching the clicked node. No extra requests.
     *
     * We only model what this codebase stores — Pharmacy (prescriptions) and Lab
     * orders — so there is no pathology / hospital / radiology / "fill form" node.
     *
     * @param array<string,mixed>|null $history result of medicalDoctorFetchPatientVisitHistory()
     * @return array{nav:string, detail:string, first:string}
     */
    function medicalDoctorRenderVisitTree(?array $history, string $locale = 'en'): array
    {
        $enc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $isEn = $locale === 'en';

        $t = [
            'empty'       => $isEn ? 'No previous visits recorded for this patient.' : 'هیچ سەردانێکی پێشوو بۆ ئەم نەخۆشە تۆمار نەکراوە.',
            'placeholder' => $isEn ? 'Select an item from the tree to view its details here.' : 'شتێک لە دارەکەوە هەڵبژێرە بۆ بینینی وردەکارییەکانی لێرە.',
            'visit'       => $isEn ? 'Clinic' : 'کلینیک',
            'visit_detail'=> $isEn ? 'Visit Detail' : 'وردەکاری سەردان',
            'pharmacy'    => $isEn ? 'Pharmacy' : 'دەرمانخانە',
            'lab'         => $isEn ? 'Lab' : 'تاقیگە',
            'treatment'   => $isEn ? 'Treatment' : 'چارەسەری',
            'history'     => $isEn ? 'History' : 'مێژوو',
            'examination' => $isEn ? 'Examination' : 'پشکنین',
            'diagnosis'   => $isEn ? 'Diagnoses' : 'دەستنیشانکردن',
            'tests'       => $isEn ? 'Tests' : 'پشکنینەکان',
            'no_meds'     => $isEn ? 'No medications recorded' : 'هیچ دەرمانێک تۆمار نەکراوە',
            'no_tests'    => $isEn ? 'No tests recorded' : 'هیچ پشکنینێک تۆمار نەکراوە',
            'no_note'     => $isEn ? 'No clinical note recorded' : 'هیچ تێبینییەکی نەخۆشی تۆمار نەکراوە',
            'view'        => $isEn ? 'Open full page' : 'کردنەوەی پەڕەی تەواو',
            'note'        => $isEn ? 'Note' : 'تێبینی',
            'by'          => $isEn ? 'Dr.' : 'د.',
            'consult'     => $isEn ? 'Consultation' : 'ڕاوێژکاری',
        ];

        if ($history === null || $history['visits'] === []) {
            return [
                'nav' => '<div class="sc-vh-empty"><i class="bi bi-diagram-3"></i><p class="mb-0">' . $enc($t['empty']) . '</p></div>',
                'detail' => '',
                'first' => '',
            ];
        }

        $rxViewBase = url('professions/medical-center/doctor/prescriptions/view.php?id=');
        $labViewBase = url('professions/medical-center/doctor/lab-orders/view.php?id=');

        $doctorChip = static function (string $name) use ($enc, $t): string {
            $name = trim($name);
            if ($name === '') {
                return '';
            }
            return '<span class="sc-vh-doctor"><i class="bi bi-person-badge"></i> '
                . $enc($t['by'] . ' ' . $name) . '</span>';
        };

        $treeHtml = '<div class="sc-vt-tree">';
        // Detail region inner HTML (the caller wraps it in .sc-vt-detail in the
        // middle column). Starts with a placeholder shown before anything is picked.
        $detailHtml = '<div class="sc-vt-placeholder" data-vt-placeholder>'
            . '<i class="bi bi-hand-index-thumb"></i><p class="mb-0">' . $enc($t['placeholder']) . '</p></div>';

        $totalVisits = count($history['visits']);
        $firstNodeId = '';

        foreach ($history['visits'] as $index => $visit) {
            // Oldest visit is #1 so the numbering grows with the timeline.
            $visitNo = $totalVisits - $index;
            // Clicking the visit itself opens this clinical panel (History /
            // Examination / Diagnoses); the Pharmacy leaf under it opens the
            // treatment-only panel.
            $visitNodeId = 'vt-visit-' . $index;
            $rxList = $visit['prescriptions'];
            $labList = $visit['lab_orders'];

            $ts = strtotime((string)$visit['date'] . ' 00:00:00');
            $dateLabel = $ts ? date('D, d M Y', $ts) : (string)$visit['date'];

            // Distinct treating doctors on this day (owner + any referral specialist).
            $docNames = [];
            foreach ($rxList as $rx) {
                $dn = trim((string)($rx['doctor_name'] ?? ''));
                if ($dn !== '') {
                    $docNames[$dn] = true;
                }
            }
            foreach ($labList as $lo) {
                $dn = trim((string)($lo['doctor_name'] ?? ''));
                if ($dn !== '') {
                    $docNames[$dn] = true;
                }
            }
            $docLabel = implode(' · ', array_map(static fn($n) => $t['by'] . ' ' . $n, array_keys($docNames)));

            $openVisit = $index === 0 ? ' open' : '';
            $treeHtml .= '<details class="sc-vt-visit"' . $openVisit . '>';
            $treeHtml .= '<summary class="sc-vt-visit-head">';
            $treeHtml .= '<span class="sc-vh-caret"><i class="bi bi-chevron-right"></i></span>';
            $treeHtml .= '<i class="bi bi-hospital sc-vt-visit-icon"></i>';
            $treeHtml .= '<span class="sc-vt-visit-main">';
            $treeHtml .= '<span class="sc-vt-visit-title">' . $enc($t['visit'] . ' ' . $visitNo) . '</span>';
            $treeHtml .= '<span class="sc-vt-visit-meta">';
            $treeHtml .= '<span class="sc-vt-visit-date"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
            if ($docLabel !== '') {
                $treeHtml .= '<span class="sc-vt-visit-doc">' . $enc($docLabel) . '</span>';
            }
            $treeHtml .= '</span>';
            $treeHtml .= '</span>';
            $treeHtml .= '</summary>';
            $treeHtml .= '<div class="sc-vt-visit-body">';

            // "Visit Detail" leaf: sits above Pharmacy/Lab and opens the visit's
            // clinical panel (History / Examination / Diagnoses). Clicking the
            // Clinic summary above only expands/collapses this list — this button
            // is what reveals the detail panel.
            $treeHtml .= '<button type="button" class="sc-vt-node sc-vt-node-visit" data-vt-target="' . $enc($visitNodeId) . '">';
            $treeHtml .= '<i class="bi bi-file-earmark-medical sc-vt-node-icon"></i>';
            $treeHtml .= '<span class="sc-vt-node-text">';
            $treeHtml .= '<span class="sc-vt-node-label">' . $enc($t['visit_detail']) . '</span>';
            $treeHtml .= '</span>';
            $treeHtml .= '<i class="bi bi-chevron-right sc-vt-node-caret"></i>';
            $treeHtml .= '</button>';

            // --- Visit-level clinical detail panel (History / Examination /
            // Diagnoses) --- shown when the visit node itself is clicked. The
            // clinical notes live on the visit's prescription(s); the treatment
            // (medications) is intentionally left to the Pharmacy leaf panel.
            $detailHtml .= '<div class="sc-vt-panel" id="' . $enc($visitNodeId) . '" data-vt-panel hidden>';
            $detailHtml .= '<div class="sc-vt-panel-head">';
            $detailHtml .= '<div class="sc-vt-panel-titles">';
            $detailHtml .= '<h3 class="sc-vt-panel-title"><i class="bi bi-hospital"></i> ' . $enc($t['visit'] . ' ' . $visitNo) . '</h3>';
            $detailHtml .= '<div class="sc-vt-panel-meta">';
            $detailHtml .= '<span class="sc-vh-time"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
            if ($docLabel !== '') {
                $detailHtml .= '<span class="sc-vh-doctor"><i class="bi bi-person-badge"></i> ' . $enc($docLabel) . '</span>';
            }
            $detailHtml .= '</div>';
            $detailHtml .= '</div>';
            $detailHtml .= '</div>';

            $visitNoteSections = [
                ['history', $t['history'], 'bi-journal-text'],
                ['examination', $t['examination'], 'bi-clipboard2-pulse'],
                ['diagnosis', $t['diagnosis'], 'bi-journal-medical'],
            ];
            $renderedAnyVisitNote = false;
            foreach ($rxList as $rx) {
                $rxAttachments = is_array($rx['attachments'] ?? null) ? $rx['attachments'] : [];
                foreach ($visitNoteSections as [$sectionKey, $sectionLabel, $sectionIcon]) {
                    $noteHtml = medicalCenterRenderMarkdown((string)($rx[$sectionKey] ?? ''));
                    $gallery = medicalDoctorRenderAttachmentStrip($rxAttachments[$sectionKey] ?? []);
                    if ($noteHtml === '' && $gallery === '') {
                        continue;
                    }
                    $renderedAnyVisitNote = true;
                    $detailHtml .= '<div class="sc-vt-section">';
                    $detailHtml .= '<div class="sc-vt-section-label"><i class="bi ' . $sectionIcon . '"></i> ' . $enc($sectionLabel) . '</div>';
                    if ($noteHtml !== '') {
                        $detailHtml .= '<div class="sc-md-render">' . $noteHtml . '</div>';
                    }
                    $detailHtml .= $gallery;
                    $detailHtml .= '</div>';
                }
            }
            if (!$renderedAnyVisitNote) {
                $detailHtml .= '<div class="sc-vt-section"><div class="sc-vh-muted">' . $enc($t['no_note']) . '</div></div>';
            }
            $detailHtml .= '</div>'; // .sc-vt-panel (visit)

            // --- Pharmacy leaf nodes + their detail panels ---
            foreach ($rxList as $rx) {
                $nodeId = 'vt-rx-' . (int)$rx['id'];
                if ($firstNodeId === '') {
                    $firstNodeId = $nodeId;
                }
                $diagPlain = medicalCenterMarkdownToPlain($rx['diagnosis']);
                $subLabel = $diagPlain !== '' ? $diagPlain : $t['consult'];
                $timeLabel = ($rt = strtotime((string)$rx['created_at'])) ? date('h:i A', $rt) : '';

                $treeHtml .= '<button type="button" class="sc-vt-node sc-vt-node-rx" data-vt-target="' . $enc($nodeId) . '">';
                $treeHtml .= '<i class="bi bi-capsule sc-vt-node-icon"></i>';
                $treeHtml .= '<span class="sc-vt-node-text">';
                $treeHtml .= '<span class="sc-vt-node-label">' . $enc($t['pharmacy']) . '</span>';
                $treeHtml .= '<span class="sc-vt-node-sub">' . $enc($subLabel) . '</span>';
                $treeHtml .= '</span>';
                $treeHtml .= '<i class="bi bi-chevron-right sc-vt-node-caret"></i>';
                $treeHtml .= '</button>';

                // Detail panel
                $detailHtml .= '<div class="sc-vt-panel" id="' . $enc($nodeId) . '" data-vt-panel hidden>';
                $detailHtml .= '<div class="sc-vt-panel-head">';
                $detailHtml .= '<div class="sc-vt-panel-titles">';
                $detailHtml .= '<h3 class="sc-vt-panel-title"><i class="bi bi-capsule"></i> ' . $enc($t['pharmacy']) . '</h3>';
                $detailHtml .= '<div class="sc-vt-panel-meta">';
                $detailHtml .= '<span class="badge ' . medicalCenterPrescriptionStatusBadgeClass((string)$rx['status']) . '">'
                    . $enc(medicalCenterPrescriptionStatusLabel((string)$rx['status'], $locale)) . '</span>';
                $detailHtml .= $doctorChip((string)($rx['doctor_name'] ?? ''));
                $detailHtml .= '<span class="sc-vh-time"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
                if ($timeLabel !== '') {
                    $detailHtml .= '<span class="sc-vh-time"><i class="bi bi-clock"></i> ' . $enc($timeLabel) . '</span>';
                }
                $detailHtml .= '</div>';
                $detailHtml .= '</div>';
                $detailHtml .= '<a class="sc-vh-view" href="' . $enc($rxViewBase . (int)$rx['id']) . '"><i class="bi bi-box-arrow-up-right"></i> ' . $enc($t['view']) . '</a>';
                $detailHtml .= '</div>';

                // Pharmacy leaf shows only the treatment (medications). The
                // clinical notes (History / Examination / Diagnoses) live on the
                // visit-level panel above.
                $detailHtml .= '<div class="sc-vt-section">';
                $detailHtml .= '<div class="sc-vt-section-label"><i class="bi bi-capsule"></i> ' . $enc($t['treatment']) . '</div>';
                if ($rx['items'] === []) {
                    $detailHtml .= '<div class="sc-vh-muted">' . $enc($t['no_meds']) . '</div>';
                } else {
                    $detailHtml .= '<ul class="sc-vh-meds">';
                    foreach ($rx['items'] as $item) {
                        $qty = medicalDoctorVisitQtyLabel((float)$item['quantity']);
                        $unit = $item['unit'] !== '' ? ' ' . $item['unit'] : '';
                        $detailHtml .= '<li><i class="bi bi-dot"></i><span class="sc-vh-med-name">' . $enc($item['name']) . '</span>'
                            . '<span class="sc-vh-med-qty">' . $enc($qty . $unit) . '</span></li>';
                    }
                    $detailHtml .= '</ul>';
                }
                $detailHtml .= '</div>';
                $detailHtml .= '</div>'; // .sc-vt-panel
            }

            // --- Lab leaf nodes + their detail panels ---
            foreach ($labList as $order) {
                $nodeId = 'vt-lab-' . (int)$order['id'];
                if ($firstNodeId === '') {
                    $firstNodeId = $nodeId;
                }
                $testCount = count($order['tests']);
                $timeLabel = ($ot = strtotime((string)$order['created_at'])) ? date('h:i A', $ot) : '';

                $treeHtml .= '<button type="button" class="sc-vt-node sc-vt-node-lab" data-vt-target="' . $enc($nodeId) . '">';
                $treeHtml .= '<i class="bi bi-clipboard2-pulse sc-vt-node-icon"></i>';
                $treeHtml .= '<span class="sc-vt-node-text">';
                $treeHtml .= '<span class="sc-vt-node-label">' . $enc($t['lab']) . '</span>';
                $treeHtml .= '<span class="sc-vt-node-sub">' . $testCount . ' ' . $enc($t['tests']) . '</span>';
                $treeHtml .= '</span>';
                $treeHtml .= '<i class="bi bi-chevron-right sc-vt-node-caret"></i>';
                $treeHtml .= '</button>';

                $detailHtml .= '<div class="sc-vt-panel" id="' . $enc($nodeId) . '" data-vt-panel hidden>';
                $detailHtml .= '<div class="sc-vt-panel-head">';
                $detailHtml .= '<div class="sc-vt-panel-titles">';
                $detailHtml .= '<h3 class="sc-vt-panel-title"><i class="bi bi-clipboard2-pulse"></i> ' . $enc($t['lab']) . '</h3>';
                $detailHtml .= '<div class="sc-vt-panel-meta">';
                $detailHtml .= '<span class="badge text-bg-' . labOrderStatusBadge((string)$order['status']) . '">'
                    . $enc(labOrderStatusLabel((string)$order['status'], $locale)) . '</span>';
                $detailHtml .= $doctorChip((string)($order['doctor_name'] ?? ''));
                $detailHtml .= '<span class="sc-vh-time"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
                if ($timeLabel !== '') {
                    $detailHtml .= '<span class="sc-vh-time"><i class="bi bi-clock"></i> ' . $enc($timeLabel) . '</span>';
                }
                $detailHtml .= '</div>';
                $detailHtml .= '</div>';
                $detailHtml .= '<a class="sc-vh-view" href="' . $enc($labViewBase . (int)$order['id']) . '"><i class="bi bi-box-arrow-up-right"></i> ' . $enc($t['view']) . '</a>';
                $detailHtml .= '</div>';

                $detailHtml .= '<div class="sc-vt-section">';
                $detailHtml .= '<div class="sc-vt-section-label"><i class="bi bi-list-check"></i> ' . $enc($t['tests']) . '</div>';
                if ($order['tests'] === []) {
                    $detailHtml .= '<div class="sc-vh-muted">' . $enc($t['no_tests']) . '</div>';
                } else {
                    $detailHtml .= '<div class="sc-vh-tests">';
                    foreach ($order['tests'] as $testName) {
                        $detailHtml .= '<span class="sc-vh-test-chip">' . $enc($testName) . '</span>';
                    }
                    $detailHtml .= '</div>';
                }
                $detailHtml .= '</div>';
                if (($order['notes'] ?? '') !== '') {
                    $detailHtml .= '<div class="sc-vt-section">';
                    $detailHtml .= '<div class="sc-vt-section-label"><i class="bi bi-chat-left-text"></i> ' . $enc($t['note']) . '</div>';
                    $detailHtml .= '<div class="sc-vh-note">' . $enc((string)$order['notes']) . '</div>';
                    $detailHtml .= '</div>';
                }
                $detailHtml .= '</div>'; // .sc-vt-panel
            }

            $treeHtml .= '</div></details>';
        }

        $treeHtml .= '</div>';

        return [
            'nav' => $treeHtml,
            'detail' => $detailHtml,
            'first' => $firstNodeId,
        ];
    }
}

if (!function_exists('medicalDoctorRenderDirectLabOrders')) {
    /**
     * Render lab-initiated (direct) orders for a patient as a flat, dated list.
     * These were not placed by any doctor, so they are shown in their own section
     * — separate from the visit-history tree — with a clear "Direct" marker.
     *
     * @param array<int,array<string,mixed>> $orders from $history['direct_lab_orders']
     */
    function medicalDoctorRenderDirectLabOrders(array $orders, string $locale = 'en'): string
    {
        $enc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $isEn = $locale === 'en';
        $t = [
            'empty'  => $isEn ? 'No walk-in lab tests for this patient.' : 'هیچ فەحسێکی ڕاستەوخۆ بۆ ئەم نەخۆشە نییە.',
            'tests'  => $isEn ? 'tests' : 'پشکنین',
            'note'   => $isEn ? 'Note' : 'تێبینی',
            'view'   => $isEn ? 'View' : 'بینین',
            'no_tests' => $isEn ? 'No tests recorded' : 'هیچ پشکنینێک تۆمار نەکراوە',
        ];

        if ($orders === []) {
            return '<div class="sc-vh-empty"><i class="bi bi-clipboard2-x"></i><p class="mb-0">' . $enc($t['empty']) . '</p></div>';
        }

        $labViewBase = url('professions/medical-center/doctor/lab-orders/view.php?id=');

        $html = '<div class="sc-vh"><div class="sc-vh-branch-body">';
        foreach ($orders as $order) {
            $ts = strtotime((string)$order['created_at']);
            $dateLabel = $ts ? date('D, d M Y · h:i A', $ts) : (string)$order['created_at'];
            $html .= '<div class="sc-vh-entry">';
            $html .= '<div class="sc-vh-entry-head">';
            $html .= '<span class="sc-vh-entry-title">' . count($order['tests']) . ' ' . $enc($t['tests']) . '</span>';
            $html .= '<span class="badge text-bg-' . labOrderStatusBadge((string)$order['status']) . '">'
                . $enc(labOrderStatusLabel((string)$order['status'], $locale)) . '</span>';
            $html .= '<span class="sc-vh-time"><i class="bi bi-calendar3"></i> ' . $enc($dateLabel) . '</span>';
            $html .= '<a class="sc-vh-view" href="' . $enc($labViewBase . (int)$order['id']) . '"><i class="bi bi-eye"></i> ' . $enc($t['view']) . '</a>';
            $html .= '</div>';
            if ($order['tests'] === []) {
                $html .= '<div class="sc-vh-muted">' . $enc($t['no_tests']) . '</div>';
            } else {
                $html .= '<div class="sc-vh-tests">';
                foreach ($order['tests'] as $testName) {
                    $html .= '<span class="sc-vh-test-chip">' . $enc($testName) . '</span>';
                }
                $html .= '</div>';
            }
            if ($order['notes'] !== '') {
                $html .= '<div class="sc-vh-muted"><i class="bi bi-chat-left-text"></i> ' . $enc($t['note']) . ': ' . $enc($order['notes']) . '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';

        return $html;
    }
}
