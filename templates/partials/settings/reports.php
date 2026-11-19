<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: reports (POST /admin/settings/reports). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="reports">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.reports')) ?></h2></hgroup></header>
<?= $notice('reports') ?>
<form action="<?= $b ?>/admin/settings/reports" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.modality_namespaces')) ?><?= $source('reports.modality_namespaces') ?><textarea class="input wk-mono wk-text-sm" name="<?= $field('reports.modality_namespaces') ?>" rows="5"><?php foreach ($modalityMap as $modality => $ns): ?><?= $e((string) $modality) ?> = <?= $e((string) $ns) ?>&#10;<?php endforeach; ?></textarea><small><?= $e(t('admin.settings.modality_namespaces_help')) ?></small></label>
<label><?= $e(t('admin.settings.references')) ?><?= $source('references.namespaces') ?><input class="input wk-mono" type="text" name="<?= $field('references.namespaces') ?>" value="<?= $e(implode(', ', (array) ($values['references.namespaces'] ?? []))) ?>" placeholder="<?= $e(t('admin.settings.references_hint')) ?>"><small><?= $e(t('admin.settings.references_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
