<?php

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What a file given to the new-page form or dropped on a namespace becomes, by its extension:
 * Markdown (the text as it is), plain text (D40, `format: text`), or a source file, kept as a
 * fenced code block in a Markdown page with its language so it reads and highlights as code.
 * The one list: the page's scripts get its extensions from the template.
 */
final class SourceFiles
{
    public const MARKDOWN = 'markdown';
    public const TEXT = 'text';
    public const CODE = 'code';

    /** extension => the fence's language */
    private const LANGUAGES = [
        'py' => 'python', 'php' => 'php', 'js' => 'javascript', 'mjs' => 'javascript', 'ts' => 'typescript',
        'jsx' => 'jsx', 'tsx' => 'tsx', 'json' => 'json', 'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'toml',
        'ini' => 'ini', 'sh' => 'bash', 'bash' => 'bash', 'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'cc' => 'cpp',
        'hpp' => 'cpp', 'cs' => 'csharp', 'java' => 'java', 'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby',
        'pl' => 'perl', 'lua' => 'lua', 'sql' => 'sql', 'html' => 'html', 'css' => 'css', 'xml' => 'xml',
        'r' => 'r', 'swift' => 'swift', 'kt' => 'kotlin', 'ps1' => 'powershell', 'tex' => 'latex',
    ];

    /** @return self::MARKDOWN|self::TEXT|self::CODE|null null: not a kind of file the form takes */
    public static function kindOf(string $name): ?string
    {
        $extension = self::extensionOf($name);

        return match (true) {
            $extension === 'md' || $extension === 'markdown' => self::MARKDOWN,
            $extension === 'txt' => self::TEXT,
            isset(self::LANGUAGES[$extension]) => self::CODE,
            default => null,
        };
    }

    public static function language(string $name): string
    {
        return self::LANGUAGES[self::extensionOf($name)] ?? '';
    }

    /** @return list<string> every extension taken, for the scripts' filter */
    public static function extensions(): array
    {
        return ['md', 'markdown', 'txt', ...array_keys(self::LANGUAGES)];
    }

    /** The file as a Markdown body: its name as the heading, the code fenced longer than any backtick run in it */
    public static function codeBody(string $name, string $code): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            $longest = max(array_map('strlen', $runs[0]));
        }
        $fence = str_repeat('`', max(3, $longest + 1));

        return '# ' . basename($name) . "\n\n" . $fence . self::language($name) . "\n" . rtrim($code, "\n") . "\n" . $fence . "\n";
    }

    private static function extensionOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
