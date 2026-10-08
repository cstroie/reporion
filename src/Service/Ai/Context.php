<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\IndexInterface;
use Reporion\Service\Checklists;
use Reporion\Service\PatientStudies;
use Reporion\Service\TagDictionary;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Conclusion;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
use Reporion\Support\ReportPath;
use Throwable;

/**
 * The one place a prompt is put together (D15). An action's placeholders
 * are filled from the report and what it points to — its template, its
 * first prior, examples — each only if the caller can read it (invariant
 * 6), each de-identified (Redactor), and the result checked once more
 * before it may leave: an identifier still in it and nothing is sent
 * (`identifier_leak`, invariant 8). No frontmatter is ever sent — not the
 * report's, not a template's, prior's or prompt page's: pages go in by
 * their body, and every text is stripped of any `---` block besides.
 *
 * Placeholders (DokuLLM's, and the report's details): {text} {template} {checklist}
 * {previous} {previous_date} (the first of `priors`, else — phase 34c — the
 * patient's latest earlier report of the same modality and region) {current_date} {current_time} {snippets}
 * {examples} {exam} {modality} {region} {age} {sex} {prompt} {action}
 * {history} — the patient's other reports the caller can read, the latest
 * HISTORY_MAX, oldest first, each with its date and exam (2026-10-07, the
 * timeline's `evolution` prompt). With none, `evolution` is not sent at
 * all: its answer is NO_HISTORY, said here (2026-10-08).
 * {language} — the language reports are written in (D26), for prompts
 * ported from DokuLLM.
 * {vocabulary} — the tag dictionary's tags (Admin → Tags), comma separated,
 * for the `tags` prompt to choose from (2026-10-08).
 *
 * Text that is data — the report, a template, a prior, an example — has its
 * `<` and `>` escaped (`&lt;` `&gt;`) before it goes in (2026-10-08): a page
 * that is itself a prompt, or a report with `<raport>` in it, cannot open
 * or close the prompt's own blocks. Assistant turns them back in the
 * answer. Placeholders are filled in one pass: a `{language}` inside a
 * report is never replaced.
 *
 * Every user message about a report starts with the patient header — age and sex, the
 * indication, the exam in front — whatever the prompt page asks for. Never
 * the name, not even its initials (D1).
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
    ) {
    }

    /**
     * @param string $text      what the editor sends: the selection, the exam in front, or the text
     * @param string $textLabel what that is, for "Context sent" ("exam 2", "selection", "text")
     * @param ?int   $exam      the exam in front on a multi-exam report (1-based), for {exam}
     *
     * @throws AiException identifier_leak
     */
    public function build(Action $action, PageRecord $page, string $text, ?User $principal, string $textLabel = 'text', ?int $exam = null, string $customPrompt = ''): Prompt
    {
        $fm = $page->frontmatter;
        $redactor = new Redactor();
        $redactor->learn($fm, $page->path);
        $contextSet = [$textLabel];

        $vars = [
            'text' => $redactor->redact($text, $fm),
            'action' => $action->id,
            'current_date' => MetaText::date($fm['study_date'] ?? null, 'Y-m-d') ?: date('Y-m-d'),
            'current_time' => date('H:i'),
            'language' => self::LANGUAGE,
            'exam' => $this->exam($fm, $exam),
            'modality' => implode(', ', array_map('strval', (array) ($fm['modality'] ?? []))),
            'region' => implode(', ', array_map('strval', (array) ($fm['region'] ?? []))),
            'age' => $this->age($fm),
            'sex' => match (\is_array($fm['patient'] ?? null) ? ($fm['patient']['sex'] ?? '') : '') {
                'F' => 'feminin',
                'M' => 'masculin',
                default => '',
            },
        ];

        $wants = static fn (string $name): bool => str_contains($action->prompt, '{' . $name . '}') || str_contains($action->system, '{' . $name . '}');

        $vars['template'] = '( fără șablon )';
        if ($wants('template') && ($template = $this->readable(MetaText::text($fm['template'] ?? null), $principal)) !== null) {
            $redactor->learn($template->frontmatter, $template->path);
            $vars['template'] = $redactor->redact($template->body, $template->frontmatter);
            $contextSet[] = 'template';
        }

        // Phase 26: the exam in front's template checklist — a template's words, no patient in them
        $vars['checklist'] = '( fără listă de verificare )';
        if ($wants('checklist')) {
            $list = (new Checklists($this->storage, $this->index))->textFor($fm, max(0, ($exam ?? 1) - 1), $principal);
            if ($list !== '') {
                $vars['checklist'] = $redactor->redact($list);
                $contextSet[] = 'checklist';
            }
        }

        $vars['previous'] = '( fără examinare anterioară )';
        $vars['previous_date'] = '';
        if ($wants('previous')) {
            foreach ((array) ($fm['priors'] ?? []) as $priorPath) {
                $prior = \is_string($priorPath) ? $this->readable($priorPath, $principal) : null;
                if ($prior !== null) {
                    $redactor->learn($prior->frontmatter, $prior->path);
                    $vars['previous'] = $redactor->redact($prior->body, $prior->frontmatter);
                    $vars['previous_date'] = MetaText::date($prior->frontmatter['study_date'] ?? null, 'Y-m-d');
                    $contextSet[] = 'prior';
                    break;
                }
            }
            // No prior named in `priors`: the patient's latest earlier report of
            // the same modality and region the caller can read (phase 34c) — said
            // as `prior (auto)`, so the rail shows which kind was used
            if (!\in_array('prior', $contextSet, true) && ($prior = $this->autoPrior($page, $principal)) !== null) {
                $redactor->learn($prior->frontmatter, $prior->path);
                $vars['previous'] = $redactor->redact($prior->body, $prior->frontmatter);
                $vars['previous_date'] = MetaText::date($prior->frontmatter['study_date'] ?? null, 'Y-m-d');
                $contextSet[] = 'prior (auto)';
            }
        }

        $vars['history'] = '( fără examinări anterioare )';
        if ($wants('history')) {
            $blocks = $this->history($page, $principal, $redactor);
            if ($blocks !== []) {
                $vars['history'] = implode("\n", $blocks);
                $contextSet[] = \count($blocks) . ' priors';
            } elseif ($action->id === 'evolution') {
                // One study is no evolution: nothing to ask a model
                return new Prompt('', '', [$textLabel, 'no priors'], $action->model, self::NO_HISTORY, $action->maxTokens);
            }
        }

        $vars['examples'] = '( fără exemple )';
        if ($wants('examples')) {
            $blocks = [];
            foreach ((array) ($fm['ai_examples'] ?? []) as $examplePath) {
                $example = \is_string($examplePath) ? $this->readable($examplePath, $principal) : null;
                if ($example !== null) {
                    $redactor->learn($example->frontmatter, $example->path);
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

        $vars['vocabulary'] = '( fără vocabular )';
        if ($wants('vocabulary') && ($terms = array_keys($this->tags?->all() ?? [])) !== []) {
            $vars['vocabulary'] = implode(', ', array_map('strval', $terms));
            $contextSet[] = \count($terms) . ' vocabulary tags';
        }

        $vars['prompt'] = $redactor->redact($customPrompt);

        // A report's header only: a poem or a how-to has no patient and no exam
        $header = ReportPath::isReport($page->path) ? $this->header($fm, $vars, $redactor) : '';
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

        return new Prompt($system, $user, $contextSet, $action->model, maxTokens: $action->maxTokens);
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
    private const UNESCAPED = ['history', 'examples', 'snippets', 'prompt'];

    /** @param array<string, string> $vars */
    private static function fill(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => \in_array($m[1], self::UNESCAPED, true) ? ($vars[$m[1]] ?? '') : self::escape($vars[$m[1]] ?? ''), $template);
    }

    /**
     * The fixed one-line request Admin → AI's *Test* sends to each alias of a
     * server (phase 33c): no page, no patient, nothing of the archive — made
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
     * its date and exam — never its path, accession or name
     *
     * @return list<string>
     */
    private function history(PageRecord $page, ?User $principal, Redactor $redactor): array
    {
        $row = $this->index->findByPath($page->path, $principal);
        if ($row === null) {
            return [];
        }
        $others = [];
        foreach ((new PatientStudies($this->index))->forRow($row, $principal) as $study) {
            $other = (string) ($study['path'] ?? '') !== $page->path ? $this->readable((string) $study['path'], $principal) : null;
            if ($other !== null) {
                $others[] = $other;
            }
        }
        $date = static fn (PageRecord $r): string => MetaText::date($r->frontmatter['study_date'] ?? null, 'Y-m-d');
        usort($others, static fn (PageRecord $a, PageRecord $b): int => $date($b) <=> $date($a));
        $others = array_reverse(\array_slice($others, 0, self::HISTORY_MAX));
        $blocks = [];
        foreach ($others as $other) {
            $redactor->learn($other->frontmatter, $other->path);
        }
        foreach ($others as $other) {
            $blocks[] = '<report date="' . MetaText::date($other->frontmatter['study_date'] ?? null, 'Y-m-d') . '" exam="'
                . self::escape(str_replace('"', "'", $redactor->redact(ReportName::examTitle($other->frontmatter), $other->frontmatter))) . '">' . "\n"
                . self::escape($redactor->redact(Redactor::withoutFrontmatter($other->body), $other->frontmatter)) . "\n</report>";
        }

        return $blocks;
    }

    /**
     * The prior {previous} takes when `priors` names none (phase 34c): among
     * the patient's other reports the caller can read (PatientStudies, the
     * timeline's lookup), the latest one before this report's study date
     * that shares a modality with it and — when this report names regions —
     * a region; null when none does
     */
    private function autoPrior(PageRecord $page, ?User $principal): ?PageRecord
    {
        $row = $this->index->findByPath($page->path, $principal);
        if ($row === null) {
            return null;
        }
        $studies = (new PatientStudies($this->index))->forRow($row, $principal);
        $list = static fn (mixed $v): array => array_values(array_filter(array_map(
            static fn (string $s): string => mb_strtolower(trim($s)),
            \is_array($v) ? array_map('strval', $v) : explode(',', (string) $v),
        ), static fn (string $s): bool => $s !== ''));
        $own = null;
        foreach ($studies as $study) {
            if ((string) $study['path'] === $page->path) {
                $own = $study;
            }
        }
        $date = MetaText::date($page->frontmatter['study_date'] ?? ($own['study_date'] ?? null), 'Y-m-d');
        $modalities = $list($own['modality'] ?? ($page->frontmatter['modality'] ?? []));
        $regions = $list($own['region'] ?? ($page->frontmatter['region'] ?? []));
        if ($date === '' || $modalities === []) {
            return null;
        }
        // Latest first (as the index gives them, sorted again to be sure)
        usort($studies, static fn (array $a, array $b): int => strcmp((string) ($b['study_date'] ?? ''), (string) ($a['study_date'] ?? '')));
        foreach ($studies as $study) {
            $when = MetaText::date($study['study_date'] ?? null, 'Y-m-d');
            if ((string) $study['path'] === $page->path || $when === '' || $when >= $date) {
                continue;
            }
            if (array_intersect($modalities, $list($study['modality'] ?? '')) === []) {
                continue;
            }
            if ($regions !== [] && array_intersect($regions, $list($study['region'] ?? '')) === []) {
                continue;
            }
            $prior = $this->readable((string) $study['path'], $principal);
            if ($prior !== null) {
                return $prior;
            }
        }

        return null;
    }

    /** A page the caller can read, read from disk; null otherwise */
    private function readable(string $path, ?User $principal): ?PageRecord
    {
        if ($path === '' || $this->index->findByPath($path, $principal) === null) {
            return null;
        }
        try {
            return $this->storage->read($path);
        } catch (Throwable) {
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
     * heading — de-identified like a prompt, with the exam title in front.
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
        $redactor->learn($fm, $page->path);
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
