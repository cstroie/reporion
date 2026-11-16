<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Workup;

use Closure;
use Reporion\Auth\User;
use Reporion\Exception\AiException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Index\Sqlite;
use Reporion\Service\Ai\Actions;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\ArchiveAsk;
use Reporion\Service\Guide\Guide;
use Reporion\Service\Library\PassageSearch;
use Reporion\Service\Library\SourceAsk;
use Reporion\Service\PriorStudy;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\AskExpansion;
use Reporion\Support\Conclusion;
use Reporion\Support\MetaText;
use Reporion\Support\RecallTerms;
use Reporion\Support\ReportName;
use Reporion\Support\SpellingVariants;

/**
 * Workup (D63, docs/plan-workup.md): the evidence for a report's conclusion, gathered in fixed
 * steps by code, then one synthesis by the model. Four blocks, each from a service that exists,
 * each under the caller's visibility (invariant 6):
 *
 * 1. `library`: library passages about the findings (PassageSearch; English search words from the
 *    library profile's `notebook-terms` prompt when there is one);
 * 2. `context`: the indication and this patient's other studies the caller can read (PriorStudy),
 *    HIS reports (D38, `archived`) among them, each with its conclusion;
 * 3. `similar`: passages of other patients' finished reports (ArchiveAsk), the caller's own first;
 * 4. `next`: the imaging guide's situations (D62, Guide::forReport()) and `protocols:` pages.
 *
 * Linked by pid and rev for reports (never the patient path, invariant 8). The synthesis
 * (`workup` prompt, Ai\Context::workup()) reads only these blocks, de-identified; it is shown,
 * never written (D8).
 */
final class Workup
{
    public const LIBRARY = 4;
    public const CONTEXT = 5;
    public const SIMILAR = 4;
    public const GUIDE = 3;
    public const PROTOCOLS = 3;

    /** The most of one passage or conclusion a block keeps */
    public const TEXT = 700;

    /** The library's characters for its passages */
    private const LIBRARY_BUDGET = 6000;

    public function __construct(
        private readonly Actions $actions,
        private readonly Assistant $assistant,
        private readonly StorageInterface $storage,
        private readonly Sqlite $index,
        private readonly PriorStudy $prior,
        private readonly ArchiveAsk $archive,
        private readonly ?PassageSearch $passages,
        private readonly ?Guide $guide,
        private readonly string $libraryNamespace = 'library',
        private readonly string $protocolsNamespace = 'protocols',
    ) {
    }

    /** Whether the synthesis can be asked: the `workup` prompt of the profile serving the report */
    public function available(string $path): bool
    {
        return $this->actions->special($path, 'workup') !== null;
    }

    /**
     * The blocks, then the synthesis. $event (a stream): `progress` {step}, `blocks`, `delta`
     * {text}. With $synthesis false, or no `workup` prompt, the blocks alone (answer null).
     *
     * @return array{blocks: array<string, mixed>, answer: ?string, notices: list<string>, context: list<string>, ms: int, provider: string}
     *
     * @throws AiException the synthesis's (busy, identifier_leak, the provider's)
     */
    public function run(PageRecord $page, User $principal, ?Request $request = null, ?Closure $event = null, bool $synthesis = true): array
    {
        $event ??= static function (string $name, array $data): void {
        };
        $notices = [];
        $blocks = $this->gather($page, $principal, $request, $notices, $event);
        $event('blocks', ['blocks' => $blocks, 'notices' => $notices]);
        $action = $synthesis ? $this->actions->special($page->path, 'workup') : null;
        if ($action === null) {
            if ($synthesis) {
                $notices[] = t('workup.no_prompt');
            }

            return ['blocks' => $blocks, 'answer' => null, 'notices' => $notices, 'context' => [], 'ms' => 0, 'provider' => ''];
        }
        $event('progress', ['step' => 'answering']);
        $done = $this->assistant->runWorkup($action, $page, self::facts($page), $blocks, $principal, $request, static function (string $piece) use ($event): void {
            $event('delta', ['text' => $piece]);
        });

        return ['blocks' => $blocks, 'answer' => trim($done['result']), 'notices' => $notices, 'context' => $done['contextSet'], 'ms' => $done['ms'], 'provider' => $done['provider']];
    }

