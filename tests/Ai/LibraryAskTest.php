<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/** Ask the library (2026-11-02) against the fake OpenAI-compatible server; the articles and the report are made up */
final class LibraryAskTest extends HttpTestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'ask-expand']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('writer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Editor)]);
        $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Viewer)]);
        $storage = $this->storage();
        $storage->create('ai:profiles:wiki:questions:library', ['title' => 'Ask the library', 'format' => 'text', 'visibility' => 'private'], "{history}\n{sources}\n<q>{question}</q> in {language}\n", 'owner');
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study', 'issued' => ['date-parts' => [[2020]]]];
        $storage->create('library:abel2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl], "*— p. 4 —*\n\nWhite matter lesions were graded with Fazekas in 128 patients.\n", 'owner');
        $storage->create('library:cole2021', ['title' => 'Another made-up study', 'visibility' => 'private', 'csl' => $csl], "Nothing on the subject: cardiac output only.\n", 'owner');
        $storage->create('reports:mri:x:260101-test-unu', ['visibility' => 'private'], "# Test Unu\n\n## IRM\n\n### Concluzii\n\nLeziuni Fazekas 2.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        $cookies = $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/library/ask', cookies: $cookies, body: (string) json_encode($body)));
    }

    public function testAQuestionIsAnsweredFromTheLibrarysPassagesNeverFromAReport(): void
    {
        $response = $this->ask('writer', ['question' => 'How were the lesions graded with Fazekas?', 'history' => [['question' => 'Earlier one?', 'answer' => 'Yes [@abel2020, p. 4].']]]);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertFalse($data['whole'], 'the library is searched, never sent whole');
        self::assertSame(['abel2020'], array_values(array_unique(array_column($data['sources'], 'key'))));
        self::assertSame('/library:abel2020', $data['sources'][0]['href']);
        $sent = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('graded with Fazekas', $sent);
        self::assertStringNotContainsString('Leziuni Fazekas 2', $sent, 'a report is never a source');
        self::assertStringNotContainsString('cardiac output', $sent, 'a passage without the words is left out');
        self::assertStringContainsString('<question>Earlier one?</question>', $sent, 'the earlier turn, its citation taken out');
        self::assertStringNotContainsString('[@abel2020, p. 4]', $sent);
    }

    public function testAskThisPaperKeepsTheQuestionToOneSource(): void
    {
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $cole = (string) $index->findByPath('library:cole2021', (new FlatFileUserStore($this->dataRoot))->find('writer'))['pid'];

        $response = $this->ask('writer', ['question' => 'How were the lesions graded with Fazekas?', 'source' => $cole]);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertTrue($data['whole'], 'one short source goes whole');
        self::assertSame(['cole2021'], array_values(array_unique(array_column($data['sources'], 'key'))));
        $sent = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('cardiac output', $sent);
        self::assertStringNotContainsString('128 patients', $sent, 'the other source is not read');

        $report = (string) $index->findByPath('reports:mri:x:260101-test-unu', (new FlatFileUserStore($this->dataRoot))->find('owner'))['pid'];
        self::assertSame(404, $this->ask('writer', ['question' => 'Fazekas?', 'source' => $report])->status, 'never a report');
        self::assertSame(404, $this->ask('writer', ['question' => 'Fazekas?', 'source' => '01J0000000000000000000000A'])->status);
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/library:cole2021', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('writer')]))->body;
        self::assertStringContainsString('"source":"' . $cole . '"', $page, 'the panel on the source\'s page');
        $read = Kernel::boot($this->config)->handle(new Request('GET', '/library:cole2021', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('reader')]))->body;
        self::assertStringNotContainsString('"source":"' . $cole . '"', $read, 'a reader does not ask');
    }

    public function testOnlyAnEditorOfTheLibraryAsksAndOnlyWithThePrompt(): void
    {
        foreach ([null, 'reader'] as $user) {
            self::assertSame(404, $this->ask($user, ['question' => 'Fazekas?'])->status, $user ?? 'anonymous');
        }
        self::assertSame(422, $this->ask('writer', ['question' => ''])->status);
        self::assertSame(0, $this->server->requests());
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/library', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('writer')]))->body;
        self::assertStringContainsString('\\/api\\/v1\\/library\\/ask', $page, 'the panel on /library');

        $this->storage()->delete('ai:profiles:wiki:questions:library', 'owner');
        self::assertSame(503, $this->ask('writer', ['question' => 'Fazekas?'])->status);
        $page = Kernel::boot($this->config)->handle(new Request('GET', '/library', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('writer')]))->body;
        self::assertStringNotContainsString('library\\/ask', $page);
    }
}
