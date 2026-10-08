<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/sign (Controller\SignController) — content only, under
 * the page header. Names the revision being signed and who signs it; lists
 * required fields still empty (with a link to the editor) instead of the
 * button; says plainly that any later edit needs signing again (D3).
 *
 * Phase 34a: a *Check before signing* panel — the rules' warnings
 * (Support\Laterality: sides that disagree, no conclusion), and, when the
 * profile has the reserved `presign` prompt, the assistant's list
 * (assets/js/ai-presign.js). Warnings only: signing is never blocked by them.
 *
 * Variables in scope: string $path, $parafa, $signerName, $signerTitle,
 * $basePath; int $rev; list<string> $missing; bool $stale;
 * list<array{code: string, exam: string, sides: list<string>, other: list<string>}> $checks; bool $presign
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $rev */
/** @var list<string> $missing */
/** @var bool $stale */
/** @var string $parafa */
/** @var string $signerName */
/** @var string $signerTitle */
/** @var string $basePath */

$p = htmlspecialchars($basePath . '/' . $path, ENT_QUOTES);
$label = static function (string $field): string {
    // A multi-exam report's exams (Support\Exams::problems())
    if (preg_match('/^exams\.(\d+)\.(title|conclusion)$/', $field, $m) === 1) {
        return t('sign.exam_' . $m[2], [(int) $m[1]]);
    }
    if ($field === 'exams.count') {
        return t('sign.exam_count');
    }
    foreach (['meta.' . str_replace('.', '_', $field), 'meta.' . substr((string) strrchr('.' . $field, '.'), 1)] as $key) {
        if (t($key) !== $key) {
            return t($key);
        }
    }

    return $field;
};
?>
<div class="wk-doc" style="max-width:720px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t('sign.title'), ENT_QUOTES) ?></h2>
<?php if ($stale): ?>
<p role="alert"><?= htmlspecialchars(t('sign.stale', [$rev]), ENT_QUOTES) ?></p>
<?php endif; ?>
<p class="wk-text-sm"><?= htmlspecialchars(t('sign.explain', [$rev, $signerName . ($signerTitle !== '' ? ' · ' . $signerTitle : '')]), ENT_QUOTES) ?></p>
<?php
$checks ??= [];
$sideWords = static fn (array $sides): string => implode(' + ', array_map(static fn (string $s): string => t('sign.side.' . $s), $sides));
?>
<section class="wk-presign" aria-labelledby="presign-h">
<h3 class="wk-eyebrow" id="presign-h"><i class="ph ph-list-checks" aria-hidden="true"></i> <?= htmlspecialchars(t('sign.check.title'), ENT_QUOTES) ?></h3>
<?php if ($checks === []): ?>
<p class="wk-presign-ok wk-text-sm"><i class="ph ph-check-circle" aria-hidden="true"></i><?= htmlspecialchars(t('sign.check.none'), ENT_QUOTES) ?></p>
<?php else: ?>
<ul class="wk-presign-list">
<?php foreach ($checks as $check): ?>
<li><i class="ph ph-warning" aria-hidden="true"></i><span><?= htmlspecialchars(t('sign.check.' . $check['code'], [$sideWords($check['sides']), $sideWords($check['other'])]), ENT_QUOTES) ?><?php if ($check['exam'] !== ''): ?> <span class="wk-dim">— <?= htmlspecialchars($check['exam'], ENT_QUOTES) ?></span><?php endif; ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($presign ?? false): ?>
<div class="wk-presign-ai" data-presign hidden>
<p class="wk-presign-ai-h wk-text-sm"><b><?= htmlspecialchars(t('sign.check.ai'), ENT_QUOTES) ?></b> <span class="wk-mono wk-dim" data-presign-meta></span>
<button type="button" class="btn btn-secondary btn-sm" data-presign-again><?= htmlspecialchars(t('ai.again'), ENT_QUOTES) ?></button></p>
<div class="wk-text-sm" data-presign-out aria-live="polite"></div>
</div>
<script type="application/json" id="presign-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'rev' => $rev,
    'strings' => ['working' => t('editor.ai.working'), 'failed' => t('editor.ai.failed'), 'none' => t('sign.check.ai_none')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/ai-presign.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<p class="wk-dim wk-text-xs"><?= htmlspecialchars(t('sign.check.help'), ENT_QUOTES) ?></p>
</section>
<?php if ($missing !== []): ?>
<div role="alert">
<p class="wk-text-sm"><?= htmlspecialchars(t('sign.missing'), ENT_QUOTES) ?></p>
<ul>
<?php foreach ($missing as $field): ?>
<li><?= htmlspecialchars($label($field), ENT_QUOTES) ?> <span class="wk-mono wk-dim"><?= htmlspecialchars($field, ENT_QUOTES) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= $p ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<a class="btn btn-primary" href="<?= $p ?>/edit"><i class="ph ph-pencil-simple"></i><?= htmlspecialchars(t('sign.edit'), ENT_QUOTES) ?></a>
</div>
<?php else: ?>
<p class="wk-text-sm"><?= htmlspecialchars(t('sign.after'), ENT_QUOTES) ?></p>
<form class="wk-form" action="<?= $p ?>/sign" method="post">
<input type="hidden" name="base_rev" value="<?= $rev ?>">
<input type="hidden" name="presign_ai" value="" data-presign-count>
<div class="field"><label for="parafa"><?= htmlspecialchars(t('sign.parafa'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="parafa" name="parafa" value="<?= htmlspecialchars($parafa, ENT_QUOTES) ?>" maxlength="32" autocomplete="off"></div>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= $p ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-primary" type="submit"><i class="ph ph-seal-check"></i><?= htmlspecialchars(t('sign.submit', [$rev]), ENT_QUOTES) ?></button>
</div>
</form>
<?php endif; ?>
</div>
