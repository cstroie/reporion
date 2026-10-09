<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A report's conclusion section(s), for the assistant's `summary`: a
 * summary of the radiologist's own conclusion is shorter to send and closer
 * to the point than one of the whole report (2026-10-08).
 *
 * A conclusion is any heading, of any level, whose text begins with
 * "conclu…" (Concluzii, Concluzie, Concluzia, Conclusion(s), diacritics and
 * case aside, a trailing colon allowed); it runs to the next heading of the
 * same or a higher level (a lower-level heading inside it belongs to it).
 * With several (one per exam of a multi-exam report), each comes under the
 * title of the `#`/`##` heading before it. Null when there is none, or what
 * it holds is under MIN characters ("Fără modificări") — the caller then
 * sends the whole text.
 */
final class Conclusion
{
    public const MIN = 20;

    public static function of(string $body): ?string
    {
        $blocks = self::sections($body);
        if ($blocks === [] || mb_strlen(implode('', array_column($blocks, 'text'))) < self::MIN) {
            return null;
        }
        if (\count($blocks) === 1) {
            return $blocks[0]['text'];
        }

        return implode("\n\n", array_map(
            static fn (array $b): string => ($b['exam'] !== '' ? '## ' . $b['exam'] . "\n\n" : '') . $b['text'],
            $blocks,
        ));
    }

    /** The first conclusion section's text, however short; null when there is none */
    public static function first(string $body): ?string
    {
        return self::sections($body)[0]['text'] ?? null;
    }

    /**
     * The non-empty conclusion sections, in order, each with the exam
     * heading before it and whether it is shared — at `#`/`##`, a sibling
     * of the exams rather than inside one (Service\Ai\ReportSummary)
     *
     * @return list<array{exam: string, text: string, shared: bool}>
     */
    public static function parts(string $body): array
    {
        return array_map(static fn (array $b): array => ['exam' => $b['exam'], 'text' => $b['text'], 'shared' => $b['level'] <= 2], self::sections($body));
    }

    /**
     * Every conclusion section's text, however short, in order (Support\Rads)
     *
     * @return list<string>
     */
    public static function texts(string $body): array
    {
        return array_column(self::sections($body), 'text');
    }

    /**
     * @return list<array{exam: string, text: string, level: int}> the non-empty conclusion sections, in order
     */
    private static function sections(string $body): array
    {
        $lines = preg_split('/\R/u', $body) ?: [];
        $fence = false;
        $exam = '';
        $found = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$/u', $line, $m) === 1) {
                $level = \strlen($m[1]);
                if ($current !== null && $level <= $current['level']) {
                    $found[] = $current;
                    $current = null;
                }
                if ($level <= 2 && !self::isConclusion($m[2])) {
                    $exam = $m[2];
                }
                if ($current === null && self::isConclusion($m[2])) {
                    $current = ['level' => $level, 'exam' => $exam, 'lines' => []];
                } elseif ($current !== null) {
                    $current['lines'][] = $line;
                }
                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $found[] = $current;
        }

        $blocks = [];
        foreach ($found as $section) {
            $text = trim(implode("\n", $section['lines']));
            if ($text !== '') {
                $blocks[] = ['exam' => $section['exam'], 'text' => $text, 'level' => $section['level']];
            }
        }

        return $blocks;
    }

    private static function isConclusion(string $heading): bool
    {
        $folded = mb_strtolower(trim($heading, " \t:.*_"));
        $folded = strtr($folded, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return preg_match('/^conclu(zi[aei]?|sions?)\b/u', $folded) === 1;
    }
}
