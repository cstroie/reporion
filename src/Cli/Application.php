<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Ai\PromptImport;
use Reporion\Service\NewReport;
use Reporion\Service\PageMoves;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Cli\ImportCommitCommand;
use Reporion\Cli\ImportConvertCommand;
use Reporion\Cli\ImportRollbackCommand;
use Reporion\Cli\ImportScanCommand;
use Reporion\Cli\PagesCommitCommand;
use Reporion\Cli\PagesConvertCommand;
use Reporion\Cli\PagesScanCommand;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Plugin\Hooks;
use Reporion\Schema\Loader as SchemaLoader;
use Reporion\Service\Accessions;
use Reporion\Support\AccessionFormat;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Render;
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
        // The same instance settings the front controller uses (data/settings.yaml)
        $config = Kernel::withInstanceSettings($config);
        $app = new self(Output::standard());

        $app->register('doctor', static fn (): CommandInterface => new DoctorCommand($config));
        $app->register('serve', static fn (): CommandInterface => new ServeCommand($rootDir));
        $app->register('ai:check', static fn (): CommandInterface => new AiCheckCommand($config));

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
        );
        $app->register('index:verify', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexVerifyCommand($maintenance($storage, $index));
        });
        $app->register('index:rebuild', static function () use ($indexAndStorage, $config): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new IndexRebuildCommand($storage, $index, (string) $config['paths']['data']);
        });
        $app->register('ai:import-prompts', static function () use ($indexAndStorage): CommandInterface {
            [$storage] = $indexAndStorage();

            return new AiImportPromptsCommand(new PromptImport($storage));
        });
        $app->register('pages:check-frontmatter', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesCheckFrontmatterCommand($maintenance($storage, $index));
        });
        $app->register('pages:normalize-headings', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesNormalizeHeadingsCommand($maintenance($storage, $index));
        });
        $app->register('pages:apply-meta-block', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new PagesApplyMetaBlockCommand($maintenance($storage, $index));
        });
        // Tasks plugins add through the maintenance.tasks hook (e.g. pacs:link, dicom)
        $app->register('pacs:link', static function () use ($indexAndStorage, $audit, $config, $rootDir): CommandInterface {
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

            return new PluginTaskCommand(
                MaintenanceRunner::standard($storage, $index, $audit(), (string) $config['paths']['data'], (int) ($config['pages']['trash_purge_days'] ?? 30), $tasks),
                'pacs:link',
                ['site', 'limit'],
            );
        });
        $app->register('templates:import', static function () use ($indexAndStorage, $audit, $config, $rootDir): CommandInterface {
            [$storage] = $indexAndStorage();
            $map = json_decode((string) @file_get_contents($rootDir . '/conf/import-map.json'), true);

            return new TemplatesImportCommand(
                $storage,
                $audit(),
                \is_array($config['reports']['modality_namespaces'] ?? null) && $config['reports']['modality_namespaces'] !== []
                    ? $config['reports']['modality_namespaces']
                    : NewReport::DEFAULT_MODALITY_NAMESPACES,
                \is_array($map['template_category_region'] ?? null) ? $map['template_category_region'] : [],
            );
        });
        $app->register('journal:replay', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new JournalReplayCommand($maintenance($storage, $index));
        });
        $app->register('trash:purge', static function () use ($indexAndStorage, $maintenance): CommandInterface {
            [$storage, $index] = $indexAndStorage();

            return new TrashPurgeCommand($maintenance($storage, $index));
        });
        $app->register('page:new', static function () use ($indexAndStorage, $audit): CommandInterface {
            [$storage] = $indexAndStorage();

            return new PageNewCommand($storage, $audit());
        });
        $app->register('page:move', static function () use ($indexAndStorage, $audit): CommandInterface {
            [$storage] = $indexAndStorage();

            return new PageMoveCommand(new PageMoves($storage, $audit()));
        });
        $app->register('user:create', static fn (): CommandInterface
            => new UserCreateCommand(new FlatFileUserStore((string) $config['paths']['data'])));

        $app->register('import:scan', static fn (): CommandInterface
            => new ImportScanCommand((string) $config['paths']['data'], $config));
        $app->register('import:convert', static fn (): CommandInterface
            => new ImportConvertCommand((string) $config['paths']['data']));
        $app->register('import:commit', static function () use ($indexAndStorage, $config, $audit): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new ImportCommitCommand((string) $config['paths']['data'], $storage, $audit());
        });
        $app->register('import:rollback', static function () use ($indexAndStorage, $config, $audit): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new ImportRollbackCommand((string) $config['paths']['data'], $storage, $audit());
        });

        $app->register('pages:scan', static fn (): CommandInterface
            => new PagesScanCommand((string) $config['paths']['data']));
        $app->register('pages:convert', static fn (): CommandInterface
            => new PagesConvertCommand((string) $config['paths']['data']));
        $app->register('pages:commit', static function () use ($indexAndStorage, $config, $audit): CommandInterface {
            [$storage, $index] = $indexAndStorage();
            return new PagesCommitCommand((string) $config['paths']['data'], $storage, $audit());
        });
        $app->register('pages:structure', static function () use ($indexAndStorage, $config, $audit): CommandInterface {
            [$storage] = $indexAndStorage();

            return new PagesStructureCommand((string) $config['paths']['data'], $storage, $audit());
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
