<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Studio (docs/library.md, phase 6): texts the assistant makes from this notebook and the library
 * sources it cites (POST /api/v1/notebook/studio, assets/js/notebook-studio.js): an overview, a
 * glossary, questions and answers, a timeline, a comparison table (the writer's questions as its
 * columns), a check of this page against its sources, a report template. Each is shown, rendered
 * by the server in the page's citation style, with Copy; nothing is saved. The template is made a
 * page by the writer (POST /api/v1/pages, its `reference` this notebook, D8).
 *
 * Variables in scope: array{path: string, tasks: list<string>, slug: string} $notebookStudio; string $basePath;
 * array<string, mixed> $frontmatter
 */

declare(strict_types=1);

/** @var array{path: string, tasks: list<string>, slug: string} $notebookStudio */
/** @var string $basePath */
/** @var array<string, mixed> $frontmatter */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$tasks = $notebookStudio['tasks'];
?>
<details class="wk-panel wk-notebook-studio" data-island="notebook-studio">
<summary class="wk-eyebrow"><i class="ph ph-flask" aria-hidden="true"></i> <?= $e(t('studio.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('studio.lede')) ?></p>
<div class="wk-lib-ai-tasks">
<?php foreach ($tasks as $task): ?>
<button class="btn btn-secondary btn-sm" type="button" data-studio-task="<?= $e($task) ?>" title="<?= $e(t('studio.' . $task . '_help')) ?>"><?= $e(t('studio.' . $task)) ?></button>
<?php endforeach; ?>
</div>
<?php if (\in_array('compare', $tasks, true)): ?>
<div class="field wk-studio-questions" data-studio-questions hidden><label><?= $e(t('studio.questions')) ?>
<textarea class="input" rows="4" maxlength="<?= (\Reporion\Service\Library\Studio::MAX_QUESTION + 1) * \Reporion\Service\Library\Studio::MAX_QUESTIONS ?>" placeholder="<?= $e(t('studio.questions_hint')) ?>"></textarea>
<small><?= $e(t('studio.questions_help', [(string) \Reporion\Service\Library\Studio::MAX_QUESTIONS])) ?></small></label>
<footer class="wk-actions"><button class="btn btn-primary btn-sm" type="button" data-studio-compare><i class="ph ph-table" aria-hidden="true"></i><?= $e(t('studio.compare_run')) ?></button></footer>
</div>
<?php endif; ?>
<div class="wk-notebook-out" data-studio-out aria-live="polite" hidden></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'path' => $notebookStudio['path'],
    'templatePath' => \Reporion\Support\Templates::NS . ':' . $notebookStudio['slug'],
    'citationStyle' => \Reporion\Support\Citation::style($frontmatter['citation_style'] ?? null),
    'language' => \is_string($frontmatter['language'] ?? null) ? $frontmatter['language'] : '',
    'strings' => [
        'working' => t('studio.working'), 'progress' => t('studio.progress'), 'failed' => t('studio.failed'), 'note' => t('studio.note'),
        'copy' => t('library.ai.copy'), 'copied' => t('library.ai.copied'), 'sources' => t('notebook.sources'),
        'whole' => t('notebook.whole'), 'condensed' => t('studio.condensed'), 'dropped' => t('notebook.dropped'),
        'questions_needed' => t('studio.questions_needed'),
        'tpl_path' => t('studio.tpl_path'), 'tpl_path_help' => t('studio.tpl_path_help'), 'tpl_create' => t('studio.tpl_create'),
        'tpl_bad_path' => t('studio.tpl_bad_path'), 'tpl_checklist' => t('studio.tpl_checklist'), 'tpl_problems' => t('studio.tpl_problems'),
        'tpl_exists' => t('studio.tpl_exists'), 'tpl_no_grant' => t('studio.tpl_no_grant'), 'tpl_note' => t('studio.tpl_note'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notebook-studio.js')) ?>" defer></script>
