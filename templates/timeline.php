<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/timeline (Controller\TimelineController).
 * Patient timeline — all reports for the same patient,
 * ordered by study date desc. Content only: Http\View::page() wraps it in
 * templates/layout.php, whose page header shows the page and its tabs (A6).
 *
 * Variables in scope (see Controller\TimelineController::timeline()):
 * string $path; list<array<string,mixed>> $pages; string $patientKey; ?string $patientKeyWeak
 * bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var list<array<string, mixed>> $pages */
/** @var string $patientKey */
/** @var ?string $patientKeyWeak */
/** @var bool $canWrite */
/** @var string $basePath */
?>
<div class="wk-doc">
<?php if ($patientKey === '' && $patientKeyWeak === ''): ?>
<p><?= htmlspecialchars(t('timeline.no_patient'), ENT_QUOTES) ?></p>
<?php else: ?>
<table class="table wk-hist">
<thead><tr>
<th><?= htmlspecialchars(t('timeline.col_path'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('timeline.col_site'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('timeline.col_study_date'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('timeline.col_status'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('timeline.col_visibility'), ENT_QUOTES) ?></th>
</tr></thead>
<tbody>
<?php foreach ($pages as $page): ?>
<tr>
<td><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?></a></td>
<td><?= htmlspecialchars((string) ($page['site'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars((string) ($page['study_date'] ?? ''), ENT_QUOTES) ?></td>
<td><?= htmlspecialchars((string) ($page['status'] ?? ''), ENT_QUOTES) ?></td>
<td><?= htmlspecialchars((string) ($page['visibility'] ?? ''), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
