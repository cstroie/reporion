<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\LibraryException;
use Reporion\Http\Request;
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Storage\PageRecord;
use Reporion\Support\AskExpansion;
use Reporion\Support\PageTitle;
use Reporion\Support\RecallTerms;
use Reporion\Support\SourceChunks;
use Reporion\Support\TemplateDraft;

/**
 * Studio (docs/library.md, phase 6): a notebook's sources, read under the caller
 * (NotebookAsk::sources(), invariant 6), turned by the assistant into one text in the notebook's
 * `language`, citing `[@key]`: an overview (the subject systematized), a glossary, an FAQ, a
 * timeline, a comparison table (one row per source, the writer's questions as columns), a check
 * of the page against its sources, or a report template (D45 shape) with its checklist. The
 * instruction is each task's reserved prompt page (D15); this class decides what goes in which
 * request. Shown, never written: the writer copies it, or makes the template page (D8).
 *
 * - overview, glossary, faq, timeline, template: the notebook and its sources whole when they fit
 *   `library.context_tokens`; otherwise each text longer than its share is condensed part by part
 *   (`source-part`), each kept in its own `<source key>` block so the answer can cite it, and the
 *   task runs on the notes (map-reduce).
 * - compare: one request per source (its row, the questions over that source alone, whole or
 *   from its notes), the table assembled here, each row citing its own key.
 * - check: the page's own text and the sources whole, or their passages holding the page's words
 *   (and those `notebook-terms` adds in the sources' language), never notes: a claim checked
 *   against a summary would be "not found" for nothing.
 *
 * At most MAX_REQUESTS requests a run, counted before the first one (refused beyond: minutes of
 * a held assistant lock and a web server timeout otherwise). A citation of a key that was not
 * sent is taken out (NotebookAsk::onlySent()).
 */
final class Studio
{
    /** The tasks: task => its reserved prompt */
    public const TASKS = [
        'overview' => 'studio-overview',
        'glossary' => 'studio-glossary',
        'faq' => 'studio-faq',
        'timeline' => 'studio-timeline',
        'compare' => 'studio-compare',
        'check' => 'studio-check',
        'template' => 'studio-template',
        // Phase 8: cards for learning the subject, kept and downloadable for Anki
        'flashcards' => 'studio-flashcards',
        // Phase 8: a two-voice script, read aloud by the speech model (AudioOverview); offered only when one is set
        'audio' => 'studio-audio',
    ];

    /** The most requests one run may make */
    public const MAX_REQUESTS = 16;

    /** The comparison's questions: how many, how long each */
    public const MAX_QUESTIONS = 8;
    public const MAX_QUESTION = 200;

