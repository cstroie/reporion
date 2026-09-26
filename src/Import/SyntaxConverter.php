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
                    $i++;
                } else {
                    $poemLines = [$poemContent];
                    $i++;
                    while ($i < count($lines) && !str_contains($lines[$i], '</poem>')) {
                        $poemLines[] = $lines[$i];
                        $i++;
                    }
                    if ($i < count($lines) && str_contains($lines[$i], '</poem>')) {
                        $beforeClose = substr($lines[$i], 0, strpos($lines[$i], '</poem>'));
                        $poemLines[] = $beforeClose;  // Include even if empty, to preserve blank lines
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
                    $i++;
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
            if (preg_match('/^<(code|file)(?:\s+(\w+))?(?:\s+(\w+))?>/', $line, $m)) {
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
                        $code[] = $beforeClose;  // Include even if empty, to preserve blank lines
                    }
                } else {
                    $i++;
                    while ($i < count($lines) && !preg_match($closePattern, $lines[$i])) {
                        $code[] = $lines[$i];
                        $i++;
                    }
                    if ($i < count($lines) && preg_match($closePattern, $lines[$i])) {
                        $beforeClose = substr($lines[$i], 0, strpos($lines[$i], $closeTag));
                        $code[] = $beforeClose;  // Include even if empty, to preserve blank lines
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
                $delimiter = $isHeader ? '^' : '|';

                // A well-formed row is bracketed by its delimiter on both ends
                // ("^c1^c2^" / "|c1|c2|"), so splitting on it always yields one
                // extra empty element at each boundary — never trim/filter interior
                // cells, since a blank cell between two delimiters is legitimate data.
                $trimmedLine = rtrim($line);
                if (!str_starts_with($trimmedLine, $delimiter) || !str_ends_with($trimmedLine, $delimiter)) {
                    $unknown[] = 'table';
                    return null;
                }

                $parts = explode($delimiter, $trimmedLine);
                array_shift($parts);
                array_pop($parts);
                $cells = array_map(fn($c) => trim($c), $parts);

                // Track header count (first header row sets it)
                if ($isHeader && $headerCount === null) {
                    $headerCount = count($cells);
                }

                // Validate cell count matches header
                if ($headerCount !== null && count($cells) !== $headerCount) {
                    $unknown[] = 'table';
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
                $result[] = '|' . str_repeat('---|', count($row['cells']));
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

    /** A DokuWiki link target as a markdown destination */
    private static function linkTarget(string $target): string
    {
        $target = trim($target);
        if (preg_match('~^[a-z][a-z0-9+.-]*://|^mailto:~i', $target) === 1) {
            return $target;
        }
        // DokuWiki ids are case-insensitive and store spaces as underscores
        return strtolower(str_replace(' ', '_', $target));
    }

    private static function convertInline(string $line, array &$unknown): string
    {
        // Link destinations and bare URLs are set aside first, so the
        // `//` of "https://" is never read as italic markup
        $kept = [];
        $keep = static function (string $text) use (&$kept): string {
            $kept[] = $text;

            return "\x00" . (\count($kept) - 1) . "\x00";
        };

        // Links [[target|label]] and [[target]] → [label](target): a page id
        // becomes the canonical colon path (Support\InternalLink), an
        // external URL is kept as it is
        $line = preg_replace_callback(
            '/\[\[([^|\]]+)(?:\|([^\]]+))?\]\]/',
            fn($m) => '[' . (isset($m[2]) && $m[2] !== '' ? $m[2] : trim($m[1])) . '](' . $keep(self::linkTarget($m[1])) . ')',
            $line
        );
        $line = preg_replace_callback('~\b[a-z][a-z0-9+.-]*://[^\s<>()\x00]+~i', fn($m) => $keep($m[0]), $line);

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

        return preg_replace_callback('/\x00(\d+)\x00/', static fn($m) => $kept[(int) $m[1]], $line);
    }
}
