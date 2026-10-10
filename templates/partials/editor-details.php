<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's Details panel (roadmap phase 14, Service\FrontmatterFields):
 * native form fields for the frontmatter. Included by templates/editor.php
 * only when `$raw` is false, as the Metadata view: the crumbs line's
 * Metadata button swaps it in for the toolbar and text (2026-09-29); it
 * has no Save of its own — the save bar's Save submits it with the body,
 * and hidden fields still post.
 * Every rendered field posts a `fm_shown[]` marker alongside its value(s)
 * — one per field, whatever its widget — so the controller can tell "this
 * was cleared" from "this was never on the page" for a checkbox or an
 * empty multi-select, neither of which post anything on their own
 * (Service\FrontmatterFields::changesFrom()).
 *
 * Variables in scope: array $details (Service\FrontmatterFields::forPage()'s
 * result); string $path, $basePath.
 */

declare(strict_types=1);

/** @var array{fields: list<array{key: string, label: string, widget: string, value: mixed, options: list<array{value: string, label: string}>, required: bool}>, patient: ?list<array{key: string, label: string, widget: string, value: string, options: list<array{value: string, label: string}>, required: bool}>, accession: ?string, visibility: string, extra: array<string, mixed>} $details */
/** @var string $path */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = htmlspecialchars($basePath, ENT_QUOTES);
$name = static fn (string $dottedKey): string => 'fm[' . str_replace('.', '][', $dottedKey) . ']';

/**
 * Phase 31: a template's checklist as rows — section or item (label +
 * keywords), add / remove / move with assets/js/details-checklist.js, which
 * also marks each item as the editor would against the template's own text.
 * A line the form cannot read is a `raw` row, posted back unchanged.
 * Without JavaScript the rows are still edited (an empty one is dropped).
 */
$checklistRow = static function (string $id, array $row) use ($e): string {
    $n = 'fm[checklist][' . $id . ']';
    $tools = '<span class="wk-cl-tools" hidden>'
        . '<button type="button" class="wk-tbtn" data-cl-move="-1" title="' . $e(t('details.checklist_up')) . '" aria-label="' . $e(t('details.checklist_up')) . '"><i class="ph ph-arrow-up" aria-hidden="true"></i></button>'
        . '<button type="button" class="wk-tbtn" data-cl-move="1" title="' . $e(t('details.checklist_down')) . '" aria-label="' . $e(t('details.checklist_down')) . '"><i class="ph ph-arrow-down" aria-hidden="true"></i></button>'
        . '<button type="button" class="wk-tbtn" data-cl-remove title="' . $e(t('details.checklist_remove')) . '" aria-label="' . $e(t('details.checklist_remove')) . '"><i class="ph ph-trash" aria-hidden="true"></i></button>'
        . '</span>';
    $kind = '<input type="hidden" name="' . $e($n) . '[kind]" value="' . $e($row['kind']) . '">';
    if ($row['kind'] === 'raw') {
        return '<li class="wk-cl-row wk-cl-raw" data-cl-row>' . $kind . '<input type="hidden" name="' . $e($n) . '[raw]" value="' . $e($row['raw_json']) . '">'
            . '<span class="wk-mono wk-dim" title="' . $e(t('details.checklist_raw')) . '">' . $e($row['raw_json']) . '</span><span class="wk-cl-mark wk-dim">' . $e(t('details.checklist_raw')) . '</span>' . $tools . '</li>';
    }
    if ($row['kind'] === 'section') {
        return '<li class="wk-cl-row wk-cl-section" data-cl-row>' . $kind
            . '<input class="input" type="text" name="' . $e($n) . '[label]" value="' . $e($row['label']) . '" placeholder="' . $e(t('details.checklist_section_ph')) . '" aria-label="' . $e(t('details.checklist_section')) . '">' . $tools . '</li>';
    }

    return '<li class="wk-cl-row" data-cl-row>' . $kind
        . '<input class="input" type="text" name="' . $e($n) . '[label]" value="' . $e($row['label']) . '" placeholder="' . $e(t('details.checklist_label_ph')) . '" aria-label="' . $e(t('details.checklist_label')) . '">'
        . '<input class="input wk-mono" type="text" name="' . $e($n) . '[keywords]" value="' . $e(implode(', ', $row['keywords'])) . '" placeholder="' . $e(t('details.checklist_keywords_ph')) . '" aria-label="' . $e(t('details.checklist_keywords')) . '" data-cl-keywords>'
        . '<span class="wk-cl-mark wk-dim" data-cl-mark></span>' . $tools . '</li>';
};

