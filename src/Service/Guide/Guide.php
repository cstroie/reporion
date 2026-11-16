<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Guide;

use Reporion\Exception\GuideException;
use Reporion\Index\GuideTable;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\GuideCodes;
use Reporion\Support\RecallTerms;

/**
 * The imaging guide (D62, docs/plan-guide.md): the Romanian "Ghid de indicații radioimagistice"
 * as a searchable dataset. The CSV (GIRI's `GHID.csv`) is kept as `data/guide/guide.csv` with
 * `guide.json` beside it (sha256, rows, situations, source, loaded_at, loaded_by); the index's
 * guide tables are filled from it by load() and by refill() (index:rebuild). GIRI is the master:
 * nothing here edits the guide. Public data: no visibility applies.
 */
final class Guide
{
    /** Words too common to search by */
    private const STOP = ['de', 'cu', 'la', 'si', 'in', 'pe', 'a', 'al', 'ale', 'din', 'pentru', 'sau', 'un', 'o', 'the', 'of', 'and', 'or', 'with', 'to'];

    /** @var list<array{terms: list<string>, expand: list<string>}>|null */
    private ?array $synonyms = null;

    public function __construct(
        private readonly GuideTable $table,
        private readonly string $dataRoot,
        private readonly string $synonymsFile = '',
    ) {
    }

    private function csvPath(): string
    {
        return $this->dataRoot . '/guide/guide.csv';
    }

    private function infoPath(): string
    {
        return $this->dataRoot . '/guide/guide.json';
    }