    /**
     * The four blocks; a source that is off or finds nothing gives an empty list (and a notice when off)
     *
     * @param list<string> $notices
     *
     * @return array{library: list<array<string, mixed>>, context: array{indication: string, studies: list<array<string, mixed>>}, similar: list<array<string, mixed>>, next: array{guide: list<array<string, mixed>>, protocols: list<array<string, mixed>>}}
     */
    public function gather(PageRecord $page, User $principal, ?Request $request, array &$notices, ?Closure $event = null): array
    {
        $event ??= static function (string $name, array $data): void {
        };
        $facts = self::facts($page);
        $question = $facts['conclusion'] !== '' ? $facts['conclusion'] : $facts['findings'];

        $event('progress', ['step' => 'library']);
        $library = $this->library($question, $principal, $request, $notices);

        $event('progress', ['step' => 'context']);
        $studies = [];
        $own = [$page->pid => true];
        foreach ($this->prior->studies($page, $principal) as $row) {
            $own[(string) $row['pid']] = true;
            if ((string) $row['pid'] === $page->pid || \count($studies) >= self::CONTEXT) {
                continue;
            }
            $record = $this->read((string) $row['path']);
            if ($record === null) {
                continue;
            }
            $studies[] = [
                'pid' => $record->pid, 'rev' => $record->rev, 'path' => $record->path,
                'date' => MetaText::date($record->frontmatter['study_date'] ?? null, 'Y-m-d'),
                'exam' => ReportName::examTitle($record->frontmatter, (string) ($row['modality'] ?? '')),
                'status' => $record->status,
                'text' => self::cut(self::conclusionOf($record)),
            ];
        }

        $event('progress', ['step' => 'similar']);
        $similar = [];
        if ($question !== '') {
            $found = array_values(array_filter($this->archive->sources($question, $principal), static fn (array $s): bool => !isset($own[$s['pid']])));
            // The caller's own reports first (owner, 2026-11-16), the search's order within each
            usort($found, static fn (array $a, array $b): int => [$b['mine'], $a['n']] <=> [$a['mine'], $b['n']]);
            $seen = [];
            foreach ($found as $s) {
                if (isset($seen[$s['pid']]) || \count($similar) >= self::SIMILAR) {
                    continue;
                }
                $seen[$s['pid']] = true;
                $record = $this->read($s['path']);
                $similar[] = [
                    'pid' => $s['pid'], 'rev' => $s['rev'], 'path' => $s['path'], 'date' => $s['study_date'], 'modality' => $s['modality'], 'mine' => $s['mine'],
                    'heading' => $s['heading'], 'text' => self::cut($s['text']),
                    'conclusion' => $record !== null ? self::cut(self::conclusionOf($record)) : '',
                ];
            }
        }

        $event('progress', ['step' => 'next']);
        $guide = [];
        if ($this->guide === null || $this->guide->info() === null) {
            $notices[] = t('workup.no_guide');
        } else {
            $guide = $this->guide->forReport($facts['indication'] !== '' ? $facts['indication'] : $question, $facts['regions'], $facts['modalities'], $facts['age'], self::GUIDE);
        }
        $protocols = [];
        $terms = RecallTerms::ofLine($facts['indication'] . ' ' . $question, 8);
        // Each word on its own (the search reads every word of a term), the first words first
        $seenProtocols = [];
        foreach (\array_slice($terms, 0, 5) as $term) {
            foreach ($this->index->search($term, $principal, 'relevance', $this->protocolsNamespace, [], self::PROTOCOLS) as $row) {
                if (\count($protocols) >= self::PROTOCOLS || isset($seenProtocols[$row['path']]) || $row['path'] === $this->protocolsNamespace) {
                    continue;
                }
                $seenProtocols[$row['path']] = true;
                $record = $this->read((string) $row['path']);
                if ($record !== null) {
                    $protocols[] = ['path' => $record->path, 'title' => (string) $row['title'], 'text' => self::cut(trim(ReportName::withoutNameHeading($record->body, $record->frontmatter)))];
                }
            }
        }

        return [
            'library' => $library,
            'context' => ['indication' => $facts['indication'], 'studies' => $studies],
            'similar' => $similar,
            'next' => ['guide' => $guide, 'protocols' => $protocols],
        ];
    }

