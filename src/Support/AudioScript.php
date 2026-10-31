<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * The audio overview's script (docs/library.md, phase 8), as the `studio-audio` prompt writes it:
 * one turn a line, `A:` the first host, `B:` the second (a line that names neither continues the
 * turn before). Consecutive turns of one host are one; a turn longer than a speech request takes
 * is cut at its sentences; at most MAX_TURNS requests, the rest left out (and said). Pure.
 */
final class AudioScript
{
    /** The most speech requests one overview makes */
    public const MAX_TURNS = 60;

    /** The most characters one speech request carries (OpenAI's limit is 4096) */
    public const MAX_CHARS = 3000;

    /**
     * @return array{turns: list<array{speaker: int, text: string}>, cut: bool}
     */
    public static function turns(string $script): array
    {
        $merged = [];
        foreach (preg_split('/\R/u', $script) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\**\s*([AB])\s*\**\s*[:.]\s*\**\s*(.*)$/u', $line, $m) === 1) {
                $speaker = $m[1] === 'B' ? 1 : 0;
                $text = trim($m[2]);
            } elseif ($merged !== []) {
                $speaker = $merged[\count($merged) - 1]['speaker'];
                $text = $line;
            } else {
                continue;
            }
            if ($text === '') {
                continue;
            }
            $last = \count($merged) - 1;
            if ($last >= 0 && $merged[$last]['speaker'] === $speaker) {
                $merged[$last]['text'] .= ' ' . $text;
            } else {
                $merged[] = ['speaker' => $speaker, 'text' => $text];
            }
        }
        $turns = [];
        foreach ($merged as $turn) {
            foreach (self::pieces($turn['text']) as $piece) {
                $turns[] = ['speaker' => $turn['speaker'], 'text' => $piece];
            }
        }

        return ['turns' => \array_slice($turns, 0, self::MAX_TURNS), 'cut' => \count($turns) > self::MAX_TURNS];
    }

    /** @return list<string> $text cut at sentences into pieces of at most MAX_CHARS */
    private static function pieces(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }
        $pieces = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text] as $sentence) {
            while (mb_strlen($sentence) > self::MAX_CHARS) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                $pieces[] = mb_substr($sentence, 0, self::MAX_CHARS);
                $sentence = mb_substr($sentence, self::MAX_CHARS);
            }
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > self::MAX_CHARS) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $sentence : $current . ' ' . $sentence;
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
