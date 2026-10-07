<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Audit\AuditLog;
use Reporion\Auth\ApiTokens;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\AiController;
use Reporion\Controller\AdminIndexController;
use Reporion\Controller\AdminAiController;
use Reporion\Controller\AdminMaintenanceController;
use Reporion\Controller\AdminPluginsController;
use Reporion\Controller\AdminSettingsController;
use Reporion\Controller\AdminSitesController;
use Reporion\Controller\AdminTagsController;
use Reporion\Controller\AdminTrashController;
use Reporion\Controller\AdminUsersController;
use Reporion\Controller\AuthController;
use Reporion\Controller\EditorController;
use Reporion\Controller\ExportController;
use Reporion\Controller\FeedController;
use Reporion\Controller\RevisionsController;
use Reporion\Controller\HomeController;
use Reporion\Controller\MediaController;
use Reporion\Controller\JoinController;
use Reporion\Controller\NamespaceController;
use Reporion\Controller\NewPageController;
use Reporion\Controller\PageController;
use Reporion\Controller\SignController;
use Reporion\Controller\StatsController;
use Reporion\Controller\PagesApiController;
use Reporion\Controller\ProfileController;
use Reporion\Controller\RenderController;
use Reporion\Controller\SearchController;
use Reporion\Controller\ThemeController;
use Reporion\Controller\PatientMergeController;
use Reporion\Controller\TimelineController;
use Reporion\Controller\VisibilityController;
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
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Joins;
use Reporion\Service\PageMoves;
use Reporion\Service\Ai\Actions as AiActions;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\Assistant;
use Reporion\Service\Ai\Check as AiCheck;
use Reporion\Service\Ai\Context as AiContext;
use Reporion\Service\Ai\EgressGuard;
use Reporion\Service\Ai\FtsExamples;
use Reporion\Service\Ai\OpenAiCompatibleProvider;
use Reporion\Service\Accessions;
use Reporion\Service\ExamAccessions;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\NewReport;
use Reporion\Service\PatientMerge;
use Reporion\Service\PatientStudies;
use Reporion\Service\OdtExport;
use Reporion\Service\PdfExport;
use Reporion\Service\Publishing;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Service\TagDictionary;
use Reporion\Service\Tags;
use Reporion\Service\Revisions;
use Reporion\Service\Signing;
use Reporion\Service\Checklists;
use Reporion\Service\References;
use Reporion\Service\FrontmatterFields;
use Reporion\Service\Snippets;
use Reporion\Service\Stats;
use Reporion\Storage\FlatFile;
use Reporion\Storage\StorageInterface;
use Reporion\Support\AccessionFormat;
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
    ) {
    }

    /**
     * $config with data/settings.yaml (Admin → Settings) laid over it, and
     * the instance's timezone, site name, tagline and icon put in place —
     * for the front controller and bin/reporion alike.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function withInstanceSettings(array $config): array
    {
        $config = (new InstanceSettings((string) $config['paths']['data']))->applyTo($config);
        $timezone = (string) ($config['site']['timezone'] ?? '');
        if ($timezone !== '' && \in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
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
     * The guided new-report service — for the front controller, the plugins
     * and bin/reporion alike.
     *
     * @param array<string, mixed> $config
     */
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
     * services they may ask for — never the filesystem or the PDO handle (D9).
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
            ],
            \is_array($config['plugins']['settings'] ?? null) ? $config['plugins']['settings'] : [],
            $hooks,
        );

        return [$loader, $registry];
    }

    /**
     * The modalities that have a schema (conf/schema/{mod}.json), in
     * upper case: MR, CT, US, XR, MG.
     *
     * @return list<string>
     */
    private static function schemaModalities(string $rootDir): array
    {
        $codes = [];
        foreach (glob($rootDir . '/conf/schema/*.json') ?: [] as $file) {
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
        $tagDictionary = new TagDictionary((string) $config['paths']['data'], $rootDir . '/conf/synonyms.txt');
        $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations', $tagDictionary->searchGroups(...));
        $storage = new FlatFile((string) $config['paths']['data'], $index);
        // Crash recovery (invariant 7): finish writes a crash left half-done.
        // Never fails the request; the next one simply tries again. Logged
        // by class only — an exception message may carry a page path (invariant 8).
        try {
            $storage->replayCrashedWrites(self::REPLAY_MIN_AGE_SECONDS);
        } catch (Throwable $e) {
            error_log('reporion: journal replay failed: ' . $e::class);
        }
        $users = new FlatFileUserStore((string) $config['paths']['data']);
        // Every byline (namespace table, revisions, the page header) reads
        // this instead of a raw username (TODO 13, display_name() in lang.php)
        $directory = [];
        foreach ($users->all() as $account) {
            $directory[$account->username] = $account->signatureName();
        }
        reporion_directory($directory);
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
        $references = new References($storage, $index, $render);
        // The AI assistant (phase 15): off until configured (D15)
        $aiConfig = AiConfig::fromConfig($config);
        $aiActions = new AiActions($aiConfig, $storage, $index);
        $templates = new PageTemplateRenderer($render, $index, $references, $aiActions);
        $schemas = new Loader($rootDir . '/conf/schema');
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
        // Dashboard filter chips and /stats: one per modality schema (conf/schema/*.json)
        $modalities = array_values(array_map(
            static fn (string $file): string => strtoupper(basename($file, '.json')),
            array_filter(glob($rootDir . '/conf/schema/*.json') ?: [], static fn (string $file): bool => basename($file) !== 'base.json')
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
        $search = new SearchController($index);
        $adminSettings = new AdminSettingsController(new InstanceSettings((string) $config['paths']['data']), $config, $index, $audit);
        $adminSites = new AdminSitesController(new InstanceSettings((string) $config['paths']['data']), $config, $index, $audit);
        $adminMaintenance = new AdminMaintenanceController(
            MaintenanceRunner::standard($storage, $index, $audit, (string) $config['paths']['data'], $trashPurgeDays),
            $index,
        );
        $tags = new Tags($storage, $index, $audit);
        $adminTags = new AdminTagsController($index, $tags, $tagDictionary, $audit);
        $media = new MediaController($storage, $index, $audit, (int) ($config['media']['max_bytes'] ?? 8 * 1024 * 1024));
        $auth = new AuthController($users, $session, $audit);
        $theme = new ThemeController();
        $signing = new Signing($storage, $schemas, $audit);
        $signPage = new SignController($storage, $index, $signing);
        $accessions = new Accessions(
            (string) $config['paths']['data'],
            $index,
            (string) ($config['accession']['pattern'] ?? AccessionFormat::DEFAULT_PATTERN),
            (int) ($config['accession']['seq_pad'] ?? AccessionFormat::DEFAULT_PAD),
        );
        $examAccessions = new ExamAccessions($accessions, \is_array($config['sites'] ?? null) ? $config['sites'] : []);
        $pagesApi = new PagesApiController($storage, $signing, $audit, $moves, $index, $render, $publishing, $examAccessions);
        $ai = new AiController(
            $aiConfig,
            $aiActions,
            new Assistant(new AiContext($storage, $index, new FtsExamples($index, $storage)), static function (?string $server) use ($config, $aiConfig): ?OpenAiCompatibleProvider {
                // An action may name another server (`Model` cell, `{server}:{alias}`); each keeps its own egress rule
                $slot = $server === null ? null : AiConfig::slotByName($config, $server);
                if ($server !== null && $slot === null) {
                    return null;
                }
                $own = $slot === null ? $aiConfig : AiConfig::fromConfig($config, $slot);

                return $slot !== null && ($own->endpoint === '' || $own->model === '') ? null : new OpenAiCompatibleProvider($own, new EgressGuard());
            }, $audit, (string) $config['paths']['data'] . '/ai'),
            $storage,
            $index,
        );
        $adminAi = new AdminAiController(new InstanceSettings((string) $config['paths']['data']), $config, $aiActions, new AiCheck(new EgressGuard()), $index, $audit);
        $adminUsers = new AdminUsersController($users, $index, $audit);
        $revisions = new RevisionsController($storage, $index, $audit, $render);
        $patientStudies = new PatientStudies($index);
        $timeline = new TimelineController($storage, $index, $patientStudies);
        $patientMerge = new PatientMergeController($index, new PatientMerge($storage, $audit));
        $frontmatterFields = new FrontmatterFields(
            $schemas,
            $index,
            \is_array($config['sites'] ?? null) ? $config['sites'] : [],
            // Phase 25: where a template's References picker looks; none set means radiology
            array_values(array_filter((array) ($config['references']['namespaces'] ?? []), 'is_string')) ?: ['radiology'],
        );
        $editor = new EditorController($storage, $index, $audit, $patientStudies, new Snippets($index, $storage), $examAccessions, $frontmatterFields, $aiActions, $aiConfig, new Checklists($storage, $index), $references);
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
        $profile = new ProfileController($users, $index, $audit, $apiTokens);
        $adminTrash = new AdminTrashController($storage, $index, $audit, $trashPurgeDays);
        $adminIndex = new AdminIndexController(
            new IndexMaintenance($storage, $index, (string) $config['paths']['data'], $audit->directory()),
            $index,
            $audit,
        );
        $newReport = self::newReport($config, $rootDir, $index, $storage, $accessions, $schemas);
        $join = new JoinController(new Joins($storage, $index, $moves, $audit, $newReport->modalityNamespaces()), $index);
        $hooks = new Hooks();
        [$pluginLoader, $plugins] = self::loadPlugins($config, $rootDir, $storage, $index, $audit, $newReport, $render, $hooks);
        reporion_plugin_strings($pluginLoader->strings($plugins->loaded));
        reporion_plugin_ui($plugins->ui());
        $adminPlugins = new AdminPluginsController(new InstanceSettings((string) $config['paths']['data']), $config, $plugins, $index, $audit);
        $newPage = new NewPageController($storage, $index, $audit, $newReport, $hooks);
        $namespace = new NamespaceController($index, $storage, $render, $moves, $tags, $audit);

        $router = new Router();
        $router->get('/', static fn (Request $request, array $params): Response
            => $home->home($request, $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
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
        // JSON API, versioned under /api/v1 (docs/architecture-api.md §3) —
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
        // Must be registered before the /{path} catch-all — first match wins.
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
        $router->post('/admin/ai/servers', static fn (Request $request, array $params): Response
            => $adminAi->save($request, 'servers', $session->principal($request)));
        $router->get('/admin/settings', static fn (Request $request, array $params): Response
            => $adminSettings->show($request, $session->principal($request)));
        $router->post('/admin/settings/icon', static fn (Request $request, array $params): Response
            => $adminSettings->uploadIcon($request, $session->principal($request)));
        $router->post('/admin/settings/{section}', static fn (Request $request, array $params): Response
            => $adminSettings->save($request, $params['section'], $session->principal($request)));
        $router->get('/admin/sites', static fn (Request $request, array $params): Response
            => $adminSites->show($request, $session->principal($request)));
        $router->post('/admin/sites', static fn (Request $request, array $params): Response
            => $adminSites->save($request, $session->principal($request)));
        $router->get('/site-icon/{file}', static fn (Request $request, array $params): Response
            => $adminSettings->icon($request, true));
        $router->get('/favicon.ico', static fn (Request $request, array $params): Response
            => $adminSettings->icon($request, false));
        $router->get('/admin/maintenance', static fn (Request $request, array $params): Response
            => $adminMaintenance->show($request, $session->principal($request)));
        $router->get('/admin/maintenance/runs/{id}.json', static fn (Request $request, array $params): Response
            => $adminMaintenance->json($request, $params['id'], $session->principal($request)));
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
        $router->post('/profile/signature', static fn (Request $request, array $params): Response
            => $profile->saveSignature($request, $session->principal($request)));
        $router->post('/profile/tokens', static fn (Request $request, array $params): Response
            => $profile->createToken($request, $session->principal($request)));
        $router->post('/profile/tokens/{id}/revoke', static fn (Request $request, array $params): Response
            => $profile->revokeToken($request, $params['id'], $session->principal($request)));
        $router->post('/admin/users/{username}/reactivate', static fn (Request $request, array $params): Response
            => $adminUsers->reactivate($request, $params['username'], $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
        $router->get('/new', static fn (Request $request, array $params): Response
            => $newPage->form($request, $session->principal($request)));
        $router->post('/new', static fn (Request $request, array $params): Response
            => $newPage->create($request, $session->principal($request)));
        $router->post('/join', static fn (Request $request, array $params): Response
            => $join->post($request, $session->principal($request)));
        // Plugin routes, each under its own /x/{plugin-id} — before the /{path} catch-alls
        foreach ($hooks->routes() as $route) {
            $handler = $route['handler'];
            $method = strtolower($route['method']);
            $router->{$method}($route['pattern'], static fn (Request $request, array $params): Response
                => $handler($request, $params, $session->principal($request)));
        }
        // Must be registered before the /{path} catch-all — first match
        // wins, and /{path}'s [^/]+ segment would otherwise swallow the
        // trailing ":" itself (verified: the router backtracks the greedy
        // segment by exactly one character to satisfy a literal suffix).
        $router->get('/{ns}:', static fn (Request $request, array $params): Response
            => $namespace->index($request, $params['ns'], $session->principal($request)));
        $router->post('/{ns}:', static fn (Request $request, array $params): Response
            => $namespace->bulk($request, $params['ns'], $session->principal($request)));
        // Root namespace index — {ns} in the route above requires 1+ chars
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
        // /{path}/compare (2026-09-30): folded into /{path}/revisions — a permanent redirect,
        // not a 404, for any bookmark or link still pointing at the old route.
        $router->get('/{path}/compare', static fn (Request $request, array $params): Response
            => Response::redirect($request->basePath . '/' . $params['path'] . '/revisions' . ($request->query === [] ? '' : '?' . http_build_query($request->query)), 301));
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

        return new self($router, new ErrorMapper($index, $session));
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return $this->errors->render($e, $request);
        }
    }
}
