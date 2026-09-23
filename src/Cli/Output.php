<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * Thin wrapper over two streams, so a command writes through this rather
 * than calling fwrite(STDOUT, ...) directly — the same reason
 * Http\Request/Response exist as objects instead of touching superglobals:
 * a test can hand a command an in-memory stream and read it back.
 */
final class Output
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {
    }

    public static function standard(): self
    {
        return new self(\STDOUT, \STDERR);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . \PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . \PHP_EOL);
    }
}
