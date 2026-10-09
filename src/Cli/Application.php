<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\PatientCsvTask;
use Reporion\Service\PageMoves;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\Loader as PluginLoader;
use Reporion\Schema\Loader as SchemaLoader;
use Reporion\Service\Accessions;
use Reporion\Support\AccessionFormat;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Render;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion's existing contract: Application::boot($config)->run($argv).
 * A handful of commands, not a framework — same "no framework" spirit as
 * Http\Router (CLAUDE.md docs/architecture-api.md §2).
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

    /** @var array<string, CommandHelp> */
    private array $help = [];

    private function __construct(
        private readonly Output $output,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function boot(array $config, ?Output $output = null): self
    {
        $rootDir = \dirname(__DIR__, 2);
        // The same instance settings the front controller uses (data/settings.yaml)
        $config = Kernel::withInstanceSettings($config);
        $app = new self($output ?? Output::standard());

        $app->register('doctor', DoctorCommand::class, static fn (): CommandInterface => new DoctorCommand($config));
        $app->register('serve', ServeCommand::class, static fn (): CommandInterface => new ServeCommand($rootDir));
        // Commands the enabled plugins declare (plugin.json `commands`): they need no service, so no index is opened
        $plugins = new PluginLoader((string) ($config['paths']['plugins'] ?? $rootDir . '/plugins'));
        foreach ($plugins->commands(array_values(array_filter((array) ($config['plugins']['enabled'] ?? []), 'is_string'))) as $name => $class) {
            $app->register($name, $class, static fn (): CommandInterface => new $class());
        }
        $app->register('ai:check', AiCheckCommand::class, static fn (): CommandInterface => new AiCheckCommand($config));

        $audit = static fn (): AuditLog => new AuditLog((string) ($config['paths']['audit'] ?? $config['paths']['data'] . '/audit'));
        $indexAndStorage = static function () use ($config, $rootDir): array {
            $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations');
            $storage = new FlatFile((string) $config['paths']['data'], $index);

            return [$storage, $index];
        };
        // The same maintenance tasks Admin → Maintenance runs (Service\Maintenance)
        $maintenance = static fn (FlatFile $storage, Sqlite $index): MaintenanceRunner => MaintenanceRunner::standard(
            $storage,
            $index,
            $audit(),
            (string) $config['paths']['data'],
            (int) ($config['pages']['trash_purge_days'] ?? 30),
            [Kernel::summarizeTask($config, $storage, $index, $audit()), Kernel::tagTask($config, $rootDir, $storage, $index, $audit()), Kernel::vectorsTask($config, $storage, $index)],
        );
        $app->register('index:verify', IndexVerifyCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexVerifyCommand($maintenance($storage, $index));
        });
        $app->register('integrity:verify', IntegrityVerifyCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IntegrityVerifyCommand($maintenance($storage, $index));
        });
        $app->register('index:rebuild', IndexRebuildCommand::class, static function () use ($indexAndStorage, $config, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexRebuildCommand($storage, $index, (string) $config['paths']['data'], $maintenance($storage, $index));
        });
        $app->register('index:vectors', IndexVectorsCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexVectorsCommand($maintenance($storage, $index));
        });
        // Not in standard(): the table is a file on the server, so Admin → Maintenance cannot offer it
        $app->register('pages:apply-patient-csv', PagesApplyPatientCsvCommand::class, static function () use ($indexAndStorage, $audit, $config): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesApplyPatientCsvCommand(
                MaintenanceRunner::standard($storage, $index, $audit(), (string) $config['paths']['data'], (int) ($config['pages']['trash_purge_days'] ?? 30), [new PatientCsvTask($storage, $audit())]),
            );
        });
        $app->register('pages:summarize', PagesSummarizeCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesSummarizeCommand($maintenance($storage, $index));
        });
        $app->register('pages:tag', PagesTagCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesTagCommand($maintenance($storage, $index));
        });
        // Tasks plugins add through the maintenance.tasks hook (e.g. pacs:link, dicom)
        $app->register('pacs:link', PacsLinkCommand::class, static function () use ($indexAndStorage, $audit, $config, $rootDir): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            $accessions = new Accessions(
                (string) $config['paths']['data'],
                $index,
                (string) ($config['accession']['pattern'] ?? AccessionFormat::DEFAULT_PATTERN),
                (int) ($config['accession']['seq_pad'] ?? AccessionFormat::DEFAULT_PAD),
            );
            $newReport = Kernel::newReport($config, $rootDir, $index, $storage, $accessions, new SchemaLoader($rootDir . '/conf/schema'));
            $hooks = new Hooks();
            Kernel::loadPlugins($config, $rootDir, $storage, $index, $audit(), $newReport, new Render(), $hooks);
            $tasks = array_values(array_filter($hooks->all('maintenance.tasks'), static fn (mixed $t): bool => $t instanceof MaintenanceTask));

            return new PacsLinkCommand(
                MaintenanceRunner::standard($storage, $index, $audit(), (string) $config['paths']['data'], (int) ($config['pages']['trash_purge_days'] ?? 30), $tasks),
            );
        });
        $app->register('journal:replay', JournalReplayCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new JournalReplayCommand($maintenance($storage, $index));
        });
        $app->register('trash:purge', TrashPurgeCommand::class, static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new TrashPurgeCommand($maintenance($storage, $index));
        });
        $app->register('page:new', PageNewCommand::class, static function () use ($indexAndStorage, $audit): CommandInterface {
            [$storage] = $indexAndStorage();

            return new PageNewCommand($storage, $audit());
        });
        $app->register('page:move', PageMoveCommand::class, static function () use ($indexAndStorage, $audit): CommandInterface {
            [$storage] = $indexAndStorage();

            return new PageMoveCommand(new PageMoves($storage, $audit()));
        });
        $app->register('user:create', UserCreateCommand::class, static fn (): CommandInterface
            => new UserCreateCommand(new FlatFileUserStore((string) $config['paths']['data'])));


        return $app;
    }

    /**
     * @param class-string<CommandInterface> $class help comes from the class, so no command is built to answer --help
     * @param callable(): CommandInterface  $factory
     */
    private function register(string $name, string $class, callable $factory): void
    {
        $this->factories[$name] = $factory;
        $this->help[$name] = $class::help();
    }

    /** @return array<string, CommandHelp> every registered command's help, by name */
    public function commands(): array
    {
        return $this->help;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === '--help' || $name === '-h') {
            $this->listCommands();

            return 0;
        }
        if ($name === null) {
            $this->output->error('Usage: bin/reporion <command> [options]   (bin/reporion --help lists the commands)');

            return 1;
        }
        if (!isset($this->factories[$name])) {
            $this->output->error("Unknown command: {$name} (bin/reporion --help lists the commands)");

            return 1;
        }

        $args = \array_slice($argv, 2);
        if (\in_array('--help', $args, true) || \in_array('-h', $args, true)) {
            $this->output->write($this->help[$name]->render($name));

            return 0;
        }
        // What the command is about to do, before it does it. --json keeps stdout to the JSON document
        if (!\in_array('--json', $args, true)) {
            $this->output->line($this->help[$name]->summary);
        }

        $command = ($this->factories[$name])();

        return $command->run($args, $this->output);
    }

    private function listCommands(): void
    {
        $this->output->line('Usage: bin/reporion <command> [options]');
        $this->output->line('       bin/reporion <command> --help    what the command does and its options');
        $this->output->line('');
        $this->output->line('Commands:');
        $width = max(array_map('strlen', array_keys($this->help))) + 2;
        foreach ($this->help as $command => $help) {
            $this->output->line('  ' . str_pad($command, $width) . $help->summary);
        }
    }
}