    /**
     * A new CSV for the guide: read and checked (GuideCsv), then kept as data/guide/guide.csv
     * (atomically, invariant 7) with its guide.json, and the index's tables filled from it.
     * With $dryRun only read and checked.
     *
     * @return array{rows: int, situations: int, warnings: list<string>, sha256: string, unchanged: bool}
     *
     * @throws GuideException
     */
    public function load(string $bytes, string $source, string $actor, bool $dryRun = false): array
    {
        $parsed = GuideCsv::parse($bytes);
        $sha = hash('sha256', $bytes);
        $result = ['rows' => \count($parsed['rows']), 'situations' => $parsed['situations'], 'warnings' => $parsed['warnings'], 'sha256' => $sha, 'unchanged' => ($this->info()['sha256'] ?? null) === $sha];
        if ($dryRun) {
            return $result;
        }
        $dir = \dirname($this->csvPath());
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('data/guide cannot be made');
        }
        AtomicWriter::put($this->csvPath(), $bytes);
        AtomicWriter::put($this->infoPath(), (string) json_encode([
            'sha256' => $sha, 'rows' => $result['rows'], 'situations' => $result['situations'],
            'source' => mb_substr(trim($source), 0, 200), 'loaded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'loaded_by' => $actor,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->table->replace($parsed['rows'], $sha);

        return $result;
    }

    /**
     * The index's tables filled again from data/guide/guide.csv (index:rebuild); emptied when
     * there is none. Returns the rows filled.
     *
     * @throws GuideException when the kept file is no longer readable as the guide
     */
    public function refill(): int
    {
        $bytes = is_file($this->csvPath()) ? file_get_contents($this->csvPath()) : false;
        if ($bytes === false || $bytes === '') {
            $this->table->replace([], null);

            return 0;
        }
        $parsed = GuideCsv::parse($bytes);
        $this->table->replace($parsed['rows'], hash('sha256', $bytes));

        return \count($parsed['rows']);
    }

    /**
     * What is loaded (guide.json) and whether the index holds that file (`indexed`), or null
     *
     * @return array{sha256: string, rows: int, situations: int, source: string, loaded_at: string, loaded_by: string, indexed: bool}|null
     */
    public function info(): ?array
    {
        $json = is_file($this->infoPath()) ? json_decode((string) file_get_contents($this->infoPath()), true) : null;
        if (!\is_array($json) || !\is_string($json['sha256'] ?? null)) {
            return null;
        }

        return [
            'sha256' => $json['sha256'], 'rows' => (int) ($json['rows'] ?? 0), 'situations' => (int) ($json['situations'] ?? 0),
            'source' => (string) ($json['source'] ?? ''), 'loaded_at' => (string) ($json['loaded_at'] ?? ''), 'loaded_by' => (string) ($json['loaded_by'] ?? ''),
            'indexed' => $this->table->sha() === $json['sha256'],
        ];
    }

    /**
     * Situations matching what was typed, best first, each with all its exams. Filters: `tip`
     * (codes, any), `chapter` (its name), `indication` (codes of GuideCodes::INDICATIONS, any).
     * Words are matched without diacritics, the last one as a prefix; a word of the IRIS
     * synonym groups brings the guide's words it stands for. Nothing matching every word: the
     * situations matching most of them.
     *
     * @param array{tip?: list<string>, chapter?: string, indication?: list<string>} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $q, array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $tips = array_values(array_filter(array_map('strtoupper', $filters['tip'] ?? []), static fn (string $t): bool => isset(GuideCodes::TYPES[$t])));
        $indications = array_values(array_filter(array_map(static fn (string $c): ?string => GuideCodes::INDICATIONS[$c] ?? null, $filters['indication'] ?? [])));
        $chapter = trim($filters['chapter'] ?? '');
        $limit = max(1, min(50, $limit));
        [$all, $any] = $this->matchOf($q);
        $keys = $this->table->find($all, $tips, $chapter, $indications, $limit, $offset);
        if ($keys === [] && $any !== '' && $any !== $all && $offset === 0) {
            $keys = $this->table->find($any, $tips, $chapter, $indications, $limit, 0);
        }

        return $this->situations($keys);
    }

    /** The guide's chapter for children: ranked first for a patient under 18, last for an adult */
    public const CHILDREN = 'Pediatrie';

    /**
     * The situations a report is about (Workup's *Next steps*, D63): searched by its indication and
     * regions, a child's (under 18) Pediatrie situations first and an adult's last, the rows of the
     * report's own exam types marked `this: true`; each with `score` (the weighed words its title
     * holds), `coverage` (0..1: the share of the indication's own weight it holds) and `aside` (a
     * procedure or catch-all row). Nothing is sent anywhere.
     *
     * @param list<string> $regions
     * @param list<string> $modalities the report's (D29), e.g. CT, MR
     *
     * @return list<array<string, mixed>>
     */
    public function forReport(string $indication, array $regions, array $modalities, ?int $age, int $limit = 3): array
    {
        // The IRIS synonyms' guide words are searched too ("AVC" → "accident vascular"), weighed as the regions
        preg_match_all('/\p{L}+/u', RecallTerms::fold($indication), $m);
        $own = self::stems($indication);
        $stems = self::stems($indication . ' ' . implode(' ', $regions) . ' ' . implode(' ', $this->expansions(' ' . implode(' ', $m[0]) . ' ')));
        if ($stems === []) {
            return [];
        }
        // Any stem matches; then ranked by how many of them the situation's own title holds
        $keys = $this->table->find(implode(' OR ', array_map(static fn (string $s): string => '"' . $s . '"*', $stems)), [], '', [], 40);
        $found = $this->situations($keys);
        $order = array_flip($keys);
        // A word weighs by how rare it is among the situations' titles (idf): "apendic" more than "durere"
        [$total, $df] = $this->table->titleFrequencies($stems);
        $weight = array_map(static fn (int $n): float => log(($total + 1) / ($n + 1)), $df);
        $rank = static function (array $s) use ($stems, $own, $age, $weight): array {
            $title = RecallTerms::fold($s['title'] . ' ' . $s['subchapter']);
            // The indication's words count double, the regions' once
            $covered = (int) round(100 * array_sum(array_map(static fn (string $stem): float => preg_match('/\b' . preg_quote($stem, '/') . '/u', $title) === 1 ? ($weight[$stem] ?? 0.0) * (\in_array($stem, $own, true) ? 2 : 1) : 0.0, $stems)));
            // A report is a diagnostic exam: procedures and the guide's catch-all rows come last
            $aside = $s['chapter'] === self::PROCEDURES || str_starts_with(RecallTerms::fold($s['title']), 'alta situatie') ? 1 : 0;
            $ageRank = $age === null ? 0 : (($s['chapter'] === self::CHILDREN) === ($age < 18) ? 0 : 1);

            return [$aside, -$covered, $ageRank];
        };
        usort($found, static fn (array $a, array $b): int => [...$rank($a), $order[$a['key']]] <=> [...$rank($b), $order[$b['key']]]);
        // How much of the indication's own weight the title holds, 0..1: what the Checks warning trusts (D62 step 4)
        $ownWeight = 200 * array_sum(array_map(static fn (string $stem): float => $weight[$stem] ?? 0.0, $own));
        $tips = array_values(array_filter(array_map(GuideCodes::typeOfModality(...), $modalities)));
        $out = [];
        foreach (\array_slice($found, 0, max(1, $limit)) as $situation) {
            foreach ($situation['exams'] as $n => $exam) {
                $situation['exams'][$n]['this'] = \in_array($exam['tip'], $tips, true);
            }
            $r = $rank($situation);
            $situation['score'] = -$r[1];
            $situation['coverage'] = $ownWeight > 0 ? round(min(1.0, -$r[1] / $ownWeight), 3) : 0.0;
            $situation['aside'] = $r[0] === 1;
            $out[] = $situation;
        }

        return $out;
    }

    /** A strong match's least coverage of the indication's own words (guide:measure, D62 step 4) */
    public const MIN_COVERAGE = 0.6;

    /** A strong match's least lead over the next situation, as a share of its score (0.15 from guide:measure on 5 080 reports, 2026-11-16: 18 warned, 1 in 280) */
    public const MIN_MARGIN = 0.15;

    /**
     * The Checks warning (D62 step 4): when the report's indication matches one guide situation
     * strongly (a diagnostic one, covering at least $minCoverage of the indication's own words,
     * leading the next by $minMargin of its score) and every row of this exam's type there is
     * discouraged (GuideCodes::DISCOURAGED: justification needed, not first, not indicated),
     * that situation and the row; else null. Never a block (D7).
     *
     * @param list<string> $regions
     * @param list<string> $modalities
     *
     * @return array{situation: array<string, mixed>, exam: array<string, mixed>, coverage: float, margin: float}|null
     */
    public function caution(string $indication, array $regions, array $modalities, ?int $age, float $minCoverage = self::MIN_COVERAGE, float $minMargin = self::MIN_MARGIN): ?array
    {
        $match = $this->strongest($indication, $regions, $modalities, $age);
        if ($match === null || $match['coverage'] < $minCoverage || $match['margin'] < $minMargin) {
            return null;
        }
        $rows = array_values(array_filter($match['situation']['exams'], static fn (array $x): bool => $x['this']));
        if ($rows === [] || \count(array_filter($rows, static fn (array $x): bool => \in_array($x['indication_code'], GuideCodes::DISCOURAGED, true))) !== \count($rows)) {
            return null;
        }

        return ['situation' => $match['situation'], 'exam' => $rows[0], 'coverage' => $match['coverage'], 'margin' => $match['margin']];
    }

    /**
     * The best situation for a report with how sure the match is: its coverage and its lead over
     * the next (0..1); null when there is none or the best is a procedure or catch-all row
     *
     * @param list<string> $regions
     * @param list<string> $modalities
     *
     * @return array{situation: array<string, mixed>, coverage: float, margin: float}|null
     */
    public function strongest(string $indication, array $regions, array $modalities, ?int $age): ?array
    {
        $found = $this->forReport($indication, $regions, $modalities, $age, 2);
        if ($found === [] || $found[0]['aside'] || $found[0]['score'] <= 0) {
            return null;
        }
        $second = $found[1]['score'] ?? 0;

        return ['situation' => $found[0], 'coverage' => (float) $found[0]['coverage'], 'margin' => round(max(0, $found[0]['score'] - $second) / $found[0]['score'], 3)];
    }

    /** Words of an indication that say nothing of the situation */
    private const VAGUE = ['suspiciune', 'suspect', 'posibil', 'probabil', 'control', 'evaluare', 'pacient', 'pacienta', 'fara', 'semne', 'stanga', 'dreapta', 'dreapt', 'stang', 'bilateral', 'cunoscut', 'cunoscuta', 'istoric'];

    /** The interventional chapter: procedures, ranked after diagnostic situations for a report */
    public const PROCEDURES = 'Radiologie intervențională';

    /**
     * A report's words as stems for a prefix search (no stemmer, D28): folded, small words dropped,
     * a word of six letters or more cut to max(5, length − 4) letters ("pancreasului" → "pancreas",
     * "brutală" → "bruta"), at most 12
     *
     * @return list<string>
     */
    public static function stems(string $text): array
    {
        preg_match_all('/\p{L}+/u', RecallTerms::fold($text), $m);
        $out = [];
        foreach ($m[0] as $word) {
            $len = mb_strlen($word);
            if ($len < 3 || \in_array($word, self::STOP, true) || \in_array($word, self::VAGUE, true)) {
                continue;
            }
            $out[] = $len >= 6 ? mb_substr($word, 0, max(5, $len - 5)) : $word;
        }

        return \array_slice(array_values(array_unique($out)), 0, 12);
    }

    /** One situation by its key, or null (none, or the guide has moved on) */
    public function situation(string $key): ?array
    {
        return preg_match('/^[0-9a-f]{12}$/', $key) === 1 ? ($this->situations([$key])[0] ?? null) : null;
    }

    /**
     * The chapters and subchapters, with their situations' counts
     *
     * @return list<array{chapter: string, subchapters: list<array{name: string, situations: int}>, situations: int}>
     */
    public function outline(): array
    {
        return $this->table->outline();
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>
     */
    private function situations(array $keys): array
    {
        $out = [];
        foreach ($this->table->rowsOf($keys) as $key => $rows) {
            $out[] = [
                'key' => $key,
                'chapter' => $rows[0]['chapter'],
                'subchapter' => $rows[0]['subchapter'],
                'title' => $rows[0]['title'],
                'exams' => array_map(static fn (array $r): array => [
                    'tip' => $r['tip'], 'type' => GuideCodes::TYPES[$r['tip']] ?? '', 'exam' => $r['exam'],
                    'indication' => $r['indication'], 'indication_code' => GuideCodes::indicationCode($r['indication']), 'grade' => $r['grade'],
                    'comment' => $r['comment'], 'other' => $r['other'], 'dose_min' => $r['dose_min'], 'dose_max' => $r['dose_max'], 'therapeutic' => $r['therapeutic'],
                ], $rows),
            ];
        }

        return $out;
    }

    /**
     * The FTS5 expressions for what was typed: every word (the last as a prefix), and any of them;
     * each widened by the synonym groups its words or phrase belong to
     *
     * @return array{0: string, 1: string}
     */
    public function matchOf(string $q): array
    {
        $folded = RecallTerms::fold($q);
        preg_match_all('/[\p{L}\p{N}]+/u', $folded, $m);
        $words = array_values(array_filter($m[0], static fn (string $w): bool => !\in_array($w, self::STOP, true) && (mb_strlen($w) > 1 || ctype_digit($w))));
        if ($words === []) {
            return ['', ''];
        }
        $quote = static fn (string $w): string => '"' . str_replace('"', '', $w) . '"';
        $terms = array_map($quote, $words);
        // A last word of three letters or more is a prefix: the reader may still be typing
        if (mb_strlen(end($words)) >= 3) {
            $terms[\count($terms) - 1] .= '*';
        }
        $expand = array_map($quote, $this->expansions(' ' . implode(' ', $words) . ' '));
        $all = '(' . implode(' AND ', $terms) . ')' . ($expand !== [] ? ' OR ' . implode(' OR ', $expand) : '');
        $any = implode(' OR ', array_merge($terms, $expand));

        return [$all, $any];
    }

    /**
     * The guide's words that the synonym groups give for a folded text padded with spaces
     *
     * @return list<string>
     */
    private function expansions(string $padded): array
    {
        $out = [];
        foreach ($this->synonyms() as $group) {
            foreach ($group['terms'] as $term) {
                $t = trim(RecallTerms::fold($term));
                if ($t !== '' && str_contains($padded, ' ' . $t . ' ')) {
                    foreach ($group['expand'] as $phrase) {
                        $out[] = trim(RecallTerms::fold($phrase));
                    }
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** @return list<array{terms: list<string>, expand: list<string>}> */
    private function synonyms(): array
    {
        if ($this->synonyms === null) {
            $json = $this->synonymsFile !== '' && is_file($this->synonymsFile) ? json_decode((string) file_get_contents($this->synonymsFile), true) : null;
            $this->synonyms = [];
            foreach (\is_array($json['groups'] ?? null) ? $json['groups'] : [] as $group) {
                if (\is_array($group['terms'] ?? null) && \is_array($group['expand'] ?? null)) {
                    $this->synonyms[] = ['terms' => array_map('strval', $group['terms']), 'expand' => array_map('strval', $group['expand'])];
                }
            }
        }

        return $this->synonyms;
    }
}
