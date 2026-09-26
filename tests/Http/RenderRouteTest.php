<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * POST /api/v1/render, through the real Kernel. Controller\RenderController
 * itself is unit-tested directly (tests/Http/RenderControllerTest.php);
 * this is the one place that proves it is actually reachable at its
 * documented path — /api/v1/render (docs/architecture-api.md §3), not bare
 * /render, a mismatch caught while building the pages write endpoints (see
 * docs/BUILD_LOG.md).
 */
final class RenderRouteTest extends HttpTestCase
{
    public function testOwnerCanReachTheRealRoute(): void
    {
        $this->createOwner();
        $cookie = (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue('owner');

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/render',
            cookies: ['reporion' => $cookie],
            body: (string) json_encode(['markdown' => '# Titlu'])
        ));

        self::assertSame(200, $response->status);
        self::assertSame('<h1 id="titlu">Titlu</h1>' . "\n", json_decode($response->body, true)['html']);
    }

    public function testBareSlashRenderNoLongerExists(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/render', body: '{}'));

        self::assertSame(404, $response->status);
    }
}
