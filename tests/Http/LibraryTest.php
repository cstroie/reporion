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
 * The library's endpoints (docs/library.md, phase 1): upload → proposed record → confirmed page,
 * the original served to who may see the page. The article is made up.
 */
final class LibraryTest extends HttpTestCase
{
    private const ARTICLE = "# A made-up study of test lesions\n\nJ Synth Imaging 2024;3:11-14. doi:10.1234/jti.2024.0001\n\nGrade 3 was the most common.\n";

    protected function setUp(): void
    {
        parent::setUp();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Editor)]);
        $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Viewer)]);
        $users->create('other', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor)]);
    }

    public function testAnEditorUploadsConfirmsAndTheOriginalIsServed(): void
    {
        $uploaded = $this->upload('editor', self::ARTICLE, 'study.md');
        self::assertSame(201, $uploaded->status);
        $proposal = json_decode($uploaded->body, true);
        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame('madeup2024', $proposal['key']);

        $confirmed = $this->call('editor', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl'], 'key' => 'study2024']);
        self::assertSame(201, $confirmed->status);
        $page = json_decode($confirmed->body, true);
        self::assertSame(['library:study2024', 'study2024', '/library:study2024'], [$page['path'], $page['key'], $page['href']]);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"library.add"', $audit);

        $file = $this->call('reader', 'GET', '/library/file/' . $page['pid']);
        self::assertSame(200, $file->status);
        self::assertSame(self::ARTICLE, $file->body);
        self::assertSame('attachment; filename="study2024.md"', $file->headers['Content-Disposition']);
        self::assertSame('nosniff', $file->headers['X-Content-Type-Options']);
        self::assertSame(404, $this->call(null, 'GET', '/library/file/' . $page['pid'])->status, 'anonymous: never');
        self::assertSame(404, $this->call('other', 'GET', '/library/file/' . $page['pid'])->status, 'no grant on the library');
    }

    public function testOnlyAnEditorOfTheLibraryUploadsOrConfirms(): void
    {
        foreach ([null, 'reader', 'other'] as $user) {
            self::assertSame(404, $this->upload($user, self::ARTICLE, 'study.md')->status, $user ?? 'anonymous');
            self::assertSame(404, $this->call($user, 'POST', '/api/v1/library', ['token' => str_repeat('0', 32), 'csl' => ['title' => 'X y z']])->status);
        }
        self::assertSame([], glob($this->dataRoot . '/tmp/library/*') ?: []);
    }

    public function testRefusalsAreSaidWithTheirOwnStatus(): void
    {
        self::assertSame(415, $this->upload('owner', 'MZ', 'tool.exe')->status);
        $this->config['library']['max_mb'] = 1;
        self::assertSame(413, $this->upload('owner', str_repeat('a', 1024 * 1024 + 1), 'big.txt')->status);

        $expired = $this->call('owner', 'POST', '/api/v1/library', ['token' => str_repeat('0', 32), 'csl' => ['title' => 'X y z']]);
        self::assertSame(410, $expired->status);
        self::assertSame('expired', json_decode($expired->body, true)['error']['code']);

        $first = json_decode($this->upload('owner', self::ARTICLE, 'a.md')->body, true);
        $this->call('owner', 'POST', '/api/v1/library', ['token' => $first['token'], 'csl' => $first['csl']]);
        $again = json_decode($this->upload('owner', self::ARTICLE, 'b.md')->body, true);
        self::assertSame('file', $again['duplicate']['by']);
        $duplicate = $this->call('owner', 'POST', '/api/v1/library', ['token' => $again['token'], 'csl' => $again['csl']]);
        self::assertSame(409, $duplicate->status);
        self::assertSame('library:madeup2024', json_decode($duplicate->body, true)['error']['fields']['path']);
    }

    public function testTheVisibilityScreenRefusesToPublishALibrarySource(): void
    {
        $proposal = json_decode($this->upload('owner', self::ARTICLE, 'study.md')->body, true);
        $page = json_decode($this->call('owner', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl']])->body, true);

        $patched = $this->call('owner', 'PATCH', '/api/v1/pages/' . $page['path'] . '/meta', ['changes' => ['visibility' => 'public'], 'base_rev' => 1, 'acknowledge' => true]);

        self::assertGreaterThanOrEqual(400, $patched->status);
        self::assertLessThan(500, $patched->status);
        self::assertSame('private', (new \Reporion\Storage\FileTree($this->dataRoot, new \Reporion\Tests\Storage\RecordingIndex()))->read($page['path'])->visibility);
    }

    public function testTheEditorRefusesToPublishALibrarySourceAndKeepsTheText(): void
    {
        $proposal = json_decode($this->upload('owner', self::ARTICLE, 'study.md')->body, true);
        $page = json_decode($this->call('owner', 'POST', '/api/v1/library', ['token' => $proposal['token'], 'csl' => $proposal['csl']])->body, true);

        $refused = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/' . $page['path'] . '/edit',
            cookies: ['reporion' => $this->cookie('owner')],
            body: http_build_query(['body' => 'edited text', 'base_rev' => 1, 'visibility' => 'public', 'acknowledge' => '1']),
        ));

        self::assertSame(422, $refused->status);
        self::assertStringContainsString('A library source is never public', $refused->body);
        self::assertStringContainsString('edited text', $refused->body);
    }

    private function upload(?string $user, string $bytes, string $name): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/api/v1/library/uploads',
            query: ['name' => $name],
            cookies: $user === null ? [] : ['reporion' => $this->cookie($user)],
            body: $bytes,
        ));
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, string $method, string $url, array $body = []): Response
    {
        $cookies = $user !== null ? ['reporion' => $this->cookie($user)] : [];

        return Kernel::boot($this->config)->handle(new Request($method, $url, cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }
}
