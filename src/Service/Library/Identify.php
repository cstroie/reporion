<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Support\ArticleIds;
use Reporion\Support\SourceText;

/**
 * Which article a file is, offline (docs/library.md, pipeline step 4): a proposed record in
 * CSL-JSON for the writer to confirm. Each field from the first place that has it: the PDF's XMP
 * metadata, then its document info, then page 1's layout (the largest font is the title, the
 * lines under it the authors), then the text. Pure: guesses, never asks a service; the online
 * lookup (phase 4) only improves on it.
 */
final class Identify
{
    /** Titles a PDF's info carries that are no title */
    private const JUNK_TITLE = '/^(untitled|microsoft word|document\d*|title|\S+\.(?:docx?|pdf|tex|indd|rtf))\b|\.(?:docx?|pdf|indd)$/i';

    /**
     * @return array{csl: array<string, mixed>, how: array<string, string>} `how`: field => where it was found
     */
    public static function propose(Extracted $x): array
    {
        $csl = ['type' => 'article-journal'];
        $how = [];
        $set = static function (string $field, mixed $value, string $from) use (&$csl, &$how): void {
            if ($value === null || $value === '' || $value === [] || isset($csl[$field])) {
                return;
            }
            $csl[$field] = $value;
            $how[$field] = $from;
        };

        $xmp = self::xmp($x->xmp);
        foreach ($xmp as $field => $value) {
            $set($field, $value, 'xmp');
        }

        $ids = ArticleIds::find($x->head);
        $set('DOI', $ids['doi'][0] ?? null, 'text');
        $set('PMID', $ids['pmid'][0] ?? null, 'text');
        $set('PMCID', $ids['pmcid'][0] ?? null, 'text');
        $set('ISBN', $ids['isbn'][0] ?? null, 'text');
        if (isset($ids['arxiv'][0])) {
            $set('number', 'arXiv:' . $ids['arxiv'][0], 'text');
        }

        $infoTitle = trim($x->info['Title'] ?? '');
        if (self::plausibleTitle($infoTitle)) {
            $set('title', $infoTitle, 'info');
        }
        $infoAuthors = self::authors(trim($x->info['Author'] ?? ''));
        if (\count($infoAuthors) > 0 && !self::looksLikeSoftware($x->info['Author'] ?? '')) {
            $set('author', $infoAuthors, 'info');
        }

        [$layoutTitle, $layoutAuthors] = self::fromLayout($x->lines);
        $set('title', $layoutTitle, 'layout');
        $set('author', $layoutAuthors, 'layout');

        // A text with no layout (a docx, a txt): its first heading, else its first short line
        if (!isset($csl['title']) && preg_match('/^#\s+(.+)$/m', $x->body, $m) === 1) {
            $set('title', trim($m[1]), 'text');
        }

        $year = self::year($x->head);
        if ($year !== null) {
            $set('issued', ['date-parts' => [[$year]]], 'text');
        }
        if (isset($csl['ISBN']) && !isset($csl['DOI'])) {
            $csl['type'] = 'book';
        }
        // The text's first line, else nothing: never the file's name, which may name a person
        $set('title', self::firstLine($x->body), 'text');
        $set('title', 'Untitled document', 'none');

        return ['csl' => $csl, 'how' => $how];
    }

    /** @return array<string, mixed> the CSL fields the XMP packet names */
    public static function xmp(string $xmp): array
    {
        if ($xmp === '') {
            return [];
        }
        $one = static function (string $tag) use ($xmp): ?string {
            if (preg_match('~<' . preg_quote($tag, '~') . '(?:\s[^>]*)?>(.*?)</' . preg_quote($tag, '~') . '>~s', $xmp, $m) !== 1) {
                return null;
            }
            // An rdf:Alt / rdf:Seq holds its value in rdf:li
            if (preg_match_all('~<rdf:li[^>]*>(.*?)</rdf:li>~s', $m[1], $li) > 0) {
                $m[1] = $li[1][0];
            }
            $value = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));

