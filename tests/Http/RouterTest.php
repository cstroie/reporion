<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesAPlaceholderAndPassesItToTheHandler(): void
    {
        $router = new Router();
        $router->get('/{path}', static fn (Request $r, array $p): Response => Response::html('got:' . $p['path']));

        $response = $router->dispatch(new Request('GET', '/reports:mri:mioveni:x'));

        self::assertSame(200, $response->status);
        self::assertSame('got:reports:mri:mioveni:x', $response->body);
    }

    public function testUnmatchedRouteIs404(): void
    {
        $router = new Router();
        $router->get('/known', static fn (): Response => Response::html('ok'));

        self::assertSame(404, $router->dispatch(new Request('GET', '/unknown'))->status);
    }

    public function testMethodMismatchIs404(): void
    {
        $router = new Router();
        $router->get('/x', static fn (): Response => Response::html('ok'));

        self::assertSame(404, $router->dispatch(new Request('POST', '/x'))->status);
    }

    public function testPostRouteMatches(): void
    {
        $router = new Router();
        $router->post('/render', static fn (Request $r): Response => Response::html('body:' . $r->body));

        $response = $router->dispatch(new Request('POST', '/render', body: 'hello'));

        self::assertSame(200, $response->status);
        self::assertSame('body:hello', $response->body);
    }
}