    /**
     * @param list<string> $notices
     *
     * @return list<array<string, mixed>>
     */
    private function library(string $question, User $principal, ?Request $request, array &$notices): array
    {
        if ($this->passages === null || $question === '' || !$principal->canRead($this->libraryNamespace)) {
            return [];
        }
        $primary = SourceAsk::terms($question);
        $terms = $primary;
        // The articles are mostly English: the library profile's search words, when it has the prompt (owner, 2026-11-16)
        $expand = $this->actions->special($this->libraryNamespace, 'notebook-terms');
        if ($expand !== null) {
            try {
                $parsed = AskExpansion::parse($this->assistant->runNotebookTerms($expand, mb_substr($question, 0, 1000), $principal, $request)['result']);
                $terms = array_values(array_unique([...$terms, ...SpellingVariants::widen($parsed['terms'])]));
            } catch (AiException) {
                $notices[] = t('notebook.expand_failed');
            }
        }
        if ($terms === []) {
            return [];
        }
        $found = $this->passages->find([], null, $terms, self::LIBRARY_BUDGET, '', $principal, self::LIBRARY, $primary);
        $out = [];
        $prefix = $this->libraryNamespace . ':';
        foreach ($found['passages'] as $p) {
            $out[] = [
                'key' => str_starts_with($p['path'], $prefix) ? substr($p['path'], \strlen($prefix)) : ($p['key'] ?? null),
                'path' => $p['path'], 'title' => $p['title'], 'page' => $p['page'], 'text' => self::cut($p['text']),
            ];
        }

        return $out;
    }

    /**
     * What Workup reads of the report in front: its findings, conclusion, indication, modalities,
     * regions and the patient's age in years (null unknown)
     *
     * @return array{findings: string, conclusion: string, indication: string, modalities: list<string>, regions: list<string>, age: ?int}
     */
    public static function facts(PageRecord $page): array
    {
        $fm = $page->frontmatter;
        $conclusion = trim(implode("\n\n", Conclusion::texts($page->body)));
        $indication = MetaText::text($fm['indication'] ?? null);
        $findings = [];
        foreach (RecallTerms::sections($page->body) as $section) {
            $head = RecallTerms::fold((string) strtok($section, "\n"));
            if (str_starts_with($head, '### indicat')) {
                $indication = trim($indication . "\n" . trim((string) preg_replace('/^###[^\n]*\n?/', '', $section)));
            } elseif (!str_starts_with($head, '### concluz')) {
                $findings[] = $section;
            }
        }
        $list = static fn (mixed $v): array => array_values(array_filter(array_map(static fn (mixed $x): string => trim((string) $x), \is_array($v) ? $v : [$v]), static fn (string $x): bool => $x !== ''));
        $born = \is_array($fm['patient'] ?? null) ? ($fm['patient']['born'] ?? null) : null;
        $year = MetaText::date($fm['study_date'] ?? null, 'Y');
        $age = is_numeric($born) ? (ctype_digit($year) ? (int) $year : (int) date('Y')) - (int) $born : null;

        return [
            'findings' => mb_substr(trim(implode("\n\n", $findings)), 0, 4000),
            'conclusion' => mb_substr($conclusion, 0, 2000),
            'indication' => mb_substr(trim($indication), 0, 1000),
            'modalities' => $list($fm['modality'] ?? null),
            'regions' => $list($fm['region'] ?? null),
            'age' => $age !== null && $age >= 0 && $age < 130 ? $age : null,
        ];
    }

    private function read(string $path): ?PageRecord
    {
        try {
            return $this->storage->read($path);
        } catch (PageNotFoundException) {
            return null;
        }
    }

    private static function conclusionOf(PageRecord $record): string
    {
        $text = trim(implode("\n", Conclusion::texts($record->body)));

        return $text !== '' ? $text : MetaText::text($record->frontmatter['summary'] ?? null);
    }

    private static function cut(string $text): string
    {
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));

        return mb_strlen($text) > self::TEXT ? rtrim(mb_substr($text, 0, self::TEXT - 1)) . '…' : $text;
    }
}
