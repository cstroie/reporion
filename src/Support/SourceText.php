<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * An extracted article's text made readable (docs/library.md, pipeline step 3): no AI, pure.
 *
 * fromPages() takes a PDF's text page by page (pdftotext's form feeds): drops the lines repeated
 * at the top or bottom of most pages (running heads, footers) and bare page numbers, rejoins words
 * hyphenated over a line end and the lines of one paragraph, fixes ligatures, and puts an anchor
 * before each page (`<a id="p12"></a>`) so a citation can open the original at that page.
 * The references section is cut off and returned apart (phase 7 reads it).
 *
 * fromMarkdown() takes what pandoc made of a docx/odt/html: already in paragraphs, only tidied.
 */
final class SourceText
{
    /** A line repeated on at least this share of the pages (and on 3 or more) is a running head */
    private const REPEAT_SHARE = 0.5;

    /** How many lines at each end of a page are looked at for running heads */
    private const EDGE_LINES = 3;

    /** Marks a page break in the line list; NUL never occurs in extracted text */
    private const ANCHOR = "\0page:";

    /** Section names that stand on their own line become `##` headings */
    private const SECTIONS = '(?:abstract|summary|background|introduction|objectives?|purpose|methods?|materials and methods|patients and methods|results|discussion|conclusions?|case (?:report|presentation)|limitations|keywords|key words|acknowledg(?:e)?ments|rezumat|introducere|material(?:e)? și metod[aă]|rezultate|discu[țţ]ii|concluzii)';

    private const LIGATURES = [
        "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
        "\u{FB05}" => 'st', "\u{FB06}" => 'st', "\u{00AD}" => '', "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ',
        "\u{2009}" => ' ', "\u{200B}" => '',
    ];

    /** The headings a references section starts with, in the languages met */
    private const REFERENCE_HEADINGS = '(?:references|bibliography|literature cited|works cited|bibliografie|referin[țţt]e(?: bibliografice)?)';

