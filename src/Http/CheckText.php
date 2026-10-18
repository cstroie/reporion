<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

/**
 * One check of Support\WritingChecks in words (D51): the Sign screen and
 * the editor's Checks section say it the same way, `sign.check.{code}`.
 */
final class CheckText
{
    /** @param array{code: string, sides: list<string>, other: list<string>, args: list<string>} $check */
    public static function message(array $check): string
    {
        $sides = static fn (array $list): string => implode(' + ', array_map(static fn (string $s): string => t('sign.side.' . $s), $list));
        $args = $check['args'] !== [] ? $check['args'] : [$sides($check['sides']), $sides($check['other'])];

        return t('sign.check.' . $check['code'], $args);
    }
}
