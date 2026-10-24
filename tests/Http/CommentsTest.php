<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * A page's comments (D52) through the API and on the page: whoever may
 * read the page comments, resolves and reopens; the author or an owner
 * deletes; audited without the words; never in the page's text, its
 * export or the API's `meta`. Fixture data is fictitious (invariant 10).
 */
final class CommentsTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261019-test-ionescu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $this->makeRadiologist('ana');
        $users->create('dan', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('dan');
        $users->create('ct', 'x', false, [new Grant('reports:ct', GrantRole::Editor)]);
        $this->makeRadiologist('ct');
        $this->createPage(self::PATH, 'private', 'TEST Ionescu', "# TEST Ionescu\n\n## IRM cerebral\n\n### Concluzii\n\nNormal.\n");
    }

    public function testAReaderCommentsAndTheCommentStaysBesideThePage(): void
    {
        $added = $this->call('ana', 'POST', '/api/v1/pages/' . self::PATH . '/comments', ['text' => 'De verificat suprarenala stângă, pacientul Ionescu.', 'quote' => '  Normal. ']);
        self::assertSame(201, $added->status);
        $comment = json_decode($added->body, true)['data'];
        self::assertSame('ana', $comment['by']);
        self::assertSame('Normal.', $comment['quote']);
        self::assertSame(1, $comment['rev']);

        $list = json_decode($this->call('dan', 'GET', '/api/v1/pages/' . self::PATH . '/comments')->body, true)['data'];
        self::assertSame([$comment['id']], array_column($list, 'id'));

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"comment.add"', $audit);
        self::assertStringNotContainsString('suprarenala', $audit, 'never the words');
        self::assertStringNotContainsString('Ionescu', $audit);

        $page = $this->call('dan', 'GET', '/' . self::PATH)->body;
        self::assertStringContainsString('data-island="comments"', $page);
        self::assertStringNotContainsString('suprarenala', $page, 'loaded by the island, not in the page');
        self::assertStringNotContainsString('suprarenala', $this->call('dan', 'GET', '/export/' . self::PATH . '.html')->body, 'never exported');
        self::assertStringNotContainsString('suprarenala', $this->call('dan', 'GET', '/api/v1/pages/' . self::PATH)->body, 'never in the API meta');
        self::assertStringNotContainsString('suprarenala', (string) file_get_contents($this->dataRoot . '/pages/reports/mri/mioveni/261019-test-ionescu.md'));
    }

    public function testResolveReopenAndWhoMayDelete(): void
    {
        $id = json_decode($this->call('ana', 'POST', '/api/v1/pages/' . self::PATH . '/comments', ['text' => 'Întrebare.'])->body, true)['data']['id'];
        $url = '/api/v1/pages/' . self::PATH . '/comments/' . $id;

        $resolved = json_decode($this->call('dan', 'POST', $url, ['action' => 'resolve'])->body, true)['data'];
        self::assertSame('dan', $resolved[0]['resolved']['by']);
        self::assertNull(json_decode($this->call('ana', 'POST', $url, ['action' => 'reopen'])->body, true)['data'][0]['resolved']);

        self::assertSame(403, $this->call('dan', 'POST', $url, ['action' => 'delete'])->status, 'not his');
        self::assertSame(200, $this->call('owner', 'POST', $url, ['action' => 'delete'])->status, 'an owner');
        self::assertSame(404, $this->call('ana', 'POST', $url, ['action' => 'delete'])->status, 'gone');
        self::assertSame(422, $this->call('ana', 'POST', '/api/v1/pages/' . self::PATH . '/comments/' . $id, ['action' => 'nope'])->status);
    }

    public function testOnlyWhoMayReadThePage(): void
    {
        $url = '/api/v1/pages/' . self::PATH . '/comments';
        self::assertSame(404, $this->call(null, 'GET', $url)->status, 'anonymous');
        self::assertSame(404, $this->call('ct', 'GET', $url)->status, 'no grant here');
        self::assertSame(404, $this->call('ct', 'POST', $url, ['text' => 'x'])->status);
        self::assertSame(422, $this->call('ana', 'POST', $url, ['text' => '   '])->status, 'an empty comment');
        self::assertSame(422, $this->call('ana', 'POST', $url, ['text' => str_repeat('x', 4001)])->status);
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, string $method, string $url, array $body = []): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request($method, $url, cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }
}
