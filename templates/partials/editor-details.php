<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's Details panel (roadmap phase 14, Service\FrontmatterFields):
 * native form fields for the frontmatter, collapsible, above the body-only
 * textarea. Included by templates/editor.php only when `$raw` is false.
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
    $req = $f['required'] ? ' <span class="wk-mono wk-dim" title="' . $e(t('details.required')) . '">•</span>' : '';

    if ($f['widget'] === 'checkboxes') {
        // Its own fieldset, not a <label>: several checkboxes, one name[]
        echo '<fieldset class="wk-form-grid" style="gap:5px 13px;grid-template-columns:repeat(auto-fit,minmax(min(120px,100%),1fr));border:0;padding:0;margin:0"><legend style="font-size:16px;margin-bottom:5px">' . $e($f['label']) . $req . '</legend>';
        foreach ($f['options'] as $opt) {
            $checked = \in_array($opt['value'], (array) $f['value'], true);
            echo '<label style="flex-direction:row;align-items:center;gap:6.5px;font-size:15.5px"><input type="checkbox" name="' . $e($inputName) . '[]" value="' . $e($opt['value']) . '"' . ($checked ? ' checked' : '') . '>' . $e($opt['label']) . '</label>';
        }
        echo $shownMarker . '</fieldset>';

        return;
    }

    echo '<label>' . $e($f['label']) . $req;
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
    echo $shownMarker . '</label>';
};
?>
<details class="wk-panel" id="editor-details">
<summary class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('details.panel')) ?></span></summary>
<div class="wk-form-grid">
<?php foreach ($details['fields'] as $f): $field($f); endforeach; ?>
</div>

<?php if ($details['patient'] !== null): ?>
<div class="wk-panel-h" style="margin-top:var(--space-4)"><span class="wk-eyebrow"><?= $e(t('details.patient')) ?></span></div>
<div class="wk-form-grid">
<?php foreach ($details['patient'] as $f): $field($f); endforeach; ?>
</div>
<?php endif; ?>

<div class="wk-form-grid" style="margin-top:var(--space-4)">
<label><?= $e(t('details.visibility')) ?><span class="tag tag-accent" style="width:fit-content"><?= $e($details['visibility']) ?></span><a class="wk-mono" style="font-size:14px" href="<?= $b ?>/<?= $e($path) ?>/visibility"><?= $e(t('vis.change')) ?></a></label>
<?php if ($details['accession'] !== null): ?>
<label><?= $e(t('details.accession')) ?><span class="wk-mono"><?= $e($details['accession']) ?></span><small class="wk-dim"><?= $e(t('details.accession_help')) ?></small></label>
<?php endif; ?>
</div>

<?php if ($details['extra'] !== []): ?>
<div class="wk-panel-h" style="margin-top:var(--space-4)"><span class="wk-eyebrow"><?= $e(t('details.extra')) ?></span></div>
<p class="wk-dim" style="font-size:15px;margin:0 0 var(--space-2)"><?= $e(t('details.extra_help')) ?></p>
<div class="wk-kv">
<?php foreach ($details['extra'] as $key => $value): ?>
<span><?= $e((string) $key) ?></span><b class="wk-mono"><?= $e(\Reporion\Support\MetaText::text($value)) ?></b>
<?php endforeach; ?>
</div>
<?php endif; ?>
</details>
