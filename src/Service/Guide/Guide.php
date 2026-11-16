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
