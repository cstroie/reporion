<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * Flashcards (docs/library.md, phase 8): the cards the `source-flashcards` and `studio-flashcards`
 * prompts write (`Q: …` then `A: …`, a blank line between cards), read back from the kept page,
 * and written as a file Anki imports (File → Import): tab-separated, with Anki's own header lines
 * (`#separator:tab`, `#html:true`, `#notetype:Basic`, `#tags:`), every field HTML-escaped and its
 * line breaks `<br>`, so a tab or a `<` in a card can never break a row or become markup. A
 * citation `[@key, p. N]` becomes the plain label the reader knows, `(Author year, p. N)`, from
 * the library's record. Pure.
 */
final class Flashcards
{
    /** The most cards read from one page */
    public const MAX = 500;

    /**
     * @return list<array{front: string, back: string}>
     */
    public static function parse(string $text): array
    {
        $cards = [];
        $front = null;
        $back = null;
        $into = null;
        $flush = static function () use (&$cards, &$front, &$back): void {
            if ($front !== null && $back !== null && trim($front) !== '' && trim($back) !== '') {
                $cards[] = ['front' => trim($front), 'back' => trim($back)];
            }
            $front = null;
            $back = null;
        };
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // `Q:`, `**Q:**`, `- Q:`, `Q1:`, `Î:` (întrebare) and `R:` (răspuns) as the model may write them
            if (preg_match('/^\s*(?:[-*]\s*)?\**\s*(Q|Î)\d*\s*:\**\s*(.*)$/u', $line, $m) === 1) {
                $flush();
                $front = $m[2];
                $into = 'front';
            } elseif (preg_match('/^\s*(?:[-*]\s*)?\**\s*(A|R)\d*\s*:\**\s*(.*)$/u', $line, $m) === 1 && $front !== null) {
                $back = $m[2];
                $into = 'back';
            } elseif (trim($line) === '') {
                if ($into === 'back') {
                    $flush();
                    $into = null;
                }
            } elseif ($into === 'front') {
                $front .= "\n" . trim($line);
            } elseif ($into === 'back') {
                $back .= "\n" . trim($line);
            }
            if (\count($cards) >= self::MAX) {
                break;
            }
        }
        $flush();

        return \array_slice($cards, 0, self::MAX);
    }

    /**
     * The cards as Anki's import file
     *
     * @param list<array{front: string, back: string}>   $cards
     * @param list<string>                               $tags  Anki tags for every card (spaces made hyphens)
     * @param \Closure(string, ?string): string          $cite  a citation key and its locator ("p. 3") as the label to print
     */
    public static function anki(array $cards, array $tags, \Closure $cite): string
    {
        $tags = array_values(array_filter(array_map(static fn (string $t): string => (string) preg_replace('/[\s#]+/u', '-', trim($t)), $tags), static fn (string $t): bool => $t !== ''));
        $out = "#separator:tab\n#html:true\n#notetype:Basic\n" . ($tags !== [] ? '#tags:' . implode(' ', $tags) . "\n" : '');
        foreach ($cards as $card) {
            $out .= self::field($card['front'], $cite) . "\t" . self::field($card['back'], $cite) . "\n";
        }

        return $out;
    }

    /** @param \Closure(string, ?string): string $cite */
    private static function field(string $text, \Closure $cite): string
    {
        $text = (string) preg_replace_callback('/\[(\s*@[a-z][a-z0-9-]{1,62}[^\]]*)\]/u', static function (array $m) use ($cite): string {
            $labels = [];
            foreach (explode(';', $m[1]) as $item) {
                if (preg_match('/^\s*@([a-z][a-z0-9-]{1,62})\s*(?:,\s*(.+?))?\s*$/u', $item, $c) === 1) {
                    $labels[] = $cite($c[1], isset($c[2]) && $c[2] !== '' ? $c[2] : null);
                }
            }

            return $labels !== [] ? '(' . implode('; ', $labels) . ')' : $m[0];
        }, $text);
        $text = htmlspecialchars(str_replace("\t", ' ', $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\r\n", "\n", "\r"], '<br>', $text);
    }
}
