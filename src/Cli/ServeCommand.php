<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * bin/reporion serve — php -S with the right docroot (public/router.php,
 * see its own docblock for why that script exists at all) AND
 * -d ffi.enable=1, which the built-in server does not inherit from plain
 * CLI's FFI trust (confirmed empirically this session — see
 * docs/BUILD_LOG.md; without it every write 500s).
 */
final class ServeCommand implements CommandInterface
{
    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $port = isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : 8080;
        $docroot = $this->rootDir . '/public';
        $router = $docroot . '/router.php';

        $output->line("php -d ffi.enable=1 -S 127.0.0.1:{$port} -t public public/router.php");
        $output->line('Ctrl-C to stop.');
        $output->line('');

        $command = \PHP_BINARY
            . ' -d ffi.enable=1 -S ' . escapeshellarg("127.0.0.1:{$port}")
            . ' -t ' . escapeshellarg($docroot)
            . ' ' . escapeshellarg($router);

        passthru($command, $exitCode);

        return $exitCode;
    }
}
