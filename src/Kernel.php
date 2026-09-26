<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Audit\AuditLog;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\AdminIndexController;
use Reporion\Controller\AdminMaintenanceController;
use Reporion\Controller\AdminSettingsController;
use Reporion\Controller\AdminTagsController;
use Reporion\Controller\AdminTrashController;
use Reporion\Controller\AdminUsersController;
use Reporion\Controller\AuthController;
use Reporion\Controller\CompareController;
use Reporion\Controller\EditorController;
use Reporion\Controller\ExportController;
use Reporion\Controller\FeedController;
use Reporion\Controller\HistoryController;
use Reporion\Controller\HomeController;
use Reporion\Controller\MediaController;
use Reporion\Controller\NamespaceController;
use Reporion\Controller\NewPageController;
use Reporion\Controller\PageController;
use Reporion\Controller\PagesApiController;
use Reporion\Controller\ProfileController;
use Reporion\Controller\RenderController;
use Reporion\Controller\SearchController;
use Reporion\Controller\ThemeController;
use Reporion\Controller\TimelineController;
use Reporion\Controller\VisibilityController;
use Reporion\Http\ErrorMapper;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Router;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\PageMoves;
use Reporion\Service\Accessions;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\NewReport;
use Reporion\Service\OdtExport;
use Reporion\Service\PdfExport;
use Reporion\Service\Publishing;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Service\Tags;
use Reporion\Service\Revisions;
use Reporion\Storage\FlatFile;
use Reporion\Support\AccessionFormat;
use Throwable;

