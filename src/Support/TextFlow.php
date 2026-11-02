<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * An article's text flow made right again (docs/library.md, "Text flow"): no AI, pure, idempotent.
 * Run on a library source as it is made (Support\SourceText, Service\Library\Library::body()) and
 * from the editor rail's Tools (`POST /api/v1/textflow`) on any page that is not a report.
 *
 * fix() works on the markdown body, block by block (a block is what blank lines part):
 *
 * - drops what the PDF printed around the article: running heads and footers (a short line that
 *   stands alone on several pages, near a page marker on most of them, with the page number at
 *   either end or none; odd and even pages may carry different ones), bare page numbers, stray
 *   reference numbers `[12]` a two-column page left in the text, and the publisher's lines (reprint
 *   orders, the ISSN and price, the copyright line, the correspondence address with its e-mail and
 *   telephone, an ahead-of-print disclaimer);
 * - joins a paragraph cut by a page break, a figure or table caption or a removed head to the
 *   paragraph that carries it on (it ends in no full stop, the next starts in lower case or with a
 *   citation); a word hyphenated over the cut is joined without its hyphen unless the text writes
 *   it with one elsewhere; the markers and captions skipped go after the joined paragraph, so a
 *   passage still names the page it starts on (SourceText::MARKER);
 * - makes a numbered heading run into its paragraph (`2. MYELIN AND MYELINOGENESIS Myelination…`,
 *   `3.1. Based on Contrast Difference on MR Sequences MR imaging is…`) a heading of its own;
 * - writes a word pdftotext glued to a number (`T1weighted`, `2years`) as the text writes it
 *   elsewhere (`T1-weighted`, `2 years`), never by guess;
 * - turns the reference list kept as printed (a `text` fence under `## References`) into a markdown
 *   list, each number paired with its entry again (referenceList()).
 *
 * What it cannot mend it reports: a paragraph still cut (`open_end`, `open_start`), a table the PDF
 * flattened into short lines (`table`), reference numbers that do not pair with the entries
 * (`references_unpaired`).
 */
final class TextFlow
{
    /** A block shorter than this may be a running head */
    private const HEAD_MAX = 90;

    /** How many blocks after a page marker count as its top */
    private const NEAR_MARKER = 3;

    /** The most blocks (markers, captions) a cut paragraph is looked past for its rest */
    private const SKIP_MAX = 8;

    /** Consecutive short lines without a full stop read as a flattened table */
    private const TABLE_RUN = 5;

