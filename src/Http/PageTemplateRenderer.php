<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;
use Reporion\Service\Ai\Actions;
use Reporion\Service\References;
use Reporion\Service\Render;
use Reporion\Storage\PageRecord;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;

/**
 * A4 (docs/architecture-api.md §6): the owner and public layouts render the
 * same document body from the same Render::toHtml() call — only the
 * surrounding chrome differs — so the reader view cannot drift from the
 * report view. Shared by Controller\PageController and Controller\HomeController.
 */
final class PageTemplateRenderer
{
    /** Frontmatter fields templates/layout-public.php prints — nothing else reaches it */
    private const PUBLIC_FIELDS = ['device'];

    public function __construct(
        private readonly Render $render,
        private readonly IndexInterface $index,
        // Phase 25: the report view's References panel; none where a test builds this bare
        private readonly ?References $references = null,
        // The report tab's Summarize button (the reserved `summary` prompt, 2026-10-07)
        private readonly ?Actions $aiActions = null,
        /** @var ?\Closure(PageRecord): ?bool Similar reports (phase 34e): a report's vector — none (null), from its current text (true), from an older one (false); the closure is null when it is off */
        private readonly ?\Closure $vectorState = null,
        /** @var ?\Closure(string): ?string why a report's pid has no Similar reports — `normal`, `common` — or null (Index\Sqlite::similarExcluded()) */
        private readonly ?\Closure $similarExcluded = null,
    ) {
    }

    /**
     * $principal picks the chrome (the "app" view vs the bare public
     * layout) for whoever is already established as entitled to read
     * $record — that decision happened in Index\Sqlite's query
     * (Search\Query), not here. Any signed-in user gets the app chrome now,
     * not just the owner: an editor or viewer with a namespace grant is
     * ordinary staff using the app, the same as the owner is (D35).
     */
    /**
     * @param ?int $currentRev set when $record is an older revision
     *                         (/{path}@{rev}): the page's current rev
     * @param ?array{by: string, ts: string, alg: string, digest: string, parafa: ?string, matches: bool} $signature
     *                         $record's signature, when shown for verification
     */
    public function render(PageRecord $record, ?User $principal, Request $request, ?int $currentRev = null, ?array $signature = null): string
    {
        // The header already titles a report by its patient: the body's name
        // heading would repeat it, and head the table of contents (D30). A
        // public report shows its exam, not its patient: no name title, no
        // name heading, so none in the table of contents (D30, invariant 8)
        $publicReport = $principal === null && ReportPath::isReport($record->path);
        $rendered = $this->render->toHtml(
            $publicReport ? ReportName::forExport($record->body, $record->frontmatter) : ReportName::withoutNameHeading($record->body, $record->frontmatter),
            $request->basePath,
            examIds: Exams::isMulti($record->frontmatter),
        );
        $title = $publicReport ? ReportName::examTitle($record->frontmatter, t('print.untitled')) : MetaText::text($record->frontmatter['title'] ?? null);
        if ($title === '') {
            $title = $record->path;
        }

        // The full frontmatter (patient block included) goes to the
        // signed-in page view only, whose metadata panel is for staff. The
        // anonymous public layout gets just the fields it prints — the
        // patient identity must not even be in its scope (invariant 8).
        $vars = [
            'title' => $title,
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            // A multi-exam report whose exams do not add up: said here, blocks signing (phase 12)
            'warnings' => [...$rendered->warnings, ...array_map(self::examProblem(...), Exams::problems($record->frontmatter, $record->body))],
            'basePath' => $request->basePath,
            'currentRev' => $currentRev,
            'signature' => $signature,
        ];

        $vars['backlinks'] = $this->index->backlinks($record->pid, $principal);
        $latestRev = $record->revlog[array_key_last($record->revlog)] ?? null;
        $vars['latestRev'] = $latestRev;
        $vars += [
            'rev' => $record->rev,
            'path' => $record->path,
            'visibility' => $record->visibility,
            'status' => $record->status,
        ];

        if ($principal === null) {
            $public = array_intersect_key($record->frontmatter, array_flip(self::PUBLIC_FIELDS));

            return View::render(\dirname(__DIR__, 2) . '/templates/layout-public.php', $vars + ['frontmatter' => $public]);
        }

        $vars['frontmatter'] = $record->frontmatter;
        // Summarize: a writer, the current revision of an unsigned page, and the prompt there
        $vars['aiSummary'] = $currentRev === null && $record->status !== 'signed' && $principal->canWrite($record->path)
            && $this->aiActions?->special($record->path, 'summary') !== null;
        // Suggest tags (2026-10-08): the same rule, the `tags` prompt
        $vars['aiTags'] = $currentRev === null && $record->status !== 'signed' && $principal->canWrite($record->path)
            && $this->aiActions?->special($record->path, 'tags') !== null;
        // Similar reports (phase 34e): the panel loads its list (assets/js/similar.js)
        $vector = $currentRev === null && $this->vectorState !== null && ReportPath::isReport($record->path) ? ($this->vectorState)($record) : null;
        $vars['similar'] = $vector !== null;
        // Made from an older text: the list is the old one's until index:vectors runs
        $vars['similarStale'] = $vector === false;
        // A normal report: a note in place of the list, nothing fetched
        $vars['similarExcluded'] = $vector !== null && $this->similarExcluded !== null ? ($this->similarExcluded)($record->pid) : null;
        $vars['references'] = ReportPath::isReport($record->path) ? ($this->references?->forReport($record->frontmatter, $principal, $request->basePath) ?? []) : [];

        $vars += ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path));
        // No header for the stub home page: there is no page to act on
        if ($record->pid !== '') {
            $vars += ChromeVars::pageHeader(
                $principal,
                $record->path,
                'view',
                $title,
                $record->visibility,
                $record->status,
                $record->rev,
                $record->pid,
                isset($record->frontmatter['device']) ? MetaText::text($record->frontmatter['device']) : null,
                \is_array($latestRev) ? (string) $latestRev['ts'] : null,
                \is_array($latestRev) ? (string) $latestRev['by'] : null,
            );
        }

        return View::page(\dirname(__DIR__, 2) . '/templates/page-view.php', $vars, $title);
    }

    /** Support\Exams::problems()'s code as the sentence the page view shows */
    private static function examProblem(string $code): string
    {
        return preg_match('/^exams\.(\d+)\.(title|conclusion)$/', $code, $m) === 1
            ? t('page.exam_' . $m[2], [(int) $m[1]])
            : t('page.exam_count');
    }
}
