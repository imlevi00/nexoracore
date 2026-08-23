<?php
/**
 * Designer grid partial — renders the editable test table.
 * Expects: $columns, $rows, $cells, $columnTypes (and helpers from lab_catalog_service.php).
 * Included by tests/designer.php and re-rendered by api/catalog.php (action=grid_html).
 */
/** @var array $columns */
/** @var array $rows */
/** @var array $cells */
/** @var array $columnTypes */

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$colTypeClass = [
    'result' => 'lab-col-result',
    'reference' => 'lab-col-reference',
    'unit' => 'lab-col-unit',
    'status' => 'lab-col-status',
    'text' => 'lab-col-text',
    'choice' => 'lab-col-choice',
];
?>
<?php if (empty($columns)): ?>
    <div class="lab-empty-hint">
        <i class="bi bi-table"></i>
        <p class="mb-0">هیچ ستوونێک نییە. بە دوگمەی <strong>«ستوون»</strong> ستوون زیاد بکە (نموونە: Result, Reference Range, Unit).</p>
    </div>
<?php else: ?>
<div class="lab-designer-wrap">
    <table class="lab-designer-table" id="designer-table">
        <thead>
            <tr>
                <th class="lab-sticky-col lab-corner-cell" style="min-width: 210px;">
                    <i class="bi bi-list-ul"></i> Name
                </th>
                <?php foreach ($columns as $i => $col): ?>
                    <?php
                    $type = (string)$col['col_type'];
                    $widthStyle = labColumnWidthStyle($col['width'] ?? null);
                    ?>
                    <th data-column-id="<?php echo (int)$col['id']; ?>"
                        class="lab-col-head <?php echo $colTypeClass[$type] ?? ''; ?>"
                        style="<?php echo $widthStyle; ?>">
                        <div class="lab-col-head-inner">
                            <div class="lab-col-head-text">
                                <span class="col-label"><?php echo $h($col['label']); ?></span>
                                <span class="lab-col-type-badge"><?php echo $h($columnTypes[$type] ?? $type); ?></span>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm lab-mini-btn" type="button"
                                        data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'
                                        aria-expanded="false" aria-label="فەرمانەکانی ستوون">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item btn-edit-column" type="button"
                                        data-id="<?php echo (int)$col['id']; ?>"
                                        data-label="<?php echo $h($col['label']); ?>"
                                        data-type="<?php echo $h($type); ?>"
                                        data-width="<?php echo $h($col['width'] ?? ''); ?>"><i class="bi bi-pencil"></i> دەستکاری ستوون</button></li>
                                    <li><button class="dropdown-item btn-move-column" type="button" data-id="<?php echo (int)$col['id']; ?>" data-dir="up" <?php echo $i === 0 ? 'disabled' : ''; ?>><i class="bi bi-chevron-right"></i> بەرەوپێش</button></li>
                                    <li><button class="dropdown-item btn-move-column" type="button" data-id="<?php echo (int)$col['id']; ?>" data-dir="down" <?php echo $i === count($columns) - 1 ? 'disabled' : ''; ?>><i class="bi bi-chevron-left"></i> بەرەودوا</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item text-danger btn-delete-column" type="button" data-id="<?php echo (int)$col['id']; ?>"><i class="bi bi-trash"></i> سڕینەوەی ستوون</button></li>
                                </ul>
                            </div>
                        </div>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody id="designer-rows">
            <?php foreach ($rows as $ri => $row): ?>
                <?php
                $referenceDisplayHtml = labReferenceDisplayHtml($row);
                $rowId = (int)$row['id'];
                $rowRangesJson = json_encode(array_map(static function (array $r): array {
                    return [
                        'label'         => (string)($r['label'] ?? ''),
                        'gender'        => (string)($r['gender'] ?? 'any'),
                        'age_min_value' => $r['age_min_value'] !== null ? labTrimNumber($r['age_min_value']) : '',
                        'age_min_unit'  => (string)($r['age_min_unit'] ?? 'year'),
                        'age_max_value' => $r['age_max_value'] !== null ? labTrimNumber($r['age_max_value']) : '',
                        'age_max_unit'  => (string)($r['age_max_unit'] ?? 'year'),
                        'normal_min'    => $r['normal_min'] !== null ? labTrimNumber($r['normal_min']) : '',
                        'normal_max'    => $r['normal_max'] !== null ? labTrimNumber($r['normal_max']) : '',
                    ];
                }, $row['ranges'] ?? []), JSON_UNESCAPED_UNICODE);
                ?>
                <tr data-row-id="<?php echo $rowId; ?>"
                    data-result-type="<?php echo $h($row['result_type']); ?>"
                    data-normal-min-male="<?php echo $h($row['normal_min_male'] ?? ''); ?>"
                    data-normal-max-male="<?php echo $h($row['normal_max_male'] ?? ''); ?>"
                    data-normal-min-female="<?php echo $h($row['normal_min_female'] ?? ''); ?>"
                    data-normal-max-female="<?php echo $h($row['normal_max_female'] ?? ''); ?>"
                    data-normal-text="<?php echo $h($row['normal_text'] ?? ''); ?>"
                    data-ranges="<?php echo $h($rowRangesJson !== false ? $rowRangesJson : '[]'); ?>"
                    data-unit="<?php echo $h($row['unit'] ?? ''); ?>">
                    <td class="lab-row-head lab-sticky-col">
                        <div class="lab-row-head-inner">
                            <span class="lab-row-drag" draggable="true" title="ڕاکێشە بۆ گواستنەوە"><i class="bi bi-grip-vertical"></i></span>
                            <input type="text" class="lab-cell-input row-name" data-row-id="<?php echo $rowId; ?>"
                                   value="<?php echo $h($row['name']); ?>" placeholder="Name">
                            <span class="lab-row-type-chip <?php echo $row['result_type'] === 'text' ? 'is-text' : 'is-numeric'; ?>" title="جۆری ئەنجام">
                                <?php echo $row['result_type'] === 'text' ? 'نووسین' : 'ژمارە'; ?>
                            </span>
                            <div class="dropdown">
                                <button class="btn btn-sm lab-mini-btn" type="button"
                                        data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'
                                        aria-expanded="false" aria-label="فەرمانەکانی ڕیز">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item btn-row-settings" type="button" data-id="<?php echo $rowId; ?>"><i class="bi bi-sliders"></i> نۆرمەڵ ڕێنج و یەکە</button></li>
                                    <li><button class="dropdown-item btn-duplicate-row" type="button" data-id="<?php echo $rowId; ?>"><i class="bi bi-files"></i> لەبەرگرتنەوە</button></li>
                                    <li><button class="dropdown-item btn-move-row" type="button" data-id="<?php echo $rowId; ?>" data-dir="up" <?php echo $ri === 0 ? 'disabled' : ''; ?>><i class="bi bi-chevron-up"></i> بۆ سەرەوە</button></li>
                                    <li><button class="dropdown-item btn-move-row" type="button" data-id="<?php echo $rowId; ?>" data-dir="down" <?php echo $ri === count($rows) - 1 ? 'disabled' : ''; ?>><i class="bi bi-chevron-down"></i> بۆ خوارەوە</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item text-danger btn-delete-row" type="button" data-id="<?php echo $rowId; ?>"><i class="bi bi-trash"></i> سڕینەوەی ڕیز</button></li>
                                </ul>
                            </div>
                        </div>
                    </td>
                    <?php foreach ($columns as $col): ?>
                        <?php $type = (string)$col['col_type']; $colId = (int)$col['id']; ?>
                        <td class="<?php echo $colTypeClass[$type] ?? ''; ?>">
                            <?php if ($type === 'result'): ?>
                                <div class="lab-cell-result-readonly"><i class="bi bi-pencil-square"></i> پڕ دەکرێتەوە</div>
                            <?php elseif ($type === 'choice'): ?>
                                <?php $cellOptionsJson = labCellOptionsJson($cells, $rowId, $colId); ?>
                                <textarea class="lab-cell-input choice-options-cell" rows="2"
                                          data-row-id="<?php echo $rowId; ?>" data-column-id="<?php echo $colId; ?>"
                                          placeholder="Positive&#10;Negative"><?php echo $h(labColumnOptionsDisplay($cellOptionsJson)); ?></textarea>
                            <?php elseif ($type === 'reference'): ?>
                                <div class="lab-derived-cell reference-cell"><?php echo $referenceDisplayHtml; ?></div>
                            <?php elseif ($type === 'unit'): ?>
                                <div class="lab-derived-cell unit-cell"><?php echo $h($row['unit'] ?? ''); ?></div>
                            <?php elseif ($type === 'status'): ?>
                                <div class="lab-derived-cell status-cell text-center"><?php echo labFlagMarkup('normal'); ?></div>
                            <?php else: ?>
                                <input type="text" class="lab-cell-input text-cell"
                                       data-row-id="<?php echo $rowId; ?>" data-column-id="<?php echo $colId; ?>"
                                       value="<?php echo $h(labCellContent($cells, $rowId, $colId)); ?>">
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($rows)): ?>
    <div class="lab-empty-hint mt-3">
        <i class="bi bi-plus-circle"></i>
        <p class="mb-0">هیچ ڕیزێک (Name) نییە. بە دوگمەی <strong>«ڕیز»</strong> Name زیاد بکە.</p>
    </div>
<?php endif; ?>
<?php endif; ?>
