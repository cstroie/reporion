<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Index\Sqlite;
use Reporion\Storage\FileTree;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;
use Reporion\Plugin\Loader;
use Reporion\Plugin\Websearch\Plugin;
use Reporion\Tests\Ai\FakeServer;
use Reporion\Tests\Http\HttpTestCase;

/**
 * Ask the archive on the web (D67): the `ask-expand` prompt's `web:` line is
 * the only thing the search engine is sent; a line holding a report's name word
 * or a number is not sent at all; the pages' passages are numbered sources
 * with their URL. Every name is made up (invariant 10).
 */
final class WebsearchAskTest extends HttpTestCase
{
    private FakeServer $server;

    /** @var list<string> every URL the fake web was asked for */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'websearch');
        $this->server = new FakeServer();
        $this->config['plugins'] = ['enabled' => ['websearch'], 'settings' => ['websearch' => ['provider' => 'brave', 'brave_key' => 'k', 'fetch_pages' => 1]]];
        $this->useExpand('ask-expand-web');
        $this->createOwner();
        $this->storage()->create('ai:profiles:reports:questions:archive', ['visibility' => 'private'], "<question>{question}</question>\n{sources}\nRăspunde.\n", 'owner');
        $this->storage()->create('ai:profiles:reports:questions:archive-expand', ['visibility' => 'private'], "{history}\n<question>{question}</question>\n", 'owner');
        $this->storage()->create('reports:mri:mioveni:260110-ionescu-vasile', ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'IONESCU Vasile']], "# IONESCU Vasile\n\n## IRM\n\n### Descriere\n\nText.\n", 'owner');
        Plugin::$transport = function (string $url): array {
            $this->asked[] = $url;
            if (str_starts_with($url, 'https://api.search.brave.com/')) {
                return [200, (string) json_encode(['web' => ['results' => [
                    ['title' => 'White matter lesions', 'url' => 'https://ref.example/wml', 'description' => 'snippet one'],
                    ['title' => 'Second', 'url' => 'https://ref.example/two', 'description' => 'Periventricular white matter lesions on FLAIR, snippet two'],
                ]]]), []];
            }

            return [200, '<p>White matter lesions are hyperintense on FLAIR MRI, often periventricular in small vessel disease.</p>', ['content-type' => 'text/html']];
        };
    }

    protected function tearDown(): void
    {
        Plugin::$transport = null;
        $this->server->stop();
        parent::tearDown();
    }

    private function useExpand(string $model): void
    {
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => $model]]]], 'retry_delays' => [0, 0, 0]];
    }

    public function testTheWebLineIsSearchedAndItsPagesAreSources(): void
    {
        $this->useExpand('ask-expand-web');
        $response = $this->ask(['question' => 'Cum descriem leziunile de substanță albă?']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertSame('https://api.search.brave.com/res/v1/web/search?q=white%20matter%20lesions%20MRI&count=5', $this->asked[0], 'only the web line goes out');
        self::assertSame(['https://ref.example/wml'], \array_slice($this->asked, 1), 'one page read (fetch_pages 1), the other its snippet');
        self::assertCount(2, $data['sources']);
        self::assertSame('https://ref.example/wml', $data['sources'][0]['url']);
        self::assertSame('Web', $data['sources'][0]['modality']);
        self::assertStringContainsString('small vessel disease', $data['sources'][0]['text']);
        self::assertStringContainsString('snippet two', $data['sources'][1]['text']);
        $prompt = (string) json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('small vessel disease', $prompt);
        self::assertStringNotContainsString('ref.example', $prompt, 'the model reads the text, not the address');
    }

    public function testALineWithANameWordOrANumberIsNotSent(): void
    {
        $this->useExpand('ask-expand-leak');
        $response = $this->ask(['question' => 'Cum descriem leziunile de substanță albă?']);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame([], $this->asked, 'nothing went to the web');
        self::assertContains(t('ask.web_held'), json_decode($response->body, true)['data']['notices']);
    }

    /** @param array<string, mixed> $body */
    private function ask(array $body): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ask', cookies: ['reporion' => $cookie], body: (string) json_encode($body)));
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
