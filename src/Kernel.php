<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Controller\AdminUsersController;
use Reporion\Controller\AuthController;
use Reporion\Controller\HomeController;
use Reporion\Controller\PageController;
use Reporion\Controller\PagesApiController;
use Reporion\Controller\RenderController;
use Reporion\Controller\SearchController;
use Reporion\Http\ErrorMapper;
use Reporion\Http\PageTemplateRenderer;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Router;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Service\Render;
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
        $render = new Render();
        $session = new Session(
            (string) $config['auth']['session_secret'],
            (string) $config['auth']['session_name'],
            (int) $config['auth']['session_lifetime'],
            $users,
        );

        $templates = new PageTemplateRenderer($render);
        $pages = new PageController($storage, $index, $templates);
        $renderController = new RenderController($render);
        $home = new HomeController($storage, $index, $templates, (string) $config['site']['home_page']);
        $search = new SearchController($index);
        $auth = new AuthController($users, $session);
        $pagesApi = new PagesApiController($storage);
        $adminUsers = new AdminUsersController($users);

        $router = new Router();
        $router->get('/', static fn (Request $request, array $params): Response
            => $home->home($request, $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
        $router->get('/search', static fn (Request $request, array $params): Response
            => $search->search($request, $session->principal($request)));
        $router->get('/login', static fn (Request $request, array $params): Response => $auth->form($request));
        $router->post('/login', static fn (Request $request, array $params): Response => $auth->login($request));
        $router->post('/logout', static fn (Request $request, array $params): Response => $auth->logout($request));
        // JSON API, versioned under /api/v1 (docs/architecture-api.md §3) —
        // distinct from the bare SSR routes above, even where names overlap
        // (e.g. GET /search vs GET /api/v1/search).
        $router->post('/api/v1/render', static fn (Request $request, array $params): Response
            => $renderController->render($request, $session->principal($request)));
        $router->post('/api/v1/pages', static fn (Request $request, array $params): Response
            => $pagesApi->create($request, $session->principal($request)));
        $router->put('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->save($request, $params['path'], $session->principal($request)));
        $router->delete('/api/v1/pages/{path}', static fn (Request $request, array $params): Response
            => $pagesApi->delete($request, $params['path'], $session->principal($request)));
        // Must be registered before the /{path} catch-all — first match wins.
        $router->get('/admin/users', static fn (Request $request, array $params): Response
            => $adminUsers->index($request, $session->principal($request)));
        $router->post('/admin/users', static fn (Request $request, array $params): Response
            => $adminUsers->create($request, $session->principal($request)));
        $router->post('/admin/users/{username}/deactivate', static fn (Request $request, array $params): Response
            => $adminUsers->deactivate($request, $params['username'], $session->principal($request)));
        $router->post('/admin/users/{username}/reactivate', static fn (Request $request, array $params): Response
            => $adminUsers->reactivate($request, $params['username'], $session->principal($request)));
        $router->get('/{path}', static fn (Request $request, array $params): Response
            => $pages->view($request, $params['path'], $session->principal($request)));

        return new self($router);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return ErrorMapper::map($e);
        }
    }
}
