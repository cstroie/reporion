<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The imaging guide (D62, Controller\GuideController): the search, the chapters' outline, or one
 * clinical situation with its exams. The guide's words are shown as they are (Romanian content,
 * D26); the chrome is lang/en.php. No script: a GET form.
 *
 * Variables in scope: string $basePath, $q; array $filters; list<array> $results, $outline;
 * ?array $situation; bool $searched; ?array $info (Guide::info())
 */

declare(strict_types=1);

use Reporion\Support\GuideCodes;

/** @var string $basePath */
/** @var string $q */
/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $results */
/** @var list<array<string, mixed>> $outline */
/** @var ?array<string, mixed> $situation */
/** @var bool $searched */
/** @var ?array<string, mixed> $info */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$link = static fn (array $query): string => $basePath . '/guide' . ($query !== [] ? '?' . http_build_query($query) : '');
$exams = static function (array $situation) use ($e): string {
    $html = '<table class="table wk-guide-exams"><thead><tr><th>' . $e(t('guide.col.exam')) . '</th><th>' . $e(t('guide.col.indication')) . '</th><th>' . $e(t('guide.col.grade')) . '</th><th>' . $e(t('guide.col.dose')) . '</th></tr></thead><tbody>';
    foreach ($situation['exams'] as $x) {
        $dose = $x['dose_min'] === $x['dose_max'] ? $x['dose_min'] : $x['dose_min'] . '–' . $x['dose_max'];
        $html .= '<tr><td><span class="tag tag-outline" title="' . $e($x['type']) . '">' . $e($x['tip']) . '</span> ' . $e($x['exam']) . ($x['therapeutic'] === 1 ? ' <i class="ph ph-first-aid" title="' . $e(t('guide.therapeutic')) . '" aria-label="' . $e(t('guide.therapeutic')) . '"></i>' : '') . '</td>'
            . '<td><span class="wk-guide-ind wk-guide-ind-' . $e($x['indication_code'] !== '' ? $x['indication_code'] : 'other') . '">' . $e($x['indication']) . '</span></td>'
            . '<td>' . $e($x['grade']) . '</td><td class="wk-num">' . $e($dose) . '</td></tr>';
    }

    return $html . '</tbody></table>';
};
$comments = static fn (array $situation): array => array_values(array_unique(array_filter(array_column($situation['exams'], 'comment'))));
?>
<section class="wk-guide">
<?php if ($info === null): ?>
<?php $noticeKind = 'info'; $noticeText = t('guide.empty'); include __DIR__ . '/partials/notice.php'; ?>
<?php elseif ($situation !== null): ?>
<p class="wk-dim wk-text-sm"><a href="<?= $e($link([])) ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> <?= $e(t('guide.title')) ?></a> · <a href="<?= $e($link(['chapter' => $situation['chapter']])) ?>"><?= $e($situation['chapter']) ?></a> · <?= $e($situation['subchapter']) ?></p>
<h1 class="wk-doc-title"><?= $e($situation['title']) ?></h1>
<?= $exams($situation) ?>
<?php foreach ($comments($situation) as $comment): ?>
<p class="wk-guide-comment"><?= $e($comment) ?></p>
<?php endforeach; ?>
<?php else: ?>
<h1 class="wk-doc-title"><?= $e(t('guide.title')) ?></h1>
<p class="wk-dim"><?= $e(t('guide.intro')) ?></p>
<form class="wk-guide-form" method="get" action="<?= $e($basePath . '/guide') ?>" role="search">
<span class="group group-fill"><span class="group-addon"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></span><input class="input" type="search" name="q" value="<?= $e($q) ?>" maxlength="200" placeholder="<?= $e(t('guide.search_hint')) ?>" aria-label="<?= $e(t('guide.search')) ?>"><button class="btn btn-primary" type="submit"><?= $e(t('guide.search_go')) ?></button></span>
<div class="wk-guide-filters">
<label><?= $e(t('guide.chapter')) ?><select class="input" name="chapter"><option value=""><?= $e(t('guide.any')) ?></option><?php foreach ($outline as $c): ?><option<?= ($filters['chapter'] ?? '') === $c['chapter'] ? ' selected' : '' ?>><?= $e($c['chapter']) ?></option><?php endforeach; ?></select></label>
<label><?= $e(t('guide.type')) ?><select class="input" name="tip"><option value=""><?= $e(t('guide.any')) ?></option><?php foreach (GuideCodes::TYPES as $code => $name): ?><option value="<?= $e($code) ?>"<?= \in_array($code, $filters['tip'] ?? [], true) ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach; ?></select></label>
<label><?= $e(t('guide.col.indication')) ?><select class="input" name="indication"><option value=""><?= $e(t('guide.any')) ?></option><?php foreach (GuideCodes::INDICATIONS as $code => $name): ?><option value="<?= $e($code) ?>"<?= \in_array($code, $filters['indication'] ?? [], true) ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach; ?></select></label>
</div>
</form>
<?php if ($searched): ?>
<?php if ($results === []): ?>
<p class="wk-dim"><?= $e(t('guide.none')) ?></p>
<?php endif; ?>
<?php foreach ($results as $s): ?>
<article class="wk-panel wk-guide-situation">
<p class="wk-dim wk-text-sm"><?= $e($s['chapter']) ?> · <?= $e($s['subchapter']) ?></p>
<h2><a href="<?= $e($basePath . '/guide/' . $s['key']) ?>"><?= $e($s['title']) ?></a></h2>
<?= $exams($s) ?>
</article>
<?php endforeach; ?>
<?php else: ?>
<ul class="wk-guide-outline">
<?php foreach ($outline as $c): ?>
<li><a href="<?= $e($link(['chapter' => $c['chapter']])) ?>"><?= $e($c['chapter']) ?></a> <span class="wk-dim">(<?= (int) $c['situations'] ?>)</span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>
<?php if ($info !== null): ?>
<footer class="wk-guide-credit wk-dim wk-text-sm"><?= $e(t('guide.credit', [$info['source'] !== '' ? $info['source'] : substr($info['sha256'], 0, 12), substr($info['loaded_at'], 0, 10)])) ?></footer>
<?php endif; ?>
</section>
