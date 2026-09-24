<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Import;

/**
 * Deterministic DokuWiki → Markdown syntax conversion per architecture-import.md Table 2.
 * Input: raw DokuWiki text (with macros already extracted by MacroExtractor).
 * Output: {markdown, unknown_constructs}.
 *
 * Process: block-level constructs first (headings, code, lists, tables), then inline.
 * Unknown/ambiguous constructs are passed through verbatim and reported.
 */
final class SyntaxConverter
{
    /**
     * @return array{markdown: string, unknown: list<string>}
     */
    public static function convert(string $text, int $headingOffset = 1): array
    {
        $unknown = [];
        $lines = explode("\n", $text);
        $result = [];
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];

            // H1 (====== X ======)
            if (preg_match('/^={6}\s*(.+?)\s*={6}$/', $line, $m)) {
                $level = 1 + $headingOffset;
                $result[] = str_repeat('#', $level) . ' ' . trim($m[1]);
                $i++;
                continue;
            }

            // H2 (===== X =====)
            if (preg_match('/^={5}\s*(.+?)\s*={5}$/', $line, $m)) {
                $level = 2 + $headingOffset;
                $result[] = str_repeat('#', $level) . ' ' . trim($m[1]);
                $i++;
                continue;
            }

            // H3 (==== X ====)
            if (preg_match('/^={4}\s*(.+?)\s*={4}$/', $line, $m)) {
                $level = 3 + $headingOffset;
                $result[] = str_repeat('#', $level) . ' ' . trim($m[1]);
                $i++;
                continue;
            }

            // H4 (=== X ===)
            if (preg_match('/^={3}\s*(.+?)\s*={3}$/', $line, $m)) {
                $level = 4 + $headingOffset;
                $result[] = str_repeat('#', $level) . ' ' . trim($m[1]);
                $i++;
                continue;
            }

            // Poem block <poem>...</poem>
            if (str_starts_with($line, '<poem>')) {
                $poemContent = substr($line, \strlen('<poem>'));
                if (str_ends_with($poemContent, '</poem>')) {
                    $poemContent = substr($poemContent, 0, -\strlen('</poem>'));
                    $result[] = $poemContent;
                    $unknown[] = 'poem-block';
                } else {
                    $poemLines = [$poemContent];
                    $i++;
                    while ($i < count($lines) && !str_contains($lines[$i], '</poem>')) {
                        $poemLines[] = $lines[$i];
                        $i++;
                    }
                    if ($i < count($lines) && str_contains($lines[$i], '</poem>')) {
                        $beforeClose = substr($lines[$i], 0, strpos($lines[$i], '</poem>'));
                        if ($beforeClose !== '') {
                            $poemLines[] = $beforeClose;
                        }
                        $i++;
                    }
                    $result = array_merge($result, $poemLines);
                    $unknown[] = 'poem-block';
                }
                continue;
            }

            // Blockquote with cite <blockquote>...<cite>...</cite>...</blockquote>
            if (str_starts_with($line, '<blockquote>')) {
                $blockContent = substr($line, \strlen('<blockquote>'));
                $blockLines = [];
                $citeLine = null;

                if (str_ends_with($blockContent, '</blockquote>')) {
                    $blockContent = substr($blockContent, 0, -\strlen('</blockquote>'));
                    $blockLines = [$blockContent];
                } else {
                    $blockLines = [$blockContent];
                    $i++;
                    while ($i < count($lines) && !str_contains($lines[$i], '</blockquote>')) {
                        $blockLines[] = $lines[$i];
                        $i++;
                    }
                    if ($i < count($lines)) {
                        $beforeClose = substr($lines[$i], 0, strpos($lines[$i], '</blockquote>'));
                        if ($beforeClose !== '') {
                            $blockLines[] = $beforeClose;
                        }
                        $i++;
                    }
                }

                // Extract cite (last <cite>...</cite> if present)
                $fullBlock = implode("\n", $blockLines);
                if (preg_match('/<cite>(.*?)<\/cite>/', $fullBlock, $m)) {
                    $citeLine = trim($m[1]);
                    $fullBlock = preg_replace('/<cite>.*?<\/cite>/', '', $fullBlock);
                }

                // Convert to markdown blockquote (> per line)
                $citeLines = array_filter(explode("\n", trim($fullBlock)));
                foreach ($citeLines as $quoteLine) {
                    $result[] = '> ' . trim($quoteLine);
                }
                if ($citeLine !== null) {
                    $result[] = '> — ' . $citeLine;
                }
                continue;
            }

            // <code>...</code> or <file>...</file> blocks, optionally with lang/name attributes
            if (preg_match('/^<(code|file)(?:\s+(\w+))(?:\s+(\w+))?>/', $line, $m)) {
                $tag = $m[1];
                $lang = $m[2] ?? null;
                $closeTag = '</' . $tag . '>';
                $closePattern = '/<\/' . $tag . '>/';

                $codeStart = strpos($line, '>') + 1;
                if ($codeStart > 0) {
                    $code = [substr($line, $codeStart)];
                } else {
                    $code = [];
                }

                if (str_contains($line, $closeTag)) {
                    $lastCode = array_pop($code);
                    if ($lastCode !== null) {
                        $beforeClose = substr($lastCode, 0, strpos($lastCode, $closeTag));
                        if ($beforeClose !== '') {
                            $code[] = $beforeClose;
                        }
                    }
                } else {
                    $i++;
                    while ($i < count($lines) && !preg_match($closePattern, $lines[$i])) {
                        $code[] = $lines[$i];
                        $i++;
                    }
                    if ($i < count($lines) && preg_match($closePattern, $lines[$i])) {
                        $beforeClose = substr($lines[$i], 0, strpos($lines[$i], $closeTag));
                        if ($beforeClose !== '') {
                            $code[] = $beforeClose;
                        }
                        $i++;
                    }
                }

                $result[] = '```' . ($lang ?? '');
                $result = array_merge($result, $code);
                $result[] = '```';
                continue;
            }

