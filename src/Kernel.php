<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion;

use Reporion\Controller\PageController;
use Reporion\Http\ErrorMapper;
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
        $render = new Render();
        $session = new Session(
            (string) $config['auth']['session_secret'],
            (string) $config['auth']['session_name'],
            (int) $config['auth']['session_lifetime'],
        );

        $pages = new PageController($storage, $index, $render);

        $router = new Router();
        $router->get('/{path}', static fn (Request $request, array $params): Response
            => $pages->view($request, $params['path'], $session->isOwner($request)));

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
