<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * A reference-manager export of any of the three formats, told apart by what it holds
 * (docs/library.md, phase 4): BibTeX (`@article{`), RIS (`TY  - `) or CSL-JSON (`[`, `{`).
 */
final class Bibliography
{
    /** The formats an export is written in */
    public const FORMATS = ['bibtex', 'ris', 'csl', 'text'];

    /** @return 'bibtex'|'ris'|'csl'|null */
    public static function detect(string $text): ?string
    {
        $head = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $text));
        if ($head === '') {
            return null;
        }
        if ($head[0] === '[' || $head[0] === '{') {
            return 'csl';
        }
        if (preg_match('/^TY  ?-/m', $head) === 1) {
            return 'ris';
        }

        return preg_match('/^\s*@[A-Za-z]+\s*[{(]/m', $head) === 1 ? 'bibtex' : null;
    }

    /** @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}> */
    public static function parse(string $text): array
    {
        return match (self::detect($text)) {
            'bibtex' => Bibtex::parse($text),
            'ris' => Ris::parse($text),
            'csl' => CslJson::parse($text),
            default => [],
        };
    }

    /**
     * @param list<array{key: string, csl: array<string, mixed>, tags?: list<string>}> $entries
     * @param string $style for `text`: the formatted list in that style
     */
    public static function write(string $format, array $entries, string $style = 'vancouver'): string
    {
        return match ($format) {
            'bibtex' => Bibtex::write($entries),
            'ris' => Ris::write($entries),
            'csl' => CslJson::write($entries),
            default => implode("\n", array_map(static fn (array $e): string => Citation::format($style, $e['csl']), $entries)) . "\n",
        };
    }
}
