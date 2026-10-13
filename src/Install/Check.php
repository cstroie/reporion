<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

/**
 * One line of the web installer's checklist (D43): what was checked, how it
 * went, and, when the installer cannot put it right itself: the command
 * or setting that does. Loaded without Composer's autoloader (vendor/ may
 * not exist yet), so it depends on nothing.
 */
final class Check
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $detail = '',
        public readonly string $fix = '',
    ) {
    }

    public static function pass(string $label, string $detail = ''): self
    {
        return new self(self::PASS, $label, $detail);
    }

    public static function warn(string $label, string $detail = '', string $fix = ''): self
    {
        return new self(self::WARN, $label, $detail, $fix);
    }

    public static function fail(string $label, string $detail = '', string $fix = ''): self
    {
        return new self(self::FAIL, $label, $detail, $fix);
    }

    /**
     * @param list<self> $checks
     */
    public static function anyFailed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->status === self::FAIL) {
                return true;
            }
        }

        return false;
    }
}
