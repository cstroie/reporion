<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Exception\LibraryException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Library\Studio;
use Reporion\Storage\FileTree;
use Reporion\Support\TemplateDraft;
use Reporion\Tests\Http\HttpTestCase;

/** Studio (docs/library.md, phase 6) against the fake OpenAI-compatible server; the articles are made up */
final class StudioTest extends HttpTestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'fallback_profile' => 'wiki', 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'ask-expand']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('writer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Editor), new Grant('templates', GrantRole::Editor)]);
        $users->create('libreader', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('wiki', GrantRole::Editor), new Grant('library', GrantRole::Viewer)]);
        $storage = $this->storage();
        $prompt = static fn (string $id, string $text) => $storage->create('ai:profiles:wiki:special:' . $id, ['title' => $id, 'format' => 'text', 'visibility' => 'private'], $text, 'owner');
        $prompt('studio-overview', "OVERVIEW of {title}\n{sources}\nin {language}\n");
        $prompt('studio-compare', "STUDIO-ROW\n{sources}\n{questions}\n({count})\n");
        $prompt('studio-check', "CHECK\n<page>{page}</page>\n{sources}\n");
        $prompt('studio-template', "STUDIO-TEMPLATE\n{sources}\n");
        $prompt('studio-flashcards', "STUDIO-CARDS\n{sources}\n");
        $prompt('studio-audio', "STUDIO-AUDIO\n{sources}\nin {language}\n");
        $prompt('source-part', "PART {part} of {title}: {source} in {words} words, {language}\n");
        $csl = ['type' => 'article-journal', 'title' => 'A made-up study', 'issued' => ['date-parts' => [[2020]]]];
        $storage->create('library:abel2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => $csl], "*— p. 4 —*\n\nWhite matter lesions were graded in 128 patients.\n\n## References\n\n```text\n1. Cited elsewhere.\n```\n", 'owner');
        $long = '';
        foreach (range(1, 6) as $page) {
            $long .= "*— p. {$page} —*\n\n" . str_repeat("Page {$page} filler sentence about nothing. ", 20) . ($page === 5 ? "\n\nWhite matter lesions graded with Fazekas.\n\n" : "\n\n");
        }
        $storage->create('library:long2021', ['title' => 'A long made-up study', 'visibility' => 'private', 'csl' => $csl], $long, 'owner');
        $storage->create('library:record2019', ['title' => 'A record with no file', 'visibility' => 'private', 'csl' => $csl], '', 'owner');
        $storage->create('wiki:notebook', ['title' => 'White matter', 'visibility' => 'private', 'language' => 'ro'], "# White matter\n\nLesions are graded with Fazekas [@abel2020] [@nosuch2000].\n", 'owner');
        $storage->create('wiki:two', ['title' => 'Two', 'visibility' => 'private', 'sources' => ['long2021', 'record2019']], "# Two\n\nNotes [@abel2020].\n", 'owner');
        $storage->create('wiki:patient', ['title' => 'P', 'visibility' => 'private', 'patient' => ['name' => 'X Y']], "Cites [@abel2020].\n", 'owner');
        $storage->create('wiki:plain', ['title' => 'Plain', 'visibility' => 'private'], "No citations.\n", 'owner');
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
    private function studio(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/notebook/studio', cookies: $user === null ? [] : ['reporion' => $this->cookie($user)], body: (string) json_encode($body)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->status, $response->body);

        return json_decode($response->body, true)['data'];
    }

    public function testASmallNotebookGoesWholeInOneRequest(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'overview']));

        self::assertSame('overview', $data['task']);
        self::assertTrue($data['whole']);
        self::assertSame(1, $this->server->requests());
        self::assertSame('Concluzie: fără leziuni.', $data['result']);
        self::assertSame(['abel2020'], array_column($data['sources'], 'key'), 'a key no source has is not a source');
        self::assertSame('/library:abel2020', $data['sources'][0]['href']);
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('OVERVIEW of White matter', $user);
        self::assertStringContainsString('<source id="1" kind="notebook" title="White matter">', $user);
        self::assertStringContainsString('<source id="2" key="abel2020" title="A made-up study">', $user);
        self::assertStringNotContainsString('Cited elsewhere', $user, 'the article\'s reference list is not sent');
        self::assertStringContainsString('in Romanian', $user);
    }

    public function testEachStudioTextIsKeptBesideTheNotebookAndListedInItsPanel(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'overview']));

        self::assertSame('wiki:notebook:studio-overview', $data['saved']['path']);
        $kept = $this->storage()->read('wiki:notebook:studio-overview');
        self::assertSame('ro', $kept->frontmatter['language'], 'it reads as the notebook reads');
        self::assertSame('studio-overview', $kept->frontmatter['analysis']['prompt']);

        $html = Kernel::boot($this->config)->handle(new Request('GET', '/wiki:notebook', cookies: ['reporion' => $this->cookie('writer')]))->body;
        self::assertStringContainsString('data-saved-prompt="studio-overview"', $html);

        // A kept text that cites sources is no notebook of its own: no Studio, no Ask there
        $this->storage()->save('wiki:notebook:studio-overview', $kept->frontmatter, "Graded [@abel2020].\n", $kept->rev, 'writer');
        $own = Kernel::boot($this->config)->handle(new Request('GET', '/wiki:notebook:studio-overview', cookies: ['reporion' => $this->cookie('writer')]))->body;
        self::assertStringNotContainsString('data-island="notebook-studio"', $own);
        self::assertSame(404, $this->studio('writer', ['path' => 'wiki:notebook:studio-overview', 'task' => 'overview'])->status);
    }

    public function testFlashcardsAreKeptAndDownloadedForAnki(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'flashcards']));
        $kept = $this->storage()->read((string) $data['saved']['path']);
        $download = fn (?string $user, string $pid): Response => Kernel::boot($this->config)->handle(new Request('GET', '/library/flashcards/' . $pid, cookies: $user === null ? [] : ['reporion' => $this->cookie($user)]));

        $file = $download('writer', $kept->pid);

        self::assertSame(200, $file->status, $file->body);
        self::assertSame('attachment; filename="notebook-flashcards.txt"', $file->headers['Content-Disposition']);
        self::assertStringStartsWith("#separator:tab\n#html:true\n#notetype:Basic\n#tags:reporion notebook\n", $file->body);
        self::assertStringContainsString("What grades does Fazekas give?\t0 to 3 (A made-up study, 2020, p. 4)\n", $file->body);
        self::assertStringContainsString('data-saved-prompt="studio-flashcards"', Kernel::boot($this->config)->handle(new Request('GET', '/wiki:notebook', cookies: ['reporion' => $this->cookie('writer')]))->body);
        self::assertStringContainsString('"format":"anki"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));

        self::assertSame(404, $download(null, $kept->pid)->status);
        self::assertSame(404, $download('writer', $this->storage()->read('wiki:notebook')->pid)->status, 'not a flashcards page');
    }

    public function testTheAudioOverviewIsTheScriptReadAloudByTwoVoicesAndKept(): void
    {
        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'audio'])->status, 'no speech model: no audio overview');
        $this->config['ai'] += ['speech_server' => 1, 'speech_model' => 'tts-test', 'speech_voices' => 'host-a, host-b'];
        $this->config['library']['tools']['ffmpeg'] = '/nonexistent/ffmpeg';

        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'audio']));

        $speech = $this->server->speech();
        self::assertSame(['host-a', 'host-b', 'host-a'], array_column($speech, 'voice'), 'one request a turn, a host\'s turns joined');
        self::assertSame('How are they graded? And in whom?', $speech[1]['input'], 'no citation, no markdown read aloud');
        self::assertSame(['tts-test', 'wav'], [$speech[0]['model'], $speech[0]['response_format']]);
        self::assertSame([], $data['notices']);
        $kept = $this->storage()->read('wiki:notebook:studio-audio');
        self::assertSame(['wav', 3, 1], [$kept->frontmatter['audio']['ext'], $kept->frontmatter['audio']['turns'], $kept->frontmatter['audio']['seconds']], 'three turns and two gaps: about a second');
        self::assertSame('/library/audio/' . $kept->pid, $data['audio']['href']);

        $get = fn (?string $user, string $range = ''): Response => Kernel::boot($this->config)->handle(new Request('GET', '/library/audio/' . $kept->pid, cookies: $user === null ? [] : ['reporion' => $this->cookie($user)], range: $range));
        $whole = $get('writer');
        self::assertSame([200, 'audio/wav', 'bytes'], [$whole->status, $whole->headers['Content-Type'], $whole->headers['Accept-Ranges']]);
        self::assertStringStartsWith('RIFF', $whole->body);
        self::assertSame(\strlen($whole->body) - 8, unpack('V', substr($whole->body, 4, 4))[1], 'one header for the whole');
        $part = $get('writer', 'bytes=0-9');
        self::assertSame([206, 'RIFF', 'bytes 0-9/' . \strlen($whole->body)], [$part->status, substr($part->body, 0, 4), $part->headers['Content-Range']]);
        self::assertSame(10, \strlen($part->body));
        self::assertSame(404, $get(null)->status);

        $view = Kernel::boot($this->config)->handle(new Request('GET', '/wiki:notebook', cookies: ['reporion' => $this->cookie('writer')]))->body;
        self::assertStringContainsString('data-studio-task="audio"', $view);
        self::assertStringContainsString('<audio controls preload="none" src="/library/audio/' . $kept->pid . '"></audio>', $view);
    }

    public function testAudioThatDoesNotJoinKeepsTheScriptAndSaysSo(): void
    {
        $this->config['ai'] += ['speech_server' => 1, 'speech_model' => 'tts-test', 'speech_voices' => 'host-a, stereo'];
        $this->config['library']['tools']['ffmpeg'] = '/nonexistent/ffmpeg';

        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'audio']));

        self::assertNull($data['audio']);
        self::assertStringStartsWith('The script is kept, but it could not be read aloud', $data['notices'][0]);
        self::assertArrayNotHasKey('audio', $this->storage()->read('wiki:notebook:studio-audio')->frontmatter);
    }

    public function testALargeNotebookIsCondensedSourceBySourceAndKeepsTheKeys(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];

        $data = $this->data($this->studio('writer', ['path' => 'wiki:two', 'task' => 'overview']));

        self::assertFalse($data['whole']);
        self::assertSame($this->server->requests(), $data['requests']);
        self::assertGreaterThan(2, $data['requests'], 'the long source in parts, then the overview');
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('OVERVIEW', $user);
        self::assertStringContainsString('key="long2021"', $user, 'the notes stay under their source');
        self::assertStringContainsString('key="abel2020"', $user);
        self::assertStringContainsString('White matter lesions were graded', $user, 'a short source goes as it is');
        self::assertStringNotContainsString('filler sentence', $user, 'the long one as notes only');
    }

    public function testSourcesToCondenseWithoutTheirPromptAreRefusedBeforeAnyRequest(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];
        $this->storage()->delete('ai:profiles:wiki:special:source-part', 'owner');

        foreach (['overview', 'compare'] as $task) {
            $response = $this->studio('writer', ['path' => 'wiki:two', 'task' => $task, 'questions' => 'Design']);
            self::assertSame(503, $response->status, $task . ': ' . $response->body);
        }
        self::assertSame(0, $this->server->requests(), 'not even the short source\'s row');
    }

    public function testTooManyRequestsAreRefusedBeforeAnyIsMade(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];
        $this->storage()->create('library:huge2022', ['title' => 'Huge', 'visibility' => 'private', 'csl' => ['title' => 'Huge']], str_repeat("A sentence of a very long made-up article.\n\n", 1500), 'owner');
        $this->storage()->create('wiki:huge', ['title' => 'Huge', 'visibility' => 'private'], "Cites [@huge2022].\n", 'owner');

        $response = $this->studio('writer', ['path' => 'wiki:huge', 'task' => 'overview']);

        self::assertSame(413, $response->status, $response->body);
        self::assertSame(0, $this->server->requests());
    }

    public function testCompareAsksEachSourceItsRowAndBuildsTheTable(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:two', 'task' => 'compare', 'questions' => "Design\nNumber of patients\n\n"]));

        self::assertSame(2, $this->server->requests(), 'one row per source with text; the record with no file asks nothing');
        $lines = explode("\n", $data['result']);
        self::assertSame('| Sursă | Design | Number of patients |', $lines[0], 'the notebook is in Romanian');
        self::assertSame('| [@abel2020] | Prospective / cohort [@abel2020, p. 4] | 128 |', $lines[2], 'a citation of another source taken out, a | out of a cell');
        self::assertSame('| [@long2021] | Prospective / cohort | 128 |', $lines[3], 'a row cites only its own source');
        self::assertSame('| [@record2019] | – | – |', $lines[4]);
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString("1. Design\n2. Number of patients\n(2)", $user);

        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:two', 'task' => 'compare', 'questions' => ''])->status);
        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:two', 'task' => 'compare', 'questions' => str_repeat("Q\n", 9)])->status);
    }

    public function testCheckSendsThePageAndTheSources(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'check']));

        self::assertSame(1, $this->server->requests());
        self::assertSame('check', $data['task']);
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('<page># White matter', $user);
        self::assertStringContainsString('key="abel2020"', $user);
        self::assertStringNotContainsString('kind="notebook"', $user, 'the page is checked, not a source');
    }

    public function testCheckOfALargeNotebookSendsThePassagesHoldingThePagesWords(): void
    {
        $this->config['library'] = ['context_tokens' => 4000];
        $this->storage()->save('wiki:two', ['title' => 'Two', 'visibility' => 'private', 'sources' => ['long2021']], "# Fazekas\n\nLesions are graded with Fazekas [@abel2020].\n", 1, 'owner');

        $data = $this->data($this->studio('writer', ['path' => 'wiki:two', 'task' => 'check']));

        self::assertFalse($data['whole']);
        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('page="5"', $user, 'the passage with the page\'s words, with its page');
        self::assertStringNotContainsString('Page 2 filler', $user);
    }

    public function testTheTemplateComesWithItsChecklistAndNoCitation(): void
    {
        $data = $this->data($this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'template']));

        $template = $data['template'];
        self::assertSame("# Art: Substanța albă\n## IRM cerebral\n### Tehnica\nSecvențe [T2 / FLAIR].", $template['body']);
        self::assertSame(['# Leziuni', 'Grad Fazekas | fazekas, grad', 'Număr de leziuni'], $template['checklist']);
        self::assertSame('wiki:notebook', $template['reference']);
        self::assertSame([], $template['problems']);
        self::assertSame(0, $data['dropped'], 'a template\'s citations are taken out on purpose, not reported');

        // The writer makes the page with the existing endpoint (D8)
        $made = Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/pages', cookies: ['reporion' => $this->cookie('writer')], body: (string) json_encode([
            'path' => 'templates:mri:substanta-alba', 'meta' => ['visibility' => 'private', 'reference' => $template['reference'], 'checklist' => $template['checklist']], 'body' => $template['body'], 'note' => 'assisted: studio-template',
        ])));
        self::assertSame(201, $made->status, $made->body);
        $audit = (string) file_get_contents((glob($this->dataRoot . '/audit/*.ndjson') ?: [''])[0]);
        self::assertMatchesRegularExpression('/"page\.create".*"assisted":\["studio-template"\]/', $audit, 'the audit line says the assistant proposed it (D8)');
        $page = $this->storage()->read('templates:mri:substanta-alba');
        self::assertSame('wiki:notebook', $page->frontmatter['reference']);
        self::assertSame($template['checklist'], $page->frontmatter['checklist']);
    }

    public function testStreamedAnswersEndWithDone(): void
    {
        $response = $this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'overview', 'stream' => true]);
        ob_start();
        ($response->stream)();
        $out = (string) ob_get_clean();

        self::assertStringContainsString("event: progress\ndata: {\"step\":1,\"steps\":1}", $out);
        self::assertStringContainsString('event: done', $out);
        self::assertStringContainsString('Concluzie: fără leziuni.', $out);
    }

    public function testOnlyAnEditorOfTheNotebookAndOfTheLibraryUsesItAndNeverOnAPatient(): void
    {
        foreach ([null, 'libreader'] as $user) {
            self::assertSame(404, $this->studio($user, ['path' => 'wiki:notebook', 'task' => 'overview'])->status, $user ?? 'anonymous');
        }
        self::assertSame(404, $this->studio('owner', ['path' => 'wiki:patient', 'task' => 'overview'])->status);
        self::assertSame(404, $this->studio('owner', ['path' => 'reports:mri:x:260101-a-b', 'task' => 'overview'])->status);
        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'glossary'])->status, 'no prompt page for it');
        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:notebook', 'task' => 'nonsense'])->status);
        self::assertSame(422, $this->studio('writer', ['path' => 'wiki:plain', 'task' => 'overview'])->status, 'no source');
        self::assertSame(0, $this->server->requests());
    }

    public function testThePageViewOffersTheTasksWithAPromptToAnEditorOfTheLibrary(): void
    {
        $view = fn (string $user): string => Kernel::boot($this->config)->handle(new Request('GET', '/wiki:notebook', cookies: ['reporion' => $this->cookie($user)]))->body;

        $html = $view('writer');
        self::assertStringContainsString('data-island="notebook-studio"', $html);
        self::assertStringContainsString('data-studio-task="overview"', $html);
        self::assertStringContainsString('data-studio-task="template"', $html);
        self::assertStringNotContainsString('data-studio-task="glossary"', $html);
        self::assertStringNotContainsString('data-island="notebook-studio"', $view('libreader'));
    }

    public function testQuestionsCellsAndTable(): void
    {
        self::assertSame(['A', 'B c'], Studio::questions("  A \n\n B   c\n"));
        self::assertSame(['A'], Studio::questions(['A', '', 3]));
        self::assertSame(['yes', 'two lines', '–'], Studio::cells("Intro\n1. yes\n**2.** two\nlines\n7. out of range", 3));
        self::assertSame("| Source | A/B |\n| --- | --- |\n| [@k] | x |", Studio::table(['A|B'], [['k', ['x']]], 'en'));
        self::assertStringStartsWith('| Sursă |', Studio::table(['A'], [['k', ['x']]], null), 'a page with no language is Romanian');
        $this->expectException(LibraryException::class);
        Studio::questions([str_repeat('a', Studio::MAX_QUESTION + 1)]);
    }

    public function testATemplateDraftWithoutAChecklistOrInTheWrongShape(): void
    {
        $draft = TemplateDraft::parse("### Findings\nNormal — nothing.\n");

        self::assertSame("### Findings\nNormal — nothing.", $draft['body']);
        self::assertSame([], $draft['checklist']);
        self::assertContains('shape.name.missing', array_column($draft['problems'], 'code'));
        self::assertContains('dash', array_column($draft['problems'], 'code'));
    }
}
