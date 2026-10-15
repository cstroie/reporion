<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The mechanical half of tags:review (2026-10-15): proposals computed from
 * the tags in use alone, no assistant, never applied by themselves.
 *
 * - Near-duplicates: tags whose words are the same once case, diacritics
 *   and the commonest Romanian article and plural endings are folded away
 *   ("leziune" / "leziuni", "chist" / "chisturi", "fractură" / "fracturi"):
 *   a `merge` into the spelling on the most pages.
 * - Too specific: laterality (stâng, drept, bilateral) and spine levels
 *   (C5-C6, L4, S1) taken off ("hernie discală L4-L5 stângă" is "hernie
 *   discală"): a `generalise` into the unqualified tag when one is in use,
 *   else into the tag with those words dropped. The detail stays in the
 *   report text, which search still finds.
 *
 * RADS categories (`rads:…`) are left alone: their number is the point;
 * so are `normal` and its spellings (Similar reports leaves a report
 * tagged `normal` out: TagList::NORMAL).
 *
 * @phpstan-type Proposal array{op: string, from: list<string>, to: string, reason: string, source: string}
 */
final class TagNormalize
{
    /** Words that say which side, folded */
    private const SIDES = ['stang', 'stanga', 'stangi', 'stangul', 'drept', 'dreapta', 'drepti', 'drepte', 'dreptul', 'bilateral', 'bilaterala', 'bilaterale', 'bilaterali', 'stg', 'dr', 'left', 'right'];

    /** A vertebral level or a span of them: C5, T12, L4-L5, L5-S1, C5C6 */
    private const LEVEL = '/^(?:[ctlds]\d{1,2})(?:-?[ctlds]?\d{1,2})?$/i';

    /** Endings folded away, the longest first; what stays keeps at least STEM_MIN letters */
    private const ENDINGS = ['urilor', 'urile', 'ilor', 'elor', 'ului', 'uri', 'lor', 'ul', 'le', 'ii', 'ei', 'i', 'a', 'e', 'u'];

    private const STEM_MIN = 4;

    /**
     * @param array<string, int> $counts tag → pages
     *
     * @return list<Proposal>
     */
    public static function proposals(array $counts): array
    {
        $groups = [];
        foreach ($counts as $tag => $n) {
            $tag = (string) $tag;
            if (str_starts_with($tag, Rads::PREFIX) || TagProposals::isNormal($tag)) {
                continue;
            }
            $plain = self::withoutQualifiers($tag);
            if ($plain === '') {
                continue;
            }
            $groups[self::key($plain)][] = ['tag' => $tag, 'plain' => $plain, 'n' => (int) $n];
        }

        $proposals = [];
        foreach ($groups as $members) {
            $qualified = array_values(array_filter($members, static fn (array $m): bool => $m['plain'] !== $m['tag']));
            if (\count($members) < 2 && $qualified === []) {
                continue;
            }
            // The unqualified tags on the most pages first; a group of qualified ones only takes the most used one's plain form
            $bare = array_values(array_filter($members, static fn (array $m): bool => $m['plain'] === $m['tag']));
            $pool = $bare !== [] ? $bare : $members;
            usort($pool, static fn (array $a, array $b): int => [$b['n'], $a['tag']] <=> [$a['n'], $b['tag']]);
            $to = $pool[0]['plain'];
            $from = array_values(array_filter(array_map(static fn (array $m): string => $m['tag'], $members), static fn (string $t): bool => $t !== $to));
            if ($from === []) {
                continue;
            }
            sort($from);
            $proposals[] = [
                'op' => $qualified !== [] ? 'generalise' : 'merge',
                'from' => $from,
                'to' => $to,
                'reason' => $qualified !== [] ? 'side or level' : 'same words',
                'source' => 'rule',
            ];
        }
        usort($proposals, static fn (array $a, array $b): int => strcmp($a['to'], $b['to']));

        return $proposals;
    }

    /** $tag without its laterality and level words, spaces made single; '' when nothing else is left */
    public static function withoutQualifiers(string $tag): string
    {
        $words = preg_split('/\s+/u', trim($tag)) ?: [];
        $kept = array_values(array_filter($words, static fn (string $w): bool => !\in_array(Slug::fold($w), self::SIDES, true) && preg_match(self::LEVEL, $w) !== 1));

        return implode(' ', $kept);
    }

    /** What near-duplicates share: each word folded and stemmed */
    public static function key(string $tag): string
    {
        $words = preg_split('/[\s\-\/]+/u', mb_strtolower(trim($tag))) ?: [];

        return implode(' ', array_map(self::stem(...), array_filter(array_map(Slug::fold(...), $words), static fn (string $w): bool => $w !== '')));
    }

    private static function stem(string $word): string
    {
        foreach (self::ENDINGS as $ending) {
            if (str_ends_with($word, $ending) && \strlen($word) - \strlen($ending) >= self::STEM_MIN) {
                $word = substr($word, 0, -\strlen($ending));
                break;
            }
        }

        // "fractură" and "fracturi", "chist" and "chisturi": the -ur- of a plural goes too, wherever it was left
        return str_ends_with($word, 'ur') && \strlen($word) - 2 >= self::STEM_MIN ? substr($word, 0, -2) : $word;
    }
}
