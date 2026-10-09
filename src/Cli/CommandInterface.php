<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

interface CommandInterface
{
    /**
     * What the command does and every option it takes. Static, so that
     * `--help` never builds the command — building one may open the index.
     */
    public static function help(): CommandHelp;

    /**
     * @param list<string> $args argv beyond the command name
     */
    public function run(array $args, Output $output): int;
}
