<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * templates/print/page.php — /{path}/print and /export/{path}.{pdf,odt} for a
 * page that is not a report (Support\ReportPath: protocols, guides, a site's
 * description): the site name, the title, the text, and a footer with the
 * revision, its date and the verification link. No letterhead, no patient
 * block, no signature — those belong to reports (templates/print/report.php).
 *
 * The same constraints as report.php (D34): dompdf renders this — tables and
 * block layout only, print.css inlined, nothing fetched.
 *
 * Variables in scope (Service\PrintView::pageVars()): string $css, $title,
 * $siteName, $updated, $bodyHtml, $verifyUrl; int $rev; ?string $printAction
 */

declare(strict_types=1);

/** @var string $css */
/** @var string $title */
/** @var string $siteName */
/** @var string $updated */
/** @var string $bodyHtml */
/** @var string $verifyUrl */
/** @var int $rev */

$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title><?= $e($title) ?></title>
<style>
<?= $css ?>
</style>
</head>
<body>
<?php if (isset($printAction)): ?>
<?= $printAction ?>
<?php endif; ?>

<div class="lh-name"><?= $e($siteName) ?></div>
<div class="lh-rule"></div>

<h1 class="doc-title"><?= $e($title) ?></h1>

<div class="body">
<?= $bodyHtml /* Render::toHtml() output — raw HTML already escaped there */ ?>
</div>

<table class="sig">
  <tr>
    <td class="sig-left"><span class="lh-sub"><?= $e(t('print.page_rev', [$rev, $updated])) ?></span></td>
    <td class="sig-right"><span class="verify"><?= $e($verifyUrl) ?></span></td>
  </tr>
</table>

</body>
</html>
