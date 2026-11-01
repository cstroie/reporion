<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * An article's own abstract, read from a library source's text (docs/library.md): no AI, pure.
 *
 * Only an abstract the text marks plainly is taken: a heading `Abstract`, `Summary` or `Rezumat`
 * (SourceText makes such a line alone a `##` heading) that starts within the first quarter of
 * the text, then its paragraphs up to the next heading, page markers left out. A structured
 * abstract whose parts became headings of their own (`## Purpose`, `## Methods`, …: the heading's
 * own text then too short to be the abstract) is followed while its parts stay short and none repeats; each part is then written `Purpose: …`, as PubMed
 * writes them. Between MIN_WORDS and MAX_WORDS words, else nothing: a guess is worse than the full text.
 */
final class SourceAbstract
{
    public const MIN_WORDS = 80;
    public const MAX_WORDS = 500;

    /** The heading must start within this share of the text */
    private const WITHIN = 0.25;

    /** A part of a structured abstract is at most this long */
    private const PART_WORDS = 250;

    private const HEADING = '/^#{1,3}[ \t]+(.+?)[ \t]*:?[ \t]*$/mu';

    private const NAMES = '/^(?:abstract|summary|rezumat)$/iu';

    private const PARTS = '/^(?:background|purpose|aims?|objectives?|introduction|methods?|materials and methods|patients and methods|design|setting|results|findings|conclusions?|interpretation|obiective|metod[aăe]|material(?:e)? și metod[aă]|rezultate|concluzii)$/iu';

    public static function fromText(string $body): ?string
    {
        $text = SourceChunks::withoutReferences(str_replace("\r\n", "\n", $body));
        if ($text === '' || preg_match_all(self::HEADING, $text, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        $limit = (int) (\strlen($text) * self::WITHIN);
        foreach ($headings as $i => $heading) {
            if ($heading[0][1] > $limit) {
                return null;
            }
            if (preg_match(self::NAMES, trim($heading[1][0])) !== 1) {
                continue;
            }
            $parts = [self::section($text, $headings, $i)];
            $seen = [];
            // An abstract long enough on its own is not followed: the heading after it is the article's
            $structured = self::words($parts[0]) < self::MIN_WORDS;
            for ($j = $i + 1; $structured && $j < \count($headings); ++$j) {
                $name = trim($headings[$j][1][0]);
                $key = mb_strtolower($name);
                $part = self::section($text, $headings, $j);
                if (preg_match(self::PARTS, $name) !== 1 || isset($seen[$key]) || self::words($part) > self::PART_WORDS
                    || self::words(implode(' ', $parts) . ' ' . $part) > self::MAX_WORDS) {
                    break;
                }
                $seen[$key] = true;
                $parts[] = $part === '' ? '' : $name . ': ' . $part;
            }
            $abstract = trim(implode("\n\n", array_filter($parts, static fn (string $p): bool => $p !== '')));
            $words = self::words($abstract);

            return $words >= self::MIN_WORDS && $words <= self::MAX_WORDS ? $abstract : null;
        }

        return null;
    }

    /**
     * The paragraphs between heading $i and the next, without page markers
     *
     * @param list<array<int, array{0: string, 1: int}>> $headings
     */
    private static function section(string $text, array $headings, int $i): string
    {
        $start = $headings[$i][0][1] + \strlen($headings[$i][0][0]);
        $end = isset($headings[$i + 1]) ? $headings[$i + 1][0][1] : \strlen($text);
        $section = (string) preg_replace(SourceText::MARKER, '', substr($text, $start, $end - $start));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $section));
    }

    private static function words(string $text): int
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }
}
