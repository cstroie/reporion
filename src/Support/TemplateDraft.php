<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Studio's report template (docs/library.md, phase 6), as the `studio-template` prompt writes it:
 * the template's markdown (D45 shape: `#` name, `##` exam, `###` sections), then a line
 * `=== checklist ===` and the checklist's lines (`# Section`, `Label | k1, k2`, FORMATS §3h). The
 * model never writes YAML: the lines are read here with Checklist's own reader, at most
 * Checklist::MAX, a line it cannot read left out. The shape and the bracket notation are checked
 * (TemplateLint, as `templates:lint`): warnings, never a block (D7). Pure.
 */
final class TemplateDraft
{
    private const SPLIT = '/^\s*={3,}\s*checklist\s*={3,}\s*$/miu';

    /**
     * @return array{body: string, checklist: list<string>, problems: list<array{code: string, line: int, text: string}>}
     */
    public static function parse(string $answer): array
    {
        $parts = preg_split(self::SPLIT, $answer, 2) ?: [$answer];
        $body = self::unfenced($parts[0]);
        $lines = [];
        foreach (preg_split('/\R/u', self::unfenced($parts[1] ?? '')) ?: [] as $line) {
            // A bullet or number the model put before a line is not part of it
            $line = trim((string) preg_replace('/^\s*(?:[-*+]|\d{1,2}[.)])\s+/u', '', $line), " \t\"'`");
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $rows = array_values(array_filter(Checklist::rows($lines), static fn (array $row): bool => $row['kind'] !== 'raw'));
        $checklist = array_values(array_filter(\array_slice(Checklist::lines($rows) ?? [], 0, Checklist::MAX), 'is_string'));

        return ['body' => $body, 'checklist' => $checklist, 'problems' => TemplateLint::problems([], $body)];
    }

    /** The text without a code fence the model may have wrapped it in */
    private static function unfenced(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^(```|~~~)[a-z]*\R(.*?)\R?\1\s*$/su', $text, $m) === 1) {
            $text = trim($m[2]);
        }

        return $text;
    }
}
