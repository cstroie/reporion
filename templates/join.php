<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * POST /join (Controller\JoinController, roadmap phase 29): the check
 * screen before reports are joined. The exams in order (↑ ↓ are submit
 * buttons), each with what it brings; the report fields to pick where the
 * parents differ; the path; what joining does. Nothing is written until
 * Join.
 *
 * Variables in scope: array $plan (Service\Joins::plan()); ?string $error;
 * string $back; string $basePath
 */

declare(strict_types=1);

use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;

/** @var array<string, mixed> $plan */
/** @var ?string $error */
/** @var string $back */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$parents = $plan['parents'];
$label = static fn (int $p): string => isset($parents[$p]) ? ReportName::examTitle($parents[$p]->frontmatter, ReportPath::leaf($parents[$p]->path)) : '';
$count = \count($plan['exams']);
?>
<div class="wk-doc wk-join">
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('join.title')) ?></h1></div>
<p class="wk-dim"><?= $e(t('join.lead')) ?></p>

<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>
<?php if ($plan['problems'] !== []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><b><?= $e(t('join.cannot')) ?></b><ul><?php foreach ($plan['problems'] as $problem): ?><li><?= $e($problem) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php if ($plan['relevelled']): ?>
<div class="wk-notice" role="status"><i class="ph ph-text-h"></i><div><?= $e(t('join.relevelled')) ?></div></div>
<?php endif; ?>
<?php if ($plan['signed'] !== []): ?>
<div class="wk-notice" role="status"><i class="ph ph-seal-check"></i><div><?= $e(t('join.signed', [implode(', ', $plan['signed'])])) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= $b ?>/join">
<input type="hidden" name="back" value="<?= $e($back) ?>">
<?php foreach ($parents as $parent): ?>
<input type="hidden" name="paths[]" value="<?= $e($parent->path) ?>">
<input type="hidden" name="rev[<?= $e($parent->pid) ?>]" value="<?= (int) $parent->rev ?>">
<?php endforeach; ?>

<?php if ($count > 0): ?>
<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('join.exams', [$count])) ?></h2><span class="wk-mono wk-dim"><?= $e(t('join.exams_note')) ?></span></header>
<div class="wk-examcards">
<?php foreach ($plan['exams'] as $i => $row): $exam = $row['exam']; ?>
<section class="wk-examcard">
<input type="hidden" name="order[]" value="<?= $e($row['key']) ?>">
<header class="wk-examcard-h">
<b class="wk-examcard-n"><?= $e(t('details.exam_n', [$i + 1])) ?></b>
<span><?= $e(MetaText::text($exam['title'] ?? null) ?: '—') ?></span>
<span class="wk-tflex"></span>
<span class="wk-examcard-tools">
<button class="wk-tbtn" type="submit" name="action" value="up:<?= $e($row['key']) ?>" title="<?= $e(t('details.exam_up')) ?>"<?= $i === 0 ? ' disabled' : '' ?>><i class="ph ph-arrow-up"></i></button>
<button class="wk-tbtn" type="submit" name="action" value="down:<?= $e($row['key']) ?>" title="<?= $e(t('details.exam_down')) ?>"<?= $i === $count - 1 ? ' disabled' : '' ?>><i class="ph ph-arrow-down"></i></button>
</span>
</header>
<div class="wk-kv wk-mt-2">
<span><?= $e(t('join.from')) ?></span><b><?= $e($label($row['parent'])) ?><?= ($parents[$row['parent']]->status ?? '') === 'signed' ? ' · ' . $e(t('page.signed')) : '' ?></b>
<span><?= $e(t('details.study_date')) ?></span><b class="wk-mono"><?= $e(MetaText::dateTime($exam['study_date'] ?? null, \Reporion\Support\MetaText::DATE, ' H:i')) ?></b>
<span><?= $e(t('details.modality')) ?></span><b><?= $e(implode(' · ', array_filter([implode(', ', Exams::listOf($exam['modality'] ?? null)), implode(', ', Exams::listOf($exam['region'] ?? null))]))) ?></b>
<?php if (($exam['accession'] ?? '') !== ''): ?><span><?= $e(t('details.accession')) ?></span><b class="wk-mono"><?= $e(MetaText::text($exam['accession'])) ?></b><?php endif; ?>
<?php if (($exam['template'] ?? '') !== ''): ?><span><?= $e(t('details.template')) ?></span><b class="wk-mono"><?= $e(MetaText::text($exam['template'])) ?></b><?php endif; ?>
</div>
<details class="wk-join-text"><summary class="wk-dim wk-text-sm"><?= $e(t('join.text', [substr_count($row['text'], "\n")])) ?></summary><pre class="wk-mono"><?= $e($row['text']) ?></pre></details>
</section>
<?php endforeach; ?>
</div>
</div>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('join.report')) ?></h2></header>
<div class="wk-form wk-join-fields">
<?php foreach ($plan['fields'] as $key => $values): ?>
<?php if ($values === []) { continue; } ?>
<fieldset><legend class="wk-text-sm"><?= $e(t('details.' . $key)) ?><?= \count($values) > 1 ? ' — ' . $e(t('join.pick')) : '' ?></legend>
<?php if (\count($values) === 1): ?>
<p class="wk-join-value"><?= $e($values[0]['value']) ?></p>
<?php else: ?>
<?php foreach ($values as $n => $value): ?>
<label class="radio"><input type="radio" name="choice[<?= $e($key) ?>]" value="<?= $n ?>"<?= $plan['chosen'][$key] === $n ? ' checked' : '' ?>><span class="dot"></span><span><?= $e($value['value']) ?> <small class="wk-dim">(<?= $e(implode(', ', array_map($label, $value['from']))) ?>)</small></span></label>
<?php endforeach; ?>
<?php endif; ?>
</fieldset>
<?php endforeach; ?>
<fieldset><legend class="wk-text-sm"><?= $e(t('new.path')) ?></legend>
<?php if (\count($plan['namespaces']) > 1): ?>
<label class="wk-text-sm"><span class="wk-dim"><?= $e(t('join.ns')) ?></span> <select class="input" name="ns"><?php foreach ($plan['namespaces'] as $ns): ?><option value="<?= $e($ns) ?>"<?= $ns === $plan['ns'] ? ' selected' : '' ?>><?= $e($ns) ?></option><?php endforeach; ?></select></label>
<?php endif; ?>
<?php if ($plan['prefix'] !== ''): ?>
<?php /* The last segment is the user's: "-rk"/"-lk" off, a typo fixed; checked on every post */ ?>
<label class="wk-pathb wk-mono"><span><?= $e($plan['prefix']) ?></span><input class="input wk-tflex" type="text" name="leaf" value="<?= $e($plan['leaf']) ?>" aria-label="<?= $e(t('join.leaf')) ?>" spellcheck="false"></label>
<p class="wk-dim wk-text-xs"><?= $e(t('join.leaf_help')) ?></p>
<?php else: ?>
<p class="wk-pathb wk-mono">—</p>
<?php endif; ?>
</fieldset>
</div>
</div>
<?php endif; ?>

<div class="wk-notice wk-mt-4" role="note"><i class="ph ph-info"></i><div><?= $e(t('join.what', [\count($parents)])) ?></div></div>

<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= $b . $e($back) ?>"><?= $e(t('editor.cancel')) ?></a>
<?php if ($count > 0): ?><button class="btn btn-secondary" type="submit" name="action" value="check"><i class="ph ph-arrows-clockwise"></i><?= $e(t('join.recheck')) ?></button><?php endif; ?>
<button class="btn btn-primary" type="submit" name="action" value="join"<?= $plan['problems'] !== [] ? ' disabled' : '' ?>><i class="ph ph-stack"></i><?= $e(t('join.do')) ?></button>
</div>
</form>
</div>
