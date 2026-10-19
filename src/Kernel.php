<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Audit\AuditLog;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\FlatFileGroupStore;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\AdminAiController;
use Reporion\Controller\AdminGroupsController;
use Reporion\Controller\AdminIndexController;
use Reporion\Controller\AdminMaintenanceController;
use Reporion\Controller\AdminPluginsController;
use Reporion\Controller\AdminSettingsController;
use Reporion\Controller\AdminSitesController;
use Reporion\Controller\AdminTagsController;
use Reporion\Controller\AdminTrashController;
use Reporion\Controller\AdminUsersController;
use Reporion\Controller\AiController;
use Reporion\Controller\AskController;
use Reporion\Controller\AuthController;
use Reporion\Controller\CommentsController;
use Reporion\Controller\CompareController;
use Reporion\Controller\EditorController;
use Reporion\Controller\EditorPages;
use Reporion\Controller\ExportController;
use Reporion\Controller\FeedController;
use Reporion\Controller\HomeController;
use Reporion\Controller\JoinController;
use Reporion\Controller\MediaController;
use Reporion\Controller\NamespaceController;
use Reporion\Controller\NewPageController;
use Reporion\Controller\PageController;
use Reporion\Controller\PagesApiController;
use Reporion\Controller\PatientMergeController;
use Reporion\Controller\ProfileController;
use Reporion\Controller\RecallController;
use Reporion\Controller\RenderController;
use Reporion\Controller\RevisionsController;
use Reporion\Controller\SearchController;
use Reporion\Controller\SignController;
use Reporion\Controller\SimilarController;
use Reporion\Controller\StatsController;
use Reporion\Controller\ThemeController;
use Reporion\Controller\TimelineController;
use Reporion\Controller\VisibilityController;
use Reporion\Controller\WritingAidsController;
use Reporion\Http\ErrorMapper;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Router;
use Reporion\Http\Session;
use Reporion\Index\IndexInterface;
use Reporion\Index\Sqlite;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\Loader as PluginLoader;
use Reporion\Schema\Loader;
use Reporion\Service\Accessions;
use Reporion\Service\Ai\Actions as AiActions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Ai\Check as AiCheck;
use Reporion\Service\Ai\CommitNote;
use Reporion\Service\Ai\Context as AiContext;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\Embedder;
use Reporion\Service\Ai\FailoverProvider;
use Reporion\Service\Ai\FtsExamples;
use Reporion\Service\Ai\OpenAiCompatibleProvider;
use Reporion\Service\Ai\ProviderInterface;
use Reporion\Service\ArchiveAsk;
use Reporion\Service\Backup\BackupService;
use Reporion\Service\Checklists;
use Reporion\Service\Comments;
use Reporion\Service\DebugPanels;
use Reporion\Service\EditorPage;
use Reporion\Service\ExamAccessions;
use Reporion\Service\FrontmatterFields;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Joins;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\SummarizeTask;
use Reporion\Service\Maintenance\TagReviewTask;
use Reporion\Service\Maintenance\TagTask;
use Reporion\Service\Maintenance\VectorsTask;
use Reporion\Service\NamespaceDefaults;
use Reporion\Service\NewReport;
use Reporion\Service\OdtExport;
use Reporion\Service\PageMoves;
use Reporion\Service\PatientMerge;
use Reporion\Service\PatientStudies;
use Reporion\Service\PdfExport;
use Reporion\Service\PrintView;
use Reporion\Service\PriorStudy;
use Reporion\Service\Publishing;
use Reporion\Service\Recall;
use Reporion\Service\References;
use Reporion\Service\Render;
use Reporion\Service\Revisions;
use Reporion\Service\Signing;
use Reporion\Service\SiteDevices;
use Reporion\Service\Snippets;
use Reporion\Service\Stats;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;
use Reporion\Service\TemplatePages;
use Reporion\Service\TempUploads;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\AccessionFormat;
use Reporion\Support\Feeds;
use Throwable;

/**
 * Boot, container, dispatch. No framework (docs/architecture-api.md §2):
 * this wires concrete services once and hands a Router the closures that
 * call them, then lets the enabled plugins add their hooks and routes
 * (Plugin\Loader, docs/architecture-api.md §5).
 */
final class Kernel
{
    /** An open intent younger than this may be a write still running, not a crashed one */
    private const REPLAY_MIN_AGE_SECONDS = 60;

    private function __construct(
        private readonly Router $router,
        private readonly ErrorMapper $errors,
        /** @var list<string> conf['feeds']['namespaces'], cleaned (Support\Feeds::clean) */
        private readonly array $feedNamespaces,
        /** Whether Ask the archive is there (D53), for the quick-navigation list */
        private readonly ?\Closure $askable = null,
    ) {
    }

    /**
     * The server's own time zone, for an instance that has none set:
     * /etc/timezone, else the /etc/localtime link, else UTC.
     */
    private static function systemTimezone(): string
    {
        $names = [];
        $file = @file_get_contents('/etc/timezone');
        if (\is_string($file)) {
            $names[] = trim($file);
        }
        $link = @readlink('/etc/localtime');
        if (\is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m) === 1) {
            $names[] = $m[1];
        }
        foreach ($names as $name) {
            if (\in_array($name, timezone_identifiers_list(), true)) {
                return $name;
            }
        }

