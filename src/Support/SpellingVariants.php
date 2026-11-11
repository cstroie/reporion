<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * British and American spellings of a medical word (2026-11-11): a question asks about `pediatric`
 * head CT, a European guideline writes `paediatric`, and a stem (`pediatr`) never matches the
 * other. Only the start of a word is compared, from a short list, so `hemisphere` stays as it is.
 * For the library's search words only (Service\Library\SourceAsk); reports are Romanian. Pure.
 */
final class SpellingVariants
{
    /** British start => American start, folded */
    private const PAIRS = [
        'paediatr' => 'pediatr',
        'orthopaed' => 'orthoped',
        'haemo' => 'hemo',
        'haema' => 'hema',
        'oedem' => 'edem',
        'oesophag' => 'esophag',
        'anaem' => 'anem',
        'anaesth' => 'anesth',
        'ischaem' => 'ischem',
        'leukaem' => 'leukem',
        'septicaem' => 'septicem',
        'gynaecol' => 'gynecol',
        'diarrhoe' => 'diarrhe',
        'foet' => 'fet',
        'fibre' => 'fiber',
    ];

    /**
     * $terms with each one's other spelling after it, no duplicates
     *
     * @param list<string> $terms folded
     *
     * @return list<string>
     */
    public static function widen(array $terms): array
    {
        $out = [];
        foreach ($terms as $term) {
            $out[$term] = true;
            $other = self::other($term);
            if ($other !== null) {
                $out[$other] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /** The other spelling of a folded word, or null when the list has none */
    public static function other(string $term): ?string
    {
        foreach (self::PAIRS as $british => $american) {
            if (str_starts_with($term, $british)) {
                return $american . substr($term, \strlen($british));
            }
        }
        foreach (self::PAIRS as $british => $american) {
            if (str_starts_with($term, $american)) {
                return $british . substr($term, \strlen($american));
            }
        }

        return null;
    }
}
