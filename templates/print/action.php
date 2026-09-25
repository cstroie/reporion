<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The print preview's own controls (GET /{path}/print only — never in the
 * PDF): back to the report, and a Print button. Hidden on paper by
 * print.css (.print-action). Without JavaScript the browser's own print
 * command does the same thing.
 *
 * Variables in scope: string $basePath, $path
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var string $path */
?>
<div class="print-action">
<a href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('print.back'), ENT_QUOTES) ?></a>
&nbsp;·&nbsp;
<a href="#" onclick="window.print(); return false;"><?= htmlspecialchars(t('print.button'), ENT_QUOTES) ?></a>
</div>
