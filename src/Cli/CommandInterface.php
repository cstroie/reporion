<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

interface CommandInterface
{
    /**
     * @param list<string> $args argv beyond the command name
     */
    public function run(array $args, Output $output): int;
}
