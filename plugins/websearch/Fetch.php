<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Websearch;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * A result page read for its best passage: the HTML's readable text (no
 * script, style, navigation, header, footer or form), cut into paragraphs,
 * and the window of about PASSAGE characters that holds most of the query's
 * words. Not HTML: nothing (the search snippet stands).
 */
final class Fetch
{
    public const PASSAGE = 900;

    public function __construct(private readonly Http $http)
    {
    }

    /** The page's best passage for $query, or '' (not HTML, not 200, unreadable) */
    public function passage(string $url, string $query): string
    {
        try {
            [$status, $body, $headers] = $this->http->get($url, ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9']);
        } catch (RuntimeException) {
            return '';
        }
        $type = strtolower($headers['content-type'] ?? 'text/html');
        if ($status !== 200 || !str_contains($type, 'html')) {
            return '';
        }

        return self::best(self::paragraphs($body), $query);
    }

    /** @return list<string> the readable blocks of an HTML page, whitespace folded */
    public static function paragraphs(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $dom = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//form|//aside|//svg|//iframe') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $out = [];
        foreach ($xpath->query('//p|//li|//td|//h1|//h2|//h3|//h4|//dd|//blockquote') ?: [] as $node) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            if (mb_strlen($text) >= 40) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * The run of consecutive paragraphs, at most PASSAGE characters, holding
     * the most distinct query words (ties: the earliest)
     *
     * @param list<string> $paragraphs
     */
    public static function best(array $paragraphs, string $query): string
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 3,
        )));
        $best = '';
        $score = 0;
        foreach (array_keys($paragraphs) as $start) {
            $text = '';
            for ($i = $start; $i < \count($paragraphs) && mb_strlen($text) < self::PASSAGE; $i++) {
                $text = trim($text . ' ' . $paragraphs[$i]);
            }
            $text = mb_substr($text, 0, self::PASSAGE);
            $lower = mb_strtolower($text);
            $hits = \count(array_filter($words, static fn (string $w): bool => str_contains($lower, $w)));
            if ($hits > $score) {
                [$best, $score] = [$text, $hits];
            }
        }

        return $best;
    }
}
