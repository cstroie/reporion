<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * One doctor check's outcome.
 */
final class CheckResult
{
    public function __construct(
        public readonly CheckStatus $status,
        public readonly string $label,
        public readonly string $detail = '',
    ) {
    }

    public static function pass(string $label, string $detail = ''): self
    {
        return new self(CheckStatus::Pass, $label, $detail);
    }

    public static function warn(string $label, string $detail = ''): self
    {
        return new self(CheckStatus::Warn, $label, $detail);
    }

    public static function fail(string $label, string $detail = ''): self
    {
        return new self(CheckStatus::Fail, $label, $detail);
    }
}
