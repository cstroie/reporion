<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Support\ReportPath;

/**
 * The frontmatter of a new page submitted without one (decided 2026-09-27:
 * "do its best effort to create the frontmatter from what I send"). Only
 * what the page itself says, nothing invented:
 *
 * - `title`: the first heading, else the page's name;
 * - `visibility: private` — publishing is always a deliberate act (D16);
 * - on a report path, `reports:{modality ns}:{site}:{yymmdd}-{name}` (D1):
 *   the modality and site from the path, `study_date` from its date, and —
 *   as a report is written (docs/FORMATS.md §11) — `patient.name` and the
 *   title from the `#` heading, `exam_title` from the first `##`.
 *
 * Everything else stays for the user to add; required fields block
 * signing, never saving (D7).
 */
final class FrontmatterGuess
{
    /**
     * @param array<string, string> $modalityNamespaces modality code → namespace segment
     *
     * @return array<string, mixed>
     */
    public static function forNewPage(string $path, string $body, array $modalityNamespaces = []): array
    {
        // Headings only outside fenced code
        $text = (string) preg_replace('/^ {0,3}(`{3,}|~{3,}).*?^ {0,3}\1[ \t]*$/ms', '', $body);
        $h1 = self::heading($text, '#');
        $h2 = self::heading($text, '##');
        $first = preg_match('/^ {0,3}#{1,6}[ \t]+(.+?)[ \t#]*$/m', $text, $m) === 1 ? trim($m[1]) : null;
        $segments = explode(':', $path);
        $leaf = (string) end($segments);

        $fm = [
            'title' => $first ?? ucfirst(str_replace(['-', '_'], ' ', $leaf)),
            'exam_title' => null,
            'visibility' => 'private',
        ];
        if (!ReportPath::isReport($path)) {
            return array_filter($fm, static fn (mixed $v): bool => $v !== null);
        }

        $map = $modalityNamespaces !== [] ? $modalityNamespaces : NewReport::DEFAULT_MODALITY_NAMESPACES;
        $modality = array_search($segments[1] ?? '', $map, true);
        $date = null;
        if (preg_match('/^(\d{2})(\d{2})(\d{2})-/', $leaf, $d) === 1 && checkdate((int) $d[2], (int) $d[3], 2000 + (int) $d[1])) {
            $date = \sprintf('20%s-%s-%s', $d[1], $d[2], $d[3]);
        }

        return array_filter([
            'title' => $h1 ?? $fm['title'],
            'exam_title' => $h2,
            'visibility' => 'private',
            'modality' => \is_string($modality) ? [$modality] : null,
            'site' => \count($segments) >= 4 ? $segments[2] : null,
            'study_date' => $date,
            'patient' => $h1 !== null ? ['name' => $h1] : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** The text of the first heading of exactly this level */
    private static function heading(string $text, string $marks): ?string
    {
        return preg_match('/^ {0,3}' . preg_quote($marks, '/') . '[ \t]+(.+?)[ \t#]*$/m', $text, $m) === 1 && trim($m[1]) !== '' ? trim($m[1]) : null;
    }
}
