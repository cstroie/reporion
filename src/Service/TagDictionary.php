<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use InvalidArgumentException;
use Reporion\Storage\AtomicWriter;
use Reporion\Support\Slug;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The tag dictionary (phase 20): per tag, a group, an ICD-10 code and its
 * synonyms — the terms D28's query-time expansion searches along with it.
 * Lives in data/tags.yaml (invariant 1: disk is authoritative; the index
 * holds no copy), written atomically. A tag needs no entry to exist — tags
 * live in page frontmatter — and an entry needs no page: a synonym group
 * for a term no page is tagged with yet is still a search synonym.
 *
 * Until the file is first written, the shipped conf/synonyms.txt seeds it:
 * one group per line, the first term the entry, the rest its synonyms.
 *
 * @phpstan-type Entry array{group: string, icd10: string, synonyms: list<string>}
 */
final class TagDictionary
{
    public const FILE = 'tags.yaml';

    /** @var array<string, Entry>|null */
    private ?array $entries = null;

    public function __construct(
        private readonly string $dataRoot,
        private readonly string $seedFile,
    ) {
    }

    /** @return array<string, Entry> tag → entry, sorted by tag */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $file = $this->dataRoot . '/' . self::FILE;
        $raw = is_file($file) ? self::parse($file) : self::seed($this->seedFile);
        $entries = [];
        foreach ($raw as $tag => $entry) {
            if (!\is_string($tag) || !\is_array($entry)) {
                continue;
            }
            $entries[$tag] = [
                'group' => \is_string($entry['group'] ?? null) ? $entry['group'] : '',
                'icd10' => \is_string($entry['icd10'] ?? null) ? $entry['icd10'] : '',
                'synonyms' => array_values(array_filter((array) ($entry['synonyms'] ?? []), 'is_string')),
            ];
        }
        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->entries = $entries;
    }

    /** @return Entry */
    public function get(string $tag): array
    {
        return $this->all()[$tag] ?? ['group' => '', 'icd10' => '', 'synonyms' => []];
    }

    /**
     * Sets one tag's entry; an entry left all blank is removed.
     *
     * @throws InvalidArgumentException naming the field at fault
     */
    public function set(string $tag, string $group, string $icd10, string $synonyms): void
    {
        $tag = self::term($tag, 'tag');
        $group = self::text($group, 32, 'group');
        $icd10 = strtoupper(self::text($icd10, 16, 'icd10'));
        $list = [];
        foreach (explode(',', $synonyms) as $synonym) {
            $synonym = trim($synonym);
            if ($synonym !== '' && mb_strtolower($synonym) !== mb_strtolower($tag)) {
                $list[mb_strtolower($synonym)] ??= self::term($synonym, 'synonyms');
            }
        }
        $entries = $this->all();
        if ($group === '' && $icd10 === '' && $list === []) {
            unset($entries[$tag]);
        } else {
            $entries[$tag] = ['group' => $group, 'icd10' => $icd10, 'synonyms' => array_values($list)];
        }
        $this->write($entries);
    }

    /**
     * After a merge ($from renamed to $into on every page): the merged
     * tags' entries fold into $into's — blanks filled, synonyms joined, the
     * old names kept as synonyms so a search for them still finds the pages.
     *
     * @param list<string> $from
     */
    public function merge(array $from, string $into): void
    {
        $entries = $this->all();
        $target = $entries[$into] ?? ['group' => '', 'icd10' => '', 'synonyms' => []];
        $changed = false;
        foreach ($from as $tag) {
            if ($tag === $into) {
                continue;
            }
            $old = $entries[$tag] ?? null;
            if ($old === null) {
                continue;
            }
            $target['group'] = $target['group'] !== '' ? $target['group'] : $old['group'];
            $target['icd10'] = $target['icd10'] !== '' ? $target['icd10'] : $old['icd10'];
            $target['synonyms'] = [...$target['synonyms'], $tag, ...$old['synonyms']];
            unset($entries[$tag]);
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        $seen = [mb_strtolower($into) => true];
        $target['synonyms'] = array_values(array_filter($target['synonyms'], static function (string $s) use (&$seen): bool {
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
        $entries[$into] = $target;
        $this->write($entries);
    }

    /**
     * D28's groups: each entry with synonyms is one group, the tag first.
     *
     * @return list<list<string>>
     */
    public function searchGroups(): array
    {
        $groups = [];
        foreach ($this->all() as $tag => $entry) {
            if ($entry['synonyms'] !== []) {
                $groups[] = [$tag, ...$entry['synonyms']];
            }
        }

        return $groups;
    }

    /**
     * Merge candidates among $tags (the tags in use), computed, never stored
     * or applied: tags equal once case, diacritics and separators are folded
     * ("PI-RADS", "pirads", "Pi Rads"), and tags that are another tag's
     * synonym. "into" is the spelling on the most pages, or the tag whose
     * synonyms they are.
     *
     * @param array<string, int> $tags tag → page count
     *
     * @return list<array{into: string, from: list<string>}>
     */
    public function suggestedMerges(array $tags): array
    {
        $byFold = [];
        foreach (array_keys($tags) as $tag) {
            $byFold[Slug::fold((string) $tag)][] = (string) $tag;
        }
        $clusters = [];
        foreach ($byFold as $same) {
            if (\count($same) > 1) {
                // The spelling on the most pages wins
                usort($same, static fn (string $a, string $b): int => [$tags[$b] ?? 0, $a] <=> [$tags[$a] ?? 0, $b]);
                $clusters[] = $same;
            }
        }
        foreach ($this->all() as $tag => $entry) {
            $folded = array_flip(array_map(Slug::fold(...), $entry['synonyms']));
            $inUse = [];
            foreach ($byFold as $fold => $same) {
                if (isset($folded[$fold])) {
                    array_push($inUse, ...array_diff($same, [$tag]));
                }
            }
            if ($inUse !== []) {
                // Into the dictionary's own tag, the synonyms' home
                $clusters[] = [(string) $tag, ...$inUse];
            }
        }

        $suggestions = [];
        $seen = [];
        foreach ($clusters as $cluster) {
            $into = array_shift($cluster);
            $from = array_values(array_unique($cluster));
            sort($from);
            $key = $into . "\0" . implode("\0", $from);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $suggestions[] = ['into' => $into, 'from' => $from];
            }
        }

        return $suggestions;
    }

    /** @param array<string, Entry> $entries */
    private function write(array $entries): void
    {
        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($entries as $tag => $entry) {
            $out[$tag] = array_filter($entry, static fn (mixed $v): bool => $v !== '' && $v !== []);
        }
        AtomicWriter::put(
            $this->dataRoot . '/' . self::FILE,
            "# Tag dictionary (Admin → Tags): group, ICD-10 code and search synonyms per tag (D28)\n" . Yaml::dump($out, 3, 2)
        );
        $this->entries = null;
    }

    /** @return array<mixed> */
    private static function parse(string $file): array
    {
        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException) {
            // An unreadable file must not take search or the admin screen down; it is left untouched
            error_log('reporion: data/' . self::FILE . ' is not valid YAML');

            return [];
        }

        return \is_array($data) ? $data : [];
    }

    /** @return array<string, array{synonyms: list<string>}> */
    private static function seed(string $file): array
    {
        $entries = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $terms = array_values(array_filter(array_map('trim', explode(',', $line)), static fn (string $t): bool => $t !== ''));
            if ($terms === [] || str_starts_with($terms[0], '#')) {
                continue;
            }
            $tag = array_shift($terms);
            $entries[$tag] = ['synonyms' => $terms];
        }

        return $entries;
    }

    private static function term(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64 || preg_match('/[,\[\]{}\x00-\x1F\x7F"]/', $value) === 1) {
            throw new InvalidArgumentException($field);
        }

        return $value;
    }

    private static function text(string $value, int $max, string $field): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($field);
        }

        return $value;
    }
}
