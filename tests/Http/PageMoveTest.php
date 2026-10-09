<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Cli\Output;
use Reporion\Cli\PageMoveCommand;
use Reporion\Audit\AuditLog;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\PageMoves;
use Reporion\Storage\FlatFile;

/**
 * Moving a page: /{path}/move, POST /api/v1/pages/{path}/move,
 * bin/reporion page:move — redirect stubs, link fixups in unsigned pages
 * only, access at both ends.
 */
final class PageMoveTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
    }

    public function testMovingFromThePageRedirectsToTheNewPathAndTheOldOneFollows(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $move = $this->as('owner', 'POST', '/reports:mri:mioveni:a/move', 'to=reports:mri:pitesti:a');
        self::assertSame(302, $move->status);
        self::assertSame('/reports:mri:pitesti:a', $move->headers['Location']);

        $old = $this->as('owner', 'GET', '/reports:mri:mioveni:a');
        self::assertSame(301, $old->status);
        self::assertSame('/reports:mri:pitesti:a', $old->headers['Location']);
        self::assertSame(200, $this->as('owner', 'GET', '/reports:mri:pitesti:a')->status);
    }

    /**
     * /{path}/rename (TODO 13): the same move as Move, restricted to the last
     * path segment — the namespace prefix comes from $path server-side,
     * never from the request.
     */
    public function testRenameChangesOnlyTheLastSegment(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $move = $this->as('owner', 'POST', '/reports:mri:mioveni:a/rename', 'name=b');
        self::assertSame(302, $move->status);
        self::assertSame('/reports:mri:mioveni:b', $move->headers['Location']);

        $old = $this->as('owner', 'GET', '/reports:mri:mioveni:a');
        self::assertSame(301, $old->status);
        self::assertSame('/reports:mri:mioveni:b', $old->headers['Location']);
    }

    public function testTheRenameRouteShowsTheRenameForm(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $form = $this->as('owner', 'GET', '/reports:mri:mioveni:a/rename');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('action="/reports:mri:mioveni:a/rename"', $form->body);
    }

    /**
     * A colon smuggled into the "name" field (a hand-built POST, since the
     * form field is a plain text input with no colon in the UI) is
     * stripped, not treated as a namespace separator — rename can never
     * cross a namespace, whatever the request contains.
     */
    public function testRenameStripsAnySmuggledColonRatherThanChangingNamespace(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $move = $this->as('owner', 'POST', '/reports:mri:mioveni:a/rename', 'name=' . rawurlencode('ct:hijack'));

        self::assertSame(302, $move->status);
        self::assertSame('/reports:mri:mioveni:cthijack', $move->headers['Location']);
    }

    public function testTheOldPathDoesNotRevealWhereAPrivatePageWent(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        $this->storage()->move('reports:mri:mioveni:a', 'reports:mri:pitesti:a', 'owner');

        $anonymous = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a'));

        self::assertSame(404, $anonymous->status);
        self::assertArrayNotHasKey('Location', $anonymous->headers);
    }

    public function testLinksAreFixedInUnsignedPagesAndSignedOnesAreLeftAlone(): void
    {
        $storage = $this->storage();
        $storage->create('docs:target', ['title' => 'Target', 'visibility' => 'private'], 'body', 'owner');
        $storage->create('docs:draft', ['title' => 'Draft', 'visibility' => 'private'], 'See [t](docs:target).', 'owner');
        $storage->create('docs:signed', ['title' => 'Signed', 'visibility' => 'private'], 'See [t](/docs:target).', 'owner');
        $storage->sign('docs:signed', 'owner', []);

        $response = $this->as('owner', 'POST', '/api/v1/pages/docs:target/move', (string) json_encode(['to' => 'guides:target']));

        self::assertSame(200, $response->status);
        $result = json_decode($response->body, true);
        self::assertSame(1, $result['links_fixed']);
        self::assertSame(1, $result['links_left_signed']);

        $draft = $this->storage()->read('docs:draft');
        self::assertStringContainsString('[t](guides:target)', $draft->body);
        self::assertSame(2, $draft->rev, 'a fixup is an ordinary new revision');
        $signed = $this->storage()->read('docs:signed');
        self::assertSame('signed', $signed->status);
        self::assertSame(1, $signed->rev);
        self::assertStringContainsString('[t](/docs:target)', $signed->body, 'still resolves through the stub');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"page.move"', $audit);
        self::assertStringContainsString('"reason":"link-fixup"', $audit);
        self::assertStringNotContainsString('docs:target', $audit);
    }

    public function testMovingNeedsWriteAccessAtTheTarget(): void
    {
        $this->storage()->create('reports:mri:mioveni:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $ui = $this->as('mihai', 'POST', '/reports:mri:mioveni:a/move', 'to=reports:ct:mioveni:a');
        $api = $this->as('mihai', 'POST', '/api/v1/pages/reports:mri:mioveni:a/move', (string) json_encode(['to' => 'reports:ct:mioveni:a']));

        self::assertSame(422, $ui->status);
        self::assertSame(404, $api->status);
        self::assertSame(200, $this->as('owner', 'GET', '/reports:mri:mioveni:a')->status);
    }

    public function testTheCliMovesThroughTheSameService(): void
    {
        $this->storage()->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        $out = fopen('php://memory', 'w+');

        $code = (new PageMoveCommand(new PageMoves($this->storage(), new AuditLog($this->dataRoot . '/audit'))))
            ->run(['docs:a', 'docs:b', '--actor=mihai'], new Output($out, fopen('php://memory', 'w')));

        self::assertSame(0, $code);
        self::assertSame('docs:b', $this->storage()->redirectTarget('docs:a'));
        rewind($out);
        self::assertStringContainsString('moved to docs:b', (string) stream_get_contents($out));
    }

    /**
     * The bulk move's single fixup pass: every link is matched once against
     * all the moves, so a link rewritten by one move is never rewritten
     * again by another whose "from" happens to be that target.
     */
    public function testRewriteManyAppliesEachMoveOnceInEveryWrittenForm(): void
    {
        $body = '[a](ns:a) [a2](/ns:a#x) [a3](ns/a) [b](ns:b "t") [c](ns:c)';

        self::assertSame(
            '[a](ns:b) [a2](/ns:b#x) [a3](ns/b) [b](ns:z "t") [c](ns:c)',
            PageMoves::rewriteMany($body, ['ns:a' => 'ns:b', 'ns:b' => 'ns:z'])
        );
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function as(string $username, string $method, string $path, string $body = '', array $query = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $cookie], body: $body));
    }
}
