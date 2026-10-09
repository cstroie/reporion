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
 * POST /api/v1/ai/complete and GET /api/v1/ai/providers (phase 15c), against
 * the fake OpenAI-compatible server.
 */
final class AiEndpointTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:260927-popescu-ana';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        // No waiting between Assistant's retries (they are counted, not timed)
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'test-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $users->create('ct', 'x', false, [new Grant('reports:ct', GrantRole::Editor)]);
        $storage = $this->storage();
        $storage->create('ai:profiles:reports', ['title' => 'Reports profile', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n", 'owner');
        $storage->create('ai:profiles:reports:system', ['title' => 'System', 'visibility' => 'private'], "Ești radiolog.\n", 'owner');
        $storage->create('ai:profiles:reports:conclusion', ['title' => 'Conclusion', 'visibility' => 'private'], "<raport>\n{text}\n</raport>\nScrie concluzia.\n", 'owner');
        $storage->create(self::PATH, ['title' => 'POPESCU Ana', 'visibility' => 'private', 'patient' => ['name' => 'POPESCU Ana', 'cnp' => '2800115123458']], "# POPESCU Ana\n\n## IRM genunchi\n\nText.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testOnlyAWriterOfThePageMayAsk(): void
    {
        $body = ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'];
        self::assertSame(404, $this->call(null, $body)->status);
        self::assertSame(404, $this->call('ana', $body)->status, 'a viewer');
        self::assertSame(404, $this->call('ct', $body)->status, 'no grant here');
        self::assertSame(404, $this->call('mihai', ['action' => 'nope'] + $body)->status, 'an unknown action');
        self::assertSame(200, $this->call('mihai', $body)->status);
    }

    public function testTheAnswerComesBackAndOnlyDeIdentifiedTextWentOut(): void
    {
        $response = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => "# POPESCU Ana\n\nPacienta Popescu, CNP 2800115123458: menisc fisurat.", 'label' => 'exam 1', 'exam' => 1]);

        self::assertSame(200, $response->status);
        $json = json_decode($response->body, true);
        self::assertSame('Concluzie: fără leziuni.', $json['result']);
        self::assertSame(['exam 1', 'no patient identifiers'], $json['context']);
        self::assertSame(['prompt_tokens' => 42, 'completion_tokens' => 7], $json['usage']);

        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('menisc fisurat', $sent);
        foreach (['Popescu', 'POPESCU', '2800115123458', 'Ana', 'reports:'] as $identifier) {
            self::assertStringNotContainsString($identifier, $sent, $identifier);
        }
        self::assertStringContainsString('Ești radiolog.', $sent, 'the profile\'s system prompt');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"ai.call"', $audit);
        self::assertStringContainsString('"ai_action":"conclusion"', $audit);
        self::assertStringNotContainsString('menisc', $audit, 'never the text');
        self::assertStringNotContainsString('Concluzie', $audit, 'never the answer');
    }

    public function testEvolutionWithNoOtherReportAsksNoModelAndWithOneSendsItDated(): void
    {
        $this->storage()->create('ai:profiles:reports:evolution', ['title' => 'Evolution', 'visibility' => 'private'], "Data: {current_date}\n{text}\n<istoric>\n{history}\n</istoric>\nWrite in {language}.\n", 'owner');
        $body = ['path' => self::PATH, 'action' => 'evolution', 'text' => 'Menisc fisurat.'];

        $alone = json_decode($this->call('mihai', $body)->body, true);
        self::assertSame('Date imagistice insuficiente pentru evaluarea evoluției.', $alone['result']);
        self::assertSame(['text', 'no priors'], $alone['context']);
        self::assertNull($this->server->lastRequest()['body'], 'nothing went to the model');

        $this->storage()->create('reports:mri:mioveni:250310-popescu-ana', ['title' => 'POPESCU Ana', 'visibility' => 'private', 'study_date' => '2025-03-10', 'patient' => ['name' => 'POPESCU Ana', 'cnp' => '2800115123458']], "# POPESCU Ana\n\n## IRM genunchi\n\nMenisc intact.\n", 'owner');
        $compared = json_decode($this->call('mihai', $body)->body, true);
        self::assertSame('Concluzie: fără leziuni.', $compared['result']);
        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('<report date=\"2025-03-10\" exam=', $sent, 'each prior carries its date');
        self::assertStringContainsString('Menisc intact.', $sent);
        self::assertStringContainsString('Write in Romanian.', $sent);
    }

    public function testEvolutionWithOneStudySendsOnlyThatOneAndNeverAnotherPatients(): void
    {
        $this->storage()->create('ai:profiles:reports:evolution', ['title' => 'Evolution', 'visibility' => 'private'], "{text}\n<istoric>\n{history}\n</istoric>\n", 'owner');
        $patient = ['name' => 'POPESCU Ana', 'cnp' => '2800115123458'];
        $this->storage()->create('reports:mri:mioveni:250310-popescu-ana', ['title' => 'POPESCU Ana', 'visibility' => 'private', 'study_date' => '2025-03-10', 'patient' => $patient], "# POPESCU Ana\n\n## IRM genunchi\n\nMenisc intact.\n", 'owner');
        $older = $this->storage()->create('reports:mri:mioveni:240110-popescu-ana', ['title' => 'POPESCU Ana', 'visibility' => 'private', 'study_date' => '2024-01-10', 'patient' => $patient], "# POPESCU Ana\n\n## IRM genunchi\n\nVechi.\n", 'owner');
        $stranger = $this->storage()->create('reports:mri:mioveni:250310-ionescu-ion', ['title' => 'IONESCU Ion', 'visibility' => 'private', 'study_date' => '2025-03-10', 'patient' => ['name' => 'IONESCU Ion', 'cnp' => '1700101123451']], "# IONESCU Ion\n\n## IRM\n\nStrain.\n", 'owner');
        $body = ['path' => self::PATH, 'action' => 'evolution', 'source' => 'page'];

        $alone = json_decode($this->call('mihai', $body + ['with' => $stranger->pid])->body, true);
        self::assertSame(['text', 'no priors'], $alone['context'], 'another patient\'s report counts as none');
        self::assertNull($this->server->lastRequest()['body'], 'nothing went to the model');

        $json = json_decode($this->call('mihai', $body + ['with' => $older->pid])->body, true);
        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Vechi.', $sent);
        self::assertStringNotContainsString('Menisc intact.', $sent, 'only the study asked for');
        self::assertStringNotContainsString('Strain.', $sent);
        self::assertContains('1 priors', $json['context']);
    }

    public function testTagsAreOfferedOnAnUnsignedReportAndTheAnswerComesBackAsAList(): void
    {
        $view = fn (): string => Kernel::boot($this->config)->handle(new Request('GET', '/' . self::PATH, cookies: ['reporion' => $this->cookie('mihai')]))->body;
        self::assertStringNotContainsString('data-ai-tags', $view(), 'no tags prompt page, no button');

        $this->storage()->create('ai:profiles:reports:tags', ['title' => 'Tags', 'visibility' => 'private'], "<report>\n{text}\n</report>\n<vocabulary>{vocabulary}</vocabulary>\nTags.\n", 'owner');
        self::assertMatchesRegularExpression('/data-ai-tags-again hidden>.*Again<\/button><button[^>]*data-ai-tags-apply/', $view(), 'Again before Save');

        file_put_contents($this->dataRoot . '/tags.yaml', "fractura:\n  synonyms: [fracturi]\nadenopatie:\n  synonyms: [adenopatii]\n");
        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'tags';
        $json = json_decode($this->call('mihai', ['path' => self::PATH, 'action' => 'tags', 'source' => 'page'])->body, true);
        self::assertSame(['irm', 'genunchi', 'fractura', 'menisc'], $json['tags'], 'parsed, de-duplicated, the dictionary\'s spelling');
        $sent = $this->server->lastRequest()['body'];
        self::assertArrayNotHasKey('max_tokens', $sent, 'no cap of its own: a reasoning model would spend it thinking');
        self::assertStringContainsString("## IRM genunchi\n\nText.", $sent['messages'][1]['content'], 'the whole report, not its conclusion');
        self::assertStringContainsString('<vocabulary>adenopatie, fractura</vocabulary>', $sent['messages'][1]['content'], 'the dictionary\'s tags, not their synonyms');
        self::assertContains('2 vocabulary tags', $json['context']);
    }

    public function testALiteActionIsSentWithoutTheSystemPrompt(): void
    {
        $this->storage()->create('ai:profiles:reports:grammar', ['title' => 'Grammar', 'visibility' => 'private', 'model' => 'lite'], "Corectează: {text}\n", 'owner');
        $this->storage()->save('ai:profiles:reports', ['title' => 'Reports profile', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n| grammar | Grammar | Fix | pen | replace |\n", 1, 'owner');

        self::assertSame(200, $this->call('mihai', ['path' => self::PATH, 'action' => 'grammar', 'text' => 'Text.'])->status);
        self::assertSame(['user'], array_column($this->server->lastRequest()['body']['messages'], 'role'));

        $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'Text.']);
        self::assertSame(['system', 'user'], array_column($this->server->lastRequest()['body']['messages'], 'role'), 'normal keeps it');
    }

    public function testTheAnswerStreamsAsServerSentEvents(): void
    {
        $response = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'Text.', 'stream' => true]);

        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type']);
        ob_start();
        ($response->stream)();
        $out = (string) ob_get_clean();
        preg_match_all('/^event: (\w+)\ndata: (.*)$/m', $out, $events, PREG_SET_ORDER);
        $deltas = array_map(static fn (array $e): string => json_decode($e[2], true)['text'], array_filter($events, static fn (array $e): bool => $e[1] === 'delta'));
        self::assertSame('Concluzie: fără leziuni.', implode('', $deltas));
        self::assertGreaterThan(1, \count($deltas));
        self::assertSame('done', end($events)[1]);
    }

    public function testAProviderFailureIsAReasonAndNotConfiguredIsSaid(): void
    {
        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'fail-401';
        $failed = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x']);
        self::assertSame(502, $failed->status);
        self::assertSame('unauthorized', json_decode($failed->body, true)['error']['code']);

        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'fail-429';
        $limited = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x']);
        self::assertSame(429, $limited->status);
        self::assertSame('rate_limited', json_decode($limited->body, true)['error']['code']);
        self::assertStringContainsString('"reason":"rate_limited","status":429', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), 'the audit says which');

        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'fail-400';
        $refused = json_decode($this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'])->body, true)['error'];
        self::assertSame('provider_error', $refused['code']);
        self::assertStringContainsString('(HTTP 400: `temperature` and `top_p` cannot both be specified', $refused['message'], 'the user sees why');
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"reason":"provider_error","status":400', $audit);
        self::assertStringNotContainsString('cannot both', $audit, 'the server\'s words are not audited');

        $streamed = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x', 'stream' => true]);
        ob_start();
        ($streamed->stream)();
        self::assertMatchesRegularExpression('/^event: error\ndata: .*"code":"provider_error".*\(HTTP 400: `temperature`/m', (string) ob_get_clean(), 'the editor\'s stream says why too');

        $this->config['ai']['enabled'] = false;
        self::assertSame(503, $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'])->status);
    }

    public function testTheSummaryIsOnePhraseFromEveryConclusionUnderItsExam(): void
    {
        $this->storage()->create('ai:profiles:reports:summary', ['title' => 'Summary', 'visibility' => 'private'], "<concluzie>\n{text}\n</concluzie>\nRezumă.\n", 'owner');
        $path = 'reports:mri:mioveni:260903-popescu-ana';
        $this->storage()->create($path, ['title' => 'POPESCU Ana', 'visibility' => 'private', 'patient' => ['name' => 'POPESCU Ana', 'cnp' => '2800115123458'],
            'exams' => [['title' => 'IRM Genunchi Drept', 'modality' => ['MR']], ['title' => 'IRM Genunchi Stâng', 'modality' => ['MR']]]],
            "# POPESCU Ana\n\n## IRM Genunchi Drept\n\nText.\n\n### Concluzii\n\nDegenerare menisc medial drept grad IIc.\n\n## IRM Genunchi Stâng\n\nText.\n\n### Concluzii\n\nModificare de semnal menisc medial stâng grad IIc.\n", 'owner');

        $json = json_decode($this->call('mihai', ['path' => $path, 'action' => 'summary', 'source' => 'page'])->body, true);

        self::assertSame('Concluzie: fără leziuni.', $json['result'], 'the model\'s phrase, nothing glued to it');
        $sent = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString("IRM Genunchi Drept:\nDegenerare menisc medial drept grad IIc.\n\nIRM Genunchi Stâng:\nModificare de semnal menisc medial stâng grad IIc.", $sent, 'one call, both conclusions under their exams');
        self::assertStringNotContainsString('Text.', $sent, 'the conclusions, not the descriptions');
        self::assertSame(1, substr_count((string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), '"ai_action":"summary"'), 'one call');
    }

    public function testAFailedCallIsTriedAgainAndEachTryIsAudited(): void
    {
        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'flaky-500';
        $response = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x']);

        self::assertSame(200, $response->status, 'the third try answers');
        self::assertSame('Concluzie: fără leziuni.', json_decode($response->body, true)['result']);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertSame(2, substr_count($audit, '"reason":"provider_error","status":500'), 'the two failures');
        self::assertStringContainsString('"attempt":3', $audit);
    }

    public function testAnAlwaysFailingServerIsTriedFourTimesThenTheErrorStands(): void
    {
        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'fail-401';
        self::assertSame(502, $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'])->status);
        self::assertSame(4, substr_count((string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), '"reason":"unauthorized"'), 'once and three retries');
    }

    public function testAnAnswerAlreadyStreamedInPartIsNotAskedAgain(): void
    {
        $this->config['ai']['servers'][0]['tiers']['normal']['model'] = 'fail-mid-stream';
        $streamed = $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x', 'stream' => true]);
        ob_start();
        ($streamed->stream)();
        $out = (string) ob_get_clean();

        self::assertSame(1, substr_count($out, 'Concluzie: '), 'the part sent once, no second answer after it');
        self::assertStringContainsString('event: error', $out);
    }

    public function testBusyIsTriedAgainAndAnswersOnceTheOtherRequestEnds(): void
    {
        $lockDir = $this->dataRoot . '/ai';
        @mkdir($lockDir, 0775, true);
        $other = fopen($lockDir . '/mihai.lock', 'c');
        self::assertTrue(flock($other, LOCK_EX | LOCK_NB));
        $this->config['ai']['retry_delays'] = [0, 0, 0];
        self::assertSame('busy', json_decode($this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'])->body, true)['error']['code'], 'still held after every retry');

        flock($other, LOCK_UN);
        fclose($other);
        self::assertSame(200, $this->call('mihai', ['path' => self::PATH, 'action' => 'conclusion', 'text' => 'x'])->status);
    }

    public function testProvidersSaysWhereTheAssistantGoes(): void
    {
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/ai/providers'))->status);
        $json = json_decode(Kernel::boot($this->config)->handle(new Request('GET', '/api/v1/ai/providers', cookies: ['reporion' => $this->cookie('mihai')]))->body, true);

        self::assertSame([['type' => 'openai-compatible', 'host' => '127.0.0.1', 'model' => 'test-model', 'external' => false]], $json['data']);
    }

    public function testTheEditorShowsTheRailOnlyWhenTheAssistantHasActionsHere(): void
    {
        $editor = $this->page('mihai', '/' . self::PATH . '/edit')->body;
        self::assertStringContainsString('<aside class="wk-ai wk-rail" id="editor-ai"', $editor);
        self::assertStringContainsString('data-ai-action="conclusion"', $editor);
        self::assertStringContainsString('127.0.0.1 · test-model · on this network · audit logged', $editor);
        self::assertStringContainsString('title="127.0.0.1 · test-model">Server 1</span>', $editor, 'the rail head names the server in use');
        self::assertStringContainsString('js/editor-ai.js', $editor);

        $this->storage()->create('ai:profiles:reports:quality', ['title' => 'Quality', 'visibility' => 'private'], "{text}\n", 'owner');
        $this->storage()->save('ai:profiles:reports', ['title' => 'Reports profile', 'visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n| --- | | | | |\n| quality | Quality | Check | check | show |\n", 1, 'owner');
        self::assertMatchesRegularExpression('/data-ai-action="conclusion".*\n<hr class="wk-ai-sep"><button[^>]*data-ai-action="quality"/', $this->page('mihai', '/' . self::PATH . '/edit')->body, 'a line between the sections');

        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "Text.\n", 'owner');
        self::assertStringNotContainsString('wk-ai', $this->page('owner', '/docs:note/edit')->body, 'no profile for docs');

        $this->config['ai']['enabled'] = false;
        self::assertStringNotContainsString('id="editor-ai"', $this->page('mihai', '/' . self::PATH . '/edit')->body, 'hidden while off (D15)');
    }

    public function testASaveSaysWhatTheAssistantProposed(): void
    {
        $document = "---\ntitle: 'POPESCU Ana'\nvisibility: private\npatient:\n  name: 'POPESCU Ana'\n---\n\n# POPESCU Ana\n\nText.\n\n### Concluzii\n\nFără leziuni.\n";
        $response = Kernel::boot($this->config)->handle(new Request('POST', '/' . self::PATH . '/edit', cookies: ['reporion' => $this->cookie('mihai')], body: http_build_query(['document' => $document, 'base_rev' => '1', 'note' => 'corectat', 'ai_assisted' => 'conclusion,conclusion,<bad>'])));

        self::assertSame(302, $response->status);
        self::assertSame('corectat · assisted: conclusion', $this->storage()->read(self::PATH)->meta['revlog'][1]['note']);
        self::assertStringContainsString('"assisted":["conclusion"]', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    private function page(string $user, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $this->cookie($user)]));
    }

    /** @param array<string, mixed> $body */
    private function call(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ai/complete', cookies: $user !== null ? ['reporion' => $this->cookie($user)] : [], body: (string) json_encode($body)));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
