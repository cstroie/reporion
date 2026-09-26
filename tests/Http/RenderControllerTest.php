<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use PHPUnit\Framework\TestCase;
use Reporion\Auth\User;
use Reporion\Controller\RenderController;
use Reporion\Http\Request;
use Reporion\Service\Render;

final class RenderControllerTest extends TestCase
{
    public function testReturnsHtmlTocAndWarningsAsJson(): void
    {
        $controller = new RenderController(new Render());
        $request = new Request('POST', '/render', body: json_encode(['markdown' => "# Titlu\n\ntext"]));

        $response = $controller->render($request, $this->signedInUser());

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertStringContainsString('<h1 id="titlu">Titlu</h1>', $decoded['html']);
        self::assertSame([['level' => 1, 'text' => 'Titlu', 'slug' => 'titlu']], $decoded['toc']);
        self::assertSame([], $decoded['warnings']);
    }

    public function testMissingMarkdownIs422(): void
    {
        $controller = new RenderController(new Render());
        $response = $controller->render(new Request('POST', '/render', body: json_encode(['path' => 'x'])), $this->signedInUser());

        self::assertSame(422, $response->status);

        $decoded = json_decode($response->body, true);
        self::assertSame('invalid_body', $decoded['error']['code']);
    }

    public function testEmptyBodyIs422(): void
    {
        $controller = new RenderController(new Render());
        $response = $controller->render(new Request('POST', '/render'), $this->signedInUser());

        self::assertSame(422, $response->status);
    }

    /**
     * Any signed-in user can reach this route (D35) — not just the owner —
     * since it compiles caller-supplied markdown with no data lookup. A
     * viewer with no write grant anywhere still gets a real render, not a
     * 404, because there is nothing here a namespace grant could scope.
     */
    public function testViewerWithNoWriteGrantAnywhereStillGetsARealRender(): void
    {
        $viewer = new User('v', 'x', false, [], true, 'now', 'now');
        $controller = new RenderController(new Render());
        $request = new Request('POST', '/render', body: json_encode(['markdown' => '# Titlu']));

        $response = $controller->render($request, $viewer);

        self::assertSame(200, $response->status);
    }

    /**
     * docs/architecture-api.md §3: this route is compute-on-demand, not a
     * public page read, so it is not on Table 4's public surface — refused
     * as 404, matching invariant 9 (never confirm a restricted route's
     * existence).
     */
    public function testAnonymousCallerGets404NotTheRenderedOutput(): void
    {
        $controller = new RenderController(new Render());
        $request = new Request('POST', '/render', body: json_encode(['markdown' => '# secret structure']));

        $response = $controller->render($request, null);

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('secret structure', $response->body);
    }

    private function signedInUser(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
