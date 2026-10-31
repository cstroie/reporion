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
use Reporion\Support\Citation;
use Reporion\Support\Exams;
use Reporion\Support\Feeds;
use Reporion\Support\InternalLink;
use Reporion\Support\LibrarySource;
use Reporion\Support\PageTitle;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;
use Reporion\Support\TemplateLint;

/**
 * A4 (docs/architecture-api.md §6): the owner and public layouts render the
 * same document body from the same Render::toHtml() call: only the
 * surrounding chrome differs, so the reader view cannot drift from the
 * report view. Shared by Controller\PageController and Controller\HomeController.
 */
final class PageTemplateRenderer
{
    /** Frontmatter fields templates/layout-public.php prints: nothing else reaches it */
    private const PUBLIC_FIELDS = ['device'];

    /** Rows the page view's left panel lists (the chips and the search box filter these in the browser) */
    private const WORKLIST_ROWS = 150;

    public function __construct(
        private readonly Render $render,
        private readonly IndexInterface $index,
        // Phase 25: the report view's References panel; none where a test builds this bare
        private readonly ?References $references = null,
        // The report tab's Summarize button (the reserved `summary` prompt, 2026-10-07)
        private readonly ?Actions $aiActions = null,
        /** @var ?\Closure(PageRecord): ?bool Similar reports (phase 34e): a report's vector: none (null), from its current text (true), from an older one (false); the closure is null when it is off */
        private readonly ?\Closure $vectorState = null,
        /** @var ?\Closure(string): ?string why a report's pid has no Similar reports:`normal`, `common`, or null (Index\Sqlite::similarExcluded()) */
        private readonly ?\Closure $similarExcluded = null,
        // Where `[@key]` citations find their sources (docs/library.md)
        private readonly string $libraryNamespace = 'library',
        // The assistant's saved analyses of a source and Studio texts of a notebook (2026-10-31)
        private readonly ?\Reporion\Service\Library\Analyses $analyses = null,
        // A speech model is set: Studio offers the audio overview (phase 8)
        private readonly bool $speaks = false,
    ) {
    }

