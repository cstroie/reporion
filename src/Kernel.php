<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Audit\AuditLog;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\AdminUsersController;
use Reporion\Controller\AuthController;
use Reporion\Controller\CompareController;
use Reporion\Controller\EditorController;
use Reporion\Controller\ExportController;
use Reporion\Controller\HistoryController;
use Reporion\Controller\HomeController;
use Reporion\Controller\NamespaceController;
use Reporion\Controller\NewPageController;
use Reporion\Controller\PageController;
use Reporion\Controller\PagesApiController;
use Reporion\Controller\RenderController;
use Reporion\Controller\SearchController;
use Reporion\Controller\ThemeController;
use Reporion\Controller\TimelineController;
use Reporion\Http\ErrorMapper;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Router;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\PdfExport;
use Reporion\Service\PrintView;
use Reporion\Service\Render;
use Reporion\Service\Revisions;
use Reporion\Storage\FlatFile;
use Throwable;

/**
 * Boot, container, dispatch. No framework (docs/architecture-api.md §2):
 * this wires concrete services once and hands a Router the closures that
 * call them — nothing here is a plugin hook yet, because no plugin needs
 * one (see docs/BUILD_LOG.md).
 */
final class Kernel
{
    private function __construct(
        private readonly Router $router,
        private readonly ErrorMapper $errors,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function boot(array $config): self
    {
        $rootDir = \dirname(__DIR__);

        $index = new Sqlite((string) $config['paths']['index'], $rootDir . '/migrations');
        $storage = new FlatFile((string) $config['paths']['data'], $index);
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
        $pages = new PageController($storage, $index, $templates, $trashPurgeDays, new Revisions($storage, $schemas), $audit);
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
        $auth = new AuthController($users, $session, $audit);
        $theme = new ThemeController();
        $pagesApi = new PagesApiController($storage, $schemas, $audit);
        $adminUsers = new AdminUsersController($users, $index);
        $history = new HistoryController($storage, $index, $audit);
        $compare = new CompareController($storage, $index, $render);
        $timeline = new TimelineController($storage, $index);
        $editor = new EditorController($storage, $index, $audit);
        $export = new ExportController(
            $storage,
            $index,
            new PrintView(
                $render,
                $users,
                (array) ($config['sites'] ?? []),
                rtrim((string) ($config['site']['base_url'] ?? ''), '/'),
                $rootDir . '/assets/css/print.css',
            ),
            new PdfExport($rootDir . '/assets'),
            $audit,
            (array) ($config['export'] ?? []),
        );
        $newPage = new NewPageController($storage, $index, $audit);
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
        $router->post('/api/v1/pages', static fn (Request $request, array $params): Response
            => $pagesApi->create($request, $session->principal($request)));
        $router->put('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->save($request, $params['path'], $session->principal($request)));
        $router->delete('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->delete($request, $params['path'], $session->principal($request)));
        $router->post('/api/v1/pages/{path}/revert', static fn (Request $request, array $params): Response
            => $pagesApi->revert($request, $params['path'], $session->principal($request)));
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
        $router->get('/export/{path}.pdf', static fn (Request $request, array $params): Response
            => $export->pdf($request, $params['path'], $session->principal($request)));
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