    /**
     * @param list<string> $pages one string per page
     *
     * @return array{body: string, references: string}
     */
    public static function fromPages(array $pages): array
    {
        $pages = array_map(static fn (string $p): array => self::lines(self::normalize($p)), $pages);
        $repeated = self::runningHeads($pages);

        // One list of lines, a page's anchor before its own: the references are cut on lines,
        // before paragraphs are joined (a PDF's "References" runs straight into the first one)
        $all = [];
        foreach ($pages as $i => $lines) {
            $all[] = self::ANCHOR . ($i + 1);
            array_push($all, ...self::stripEdges($lines, $repeated));
        }
        $references = '';
        $cut = self::referencesLine($all);
        if ($cut !== null) {
            $references = trim(implode("\n", array_filter(
                \array_slice($all, $cut + 1),
                static fn (string $l): bool => !str_starts_with($l, self::ANCHOR),
            )));
            $all = \array_slice($all, 0, $cut);
        }

        $parts = [];
        $page = [];
        foreach ($all as $line) {
            if (str_starts_with($line, self::ANCHOR)) {
                if ($page !== [] || $parts !== []) {
                    $parts[] = self::paragraphs($page);
                }
                $parts[] = '<a id="p' . substr($line, \strlen(self::ANCHOR)) . '"></a>';
                $page = [];
                continue;
            }
            $page[] = $line;
        }
        $parts[] = self::paragraphs($page);

        return [
            'body' => trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== ''))) . "\n",
            'references' => $references,
        ];
    }

    /** @return array{body: string, references: string} */
    public static function fromMarkdown(string $markdown): array
    {
        $text = self::normalize($markdown);
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return self::splitReferences(trim($text));
    }

    /** NFC, ligatures, soft hyphens and odd spaces, Unix line ends */
    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        return strtr($text, self::LIGATURES);
    }

    /** @return list<string> */
    private static function lines(string $page): array
    {
        return array_map(static fn (string $l): string => rtrim($l), explode("\n", $page));
    }

    /** A line's shape for comparing running heads: digits (page numbers) folded, spaces squeezed */
    private static function shape(string $line): string
    {
        return mb_strtolower((string) preg_replace(['/\d+/', '/\s+/'], ['#', ' '], trim($line)));
    }

    /**
     * @param list<list<string>> $pages
     *
     * @return array<string, true> the shapes of the running heads and footers
     */
    private static function runningHeads(array $pages): array
    {
        $count = \count($pages);
        if ($count < 3) {
            return [];
        }
        $seen = [];
        foreach ($pages as $lines) {
            $edge = [];
            foreach (array_merge(\array_slice(self::nonBlank($lines), 0, self::EDGE_LINES), \array_slice(self::nonBlank($lines), -self::EDGE_LINES)) as $line) {
                $edge[self::shape($line)] = true;
            }
            foreach (array_keys($edge) as $shape) {
                $seen[$shape] = ($seen[$shape] ?? 0) + 1;
            }
        }
        $repeated = [];
        foreach ($seen as $shape => $n) {
            if ($shape !== '' && $n >= 3 && $n >= $count * self::REPEAT_SHARE) {
                $repeated[(string) $shape] = true;
            }
        }

        return $repeated;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function nonBlank(array $lines): array
    {
        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }

    /**
     * The page's lines without its running heads at either end and without a bare page number there
     *
     * @param list<string> $lines
     * @param array<string, true> $repeated
     *
     * @return list<string>
     */
    private static function stripEdges(array $lines, array $repeated): array
    {
        $isEdgeJunk = static function (string $line) use ($repeated): bool {
            $t = trim($line);

            return $t === ''
                || isset($repeated[self::shape($t)])
                || preg_match('/^(?:page\s+|pag\.\s*)?\d{1,4}(?:\s*(?:\/|of|din)\s*\d{1,4})?$/i', $t) === 1;
        };
        for ($n = 0; $n < self::EDGE_LINES + 1 && $lines !== [] && $isEdgeJunk($lines[0]); ++$n) {
            array_shift($lines);
            while ($lines !== [] && trim($lines[0]) === '') {
                array_shift($lines);
            }
        }
        for ($n = 0; $n < self::EDGE_LINES + 1 && $lines !== [] && $isEdgeJunk($lines[\count($lines) - 1]); ++$n) {
            array_pop($lines);
            while ($lines !== [] && trim($lines[\count($lines) - 1]) === '') {
                array_pop($lines);
            }
        }

        return $lines;
    }

    /**
     * Lines joined into paragraphs: a blank line ends one; a line ending in a hyphen joins the next
     * without it when the next starts in lower case (a word broken over the line end).
     *
     * @param list<string> $lines
     */
    private static function paragraphs(array $lines): string
    {
        $paragraphs = [];
        $current = '';
        foreach ($lines as $line) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($t === '') {
                if ($current !== '') {
                    $paragraphs[] = $current;
                    $current = '';
                }
                continue;
            }
            if (self::isSection($t)) {
                if ($current !== '') {
                    $paragraphs[] = $current;
                }
                $paragraphs[] = "\0## " . (string) preg_replace('/^(?:\d+\.?|[IVX]+\.)\s*/', '', rtrim($t, ':'));
                $current = '';
                continue;
            }
            if ($current === '') {
                $current = $t;
            } elseif (preg_match('/\p{Ll}-$/u', $current) === 1 && preg_match('/^\p{Ll}/u', $t) === 1) {
                $current = substr($current, 0, -1) . $t;
            } else {
                $current .= ' ' . $t;
            }
        }
        if ($current !== '') {
            $paragraphs[] = $current;
        }

        return implode("\n\n", array_map(
            static fn (string $p): string => str_starts_with($p, "\0") ? substr($p, 1) : self::escapeMarkdown($p),
            $paragraphs,
        ));
    }

    private static function isSection(string $line): bool
    {
        return preg_match('/^(?:\d+\.?\s*|[IVX]+\.\s*)?' . self::SECTIONS . '\s*:?$/iu', $line) === 1;
    }

    /**
     * The last line that is a references heading alone, past the first third of the text
     *
     * @param list<string> $lines
     */
    private static function referencesLine(array $lines): ?int
    {
        $found = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:\d+\.?\s*)?' . self::REFERENCE_HEADINGS . '\s*:?$/iu', trim($line)) === 1) {
                $found = $i;
            }
        }

        return $found !== null && $found > \count($lines) / 3 ? $found : null;
    }

    /** A paragraph of extracted prose must not turn into markdown it never was (a list, a heading, a quote) */
    private static function escapeMarkdown(string $paragraph): string
    {
        $paragraph = (string) preg_replace('/^(#{1,6}\s|>|[-+*]\s)/', '\\\\$1', $paragraph);
        $paragraph = (string) preg_replace('/^(\d{1,9})([.)])(\s)/', '$1\\\\$2$3', $paragraph);

        return str_replace(['<', '[', ']'], ['&lt;', '\\[', '\\]'], $paragraph);
    }

    /** @return array{body: string, references: string} */
    private static function splitReferences(string $text): array
    {
        // The last heading-like line that names the references, with real text before it
        $pattern = '/^(?:#{1,6}\s*)?(?:\d+\.?\s*)?' . self::REFERENCE_HEADINGS . '\s*:?\s*$/imu';
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            $at = (int) $last[1];
            if ($at > \strlen($text) / 3) {
                return [
                    'body' => rtrim(substr($text, 0, $at)) . "\n",
                    'references' => trim(substr($text, $at + \strlen($last[0]))),
                ];
            }
        }

        return ['body' => rtrim($text) . "\n", 'references' => ''];
    }
}