/** One field's control, its label and its `fm_shown[]` marker */
$field = static function (array $f) use ($e, $name, $checklistRow): void {
    $inputName = $name($f['key']);
    $shownMarker = '<input type="hidden" name="fm_shown[]" value="' . $e($f['key']) . '">';
    // The star is for the eye; a screen reader hears the words
    $req = $f['required'] ? '<span class="wk-required-mark" title="' . $e(t('details.required')) . '" aria-hidden="true">*</span><span class="wk-vh"> (' . $e(t('details.required')) . ')</span>' : '';

    if ($f['widget'] === 'checklist') {
        $rows = (array) $f['value'];
        echo '<fieldset class="wk-cl" data-island="details-checklist" data-max="' . \Reporion\Support\Checklist::MAX . '"><legend><span class="wk-field-label">' . $e($f['label']) . '</span></legend>';
        echo '<small class="wk-dim">' . $e((string) ($f['help'] ?? '')) . '</small>';
        echo '<ol class="wk-cl-rows" data-cl-rows>';
        foreach ($rows as $i => $row) {
            echo $checklistRow((string) $i, $row);
        }
        // Two blank items: room to add without JavaScript (an empty row is dropped on save)
        echo $checklistRow('b1', ['kind' => 'item', 'label' => '', 'keywords' => []]) . $checklistRow('b2', ['kind' => 'item', 'label' => '', 'keywords' => []]);
        echo '</ol>';
        echo '<div class="wk-cl-foot"><span class="wk-cl-add" hidden><button type="button" class="btn btn-secondary btn-sm" data-cl-add="item"><i class="ph ph-plus" aria-hidden="true"></i>' . $e(t('details.checklist_add_item')) . '</button>'
            . '<button type="button" class="btn btn-ghost btn-sm" data-cl-add="section"><i class="ph ph-text-h" aria-hidden="true"></i>' . $e(t('details.checklist_add_section')) . '</button></span>'
            . '<span class="wk-mono wk-dim wk-text-xs" data-cl-count></span></div>';
        echo '<template data-cl-blank-item>' . $checklistRow('__id__', ['kind' => 'item', 'label' => '', 'keywords' => []]) . '</template>';
        echo '<template data-cl-blank-section>' . $checklistRow('__id__', ['kind' => 'section', 'label' => '', 'keywords' => []]) . '</template>';
        echo '<script type="application/json" data-cl-config>' . json_encode([
            'count' => t('details.checklist_count'),
            'over' => t('details.checklist_over'),
            'inText' => t('details.checklist_in_text'),
            'notInText' => t('details.checklist_not_in_text'),
            'byHand' => t('details.checklist_by_hand'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
        echo '<input type="hidden" name="fm_shown[]" value="' . $e($f['key']) . '"></fieldset>';

        return;
    }

    if ($f['widget'] === 'checkboxes') {
        // Its own fieldset, not a <label>: several checkboxes, one name[]
        echo '<fieldset class="wk-form-grid wk-checkgrid"><legend><span class="wk-field-label">' . $e($f['label']) . $req . '</span></legend>';
        foreach ($f['options'] as $opt) {
            $checked = \in_array($opt['value'], (array) $f['value'], true);
            // .wk-checkgrid label: a row, not .wk-form-grid label's column (wiki.css)
            echo '<label class="radio"><input type="checkbox" name="' . $e($inputName) . '[]" value="' . $e($opt['value']) . '"' . ($checked ? ' checked' : '') . '><span class="dot"></span>' . $e($opt['label']) . '</label>';
        }
        echo $shownMarker . '</fieldset>';

        return;
    }

    echo '<label><span class="wk-field-label">' . $e($f['label']) . $req . '</span>';
    switch ($f['widget']) {
        case 'textarea':
            echo '<textarea class="input" name="' . $e($inputName) . '" rows="3">' . $e((string) $f['value']) . '</textarea>';
            break;
        case 'select':
            // The blank choice, unless the field names its own (format: Markdown)
            echo '<select class="input" name="' . $e($inputName) . '">' . (\in_array('', array_column($f['options'], 'value'), true) ? '' : '<option value="">—</option>');
            foreach ($f['options'] as $opt) {
                echo '<option value="' . $e($opt['value']) . '"' . ($opt['value'] === $f['value'] ? ' selected' : '') . '>' . $e($opt['label']) . '</option>';
            }
            echo '</select>';
            break;
        case 'date':
            echo '<input class="input" type="date" name="' . $e($inputName) . '" value="' . $e((string) $f['value']) . '">';
            break;
        default:
            echo '<input class="input" type="text" name="' . $e($inputName) . '" value="' . $e((string) $f['value']) . '">';
    }
    // A field may say where its choices come from (phase 25's reference page)
    echo (($f['help'] ?? '') !== '' ? '<small class="wk-dim">' . $e($f['help']) . '</small>' : '') . $shownMarker . '</label>';
};
?>
<div class="wk-panel wk-edit-meta" id="editor-details"<?= ($details['visibilityAckMissing'] ?? false) ? ' data-open="visibility"' : '' ?>>
<section aria-labelledby="details-h">
<header class="wk-panel-h"><h2 class="wk-eyebrow" id="details-h"><?= $e(t('details.panel')) ?></h2></header>
<div class="wk-form-grid">
<?php foreach ($details['fields'] as $f): $field($f); endforeach; ?>
</div>
</section>

<?php if ($details['patient'] !== null): ?>
<section aria-labelledby="details-patient-h">
<header class="wk-panel-h wk-mt-4"><h2 class="wk-eyebrow" id="details-patient-h"><?= $e(t('details.patient')) ?></h2></header>
<div class="wk-form-grid">
<?php foreach ($details['patient'] as $f): $field($f); endforeach; ?>
</div>
</section>
<?php endif; ?>

<?php if (($details['exams'] ?? null) !== null): ?>
<?php
/** One exam's card (phase 28b): its fields, its order marker, what it holds that is not edited here */
$card = static function (array $ex, int $n) use ($e, $field): void {
    echo '<section class="wk-examcard" data-exam-id="' . $e($ex['id']) . '">';
    echo '<header class="wk-examcard-h"><b class="wk-examcard-n">' . $e(t('details.exam_n', [$n])) . '</b>';
    if ($ex['accession'] !== '') {
        echo '<span class="wk-mono wk-dim" title="' . $e(t('details.accession_help')) . '">' . $e($ex['accession']) . '</span>';
    }
    if ($ex['study'] !== '') {
        echo '<span class="wk-mono wk-dim" title="' . $e(t('details.exam_study')) . '"><i class="ph ph-link-simple" aria-hidden="true"></i> ' . $e($ex['study']) . '</span>';
    }
    echo '<span class="wk-tflex"></span><span class="wk-examcard-tools" hidden>'
        . '<button type="button" class="wk-tbtn" data-exam-move="-1" title="' . $e(t('details.exam_up')) . '" aria-label="' . $e(t('details.exam_up')) . '"><i class="ph ph-arrow-up" aria-hidden="true"></i></button>'
        . '<button type="button" class="wk-tbtn" data-exam-move="1" title="' . $e(t('details.exam_down')) . '" aria-label="' . $e(t('details.exam_down')) . '"><i class="ph ph-arrow-down" aria-hidden="true"></i></button>'
        . '<button type="button" class="wk-tbtn" data-exam-remove title="' . $e(t('details.exam_remove')) . '" aria-label="' . $e(t('details.exam_remove')) . '"><i class="ph ph-trash" aria-hidden="true"></i></button>'
        . '</span></header>';
    echo '<input type="hidden" name="fm[exam_order][]" value="' . $e($ex['id']) . '">';
    echo '<div class="wk-form-grid">';
    foreach ($ex['fields'] as $f) {
        $field($f);
    }
    echo '</div>';
    if ($ex['extra'] !== []) {
        echo '<p class="wk-dim wk-text-sm">' . $e(t('details.exam_extra', [implode(', ', array_keys($ex['extra']))])) . '</p>';
    }
    echo '</section>';
};
?>
<section aria-labelledby="details-exams-h">
<header class="wk-panel-h wk-mt-4"><h2 class="wk-eyebrow" id="details-exams-h"><?= $e(t('details.exams')) ?></h2><button type="button" class="btn btn-secondary btn-sm" data-exam-add hidden><i class="ph ph-plus" aria-hidden="true"></i><?= $e(t('details.exam_add')) ?></button></header>
<input type="hidden" name="fm_shown[]" value="exam_order">
<div class="wk-examcards" data-exam-cards data-confirm-remove="<?= $e(t('details.exam_remove_confirm')) ?>" data-shape="<?= $e(t('details.exam_shape')) ?>" data-new-title="<?= $e(t('editor.exam.new')) ?>">
<?php foreach ($details['exams'] as $i => $ex): $card($ex, $i + 1); endforeach; ?>
</div>
<p class="wk-dim wk-text-sm" data-exam-why role="status" hidden></p>
<template data-exam-blank><?php $card($details['examBlank'], 0); ?></template>
</section>
<?php endif; ?>

<section aria-labelledby="details-vis-h">
<header class="wk-panel-h wk-mt-4"><h2 class="wk-eyebrow" id="details-vis-h"><?= $e(t('details.visibility')) ?></h2></header>
<?php
$visName = 'visibility';
$visNow = $details['visibilityNow'] ?? $details['visibility'];
$visChosen = $details['visibility'];
$visPreview = $details['visibilityPreview'] ?? \Reporion\Service\Publishing::previewOf($path, []);
$visAckMissing = $details['visibilityAckMissing'] ?? false;
include __DIR__ . '/visibility-picker.php';
?>
</section>

<?php if ($details['accession'] !== null && ($details['exams'] ?? null) === null): ?>
<div class="wk-form-grid wk-mt-4">
<label><?= $e(t('details.accession')) ?><span class="wk-mono"><?= $e($details['accession']) ?></span><small class="wk-dim"><?= $e(t('details.accession_help')) ?></small></label>
</div>
<?php endif; ?>

<?php if ($details['extra'] !== []): ?>
<section aria-labelledby="details-extra-h">
<header class="wk-panel-h wk-mt-4"><hgroup><h2 class="wk-eyebrow" id="details-extra-h"><?= $e(t('details.extra')) ?></h2><p class="wk-dim"><?= $e(t('details.extra_help')) ?></p></hgroup></header>
<dl class="wk-kv">
<?php foreach ($details['extra'] as $key => $value): ?>
<dt><?= $e((string) $key) ?></dt><dd class="wk-mono"><?= $e(\Reporion\Support\MetaText::text($value)) ?></dd>
<?php endforeach; ?>
</dl>
</section>
<?php endif; ?>
</div>
