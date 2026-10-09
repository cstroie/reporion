<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Two reports' rendered HTML lined up section by section, for the
 * report-vs-prior compare (`/{path}/compare`, roadmap phase 17a). Splits
 * Render::toHtml()'s output — never the markdown, so the canonical renderer
 * stays the only one (invariant 4) — at its top-level `<h1>`/`<h2>` (an
 * exam, docs/FORMATS.md §11) and `<h3>` (a section) headings.
 *
 * Exams pair by name — case, diacritics and punctuation aside, so "CT
 * cerebral" meets "CT Cerebral:" — and those left over by position.
 * Sections of a paired exam pair the same way by name, then by the
 * heading's first word cut to six letters ("Concluzii" meets "Concluzie").
 * A `##` heading that is itself a section (the archive's shared `##
 * Concluzii` after several exams) belongs to a group of its own, paired
 * with the other report's. What comes before the first heading is the
 * preamble row.
 *
 * Rows follow the newer report's order: the older one's exams and sections
 * are moved, in memory only, to sit beside their pair — `reordered` says
 * when that changed their order. One the newer report does not have comes
 * after the one it followed in the older report.
 */
final class CompareSections
{
    private const SECTION = '/^(indica|tehnic|descri|conclu|recoma|compar|observ|istori|anamne|protoc|rezult)/';

    /**
     * @return array{rows: list<array{0: string, 1: string}>, reordered: bool}
     *         per row, the newer and the older report's HTML ('' where one has no such part)
     */
    public static function align(string $newerHtml, string $olderHtml): array
    {
        $newer = self::groups($newerHtml);
        $older = self::groups($olderHtml);

        // Groups: the preamble and the shared sections by kind, exams by name, then by position
        $pairs = self::pair(
            $newer,
            $older,
            [
                static fn (array $g): ?string => $g['kind'] === 'exam' ? null : $g['kind'],
                static fn (array $g): ?string => $g['kind'] === 'exam' ? self::fold($g['title']) : null,
                static fn (array $g): ?string => $g['kind'] === 'exam' ? 'exam' : null,
            ],
        );
        [$order, $reordered] = self::merge(\count($newer), \count($older), $pairs);

        $rows = [];
        foreach ($order as [$n, $o]) {
            $a = $n !== null ? $newer[$n] : null;
            $b = $o !== null ? $older[$o] : null;
            if (($a['head'] ?? '') !== '' || ($b['head'] ?? '') !== '') {
                $rows[] = [$a['head'] ?? '', $b['head'] ?? ''];
            }
            $sa = $a['sections'] ?? [];
            $sb = $b['sections'] ?? [];
            $sectionPairs = self::pair($sa, $sb, [
                static fn (array $s): ?string => self::fold($s['title']),
                static fn (array $s): ?string => self::word($s['title']),
            ]);
            [$sectionOrder, $moved] = self::merge(\count($sa), \count($sb), $sectionPairs);
            $reordered = $reordered || $moved;
            foreach ($sectionOrder as [$i, $j]) {
                $rows[] = [$i !== null ? $sa[$i]['html'] : '', $j !== null ? $sb[$j]['html'] : ''];
            }
        }

        return ['rows' => $rows, 'reordered' => $reordered];
    }

    /**
     * The rendered HTML as groups: the preamble, each exam (its heading and
     * what follows up to its first section, then its sections), and the
     * shared sections after the exams.
     *
     * @return list<array{kind: string, title: string, head: string, sections: list<array{title: string, html: string}>}>
     */
    public static function groups(string $html): array
    {
        $groups = [];
        $group = ['kind' => 'pre', 'title' => '', 'head' => '', 'sections' => []];
        $section = null;
        $flush = static function () use (&$group, &$section, &$groups): void {
            if ($section !== null) {
                $group['sections'][] = $section;
                $section = null;
            }
            if (trim($group['head']) !== '' || $group['sections'] !== []) {
                $groups[] = $group;
            }
        };

        foreach (self::nodes($html) as [$level, $text, $part]) {
            if ($level === null) {
                if ($section !== null) {
                    $section['html'] .= $part;
                } else {
                    $group['head'] .= $part;
                }
                continue;
            }
            $isSection = $level === 3 || preg_match(self::SECTION, self::word($text)) === 1;
            if ($level === 3 || ($isSection && $group['kind'] === 'shared')) {
                if ($section !== null) {
                    $group['sections'][] = $section;
                }
                $section = ['title' => $text, 'html' => $part];
                continue;
            }
            // A new group: an exam, or (a `##` section) the shared sections
            $flush();
            $group = $isSection
                ? ['kind' => 'shared', 'title' => '', 'head' => '', 'sections' => []]
                : ['kind' => 'exam', 'title' => $text, 'head' => $part, 'sections' => []];
            if ($isSection) {
                $section = ['title' => $text, 'html' => $part];
            }
        }
        $flush();

        return $groups;
    }

    /**
     * Pairs $b's items with $a's, one rule after another: an item pairs
     * with the first unpaired one on the other side whose key under the
     * same rule is equal (null keys never pair).
     *
     * @param list<array<string, mixed>>                      $a
     * @param list<array<string, mixed>>                      $b
     * @param list<\Closure(array<string, mixed>): ?string>  $rules
     *
     * @return array<int, int> $b index => $a index
     */
    private static function pair(array $a, array $b, array $rules): array
    {
        $pairs = [];
        foreach ($rules as $rule) {
            foreach ($b as $j => $item) {
                $key = isset($pairs[$j]) ? null : $rule($item);
                if ($key === null || $key === '') {
                    continue;
                }
                foreach ($a as $i => $other) {
                    if (!\in_array($i, $pairs, true) && $rule($other) === $key) {
                        $pairs[$j] = $i;
                        break;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * $a's order, each with its pair from $b; an unpaired $b item after the
     * $b item before it. Reordered: the paired $b items, read in $a's
     * order, are not in their own order.
     *
     * @param array<int, int> $pairs $b index => $a index
     *
     * @return array{0: list<array{0: ?int, 1: ?int}>, 1: bool}
     */
    private static function merge(int $countA, int $countB, array $pairs): array
    {
        $byA = array_flip($pairs);
        $order = [];
        for ($i = 0; $i < $countA; $i++) {
            $order[] = [$i, $byA[$i] ?? null];
        }
        $previous = null;
        for ($j = 0; $j < $countB; $j++) {
            if (!isset($pairs[$j])) {
                $at = 0;
                foreach ($order as $k => [, $o]) {
                    if ($o === $previous && $previous !== null) {
                        $at = $k + 1;
                    }
                }
                array_splice($order, $at, 0, [[null, $j]]);
            }
            $previous = $j;
        }

        $seen = array_values(array_filter(array_column($order, 1), static fn (?int $o): bool => $o !== null && isset($pairs[$o])));
        $sorted = $seen;
        sort($sorted);

        return [$order, $seen !== $sorted];
    }

    /**
     * The top-level nodes: [heading level or null, heading text, the node's HTML]
     *
     * @return list<array{0: ?int, 1: string, 2: string}>
     */
    private static function nodes(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($doc->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $root = $child;
                break;
            }
        }
        if ($root === null) {
            return [[null, '', $html]];
        }

        $nodes = [];
        foreach (iterator_to_array($root->childNodes) as $node) {
            $level = self::headingLevel($node);
            $nodes[] = [$level, $level !== null ? trim((string) $node->textContent) : '', (string) $doc->saveHTML($node)];
        }

        return $nodes;
    }

    private static function headingLevel(DOMNode $node): ?int
    {
        return $node instanceof DOMElement && preg_match('/^h([123])$/', strtolower($node->tagName), $m) === 1 ? (int) $m[1] : null;
    }

    /** Lower case, Romanian diacritics folded, anything but letters and digits one space */
    private static function fold(string $text): string
    {
        $folded = strtr(mb_strtolower($text), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $folded));
    }

    /** The heading's first word, folded, at most six letters */
    private static function word(string $text): string
    {
        preg_match('/\p{L}+/u', self::fold($text), $m);

        return mb_substr($m[0] ?? '', 0, 6);
    }
}
