<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /notes: the notes app's shell (D55). The same HTML for every caller:
 * no account, no page and no note in it, so the service worker may keep
 * it for offline use. assets/js/notes.js fills it from /api/v1 and from
 * the browser's own copy (IndexedDB).
 *
 * Variables in scope: string $basePath, array $config (the island's)
 */

declare(strict_types=1);

use Reporion\Support\Asset;

/** @var string $basePath */
/** @var array<string, mixed> $config */

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<title><?= $e(t('notes.title')) ?>: <?= $e(t('app.name')) ?></title>
<link rel="manifest" href="<?= $e($basePath) ?>/notes/manifest.webmanifest">
<link rel="icon" href="<?= $e(Asset::url($basePath, 'icon.svg')) ?>" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="<?= $e(Asset::url($basePath, 'apple-touch-icon.png')) ?>">
<?php include __DIR__ . '/partials/head-assets.php'; ?>
<link rel="stylesheet" href="<?= $e(Asset::url($basePath, 'css/notes.css')) ?>">
</head>
<body class="wk wk-notes">
<div class="nt-app" data-island="notes">
<header class="nt-top">
<a class="nt-brand" href="#/" data-nav-list><?php $markClass = 'wk-brand-icon'; include __DIR__ . '/partials/brand-mark.php'; ?><span><?= $e(t('notes.title')) ?></span></a>
<span class="nt-status" data-status aria-live="polite"></span>
<span class="nt-top-actions">
<button class="btn btn-ghost nt-icon" type="button" data-sync title="<?= $e(t('notes.sync_now')) ?>" aria-label="<?= $e(t('notes.sync_now')) ?>" hidden><i class="ph ph-arrows-clockwise" aria-hidden="true"></i></button>
<button class="btn btn-ghost nt-icon" type="button" data-theme-toggle title="<?= $e(t('notes.theme')) ?>" aria-label="<?= $e(t('notes.theme')) ?>"><i class="ph ph-circle-half" aria-hidden="true"></i></button>
<button class="btn btn-ghost nt-icon" type="button" data-sign-out title="<?= $e(t('notes.sign_out')) ?>" aria-label="<?= $e(t('notes.sign_out')) ?>" hidden><i class="ph ph-sign-out" aria-hidden="true"></i></button>
</span>
</header>
<div class="nt-notices" data-notices></div>

<section class="nt-view nt-signin" data-view="signin" hidden>
<form class="card elev-md nt-signin-card" data-signin>
<span class="card-kicker"><?= $e(t('nav.signin')) ?></span>
<div class="field">
<label><?= $e(t('auth.username')) ?>
<input class="input" type="text" name="username" autocomplete="username" autocapitalize="none" required></label>
</div>
<div class="field">
<label><?= $e(t('auth.password')) ?>
<input class="input" type="password" name="password" autocomplete="current-password" required></label>
</div>
<button class="btn btn-primary btn-block" type="submit"><i class="ph ph-sign-in" aria-hidden="true"></i><?= $e(t('auth.submit')) ?></button>
</form>
</section>

<section class="nt-view nt-list" data-view="list" hidden>
<div class="nt-list-bar">
<input class="input" type="search" data-search placeholder="<?= $e(t('notes.search')) ?>" aria-label="<?= $e(t('notes.search')) ?>">
<button class="btn btn-primary" type="button" data-new><i class="ph ph-plus" aria-hidden="true"></i><?= $e(t('notes.new')) ?></button>
</div>
<ul class="nt-items" data-items></ul>
<p class="nt-empty" data-empty hidden><?= $e(t('notes.empty')) ?></p>
</section>

<section class="nt-view nt-editor" data-view="editor" hidden>
<div class="nt-editor-bar">
<a class="btn btn-ghost" href="#/" data-nav-list><i class="ph ph-arrow-left" aria-hidden="true"></i><?= $e(t('notes.back')) ?></a>
<span class="nt-editor-actions">
<button class="btn btn-ghost" type="button" data-preview aria-pressed="false"><i class="ph ph-eye" aria-hidden="true"></i><span data-preview-label><?= $e(t('notes.preview')) ?></span></button>
<button class="btn btn-ghost btn-danger" type="button" data-delete><i class="ph ph-trash" aria-hidden="true"></i><?= $e(t('notes.delete')) ?></button>
</span>
</div>
<div class="nt-conflict" data-conflict hidden></div>
<textarea class="input nt-text" data-text spellcheck="true" aria-label="<?= $e(t('notes.title')) ?>"></textarea>
<div class="nt-preview wk-prose" data-preview-pane hidden></div>
</section>
<script type="application/json"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</div>
<script src="<?= $e(Asset::url($basePath, 'js/notes.js')) ?>" defer></script>
</body>
</html>
