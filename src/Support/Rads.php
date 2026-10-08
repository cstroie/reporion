<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The RADS categories a report states, as tags (roadmap phase 34h,
 * 2026-10-08): BI-RADS, PI-RADS, LI-RADS, Lung-RADS, TI-RADS (ACR and EU)
 * and O-RADS, each with the system's fixed categories — `rads:birads-4a`,
 * `rads:lirads-m`, `rads:lungrads-4x`, `rads:eutirads-4`. Read from the
 * conclusion section(s) (Support\Conclusion), or the whole text when there
 * is none; no assistant involved. Diacritics and case aside; "BI-RADS 4A",
 * "BIRADS: 4a", "BI-RADS categoria IV", "LI-RADS LR-5", "LR-5", "ACR TI-RADS
 * TR4" all count. A category is left out when its clause (up to a comma,
 * semicolon, bracket or full stop) or a one- or two-word bracket right after
 * it names it as history — "anterior BI-RADS 3", "precedent: BI-RADS 3",
 * "BI-RADS 3 (precedent)" — or a word right before it negates it ("nu
 * BI-RADS 4"). Breast density letters ("BI-RADS B") are not categories.
 */
final class Rads
{
    public const PREFIX = 'rads:';

    /** The system's spelling (folded) → [tag name, the categories it has] */
    private const SYSTEMS = [
        'bi' => ['birads', ['0', '1', '2', '3', '4', '4a', '4b', '4c', '5', '6']],
        'pi' => ['pirads', ['1', '2', '3', '4', '5']],
        'li' => ['lirads', ['1', '2', '3', '4', '5', 'm', 'tiv', 'nc']],
        'lung' => ['lungrads', ['0', '1', '2', '3', '4a', '4b', '4x']],
        'ti' => ['tirads', ['1', '2', '3', '4', '5']],
        'euti' => ['eutirads', ['1', '2', '3', '4', '5']],
        'o' => ['orads', ['0', '1', '2', '3', '4', '5']],
    ];

    private const ROMAN = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6'];

    /** Words that make a clause about an earlier study */
    private const HISTORY = '/\b(anterior\w*|precedent\w*|prealabil\w*|previous\w*|prior|initial\w*|vechi|in\s+\d{4}|din\s+\d{4}|din\s+data|din\s+\d{1,2}[.\/]\d{1,2}|was|fost|era)\b/u';

    /**
     * @return list<string> the tags, each once, in the order the text gives them
     */
    public static function tags(string $body): array
    {
        $texts = Conclusion::texts($body);

        return self::scan($texts !== [] ? implode("\n", $texts) : $body);
    }

    /**
     * A tag list with every RADS phrasing in it ("BI-RADS 4A", from a model)
     * spelled as its tag, and $found added after it — each once
     *
     * @param list<string> $tags
     * @param list<string> $found
     *
     * @return list<string>
     */
    public static function merge(array $tags, array $found): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $as = str_starts_with($tag, self::PREFIX) ? [] : self::scan($tag);
            array_push($out, ...($as !== [] ? $as : [$tag]));
        }
        foreach ($found as $tag) {
            $out[] = $tag;
        }
        $seen = [];

        return array_values(array_filter($out, static function (string $tag) use (&$seen): bool {
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    /** @return list<string> */
    private static function scan(string $text): array
    {
        $folded = mb_strtolower(strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't']));
        $folded = str_replace(['*', '_', '–', '—'], ['', '', '-', '-'], $folded);
        $pattern = '/(?<![a-z0-9-])(?:acr[\s-]+)?(?<sys>eu[\s-]?ti|bi|pi|li|lung|ti|o)[\s-]?rads(?:\s*v?\d(?:\.\d)?(?=\s))?'
            . '\s*(?:[:=-]\s*)?(?:(?:categor\w*|cat\.?|scor\w*|score|clasa)\s*[:=-]?\s*)?'
            . '(?:(?:lr|tr)[\s-]?)?(?<cat>tiv|nc|[0-6][abcx]?|vi|iv|v|i{1,3}|m)(?![a-z0-9])'
            . '|(?<![a-z0-9-])lr[\s-](?<lr>[1-5]|m|tiv|nc)(?![a-z0-9])/u';
        if (preg_match_all($pattern, $folded, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $tags = [];
        foreach ($matches as $m) {
            if (isset($m['lr']) && $m['lr'][1] >= 0 && $m['lr'][0] !== '') {
                $system = 'li';
                $category = $m['lr'][0];
            } else {
                $system = (string) preg_replace('/[\s-]/', '', $m['sys'][0]);
                $category = $m['cat'][0];
            }
            $category = self::ROMAN[$category] ?? $category;
            [$name, $allowed] = self::SYSTEMS[$system];
            if (!\in_array($category, $allowed, true) || self::discounted($folded, $m[0][1], $m[0][1] + \strlen($m[0][0]))) {
                continue;
            }
            $tags[] = self::PREFIX . $name . '-' . $category;
        }

        return array_values(array_unique($tags));
    }

    /** Whether the mention between $start and $end sits in a clause about history, or is negated */
    private static function discounted(string $text, int $start, int $end): bool
    {
        $before = substr($text, 0, $start);
        // The clause so far: after the last comma, semicolon, bracket, full stop or line break
        $head = (string) preg_replace('/^.*[,;().\n]/s', '', $before);
        $after = substr($text, $end);
        // The rest of the clause, or a short note in brackets right after the category ("(anterior)")
        $tail = preg_match('/^\s*\(\s*(\S+(?:\s+\S+)?)\s*\)/', $after, $paren) === 1 ? $paren[1] : (string) preg_split('/[,;(.\n]/', $after, 2)[0];

        return preg_match(self::HISTORY, $head . ' ' . $tail) === 1
            || preg_match('/\b(nu|not|no|fara|without)\s+(?:(?:este|e|is|a|an|un|o)\s+)?$/u', $head) === 1;
    }
}
