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
 * A section's key is its exam's ordinal plus the heading's first word,
 * folded and cut to six letters, so "Concluzii" meets "Concluzie:" and
 * "Descriere" meets "DESCRIERE"; exam titles never take part, since two
 * studies of one patient rarely name their exam the same. A `##` heading
 * that is itself a section (the archive's shared `## Concluzii` after
 * several exams) is keyed as a section, not an exam. What comes before the
 * first heading is the preamble row. Rows follow the newer report's order;
 * a section only the older one has comes after the row it followed there.
 */
final class CompareSections
{
    private const SECTION = '/^(indica|tehnic|descri|conclu|recoma|compar|observ|istori|anamne|protoc|rezult)/';

    /**
     * @return list<array{0: string, 1: string}> per row, the newer and the older report's HTML ('' where one has no such section)
     */
    public static function align(string $newerHtml, string $olderHtml): array
    {
        $newer = self::split($newerHtml);
        $older = self::split($olderHtml);

        $order = array_keys($newer);
        $previous = null;
        foreach (array_keys($older) as $key) {
            if (!isset($newer[$key])) {
                $at = $previous === null ? 0 : (int) array_search($previous, $order, true) + 1;
                array_splice($order, $at, 0, [$key]);
            }
            $previous = $key;
        }

        return array_map(static fn (string $key): array => [$newer[$key] ?? '', $older[$key] ?? ''], $order);
    }

    /**
     * @return array<string, string> section key => that section's HTML, in document order
     */
    public static function split(string $html): array
    {
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
            return trim($html) === '' ? [] : ['pre' => $html];
        }

        $sections = [];
        $key = 'pre';
        $exam = 0;
        foreach (iterator_to_array($root->childNodes) as $node) {
            $level = self::headingLevel($node);
            if ($level !== null) {
                $word = self::word((string) $node->textContent);
                $isSection = $level === 3 || preg_match(self::SECTION, $word) === 1;
                if (!$isSection) {
                    $exam++;
                    $key = 'e' . $exam;
                } else {
                    $key = ($level === 3 ? 'e' . $exam : 's') . ':' . $word;
                }
                $base = $key;
                for ($n = 2; isset($sections[$key]); $n++) {
                    $key = $base . '#' . $n;
                }
            }
            $sections[$key] = ($sections[$key] ?? '') . $doc->saveHTML($node);
        }

        return array_filter($sections, static fn (string $part): bool => trim($part) !== '');
    }

    private static function headingLevel(DOMNode $node): ?int
    {
        return $node instanceof DOMElement && preg_match('/^h([123])$/', strtolower($node->tagName), $m) === 1 ? (int) $m[1] : null;
    }

    /** The heading's first word, lower case, Romanian diacritics folded, at most six letters */
    private static function word(string $text): string
    {
        $folded = strtr(mb_strtolower(trim($text)), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        preg_match('/\p{L}+/u', $folded, $m);

        return mb_substr($m[0] ?? '', 0, 6);
    }
}