            return $value !== '' ? $value : null;
        };

        $fields = [];
        $doi = $one('prism:doi') ?? $one('pdfx:doi') ?? $one('dc:identifier');
        if ($doi !== null && ($normalized = ArticleIds::normalizeDoi($doi)) !== null) {
            $fields['DOI'] = $normalized;
        }
        $title = $one('dc:title');
        if ($title !== null && self::plausibleTitle($title)) {
            $fields['title'] = $title;
        }
        if (preg_match('~<dc:creator[^>]*>(.*?)</dc:creator>~s', $xmp, $m) === 1 && preg_match_all('~<rdf:li[^>]*>(.*?)</rdf:li>~s', $m[1], $li) > 0) {
            $authors = [];
            foreach ($li[1] as $name) {
                $authors = array_merge($authors, self::authors(trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_XML1, 'UTF-8'))));
            }
            if ($authors !== []) {
                $fields['author'] = $authors;
            }
        }
        foreach (['prism:publicationName' => 'container-title', 'prism:volume' => 'volume', 'prism:number' => 'issue', 'prism:issn' => 'ISSN'] as $tag => $field) {
            $value = $one($tag);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }
        $first = $one('prism:startingPage');
        if ($first !== null) {
            $last = $one('prism:endingPage');
            $fields['page'] = $first . ($last !== null && $last !== $first ? '-' . $last : '');
        }
        $date = $one('prism:coverDate') ?? $one('prism:publicationDate');
        if ($date !== null && preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $d) === 1) {
            $fields['issued'] = ['date-parts' => [array_map('intval', \array_slice($d, 1))]];
        }

        return $fields;
    }

    /**
     * People's names as written in a byline or a PDF's Author field: "Ann Nobody, Bob B. Nobody and
     * C Nobody", "Nobody A, Nobody BC" (Vancouver), "Nobody, Ann; Nobody, Bob"
     *
     * @return list<array{family: string, given?: string}>
     */
    public static function authors(string $line): array
    {
        // Superscript affiliation marks and symbols the byline carries
        $line = (string) preg_replace('/[\d*†‡§¶#]+(?=[,;\s]|$)|\s+\d+(?:,\d+)*\b/u', '', $line);
        $line = trim((string) preg_replace('/\s+/u', ' ', $line), ' ,;.');
        if ($line === '' || mb_strlen($line) > 600) {
            return [];
        }
        $semi = str_contains($line, ';');
        $parts = preg_split($semi ? '/\s*;\s*/' : '/\s*,\s*|\s+(?:and|&|și|si)\s+/iu', $line) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        $authors = [];
        foreach ($parts as $part) {
            $person = self::person($part, $semi);
            if ($person === null) {
                return [];
            }
            $authors[] = $person;
        }

        return \count($authors) <= 100 ? $authors : [];
    }

    /** @return array{family: string, given?: string}|null null: not a person's name */
    private static function person(string $name, bool $commaInverted): ?array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($commaInverted && str_contains($name, ',')) {
            [$family, $given] = array_map('trim', explode(',', $name, 2));

            return self::valid($family) ? array_filter(['family' => $family, 'given' => $given], static fn (string $v): bool => $v !== '') : null;
        }
        $words = explode(' ', $name);
        if (\count($words) < 2 || \count($words) > 5) {
            return null;
        }
        // Vancouver: "Nobody AB": the last word is initials only
        $last = (string) end($words);
        if (preg_match('/^\p{Lu}{1,3}$/u', $last) === 1) {
            $family = implode(' ', \array_slice($words, 0, -1));

            return self::valid($family) ? ['family' => $family, 'given' => implode('. ', mb_str_split($last)) . '.'] : null;
        }
        // "Ann B. van Nobody": a lower-case particle starts the family name
        $at = \count($words) - 1;
        while ($at > 1 && preg_match('/^(?:van|von|de|der|den|da|del|di|du|la|le)$/i', $words[$at - 1]) === 1) {
            --$at;
        }
        $family = implode(' ', \array_slice($words, $at));
        $given = implode(' ', \array_slice($words, 0, $at));

        foreach (\array_slice($words, 0, $at) as $word) {
            if (preg_match('/^\p{Lu}[\p{L}.\'’\-]*$/u', $word) !== 1 || self::isNotAName($word)) {
                return null;
            }
        }

        return self::valid($family) ? ['family' => $family, 'given' => $given] : null;
    }

    private static function valid(string $family): bool
    {
        $core = (string) preg_replace('/^(?:(?:van|von|de|der|den|da|del|di|du|la|le)\s+)+/i', '', $family);
        if (preg_match('/^\p{Lu}[\p{L}\'’\- ]{1,40}$/u', $core) !== 1) {
            return false;
        }
        foreach (explode(' ', $core) as $word) {
            if (self::isNotAName($word)) {
                return false;
            }
        }

        return true;
    }

    /** Words a byline-shaped line holds that no name does */
    private static function isNotAName(string $word): bool
    {
        return preg_match('/^(?:university|universit[yaă]|hospital|spital|department|dept|institute|institut|journal|clinic|clinica|center|centre|school|faculty|received|accepted|published|revised|online|abstract|keywords?|correspondence|email|e-mail|doi|copyright|volume|issue|january|february|march|april|may|june|july|august|september|october|november|december|radiology|medicine|imaging)\.?$/iu', $word) === 1;
    }

    private static function looksLikeSoftware(string $author): bool
    {
        return preg_match('/\b(?:adobe|microsoft|acrobat|latex|tex|elsevier|springer|wiley|user|admin)\b/i', $author) === 1;
    }

    public static function plausibleTitle(string $title): bool
    {
        $words = preg_split('/\s+/u', trim($title)) ?: [];

        return \count($words) >= 3 && mb_strlen($title) <= 400 && preg_match(self::JUNK_TITLE, trim($title)) !== 1;
    }

    /**
     * The title: the lines in the largest font that make 3 words or more, read top to bottom; the
     * authors: the next line under them that reads as a byline
     *
     * @param list<array{size: float, top: int, text: string}> $lines
     *
     * @return array{0: ?string, 1: list<array{family: string, given?: string}>}
     */
    public static function fromLayout(array $lines): array
    {
        if ($lines === []) {
            return [null, []];
        }
        $sizes = array_unique(array_map(static fn (array $l): float => $l['size'], $lines));
        rsort($sizes);
        foreach (\array_slice($sizes, 0, 3) as $size) {
            $block = [];
            $lastIndex = null;
            foreach ($lines as $i => $line) {
                if (abs($line['size'] - $size) < 0.5) {
                    // One block only: the first run of lines in this size
                    if ($lastIndex !== null && $i > $lastIndex + 1) {
                        break;
                    }
                    $block[] = $line['text'];
                    $lastIndex = $i;
                }
            }
            $title = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $block)));
            if ($lastIndex === null || !self::plausibleTitle($title)) {
                continue;
            }
            $authors = [];
            foreach (\array_slice($lines, $lastIndex + 1, 3) as $line) {
                $authors = self::authors($line['text']);
                if ($authors !== []) {
                    break;
                }
            }

            return [$title, $authors];
        }

        return [null, []];
    }

    /** The year in a citation line (`2024;12(3):45`), a copyright, else the first plausible year */
    public static function year(string $head): ?int
    {
        $head = substr($head, 0, 4000);
        $max = (int) date('Y') + 1;
        foreach (['/\b((?:19|20)\d{2})\s*;\s*\d+/', '/(?:©|\(c\)|copyright)\s*((?:19|20)\d{2})\b/i', '/\b(?:published|accepted|received)[^.\n]{0,40}?\b((?:19|20)\d{2})\b/i', '/\b((?:19|20)\d{2})\b/'] as $pattern) {
            if (preg_match_all($pattern, $head, $m) > 0) {
                foreach ($m[1] as $y) {
                    if ((int) $y >= 1900 && (int) $y <= $max) {
                        return (int) $y;
                    }
                }
            }
        }

        return null;
    }

    /** The first line of the text that reads as a title: 2 to 30 words, not a page marker */
    private static function firstLine(string $body): ?string
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim((string) preg_replace(['/^#+\s*/', '/\\\\([\[\]#>*+\-.)])/'], ['', '$1'], $line));
            if ($line === '' || preg_match(SourceText::MARKER, $line) === 1) {
                continue;
            }
            $words = \count(preg_split('/\s+/u', $line) ?: []);
            $line = mb_strlen($line) > 200 ? mb_substr($line, 0, 200) . '…' : $line;

            return $words >= 2 && $words <= 30 ? $line : null;
        }

        return null;
    }
}
