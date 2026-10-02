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

/** One field's control, its label and its `fm_shown[]` marker */
$field = static function (array $f) use ($e, $name): void {
    $inputName = $name($f['key']);
    $shownMarker = '<input type="hidden" name="fm_shown[]" value="' . $e($f['key']) . '">';
    $req = $f['required'] ? '<span class="wk-required-mark" title="' . $e(t('details.required')) . '">*</span>' : '';

    if ($f['widget'] === 'checkboxes') {
        // Its own fieldset, not a <label>: several checkboxes, one name[]
        echo '<fieldset class="wk-form-grid" style="gap:var(--space-2) 13px;grid-template-columns:repeat(auto-fit,minmax(min(120px,100%),1fr));border:0;padding:0;margin:0"><legend style="font-size:var(--text-sm);margin-bottom:var(--space-2)"><span class="wk-field-label">' . $e($f['label']) . $req . '</span></legend>';
        foreach ($f['options'] as $opt) {
            $checked = \in_array($opt['value'], (array) $f['value'], true);
            // flex-direction:row inline: .wk-form-grid label (this fieldset's own class) forces
            // column, more specific than plain .radio — same fix as new-report.php's region checkboxes
            echo '<label class="radio" style="flex-direction:row;font-size:var(--text-sm)"><input type="checkbox" name="' . $e($inputName) . '[]" value="' . $e($opt['value']) . '"' . ($checked ? ' checked' : '') . '><span class="dot"></span>' . $e($opt['label']) . '</label>';
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
            echo '<select class="input" name="' . $e($inputName) . '"><option value="">—</option>';
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
<div class="wk-panel wk-edit-meta" id="editor-details">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('details.panel')) ?></h2></header>
<div class="wk-form-grid">
<?php foreach ($details['fields'] as $f): $field($f); endforeach; ?>
</div>

<?php if ($details['patient'] !== null): ?>
<header class="wk-panel-h wk-mt-4"><h2 class="wk-eyebrow"><?= $e(t('details.patient')) ?></h2></header>
<div class="wk-form-grid">
<?php foreach ($details['patient'] as $f): $field($f); endforeach; ?>
</div>
<?php endif; ?>

<div class="wk-form-grid wk-mt-4">
<label><?= $e(t('details.visibility')) ?><span class="tag tag-accent" style="width:fit-content"><?= $e($details['visibility']) ?></span><a class="wk-mono wk-text-sm" href="<?= $b ?>/<?= $e($path) ?>/visibility"><?= $e(t('vis.change')) ?></a></label>
<?php if ($details['accession'] !== null): ?>
<label><?= $e(t('details.accession')) ?><span class="wk-mono"><?= $e($details['accession']) ?></span><small class="wk-dim"><?= $e(t('details.accession_help')) ?></small></label>
<?php endif; ?>
</div>

<?php if ($details['extra'] !== []): ?>
<header class="wk-panel-h wk-mt-4"><hgroup><h2 class="wk-eyebrow"><?= $e(t('details.extra')) ?></h2><p class="wk-dim"><?= $e(t('details.extra_help')) ?></p></hgroup></header>
<div class="wk-kv">
<?php foreach ($details['extra'] as $key => $value): ?>
<span><?= $e((string) $key) ?></span><b class="wk-mono"><?= $e(\Reporion\Support\MetaText::text($value)) ?></b>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
