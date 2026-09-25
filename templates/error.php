<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Not found, for a signed-in caller (Http\ErrorMapper) — content only, in
 * the app shell. Card from design/mockup/WikiErrors.dc.html (.wk-errc).
 * The path shown is the one the caller typed; a private page they cannot
 * read looks exactly like one that does not exist (invariant 9).
 *
 * Variables in scope: int $status; ?string $path; bool $canCreate; string $basePath
 */

declare(strict_types=1);

/** @var ?string $path */
/** @var bool $canCreate */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
?>
<div class="wk-err">
<div class="wk-errc">
<b>404</b>
<h1 class="wk-sec-title"><?= htmlspecialchars(t('err.404.title'), ENT_QUOTES) ?></h1>
<?php if ($path !== null): ?>
<p class="wk-mono wk-dim" style="font-size:11.5px;margin:0"><?= htmlspecialchars($path, ENT_QUOTES) ?></p>
<?php endif; ?>
<p style="font-size:13px;margin:0"><?= htmlspecialchars(t('err.404.body'), ENT_QUOTES) ?></p>
<div class="wk-actions">
<?php if ($canCreate): ?>
<a class="btn btn-primary btn-sm" href="<?= $b ?>/new?path=<?= htmlspecialchars(rawurlencode((string) $path), ENT_QUOTES) ?>"><i class="ph ph-plus"></i><?= htmlspecialchars(t('err.404.create'), ENT_QUOTES) ?></a>
<?php endif; ?>
<?php if ($path !== null): ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/search?q=<?= htmlspecialchars(rawurlencode(str_replace([':', '-'], ' ', (string) $path)), ENT_QUOTES) ?>"><i class="ph ph-magnifying-glass"></i><?= htmlspecialchars(t('err.404.search'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="btn btn-ghost btn-sm" href="<?= $b ?>/"><?= htmlspecialchars(t('err.home'), ENT_QUOTES) ?></a>
</div>
</div>
</div>
