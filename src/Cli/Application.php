<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Cli\ImportCommitCommand;
use Reporion\Cli\ImportConvertCommand;
use Reporion\Cli\ImportRollbackCommand;
use Reporion\Cli\ImportScanCommand;
use Reporion\Cli\PagesCommitCommand;
use Reporion\Cli\PagesConvertCommand;
use Reporion\Cli\PagesScanCommand;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion's existing contract: Application::boot($config)->run($argv).
 * A handful of commands, not a framework — same "no framework" spirit as
 * Http\Router (CLAUDE.md docs/architecture-api.md §2). trash:purge,
 * page:new, page:move and the import:* family are real, separate future
 * work — see docs/BUILD_LOG.md for why they were not folded into this step.
 *
 * Commands are registered as factories, not instances: doctor and serve
 * don't need a database connection, and index:verify/index:rebuild
 * shouldn't force one open just to print usage or dispatch to an unrelated
 * command. Only the factory for the command actually being run executes.
 */
final class Application
{
    /** @var array<string, callable(): CommandInterface> */
    private array $factories = [];

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

        $app->register('doctor', static fn (): CommandInterface => new DoctorCommand($config));
        $app->register('serve', static fn (): CommandInterface => new ServeCommand($rootDir));

        $indexAndStorage = static function () use ($config, $rootDir): array {
            $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations');
            $storage = new FlatFile((string) $config['paths']['data'], $index);

            return [$storage, $index];
        };
        $app->register('index:verify', static function () use ($indexAndStorage): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexVerifyCommand($storage, $index);
        });
        $app->register('index:rebuild', static function () use ($indexAndStorage): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexRebuildCommand($storage, $index);
        });
        $app->register('user:create', static fn (): CommandInterface
            => new UserCreateCommand(new FlatFileUserStore((string) $config['paths']['data'])));

        $app->register('import:scan', static fn (): CommandInterface
            => new ImportScanCommand((string) $config['paths']['data'], $config));
        $app->register('import:convert', static fn (): CommandInterface
            => new ImportConvertCommand((string) $config['paths']['data']));
        $app->register('import:commit', static function () use ($indexAndStorage, $config): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new ImportCommitCommand((string) $config['paths']['data'], $storage);
        });
        $app->register('import:rollback', static function () use ($indexAndStorage, $config): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new ImportRollbackCommand((string) $config['paths']['data'], $storage);
        });

        $app->register('pages:scan', static fn (): CommandInterface
            => new PagesScanCommand((string) $config['paths']['data']));
        $app->register('pages:convert', static fn (): CommandInterface
            => new PagesConvertCommand((string) $config['paths']['data']));
        $app->register('pages:commit', static function () use ($indexAndStorage, $config): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new PagesCommitCommand((string) $config['paths']['data'], $storage);
        });

        return $app;
    }

    /**
     * @param callable(): CommandInterface $factory
     */
    private function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === null || !isset($this->factories[$name])) {
            $this->output->error('Usage: bin/reporion <command> [options]');
            $this->output->error('');
            $this->output->error('Available commands:');
            foreach (array_keys($this->factories) as $command) {
                $this->output->error("  {$command}");
            }

            return 1;
        }

        $command = ($this->factories[$name])();

        return $command->run(\array_slice($argv, 2), $this->output);
    }
}
