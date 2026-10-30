<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The identifiers of a scientific article found in its text (docs/library.md,
 * pipeline step 4): DOI, PMID, PMCID, arXiv id, ISBN. Each list in the order
 * first met, without repeats; the first DOI is usually the article's own (a
 * journal prints it on page 1), later ones are usually its references.
 *
 * Pure: reads text, asks no service.
 */
final class ArticleIds
{
    /** @return array{doi: list<string>, pmid: list<string>, pmcid: list<string>, arxiv: list<string>, isbn: list<string>} */
    public static function find(string $text): array
    {
        return [
            'doi' => self::dois($text),
            'pmid' => self::matches('/\bPMID\s*:?\s*(\d{1,8})\b/i', $text),
            'pmcid' => self::matches('/\b(PMC\d{4,9})\b/', $text),
            'arxiv' => self::matches('/\barXiv\s*:\s*(\d{4}\.\d{4,5})(?:v\d+)?\b/i', $text),
            'isbn' => self::isbns($text),
        ];
    }

    /** A DOI as written in a field or a link, folded to lower case; null when it is not one */
    public static function normalizeDoi(string $doi): ?string
    {
        $doi = trim($doi);
        $doi = (string) preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi\s*:\s*)~i', '', $doi);
        $doi = self::trimDoi($doi);

        return preg_match('~^10\.\d{4,9}/\S+$~', $doi) === 1 ? strtolower($doi) : null;
    }

    /** @return list<string> */
    private static function dois(string $text): array
    {
        preg_match_all('~\b10\.\d{4,9}/[^\s"<>{}]+~', $text, $m);
        $found = [];
        foreach ($m[0] as $raw) {
            $doi = self::normalizeDoi($raw);
            if ($doi !== null && !\in_array($doi, $found, true)) {
                $found[] = $doi;
            }
        }

        return $found;
    }

    /** Punctuation that ends the sentence, not the DOI; a closing bracket only when unbalanced */
    private static function trimDoi(string $doi): string
    {
        while ($doi !== '') {
            $last = substr($doi, -1);
            if (strpbrk($last, '.,;:\'') !== false) {
                $doi = substr($doi, 0, -1);
                continue;
            }
            if ($last === ')' && substr_count($doi, '(') < substr_count($doi, ')')) {
                $doi = substr($doi, 0, -1);
                continue;
            }
            if ($last === ']' && substr_count($doi, '[') < substr_count($doi, ']')) {
                $doi = substr($doi, 0, -1);
                continue;
            }
            break;
        }

        return $doi;
    }

    /** @return list<string> */
    private static function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> ISBN-13 or ISBN-10 digits whose check digit holds */
    private static function isbns(string $text): array
    {
        preg_match_all('/\bISBN(?:-1[03])?\s*:?\s*([0-9][0-9\- ]{8,16}[0-9Xx])\b/', $text, $m);
        $found = [];
        foreach ($m[1] as $raw) {
            $isbn = strtoupper((string) preg_replace('/[\- ]/', '', $raw));
            if (self::isIsbn($isbn) && !\in_array($isbn, $found, true)) {
                $found[] = $isbn;
            }
        }

        return $found;
    }

    private static function isIsbn(string $isbn): bool
    {
        if (preg_match('/^\d{13}$/', $isbn) === 1) {
            $sum = 0;
            for ($i = 0; $i < 13; ++$i) {
                $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0;
        }
        if (preg_match('/^\d{9}[\dX]$/', $isbn) === 1) {
            $sum = 0;
            for ($i = 0; $i < 10; ++$i) {
                $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
            }

            return $sum % 11 === 0;
        }

        return false;
    }
}
