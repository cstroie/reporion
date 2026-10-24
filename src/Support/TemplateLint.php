<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

/**
 * What a report template's text does that the `create` prompt cannot use well
 * (2026-10-24, owner's request): the bracket notation is `[a / b]` pick one,
 * `[normal sentence. / pathological sentence …]` swap the sentence, `[...]` a
 * blank, `[sentence.]` optional. Structural rules only, the ones a template
 * gets wrong by typing, never wording: a sentence's meaning is the doctor's.
 * A warning, never a block (D7), shown in the page view, the API's `lint`
 * and `bin/reporion templates:lint`.
 *
 * Codes: `bracket` (the line's brackets do not close: a bracket spanning two
 * lines included), `slash` (a ` / ` choice outside brackets; the size form
 * `[...] / [...] / [...] mm` is not one, inside brackets either), `dash` (— or –: a sentence is joined
 * by a comma, semicolon or full stop), `heading` (a bracket in a heading: it
 * stays in the report as typed), `vague` (a choice that says nothing to write:
 * `modificări`, `nu se aplică`, `descriere`, or a bare `[...]` beside a
 * sentence), plus TemplateHeadings::problems()' codes as `shape.{code}`.
 */
final class TemplateLint
{
    /** A choice that is a placeholder word, not text a report can carry */
    private const VAGUE = ['modificări', 'modificari', 'nu se aplică', 'nu se aplica', 'descriere'];

    private const QUOTE = 80;

    /**
     * A report template: under `templates:`, not a snippet or a readable page template, not a text page (D40)
     *
     * @param array<string, mixed> $frontmatter
     */
    public static function applies(string $path, array $frontmatter): bool
    {
        return str_starts_with($path, Templates::NS . ':')
            && !str_starts_with($path, Templates::NS . ':snippets:')
            && !str_starts_with($path, Templates::PAGES_NS . ':')
            && !BodyFormat::isText($frontmatter);
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{code: string, line: int, text: string}> line 0: the page as a whole
     */
    public static function problems(array $frontmatter, string $body): array
    {
        $found = array_map(static fn (string $code): array => ['code' => 'shape.' . $code, 'line' => 0, 'text' => ''], TemplateHeadings::problems($frontmatter, $body));
        $fence = false;
        foreach (preg_split('/\R/u', $body) ?: [] as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fence = !$fence;
                continue;
            }
            if ($fence || trim($line) === '') {
                continue;
            }
            $add = static function (string $code) use (&$found, $i, $line): void {
                $found[] = ['code' => $code, 'line' => $i + 1, 'text' => mb_strimwidth(trim($line), 0, self::QUOTE, '…')];
            };
            $heading = preg_match('/^#{1,6}\s/', $line) === 1;
            if (preg_match('/[—–]/u', $line) === 1) {
                $add('dash');
            }
            if ($heading) {
                if (str_contains($line, '[')) {
                    $add('heading');
                }
                continue;
            }
            $groups = self::groups($line);
            if ($groups === null) {
                $add('bracket');
                continue;
            }
            if (self::slashOutside($line)) {
                $add('slash');
            }
            foreach ($groups as $group) {
                if (self::vague($group)) {
                    $add('vague');
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Every bracket group of the line, outer and nested, as its inner text; null when they do not close
     *
     * @return ?list<string>
     */
    private static function groups(string $line): ?array
    {
        $open = [];
        $groups = [];
        $chars = mb_str_split($line);
        foreach ($chars as $i => $c) {
            if ($c === '[') {
                $open[] = $i;
            } elseif ($c === ']') {
                if ($open === []) {
                    return null;
                }
                $start = array_pop($open);
                $groups[] = implode('', \array_slice($chars, $start + 1, $i - $start - 1));
            }
        }

        return $open === [] ? $groups : null;
    }

    /** A ` / ` at depth 0, other than between two bracket groups (`[...] / [...] / [...] mm`) */
    private static function slashOutside(string $line): bool
    {
        $depth = 0;
        $chars = mb_str_split($line);
        $n = \count($chars);
        for ($i = 0; $i < $n; ++$i) {
            $c = $chars[$i];
            if ($c === '[') {
                ++$depth;
            } elseif ($c === ']') {
                --$depth;
            } elseif ($c === '/' && $depth === 0 && ($chars[$i - 1] ?? '') === ' ' && ($chars[$i + 1] ?? '') === ' ' && !self::isSize($chars, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The slash at $i is the size form's, `[...] / [...]`: two blanks, not two choices
     *
     * @param list<string> $chars
     */
    private static function isSize(array $chars, int $i): bool
    {
        return implode('', \array_slice($chars, max(0, $i - 6), 5)) === '[...]' && implode('', \array_slice($chars, $i + 2, 5)) === '[...]';
    }

    /** One group's choices: one that is a placeholder word, or a bare blank beside a sentence */
    private static function vague(string $group): bool
    {
        $choices = self::choices($group);
        if (\count($choices) < 2) {
            return false;
        }
        $sentence = false;
        foreach ($choices as $choice) {
            $sentence = $sentence || str_ends_with($choice, '.');
        }
        foreach ($choices as $choice) {
            $word = mb_strtolower(rtrim($choice, '. '));
            if (\in_array($word, self::VAGUE, true) || ($sentence && $word === '[...]')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The group's own choices: split at ` / ` outside its nested brackets
     *
     * @return list<string>
     */
    private static function choices(string $group): array
    {
        $choices = [];
        $depth = 0;
        $current = '';
        $chars = mb_str_split($group);
        $n = \count($chars);
        for ($i = 0; $i < $n; ++$i) {
            $c = $chars[$i];
            $depth += $c === '[' ? 1 : ($c === ']' ? -1 : 0);
            if ($depth === 0 && $c === ' ' && ($chars[$i + 1] ?? '') === '/' && ($chars[$i + 2] ?? '') === ' ' && !self::isSize($chars, $i + 1)) {
                $choices[] = trim($current);
                $current = '';
                $i += 2;
                continue;
            }
            $current .= $c;
        }
        $choices[] = trim($current);

        return $choices;
    }
}
