<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A library record's form (docs/library.md, phase 2): the proposed record of an upload, corrected
 * before it is added (templates/library.php), or a source's record corrected afterwards
 * (partials/library-source.php). Inside a <template>, cloned and filled by assets/js/library.js,
 * so it has no ids; every input sits in its label (docs/design-rules.md, Forms).
 *
 * Variables in scope: string $libraryFormMode ('add' | 'edit')
 */

declare(strict_types=1);

/** @var string $libraryFormMode */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$adding = $libraryFormMode === 'add';
$field = static function (string $name, string $key, string $type = 'text', bool $help = false, string $extra = '', string $class = '') use ($e): string {
    return '<div class="field' . ($class !== '' ? ' ' . $class : '') . '"><label>' . $e(t('library.f_' . $key))
        . '<input class="input" type="' . $type . '" name="' . $name . '" placeholder="' . $e(t('library.f_' . $key . '_hint')) . '"' . $extra . '>'
        . ($help ? '<small>' . $e(t('library.f_' . $key . '_help')) . '</small>' : '') . '</label></div>';
};
?>
<form class="wk-lib-form" data-lib-form novalidate>
<div data-lib-dup></div>
<?= $field('title', 'title', 'text', false, ' required maxlength="500"') ?>
<div class="field"><label><?= $e(t('library.f_authors')) ?>
<textarea class="input" name="authors" rows="3" placeholder="<?= $e(t('library.f_authors_hint')) ?>"></textarea>
<small><?= $e(t('library.f_authors_help')) ?></small></label></div>
<div class="wk-lib-grid">
<div class="field wk-lib-wide"><label><?= $e(t('library.f_type')) ?>
<select class="input" name="type">
<?php foreach (['article-journal', 'book', 'chapter', 'paper-conference', 'report', 'thesis', 'webpage', 'manuscript', 'standard', 'dataset'] as $type): ?>
<option value="<?= $e($type) ?>"><?= $e(t('library.type_' . $type)) ?></option>
<?php endforeach; ?>
</select></label></div>
<?= $field('year', 'year', 'text', false, ' inputmode="numeric" maxlength="4" pattern="[0-9]{4}"') ?>
<?= $field('container-title', 'journal', 'text', false, '', 'wk-lib-wide') ?>
<?= $field('volume', 'volume') ?>
<?= $field('issue', 'issue') ?>
<?= $field('page', 'pages') ?>
<?= $field('DOI', 'doi', 'text', false, '', 'wk-lib-wide') ?>
<?= $field('PMID', 'pmid', 'text', false, ' inputmode="numeric"') ?>
</div>
<div class="wk-lib-pair">
<?php if ($adding): ?>
<?= $field('key', 'key', 'text', true, ' maxlength="63" pattern="[a-z][a-z0-9\-]{1,62}" autocapitalize="off" spellcheck="false"') ?>
<?php endif; ?>
<?= $field('tags', 'tags', 'text', true) ?>
</div>
<?php if ($adding): ?>
<details class="wk-lib-preview" data-lib-preview hidden><summary><?= $e(t('library.preview')) ?></summary><p class="wk-dim wk-text-sm" data-lib-preview-text></p></details>
<?php endif; ?>
<div data-lib-status aria-live="polite"></div>
<footer class="wk-actions">
<button class="btn btn-ghost" type="button" data-lib-cancel><i class="ph ph-x" aria-hidden="true"></i><?= $e(t($adding ? 'library.discard' : 'library.cancel')) ?></button>
<button class="btn btn-primary" type="submit" data-lib-submit><i class="ph <?= $adding ? 'ph-books' : 'ph-floppy-disk' ?>" aria-hidden="true"></i><?= $e(t($adding ? 'library.add_one' : 'library.save_record')) ?></button>
</footer>
</form>
