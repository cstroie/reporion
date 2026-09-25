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
 * Icons are Phosphor (assets/css/phosphor.css), the same glyphs as the
 * mockup's rail.
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
 *   string  $railNsHref   — namespace-index-icon target (Http\ChromeVars::
 *                           forPath()), '/:' (root) when the current path
 *                           has no namespace of its own — unlike
 *                           railEditHref this isn't a permission gate:
 *                           visibility is enforced by the route itself
 *   string $theme         — 'dark' or 'light' (Http\ChromeVars::theme()) —
 *                           the toggle posts the OPPOSITE value
 *   string $currentUrl    — POST /theme's return_to, so toggling doesn't
 *                           navigate away from wherever this was clicked
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var bool $isOwner */
/** @var bool $canCreate */
/** @var string $railActive */
/** @var ?string $railEditHref */
/** @var string $railNsHref */
/** @var string $theme */
/** @var string $currentUrl */
?>
<nav class="wk-irail">
<a class="wk-ib" data-on="<?= $railActive === 'ns' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath . $railNsHref, ENT_QUOTES) ?>" title="Namespace index"><i class="ph ph-list-checks"></i></a>
<a class="wk-ib" data-on="<?= $railActive === 'search' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" title="Search"><i class="ph ph-magnifying-glass"></i></a>
<?php if ($railEditHref !== null): ?>
<a class="wk-ib" data-on="<?= $railActive === 'edit' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath . $railEditHref, ENT_QUOTES) ?>" title="Editor"><i class="ph ph-pencil-simple"></i></a>
<?php else: ?>
<a class="wk-ib wk-ib-inert" title="Editor (open a page you can write to first)"><i class="ph ph-pencil-simple"></i></a>
<?php endif; ?>
<?php if ($canCreate): ?>
<a class="wk-ib" data-on="<?= $railActive === 'new' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" title="New report"><i class="ph ph-plus"></i></a>
<?php endif; ?>
<a class="wk-ib wk-ib-inert" title="Tags (not built yet)"><i class="ph ph-tag"></i></a>
<a class="wk-ib wk-ib-inert" title="Integrations (not built yet)"><i class="ph ph-plugs-connected"></i></a>
<?php if ($isOwner): ?>
<a class="wk-ib" data-on="<?= $railActive === 'admin' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users" title="Admin"><i class="ph ph-sliders-horizontal"></i></a>
<?php endif; ?>
<span class="wk-tflex"></span>
<a class="wk-ib wk-ib-inert" title="Account (not built yet)"><i class="ph ph-user-circle"></i></a>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/theme" method="post">
<input type="hidden" name="theme" value="<?= $theme === 'dark' ? 'light' : 'dark' ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="wk-ib" title="<?= htmlspecialchars(t('nav.theme'), ENT_QUOTES) ?>"><i class="ph ph-circle-half"></i></button>
</form>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/logout" method="post">
<button type="submit" class="wk-ib" title="Sign out"><i class="ph ph-sign-out"></i></button>
</form>
</nav>