            // Definition lists: ; term → **term**, : definition → plain text on next line
            if (str_starts_with($line, ';') && !str_starts_with($line, ';;')) {
                $term = trim(substr($line, 1));
                $result[] = '**' . $term . '**';
                $i++;
                if ($i < count($lines) && str_starts_with($lines[$i], ':') && !str_starts_with($lines[$i], '::')) {
                    $definition = trim(substr($lines[$i], 1));
                    $result[] = $definition;
                    $i++;
                }
                continue;
            }

            // DokuWiki tables (block-level)
            if (preg_match('/^[\^|]/', $line)) {
                $tableLines = [$line];
                $i++;
                while ($i < count($lines) && preg_match('/^[\^|]/', $lines[$i])) {
                    $tableLines[] = $lines[$i];
                    $i++;
                }

                $table = self::convertTable($tableLines, $unknown);
                if ($table !== null) {
                    $result = array_merge($result, $table);
                } else {
                    $result = array_merge($result, $tableLines);
                }
                continue;
            }

            // DokuWiki lists: convert `  * item` or `  - item` to `- item`
            if (preg_match('/^  ([-*])\s+(.+)$/', $line, $m)) {
                $result[] = '- ' . $m[2];
                $i++;
                continue;
            }

            // Ordered lists `  1.` → `1.`
            if (preg_match('/^  (\d+\.)\s+(.+)$/', $line, $m)) {
                $result[] = $m[1] . ' ' . $m[2];
                $i++;
                continue;
            }

            // Simple inline conversion
            $line = self::convertInline($line, $unknown);
            $result[] = $line;
            $i++;
        }

        return [
            'markdown' => implode("\n", $result),
            'unknown' => array_unique($unknown),
        ];
    }

    /**
     * Convert a DokuWiki table block to markdown table format.
     * Returns null if the table is malformed (mismatched row lengths, etc).
     *
     * @param list<string> $tableLines
     * @param list<string> $unknown
     * @return list<string>|null
     */
    private static function convertTable(array $tableLines, array &$unknown): ?array
    {
        $rows = [];
        $headerCount = null;

        foreach ($tableLines as $line) {
            if (preg_match('/^[\^|]/', $line)) {
                // Detect if this is a header row (contains ^) or data row (contains |)
                $isHeader = str_contains($line, '^');

                // Split by ^ or | based on row type
                $pattern = $isHeader ? '/\^/' : '/\|/';
                $cells = preg_split($pattern, trim($line, '^|'));

                // Filter empty cells at boundaries
                $cells = array_filter($cells, fn($c) => trim($c) !== '');
                if (empty($cells)) {
                    continue;
                }

                // Trim each cell
                $cells = array_map(fn($c) => trim($c), $cells);

                // Track header count (first header row sets it)
                if ($isHeader && $headerCount === null) {
                    $headerCount = count($cells);
                }

                // Validate cell count matches header
                if ($headerCount !== null && count($cells) !== $headerCount) {
                    return null;
                }

                $rows[] = ['cells' => $cells, 'isHeader' => $isHeader];
            }
        }

        if (empty($rows) || $headerCount === null) {
            return null;
        }

        $result = [];
        $headerEmitted = false;

        foreach ($rows as $row) {
            if ($row['isHeader'] && !$headerEmitted) {
                // Emit header row
                $result[] = '| ' . implode(' | ', $row['cells']) . ' |';
                // Emit separator
                $result[] = '|' . implode('|', array_map(fn($c) => '---|', array_fill(0, count($row['cells']), ''))) . '';
                $headerEmitted = true;
            } elseif (!$row['isHeader']) {
                // Emit data row
                $result[] = '| ' . implode(' | ', $row['cells']) . ' |';
            }
        }

        if (!empty($result)) {
            $unknown[] = 'table';
            return $result;
        }

        return null;
    }

    private static function convertInline(string $line, array &$unknown): string
    {
        // Italic //text// → *text*
        $line = preg_replace_callback(
            '/\/\/([^\/]+)\/\//',
            fn($m) => '*' . $m[1] . '*',
            $line
        );

        // Underline __text__ → **text**
        $line = preg_replace_callback(
            '/__([^_]+)__/',
            fn($m) => '**' . $m[1] . '**',
            $line
        );

        // Line breaks \\ → two trailing spaces
        $line = str_replace('\\\\', '  ', $line);

        // Links [[page|label]] → [label](/page)
        $line = preg_replace_callback(
            '/\[\[([^|\]]+)\|([^\]]+)\]\]/',
            fn($m) => '[' . $m[2] . '](' . str_replace(':', '/', $m[1]) . ')',
            $line
        );

        // Links [[page]] (no label) → [page](/page)
        $line = preg_replace_callback(
            '/\[\[([^\]]+)\]\]/',
            fn($m) => '[' . $m[1] . '](' . str_replace(':', '/', $m[1]) . ')',
            $line
        );

        // Images {{image.jpg}} → ![](media/{filename})
        $line = preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($m) use (&$unknown): string {
                $file = $m[1];
                $unknown[] = "image:{$file}";
                return '![](media/' . pathinfo($file, PATHINFO_FILENAME) . ')';
            },
            $line
        );

        return $line;
    }
}
