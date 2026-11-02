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
use Reporion\Storage\FileTree;

/** The editor rail's Text flow: POST /api/v1/textflow. Fixture text is made up (invariant 10) */
final class TextFlowTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('ana', 'x', false, [new Grant('notes', GrantRole::Editor), new Grant('docs', GrantRole::Viewer)]);
        $storage = new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $storage->create('notes:paper', ['visibility' => 'private'], "Text.\n", 'ana');
    }

    public function testFixReturnsTheMendedBodyAndWhatIsLeft(): void
    {
        $text = "*— p. 1 —*\n\nThe gel was poured into a\n\n*— p. 2 —*\n\nmould of plastic.\n";
        $response = $this->call('ana', ['path' => 'notes:paper', 'text' => $text]);
        $data = json_decode($response->body, true)['data'];

        self::assertSame(200, $response->status);
        self::assertTrue($data['changed']);
        self::assertSame("*— p. 1 —*\n\nThe gel was poured into a mould of plastic.\n\n*— p. 2 —*\n", $data['text']);
        self::assertSame(['joined' => 1], $data['fixed']);
        self::assertSame([], $data['issues']);
    }

    public function testOnlyAWriterAndNeverOnAReport(): void
    {
        self::assertSame(404, $this->call(null, ['path' => 'notes:paper', 'text' => 'x'])->status);
        self::assertSame(404, $this->call('ana', ['path' => 'docs:page', 'text' => 'x'])->status, 'reads only there');
        self::assertSame(404, $this->call('owner', ['path' => 'reports:mri:site:261102-test-x', 'text' => 'x'])->status, 'a report is no article');
        self::assertSame(413, $this->call('ana', ['path' => 'notes:paper', 'text' => str_repeat('x', 1_000_001)])->status);
    }

    public function testTheEditorOffersItOnAPageThatIsNoReport(): void
    {
        $page = $this->call('ana', [], 'GET', '/notes:paper/edit')->body;

        self::assertStringContainsString('data-flow="fix"', $page);
        self::assertStringContainsString('data-flow="check"', $page);
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, array $body, string $method = 'POST', string $path = '/api/v1/textflow'): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }
}
