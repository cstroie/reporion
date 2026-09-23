<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

/**
 * bin/reporion's existing contract: Application::boot($config)->run($argv).
 * A handful of commands, not a framework — same "no framework" spirit as
 * Http\Router (CLAUDE.md docs/architecture-api.md §2). Only doctor and
 * serve exist so far; index:verify, index:rebuild, trash:purge, page:new,
 * page:move and the import:* family are real, separate future work — see
 * docs/BUILD_LOG.md for why they were not folded into this step.
 */
final class Application
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    private function __construct(
        private readonly Output $output,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function boot(array $config): self
    {
        $rootDir = \dirname(__DIR__, 2);
        $app = new self(Output::standard());
        $app->register('doctor', new DoctorCommand($config));
        $app->register('serve', new ServeCommand($rootDir));

        return $app;
    }

    private function register(string $name, CommandInterface $command): void
    {
        $this->commands[$name] = $command;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === null || !isset($this->commands[$name])) {
            $this->output->error('Usage: bin/reporion <command> [options]');
            $this->output->error('');
            $this->output->error('Available commands:');
            foreach (array_keys($this->commands) as $command) {
                $this->output->error("  {$command}");
            }

            return 1;
        }

        return $this->commands[$name]->run(\array_slice($argv, 2), $this->output);
    }
}
