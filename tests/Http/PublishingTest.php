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
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * PATCH /api/v1/pages/{path}/meta and GET/POST /{path}/visibility —
 * D16's acknowledged publish, and signed reports refused.
 */
final class PublishingTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260923-test-subject';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $this->storage()->create(self::PATH, ['title' => 'RM', 'visibility' => 'private', 'patient' => ['name' => 'TEST SUBJECT', 'born' => 1970]], 'body', 'owner');
    }

    public function testMetaChangesAreOneNewRevisionAndStatusIsNeverSetThisWay(): void
    {
        $response = $this->api('owner', ['meta' => ['visibility' => 'unlisted', 'tags' => ['a'], 'status' => 'signed'], 'base_rev' => 1]);

        self::assertSame(200, $response->status);
        $page = $this->storage()->read(self::PATH);
        self::assertSame(2, $page->rev);
        self::assertSame('unlisted', $page->visibility);
        self::assertSame(['a'], $page->frontmatter['tags']);
        self::assertSame('draft', $page->status);
    }

    public function testGoingPublicNeedsAcknowledgementAndIsAudited(): void
    {
        $first = $this->api('owner', ['meta' => ['visibility' => 'public'], 'base_rev' => 1]);

        self::assertSame(409, $first->status);
        $payload = json_decode($first->body, true);
        self::assertSame('acknowledge_required', $payload['error']['code']);
        self::assertTrue($payload['preview']['pathLooksPersonal']);
        self::assertContains('patient.name', $payload['preview']['hiddenPatientFields']);
        self::assertSame('private', $this->storage()->read(self::PATH)->visibility);

        self::assertSame(200, $this->api('owner', ['meta' => ['visibility' => 'public'], 'base_rev' => 1, 'acknowledge' => true])->status);
        self::assertSame('public', $this->storage()->read(self::PATH)->visibility);
        self::assertStringContainsString('"action":"page.publish"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testSignedReportsAreRefusedAndNonWritersGet404(): void
    {
        $this->storage()->sign(self::PATH, 'owner', []);

        $signed = $this->api('owner', ['meta' => ['visibility' => 'unlisted'], 'base_rev' => 1]);
        self::assertSame(409, $signed->status);
        self::assertSame('signed', json_decode($signed->body, true)['error']['code']);
        self::assertSame(404, $this->api('ana', ['meta' => ['visibility' => 'unlisted'], 'base_rev' => 1])->status);
        self::assertSame('signed', $this->storage()->read(self::PATH)->status);
    }

    public function testTheBrowserFlowConfirmsBeforePublishing(): void
    {
        $confirm = $this->as('owner', 'POST', '/' . self::PATH . '/visibility', 'visibility=public&base_rev=1');
        self::assertSame(200, $confirm->status);
        self::assertStringContainsString(t('vis.personal_path'), htmlspecialchars_decode($confirm->body, ENT_QUOTES));
        self::assertStringContainsString('name="acknowledge"', $confirm->body);
        self::assertSame('private', $this->storage()->read(self::PATH)->visibility);

        $done = $this->as('owner', 'POST', '/' . self::PATH . '/visibility', 'visibility=public&base_rev=1&acknowledge=1');
        self::assertSame(302, $done->status);
        self::assertSame('public', $this->storage()->read(self::PATH)->visibility);
    }

    public function testASignedReportsVisibilityScreenPointsAtDuplicating(): void
    {
        $this->storage()->sign(self::PATH, 'owner', []);

        $form = $this->as('owner', 'GET', '/' . self::PATH . '/visibility');

        self::assertStringContainsString('/new?from=', $form->body);
        self::assertStringNotContainsString('name="visibility"', $form->body);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param array<string, mixed> $body */
    private function api(string $username, array $body): Response
    {
        return $this->as($username, 'PATCH', '/api/v1/pages/' . self::PATH . '/meta', (string) json_encode($body));
    }

    private function as(string $username, string $method, string $path, string $body = ''): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: $body));
    }
}
