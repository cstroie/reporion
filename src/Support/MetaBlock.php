<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The imported DokuWiki `~~META: … ~~` block still sitting, verbatim, in
 * 1 938 report bodies (TODO.md idea 10; docs/architecture-import.md: an
 * unknown macro is preserved and reported, not deleted). Every block seen
 * in the archive carries the same ten keys — `&date &name &age &sex
 * &section &medic &fo &diag &exam &secv` — one `&key = value` per line.
 * This class only reads that syntax; what each key *means* (mapping to
 * frontmatter) is `Service\Maintenance\MetaBlockTask`'s job, which is
 * schema-aware and this is not.
 *
 * A handful of blocks in the real archive are malformed — two `&key =`
 * pairs run together on one line with the newline between them missing,
 * so a naive single regex over the whole block would attribute the
 * second key's value to the first. `parse()` checks every line
 * individually and reports the whole block unparseable rather than guess:
 * a wrong value is worse than a blank one (the same rule
 * `Import\MetadataExtractor` already states).
 */
final class MetaBlock
{
    private const KEYS = ['date', 'name', 'age', 'sex', 'section', 'medic', 'fo', 'diag', 'exam', 'secv'];

    /**
     * $body's `~~META: … ~~` block, or null when it has none.
     *
     * @return ?array{ok: bool, fields: array<string, string>, reasons: list<string>, bodyWithoutBlock: string}
     */
    public static function parse(string $body): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        if (preg_match('/^~~META:\n(.*?)^~~[ \t]*\n?/ms', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        [$whole, $start] = [$m[0][0], $m[0][1]];
        $inner = $m[1][0];

        $fields = [];
        $reasons = [];
        $seen = [];
        foreach (explode("\n", rtrim($inner, "\n")) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^&([a-z]+)[ \t]*=[ \t]*(.*)$/', $line, $lm) !== 1) {
                $reasons[] = 'line ' . ($n + 1) . ' is not "&key = value"';
                continue;
            }
            [$key, $value] = [$lm[1], trim($lm[2])];
            // The corruption seen in the real archive: a second "&key =" run
            // into the first value because a newline between them is missing
            if (preg_match('/&[a-z]+[ \t]*=/', $value) === 1) {
                $reasons[] = 'line ' . ($n + 1) . ' ("&' . $key . '") holds what looks like another "&key =" — a missing newline';
                continue;
            }
            if (!\in_array($key, self::KEYS, true)) {
                $reasons[] = 'unknown key "&' . $key . '"';
                continue;
            }
            if (isset($seen[$key])) {
                $reasons[] = '"&' . $key . '" appears twice';
                continue;
            }
            $seen[$key] = true;
            $fields[$key] = $value;
        }
        foreach (self::KEYS as $key) {
            $fields[$key] ??= '';
        }

        $before = substr($text, 0, $start);
        $after = substr($text, $start + \strlen($whole));
        // One blank line where the block was, never two, never none butted
        // against the next heading
        $stripped = rtrim($before, "\n") . "\n\n" . ltrim($after, "\n");
        if (trim($before) === '') {
            $stripped = ltrim($after, "\n");
        }

        return [
            'ok' => $reasons === [],
            'fields' => $fields,
            'reasons' => $reasons,
            'bodyWithoutBlock' => $stripped,
        ];
    }

    /**
     * `&age`'s shapes seen in the archive: "62 ani" (Romanian, most of
     * them), "62Y" (a few), "3 luni" (months, always under a year old).
     * Returns a birth year against $studyYear, or null when the text does
     * not match a known shape.
     */
    public static function parseAge(string $age, int $studyYear): ?int
    {
        if (preg_match('/^(\d{1,3})\s*(ani|Y)$/iu', $age, $m) === 1) {
            return $studyYear - (int) $m[1];
        }
        if (preg_match('/^(\d{1,2})\s*luni$/iu', $age, $m) === 1) {
            return $studyYear;
        }

        return null;
    }

    /** `&date`'s shape: "27.09.2026" — the only one seen at real volume in the archive */
    public static function parseDate(string $date): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m) !== 1) {
            return null;
        }
        $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $iso : null;
    }

    /**
     * `&secv`'s shape: sequence codes separated by commas, or semicolons
     * between an exam's own groups ("FLAIR TRS, T1 SAG; T2 SAG, T1 COR") —
     * flattened here, `Schema\Loader`'s `sequences` field is one list.
     *
     * @return list<string>
     */
    public static function parseSequences(string $secv): array
    {
        $parts = preg_split('/[,;]/', $secv) ?: [];
        $seqs = array_values(array_unique(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== '')));

        return $seqs;
    }
}
