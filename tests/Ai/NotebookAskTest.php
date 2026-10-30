<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Library\NotebookAsk;
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/** Ask the notebook (docs/library.md, phase 5) against the fake OpenAI-compatible server; the articles are made up */
final class NotebookAskTest extends HttpTestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'ask-expand']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('writer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Editor)]);
        $users->create('libreader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Viewer)]);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Viewer), new Grant('library', GrantRole::Editor)]);
        $storage = $this->storage();
        $storage->create('ai:profiles:wiki:special:ask-notebook', ['title' => 'Ask', 'format' => 'text', 'visibility' => 'private'], "{sources}\n<q>{question}</q> in {language}\n", 'owner');
        $storage->create('ai:profiles:wiki:special:notebook-terms', ['title' => 'Terms', 'format' => 'text', 'visibility' => 'private', 'model' => 'lite'], "<q>{question}</q>\n", 'owner');
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study', 'issued' => ['date-parts' => [[2020]]]];
        $storage->create('library:abel2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl], "*— p. 4 —*\n\nWhite matter lesions were graded in 128 patients.\n\n## References\n\n```text\n1. Cited elsewhere.\n```\n", 'owner');
        $long = '';
        foreach (range(1, 6) as $page) {
            $long .= "*— p. {$page} —*\n\n" . str_repeat("Page {$page} filler sentence about nothing. ", 20) . ($page === 5 ? "\n\nLeziuni de substanță albă: white matter lesions graded with Fazekas.\n\n" : "\n\n");
        }
        $storage->create('library:long2021', ['title' => 'A long made-up study', 'visibility' => 'private', 'csl' => $csl], $long, 'owner');
        $storage->create('wiki:notebook', ['title' => 'White matter', 'visibility' => 'private', 'language' => 'ro'], "# White matter\n\nOur notes [@abel2020].\n", 'owner');
        $storage->create('wiki:long', ['title' => 'Long', 'visibility' => 'private', 'sources' => ['long2021']], "# Long\n\nNotes on leziuni [@abel2020].\n", 'owner');
        $storage->create('wiki:patient', ['title' => 'P', 'visibility' => 'private', 'patient' => ['name' => 'X Y']], "Cites [@abel2020].\n", 'owner');
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

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/notebook/ask', cookies: $user === null ? [] : ['reporion' => $this->cookie($user)], body: (string) json_encode($body)));
    }

    public function testASmallNotebookAndItsSourcesAreReadWhole(): void
    {
        $response = $this->ask('writer', ['path' => 'wiki:notebook', 'question' => 'Cum sunt gradate leziunile?']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertTrue($data['whole']);
        self::assertSame('Concluzie: fără leziuni.', $data['answer']);
        self::assertSame([null, 'abel2020'], array_column($data['sources'], 'key'));
        self::assertSame('/library:abel2020', $data['sources'][1]['href']);
        self::assertSame(1, $this->server->requests(), 'no search words needed');
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('<source id="1" kind="notebook" title="White matter">', $user);
        self::assertStringContainsString('<source id="2" key="abel2020" title="A made-up study">', $user);
        self::assertStringContainsString('graded in 128 patients', $user);
        self::assertStringNotContainsString('Cited elsewhere', $user, 'the article\'s reference list is not a source');
        self::assertStringContainsString('in Romanian', $user);
    }

    public function testALargeOneSendsThePassagesTheQuestionsWordsAreIn(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];

        $response = $this->ask('writer', ['path' => 'wiki:long', 'question' => 'Cum descriem leziunile de substanță albă?']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertFalse($data['whole']);
        self::assertSame(2, $this->server->requests(), 'the search words, then the answer');
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('key="long2021" page="5"', $user, 'the passage carries its page');
        self::assertStringContainsString('graded with Fazekas', $user);
        self::assertStringNotContainsString('Page 2 filler', $user, 'a passage without the words is left out');
    }

    public function testNothingMatchingAsksNoModel(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];
        $this->storage()->delete('ai:profiles:wiki:special:notebook-terms', 'owner');

        $response = $this->ask('writer', ['path' => 'wiki:long', 'question' => 'Ce spune despre zebre?']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertNull($data['answer']);
        self::assertSame([], $data['sources']);
        self::assertNotSame([], $data['notices']);
        self::assertSame(0, $this->server->requests());
    }

    public function testOnlyAnEditorOfTheNotebookAndOfTheLibraryAsksAndNeverAboutAPatient(): void
    {
        foreach ([null, 'libreader', 'viewer'] as $user) {
            self::assertSame(404, $this->ask($user, ['path' => 'wiki:notebook', 'question' => 'Ce?'])->status, $user ?? 'anonymous');
        }
        self::assertSame(404, $this->ask('owner', ['path' => 'wiki:patient', 'question' => 'Ce?'])->status, 'a page naming a patient is no notebook');
        self::assertSame(404, $this->ask('owner', ['path' => 'reports:mri:x:260101-a-b', 'question' => 'Ce?'])->status);
        self::assertSame(422, $this->ask('writer', ['path' => 'wiki:notebook', 'question' => ''])->status);
        self::assertSame(422, $this->ask('writer', ['path' => 'wiki:notebook', 'question' => str_repeat('a', 1001)])->status);
        self::assertSame(0, $this->server->requests());

        $this->storage()->delete('ai:profiles:wiki:special:ask-notebook', 'owner');
        self::assertSame(503, $this->ask('writer', ['path' => 'wiki:notebook', 'question' => 'Ce?'])->status);
    }

    public function testAReadTokenCannotAsk(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        [$user, $token] = (new \Reporion\Auth\ApiTokens($store))->create($store->find('writer'), 'script', 'read');
        $store->save($user);

        self::assertSame(403, Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/notebook/ask', body: '{"path":"wiki:notebook","question":"Ce?"}', authorization: 'Bearer ' . $token))->status);
    }

    public function testThePanelIsOfferedWhereItCanBeUsed(): void
    {
        $view = fn (string $user, string $path): string => Kernel::boot($this->config)->handle(new Request('GET', '/' . $path, cookies: ['reporion' => $this->cookie($user)]))->body;

        self::assertStringContainsString('data-island="notebook-ask"', $view('writer', 'wiki:notebook'));
        self::assertStringNotContainsString('data-island="notebook-ask"', $view('libreader', 'wiki:notebook'), 'not an editor of the library');
        self::assertStringNotContainsString('data-island="notebook-ask"', $view('owner', 'wiki:patient'));
        self::assertStringNotContainsString('data-island="notebook-ask"', $view('owner', 'library:abel2020'), 'a source is no notebook');
    }

    public function testACitationOfASourceNotSentIsTakenOut(): void
    {
        [$answer, $dropped] = NotebookAsk::onlySent('Graded [@abel2020, p. 4] and also [@ghost1999]. Both [@abel2020; @ghost1999, p. 2].', ['abel2020']);

        self::assertSame('Graded [@abel2020, p. 4] and also. Both [@abel2020].', $answer);
        self::assertSame(2, $dropped);
    }

    public function testTheSourcesAreThoseTheCallerMayRead(): void
    {
        $storage = $this->storage();
        $ask = new NotebookAsk(
            new \Reporion\Service\Ai\Actions(\Reporion\Service\Ai\AiConfig::fromConfig($this->config), $storage, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations')),
            \Reporion\Kernel::assistant($this->config, \Reporion\Service\Ai\AiConfig::fromConfig($this->config), $storage, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'), new \Reporion\Audit\AuditLog($this->dataRoot . '/audit')),
            $storage,
            new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'),
            'library',
            32000,
        );
        $notebook = $storage->read('wiki:long');
        $stranger = new User('stranger', 'x', false, [new Grant('wiki', GrantRole::Editor)], true, 'now', 'now');

        self::assertSame(['abel2020', 'long2021'], array_column($ask->sources($notebook, new User('owner', 'x', true, [], true, 'now', 'now')), 'key'), 'cited, then listed in sources');
        self::assertSame([], $ask->sources($notebook, $stranger), 'no grant on the library: no source text');
    }
}