    /**
     * $principal picks the chrome (the "app" view vs the bare public
     * layout) for whoever is already established as entitled to read
     * $record, that decision happened in Index\Sqlite's query
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
        $rendered = $this->render->body(
            $publicReport ? ReportName::forExport($record->body, $record->frontmatter) : ReportName::withoutNameHeading($record->body, $record->frontmatter),
            $record->frontmatter,
            $request->basePath,
            examIds: Exams::isMulti($record->frontmatter),
            // A cited source links to its library page for a reader of the library (docs/library.md)
            citeLinks: $principal !== null && $principal->canRead($this->libraryNamespace),
        );
        $title = $publicReport ? ReportName::examTitle($record->frontmatter, t('print.untitled')) : PageTitle::of($record->frontmatter, $record->body);
        if ($title === '') {
            $title = $record->path;
        }

        // The full frontmatter (patient block included) goes to the
        // signed-in page view only, whose metadata panel is for staff. The
        // anonymous public layout gets just the fields it prints: the
        // patient identity must not even be in its scope (invariant 8).
        $vars = [
            'title' => $title,
            'contentHtml' => $rendered->html,
            'toc' => $rendered->toc,
            // A multi-exam report whose exams do not add up: said here, blocks signing (phase 12)
            // What the dialect could not render (raw HTML, a citation no source has) is for a writer to fix: a visitor is not told
            'warnings' => [...($principal !== null ? $rendered->warnings : []), ...array_map(self::examProblem(...), Exams::problems($record->frontmatter, $record->body))],
            'basePath' => $request->basePath,
            'currentRev' => $currentRev,
            'signature' => $signature,
        ];

        $vars['backlinks'] = $this->index->backlinks($record->pid, $principal);
        // The page's comments (D52): for whoever is signed in and reads it; loaded by assets/js/comments.js
        $vars['comments'] = $principal !== null;
        $vars['commenter'] = $principal !== null ? ['username' => $principal->username, 'owner' => $principal->isOwner] : null;
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

            $vars['feedLinks'] = Feeds::alternates($request->feedNamespaces, ChromeVars::namespaceOf($record->path), $request->basePath);

            return View::render(\dirname(__DIR__, 2) . '/templates/layout-public.php', $vars + ChromeVars::theme($request) + ['frontmatter' => $public]);
        }

        $vars['frontmatter'] = $record->frontmatter;
        // A report template the Create action reads badly: said to whoever is signed in (Support\TemplateLint, a warning, D7)
        if (TemplateLint::applies($record->path, $record->frontmatter)
            && ($lint = TemplateLintText::notice(TemplateLint::problems($record->frontmatter, $record->body))) !== null) {
            $vars['warnings'][] = $lint;
        }
        // Summarize: a writer, the current revision of an unsigned page, and the prompt there
        $vars['aiSummary'] = $currentRev === null && $record->status !== 'signed' && $principal->canWrite($record->path) && !LibrarySource::isSource($record->frontmatter)
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
        // A library source (docs/library.md): its reference, its original, its record to correct
        $vars['librarySource'] = LibrarySource::isSource($record->frontmatter) ? [
            'pid' => $record->pid,
            'key' => substr($record->path, (int) strrpos($record->path, ':') + 1),
            'rev' => $record->rev,
            'citation' => Citation::vancouver(\is_array($record->frontmatter['csl'] ?? null) ? $record->frontmatter['csl'] : ['title' => $title]),
            'csl' => \is_array($record->frontmatter['csl'] ?? null) ? $record->frontmatter['csl'] : [],
            'tags' => array_values(array_filter((array) ($record->frontmatter['tags'] ?? []), 'is_string')),
            'original' => \is_array($record->frontmatter['original'] ?? null) ? [
                'ext' => (string) ($record->frontmatter['original']['ext'] ?? ''),
                'pages' => (int) ($record->frontmatter['original']['pages'] ?? 0),
                'bytes' => (int) ($record->frontmatter['original']['bytes'] ?? 0),
            ] : null,
            'path' => $record->path,
            // The analyses the assistant can make of it (docs/library.md, phase 5): those whose prompt page the serving profile has
            'aiTasks' => $currentRev === null && $principal->canWrite($record->path) && $this->aiActions !== null
                ? array_values(array_filter(array_keys(\Reporion\Service\Library\SourceAnalysis::TASKS), fn (string $task): bool => $this->aiActions->special($record->path, \Reporion\Service\Library\SourceAnalysis::TASKS[$task]) !== null))
                : [],
            'canEdit' => $currentRev === null && $principal->canWrite($record->path),
            // What it cites (phase 7): its reference list against the records the caller reads (the index only)
            'cites' => $this->cites($record, $principal),
            // What the assistant made of it before, kept beside it, for any reader of the source
            'saved' => $currentRev === null ? $this->rendered($this->analyses?->shown($record, $principal, array_values(\Reporion\Service\Library\SourceAnalysis::TASKS)) ?? [], [], $request->basePath, $principal) : [],
        ] : null;
        // Ask the notebook (docs/library.md, phase 5): a page that cites library sources, for an editor of it and of the library, with the prompt there
        $notebook = $currentRev === null && !ReportPath::isReport($record->path) && !isset($record->frontmatter['patient']) && !LibrarySource::isSource($record->frontmatter)
            && !\Reporion\Service\Library\Analyses::isAnalysis($record->frontmatter)
            && $principal->canWrite($record->path) && $principal->canWrite($this->libraryNamespace)
            && (InternalLink::citedKeys($record->body) !== [] || (\is_array($record->frontmatter['sources'] ?? null) && $record->frontmatter['sources'] !== []));
        $vars['notebookAsk'] = $notebook && $this->aiActions?->special($record->path, 'ask-notebook') !== null ? ['path' => $record->path] : null;
        // Studio (phase 6): the same gate, the tasks whose prompt page the serving profile has
        $studioTasks = $notebook && $this->aiActions !== null
            ? array_values(array_filter(array_keys(\Reporion\Service\Library\Studio::TASKS), fn (string $task): bool => ($task !== 'audio' || $this->speaks) && $this->aiActions->special($record->path, \Reporion\Service\Library\Studio::TASKS[$task]) !== null))
            : [];
        $vars['notebookStudio'] = $studioTasks !== [] ? ['path' => $record->path, 'tasks' => $studioTasks, 'slug' => substr($record->path, (int) strrpos($record->path, ':') + 1),
            'saved' => $this->rendered($this->analyses?->shown($record, $principal, array_values(\Reporion\Service\Library\Studio::TASKS)) ?? [], $record->frontmatter, $request->basePath, $principal)] : null;
        $vars['references'] = ReportPath::isReport($record->path) ? ($this->references?->forReport($record->frontmatter, $principal, $request->basePath) ?? []) : [];

        $vars += ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($record->path));
        // No header for the stub home page: there is no page to act on
        if ($record->pid !== '') {
            // The left panel: the namespace's pages the caller may read, newest exam first (listWorklist(), invariant 6)
            $vars['worklist'] = $this->index->listWorklist(ChromeVars::namespaceOf($record->path), $principal, self::WORKLIST_ROWS, byStudyDate: true);
            $vars += ChromeVars::pageHeader(
                $principal,
                $record->path,
                'view',
                $title,
                $record->visibility,
                $record->status,
                $record->rev,
                $record->pid,
                (Exams::report($record->frontmatter)['device'] ?: null),
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

    /**
     * A library source's reference list matched against the library (docs/library.md, phase 7):
     * the records the caller reads, from the index (no source file read), itself left out; null
     * when the source keeps no reference list
     *
     * @return ?array{entries: list<array{text: string, doi: ?string, match: ?array{key: string, path: string, title: string, by: string}}>, found: int}
     */
    private function cites(PageRecord $record, ?User $principal): ?array
    {
        $entries = \Reporion\Support\ReferenceList::entries(\Reporion\Support\ReferenceList::of($record->body));
        if ($entries === []) {
            return null;
        }
        $records = [];
        foreach ($this->index->listNamespaceMeta($this->libraryNamespace, $principal) as $row) {
            if ($row['pid'] !== $record->pid && LibrarySource::isSource($row['meta']) && \is_array($row['meta']['csl'] ?? null)) {
                $records[] = ['key' => substr($row['path'], \strlen($this->libraryNamespace) + 1), 'path' => $row['path'], 'title' => (string) $row['title'], 'csl' => $row['meta']['csl']];
            }
        }
        $matched = \Reporion\Support\ReferenceList::match($entries, $records);

        return ['entries' => $matched, 'found' => \count(array_filter($matched, static fn (array $e): bool => $e['match'] !== null))];
    }

    /**
     * Saved analyses with their text rendered by the one renderer (invariant 4), in the citation
     * style and language of the page they belong to; their headings' ids dropped, as the
     * References panel's, so they never collide with the page's own anchors
     *
     * @param list<array{prompt: string, path: string, text: string, at: string, provider: string, stale: bool}> $saved
     * @param array<string, mixed> $frontmatter the page's
     *
     * @return list<array{prompt: string, path: string, text: string, at: string, provider: string, stale: bool, html: string}>
     */
    private function rendered(array $saved, array $frontmatter, string $basePath, ?User $principal): array
    {
        $style = array_intersect_key($frontmatter, ['citation_style' => true, 'language' => true]);
        foreach ($saved as $i => $item) {
            $html = $this->render->body($item['text'], $style, $basePath, citeLinks: $principal !== null && $principal->canRead($this->libraryNamespace))->html;
            $saved[$i]['html'] = (string) preg_replace('/(<h[1-6][^>]*?)\sid="[^"]*"/', '$1', $html);
        }

        return $saved;
    }
}