        return 'UTC';
    }

    /**
     * What conf/local.php does not have to say: the built-in value of every
     * setting that Admin → Settings overlays from data/settings.yaml. conf/
     * keeps only the data directory and the session secret; a local.php that
     * still carries any of these keeps winning over the default.
     */
    private const DEFAULTS = [
        'site' => ['title' => 'Reporion', 'tagline' => 'Imaging reports', 'base_url' => '', 'home_page' => 'site:home'],
        'auth' => ['session_name' => 'reporion', 'session_lifetime' => 2592000],
        'pages' => ['trash_purge_days' => 30],
        'accession' => ['pattern' => '{SITE}-{MOD}-{yy}-{seq}', 'seq_pad' => 4],
        'media' => ['max_bytes' => 8388608],
        'export' => ['allow_draft_export' => false, 'allow_public_export' => true, 'pseudonymise_public' => true],
        'feeds' => ['namespaces' => []],
        'references' => ['namespaces' => ['radiology']],
        'sites' => [],
        'plugins' => ['enabled' => []],
    ];

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function withDefaults(array $config): array
    {
        foreach (self::DEFAULTS as $section => $defaults) {
            $given = \is_array($config[$section] ?? null) ? $config[$section] : [];
            $config[$section] = $given + $defaults;
        }
        $data = (string) $config['paths']['data'];
        $config['paths'] += ['index' => $data . '/index.sqlite', 'audit' => $data . '/audit'];

        return $config;
    }

    /**
     * $config with data/settings.yaml (Admin → Settings) laid over it, and
     * the instance's timezone, site name, tagline and icon put in place
     * for the front controller and bin/reporion alike.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function withInstanceSettings(array $config): array
    {
        $config = self::withDefaults($config);
        $config = (new InstanceSettings((string) $config['paths']['data']))->applyTo($config);
        $timezone = (string) ($config['site']['timezone'] ?? '');
        if (!\in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = self::systemTimezone();
        }
        $config['site']['timezone'] = $timezone;
        date_default_timezone_set($timezone);
        reporion_instance(array_filter([
            'app.name' => (string) ($config['site']['title'] ?? ''),
            'auth.tagline' => (string) ($config['site']['tagline'] ?? ''),
            'icon' => (string) ($config['site']['icon'] ?? ''),
            // The top nav's brand toggles between the start page and this
            'home_page_path' => (string) ($config['site']['home_page'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));

        return $config;
    }

    /**
     * The guided new-report service: for the front controller, the plugins
     * and bin/reporion alike.
     *
     * @param array<string, mixed> $config
     */
    /**
     * The AI assistant (phase 15): one request at a time per user. An action
     * may name another server (`Model` cell, `{server}:{alias}`); each keeps
     * its own egress rule. $delays replaces the configured retries:`[]`
     * for a caller that cannot wait (a Save's revision note, 2026-10-10).
     *
     * @param array<string, mixed> $config
     * @param ?list<int>           $delays
     */
    public static function assistant(array $config, AiConfig $aiConfig, FlatFile $storage, Sqlite $index, AuditLog $audit, ?array $delays = null): Assistant
    {
        $providers = static function (?string $server) use ($config, $aiConfig): ?ProviderInterface {
            $slot = $server === null ? null : AiConfig::slotByName($config, $server);
            if ($server !== null && $slot === null) {
                return null;
            }
            $own = $slot === null ? $aiConfig : AiConfig::fromConfig($config, $slot);
            if ($slot !== null && ($own->endpoint === '' || $own->model === '')) {
                return null;
            }
            $provider = new OpenAiCompatibleProvider($own, new EgressGuard());
            // Phase 34f: the server's fallback, when it has one that is set up
            $other = $own->fallback !== null ? AiConfig::fromConfig($config, $own->fallback) : null;
            if ($other === null || $other->endpoint === '' || $other->model === '') {
                return $provider;
            }

            return new FailoverProvider($provider, new OpenAiCompatibleProvider($other, new EgressGuard()), $own->serverName, $other->serverName);
        };

        $tags = new TagDictionary((string) $config['paths']['data'], \dirname(__DIR__) . '/resources/synonyms.txt');

        return new Assistant(new AiContext($storage, $index, new FtsExamples($index, $storage), $tags, new Recall($index, $storage)), $providers, $audit, (string) $config['paths']['data'] . '/ai', $delays ?? Assistant::delaysFromConfig($config));
    }

    /**
     * pages:summarize: bulk Summarize (TODO idea 12), for Admin → Maintenance
     * and bin/reporion alike
     *
     * @param array<string, mixed> $config
     */
    public static function summarizeTask(array $config, FlatFile $storage, Sqlite $index, AuditLog $audit): SummarizeTask
    {
        $aiConfig = AiConfig::fromConfig($config);

        return new SummarizeTask($storage, $audit, new AiActions($aiConfig, $storage, $index), self::assistant($config, $aiConfig, $storage, $index, $audit), $aiConfig->timeout);
    }

    /**
     * pages:tag: bulk Suggest tags (2026-10-08), for Admin → Maintenance and
     * bin/reporion alike
     *
     * @param array<string, mixed> $config
     */
    public static function tagTask(array $config, string $rootDir, FlatFile $storage, Sqlite $index, AuditLog $audit): TagTask
    {
        $aiConfig = AiConfig::fromConfig($config);

        return new TagTask($storage, $audit, new AiActions($aiConfig, $storage, $index), self::assistant($config, $aiConfig, $storage, $index, $audit), new TagDictionary((string) $config['paths']['data'], $rootDir . '/resources/synonyms.txt'), $aiConfig->timeout);
    }

    /**
     * tags:review: proposals to tidy the tags, the rule's and the
     * assistant's (2026-10-15), for Admin → Tags and bin/reporion alike
     *
     * @param array<string, mixed> $config
     */
    public static function tagReviewTask(array $config, string $rootDir, FlatFile $storage, Sqlite $index, AuditLog $audit): TagReviewTask
    {
        $aiConfig = AiConfig::fromConfig($config);

        return new TagReviewTask($index, new TagDictionary((string) $config['paths']['data'], $rootDir . '/resources/synonyms.txt'), new AiActions($aiConfig, $storage, $index), self::assistant($config, $aiConfig, $storage, $index, $audit), $aiConfig->timeout);
    }

    /**
     * index:vectors: the embeddings of Similar reports (phase 34e), for
     * Admin → Maintenance and bin/reporion alike
     *
     * @param array<string, mixed> $config
     */
    public static function vectorsTask(array $config, FlatFile $storage, Sqlite $index): VectorsTask
    {
        return new VectorsTask($storage, $index, Embedder::fromConfig($config), AiConfig::fromConfig($config)->timeout);
    }

    public static function newReport(array $config, string $rootDir, Sqlite $index, FlatFile $storage, Accessions $accessions, Loader $schemas): NewReport
    {
        return new NewReport(
            $storage,
            $index,
            $accessions,
            $schemas,
            self::schemaModalities($rootDir),
            \is_array($config['sites'] ?? null) ? $config['sites'] : [],
            \is_array($config['reports']['modality_namespaces'] ?? null) ? $config['reports']['modality_namespaces'] : [],
        );
    }

    /**
     * Plugins (docs/architecture-api.md §5): loads the enabled ones with the
     * services they may ask for: never the filesystem or the PDO handle (D9).
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: PluginLoader, 1: \Reporion\Plugin\Registry}
     */
    public static function loadPlugins(array $config, string $rootDir, FlatFile $storage, Sqlite $index, AuditLog $audit, NewReport $newReport, Render $render, Hooks $hooks): array
    {
        $loader = new PluginLoader((string) ($config['paths']['plugins'] ?? $rootDir . '/plugins'));
        $registry = $loader->load(
            array_values(array_filter((array) ($config['plugins']['enabled'] ?? []), 'is_string')),
            [
                StorageInterface::class => $storage,
                IndexInterface::class => $index,
                AuditLog::class => $audit,
                NewReport::class => $newReport,
                Render::class => $render,
                // Files kept only until used (data/tmp): the dicom plugin's uploaded file
                TempUploads::class => new TempUploads((string) $config['paths']['data']),
                // The sites' devices: read, and link a PACS scanner to one (the dicom plugin)
                // Whether an owner sees the collapsed Debug panels (Admin → Settings → Debug)
                DebugPanels::class => new DebugPanels(($config['debug']['panels'] ?? false) === true),
                SiteDevices::class => new SiteDevices(new InstanceSettings((string) $config['paths']['data']), \is_array($config['sites'] ?? null) ? $config['sites'] : []),
            ],
            \is_array($config['plugins']['settings'] ?? null) ? $config['plugins']['settings'] : [],
            $hooks,
        );

        return [$loader, $registry];
    }

    /**
     * The modalities that have a schema (resources/schema/{mod}.json), in
     * upper case: MR, CT, US, XR, MG.
     *
     * @return list<string>
     */
    private static function schemaModalities(string $rootDir): array
    {
        $codes = [];
        foreach (glob($rootDir . '/resources/schema/*.json') ?: [] as $file) {
            if (basename($file) !== 'base.json') {
                $codes[] = strtoupper(basename($file, '.json'));
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function boot(array $config): self
    {
        $rootDir = \dirname(__DIR__);
        $config = self::withInstanceSettings($config);

        // Admin → Tags' dictionary; its synonyms are D28's search expansion
        $tagDictionary = new TagDictionary((string) $config['paths']['data'], $rootDir . '/resources/synonyms.txt');
        $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations', $tagDictionary->searchGroups(...));
        $storage = new FlatFile((string) $config['paths']['data'], $index);
        // A page on disk the index lacks is put right when a signed-in lookup misses it (FlatFile::adopt())
        $index->onMiss($storage->adopt(...));
        // Crash recovery (invariant 7): finish writes a crash left half-done.
        // Never fails the request; the next one simply tries again. Logged
        // by class only: an exception message may carry a page path (invariant 8).
        try {
            $storage->replayCrashedWrites(self::REPLAY_MIN_AGE_SECONDS);
        } catch (Throwable $e) {
            error_log('reporion: journal replay failed: ' . $e::class);
        }
        $groups = new FlatFileGroupStore((string) $config['paths']['data']);
        $users = new FlatFileUserStore((string) $config['paths']['data'], $groups);
        // Every byline (namespace table, revisions, the page header) reads
        // this instead of a raw username (TODO 13, display_name() in lang.php);
        // the accounts are read only once a byline is shown
        reporion_directory(static function () use ($users): array {
            $directory = [];
            foreach ($users->all() as $account) {
                $directory[$account->username] = $account->signatureName();
            }

            return $directory;
        });
        $audit = new AuditLog((string) ($config['paths']['audit'] ?? $config['paths']['data'] . '/audit'));
        $render = new Render();
        $session = new Session(
            (string) $config['auth']['session_secret'],
            (string) $config['auth']['session_name'],
            (int) $config['auth']['session_lifetime'],
            $users,
            $apiTokens = new ApiTokens($users),
        );

        $trashPurgeDays = (int) $config['pages']['trash_purge_days'];
        // Phase 25: a report's reference pages, from its exams' templates
        // A report's exam templates, read once per request for both its checklists and its references
        $templatePages = new TemplatePages($storage, $index);
        $references = new References($storage, $index, $render, $templatePages);
        // The AI assistant (phase 15): off until configured (D15)
        $aiConfig = AiConfig::fromConfig($config);
        $aiActions = new AiActions($aiConfig, $storage, $index);
        // Similar reports (phase 34e): the one embedding model, when one is set up
        $embedModel = Embedder::fromConfig($config)?->model;
        // A report's vector: none (null), made from its text as it is now (true), or from an older one (false)
        $vectorState = $embedModel !== null ? static fn (PageRecord $page): ?bool => Embedder::vectorState($index, $embedModel, $page) : null;
        $commonMin = Embedder::commonMin($config);
        // Why a report has no Similar reports: normal (its tag) or a stock conclusion
        $similarExcluded = $embedModel !== null ? static fn (string $pid): ?string => $index->similarExcluded($pid, $embedModel, $commonMin) : null;
        $templates = new PageTemplateRenderer($render, $index, $references, $aiActions, $vectorState, $similarExcluded);
        $similar = new SimilarController($index, $embedModel, Embedder::minScore($config), $commonMin);
        // Recall while writing (D50): the editor's passages from finished reports, no AI
        $schemas = new Loader($rootDir . '/resources/schema');
        $moves = new PageMoves($storage, $audit);
        $feeds = new FeedController(
            $index,
            array_values((array) ($config['feeds']['namespaces'] ?? [])),
            rtrim((string) ($config['site']['base_url'] ?? ''), '/'),
            (string) ($config['site']['title'] ?? 'Reporion'),
        );
        $publishing = new Publishing($storage, $audit);
        $visibility = new VisibilityController($storage, $index, $publishing);
        $pages = new PageController($storage, $index, $templates, $trashPurgeDays, new Revisions($storage, $schemas), $audit, $moves);
        $renderController = new RenderController($render);
        // Dashboard filter chips and /stats: one per modality schema (resources/schema/*.json)
        $modalities = array_values(array_map(
            static fn (string $file): string => strtoupper(basename($file, '.json')),
            array_filter(glob($rootDir . '/resources/schema/*.json') ?: [], static fn (string $file): bool => basename($file) !== 'base.json')
        ));
        $stats = new Stats($index, HomeController::STALE_DAYS);
        $home = new HomeController(
            $storage,
            $index,
            $templates,
            (string) $config['site']['home_page'],
            $modalities,
            $stats,
        );
        // /stats site filter: site key => its name (Admin → Sites)
        $siteNames = [];
        foreach (\is_array($config['sites'] ?? null) ? $config['sites'] : [] as $key => $site) {
            $siteNames[(string) $key] = \is_array($site) && \is_string($site['name'] ?? null) && $site['name'] !== '' ? $site['name'] : (string) $key;
        }
        $statsController = new StatsController($stats, $index, $siteNames, $modalities);
        // $ask is made below, with the assistant: read when a search renders
        $ask = null;
        $search = new SearchController($index, static function () use (&$ask): bool {
            return $ask instanceof AskController && $ask->available();
        });
        $adminSettings = new AdminSettingsController(new InstanceSettings((string) $config['paths']['data']), $config, $index, $audit);
        $maintenanceRunner = MaintenanceRunner::standard($storage, $index, $audit, (string) $config['paths']['data'], $trashPurgeDays, [self::summarizeTask($config, $storage, $index, $audit), self::tagTask($config, $rootDir, $storage, $index, $audit), self::tagReviewTask($config, $rootDir, $storage, $index, $audit), self::vectorsTask($config, $storage, $index)]);
        $adminMaintenance = new AdminMaintenanceController($maintenanceRunner, $index, null, new BackupService((string) $config['paths']['data'], $rootDir . '/conf', $audit->directory()), $audit);
        $tags = new Tags($storage, $index, $audit);
        $adminTags = new AdminTagsController($index, $tags, $tagDictionary, $audit, $maintenanceRunner);
        $media = new MediaController($storage, $index, $audit, (int) ($config['media']['max_bytes'] ?? 8 * 1024 * 1024));
        $auth = new AuthController($users, $session, $audit);
        $theme = new ThemeController();
        $signing = new Signing($storage, $schemas, $audit);
        $signPage = new SignController($storage, $index, $signing, $aiActions, $audit);
        $accessions = new Accessions(
            (string) $config['paths']['data'],
            $index,
            (string) ($config['accession']['pattern'] ?? AccessionFormat::DEFAULT_PATTERN),
            (int) ($config['accession']['seq_pad'] ?? AccessionFormat::DEFAULT_PAD),
        );
        $adminSites = new AdminSitesController(new InstanceSettings((string) $config['paths']['data']), $config, $index, $audit);
        $examAccessions = new ExamAccessions($accessions, \is_array($config['sites'] ?? null) ? $config['sites'] : []);
        $pagesApi = new PagesApiController($storage, $signing, $audit, $moves, $index, $render, $publishing, $examAccessions);
        $frontmatterFields = new FrontmatterFields(
            $schemas,
            $index,
            \is_array($config['sites'] ?? null) ? $config['sites'] : [],
            // Phase 25: where a template's References picker looks; none set means radiology
            array_values(array_filter((array) ($config['references']['namespaces'] ?? []), 'is_string')) ?: ['radiology'],
        );
        // The editor's report aids, on a stored report or one not written yet (Service\EditorPage)
        // A page's comments (D52): beside the page, never in it
        $comments = new CommentsController($index, new Comments($storage, $audit));
        $editorPage = new EditorPage($index, $storage, $frontmatterFields);
        $editorPages = new EditorPages($index, $editorPage);
        $recall = new RecallController($editorPages, new Recall($index, $storage));
        // The editor's checks and prior section (D51): rules and the index, no AI
        $writingAids = new WritingAidsController($editorPages, new PriorStudy($storage, $index));
        $assistant = self::assistant($config, $aiConfig, $storage, $index, $audit);
        // Plugins listen on these (and add routes); the Ask screen asks them for outside passages
        $hooks = new Hooks();
        // Ask the archive (D53): a question answered from the passages the caller can read
        $ask = new AskController($aiConfig, $aiActions, $assistant, new ArchiveAsk($index), $index, $hooks);
        $ai = new AiController(
            $aiConfig,
            $aiActions,
            $assistant,
            $storage,
            $index,
            $tagDictionary,
            $editorPage,
        );
        $adminAi = new AdminAiController(new InstanceSettings((string) $config['paths']['data']), $config, $aiActions, new AiCheck(new EgressGuard()), $index, $audit);
        $adminUsers = new AdminUsersController($users, $groups, $index, $audit);
        $adminGroups = new AdminGroupsController($groups, $users, $index, $audit);
        $revisions = new RevisionsController($storage, $index, $audit, $render);
        $patientStudies = new PatientStudies($index);
        $timeline = new TimelineController($storage, $index, $patientStudies, $aiActions);
        $compare = new CompareController($storage, $index, $patientStudies, $render, $aiActions);
        $patientMerge = new PatientMergeController($index, new PatientMerge($storage, $audit));
        $editor = new EditorController($storage, $index, $audit, $patientStudies, new Snippets($index, $storage), $examAccessions, $frontmatterFields, $aiActions, $aiConfig, new Checklists($storage, $index, $templatePages), $references, ($config['editor']['save_stays_open'] ?? false) === true, new CommitNote($aiActions, self::assistant($config, $aiConfig, $storage, $index, $audit, delays: [])), new NamespaceDefaults($index));
        $export = new ExportController(
            $storage,
            $index,
            new PrintView(
                $render,
                $storage,
                $users,
                (array) ($config['sites'] ?? []),
                rtrim((string) ($config['site']['base_url'] ?? ''), '/'),
                $rootDir . '/assets/css/print.css',
            ),
            new PdfExport($rootDir . '/assets'),
            new OdtExport(),
            $audit,
            (array) ($config['export'] ?? []),
        );
        $profile = new ProfileController($users, $index, $audit, $apiTokens, $groups);
        $adminTrash = new AdminTrashController($storage, $index, $audit, $trashPurgeDays);
        $adminIndex = new AdminIndexController(
            new IndexMaintenance($storage, $index, (string) $config['paths']['data'], $audit->directory()),
            $index,
            $audit,
        );
        $newReport = self::newReport($config, $rootDir, $index, $storage, $accessions, $schemas);
        $join = new JoinController(new Joins($storage, $index, $moves, $audit, $newReport->modalityNamespaces()), $index);
        [$pluginLoader, $plugins] = self::loadPlugins($config, $rootDir, $storage, $index, $audit, $newReport, $render, $hooks);
        reporion_plugin_strings($pluginLoader->strings($plugins->loaded));
        reporion_plugin_ui($plugins->ui());
        $adminPlugins = new AdminPluginsController(new InstanceSettings((string) $config['paths']['data']), $config, $plugins, $index, $audit);
        $debugPanels = new DebugPanels(($config['debug']['panels'] ?? false) === true);
        $newPage = new NewPageController($storage, $index, $audit, $newReport, $hooks, $editor, $debugPanels);
        $namespace = new NamespaceController($index, $storage, $render, $moves, $tags, $audit);

        $router = new Router();
        $router->get('/', static fn (Request $request, array $params): Response
            => $home->home($request, $session->principal($request)));
        // Must be registered before the /{path} catch-all: first match wins.
        $router->get('/stats', static fn (Request $request, array $params): Response
            => $statsController->show($request, $session->principal($request)));
        $router->get('/stats.csv', static fn (Request $request, array $params): Response
            => $statsController->csv($request, $session->principal($request)));
        $router->get('/search', static fn (Request $request, array $params): Response
            => $search->search($request, $session->principal($request)));
        $router->get('/login', static fn (Request $request, array $params): Response => $auth->form($request));
        $router->post('/login', static fn (Request $request, array $params): Response => $auth->login($request));
        $router->post('/logout', static fn (Request $request, array $params): Response => $auth->logout($request));
        $router->post('/theme', static fn (Request $request, array $params): Response => $theme->set($request));
        $router->post('/palette', static fn (Request $request, array $params): Response => $theme->setPalette($request));
        // JSON API, versioned under /api/v1 (docs/architecture-api.md §3)
        // distinct from the bare SSR routes above, even where names overlap
        // (e.g. GET /search vs GET /api/v1/search).
        $router->get('/api/v1/search', static fn (Request $request, array $params): Response
            => $search->suggest($request, $session->principal($request)));
        $router->post('/api/v1/ai/complete', static fn (Request $request, array $params): Response
            => $ai->complete($request, $session->principal($request)));
        $router->get('/api/v1/ai/providers', static fn (Request $request, array $params): Response
            => $ai->providers($request, $session->principal($request)));
        $router->post('/api/v1/render', static fn (Request $request, array $params): Response
            => $renderController->render($request, $session->principal($request)));
        $router->post('/api/v1/media', static fn (Request $request, array $params): Response
            => $media->upload($request, $session->principal($request)));
        $router->get('/media/{sha:[0-9a-f]+}.{ext:png|jpg|gif|webp}', static fn (Request $request, array $params): Response
            => $media->show($request, $params['sha'], $params['ext'], $session->principal($request)));
        $router->get('/api/v1/pages', static fn (Request $request, array $params): Response
            => $pagesApi->index($request, $session->principal($request)));
        $router->get('/ask', static fn (Request $request, array $params): Response
            => $ask->show($request, $session->principal($request)));
        $router->post('/api/v1/ask', static fn (Request $request, array $params): Response
            => $ask->ask($request, $session->principal($request)));
        $router->post('/api/v1/recall', static fn (Request $request, array $params): Response
            => $recall->find($request, $session->principal($request)));
        $router->post('/api/v1/checks', static fn (Request $request, array $params): Response
            => $writingAids->checks($request, $session->principal($request)));
        $router->get('/api/v1/pages/{path}/prior', static fn (Request $request, array $params): Response
            => $writingAids->prior($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/prior', static fn (Request $request, array $params): Response
            => $writingAids->prior($request, $params['path'], $session->principal($request)));
        $router->get('/api/v1/pages/{path}/comments', static fn (Request $request, array $params): Response
            => $comments->list($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/comments', static fn (Request $request, array $params): Response
            => $comments->add($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/comments/{id:[0-9A-Z]+}', static fn (Request $request, array $params): Response
            => $comments->change($request, $params['path'], $params['id'], $session->principal($request)));
        $router->get('/api/v1/pages/{path}/similar', static fn (Request $request, array $params): Response
            => $similar->show($request, $params['path'], $session->principal($request)));
        $router->put('/api/v1/pages/{path}/autosave', static fn (Request $request, array $params): Response
            => $pagesApi->saveDraft($request, $params['path'], $session->principal($request)));
        $router->get('/api/v1/pages/{path}/autosave', static fn (Request $request, array $params): Response
            => $pagesApi->showDraft($request, $params['path'], $session->principal($request)));
        $router->delete('/api/v1/pages/{path}/autosave', static fn (Request $request, array $params): Response
            => $pagesApi->discardDraft($request, $params['path'], $session->principal($request)));
        $router->get('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->show($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages', static fn (Request $request, array $params): Response
            => $pagesApi->create($request, $session->principal($request)));
        $router->put('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->save($request, $params['path'], $session->principal($request)));
        $router->delete('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->delete($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/revert', static fn (Request $request, array $params): Response
            => $pagesApi->revert($request, $params['path'], $session->principal($request)));
        $router->patch('/api/v1/pages/{path}/meta', static fn (Request $request, array $params): Response
            => $pagesApi->meta($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/restore', static fn (Request $request, array $params): Response
            => $pagesApi->restore($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/duplicate', static fn (Request $request, array $params): Response
            => $pagesApi->duplicate($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/move', static fn (Request $request, array $params): Response
            => $pagesApi->move($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/sign', static fn (Request $request, array $params): Response
            => $pagesApi->sign($request, $params['path'], $session->principal($request)));
        // Must be registered before the /{path} catch-all: first match wins.
        $router->get('/admin/users', static fn (Request $request, array $params): Response
            => $adminUsers->index($request, $session->principal($request)));
        $router->post('/admin/users', static fn (Request $request, array $params): Response
            => $adminUsers->create($request, $session->principal($request)));
        $router->post('/admin/users/{username}/deactivate', static fn (Request $request, array $params): Response
            => $adminUsers->deactivate($request, $params['username'], $session->principal($request)));
        $router->post('/admin/users/{username}/profile', static fn (Request $request, array $params): Response
            => $adminUsers->profile($request, $params['username'], $session->principal($request)));
        $router->post('/admin/users/{username}/password', static fn (Request $request, array $params): Response
            => $adminUsers->setPassword($request, $params['username'], $session->principal($request)));
        $router->get('/admin/users/{username}', static fn (Request $request, array $params): Response
            => $adminUsers->show($request, $params['username'], $session->principal($request)));
        $router->post('/admin/users/{username}', static fn (Request $request, array $params): Response
            => $adminUsers->update($request, $params['username'], $session->principal($request)));
        $router->get('/admin/groups', static fn (Request $request, array $params): Response
            => $adminGroups->index($request, $session->principal($request)));
        $router->post('/admin/groups', static fn (Request $request, array $params): Response
            => $adminGroups->create($request, $session->principal($request)));
        $router->post('/admin/groups/{name}/delete', static fn (Request $request, array $params): Response
            => $adminGroups->delete($request, $params['name'], $session->principal($request)));
        $router->get('/admin/groups/{name}', static fn (Request $request, array $params): Response
            => $adminGroups->show($request, $params['name'], $session->principal($request)));
        $router->post('/admin/groups/{name}', static fn (Request $request, array $params): Response
            => $adminGroups->update($request, $params['name'], $session->principal($request)));
        $router->get('/admin/index', static fn (Request $request, array $params): Response
            => $adminIndex->show($request, $session->principal($request)));
        $router->post('/admin/index/rebuild', static fn (Request $request, array $params): Response
            => $adminIndex->rebuild($request, $session->principal($request)));
        $router->get('/admin/ai', static fn (Request $request, array $params): Response
            => $adminAi->show($request, $session->principal($request)));
        $router->post('/admin/ai/check', static fn (Request $request, array $params): Response
            => $adminAi->check($request, $session->principal($request)));
        $router->post('/admin/ai/use', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'use', $session->principal($request)));
        $router->post('/admin/ai/enabled', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'enabled', $session->principal($request)));
        $router->post('/admin/ai/server', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'server', $session->principal($request)));
        $router->post('/admin/ai/similar', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'similar', $session->principal($request)));
        $router->post('/admin/ai/servers', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'servers', $session->principal($request)));
        $router->post('/admin/ai/servers/{slot}/models', static fn (Request $request, array $params): Response
            => $adminAi->models($request, $params['slot'], $session->principal($request)));
        $router->post('/admin/ai/servers/{slot}/test', static fn (Request $request, array $params): Response
            => $adminAi->test($request, $params['slot'], $session->principal($request)));
        $router->get('/admin/settings', static fn (Request $request, array $params): Response
            => $adminSettings->show($request, $session->principal($request)));
        $router->post('/admin/settings/icon', static fn (Request $request, array $params): Response
            => $adminSettings->uploadIcon($request, $session->principal($request)));
        $router->post('/admin/settings/{section}', static fn (Request $request, array $params): Response
            => $adminSettings->save($request, $params['section'], $session->principal($request)));
        $router->get('/admin/sites', static fn (Request $request, array $params): Response
            => $adminSites->show($request, $session->principal($request)));
        $router->post('/admin/sites', static fn (Request $request, array $params): Response
            => $adminSites->add($request, $session->principal($request)));
        $router->post('/admin/sites/{code}', static fn (Request $request, array $params): Response
            => $adminSites->save($request, $params['code'], $session->principal($request)));
        $router->post('/admin/sites/{code}/remove', static fn (Request $request, array $params): Response
            => $adminSites->remove($request, $params['code'], $session->principal($request)));
        $router->get('/site-icon/{file}', static fn (Request $request, array $params): Response
            => $adminSettings->icon($request, true));
        $router->get('/favicon.ico', static fn (Request $request, array $params): Response
            => $adminSettings->icon($request, false));
        $router->get('/admin/maintenance', static fn (Request $request, array $params): Response
            => $adminMaintenance->show($request, $session->principal($request)));
        $router->get('/admin/maintenance/runs/{id}.json', static fn (Request $request, array $params): Response
            => $adminMaintenance->json($request, $params['id'], $session->principal($request)));
        $router->get('/admin/maintenance/backups/{file}', static fn (Request $request, array $params): Response
            => $adminMaintenance->download($request, $params['file'], $session->principal($request)));
        $router->post('/admin/maintenance/{task}', static fn (Request $request, array $params): Response
            => $adminMaintenance->run($request, $params['task'], $session->principal($request)));
        $router->get('/admin/plugins', static fn (Request $request, array $params): Response
            => $adminPlugins->show($request, $session->principal($request)));
        $router->post('/admin/plugins/{id}/toggle', static fn (Request $request, array $params): Response
            => $adminPlugins->toggle($request, $params['id'], $session->principal($request)));
        $router->post('/admin/plugins/{id}/settings', static fn (Request $request, array $params): Response
            => $adminPlugins->save($request, $params['id'], $session->principal($request)));
        $router->get('/admin/tags', static fn (Request $request, array $params): Response
            => $adminTags->show($request, $session->principal($request)));
        $router->post('/admin/tags/rename', static fn (Request $request, array $params): Response
            => $adminTags->rename($request, $session->principal($request)));
        $router->post('/admin/tags/merge', static fn (Request $request, array $params): Response
            => $adminTags->merge($request, $session->principal($request)));
        $router->post('/admin/tags/review/{id}', static fn (Request $request, array $params): Response
            => $adminTags->review($request, $params['id'], $session->principal($request)));
        $router->post('/admin/tags/dictionary', static fn (Request $request, array $params): Response
            => $adminTags->saveEntry($request, $session->principal($request)));
        $router->get('/admin/trash', static fn (Request $request, array $params): Response
            => $adminTrash->show($request, $session->principal($request)));
        $router->post('/admin/trash/{pid}/restore', static fn (Request $request, array $params): Response
            => $adminTrash->restore($request, $params['pid'], $session->principal($request)));
        $router->get('/profile', static fn (Request $request, array $params): Response
            => $profile->show($request, $session->principal($request)));
        $router->post('/profile/password', static fn (Request $request, array $params): Response
            => $profile->changePassword($request, $session->principal($request)));
        $router->post('/profile/pins', static fn (Request $request, array $params): Response
            => $profile->togglePin($request, $session->principal($request)));
        $router->post('/profile/views', static fn (Request $request, array $params): Response
            => $profile->saveView($request, $session->principal($request)));
        $router->post('/profile/signature', static fn (Request $request, array $params): Response
            => $profile->saveSignature($request, $session->principal($request)));
        $router->post('/profile/tokens', static fn (Request $request, array $params): Response
            => $profile->createToken($request, $session->principal($request)));
        $router->post('/profile/tokens/{id}/revoke', static fn (Request $request, array $params): Response
            => $profile->revokeToken($request, $params['id'], $session->principal($request)));
        $router->post('/admin/users/{username}/reactivate', static fn (Request $request, array $params): Response
            => $adminUsers->reactivate($request, $params['username'], $session->principal($request)));
        // Must be registered before the /{path} catch-all: first match wins.
        $router->get('/new', static fn (Request $request, array $params): Response
            => $newPage->form($request, $session->principal($request)));
        $router->post('/new', static fn (Request $request, array $params): Response
            => $newPage->create($request, $session->principal($request)));
        $router->post('/join', static fn (Request $request, array $params): Response
            => $join->post($request, $session->principal($request)));
        // Plugin routes, each under its own /x/{plugin-id}: before the /{path} catch-alls
        foreach ($hooks->routes() as $route) {
            $handler = $route['handler'];
            $method = strtolower($route['method']);
            $router->{$method}($route['pattern'], static fn (Request $request, array $params): Response
                => $handler($request, $params, $session->principal($request)));
        }
        // Must be registered before the /{path} catch-all: first match
        // wins, and /{path}'s [^/]+ segment would otherwise swallow the
        // trailing ":" itself (verified: the router backtracks the greedy
        // segment by exactly one character to satisfy a literal suffix).
        $router->get('/{ns}:', static fn (Request $request, array $params): Response
            => $namespace->index($request, $params['ns'], $session->principal($request)));
        $router->post('/{ns}:', static fn (Request $request, array $params): Response
            => $namespace->bulk($request, $params['ns'], $session->principal($request)));
        // Root namespace index: {ns} in the route above requires 1+ chars
        // ([^/]+), so "/:" (ns === '') needs its own literal route; must
        // stay registered before the /{path} catch-all, which would
        // otherwise treat ":" as a one-segment page path.
        $router->get('/:', static fn (Request $request, array $params): Response
            => $namespace->index($request, '', $session->principal($request)));
        $router->post('/:', static fn (Request $request, array $params): Response
            => $namespace->bulk($request, '', $session->principal($request)));
        $router->get('/feed.atom', static fn (Request $request, array $params): Response => $feeds->all($request));
        $router->get('/feed/{ns}.atom', static fn (Request $request, array $params): Response => $feeds->one($request, $params['ns']));
        $router->post('/export/bundle.zip', static fn (Request $request, array $params): Response
            => $export->bundle($request, $session->principal($request)));
        $router->get('/export/{path}.pdf', static fn (Request $request, array $params): Response
            => $export->pdf($request, $params['path'], $session->principal($request)));
        $router->get('/export/{path}.odt', static fn (Request $request, array $params): Response
            => $export->odt($request, $params['path'], $session->principal($request)));
        $router->get('/export/{path}.md', static fn (Request $request, array $params): Response
            => $export->md($request, $params['path'], $session->principal($request)));
        $router->get('/export/{path}.html', static fn (Request $request, array $params): Response
            => $export->html($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/print', static fn (Request $request, array $params): Response
            => $export->print($request, $params['path'], $session->principal($request)));
        $router->get('/r/{pid}/{rev}', static fn (Request $request, array $params): Response
            => $pages->permalink($request, $params['pid'], $params['rev'], $session->principal($request)));
        $router->get('/{path}/revisions', static fn (Request $request, array $params): Response
            => $revisions->revisions($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/revisions/revert', static fn (Request $request, array $params): Response
            => $revisions->revert($request, $params['path'], $session->principal($request)));
        // Report vs prior (phase 17a): medical; two revisions of one page are /{path}/revisions
        $router->get('/{path}/compare', static fn (Request $request, array $params): Response
            => $compare->compare($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/new', static fn (Request $request, array $params): Response
            => $newPage->form($request, $session->principal($request), $params['path']));
        $router->get('/{path}/edit', static fn (Request $request, array $params): Response
            => $editor->edit($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/edit', static fn (Request $request, array $params): Response
            => $editor->save($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/visibility', static fn (Request $request, array $params): Response
            => $visibility->form($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/visibility', static fn (Request $request, array $params): Response
            => $visibility->change($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/sign', static fn (Request $request, array $params): Response
            => $signPage->form($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/sign', static fn (Request $request, array $params): Response
            => $signPage->sign($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/move', static fn (Request $request, array $params): Response
            => $pages->moveForm($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/move', static fn (Request $request, array $params): Response
            => $pages->move($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/rename', static fn (Request $request, array $params): Response
            => $pages->moveForm($request, $params['path'], $session->principal($request), rename: true));
        $router->post('/{path}/rename', static fn (Request $request, array $params): Response
            => $pages->move($request, $params['path'], $session->principal($request), rename: true));
        $router->get('/{path}/delete', static fn (Request $request, array $params): Response
            => $pages->confirmDelete($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/delete', static fn (Request $request, array $params): Response
            => $pages->delete($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/timeline', static fn (Request $request, array $params): Response
            => $timeline->timeline($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/patient-merge', static fn (Request $request, array $params): Response
            => $patientMerge->confirm($request, $params['path'], $session->principal($request)));
        $router->get('/{path}', static fn (Request $request, array $params): Response
            => $pages->view($request, $params['path'], $session->principal($request)));

        return new self($router, new ErrorMapper($index, $session), Feeds::clean((array) ($config['feeds']['namespaces'] ?? [])), static fn (): bool => $ask->available());
    }

    public function handle(Request $request): Response
    {
        $request = $request->withFeedNamespaces($this->feedNamespaces)->withAskable($this->askable);
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return $this->errors->render($e, $request);
        }
    }
}
