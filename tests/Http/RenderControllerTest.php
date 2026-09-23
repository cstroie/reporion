<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Controller\RenderController;
use Reporion\Http\Request;
use Reporion\Service\Render;

final class RenderControllerTest extends TestCase
{
    public function testReturnsHtmlTocAndWarningsAsJson(): void
    {
        $controller = new RenderController(new Render());
        $request = new Request('POST', '/render', body: json_encode(['markdown' => "# Titlu\n\ntext"]));

        $response = $controller->render($request, isOwner: true);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertStringContainsString('<h1>Titlu</h1>', $decoded['html']);
        self::assertSame([['level' => 1, 'text' => 'Titlu', 'slug' => 'titlu']], $decoded['toc']);
        self::assertSame([], $decoded['warnings']);
    }

    public function testMissingMarkdownIs422(): void
    {
        $controller = new RenderController(new Render());
        $response = $controller->render(new Request('POST', '/render', body: json_encode(['path' => 'x'])), isOwner: true);

        self::assertSame(422, $response->status);

        $decoded = json_decode($response->body, true);
        self::assertSame('invalid_body', $decoded['error']['code']);
    }

    public function testEmptyBodyIs422(): void
    {
        $controller = new RenderController(new Render());
        $response = $controller->render(new Request('POST', '/render'), isOwner: true);

        self::assertSame(422, $response->status);
    }

    /**
     * docs/architecture-api.md §3: the API has exactly one authenticated
     * caller. This route is compute-on-demand, not a public page read, so
     * it is not on Table 4's public surface — refused as 404, matching
     * invariant 9 (never confirm a restricted route's existence).
     */
    public function testAnonymousCallerGets404NotTheRenderedOutput(): void
    {
        $controller = new RenderController(new Render());
        $request = new Request('POST', '/render', body: json_encode(['markdown' => '# secret structure']));

        $response = $controller->render($request, isOwner: false);

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('secret structure', $response->body);
    }
}
