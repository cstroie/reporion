<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\IndexInterface;
use Reporion\Service\Checklists;
use Reporion\Service\PriorStudy;
use Reporion\Service\TagDictionary;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Conclusion;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;
use RuntimeException;

/**
 * The one place a prompt is put together (D15). An action's placeholders
 * are filled from the report and what it points to: its template, its
 * first prior, examples: each only if the caller can read it (invariant
 * 6), each de-identified (Redactor), and the result checked once more
 * before it may leave: an identifier still in it and nothing is sent
 * (`identifier_leak`, invariant 8). No frontmatter is ever sent, not the
 * report's, not a template's, prior's or prompt page's: pages go in by
 * their body, and every text is stripped of any `---` block besides.
 *
 * Placeholders (DokuLLM's, and the report's details): {text} {template} {checklist}
 * {previous} {previous_date} (the first of `priors`, else: phase 34c: the
 * patient's latest earlier report of the same modality and region) {current_date} {current_time} {snippets}
 * {examples} {exam} {modality} {region} {age} {sex} {prompt} {action}
 * {history}: the patient's other reports the caller can read, the latest
 * HISTORY_MAX, oldest first, each with its date and exam (2026-10-07, the
 * timeline's `evolution` prompt). With none, `evolution` is not sent at
 * all: its answer is NO_HISTORY, said here (2026-10-08). With a `$with`
 * pid (the report-vs-prior compare, phase 17a) only that one study, and
 * only when it is among them: another patient's or an unreadable one
 * counts as none.
 * {language}: the language reports are written in (D26), for prompts
 * ported from DokuLLM.
 * {vocabulary}: the tag dictionary's tags (Admin → Tags), comma separated,
 * for the `tags` prompt to choose from (2026-10-08).
 * {diff}: the same text as {text}, by the name the `commit` prompt uses:
 * the change a Save is about to write (Support\CommitDiff, 2026-10-10).
 * {recall}: the passages of finished reports the caller can read that
 * describe the line in {text}, their own first, never this patient's,
 * de-identified (Service\Recall, D50: the `recall-expand` and
 * `recall-missing` prompts, 2026-10-18).
 *
 * Text that is data: the report, a template, a prior, an example: has its
 * `<` and `>` escaped (`&lt;` `&gt;`) before it goes in (2026-10-08): a page
 * that is itself a prompt, or a report with `<report>` in it, cannot open
 * or close the prompt's own blocks. Assistant turns them back in the
 * answer. Placeholders are filled in one pass: a `{language}` inside a
 * report is never replaced.
 *
 * Every user message about a report starts with the patient header: age and sex, the
 * indication, the exam in front: whatever the prompt page asks for. Never
 * the name, not even its initials (D1). Not `commit`'s: a revision note is
 * about the change, and its small model is better without (2026-10-10).
 */
final class Context
{
    /** The most of the patient's other reports {history} takes, the latest */
    public const HISTORY_MAX = 8;

    /** The language of report content (D26), for {language} */
    public const LANGUAGE = 'Romanian';

