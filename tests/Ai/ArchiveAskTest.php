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
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;

/**
 * Ask the archive (D53): POST /api/v1/ask answers from the finished
 * reports the caller can read, de-identified, against the fake
 * OpenAI-compatible server; GET /ask is the screen. Fixture data is
 * fictitious (invariant 10).
 */
final class ArchiveAskTest extends HttpTestCase
{
    private const QUESTION = 'Cum descriem leziunile de substanță albă FLAIR la Ionescu?';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $storage = $this->storage();
        $storage->create('ai:profiles:reports:special:ask-archive', ['visibility' => 'private'], "<intrebare>{question}</intrebare>\n{sources}\nRăspunde citând sursele.\n", 'owner');
        // Finished, readable: a source
        $storage->create(
            'reports:mri:mioveni:260110-ionescu-vasile',
            ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'study_date' => '2026-01-10', 'patient' => ['name' => 'IONESCU Vasile', 'cnp' => '1900101000002']],
            "# IONESCU Vasile\n\n## IRM cerebral\n\n### Descriere\n\nLeziuni de substanță albă periventriculare la pacientul Ionescu, hiperintense FLAIR, fără efect de masă.\n",
            'mihai',
        );
        // A draft: not finished, never a source
        $storage->create(
            'reports:mri:mioveni:260111-marin-ion',
            ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'MARIN Ion']],
            "# MARIN Ion\n\n## IRM cerebral\n\n### Descriere\n\nLeziuni de substanță albă DRAFTONLY, hiperintense FLAIR.\n",
            'mihai',
        );
        // Finished but outside mihai's grants: never a source for him
        $storage->create(
            'reports:ct:mioveni:260112-dinu-ana',
            ['visibility' => 'private', 'status' => 'archived', 'modality' => ['CT'], 'patient' => ['name' => 'DINU Ana']],
            "# DINU Ana\n\n## CT cerebral\n\n### Descriere\n\nLeziuni de substanță albă HIDDENCT, hipodense, FLAIR nu se aplică.\n",
            'owner',
        );
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testTheAnswerComesFromReadableFinishedPassagesDeIdentified(): void
    {
        $response = $this->ask('mihai', ['question' => self::QUESTION]);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertCount(1, $data['sources']);
        self::assertSame(1, $data['sources'][0]['n']);
        self::assertStringStartsWith('/r/', $data['sources'][0]['href']);
        self::assertStringNotContainsString('reports:', $response->body, 'a source is linked by pid, never by path');
        self::assertContains('1 sources', $data['context']);

        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('<sursa id="1" data="2026-01-10">', $user);
        self::assertStringContainsString('hiperintense FLAIR, fără efect de masă', $user);
        self::assertStringContainsString('la [nume]?', $user, 'a name in the question goes as [nume]');
        foreach (['Ionescu', 'IONESCU', 'Vasile', 'reports:', 'DRAFTONLY', 'HIDDENCT', '1900101000002'] as $never) {
            self::assertStringNotContainsString($never, $user, $never);
        }
    }

    public function testTheOwnerReadsWhatMihaiCannot(): void
    {
        $this->makeRadiologist('owner');
        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertCount(2, $data['sources']);
    }

    public function testNoSourceAsksNoModel(): void
    {
        $response = $this->ask('mihai', ['question' => 'meningiom sfenoidal calcificat']);

        self::assertSame(200, $response->status);
        self::assertNull(json_decode($response->body, true)['data']['answer']);
        self::assertNull($this->server->lastRequest()['body'], 'no model asked');
    }

    public function testRefusals(): void
    {
        self::assertSame(404, $this->ask(null, ['question' => self::QUESTION])->status);
        self::assertSame(422, $this->ask('mihai', ['question' => ''])->status);
        self::assertSame(422, $this->ask('mihai', ['question' => str_repeat('a', 1001)])->status);

        $this->storage()->delete('ai:profiles:reports:special:ask-archive', 'owner');
        $response = $this->ask('mihai', ['question' => self::QUESTION]);
        self::assertSame(503, $response->status);
        self::assertSame('not_configured', json_decode($response->body, true)['error']['code']);
    }

    public function testTheScreenAndTheSearchLink(): void
    {
        $screen = $this->get('/ask?q=' . rawurlencode('substanță albă'));
        self::assertSame(200, $screen->status);
        self::assertStringContainsString('data-island="ask"', $screen->body);
        self::assertStringContainsString('substanță albă</textarea>', $screen->body);

        self::assertStringContainsString('/ask?q=', $this->get('/search?q=leziuni')->body);

        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/ask'))->status);
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ask', cookies: $cookies, body: (string) json_encode($body)));
    }

    private function get(string $uri): Response
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, query: $params, cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('mihai')]));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
