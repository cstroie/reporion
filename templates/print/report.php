<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * templates/print/report.php — the ONE template behind /{path}/print and
 * /export/{path}.pdf (Service\PrintView builds its variables).
 *
 * Constraints (D34): dompdf renders this. No flexbox, no grid, no
 * color-mix(), no var(); tables and absolute mm/pt widths only. The
 * stylesheet is assets/css/print.css, inlined so the browser preview and
 * the PDF get byte-identical markup and dompdf never fetches anything.
 *
 * The body HTML is Render::toHtml() output — the same call the page view
 * uses; never re-render markdown here. Labels come from lang/en.php
 * (`print.*`, Romanian: the printed report is content, D26).
 *
 * Variables in scope: string $css, $title, $accession, $studyDate,
 * $studyDateTime, $referrer, $indication, $device, $protocol, $region, $bodyHtml,
 * $verifyUrl; array $site; ?array $patient; bool $isDraft; int $rev;
 * ?array $signer; optional string $printAction (the preview's print button)
 */

declare(strict_types=1);

/** @var string $css */
/** @var string $title */
/** @var array{name: string, dept: string, address: string, phone: string} $site */
/** @var ?array{name: string, age: ?int, sex: string} $patient */
/** @var ?array{name: string, title: string, parafa: string, at: string} $signer */
/** @var bool $isDraft */
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

<table class="lh">
  <tr>
    <td class="lh-left">
      <div class="lh-name"><?= $e($site['name']) ?></div>
      <?php if ($site['dept'] !== ''): ?><div class="lh-sub"><?= $e($site['dept']) ?></div><?php endif; ?>
      <?php if ($site['address'] !== '' || $site['phone'] !== ''): ?>
      <div class="lh-sub"><?= $e($site['address']) ?><?= $site['phone'] !== '' ? ' &middot; ' . $e(t('print.phone', [$site['phone']])) : '' ?></div>
      <?php endif; ?>
    </td>
    <td class="lh-right">
      <?= $e(t('print.kind')) ?><br>
      <?= $accession !== '' ? $e($accession) . '<br>' : '' ?>
      <?= $e($studyDate) ?>
    </td>
  </tr>
</table>
<div class="lh-rule"></div>

<?php if ($isDraft): ?>
  <div class="draft-band"><?= $e(t('print.draft_band')) ?></div>
<?php endif; ?>

<h1 class="doc-title"><?= $e($title) ?></h1>

<table class="pt">
  <?php if ($patient !== null): ?>
  <tr>
    <td class="pt-k"><?= $e(t('print.patient')) ?></td>
    <td class="pt-v"><?= $e($patient['name']) ?><?= $patient['age'] !== null ? ', ' . $e(t('print.age', [$patient['age']])) : '' ?><?= $patient['sex'] !== '' ? ' (' . $e($patient['sex']) . ')' : '' ?></td>
    <td class="pt-k2"><?= $e(t('print.study_date')) ?></td>
    <td class="pt-v"><?= $e($studyDateTime) ?></td>
  </tr>
  <?php endif; ?>
  <tr>
    <td class="pt-k"><?= $e(t('print.referrer')) ?></td>
    <td class="pt-v"><?= $referrer !== '' ? $e($referrer) : '&mdash;' ?></td>
    <td class="pt-k2"><?= $e(t('print.device')) ?></td>
    <td class="pt-v"><?= $device !== '' ? $e($device) : '&mdash;' ?></td>
  </tr>
  <?php if ($indication !== ''): ?>
  <tr>
    <td class="pt-k"><?= $e(t('print.indication')) ?></td>
    <td class="pt-v" colspan="3"><?= $e($indication) ?></td>
  </tr>
  <?php endif; ?>
  <?php if ($protocol !== '' || $region !== ''): ?>
  <tr>
    <td class="pt-k"><?= $e(t('print.protocol')) ?></td>
    <td class="pt-v"><?= $protocol !== '' ? $e($protocol) : '&mdash;' ?></td>
    <td class="pt-k2"><?= $e(t('print.region')) ?></td>
    <td class="pt-v"><?= $region !== '' ? $e($region) : '&mdash;' ?></td>
  </tr>
  <?php endif; ?>
</table>

<div class="body">
<?= $bodyHtml /* Render::toHtml() output — raw HTML already escaped there */ ?>
</div>

<table class="sig">
  <tr>
    <td class="sig-left">
      <?php if ($signer !== null): ?>
      <strong><?= $e($signer['name']) ?></strong><br>
      <?php if ($signer['title'] !== ''): ?><?= $e($signer['title']) ?><br><?php endif; ?>
      <?php if ($signer['parafa'] !== ''): ?><?= $e(t('print.parafa', [$signer['parafa']])) ?><br><?php endif; ?>
      <span class="lh-sub"><?= $e(t('print.signed_at', [$signer['at']])) ?></span>
      <?php else: ?>
      <span class="lh-sub"><?= $e(t('print.unsigned')) ?></span>
      <?php endif; ?>
    </td>
    <td class="sig-right">
      <?= $e(t($signer !== null ? 'print.rev_signed' : 'print.rev_unsigned', [$rev])) ?><br>
      <span class="verify"><?= $e($verifyUrl) ?></span>
    </td>
  </tr>
</table>

</body>
</html>
