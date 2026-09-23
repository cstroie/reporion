<?php
declare(strict_types=1);
/**
 * templates/print/report.php — the ONE template behind /{path}/print,
 * /export/{path}.pdf and (via PhpWord) the ODT writer.
 *
 * Constraints (D34): dompdf renders this. No flexbox, no grid, no color-mix().
 * Tables and absolute mm widths only. See assets/css/print.css.
 *
 * Contract:
 *   $page   Reporion\Domain\Page      frontmatter + rendered body HTML
 *   $site   array                      conf['sites'][...] letterhead data
 *   $sig    array                      conf['signature']
 *   $opts   array                      ['pseudonymise'=>bool,'qr'=>string|null,
 *                                       'key_images'=>bool,'prior_line'=>bool]
 *
 * The body HTML comes from Render::toHtml() — the SAME call the page view uses.
 * Never re-render markdown here.
 */
$m   = $page->meta();
$pat = ($opts['pseudonymise'] ?? false) ? null : ($m['patient'] ?? null);
$e   = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="<?= $e($assetPath) ?>/css/print.css">
<title><?= $e($m['title']) ?></title>
</head>
<body>

<table class="lh">
  <tr>
    <td class="lh-left">
      <div class="lh-name"><?= $e($site['name']) ?></div>
      <div class="lh-sub"><?= $e($site['dept'] ?? 'Laborator de Radiologie si Imagistica Medicala') ?></div>
      <div class="lh-sub"><?= $e($site['address'] ?? '') ?><?= isset($site['phone']) ? ' &middot; tel ' . $e($site['phone']) : '' ?></div>
    </td>
    <td class="lh-right">
      <?= $e($m['modality_label'] ?? 'Buletin imagistic') ?><br>
      <?= $e($m['accession']) ?><br>
      <?= $e($page->studyDateFormatted('d.m.Y')) ?>
    </td>
  </tr>
</table>
<div class="lh-rule"></div>

<?php if ($page->status() === 'draft'): ?>
  <div class="draft-band">C I O R N A &nbsp;&mdash;&nbsp; DOCUMENT NESEMNAT</div>
<?php endif; ?>

<h1 class="doc-title"><?= $e($m['title']) ?></h1>

<table class="pt">
  <?php if ($pat !== null): ?>
  <tr>
    <td class="pt-k">Pacient</td>
    <td class="pt-v"><?= $e($pat['name']) ?><?= isset($pat['born']) ? ', ' . $e((string) $page->age()) . ' ani' : '' ?><?= isset($pat['sex']) ? ' (' . $e($pat['sex']) . ')' : '' ?></td>
    <td class="pt-k2">Data examinarii</td>
    <td class="pt-v"><?= $e($page->studyDateFormatted('d.m.Y H:i')) ?></td>
  </tr>
  <?php endif; ?>
  <tr>
    <td class="pt-k">Trimis de</td>
    <td class="pt-v"><?= $e($m['referrer'] ?? '&mdash;') ?></td>
    <td class="pt-k2">Aparat</td>
    <td class="pt-v"><?= $e($page->deviceLabel()) ?></td>
  </tr>
  <?php if (!empty($m['protocol'])): ?>
  <tr>
    <td class="pt-k">Protocol</td>
    <td class="pt-v"><?= $e($m['protocol']) ?></td>
    <td class="pt-k2">Regiune</td>
    <td class="pt-v"><?= $e(implode(', ', (array) ($m['region'] ?? []))) ?></td>
  </tr>
  <?php endif; ?>
</table>

<?php if (($opts['prior_line'] ?? true) && $page->priors() !== []): ?>
  <h2>Comparativ</h2>
  <p><?= $e($page->priorsSentence()) ?></p>
<?php endif; ?>

<?= $page->bodyHtml() /* Render::toHtml() output — already escaped upstream */ ?>

<?php if (($opts['key_images'] ?? false) && $page->keyImages() !== []): ?>
  <h2>Imagini</h2>
  <?php foreach ($page->keyImages() as $img): ?>
    <figure>
      <img src="<?= $e($img['abs_path']) ?>" style="width: <?= (int) $img['print_mm'] ?>mm">
      <figcaption><?= $e($img['caption'] ?? '') ?></figcaption>
    </figure>
  <?php endforeach; ?>
<?php endif; ?>

<table class="sig">
  <tr>
    <td class="sig-left">
      <?php if (!empty($sig['image'])): ?>
        <img class="sig-img" src="<?= $e($sig['image']) ?>"><br>
      <?php endif; ?>
      <strong><?= $e($sig['display_name']) ?></strong><br>
      <?= $e($sig['title']) ?><br>
      <?php if (!empty($sig['parafa'])): ?>Parafa <?= $e($sig['parafa']) ?><br><?php endif; ?>
      <?php if ($page->isSigned()): ?>
        <span class="lh-sub">semnat electronic <?= $e($page->signedAtFormatted('d.m.Y H:i')) ?></span>
      <?php endif; ?>
    </td>
    <td class="sig-right">
      rev <?= (int) $page->rev() ?><?= $page->isSigned() ? ' &middot; semnat' : ' &middot; nesemnat' ?><br>
      <span class="verify"><?= $e($page->verifyUrl()) ?></span><br>
      <?php if (!empty($opts['qr'])): ?>
        <img src="<?= $e($opts['qr']) ?>" style="width:18mm;height:18mm">
      <?php endif; ?>
    </td>
  </tr>
</table>

</body>
</html>
