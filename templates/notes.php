<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /notes: the notes app's shell (D55). The same HTML for every caller:
 * no account, no page and no note in it, so the service worker may keep
 * it for offline use. assets/js/notes.js fills it from /api/v1 and from
 * the browser's own copy (IndexedDB).
 *
 * An app, not a page (2026-10-24, owner's request): its own look
 * (assets/css/notes.css: Reporion's colour tokens and fonts, none of its
 * page components), three screens in one frame: sign-in, the list (a grid
 * of cards, a search pill, a + button) and the editor (borderless title
 * and text). On a phone one screen fills the window; on a wide window the
 * app is a framed window with the list and the open note side by side.
 * `data-screen` on the frame says which screen is in front.
 *
 * Variables in scope: string $basePath, array $config (the island's)
 */

declare(strict_types=1);

use Reporion\Support\Asset;

/** @var string $basePath */
/** @var array<string, mixed> $config */

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
$icon = $e(Asset::url($basePath, 'notes/icon.svg'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="color-scheme" content="dark light">
<title><?= $e(t('notes.title')) ?></title>
<link rel="manifest" href="<?= $e($basePath) ?>/notes/manifest.webmanifest">
<meta name="theme-color" content="#0d1017">
<link rel="icon" href="<?= $icon ?>" type="image/svg+xml" sizes="any">
<link rel="icon" href="<?= $e(Asset::url($basePath, 'notes/icon-192.png')) ?>" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="<?= $e(Asset::url($basePath, 'notes/apple-touch-icon.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= $e(t('notes.title')) ?>">
<?php foreach (\Reporion\Controller\NotesController::STYLES as $css): ?>
<link rel="stylesheet" href="<?= $e(Asset::url($basePath, $css)) ?>">
<?php endforeach; ?>
</head>
<body class="nt">
<div class="nt-app" data-island="notes" data-screen="boot">

<section class="nt-signin" data-view="signin">
<form class="nt-signin-card" data-signin>
<img class="nt-signin-icon" src="<?= $icon ?>" alt="" width="72" height="72">
<h1><?= $e(t('notes.title')) ?></h1>
<p><?= $e(t('notes.signin_lead')) ?></p>
<label class="nt-field"><span><?= $e(t('auth.username')) ?></span>
<input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
<label class="nt-field"><span><?= $e(t('auth.password')) ?></span>
<input type="password" name="password" autocomplete="current-password" required></label>
<button class="nt-btn nt-btn-primary nt-btn-block" type="submit"><?= $e(t('auth.submit')) ?></button>
</form>
</section>

<section class="nt-list" data-view="list">
<header class="nt-bar nt-bar-large">
<h1 class="nt-bar-title"><img src="<?= $icon ?>" alt="" width="28" height="28"><?= $e(t('notes.title')) ?></h1>
<span class="nt-bar-actions">
<button class="nt-icon-btn" type="button" data-sync title="<?= $e(t('notes.sync_now')) ?>" aria-label="<?= $e(t('notes.sync_now')) ?>"><i class="ph ph-cloud-check" data-status-icon aria-hidden="true"></i></button>
<button class="nt-icon-btn" type="button" data-menu-button aria-haspopup="menu" aria-expanded="false" title="<?= $e(t('notes.menu')) ?>" aria-label="<?= $e(t('notes.menu')) ?>"><i class="ph ph-dots-three-vertical" aria-hidden="true"></i></button>
</span>
</header>
<div class="nt-search">
<i class="ph ph-magnifying-glass" aria-hidden="true"></i>
<input type="search" data-search placeholder="<?= $e(t('notes.search')) ?>" aria-label="<?= $e(t('notes.search')) ?>" autocomplete="off">
</div>
<div class="nt-filter" data-filter hidden role="group" aria-label="<?= $e(t('notes.tags')) ?>"></div>
<div class="nt-scroll" data-list-scroll>
<div class="nt-banners" data-banners></div>
<ul class="nt-grid" data-items></ul>
<div class="nt-empty" data-empty hidden>
<i class="ph ph-note-pencil" aria-hidden="true"></i>
<p><?= $e(t('notes.empty')) ?></p>
<small><?= $e(t('notes.empty_help')) ?></small>
</div>
</div>
<button class="nt-fab" type="button" data-new title="<?= $e(t('notes.new')) ?>"><i class="ph ph-plus" aria-hidden="true"></i><span><?= $e(t('notes.new')) ?></span></button>
</section>

<section class="nt-editor" data-view="editor">
<div class="nt-editor-none" data-editor-none>
<i class="ph ph-note-pencil" aria-hidden="true"></i>
<p><?= $e(t('notes.pick')) ?></p>
</div>
<div class="nt-editor-note" data-editor-note data-mode="edit" hidden>
<header class="nt-bar">
<a class="nt-icon-btn nt-back" href="#/" data-nav-list title="<?= $e(t('notes.back')) ?>" aria-label="<?= $e(t('notes.back')) ?>"><i class="ph ph-caret-left" aria-hidden="true"></i></a>
<span class="nt-bar-status" data-note-status aria-live="polite"></span>
<span class="nt-bar-actions">
<button class="nt-icon-btn" type="button" data-preview aria-pressed="false" title="<?= $e(t('notes.preview')) ?>" aria-label="<?= $e(t('notes.preview')) ?>"><i class="ph ph-eye" aria-hidden="true"></i></button>
<button class="nt-icon-btn" type="button" data-delete title="<?= $e(t('notes.delete')) ?>" aria-label="<?= $e(t('notes.delete')) ?>"><i class="ph ph-trash" aria-hidden="true"></i></button>
</span>
</header>
<div class="nt-scroll nt-sheet">
<div class="nt-conflict" data-conflict hidden></div>
<?php /* The paper: tinted while editing, gone in the preview (data-mode on .nt-editor-note) */ ?>
<div class="nt-paper">
<input class="nt-title" type="text" data-title maxlength="200" autocomplete="off" placeholder="<?= $e(t('notes.title_hint')) ?>" aria-label="<?= $e(t('notes.field_title')) ?>">
<div class="nt-tags" data-tags>
<input class="nt-tag-input" type="text" data-tag-input list="nt-tag-list" maxlength="40" autocomplete="off" autocapitalize="none" enterkeyhint="done" placeholder="<?= $e(t('notes.tag_hint')) ?>" aria-label="<?= $e(t('notes.tag_add')) ?>">
</div>
<datalist id="nt-tag-list" data-tag-list></datalist>
<textarea class="nt-text" data-text spellcheck="true" placeholder="<?= $e(t('notes.text_hint')) ?>" aria-label="<?= $e(t('notes.field_text')) ?>"></textarea>
<div class="nt-prose" data-preview-pane hidden></div>
</div>
</div>
</div>
</section>

<div class="nt-menu" data-menu role="menu" hidden>
<div class="nt-menu-head">
<span class="nt-menu-user" data-menu-user></span>
<span class="nt-menu-status" data-status></span>
</div>
<button class="nt-menu-item" type="button" role="menuitem" data-sync-now><i class="ph ph-arrows-clockwise" aria-hidden="true"></i><?= $e(t('notes.sync_now')) ?></button>
<button class="nt-menu-item" type="button" role="menuitem" data-install hidden><i class="ph ph-download-simple" aria-hidden="true"></i><?= $e(t('notes.install')) ?></button>
<div class="nt-menu-theme">
<span><?= $e(t('notes.theme')) ?></span>
<div class="nt-seg" role="group" aria-label="<?= $e(t('notes.theme')) ?>">
<button type="button" data-theme-set="system" title="<?= $e(t('notes.theme_system')) ?>" aria-label="<?= $e(t('notes.theme_system')) ?>"><i class="ph ph-circle-half" aria-hidden="true"></i></button>
<button type="button" data-theme-set="light" title="<?= $e(t('notes.theme_light')) ?>" aria-label="<?= $e(t('notes.theme_light')) ?>"><i class="ph ph-sun" aria-hidden="true"></i></button>
<button type="button" data-theme-set="dark" title="<?= $e(t('notes.theme_dark')) ?>" aria-label="<?= $e(t('notes.theme_dark')) ?>"><i class="ph ph-moon" aria-hidden="true"></i></button>
</div>
</div>
<a class="nt-menu-item" role="menuitem" href="<?= $e($basePath) ?>/"><i class="ph ph-arrow-square-out" aria-hidden="true"></i><?= $e(t('notes.open_reporion')) ?></a>
<button class="nt-menu-item nt-menu-danger" type="button" role="menuitem" data-sign-out><i class="ph ph-sign-out" aria-hidden="true"></i><?= $e(t('notes.sign_out')) ?></button>
<p class="nt-menu-foot" data-version><?= $e(t('notes.version', [(string) $config['version'], (string) $config['build']])) ?></p>
</div>

<div class="nt-toasts" data-toasts aria-live="polite"></div>
<script type="application/json"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</div>
<script src="<?= $e(Asset::url($basePath, 'js/notes.js')) ?>" defer></script>
</body>
</html>