    /** Words a title may hold in lower case */
    private const SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'or', 'over', 'the', 'to', 'vs', 'versus', 'with', 'without', 'și', 'de', 'la', 'în', 'cu', 'din', 'pe'];

    /** A block that is the publisher's, not the article's */
    private const BOILERPLATE = [
        '/^send orders for reprints\b/iu',
        '/^disclaimer:/iu',
        '/^©\s*(?:\d{4}|x{4})\b/iu',
        '/^(?:copyright\s*)?©/iu',
        '/^\*?address (?:for )?(?:correspondence|reprint requests?)\b/iu',
        '/^(?:\*\s*)?corresponding author\b/iu',
        '/^(?:this is an open access article|all rights reserved)/iu',
        '/^(?:downloaded from|for personal use only)\b/iu',
    ];

    /** The same, inside a block: [pattern, what it leaves] */
    private const INLINE_BOILERPLATE = [
        // From the correspondence note to its price line, or the block's end
        '/\s*\*?Address (?:for )?(?:correspondence|reprint requests?) to\b.*?(?:\$\d+\.\d{2}\+\.\d{2}|$)/su',
        '/\s*Send Orders for Reprints to \S+/u',
        '/\s*\b\d{4}-\d{3}[\dX]\/\S+\s+\$\d+\.\d{2}\+\.\d{2}/u',
        '/\s*©\s*(?:\d{4}|X{4})\b[^.]{0,100}?(?:Publishers?|Ltd\.?|Inc\.?|GmbH|B\.V\.|All rights reserved\.?)\s*$/u',
    ];

    /** A figure or table caption */
    private const CAPTION = '/^(?:\*\*)?(?:fig(?:ure)?s?\.?\s*\(?\d+[a-z]?\)?|table\s+\d+|tabel(?:ul)?\s+\d+|fig\.\s*\d+)\s*[.:]/iu';

    /** A reference entry's first line: a surname and initials */
    private const AUTHOR = '/^\p{Lu}[\p{L}\'’-]+(?:\s+(?:van|von|de|der|den|da|di|du|le|la|del|dos))*(?:\s+\p{Lu}[\p{L}\'’-]+)?,?\s+\p{Lu}(?:-?\p{Lu}){0,3}\b[,.]?/u';

    /** The page markers SourceText writes */
    private const MARKER = '/^\*— p\. \d+ —\*$/u';

    /**
     * @return array{text: string, fixed: array<string, int>, issues: list<array{code: string, quote: string}>}
     */
    public static function fix(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $fixed = ['heads' => 0, 'boilerplate' => 0, 'joined' => 0, 'headings' => 0, 'words' => 0, 'references' => 0];
        $issues = [];

        [$main, $refHead, $refs] = self::cutReferences($body);
        // Until it settles (a join moves a marker, which may show another head at a page's top), three passes at most
        $heads = [];
        $text = $main;
        for ($pass = 0; $pass < 3; ++$pass) {
            $blocks = self::blocks($text);
            $heads += self::runningHeads($blocks);
            $blocks = self::dropJunk($blocks, $heads, $fixed);
            $blocks = self::splitRunIns($blocks, $fixed);
            $blocks = self::join($blocks, $main, $fixed);
            $next = self::unglue(implode("\n\n", array_column($blocks, 'text')), $fixed);
            if ($next === $text) {
                break;
            }
            $text = $next;
        }

        if ($refHead !== '') {
            if (preg_match('/^(`{3,}|~{3,})text\n(.*?)\n\1\s*$/su', trim($refs), $m) === 1) {
                $list = self::referenceList($m[2], $heads);
                $fixed['references'] = $list['entries'];
                if (!$list['paired'] && $list['entries'] > 0) {
                    $issues[] = ['code' => 'references_unpaired', 'quote' => ''];
                }
                $refs = $list['text'];
            }
            $text = rtrim($text) . "\n\n" . $refHead . "\n\n" . trim($refs);
        }

        foreach (self::blocks($text) as $i => $block) {
            if ($block['kind'] !== 'text') {
                continue;
            }
            $t = $block['text'];
            if (self::isOpenEnd($t) && mb_strlen($t) >= 40) {
                $issues[] = ['code' => 'open_end', 'quote' => self::tail($t)];
            }
            if (self::startsLower($t)) {
                $issues[] = ['code' => 'open_start', 'quote' => self::head($t)];
            }
        }
        foreach (self::tables(self::blocks($text)) as $quote) {
            $issues[] = ['code' => 'table', 'quote' => $quote];
        }

        return [
            'text' => trim($text) . ($text !== '' ? "\n" : ''),
            'fixed' => array_filter($fixed),
            'issues' => $issues,
        ];
    }

    /**
     * A reference list as printed (pdftotext's lines: numbers apart from entries in two columns, an
     * entry wrapped over lines, running heads among them) as a markdown list: `1. …` numbered when
     * its numbers pair with the entries, else `- …`. Addresses become links.
     *
     * @param array<string, true> $heads running heads' shapes to leave out
     *
     * @return array{text: string, entries: int, paired: bool}
     */
    public static function referenceList(string $raw, array $heads = []): array
    {
        $lines = [];
        $skipping = false;
        foreach (preg_split('/\R/u', trim(str_replace("\r", '', $raw))) ?: [] as $line) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($t === '') {
                $skipping = false;
                $lines[] = '';
                continue;
            }
            if ($skipping || preg_match('/^disclaimer:/iu', $t) === 1) {
                $skipping = true;
                continue;
            }
            if (isset($heads[self::shape($t)]) || self::isPublisher($t) || preg_match('/^\d{1,4}$/', $t) === 1 && !self::followsPmid($lines)) {
                continue;
            }
            // A head the body did not show: a few words with no full stop, digit or comma, after an entry ended
            if (preg_match('/^[\p{L}\s:&-]+$/u', $t) === 1 && \count(explode(' ', $t)) <= 6 && self::entryEnded($lines)) {
                continue;
            }
            $lines[] = $t;
        }

        // Numbered inline (`[1] Name …`, `1. Name …`): each number starts its entry
        $inline = '/^(?:\[(\d{1,4})\]|(\d{1,4})[.)])\s+(?=\S)/u';
        $numbers = [];
        $entries = [];
        $first = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''))[0] ?? '';
        if (preg_match($inline, $first) === 1) {
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                if (preg_match($inline, $line, $m) === 1) {
                    $numbers[] = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                    $entries[] = trim(substr($line, \strlen($m[0])));
                } elseif ($entries !== []) {
                    $entries[\count($entries) - 1] = self::joinLine($entries[\count($entries) - 1], $line);
                } else {
                    $entries[] = $line;
                }
            }
        } else {
            $prev = '';
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                // Lines of numbers alone (`[1]`, `[8] [9]`): the column a two-column page set apart
                if (preg_match('/^(?:\[\d{1,4}\]\s*)+$/u', $line) === 1) {
                    preg_match_all('/\[(\d{1,4})\]/', $line, $m);
                    array_push($numbers, ...array_map('intval', $m[1]));
                    continue;
                }
                if ($entries === [] || self::startsEntry($line, $prev)) {
                    $entries[] = $line;
                } else {
                    $entries[\count($entries) - 1] = self::joinLine($entries[\count($entries) - 1], $line);
                }
                $prev = $line;
            }
        }
        $entries = array_values(array_filter(array_map(static function (string $e): string {
            $e = (string) preg_replace('/\b(\d{5,9})\s+PMID:\s*$/u', 'PMID: $1', trim($e));

            return trim((string) preg_replace('/\s+/u', ' ', $e));
        }, $entries), static fn (string $e): bool => $e !== ''));
        if ($entries === []) {
            return ['text' => '', 'entries' => 0, 'paired' => true];
        }

        $sorted = $numbers;
        sort($sorted);
        $paired = $numbers !== [] && \count($numbers) === \count($entries) && $sorted === range($sorted[0], $sorted[0] + \count($sorted) - 1);
        $out = [];
        foreach ($entries as $i => $entry) {
            $out[] = ($paired ? ($sorted[$i]) . '. ' : '- ') . self::escapeEntry($entry);
        }

        return ['text' => implode("\n", $out), 'entries' => \count($entries), 'paired' => $paired || $numbers === []];
    }

    /**
     * The body before the library's reference list, its heading and the list
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function cutReferences(string $body): array
    {
        $at = strrpos("\n" . $body, "\n## References\n");
        if ($at === false) {
            return [$body, '', ''];
        }

        return [substr($body, 0, max(0, $at - 1)), '## References', substr($body, $at + \strlen('## References'))];
    }

    /**
     * The body's blocks; a fenced block stays one, whatever blank lines it holds
     *
     * @return list<array{kind: string, text: string, page: int}>
     */
    private static function blocks(string $text): array
    {
        $blocks = [];
        $current = [];
        $fence = null;
        $page = 0;
        $flush = static function () use (&$blocks, &$current, &$page): void {
            if ($current === []) {
                return;
            }
            $text = implode("\n", $current);
            $kind = self::kind($text);
            if ($kind === 'marker') {
                ++$page;
            }
            $blocks[] = ['kind' => $kind, 'text' => $text, 'page' => $page];
            $current = [];
        };
        foreach (explode("\n", $text) as $line) {
            if ($fence !== null) {
                $current[] = $line;
                if (preg_match('/^\s{0,3}' . preg_quote($fence, '/') . '[`~]*\s*$/', $line) === 1) {
                    $fence = null;
                    $flush();
                }
                continue;
            }
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $flush();
                $fence = $m[1];
                $current[] = $line;
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            $current[] = rtrim($line);
        }
        $flush();

        return $blocks;
    }

    private static function kind(string $text): string
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $text) === 1) {
            return 'code';
        }
        if (preg_match(self::MARKER, $text) === 1) {
            return 'marker';
        }
        if (preg_match('/^#{1,6}\s/', $text) === 1) {
            return 'heading';
        }
        if (preg_match('/^(?:[-*+]\s|\d{1,9}[.)]\s|\||>|<|\s{4})/', $text) === 1 || str_contains($text, "\n|")) {
            return 'other';
        }
        if (preg_match(self::CAPTION, $text) === 1) {
            return 'caption';
        }

        return 'text';
    }

    /** A head's shape: case folded, the page number at either end dropped, other digits folded */
    private static function shape(string $text): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        $t = (string) preg_replace('/^\d{1,4}\s+|\s+\d{1,4}$/u', '', $t);

        return mb_strtolower((string) preg_replace('/\d+/', '#', $t));
    }

    /**
     * The shapes of the short blocks that stand alone on two pages or more, at the top of most
     *
     * @param list<array{kind: string, text: string, page: int}> $blocks
     *
     * @return array<string, true>
     */
    private static function runningHeads(array $blocks): array
    {
        $pages = [];
        $near = [];
        $since = 0;
        foreach ($blocks as $block) {
            if ($block['kind'] === 'marker') {
                $since = 0;
                continue;
            }
            ++$since;
            if ($block['kind'] !== 'text' || mb_strlen($block['text']) > self::HEAD_MAX || str_contains($block['text'], "\n")
                || preg_match('/[!?]$/u', $block['text']) === 1 || $block['page'] === 0
                || str_ends_with($block['text'], '.') && \count(explode(' ', $block['text'])) > 6) {
                continue;
            }
            $shape = self::shape($block['text']);
            if (mb_strlen($shape) < 4) {
                continue;
            }
            $pages[$shape][$block['page']] = true;
            if ($since <= self::NEAR_MARKER) {
                $near[$shape][$block['page']] = true;
            }
        }
        $heads = [];
        foreach ($pages as $shape => $on) {
            $n = \count($near[$shape] ?? []);
            if (\count($on) >= 2 && $n >= 2 && 2 * $n >= \count($on)) {
                $heads[(string) $shape] = true;
            }
        }

        return $heads;
    }

    /**
     * @param list<array{kind: string, text: string, page: int}> $blocks
     * @param array<string, true> $heads
     * @param array<string, int> $fixed
     *
     * @return list<array{kind: string, text: string, page: int}>
     */
    private static function dropJunk(array $blocks, array $heads, array &$fixed): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if ($block['kind'] !== 'text') {
                $out[] = $block;
                continue;
            }
            $t = $block['text'];
            if (isset($heads[self::shape($t)]) && mb_strlen($t) <= self::HEAD_MAX) {
                ++$fixed['heads'];
                continue;
            }
            if (preg_match('/^\d{1,4}$/', $t) === 1 || preg_match('/^(?:\\\\?\[\d{1,4}\\\\?\]\s*)+$/u', $t) === 1) {
                ++$fixed['heads'];
                continue;
            }
            if (self::isPublisher($t)) {
                ++$fixed['boilerplate'];
                continue;
            }
            foreach (self::INLINE_BOILERPLATE as $pattern) {
                $cut = (string) preg_replace($pattern, '', $t);
                if ($cut !== $t) {
                    ++$fixed['boilerplate'];
                    $t = $cut;
                }
            }
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            $block['text'] = $t;
            $block['kind'] = self::kind($t);
            $out[] = $block;
        }

        return $out;
    }

    private static function isPublisher(string $t): bool
    {
        foreach (self::BOILERPLATE as $pattern) {
            if (preg_match($pattern, $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Numbered headings that run into their paragraph, at its start or after a sentence, made blocks of their own
     *
     * @param list<array{kind: string, text: string, page: int}> $blocks
     * @param array<string, int> $fixed
     *
     * @return list<array{kind: string, text: string, page: int}>
     */
    private static function splitRunIns(array $blocks, array &$fixed): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if ($block['kind'] !== 'text') {
                $out[] = $block;
                continue;
            }
            $pieces = preg_split('/(?<=[.!?\]])\s+(?=\d{1,2}(?:\.\d{1,2}){0,3}\\\\?\.\s+\p{Lu})/u', $block['text']) ?: [$block['text']];
            $rest = '';
            foreach ($pieces as $k => $piece) {
                $split = self::runIn($piece);
                if ($split === null) {
                    $rest = $rest === '' ? $piece : $rest . ' ' . $piece;
                    continue;
                }
                if ($rest !== '') {
                    $out[] = ['kind' => 'text', 'text' => $rest, 'page' => $block['page']];
                    $rest = '';
                }
                ++$fixed['headings'];
                $out[] = ['kind' => 'heading', 'text' => $split[0], 'page' => $block['page']];
                $rest = $split[1];
            }
            if ($rest !== '') {
                $out[] = ['kind' => self::kind($rest), 'text' => $rest, 'page' => $block['page']];
            }
        }

        return $out;
    }

    /**
     * A numbered heading at the start of $text: [`## 2. TITLE`, the rest], or null
     *
     * @return array{0: string, 1: string}|null
     */
    private static function runIn(string $text): ?array
    {
        if (preg_match('/^(\d{1,2}(?:\.\d{1,2}){0,3})\\\\?\.\s+/u', $text, $m) !== 1) {
            return null;
        }
        $number = $m[1];
        $tokens = preg_split('/\s+/u', trim(substr($text, \strlen($m[0])))) ?: [];
        if ($tokens === []) {
            return null;
        }
        $level = str_contains($number, '.') ? '###' : '##';

        // ALL CAPS: the run of capital words; a capital word just before a lower-case one begins the sentence
        $caps = 0;
        while ($caps < \count($tokens) && self::isCapsWord($tokens[$caps])) {
            ++$caps;
        }
        if ($caps >= 2 || $caps === 1 && mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $tokens[0])) > 3) {
            if ($caps < \count($tokens) && preg_match('/^\p{Ll}/u', $tokens[$caps]) === 1) {
                --$caps;
            }
            if ($caps >= 1) {
                // A citation after the title stays with it
                while ($caps < \count($tokens) && preg_match('/^\\\\?\[[\d,–-]+\\\\?\]$/u', $tokens[$caps]) === 1) {
                    ++$caps;
                }

                return [$level . ' ' . $number . '. ' . implode(' ', \array_slice($tokens, 0, $caps)), implode(' ', \array_slice($tokens, $caps))];
            }

            return null;
        }

        // Title Case, sub-sections only: up to the parenthesis that ends it, or the word before the first lower-case one
        if (!str_contains($number, '.') || preg_match('/^\p{Lu}/u', $tokens[0]) !== 1) {
            return null;
        }
        $end = null;
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($i >= 14) {
                return null;
            }
            if ($depth > 0 || str_starts_with($token, '(')) {
                $depth += substr_count($token, '(') - substr_count($token, ')');
                if ($depth <= 0) {
                    $end = $i + 1;
                    break;
                }
                continue;
            }
            if (preg_match('/^\p{Lu}|^\d/u', $token) === 1 || \in_array(mb_strtolower($token), self::SMALL_WORDS, true)) {
                if (preg_match('/[.!?:]$/u', $token) === 1 && $i > 0) {
                    return null;
                }
                continue;
            }
            // A lower-case word: the capital word before it began the sentence
            $end = $i - 1;
            break;
        }
        if ($end === null) {
            // The whole block: a heading alone, when it has no full stop
            return preg_match('/[.!?]$/u', $text) === 1 || \count($tokens) > 12 ? null : [$level . ' ' . $number . '. ' . implode(' ', $tokens), ''];
        }
        if ($end < 1 || \in_array(mb_strtolower($tokens[$end - 1]), self::SMALL_WORDS, true)) {
            return null;
        }
        $rest = implode(' ', \array_slice($tokens, $end));
        if ($rest !== '' && preg_match('/^\p{Lu}/u', $rest) !== 1) {
            return null;
        }

        return [$level . ' ' . $number . '. ' . implode(' ', \array_slice($tokens, 0, $end)), $rest];
    }

    private static function isCapsWord(string $token): bool
    {
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $token);

        return $letters !== '' && (mb_strlen($letters) > 1 || preg_match('/^[AI&]$/u', $letters) === 1) && mb_strtoupper($letters) === $letters
            || preg_match('/^(?:&|-|–|\d+)$/u', $token) === 1;
    }

    /**
     * Paragraphs cut by a page break, a caption or a removed head joined again; what stood between goes after
     *
     * @param list<array{kind: string, text: string, page: int}> $blocks
     * @param array<string, int> $fixed
     *
     * @return list<array{kind: string, text: string, page: int}>
     */
    private static function join(array $blocks, string $whole, array &$fixed): array
    {
        $out = [];
        $n = \count($blocks);
        $used = [];
        for ($i = 0; $i < $n; ++$i) {
            if (isset($used[$i])) {
                continue;
            }
            $block = $blocks[$i];
            $after = [];
            while ($block['kind'] === 'text' && self::isOpenEnd($block['text'])) {
                $skipped = [];
                $next = null;
                for ($j = $i + 1; $j < $n && $j <= $i + self::SKIP_MAX + \count($after); ++$j) {
                    if (isset($used[$j])) {
                        continue;
                    }
                    $kind = $blocks[$j]['kind'];
                    if ($kind === 'marker' || $kind === 'caption') {
                        $skipped[] = $j;
                        continue;
                    }
                    $next = $kind === 'text' && self::carriesOn($blocks[$j]['text']) ? $j : null;
                    break;
                }
                if ($next === null) {
                    break;
                }
                $block['text'] = self::glue($block['text'], $blocks[$next]['text'], $whole);
                ++$fixed['joined'];
                $used[$next] = true;
                foreach ($skipped as $s) {
                    $used[$s] = true;
                    $after[] = $blocks[$s];
                }
            }
            $out[] = $block;
            array_push($out, ...$after);
        }

        return $out;
    }

    /** A paragraph that stops mid-sentence: no closing mark, or a word broken by a hyphen */
    private static function isOpenEnd(string $t): bool
    {
        if (str_contains($t, "\n") && preg_match('/\n\s*(?:[-*+]|\d+[.)])\s/u', $t) === 1) {
            return false;
        }

        return preg_match('/[\p{Ll}\d,;(–-]$/u', $t) === 1 && preg_match('/^#/', $t) !== 1;
    }

    private static function startsLower(string $t): bool
    {
        return preg_match('/^["“(]?\p{Ll}/u', $t) === 1;
    }

    /** A block that carries on a sentence: lower case, a citation, a closing mark */
    private static function carriesOn(string $t): bool
    {
        return self::startsLower($t) || preg_match('/^(?:\\\\?\[\d|[,;.)])/u', $t) === 1;
    }

    private static function glue(string $a, string $b, string $whole): string
    {
        if (preg_match('/([\p{L}\d]+)-$/u', $a, $m) === 1 && preg_match('/^(\p{Ll}+)/u', $b, $n) === 1) {
            $hyphenated = $m[1] . '-' . $n[1];
            // A word the text writes with its hyphen elsewhere keeps it, and so does a number's (`T2-weighted`)
            if (preg_match('/\d$/u', $m[1]) === 1 || preg_match('/(?<!\p{L})' . preg_quote($hyphenated, '/') . '(?!\p{L})/u', $whole) === 1) {
                return $a . $b;
            }

            return substr($a, 0, -1) . $b;
        }
        // A word cut without a hyphen (a note was taken out between): one word when the text writes it
        // whole elsewhere and its second half stands nowhere else as a word of its own
        if (preg_match('/(?<![\p{L}])(\p{Ll}+)$/u', $a, $m) === 1 && preg_match('/^(\p{Ll}+)/u', $b, $n) === 1
            && preg_match('/(?<!\p{L})' . preg_quote($m[1] . $n[1], '/') . '(?!\p{L})/iu', $whole) === 1
            && preg_match_all('/(?<!\p{L})' . preg_quote($n[1], '/') . '(?!\p{L})/u', $whole) <= 1) {
            return $a . $b;
        }
        if (preg_match('/^[,;.)]/u', $b) === 1) {
            return $a . $b;
        }

        return $a . ' ' . $b;
    }

    /** `T1weighted`, `2years`: written as the text writes them elsewhere */
    private static function unglue(string $text, array &$fixed): string
    {
        return (string) preg_replace_callback('/(?<![\p{L}\d.-])(\p{Lu}?\d{1,3})(\p{Ll}{3,})(?![\p{L}\d])/u', static function (array $m) use ($text, &$fixed): string {
            if (preg_match('/(?<![\p{L}\d])' . preg_quote($m[1] . '-' . $m[2], '/') . '(?!\p{L})/u', $text) === 1) {
                ++$fixed['words'];

                return $m[1] . '-' . $m[2];
            }
            if (ctype_digit($m[1]) && preg_match('/(?<![\p{L}\d])\d{1,3} ' . preg_quote($m[2], '/') . '(?!\p{L})/u', $text) === 1) {
                ++$fixed['words'];

                return $m[1] . ' ' . $m[2];
            }

            return $m[0];
        }, $text);
    }

    /**
     * Runs of short lines without a full stop: a table the PDF flattened (shown, never mended)
     *
     * @param list<array{kind: string, text: string, page: int}> $blocks
     *
     * @return list<string>
     */
    private static function tables(array $blocks): array
    {
        $found = [];
        $run = [];
        $blocks[] = ['kind' => 'end', 'text' => '', 'page' => 0];
        foreach ($blocks as $block) {
            $short = $block['kind'] === 'text' && mb_strlen($block['text']) < 80 && preg_match('/[.!?]$/u', $block['text']) !== 1;
            if ($short || $block['kind'] === 'caption' && $run !== []) {
                $run[] = $block['text'];
                continue;
            }
            if ($block['kind'] === 'marker' && $run !== []) {
                continue;
            }
            if (\count($run) >= self::TABLE_RUN) {
                $found[] = self::head($run[0]);
            }
            $run = [];
        }

        return $found;
    }

    /** A new reference entry: after a PMID or an address ended the last, or a surname after a full stop */
    private static function startsEntry(string $line, string $prev): bool
    {
        if (preg_match('/^(?:PMID|doi|https?:|\d)/iu', $line) === 1 || preg_match('/^\p{Ll}/u', $line) === 1) {
            return false;
        }
        if (preg_match('/(?:PMID:?\s*\d{5,9}|^\d{5,9})\.?$/u', $prev) === 1) {
            return true;
        }
        if (preg_match('/https?:\/\/\S+$/u', $prev) === 1 && preg_match('/^\p{Lu}\S*\s/u', $line) === 1) {
            return true;
        }

        return preg_match('/[.\d)]$/u', $prev) === 1 && preg_match(self::AUTHOR, $line) === 1;
    }

    /** @param list<string> $lines */
    private static function entryEnded(array $lines): bool
    {
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if ($lines[$i] !== '') {
                return preg_match('/(?:PMID:?\s*\d{5,9}|^\d{5,9}|\d\.)$/u', $lines[$i]) === 1;
            }
        }

        return false;
    }

    /** @param list<string> $lines */
    private static function followsPmid(array $lines): bool
    {
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            if ($lines[$i] !== '') {
                return preg_match('/PMID:?$/u', $lines[$i]) === 1 || preg_match('/https?:\/\/\S+$/u', $lines[$i]) === 1;
            }
        }

        return false;
    }

    /** An entry's next line: a word broken by a hyphen or an address broken mid-way joins without a space */
    private static function joinLine(string $entry, string $line): string
    {
        if (preg_match('/https?:\/\/\S+$/u', $entry) === 1 && preg_match('/^[\w\-.\/?=&%#~]+$/u', $line) === 1 && preg_match('/^\d{5,9}$/', $line) !== 1) {
            return $entry . $line;
        }
        if (preg_match('/\p{Ll}-$/u', $entry) === 1 && preg_match('/^\p{Ll}/u', $line) === 1) {
            return substr($entry, 0, -1) . $line;
        }

        return $entry . ' ' . $line;
    }

    /** An entry as markdown text: nothing it never was, addresses as links */
    private static function escapeEntry(string $entry): string
    {
        $urls = [];
        $entry = (string) preg_replace_callback('/(?:from:)?\Khttps?:\/\/[^\s<>]+/u', static function (array $m) use (&$urls): string {
            $url = rtrim($m[0], '.,;)');
            $urls[] = $url;

            return "\0" . (\count($urls) - 1) . "\0" . substr($m[0], \strlen($url));
        }, $entry);
        $entry = str_replace(['\\', '<', '[', ']', '*', '`', '_'], ['\\\\', '&lt;', '\\[', '\\]', '\\*', '\\`', '\\_'], $entry);

        return (string) preg_replace_callback('/\0(\d+)\0/', static fn (array $m): string => '<' . $urls[(int) $m[1]] . '>', $entry);
    }

    private static function tail(string $t): string
    {
        return mb_strlen($t) > 60 ? mb_substr($t, -60) : $t;
    }

    private static function head(string $t): string
    {
        $line = strtok($t, "\n");

        return mb_substr($line === false ? $t : $line, 0, 60);
    }
}
