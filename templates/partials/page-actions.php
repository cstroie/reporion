<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The ⋯ menu of a page a writer may change: visibility, move, rename,
 * duplicate, delete — each a link to the page's own confirmation form
 * (Controller\PageController), so the menu adds no route. The page header
 * and every row of a namespace's pages table include it.
 *
 * Variables in scope: string $actPath (the page), string $actVisibility,
 * string $basePath; optional bool $actFollowUp (New exam), list $actPlugins
 * (plugin `page_action` slots) with string $actPid, string $actClass (extra
 * class on the button)
 */

declare(strict_types=1);

use Reporion\Support\Visibility;

/** @var string $actPath */
/** @var string $actVisibility */
$ah = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$ab = $ah($basePath);
$ap = $ab . '/' . $ah($actPath);
?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= $ah(t('page.more')) ?>" aria-label="<?= $ah(t('page.more')) ?>" aria-haspopup="true"><i class="ph ph-dots-three-vertical"></i></summary>
<div class="wk-menu wk-menu-r">
<a class="wk-mi" href="<?= $ap ?>/visibility"><i class="ph <?= Visibility::icon($actVisibility) ?>"></i><?= $ah(t('page.visibility_menu')) ?><span class="wk-mi-end wk-dim"><?= $ah(Visibility::label($actVisibility)) ?></span></a>
<a class="wk-mi" href="<?= $ap ?>/move"><i class="ph ph-arrow-elbow-down-right"></i><?= $ah(t('page.move')) ?></a>
<a class="wk-mi" href="<?= $ap ?>/rename"><i class="ph ph-text-aa"></i><?= $ah(t('page.rename')) ?></a>
<?php if ($actFollowUp ?? false): ?>
<a class="wk-mi" href="<?= $ap ?>/new"><i class="ph ph-user-plus"></i><?= $ah(t('page.new_exam')) ?></a>
<?php endif; ?>
<a class="wk-mi" href="<?= $ab ?>/new?from=<?= $ah(rawurlencode($actPath)) ?>"><i class="ph ph-copy-simple"></i><?= $ah(t('page.duplicate')) ?></a>
<?php foreach ($actPlugins ?? [] as $slot): ?>
<a class="wk-mi" href="<?= $ab . $ah(str_replace('{pid}', rawurlencode($actPid ?? ''), $slot['href'])) ?>"><i class="ph ph-<?= $ah($slot['icon']) ?>"></i><?= $ah(t($slot['label'])) ?></a>
<?php endforeach; ?>
<div class="wk-mi-sep"></div>
<a class="wk-mi wk-mi-danger" href="<?= $ap ?>/delete"><i class="ph ph-trash"></i><?= $ah(t('page.delete_menu')) ?></a>
</div>
</details>
