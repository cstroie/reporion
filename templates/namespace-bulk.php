<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * POST /{ns}: (Controller\NamespaceController::bulk()) — the confirm step
 * of the namespace index's bulk Move or Tag: the selection, the target
 * (a namespace or a tag), and a submit that posts back with step=apply.
 * Same plain-words explanation as the single-page Move form
 * (templates/page-move.php). Content only, in the app shell.
 *
 * Variables in scope: string $ns, $action ('move'|'tag'), $year, $value,
 * $basePath; list<array<string, mixed>> $selected (index rows); ?string $error
 */

declare(strict_types=1);

/** @var string $ns */
/** @var string $action */
/** @var list<array<string, mixed>> $selected */
/** @var string $year */
/** @var ?string $error */
/** @var string $value */
/** @var string $basePath */

$nsUrl = $basePath . '/' . $ns . ':';
$back = $nsUrl . ($year !== '' ? '?year=' . urlencode($year) : '');
$isMove = $action === 'move';
?>
<div class="wk-doc" style="max-width:720px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t($isMove ? 'ns.bulk_move_title' : 'ns.bulk_tag_title', [\count($selected)]), ENT_QUOTES) ?></h2>
<p class="wk-text-sm"><?= htmlspecialchars(t($isMove ? 'ns.bulk_move_help' : 'ns.bulk_tag_help'), ENT_QUOTES) ?></p>
<?php if ($error !== null): ?>
<div class="wk-notice wk-notice-warn" role="alert"><i class="ph ph-warning"></i><div><?= htmlspecialchars($error, ENT_QUOTES) ?></div></div>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($nsUrl, ENT_QUOTES) ?>" method="post">
<input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
<input type="hidden" name="step" value="apply">
<input type="hidden" name="year" value="<?= htmlspecialchars($year, ENT_QUOTES) ?>">
<?php foreach ($selected as $row): ?>
<input type="hidden" name="paths[]" value="<?= htmlspecialchars((string) $row['path'], ENT_QUOTES) ?>">
<?php endforeach; ?>
<?php if ($isMove): ?>
<div class="field"><label for="to"><?= htmlspecialchars(t('ns.bulk_move_to'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="to" name="to" value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" autocomplete="off" required autofocus></div>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= htmlspecialchars($back, ENT_QUOTES) ?>"><?= htmlspecialchars(t('ns.bulk_cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-primary" type="submit"><i class="ph ph-arrow-elbow-down-right"></i><?= htmlspecialchars(t('ns.bulk_move_submit'), ENT_QUOTES) ?></button>
</div>
<?php else: ?>
<div class="field"><label for="tag"><?= htmlspecialchars(t('ns.bulk_tag_label'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="tag" name="tag" value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" autocomplete="off" required autofocus></div>
<?php /* Enter in the tag field submits through the form's first submit button: an invisible Add, since the visible one comes last */ ?>
<button type="submit" name="op" value="add" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></button>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= htmlspecialchars($back, ENT_QUOTES) ?>"><?= htmlspecialchars(t('ns.bulk_cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-secondary" type="submit" name="op" value="remove"><?= htmlspecialchars(t('ns.bulk_tag_remove'), ENT_QUOTES) ?></button>
<button class="btn btn-primary" type="submit" name="op" value="add"><i class="ph ph-tag"></i><?= htmlspecialchars(t('ns.bulk_tag_add'), ENT_QUOTES) ?></button>
</div>
<?php endif; ?>
</form>
<div class="wk-panel" style="margin-top:var(--space-6)">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('ns.bulk_selection'), ENT_QUOTES) ?></h2><span class="wk-count"><?= \count($selected) ?></span></header>
<table class="table">
<thead><tr><th><?= htmlspecialchars(t('ns.col_title'), ENT_QUOTES) ?></th><th><?= htmlspecialchars(t('ns.col_page'), ENT_QUOTES) ?></th><th><?= htmlspecialchars(t('ns.col_status'), ENT_QUOTES) ?></th></tr></thead>
<tbody>
<?php foreach ($selected as $row): ?>
<?php $segments = explode(':', (string) $row['path']); ?>
<tr>
<td><?= htmlspecialchars(trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : (string) end($segments), ENT_QUOTES) ?></td>
<td class="wk-mono wk-dim"><?= htmlspecialchars((string) end($segments), ENT_QUOTES) ?></td>
<td><span class="tag <?= \Reporion\Support\Badges::statusTag((string) $row['status']) ?>"><?= htmlspecialchars((string) $row['status'], ENT_QUOTES) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
