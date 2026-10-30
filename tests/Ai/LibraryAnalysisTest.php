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

/**
 * The assistant's analyses of a library source (docs/library.md, phase 5) against the fake
 * OpenAI-compatible server; the articles are made up.
 */
final class LibraryAnalysisTest extends HttpTestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        // 4000 tokens: a source over 3000 characters is read in parts
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->config['library'] = ['context_tokens' => 4000];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Editor)]);
        $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('library', GrantRole::Viewer)]);
        $users->create('other', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor)]);
        $storage = $this->storage();
        foreach (['source-points' => "Points of {title} ({citation}) in {language}:\n<a>{source}</a>\n", 'source-part' => "Part {part}, at most {words} words:\n<a>{source}</a>\n", 'summary' => "Summarize:\n<a>{text}</a>\n"] as $id => $prompt) {
            $storage->create('ai:profiles:wiki:special:' . $id, ['title' => $id, 'format' => 'text', 'visibility' => 'private'], $prompt, 'owner');
        }
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study', 'author' => [['family' => 'Nobody', 'given' => 'Ann']], 'issued' => ['date-parts' => [[2024]]]];
        $storage->create('library:short2024', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl, 'language' => 'en'], "Grade 3 was the most common in 128 patients.\n\n## References\n\n```text\n1. Nobody C. Cited paper.\n```\n", 'owner');
        $long = '';
        foreach (['Methods', 'Results', 'Discussion', 'Limits'] as $i => $name) {
            $long .= "## {$name}\n\n*— p. " . ($i + 1) . " —*\n\n" . str_repeat("{$name} sentence with a finding. ", 40) . "\n\n";
        }
        $storage->create('library:long2024', ['title' => 'A long made-up study', 'visibility' => 'private', 'csl' => $csl], $long, 'owner');
        $storage->create('library:huge2024', ['title' => 'A huge study', 'visibility' => 'private', 'csl' => $csl], str_repeat("Filler sentence of the huge study. ", 1400), 'owner');
        $storage->create('wiki:plain', ['title' => 'Plain', 'visibility' => 'private'], "Text.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    private function pid(string $path): string
    {
        return $this->storage()->read($path)->pid;
    }

    /** @param array<string, mixed> $body */
    private function analyze(?string $user, string $path, array $body): Response
    {
        $cookies = $user === null ? [] : ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/library/' . $this->pid($path) . '/analyze', cookies: $cookies, body: (string) json_encode($body)));
    }

    public function testEachAnalysisIsKeptBesideItsSourceAndShownWhenItIsOpenedAgain(): void
    {
        $data = json_decode($this->analyze('editor', 'library:short2024', ['task' => 'points'])->body, true)['data'];

        self::assertSame(['path' => 'library:short2024:source-points', 'href' => '/library:short2024:source-points', 'created' => true], $data['saved']);
        $kept = $this->storage()->read('library:short2024:source-points');
        self::assertSame("Concluzie: fără leziuni.\n", $kept->body);
        self::assertSame('Key points: A made-up study', $kept->frontmatter['title']);
        self::assertSame('private', $kept->visibility);
        self::assertSame(['source-points', $this->pid('library:short2024')], [$kept->frontmatter['analysis']['prompt'], $kept->frontmatter['analysis']['of']]);
        $revlog = $kept->revlog;
        self::assertSame('assisted: source-points', end($revlog)['note'] ?? null);
        self::assertStringContainsString('"assisted":["source-points"]', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        // Run again: a new revision of the same page
        self::assertFalse(json_decode($this->analyze('editor', 'library:short2024', ['task' => 'points'])->body, true)['data']['saved']['created']);
        self::assertSame(2, $this->storage()->read('library:short2024:source-points')->rev);

        // Reopened, by any reader of the source
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('reader');
        $view = Kernel::boot($this->config)->handle(new Request('GET', '/library:short2024', cookies: ['reporion' => $cookie]))->body;
        self::assertStringContainsString('data-saved-prompt="source-points"', $view);
        self::assertStringContainsString('<p>Concluzie: fără leziuni.</p>', $view, 'rendered by the server');
        self::assertStringNotContainsString('older text', $view);

        // The source's text changes: the analysis is from an older one
        $source = $this->storage()->read('library:short2024');
        $this->storage()->save('library:short2024', $source->frontmatter, $source->body . "\nA new line.\n", $source->rev, 'owner');
        self::assertStringContainsString('older text', Kernel::boot($this->config)->handle(new Request('GET', '/library:short2024', cookies: ['reporion' => $cookie]))->body);
    }

    public function testAPageThereThatIsNoAnalysisIsNeverWrittenOver(): void
    {
        $this->storage()->create('library:short2024:source-points', ['title' => 'Mine', 'visibility' => 'private'], "My own notes.\n", 'owner');

        $data = json_decode($this->analyze('editor', 'library:short2024', ['task' => 'points'])->body, true)['data'];

        self::assertNull($data['saved']);
        self::assertSame('Concluzie: fără leziuni.', $data['result'], 'the answer is shown all the same');
        self::assertSame("My own notes.\n", $this->storage()->read('library:short2024:source-points')->body);
    }

    public function testTheAnalysesMoveAndGoToTheTrashWithTheirSource(): void
    {
        $this->analyze('editor', 'library:short2024', ['task' => 'points']);
        $this->analyze('editor', 'library:short2024', ['task' => 'summary']);
        $storage = $this->storage();
        $moves = new \Reporion\Service\PageMoves($storage, new \Reporion\Audit\AuditLog($this->dataRoot . '/audit'), 'library', new \Reporion\Service\Library\Analyses($storage));

        $moves->move('library:short2024', 'library:nobody2024', 'owner');

        self::assertSame("Concluzie: fără leziuni.\n", $storage->read('library:nobody2024:source-points')->body);
        self::assertSame('library:nobody2024:summary', $storage->read('library:nobody2024:summary')->path);
        self::assertSame([], iterator_to_array((function () use ($storage) {
            foreach ($storage->allPaths() as $p) {
                if (str_starts_with($p, 'library:short2024')) {
                    yield $p;
                }
            }
        })(), false), 'nothing left under the old key');

        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');
        $deleted = Kernel::boot($this->config)->handle(new Request('DELETE', '/api/v1/pages/library:nobody2024', cookies: ['reporion' => $cookie]));
        self::assertSame(200, $deleted->status, $deleted->body);
        foreach (['library:nobody2024:source-points', 'library:nobody2024:summary'] as $path) {
            try {
                $storage->read($path);
                self::fail($path . ' went to the trash with its source');
            } catch (\Reporion\Exception\PageNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testASourceThatFitsIsOneRequestAboutItsTextWithoutItsReferenceList(): void
    {
        $response = $this->analyze('editor', 'library:short2024', ['task' => 'points']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertSame('Concluzie: fără leziuni.', $data['result']);
        self::assertSame([1, 1], [$data['parts'], $data['requests']]);
        self::assertSame(1, $this->server->requests());
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('Points of A made-up study (Nobody A. A made-up study. 2024.) in English:', $user);
        self::assertStringContainsString('Grade 3 was the most common in 128 patients.', $user);
        self::assertStringNotContainsString('Cited paper', $user, 'the article\'s own reference list is not analysed');
    }

    public function testALongSourceIsCondensedPartByPartThenAnalysedFromTheNotes(): void
    {
        $response = $this->analyze('editor', 'library:long2024', ['task' => 'points']);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertGreaterThan(1, $data['parts']);
        self::assertSame($data['parts'] + 1, $data['requests']);
        self::assertSame($data['requests'], $this->server->requests(), 'one request a part, then the analysis');
        $last = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('Points of A long made-up study', $last);
        self::assertStringContainsString('Concluzie: fără leziuni.', $last, 'it reads the notes');
        self::assertStringNotContainsString('sentence with a finding', $last, 'not the text itself');
        self::assertContains('source notes (' . $data['parts'] . ' parts)', $data['context']);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertSame($data['requests'], substr_count($audit, '"action":"ai.call"'), 'every request is audited');
    }

    public function testASourceTooLongToBeReadInTheMostPartsIsRefusedBeforeAnyRequest(): void
    {
        $response = $this->analyze('editor', 'library:huge2024', ['task' => 'points']);

        self::assertSame(413, $response->status);
        self::assertSame('too_long', json_decode($response->body, true)['error']['code']);
        self::assertSame(0, $this->server->requests());
    }

    public function testTheProgressIsStreamedPartByPart(): void
    {
        $response = $this->analyze('editor', 'library:long2024', ['task' => 'points', 'stream' => true]);

        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type']);
        ob_start();
        ($response->stream)();
        $out = (string) ob_get_clean();
        preg_match_all('/^event: (\w+)\ndata: (.*)$/m', $out, $events, PREG_SET_ORDER);
        $names = array_column($events, 1);
        self::assertSame('done', end($names));
        $progress = array_filter($events, static fn (array $e): bool => $e[1] === 'progress');
        self::assertGreaterThan(2, \count($progress));
        self::assertSame(['step' => 1, 'steps' => \count($progress)], json_decode(reset($progress)[2], true));
    }

    public function testOnlyAnEditorOfTheLibraryAnalysesAndOnlyASourceWithItsPromptPage(): void
    {
        foreach ([null, 'reader', 'other'] as $user) {
            self::assertSame(404, $this->analyze($user, 'library:short2024', ['task' => 'points'])->status, $user ?? 'anonymous');
        }
        self::assertSame(404, $this->analyze('owner', 'wiki:plain', ['task' => 'points'])->status, 'a page that is no source');
        self::assertSame(422, $this->analyze('editor', 'library:short2024', ['task' => 'definitions'])->status, 'no prompt page for it');
        self::assertSame(422, $this->analyze('editor', 'library:short2024', ['task' => 'bogus'])->status);
        self::assertSame(0, $this->server->requests());

        $this->config['ai']['enabled'] = false;
        self::assertSame(503, $this->analyze('editor', 'library:short2024', ['task' => 'points'])->status);
    }

    public function testTheSourceCardOffersTheAnalysesWhosePromptPagesThereAreToAnEditorOnly(): void
    {
        $view = fn (string $user): string => Kernel::boot($this->config)->handle(new Request('GET', '/library:short2024', cookies: ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)]))->body;

        $editor = $view('editor');
        self::assertStringContainsString('data-island="library-ai"', $editor);
        self::assertStringContainsString('data-lib-ai-task="points"', $editor);
        self::assertStringContainsString('data-lib-ai-task="summary"', $editor);
        self::assertStringNotContainsString('data-lib-ai-task="definitions"', $editor, 'no prompt page: no button');
        self::assertStringNotContainsString('data-ai-summary', $editor, 'the page\'s own Summarize would fail on a long article');
        self::assertStringNotContainsString('data-island="library-ai"', $view('reader'), 'a viewer is not offered the assistant');
    }

    public function testAReadTokenCannotAsk(): void
    {
        $store = new FlatFileUserStore($this->dataRoot);
        [$user, $token] = (new \Reporion\Auth\ApiTokens($store))->create($store->find('editor'), 'script', 'read');
        $store->save($user);

        $response = Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/library/' . $this->pid('library:short2024') . '/analyze', body: '{"task":"points"}', authorization: 'Bearer ' . $token));

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->server->requests());
    }

    public function testTheSummaryIsAskedLikeTheOthers(): void
    {
        $data = json_decode($this->analyze('editor', 'library:short2024', ['task' => 'summary'])->body, true)['data'];

        self::assertSame('Concluzie: fără leziuni.', $data['result']);
        self::assertStringContainsString('Grade 3 was the most common', $this->server->lastRequest()['body']['messages'][1]['content']);
    }

    public function testThePartsAnswersAndTheirNotesAlwaysFitTheirRequests(): void
    {
        foreach ([2, 3, 6] as $n) {
            foreach ([4000, 32000, 128000] as $tokens) {
                $budget = \Reporion\Support\SourceChunks::budget($tokens);
                ['words' => $words, 'noteChars' => $noteChars] = \Reporion\Service\Library\SourceAnalysis::shares($budget, $n);
                self::assertLessThanOrEqual(\Reporion\Service\Library\SourceAnalysis::MAX_PART_WORDS, $words, "{$n} parts of {$tokens}: a part's answer stays inside the reserve");
                self::assertLessThanOrEqual($budget, $n * $noteChars, "{$n} parts of {$tokens}: the notes fit the analysis request");
                self::assertLessThanOrEqual(\Reporion\Support\SourceChunks::RESERVED_TOKENS, (int) ($words * 1.4), 'about 1.4 tokens a word');
            }
        }
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
