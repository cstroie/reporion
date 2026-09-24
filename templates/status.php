<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Status bar (Workbench chrome, design/mockup/Wiki.dc.html's "bench"
 * variant) — included (not View::render()'d) by page-view.php, editor.php
 * and history.php, sharing whichever's already-extracted scope. A sibling
 * of .wk-body inside body.wk-shell (a flex column), not a child of it —
 * `flex: none` keeps it a fixed-height strip while .wk-body takes the rest.
 *
 * Only two of the mockup's six items are built: page count and draft
 * count in the current namespace (Index\Sqlite::namespaceStats(), same
 * visibility predicate as every other listing). The other four have no
 * real backend in this app and are deliberately NOT ported, on the user's
 * explicit direction when this was scoped:
 *   - "queue: 3 HL7 orders"     — no HL7 integration exists
 *   - "embeddings 9 214 · vec0" — vector search is off by default (D15)
 *   - "backup 04:00"            — rsync/cron (D22), nothing in-app tracks it
 *   - an "amended" count        — not even a valid `status` value (the
 *                                 column's CHECK constraint is
 *                                 draft|signed|archived)
 * A status bar stating a number that isn't true is worse than an inert
 * button that says nothing yet — see docs/BUILD_LOG.md.
 *
 * Required in scope — set by whichever controller built the vars for the
 * including template (Http\ChromeVars::worklist(), which already computes
 * the same namespace for templates/worklist.php):
 *   string $worklistNs
 *   int $statusTotal, $statusDraft
 */

declare(strict_types=1);

/** @var string $worklistNs */
/** @var int $statusTotal */
/** @var int $statusDraft */
?>
<div class="wk-status">
<span><?= htmlspecialchars(t('status.pages', [$statusTotal, $worklistNs]), ENT_QUOTES) ?></span>
<span><?= htmlspecialchars(t('status.drafts', [$statusDraft]), ENT_QUOTES) ?></span>
</div>
