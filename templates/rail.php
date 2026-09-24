<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Icon nav rail (Workbench chrome, design/mockup/Wiki.dc.html's "bench"
 * variant) — included (not View::render()'d) by every signed-in template,
 * sharing its caller's already-extracted scope. Every item is a real link
 * to a real route (no client-side tab state — CLAUDE.md: "no SPA router";
 * the confirmation-page precedent for delete applies here too: a route
 * that changes nothing on GET is safe to link to plainly).
 *
 * Icons are Font Awesome (assets/css/fontawesome.css), substituted for the
 * mockup's Phosphor classes (not loaded in this app). The pruned Font
 * Awesome subset already in this repo has no plus/tag/plug/sliders glyph,
 * so four items use a different icon than the mockup:
 *   new report:    fa-plus        -> fa-file-medical
 *   tags:          fa-tag         -> fa-sticky-note
 *   integrations:  fa-plugs-...   -> fa-sync-alt
 *   admin:         fa-sliders-... -> fa-hospital
 *
 * Tags, Integrations and Account have no backend at all — rendered
 * .wk-ib-inert (visible, not clickable), same "show it, don't fake it"
 * rule as the page-actions kebab menu. Admin is omitted entirely (not
 * inert) for a non-owner, matching how the old top-bar Admin link worked —
 * a permission gate hides, an unbuilt feature stays visible-but-inert;
 * those are different situations and look different on purpose.
 *
 * Required in scope — set by whichever controller/renderer built the vars
 * for the including template (Http\PageTemplateRenderer for page-view.php),
 * the same place every other var these templates use comes from:
 *   string $basePath
 *   bool   $isOwner
 *   bool   $canCreate     — same question PageTemplateRenderer::render()
 *                           already answers for the old top-bar New link:
 *                           hasAnyWriteAccess(), not canWrite() of any one
 *                           page. Gates the New-report icon the same way —
 *                           hidden, not inert, for a caller with no write
 *                           access anywhere (an inert icon would imply the
 *                           feature doesn't exist yet; it does, they just
 *                           can't use it, same distinction Admin makes).
 *   string $railActive   — one of: view, edit, hist, ns, search, new, admin
 *   ?string $railEditHref — edit-icon target, or null when there is no
 *                           single current page to edit (e.g. namespace/
 *                           search screens) OR the caller cannot write to
 *                           the one that is open — same gate as the old
 *                           Edit button, never just "a page exists"
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var bool $isOwner */
/** @var bool $canCreate */
/** @var string $railActive */
/** @var ?string $railEditHref */
?>
<nav class="wk-irail">
<a class="wk-ib wk-ib-inert" title="Namespace index (needs a namespace to jump to)"><i class="fas fa-list-check"></i></a>
<a class="wk-ib" data-on="<?= $railActive === 'search' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" title="Search"><i class="fas fa-magnifying-glass"></i></a>
<?php if ($railEditHref !== null): ?>
<a class="wk-ib" data-on="<?= $railActive === 'edit' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath . $railEditHref, ENT_QUOTES) ?>" title="Editor"><i class="fas fa-pen"></i></a>
<?php else: ?>
<a class="wk-ib wk-ib-inert" title="Editor (open a page you can write to first)"><i class="fas fa-pen"></i></a>
<?php endif; ?>
<?php if ($canCreate): ?>
<a class="wk-ib" data-on="<?= $railActive === 'new' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" title="New report"><i class="fas fa-file-medical"></i></a>
<?php endif; ?>
<a class="wk-ib wk-ib-inert" title="Tags (not built yet)"><i class="fas fa-sticky-note"></i></a>
<a class="wk-ib wk-ib-inert" title="Integrations (not built yet)"><i class="fas fa-sync-alt"></i></a>
<?php if ($isOwner): ?>
<a class="wk-ib" data-on="<?= $railActive === 'admin' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users" title="Admin"><i class="fas fa-hospital"></i></a>
<?php endif; ?>
<span class="wk-tflex"></span>
<a class="wk-ib wk-ib-inert" title="Account (not built yet)"><i class="fas fa-circle-user"></i></a>
<a class="wk-ib wk-ib-inert" title="Theme (not wired up yet)"><i class="fas fa-circle-half-stroke"></i></a>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/logout" method="post">
<button type="submit" class="wk-ib" title="Sign out"><i class="fas fa-sign-out-alt"></i></button>
</form>
</nav>