    /** `evolution`'s answer when the patient has no other report to compare with */
    public const NO_HISTORY = 'Date imagistice insuficiente pentru evaluarea evoluției.';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly ?Examples $examples = null,
        private readonly ?TagDictionary $tags = null,
        /** {recall}: passages for one line (Service\Recall, D50) */
        private readonly ?Examples $recall = null,
    ) {
    }

    /**
     * @param string $text      what the editor sends: the selection, the exam in front, or the text
     * @param string $textLabel what that is, for "Context sent" ("exam 2", "selection", "text")
     * @param ?int   $exam      the exam in front on a multi-exam report (1-based), for {exam}
     * @param ?string $with     narrows {history} to this one study's pid
     *
     * @throws AiException identifier_leak
     */
    public function build(Action $action, PageRecord $page, string $text, ?User $principal, string $textLabel = 'text', ?int $exam = null, string $customPrompt = '', ?string $with = null): Prompt
    {
        $fm = $page->frontmatter;
        $redactor = new Redactor();
        $redactor->learn($fm, $page->path, $page->body);
        $contextSet = [$textLabel];

        // The exam in front: its own template and region. The modality and the day are the report's:
        // every exam of a report shares them (Support\Exams)
        $all = Exams::of($fm);
        $own = $all[max(0, ($exam ?? 1) - 1)] ?? $all[0];
        $shared = Exams::report($fm);
        $examValue = static fn (string $key): mixed => $own[$key] ?? null;

        $vars = [
            'text' => $redactor->redact($text, $fm),
            'action' => $action->id,
            'current_date' => $shared['date'] ?: date('Y-m-d'),
            'current_time' => date('H:i'),
            'language' => self::LANGUAGE,
            'exam' => $this->exam($fm, $exam),
            'modality' => implode(', ', $shared['modality']),
            'region' => implode(', ', array_map('strval', (array) ($examValue('region') ?? []))),
            'age' => $this->age($fm),
            'sex' => match (\is_array($fm['patient'] ?? null) ? ($fm['patient']['sex'] ?? '') : '') {
                'F' => 'feminin',
                'M' => 'masculin',
                default => '',
            },
        ];

        $wants = static fn (string $name): bool => str_contains($action->prompt, '{' . $name . '}') || str_contains($action->system, '{' . $name . '}');

        $vars['template'] = '( fără șablon )';
        if ($wants('template') && ($template = $this->readable(MetaText::text($examValue('template')), $principal)) !== null) {
            $redactor->learn($template->frontmatter, $template->path);
            $vars['template'] = $redactor->redact($template->body, $template->frontmatter);
            $contextSet[] = 'template';
        }

        // Phase 26: the exam in front's template checklist: a template's words, no patient in them
        $vars['checklist'] = '( fără listă de verificare )';
        if ($wants('checklist')) {
            $list = (new Checklists($this->storage, $this->index))->textFor($fm, max(0, ($exam ?? 1) - 1), $principal);
            if ($list !== '') {
                $vars['checklist'] = $redactor->redact($list);
                $contextSet[] = 'checklist';
            }
        }

        // The patient's other studies, asked of the index once for {previous} and {history} both
        $studies = null;
        $patientStudies = function () use (&$studies, $page, $principal): array {
            return $studies ??= $this->patientStudies($page, $principal);
        };

        $vars['previous'] = '( fără examinare anterioară )';
        $vars['previous_date'] = '';
        if ($wants('previous')) {
            $priors = new PriorStudy($this->storage, $this->index);
            $prior = $priors->named($page, $principal);
            $label = 'prior';
            // No prior named in `priors`: the patient's latest earlier report of
            // the same modality and region the caller can read (phase 34c): said
            // as `prior (auto)`, so the rail shows which kind was used
            if ($prior === null) {
                $prior = $priors->auto($page, $patientStudies(), $own);
                $label = 'prior (auto)';
            }
            if ($prior !== null) {
                $redactor->learn($prior->frontmatter, $prior->path, $prior->body);
                $vars['previous'] = $redactor->redact($prior->body, $prior->frontmatter);
                $vars['previous_date'] = MetaText::date($prior->frontmatter['study_date'] ?? null, 'Y-m-d');
                $contextSet[] = $label;
            }
        }

        $vars['history'] = '( fără examinări anterioare )';
        if ($wants('history')) {
            $blocks = $this->history($page, $patientStudies(), $redactor, $with);
            if ($blocks !== []) {
                $vars['history'] = implode("\n", $blocks);
                $contextSet[] = \count($blocks) . ' priors';
            } elseif ($action->id === 'evolution') {
                // One study is no evolution: nothing to ask a model
                return new Prompt('', '', [$textLabel, 'no priors'], $action->model, self::NO_HISTORY, $action->maxTokens, $action->timeout);
            }
        }

        $vars['examples'] = '( fără exemple )';
        if ($wants('examples')) {
            $blocks = [];
            foreach ((array) ($fm['ai_examples'] ?? []) as $examplePath) {
                $example = \is_string($examplePath) ? $this->readable($examplePath, $principal) : null;
                if ($example !== null) {
                    $redactor->learn($example->frontmatter, $example->path, $example->body);
                    $blocks[] = '<exemplu id="' . (\count($blocks) + 1) . '">' . "\n" . self::escape($redactor->redact($example->body, $example->frontmatter)) . "\n</exemplu>";
                }
            }
            if ($blocks !== []) {
                $vars['examples'] = implode("\n", $blocks);
                $contextSet[] = \count($blocks) . ' examples';
            }
        }

        $vars['snippets'] = '( fără exemple )';
        if ($wants('snippets') && $this->examples !== null) {
            $snippets = $this->examples->for($page, $text, $principal, $redactor);
            if ($snippets !== []) {
                $vars['snippets'] = implode("\n", array_map(static fn (string $s, int $i): string => '<exemplu id="' . ($i + 1) . '">' . "\n" . self::escape($s) . "\n</exemplu>", $snippets, array_keys($snippets)));
                $contextSet[] = \count($snippets) . ' snippets';
            }
        }

        // D50: the passages that describe the line in {text}, the doctor's own first
        $vars['recall'] = '( fără pasaje )';
        if ($wants('recall') && $this->recall !== null) {
            $passages = $this->recall->for($page, $text, $principal, $redactor);
            if ($passages !== []) {
                $vars['recall'] = implode("\n", array_map(static fn (string $s, int $i): string => '<exemplu id="' . ($i + 1) . '">' . "\n" . self::escape($s) . "\n</exemplu>", $passages, array_keys($passages)));
                $contextSet[] = \count($passages) . ' recalled';
            }
        }

        $vars['vocabulary'] = '( fără vocabular )';
        if ($wants('vocabulary') && ($terms = array_keys($this->tags?->all() ?? [])) !== []) {
            $vars['vocabulary'] = implode(', ', array_map('strval', $terms));
            $contextSet[] = \count($terms) . ' vocabulary tags';
        }

        $vars['prompt'] = $redactor->redact($customPrompt);
        $vars['diff'] = $vars['text'];

        // A report's header only: a poem or a how-to has no patient and no exam
        $header = ReportPath::isReport($page->path) && $action->id !== 'commit' ? $this->header($fm, $vars, $redactor) : '';
        if ($header !== '') {
            $contextSet[] = 'patient details';
        }

        // A prompt page's body carries no frontmatter; a pasted-in block goes too
        $system = $redactor->redact(self::fill(Redactor::withoutFrontmatter($action->system), $vars));
        $user = $header . self::fill(Redactor::withoutFrontmatter($action->prompt), $vars);
        // The final guard: nothing identifying leaves, whatever the prompt pages say
        if ($redactor->leaks($system . "\n" . $user)) {
            throw new AiException('identifier_leak', 'The prompt still held a patient identifier; nothing was sent');
        }
        $contextSet[] = 'no patient identifiers';

        return new Prompt($system, $user, $contextSet, $action->model, maxTokens: $action->maxTokens, timeout: $action->timeout);
    }

    /**
     * "patient: 46y, female / indication: … / exam: …", each line only when known, then a blank line
     *
     * @param array<string, mixed>  $fm
     * @param array<string, string> $vars
     */
    private function header(array $fm, array $vars, Redactor $redactor): string
    {
        $sex = match (\is_array($fm['patient'] ?? null) ? ($fm['patient']['sex'] ?? '') : '') {
            'F' => 'female',
            'M' => 'male',
            default => '',
        };
        $patient = implode(', ', array_filter([$vars['age'] !== '' ? $vars['age'] . 'y' : '', $sex], static fn (string $s): bool => $s !== ''));
        $indication = trim((string) preg_replace('/\s+/u', ' ', $redactor->redact(Redactor::withoutFrontmatter(MetaText::text($fm['indication'] ?? null)), $fm)));
        $lines = array_filter([
            'patient' => $patient,
            'indication' => $indication,
            'exam' => $vars['exam'],
        ], static fn (string $s): bool => $s !== '');

        return $lines === [] ? '' : implode("\n", array_map(static fn (string $k, string $v): string => $k . ': ' . self::escape($v), array_keys($lines), $lines)) . "\n\n";
    }

    /** Placeholders whose value Context wraps in its own tags (each body escaped there), or the user's own words */
    private const UNESCAPED = ['history', 'examples', 'snippets', 'recall', 'prompt', 'question', 'sources'];

    /** @param array<string, string> $vars */
    private static function fill(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => \in_array($m[1], self::UNESCAPED, true) ? ($vars[$m[1]] ?? '') : self::escape($vars[$m[1]] ?? ''), $template);
    }

    /**
     * tags:review's prompt (2026-10-15): the tag vocabulary, no report.
     * {tags} is one tag per line with its page count, {vocabulary} the
     * dictionary's tags, {language} as ever. A tag is data typed by people
     * and models, so one that may name a patient never goes: a word of it
     * is a name word of a report's path (D1: `{yymmdd}-{name}`), or the
     * Redactor finds an identifier's shape in it (a CNP, an accession, a
     * report page name). Those are left out and counted in the context set
     * (`N tags withheld`); nothing about them is sent.
     *
     * @param array<string, int> $counts tag → pages, the tags to send
     *
     * @throws AiException identifier_leak
     */
    public function vocabulary(Action $action, array $counts): Prompt
    {
        $names = $this->patientWords();
        $redactor = new Redactor();
        $lines = [];
        $withheld = 0;
        foreach ($counts as $tag => $n) {
            $tag = (string) $tag;
            $words = array_map(Redactor::fold(...), preg_split('/[^\p{L}]+/u', $tag, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if (array_intersect_key(array_flip($words), $names) !== [] || $redactor->redact($tag) !== $tag) {
                ++$withheld;
                continue;
            }
            $lines[] = $tag . ' (' . $n . ')';
        }
        $terms = array_keys($this->tags?->all() ?? []);
        $vars = [
            'tags' => implode("\n", $lines),
            'vocabulary' => $terms !== [] ? implode(', ', array_map('strval', $terms)) : '( fără vocabular )',
            'language' => self::LANGUAGE,
            'action' => $action->id,
        ];
        $contextSet = [\count($lines) . ' tags'];
        if ($withheld > 0) {
            $contextSet[] = $withheld . ' tags withheld';
        }
        $system = self::fill(Redactor::withoutFrontmatter($action->system), $vars);
        $user = self::fill(Redactor::withoutFrontmatter($action->prompt), $vars);
        if ($redactor->redact($vars['tags']) !== $vars['tags']) {
            throw new AiException('identifier_leak', 'The prompt still held a patient identifier; nothing was sent');
        }
        $contextSet[] = 'no patient identifiers';

        return new Prompt($system, $user, $contextSet, $action->model, maxTokens: $action->maxTokens, timeout: $action->timeout);
    }

    /**
     * Ask the archive's request (D53, 2026-10-19): a question and the
     * passages Service\ArchiveAsk found for it, about no one report.
     * {question} is the question; {sources} the passages as
     * `<source id="n" date="YYYY-MM-DD">` blocks, the numbers the answer
     * cites and the screen links. Each passage is de-identified by its own
     * report (Redactor::learn(), as {recall}); the question by all of them,
     * and a word of it that is a name word of a report's path (D1) goes as
     * `[nume]`: a question may name a patient, the prompt never does. The
     * final guard as ever: an identifier left and nothing is sent.
     *
     * @param list<array{n: int, path: string, text: string, study_date: ?string}> $sources
     *
     * @throws AiException identifier_leak
     */
    public function archive(Action $action, string $question, array $sources): Prompt
    {
        $redactor = new Redactor();
        $blocks = [];
        foreach ($sources as $source) {
            $record = $this->study($source['path']);
            if ($record === null) {
                continue;
            }
            $redactor->learn($record->frontmatter, $record->path, $record->body);
            $text = trim($redactor->redact($source['text'], $record->frontmatter));
            if ($text === '') {
                continue;
            }
            $date = $source['study_date'] !== null ? ' date="' . self::escape($source['study_date']) . '"' : '';
            $blocks[] = '<source id="' . $source['n'] . '"' . $date . ">\n" . self::escape($text) . "\n</source>";
        }

        return $this->archivePrompt($action, $question, $redactor, $blocks);
    }

    /**
     * Ask the archive's request from passages that are no report of ours
     * (Ask HippoBridge, D38/D53): the HIS texts a plugin found, each with the
     * patient it is about. Same prompt, same guard as archive(): each
     * passage is de-identified by the name and CNP its hit carried
     * (Redactor::learn() with a synthetic patient block), which are learned
     * before anything is redacted and never reach the prompt.
     *
     * @param list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string}> $passages
     *
     * @throws AiException identifier_leak
     */
    public function archivePassages(Action $action, string $question, array $passages): Prompt
    {
        [$redactor, $clean] = self::deidentifyPassages($passages);
        $blocks = [];
        foreach ($clean as $passage) {
            $date = $passage['date'] !== null ? ' date="' . self::escape($passage['date']) . '"' : '';
            $blocks[] = '<source id="' . $passage['n'] . '"' . $date . ">\n" . self::escape($passage['text']) . "\n</source>";
        }

        return $this->archivePrompt($action, $question, $redactor, $blocks);
    }

    /**
     * The passages as the prompt gets them: every name and CNP they carry
     * learned first, then each text redacted; one left empty is dropped. Also
     * what the screen may show of them (ArchiveAnswer), so the text a
     * caller reads is the text the model read.
     *
     * @param list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string}> $passages
     *
     * @return array{0: Redactor, 1: list<array{n: int, text: string, date: ?string, names: list<string>, cnp: string}>}
     */
    public static function deidentifyPassages(array $passages): array
    {
        $redactor = new Redactor();
        foreach ($passages as $passage) {
            $redactor->learn(['patient' => ['name' => implode(' ', $passage['names']), 'cnp' => $passage['cnp']]], '');
        }
        $clean = [];
        foreach ($passages as $passage) {
            $text = trim($redactor->redact($passage['text']));
            if ($text !== '') {
                $clean[] = ['text' => $text] + $passage;
            }
        }

        return [$redactor, $clean];
    }

    /**
     * archive()'s and archivePassages()' common end: the question
     * de-identified by everything learned and by the report path words, the
     * prompt filled, the final guard
     *
     * @param list<string> $blocks
     */
    private function archivePrompt(Action $action, string $question, Redactor $redactor, array $blocks): Prompt
    {
        $names = $this->patientWords();
        $question = (string) preg_replace_callback('/\p{L}+/u', static fn (array $m): string => isset($names[Redactor::fold($m[0])]) ? '[nume]' : $m[0], $question);
        $vars = [
            'question' => self::escape(trim($redactor->redact($question))),
            'sources' => $blocks !== [] ? implode("\n", $blocks) : '( fără surse )',
            'language' => self::LANGUAGE,
            'current_date' => date('Y-m-d'),
            'action' => $action->id,
        ];
        $system = $redactor->redact(self::fill(Redactor::withoutFrontmatter($action->system), $vars));
        $user = self::fill(Redactor::withoutFrontmatter($action->prompt), $vars);
        if ($redactor->leaks($system . "\n" . $user)) {
            throw new AiException('identifier_leak', 'The prompt still held a patient identifier; nothing was sent');
        }

        return new Prompt($system, $user, [\count($blocks) . ' sources', 'no patient identifiers'], $action->model, maxTokens: $action->maxTokens, timeout: $action->timeout);
    }

    /** @var array<string, true>|null the name words of the report paths, folded */
    private ?array $patientWords = null;

    /** @return array<string, true> every name word (three letters or more) of a report's path, folded: read once */
    private function patientWords(): array
    {
        if ($this->patientWords !== null) {
            return $this->patientWords;
        }
        $words = [];
        foreach ($this->storage->allPaths() as $path) {
            if (!ReportPath::isReport($path)) {
                continue;
            }
            $segments = explode(':', $path);
            $slug = explode('-', (string) end($segments));
            array_shift($slug);
            foreach ($slug as $word) {
                if (\strlen($word) >= 3 && ctype_alpha($word)) {
                    $words[Redactor::fold($word)] = true;
                }
            }
        }

        return $this->patientWords = $words;
    }

    /**
     * The fixed one-line request Admin → AI's *Test* sends to each alias of a
     * server (phase 33c): no page, no patient, nothing of the archive: made
     * here all the same, since a Prompt comes from nowhere else (D15)
     */
    public static function probe(string $tier): Prompt
    {
        return new Prompt('You are a connection test. Reply with the single word OK.', 'Reply with OK.', ['test'], $tier);
    }

    /** Data that cannot open or close a prompt block */
    public static function escape(string $text): string
    {
        return strtr($text, ['<' => '&lt;', '>' => '&gt;']);
    }

    /**
     * The patient's other reports for {history}: the latest HISTORY_MAX the
     * caller can read, oldest first, each body de-identified and tagged with
     * its date and exam: never its path, accession or name
     *
     * @param list<array<string, mixed>> $studies patientStudies()
     *
     * @return list<string>
     */
    private function history(PageRecord $page, array $studies, Redactor $redactor, ?string $with = null): array
    {
        // The index gives them latest first: only the HISTORY_MAX kept are read from disk
        $others = [];
        foreach ($studies as $study) {
            if (\count($others) >= self::HISTORY_MAX) {
                break;
            }
            if ($with !== null && (string) ($study['pid'] ?? '') !== $with) {
                continue;
            }
            $other = (string) ($study['path'] ?? '') !== $page->path ? $this->study((string) $study['path']) : null;
            if ($other !== null) {
                $others[] = $other;
            }
        }
        $date = static fn (PageRecord $r): string => MetaText::date($r->frontmatter['study_date'] ?? null, 'Y-m-d');
        usort($others, static fn (PageRecord $a, PageRecord $b): int => $date($b) <=> $date($a));
        $others = array_reverse($others);
        $blocks = [];
        foreach ($others as $other) {
            $redactor->learn($other->frontmatter, $other->path, $other->body);
        }
        foreach ($others as $other) {
            $blocks[] = '<report date="' . MetaText::date($other->frontmatter['study_date'] ?? null, 'Y-m-d') . '" exam="'
                . self::escape(str_replace('"', "'", $redactor->redact(ReportName::examTitle($other->frontmatter), $other->frontmatter))) . '">' . "\n"
                . self::escape($redactor->redact(Redactor::withoutFrontmatter($other->body), $other->frontmatter)) . "\n</report>";
        }

        return $blocks;
    }

    /**
     * The patient's reports the caller can list (PatientStudies, the
     * timeline's lookup), latest study first; none when the index does not
     * know the page
     *
     * @return list<array<string, mixed>>
     */
    private function patientStudies(PageRecord $page, ?User $principal): array
    {
        return (new PriorStudy($this->storage, $this->index))->studies($page, $principal);
    }

    /**
     * One of patientStudies()' rows, read from disk: the listing it came from
     * already applied the caller's visibility and grants (invariant 6), a
     * stricter rule than findByPath()'s, so no second lookup
     */
    private function study(string $path): ?PageRecord
    {
        if ($path === '') {
            return null;
        }
        try {
            return $this->storage->read($path);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** A page the caller can read, read from disk; null otherwise */
    private function readable(string $path, ?User $principal): ?PageRecord
    {
        if ($path === '' || $this->index->findByPath($path, $principal) === null) {
            return null;
        }
        try {
            return $this->storage->read($path);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @param array<string, mixed> $fm */
    private function exam(array $fm, ?int $exam): string
    {
        $declared = Exams::declared($fm);
        if ($exam !== null && isset($declared[$exam - 1]) && $declared[$exam - 1]['title'] !== '') {
            return $declared[$exam - 1]['title'];
        }

        return ReportName::examTitle($fm);
    }

    /** @param array<string, mixed> $fm */
    private function age(array $fm): string
    {
        $born = \is_array($fm['patient'] ?? null) ? ($fm['patient']['born'] ?? null) : null;
        $year = MetaText::date($fm['study_date'] ?? null, 'Y');
        if (!is_numeric($born)) {
            return '';
        }
        $studyYear = ctype_digit($year) ? (int) $year : (int) (new DateTimeImmutable('now'))->format('Y');
        $age = $studyYear - (int) $born;

        return $age >= 0 && $age < 130 ? (string) $age : '';
    }

    /** The most characters of a report Similar reports embeds */
    public const EMBED_MAX = 2000;

    /**
     * What Similar reports embeds of a report (phase 34e): its conclusion
     * section(s), else its `summary`, else its text without the name
     * heading: de-identified like a prompt, with the exam title in front.
     * Null when there is nothing to embed, or an identifier is still in it
     * (nothing leaves, invariant 8).
     */
    public static function forEmbedding(PageRecord $page): ?string
    {
        return self::embeddingText($page)['text'];
    }

    /**
     * forEmbedding()'s text, and whether one was there but held back
     * because an identifier survived redaction (index:vectors counts those
     * apart from reports with nothing to embed)
     *
     * @return array{text: ?string, withheld: bool}
     */
    public static function embeddingText(PageRecord $page): array
    {
        $fm = $page->frontmatter;
        $redactor = new Redactor();
        $redactor->learn($fm, $page->path, $page->body);
        $conclusion = trim(implode("\n\n", Conclusion::texts($page->body)));
        $summary = MetaText::text($fm['summary'] ?? null);
        $text = $conclusion !== '' ? $conclusion : ($summary !== '' ? $summary : trim(ReportName::withoutNameHeading(Redactor::withoutFrontmatter($page->body), $fm)));
        if ($text === '') {
            return ['text' => null, 'withheld' => false];
        }
        $exam = ReportName::examTitle($fm);
        $text = $redactor->redact(($exam !== '' ? $exam . "\n\n" : '') . $text, $fm);
        $text = mb_substr(trim($text), 0, self::EMBED_MAX);
        if ($text === '') {
            return ['text' => null, 'withheld' => false];
        }

        return $redactor->leaks($text) ? ['text' => null, 'withheld' => true] : ['text' => $text, 'withheld' => false];
    }
}
