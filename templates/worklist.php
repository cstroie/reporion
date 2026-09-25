<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Worklist sidebar (Workbench chrome, design/mockup/Wiki.dc.html's "bench"
 * variant / design/mockup/WikiWorklist.dc.html) — included (not
 * View::render()'d) by page-view.php, editor.php and history.php, sharing
 * whichever's already-extracted scope. Namespace-scoped listing
 * (`Index\Sqlite::listWorklist()`), most-recently-updated first, the
 * currently-open page highlighted.
 *
 * Deliberately NOT ported in this slice: the modality/date-range/"mine"
 * filter chips (see docs/BUILD_LOG.md) — this is the plain listing only.
 * The mockup's lock/link visibility icons aren't ported either (no
 * matching glyph in the Font Awesome subset this app loads — see
 * templates/rail.php's own docblock for the same constraint); non-public
 * visibility shows as a plain `.wk-vis` text marker instead of an icon.
 *
 * Required in scope — set by whichever controller built the vars for the
 * including template:
 *   string $basePath
 *   string $path               — the CURRENTLY OPEN page's path, not this
 *                                 sidebar's own scope (that's $worklistNs);
 *                                 used only to pick which row gets .wk-sel
 *   string $worklistNs         — the namespace this sidebar is scoped to
 *   list<array<string, mixed>> $worklistRows — Index\Sqlite::listWorklist() rows
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var string $path */
/** @var string $worklistNs */
/** @var list<array<string, mixed>> $worklistRows */
?>
<div class="wk-col wk-side">
<div class="wk-list">
<div class="wk-rail-head"><span class="wk-eyebrow"><?= htmlspecialchars($worklistNs, ENT_QUOTES) ?></span></div>
<?php foreach ($worklistRows as $row): ?>
<?php $rowPath = (string) $row['path'];
    $isCurrent = $rowPath === $path; ?>
<a class="wk-row<?= $isCurrent ? ' wk-sel' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($rowPath, ENT_QUOTES) ?>">
<div class="wk-row-t">
<?= htmlspecialchars((string) ($row['title'] ?: $rowPath), ENT_QUOTES) ?>
<?php if ((string) $row['visibility'] !== 'public'): ?><span class="wk-vis"><?= htmlspecialchars((string) $row['visibility'], ENT_QUOTES) ?></span><?php endif; ?>
</div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars((new DateTimeImmutable((string) $row['updated']))->format('d M'), ENT_QUOTES) ?> · rev <?= (int) $row['rev'] ?> · <?= htmlspecialchars((string) ($row['updated_by'] ?? '-'), ENT_QUOTES) ?></div>
<?php if (($row['summary'] ?? '') !== ''): ?>
<div class="wk-row-s"><?= htmlspecialchars((string) $row['summary'], ENT_QUOTES) ?></div>
<?php endif; ?>
</a>
<?php endforeach; ?>
</div>
</div>