    /** The share of the budget the page's own text may take in a check */
    private const CHECK_PAGE_SHARE = 0.5;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly NotebookAsk $notebooks,
        private readonly int $contextTokens,
        // The table's heading for a notebook with no `language` (`library.language`)
        private readonly string $defaultLanguage = 'ro',
    ) {
    }

    /**
     * The tasks the profile serving $path has a prompt page for
     *
     * @return list<string> in TASKS' order
     */
    public function available(string $path): array
    {
        return array_values(array_filter(
            array_keys(self::TASKS),
            fn (string $task): bool => $this->actions->special($path, self::TASKS[$task]) !== null,
        ));
    }

    /**
     * The comparison's questions as sent: trimmed, one line each, empty ones out
     *
     * @return list<string>
     *
     * @throws LibraryException INVALID for none, too many or too long
     */
    public static function questions(mixed $raw): array
    {
        $lines = \is_array($raw) ? $raw : (\is_string($raw) ? (preg_split('/\R/u', $raw) ?: []) : []);
        $questions = [];
        foreach ($lines as $line) {
            $line = \is_string($line) ? trim((string) preg_replace('/\s+/u', ' ', $line)) : '';
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) > self::MAX_QUESTION) {
                throw new LibraryException(LibraryException::INVALID);
            }
            $questions[] = $line;
        }
        if ($questions === [] || \count($questions) > self::MAX_QUESTIONS) {
            throw new LibraryException(LibraryException::INVALID);
        }

        return $questions;
    }

    /**
     * What a run of $task on $notebook will send, counted before any request
     *
     * @return array{task: string, texts: list<array{key: ?string, path: string, title: string, text: string}>, page: string, whole: bool, requests: int}
     *
     * @throws LibraryException INVALID (an unknown task, no source the caller may read), TOO_LARGE, NO_TEXT (a check of an
     *                          empty page), TOOL_MISSING (the sources must be condensed and the `source-part` prompt is missing)
     */
    public function plan(string $task, PageRecord $notebook, User $principal): array
    {
        if (!isset(self::TASKS[$task])) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $sources = $this->notebooks->sources($notebook, $principal);
        if ($sources === []) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $budget = SourceChunks::budget($this->contextTokens);
        $own = ['key' => null, 'path' => $notebook->path, 'title' => PageTitle::of($notebook->frontmatter, $notebook->body), 'text' => trim($notebook->body)];
        $page = '';

        if ($task === 'compare') {
            $texts = $sources;
            $requests = 0;
            foreach ($texts as $t) {
                $n = \count(SourceChunks::split($t['text'], $budget));
                $requests += $n === 0 ? 0 : ($n > 1 ? $n + 1 : 1);
            }
            $whole = $requests === \count(array_filter($texts, static fn (array $t): bool => $t['text'] !== ''));
        } elseif ($task === 'check') {
            $page = $own['text'];
            if ($page === '' || mb_strlen($page) > (int) ($budget * self::CHECK_PAGE_SHARE)) {
                throw new LibraryException($page === '' ? LibraryException::NO_TEXT : LibraryException::TOO_LARGE);
            }
            $texts = $sources;
            $whole = self::length($texts) <= $budget - mb_strlen($page);
            // Passages: the search words first, when the prompt for them is there
            $requests = $whole || $this->actions->special($notebook->path, 'notebook-terms') === null ? 1 : 2;
        } else {
            $texts = $own['text'] !== '' ? [$own, ...$sources] : $sources;
            $whole = self::length($texts) <= $budget;
            $requests = $whole ? 1 : \count(self::condensing($texts, $budget)) + 1;
        }
        if ($requests > self::MAX_REQUESTS) {
            throw new LibraryException(LibraryException::TOO_LARGE);
        }
        // Condensing needs its prompt: known now, not after rows already asked
        $condenses = $task === 'compare'
            ? $requests > \count(array_filter($texts, static fn (array $t): bool => $t['text'] !== ''))
            : ($task !== 'check' && !$whole);
        if ($condenses && $this->actions->special($notebook->path, 'source-part') === null) {
            throw new LibraryException(LibraryException::TOOL_MISSING);
        }

        return ['task' => $task, 'texts' => $texts, 'page' => $page, 'whole' => $whole, 'requests' => max(1, $requests)];
    }

    /**
     * @param array{task: string, texts: list<array{key: ?string, path: string, title: string, text: string}>, page: string, whole: bool, requests: int} $plan plan()'s
     * @param list<string>          $questions the comparison's columns (questions()), else []
     * @param \Closure(int, int): void $progress called with (step, steps) before each request
     *
     * @return array{task: string, result: string, template: ?array{body: string, checklist: list<string>, problems: list<string>}, notices: list<string>, sources: list<array{key: string, title: string, path: string}>, whole: bool, requests: int, dropped: int, ms: int, provider: string, context: list<string>}
     *
     * @throws AiException not_configured when a prompt page is missing, or the provider's reason
     */
    public function run(array $plan, PageRecord $notebook, User $user, ?Request $request, array $questions, \Closure $progress): array
    {
        $task = $plan['task'];
        $action = $this->actions->special($notebook->path, self::TASKS[$task]);
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for this Studio task');
        }
        $language = $notebook->frontmatter['language'] ?? null;
        $title = PageTitle::of($notebook->frontmatter, $notebook->body);
        $budget = SourceChunks::budget($this->contextTokens);
        $step = 0;
        $steps = $plan['requests'];
        $ms = 0;
        $last = ['provider' => '', 'contextSet' => []];
        $call = function (\Closure $request) use (&$step, $steps, $progress, &$ms, &$last): string {
            $progress(++$step, $steps);
            $done = $request();
            $ms += $done['ms'];
            $last = $done;

            return trim($done['result']);
        };
        $notices = [];
        $sent = array_values(array_unique(array_filter(array_column($plan['texts'], 'key'), 'is_string')));

        if ($task === 'compare') {
            $rows = [];
            foreach ($plan['texts'] as $t) {
                if ($t['text'] === '') {
                    $rows[] = [$t['key'], array_fill(0, \count($questions), '–')];
                    continue;
                }
                $text = $this->notesOf($t, $notebook, $budget, $language, $call, $user, $request);
                $answer = $call(fn (): array => $this->assistant->runStudio($action, $title, [['n' => 1] + $this->asSource($t, $text)], $language, $user, $request, '', $questions));
                $rows[] = [$t['key'], self::cells(NotebookAsk::onlySent($answer, [(string) $t['key']])[0], \count($questions))];
            }
            $result = self::table($questions, $rows, \is_string($language) && $language !== '' ? $language : $this->defaultLanguage);
            $dropped = 0;
        } else {
            if ($task === 'check') {
                $sources = $plan['whole'] ? self::numbered($plan['texts']) : $this->checkPassages($plan['texts'], $notebook, $plan['page'], $budget - mb_strlen($plan['page']), $call, $user, $request);
                // No passage holds the page's words: nothing to check against, no request
                if ($sources === []) {
                    $notices[] = t('studio.nothing');
                }
            } elseif ($plan['whole']) {
                $sources = self::numbered($plan['texts']);
            } else {
                $sources = $this->condensed($plan['texts'], $notebook, $budget, $language, $call, $user, $request);
            }
            if ($sources === []) {
                [$result, $dropped] = ['', 0];
            } else {
                $answer = $call(fn (): array => $this->assistant->runStudio($action, $title, $sources, $language, $user, $request, $task === 'check' ? $plan['page'] : '', []));
                // A template goes into reports: no citation in it at all
                [$result, $dropped] = NotebookAsk::onlySent($answer, $task === 'template' ? [] : $sent);
                // Taken out on purpose there, not as a source the model was not given
                $dropped = $task === 'template' ? 0 : $dropped;
            }
        }

        return [
            'task' => $task,
            'result' => $result,
            'template' => $task === 'template' && $result !== '' ? TemplateDraft::parse($result) : null,
            'notices' => $notices,
            'sources' => array_values(array_map(static fn (array $t): array => ['key' => (string) $t['key'], 'title' => $t['title'], 'path' => $t['path']], array_filter($plan['texts'], static fn (array $t): bool => $t['key'] !== null))),
            'whole' => $plan['whole'],
            'requests' => $step,
            'dropped' => $dropped,
            'ms' => $ms,
            'provider' => (string) $last['provider'],
            'context' => $last['contextSet'],
        ];
    }

    /** @param list<array{text: string}> $texts */
    private static function length(array $texts): int
    {
        return array_sum(array_map(static fn (array $t): int => mb_strlen($t['text']), $texts));
    }

    /**
     * The texts that must be condensed when $texts go as notes: each text's share is the budget
     * over all their parts; a text no longer than its share goes as it is
     *
     * @param list<array{key: ?string, path: string, title: string, text: string}> $texts
     *
     * @return list<array{index: int, part: string, of: int, n: int}> one per request, in order
     */
    private static function condensing(array $texts, int $budget): array
    {
        $parts = [];
        foreach ($texts as $i => $t) {
            $parts[$i] = SourceChunks::split($t['text'], $budget);
        }
        $noteChars = SourceAnalysis::shares($budget, max(1, array_sum(array_map('count', $parts))))['noteChars'];
        $out = [];
        foreach ($parts as $i => $list) {
            if (\count($list) === 1 && mb_strlen($list[0]) <= $noteChars) {
                continue;
            }
            foreach ($list as $k => $part) {
                $out[] = ['index' => $i, 'part' => $part, 'of' => \count($list), 'n' => $k + 1];
            }
        }

        return $out;
    }

    /**
     * Every text as notes (condensing()), each text one numbered source block
     *
     * @param list<array{key: ?string, path: string, title: string, text: string}> $texts
     * @param \Closure(\Closure): string $call
     *
     * @return list<array{n: int, key: ?string, title: string, page: ?int, text: string}>
     */
    private function condensed(array $texts, PageRecord $notebook, int $budget, mixed $language, \Closure $call, User $user, ?Request $request): array
    {
        $parts = [];
        foreach ($texts as $i => $t) {
            $parts[$i] = SourceChunks::split($t['text'], $budget);
        }
        ['words' => $words, 'noteChars' => $noteChars] = SourceAnalysis::shares($budget, max(1, array_sum(array_map('count', $parts))));
        $partAction = $this->partAction($notebook);
        $notes = [];
        foreach (self::condensing($texts, $budget) as $c) {
            $t = $texts[$c['index']];
            $notes[$c['index']][] = mb_substr($call(fn (): array => $this->assistant->runSource($partAction, $this->pageOf($t, $notebook), $c['part'], 'source part ' . $c['n'] . ' of ' . $c['of'], $c['n'] . ' of ' . $c['of'], $words, $user, $request, $language)), 0, $noteChars);
        }
        $out = [];
        foreach ($texts as $i => $t) {
            $out[] = ['n' => \count($out) + 1] + $this->asSource($t, isset($notes[$i]) ? implode("\n\n", $notes[$i]) : $t['text']);
        }

        return $out;
    }

    /**
     * One source's text for its own request: whole when it fits, else its parts' notes
     *
     * @param array{key: ?string, path: string, title: string, text: string} $t
     * @param \Closure(\Closure): string $call
     */
    private function notesOf(array $t, PageRecord $notebook, int $budget, mixed $language, \Closure $call, User $user, ?Request $request): string
    {
        $parts = SourceChunks::split($t['text'], $budget);
        if (\count($parts) <= 1) {
            return $t['text'];
        }
        ['words' => $words, 'noteChars' => $noteChars] = SourceAnalysis::shares($budget, \count($parts));
        $partAction = $this->partAction($notebook);
        $notes = [];
        foreach ($parts as $k => $part) {
            $of = ($k + 1) . ' of ' . \count($parts);
            $notes[] = mb_substr($call(fn (): array => $this->assistant->runSource($partAction, $this->pageOf($t, $notebook), $part, 'source part ' . $of, $of, $words, $user, $request, $language)), 0, $noteChars);
        }

        return implode("\n\n", $notes);
    }

    /**
     * A check's sources when they do not fit beside the page: the passages holding the page's
     * words, its headings' and those `notebook-terms` adds (the sources are mostly English, the
     * notebooks Romanian), and those nearest in meaning to its title and headings (PassageSearch)
     *
     * @param list<array{key: ?string, path: string, title: string, text: string}> $texts
     * @param \Closure(\Closure): string $call
     *
     * @return list<array{n: int, key: ?string, title: string, page: ?int, text: string}>
     */
    private function checkPassages(array $texts, PageRecord $notebook, string $page, int $budget, \Closure $call, User $user, ?Request $request): array
    {
        preg_match_all('/^#{1,6}[ \t]+(.+)$/mu', $page, $m);
        $subject = trim(PageTitle::of($notebook->frontmatter, $notebook->body) . "\n" . implode("\n", $m[1]));
        $terms = [...RecallTerms::frequent($page, 5, 30), ...RecallTerms::ofLine($subject, 24)];
        $expand = $this->actions->special($notebook->path, 'notebook-terms');
        if ($expand !== null) {
            try {
                $terms = [...$terms, ...AskExpansion::parse($call(fn (): array => $this->assistant->runNotebookTerms($expand, mb_substr($subject, 0, 600), $user, $request)))['terms']];
            } catch (AiException) {
                // The page's own words then
            }
        }
        $out = [];
        $found = $this->notebooks->search()->choose($texts, array_values(array_unique($terms)), $budget, $this->assistant->safeQuestion(mb_substr($subject, 0, 600)), $user);
        foreach ($found['passages'] as $p) {
            $out[] = ['n' => \count($out) + 1, 'key' => $p['key'], 'title' => $p['title'], 'page' => $p['page'], 'text' => $p['text']];
        }

        return $out;
    }

    /**
     * @param list<array{key: ?string, path: string, title: string, text: string}> $texts
     *
     * @return list<array{n: int, key: ?string, title: string, page: ?int, text: string}>
     */
    private static function numbered(array $texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            $out[] = ['n' => \count($out) + 1, 'key' => $t['key'], 'title' => $t['title'], 'page' => null, 'text' => $t['text']];
        }

        return $out;
    }

    /**
     * @param array{key: ?string, path: string, title: string, text: string} $t
     *
     * @return array{key: ?string, title: string, page: null, text: string}
     */
    private function asSource(array $t, string $text): array
    {
        return ['key' => $t['key'], 'title' => $t['title'], 'page' => null, 'text' => $text];
    }

    private function partAction(PageRecord $notebook): Action
    {
        $action = $this->actions->special($notebook->path, 'source-part');
        if ($action === null) {
            throw new AiException('not_configured', 'No prompt page for condensing a long source');
        }

        return $action;
    }

    /**
     * The page a text's part is condensed as (Context::source() reads its title and record): the
     * source's own, or the notebook for its own text
     *
     * @param array{key: ?string, path: string, title: string, text: string, record?: PageRecord} $t
     */
    private function pageOf(array $t, PageRecord $notebook): PageRecord
    {
        return $t['record'] ?? $notebook;
    }

    /**
     * One row's answers, one per question: the model writes `1. answer` lines (a line it did
     * not number continues the one before)
     *
     * @return list<string> exactly $count cells
     */
    public static function cells(string $answer, int $count): array
    {
        $cells = [];
        $at = null;
        foreach (preg_split('/\R/u', $answer) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^(?:[-*]\s*)?\**(\d{1,2})[.)]\**\s*(.*)$/u', $line, $m) === 1) {
                // A number past the questions ends the row
                $at = (int) $m[1] >= 1 && (int) $m[1] <= $count ? (int) $m[1] - 1 : null;
                if ($at !== null) {
                    $cells[$at] = trim($m[2]);
                }
            } elseif ($at !== null && $line !== '') {
                $cells[$at] = trim($cells[$at] . ' ' . $line);
            }
        }
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $cell = trim(str_replace('|', '/', $cells[$i] ?? ''));
            $out[] = $cell !== '' ? $cell : '–';
        }

        return $out;
    }

    /**
     * The comparison as a markdown table: a row per source, citing it, a column per question; the
     * first column's heading in the page's `language` (Romanian when none)
     *
     * @param list<string>                              $questions
     * @param list<array{0: ?string, 1: list<string>}> $rows
     */
    public static function table(array $questions, array $rows, mixed $language = null): string
    {
        $head = array_map(static fn (string $q): string => str_replace('|', '/', $q), $questions);
        // Page content: the heading in the page's language, as the References heading
        $source = t(\is_string($language) && strtolower(substr(trim($language), 0, 2)) === 'en' ? 'studio.col_source_en' : 'studio.col_source_ro');
        $lines = ['| ' . $source . ' | ' . implode(' | ', $head) . ' |', '|' . str_repeat(' --- |', \count($questions) + 1)];
        foreach ($rows as [$key, $cells]) {
            $lines[] = '| [@' . $key . '] | ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }
}
