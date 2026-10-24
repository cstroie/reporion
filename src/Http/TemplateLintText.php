<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * Support\TemplateLint's problems in words: one per problem for the API and
 * the CLI, one notice for the page view (the first few, then a count)
 */
final class TemplateLintText
{
    private const SHOWN = 3;

    /** @param array{code: string, line: int, text: string} $problem */
    public static function message(array $problem): string
    {
        $what = t(str_starts_with($problem['code'], 'shape.') ? 'tpl.lint.shape' : 'tpl.lint.' . $problem['code']);

        return $problem['line'] > 0 ? t('tpl.lint.at', [$problem['line'], $what, $problem['text']]) : $what;
    }

    /** @param list<array{code: string, line: int, text: string}> $problems */
    public static function notice(array $problems): ?string
    {
        if ($problems === []) {
            return null;
        }
        $shown = implode('; ', array_map(self::message(...), \array_slice($problems, 0, self::SHOWN)));
        $more = \count($problems) - self::SHOWN;

        return t('tpl.lint.summary', [\count($problems), $shown]) . ($more > 0 ? ' ' . t('tpl.lint.more', [$more]) : '');
    }
}