/**
 * Boot, container, dispatch. No framework (docs/architecture-api.md §2):
 * this wires concrete services once and hands a Router the closures that
 * call them — nothing here is a plugin hook yet, because no plugin needs
 * one (see docs/BUILD_LOG.md).
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
        ], static fn (string $value): bool => $value !== ''));

        return $config;
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

        $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations');
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
        $audit = new AuditLog((string) ($config['paths']['audit'] ?? $config['paths']['data'] . '/audit'));
        $render = new Render();
        $session = new Session(
            (string) $config['auth']['session_secret'],
            (string) $config['auth']['session_name'],
            (int) $config['auth']['session_lifetime'],
            $users,
        );

        $trashPurgeDays = (int) $config['pages']['trash_purge_days'];
        $templates = new PageTemplateRenderer($render, $index);
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
        $home = new HomeController(
            $storage,
            $index,
            $templates,
            (string) $config['site']['home_page'],
            // Dashboard filter chips: one per modality schema (conf/schema/*.json)
            array_values(array_map(
                static fn (string $file): string => strtoupper(basename($file, '.json')),
                array_filter(glob($rootDir . '/conf/schema/*.json') ?: [], static fn (string $file): bool => basename($file) !== 'base.json')
            )),
        );
        $search = new SearchController($index);
        $adminSettings = new AdminSettingsController(new InstanceSettings((string) $config['paths']['data']), $config, $index, $audit);
        $adminMaintenance = new AdminMaintenanceController(
            MaintenanceRunner::standard($storage, $index, $audit, (string) $config['paths']['data'], $trashPurgeDays),
            $index,
        );
        $adminTags = new AdminTagsController($index, new Tags($storage, $index, $audit));
        $media = new MediaController($storage, $index, $audit, (int) ($config['media']['max_bytes'] ?? 8 * 1024 * 1024));
        $auth = new AuthController($users, $session, $audit);
        $theme = new ThemeController();
        $pagesApi = new PagesApiController($storage, $schemas, $audit, $moves, $index, $render, $publishing);
        $adminUsers = new AdminUsersController($users, $index, $audit);
        $history = new HistoryController($storage, $index, $audit);
        $compare = new CompareController($storage, $index, $render);
        $timeline = new TimelineController($storage, $index);
        $editor = new EditorController($storage, $index, $audit);
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
        $profile = new ProfileController($users, $index, $audit);
        $adminTrash = new AdminTrashController($storage, $index, $audit, $trashPurgeDays);
        $adminIndex = new AdminIndexController(
            new IndexMaintenance($storage, $index, (string) $config['paths']['data'], $audit->directory()),
            $index,
            $audit,
        );
        $newPage = new NewPageController($storage, $index, $audit, new NewReport(
            $storage,
            $index,
            new Accessions(
                (string) $config['paths']['data'],
                $index,
                (string) ($config['accession']['pattern'] ?? AccessionFormat::DEFAULT_PATTERN),
                (int) ($config['accession']['seq_pad'] ?? AccessionFormat::DEFAULT_PAD),
            ),
            $schemas,
            self::schemaModalities($rootDir),
            \is_array($config['sites'] ?? null) ? $config['sites'] : [],
            \is_array($config['reports']['modality_namespaces'] ?? null) ? $config['reports']['modality_namespaces'] : [],
        ));
        $namespace = new NamespaceController($index, $storage, $render);

        $router = new Router();
        $router->get('/', static fn (Request $request, array $params): Response
            => $home->home($request, $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
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
        $router->get('/admin/settings', static fn (Request $request, array $params): Response
            => $adminSettings->show($request, $session->principal($request)));
        $router->post('/admin/settings/icon', static fn (Request $request, array $params): Response
            => $adminSettings->uploadIcon($request, $session->principal($request)));
        $router->post('/admin/settings/{section}', static fn (Request $request, array $params): Response
            => $adminSettings->save($request, $params['section'], $session->principal($request)));
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
        $router->get('/admin/tags', static fn (Request $request, array $params): Response
            => $adminTags->show($request, $session->principal($request)));
        $router->post('/admin/tags/rename', static fn (Request $request, array $params): Response
            => $adminTags->rename($request, $session->principal($request)));
        $router->post('/admin/tags/merge', static fn (Request $request, array $params): Response
            => $adminTags->merge($request, $session->principal($request)));
        $router->get('/admin/trash', static fn (Request $request, array $params): Response
            => $adminTrash->show($request, $session->principal($request)));
        $router->post('/admin/trash/{pid}/restore', static fn (Request $request, array $params): Response
            => $adminTrash->restore($request, $params['pid'], $session->principal($request)));
        $router->get('/profile', static fn (Request $request, array $params): Response
            => $profile->show($request, $session->principal($request)));
        $router->post('/profile/password', static fn (Request $request, array $params): Response
            => $profile->changePassword($request, $session->principal($request)));
        $router->post('/admin/users/{username}/reactivate', static fn (Request $request, array $params): Response
            => $adminUsers->reactivate($request, $params['username'], $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
        $router->get('/new', static fn (Request $request, array $params): Response
            => $newPage->form($request, $session->principal($request)));
        $router->post('/new', static fn (Request $request, array $params): Response
            => $newPage->create($request, $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match
        // wins, and /{path}'s [^/]+ segment would otherwise swallow the
        // trailing ":" itself (verified: the router backtracks the greedy
        // segment by exactly one character to satisfy a literal suffix).
        $router->get('/{ns}:', static fn (Request $request, array $params): Response
            => $namespace->index($request, $params['ns'], $session->principal($request)));
        // Root namespace index — {ns} in the route above requires 1+ chars
        // ([^/]+), so "/:" (ns === '') needs its own literal route; must
        // stay registered before the /{path} catch-all, which would
        // otherwise treat ":" as a one-segment page path.
        $router->get('/:', static fn (Request $request, array $params): Response
            => $namespace->index($request, '', $session->principal($request)));
        $router->get('/feed.atom', static fn (Request $request, array $params): Response => $feeds->all($request));
        $router->get('/feed/{ns}.atom', static fn (Request $request, array $params): Response => $feeds->one($request, $params['ns']));
        $router->get('/export/{path}.pdf', static fn (Request $request, array $params): Response
            => $export->pdf($request, $params['path'], $session->principal($request)));
        $router->get('/export/{path}.odt', static fn (Request $request, array $params): Response
            => $export->odt($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/print', static fn (Request $request, array $params): Response
            => $export->print($request, $params['path'], $session->principal($request)));
        $router->get('/r/{pid}/{rev}', static fn (Request $request, array $params): Response
            => $pages->permalink($request, $params['pid'], $params['rev'], $session->principal($request)));
        $router->get('/{path}/history', static fn (Request $request, array $params): Response
            => $history->history($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/history/revert', static fn (Request $request, array $params): Response
            => $history->revert($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/edit', static fn (Request $request, array $params): Response
            => $editor->edit($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/edit', static fn (Request $request, array $params): Response
            => $editor->save($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/visibility', static fn (Request $request, array $params): Response
            => $visibility->form($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/visibility', static fn (Request $request, array $params): Response
            => $visibility->change($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/move', static fn (Request $request, array $params): Response
            => $pages->moveForm($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/move', static fn (Request $request, array $params): Response
            => $pages->move($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/delete', static fn (Request $request, array $params): Response
            => $pages->confirmDelete($request, $params['path'], $session->principal($request)));
        $router->post('/{path}/delete', static fn (Request $request, array $params): Response
            => $pages->delete($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/compare', static fn (Request $request, array $params): Response
            => $compare->compare($request, $params['path'], $session->principal($request)));
        $router->get('/{path}/timeline', static fn (Request $request, array $params): Response
            => $timeline->timeline($request, $params['path'], $session->principal($request)));
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
