<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Ai;

use DateTimeImmutable;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Index\IndexInterface;
use Reporion\Service\Checklists;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\Exams;
use Reporion\Support\MetaText;
use Reporion\Support\ReportName;
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
 * {previous} {previous_date} {current_date} {current_time} {snippets}
 * {examples} {exam} {modality} {region} {age} {sex} {prompt} {action}.
 *
 * Every user message starts with the patient header — age and sex, the
 * indication, the exam in front — whatever the prompt page asks for. Never
 * the name, not even its initials (D1).
 */
final class Context
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly ?Examples $examples = null,
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
            'current_date' => MetaText::date($fm['study_date'] ?? null, 'd.m.Y') ?: date('d.m.Y'),
            'current_time' => date('H:i'),
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
                    $vars['previous_date'] = MetaText::date($prior->frontmatter['study_date'] ?? null, 'd.m.Y');
                    $contextSet[] = 'prior';
                    break;
                }
            }
        }

        $vars['examples'] = '( fără exemple )';
        if ($wants('examples')) {
            $blocks = [];
            foreach ((array) ($fm['ai_examples'] ?? []) as $examplePath) {
                $example = \is_string($examplePath) ? $this->readable($examplePath, $principal) : null;
                if ($example !== null) {
                    $redactor->learn($example->frontmatter, $example->path);
                    $blocks[] = '<exemplu id="' . (\count($blocks) + 1) . '">' . "\n" . $redactor->redact($example->body, $example->frontmatter) . "\n</exemplu>";
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
                $vars['snippets'] = implode("\n", array_map(static fn (string $s, int $i): string => '<exemplu id="' . ($i + 1) . '">' . "\n" . $s . "\n</exemplu>", $snippets, array_keys($snippets)));
                $contextSet[] = \count($snippets) . ' snippets';
            }
        }

        $vars['prompt'] = $redactor->redact($customPrompt);

        $header = $this->header($fm, $vars, $redactor);
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

        return new Prompt($system, $user, $contextSet);
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

        return $lines === [] ? '' : implode("\n", array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($lines), $lines)) . "\n\n";
    }

    /** @param array<string, string> $vars */
    private static function fill(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => $vars[$m[1]] ?? '', $template);
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
}
