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
use Reporion\Service\Ai\Action;
use Reporion\Service\Ai\Context;
use Reporion\Service\ArchiveAsk;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;

/**
 * Ask the archive's three additions (D53): reports found by meaning as well
 * as by words (their conclusions' vectors, fused by rank), the `ask-expand`
 * step (a standalone question and the words a report would use), and
 * follow-up questions (the browser's earlier turns, de-identified before any
 * model). Fixture data is fictitious (invariant 10); vectors are made up, unit
 * length, so what is near is known.
 */
final class AskHybridTest extends HttpTestCase
{
    private FakeServer $server;

    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = [
            'enabled' => true,
            'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model'], 'lite' => ['model' => 'ask-expand']]]],
            'embed_server' => 1, 'embed_model' => 'embed-test', 'embed_min_score' => 0.5,
            'retry_delays' => [0, 0, 0],
        ];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $this->index = new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new FlatFile($this->dataRoot, $this->index);
        $storage->create('ai:profiles:reports:special:ask-archive', ['visibility' => 'private'], "<question>{question}</question>\n{sources}\nRăspunde citând sursele.\n", 'owner');
        $storage->create('ai:profiles:reports:special:ask-expand', ['visibility' => 'private'], "{history}\n<question>{question}</question>\nScrie întrebarea completă, apoi cuvintele.\n", 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    /** @param list<float> $vector */
    private function report(string $path, string $body, array $vector, string $status = 'archived', string $by = 'owner', array $extra = []): string
    {
        $storage = new FlatFile($this->dataRoot, $this->index);
        $record = $storage->create($path, ['visibility' => 'private', 'status' => $status, 'modality' => ['MR'], 'study_date' => '2026-01-10', 'patient' => ['name' => 'TEST Ask']] + $extra, $body, $by);
        $unit = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $vector)));
        $this->index->putVector($record->pid, 'embed-test', sha1($path), array_map(static fn (float $x): float => $x / $unit, $vector));

        return $record->pid;
    }

    private function body(string $conclusion, string $description = 'Descriere fără legătură.'): string
    {
        return "# TEST Ask\n\n## IRM cerebral\n\n### Descriere\n\n{$description}\n\n### Concluzii\n\n{$conclusion}\n";
    }

    public function testReportsAreFoundByMeaningWhenNoWordMatches(): void
    {
        $near = $this->report('reports:mri:mioveni:260110-test-near', $this->body('Aspect compatibil cu sechele ischemice vechi, fără semne de acutizare.'), [1.0, 0.0, 0.0, 0.0]);
        $this->report('reports:mri:mioveni:260111-test-far', $this->body('Sinus maxilar drept cu mucocel mic, fără complicații.'), [0.0, 1.0, 0.0, 0.0]);
        $ask = new ArchiveAsk($this->index, ['model' => 'embed-test', 'min' => 0.5, 'common' => 0]);
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');

        // Words of the question are in no report; its vector is near the first
        $sources = $ask->sources('xqzv wqpl', $owner, [], [1.0, 0.0, 0.0, 0.0]);

        self::assertCount(1, $sources);
        self::assertSame($near, $sources[0]['pid']);
        self::assertSame('Concluzii', $sources[0]['heading'], 'a vector is made of the conclusion: that is what is cited');
        self::assertSame([], $ask->sources('xqzv wqpl', $owner, [], null), 'no vector, no words: nothing');
        self::assertSame([], $ask->sources('xqzv wqpl', $owner, [], [0.0, 0.0, 1.0, 0.0]), 'under the minimum score: no filler');
    }

    public function testMeaningObeysVisibilityStatusAndTheNormalTag(): void
    {
        $this->report('reports:ct:mioveni:260112-test-ct', $this->body('Concluzie despre sechele ischemice.'), [1.0, 0.0, 0.0, 0.0]);
        $this->report('reports:mri:mioveni:260113-test-draft', $this->body('Ciornă despre sechele ischemice.'), [1.0, 0.0, 0.0, 0.0], 'draft');
        $mri = $this->report('reports:mri:mioveni:260114-test-mri', $this->body('Concluzie MRI despre sechele ischemice.'), [1.0, 0.0, 0.0, 0.0]);
        $normal = $this->report('reports:mri:mioveni:260115-test-normal', $this->body('Fără modificări.'), [1.0, 0.0, 0.0, 0.0], 'archived', 'owner', ['tags' => ['normal']]);
        $ask = new ArchiveAsk($this->index, ['model' => 'embed-test', 'min' => 0.5, 'common' => 0]);
        $mihai = (new FlatFileUserStore($this->dataRoot))->find('mihai');
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');

        $mine = array_column($ask->sources('xqzv', $mihai, [], [1.0, 0.0, 0.0, 0.0]), 'pid');
        self::assertSame([$mri], $mine, 'only the MRI report he may read: not the CT one, not the draft, not the normal one');
        self::assertNotContains($normal, array_column($ask->sources('xqzv', $owner, [], [1.0, 0.0, 0.0, 0.0]), 'pid'));
        self::assertCount(2, $ask->sources('xqzv', $owner, [], [1.0, 0.0, 0.0, 0.0]), 'the owner reads the CT one too');
    }

    public function testAFewPlacesAreKeptForWhatOnlyMeaningFinds(): void
    {
        // Ten passages hold the word; one more report is near by meaning only
        foreach (range(1, 5) as $i) {
            $this->report("reports:mri:mioveni:26020{$i}-test-kw{$i}", "# TEST Ask\n\n## IRM\n\n### Descriere\n\nHernie discală numărul {$i} cu compresiune pe rădăcină.\n\nAlt paragraf cu hernie discală și fragment {$i} lung.\n", [0.0, 1.0, 0.0, 0.0]);
        }
        $only = $this->report('reports:mri:mioveni:260210-test-meaning', $this->body('Protruzie centrală fără compresiune.'), [1.0, 0.0, 0.0, 0.0]);
        $ask = new ArchiveAsk($this->index, ['model' => 'embed-test', 'min' => 0.5, 'common' => 0]);
        $owner = new User('owner', 'x', true, [], true, 'now', 'now');

        $sources = $ask->sources('hernie discală', $owner, [], [1.0, 0.0, 0.0, 0.0]);

        self::assertCount(ArchiveAsk::SOURCES, $sources);
        self::assertContains($only, array_column($sources, 'pid'), 'exact words do not crowd out the report found by meaning');
        self::assertNotContains($only, array_column($ask->sources('hernie discală', $owner), 'pid'), 'keywords alone would not find it');
    }

    public function testTheQuestionIsMadeStandaloneAndItsWordsWidenTheSearch(): void
    {
        $this->report('reports:mri:mioveni:260301-test-flair', $this->body('Aspect normal.', 'Zone de hiperintensitate periventriculară, fără efect de masă.'), [0.0, 0.0, 1.0, 0.0]);

        $data = $this->decode($this->ask('owner', [
            'question' => 'și la copii?',
            'history' => [['question' => 'Cum descriem leziunile de substanță albă?', 'answer' => 'Hiperintense FLAIR [1].']],
        ]));

        // The word the question never had comes from the expansion; the answer is asked the standalone question
        self::assertSame('Cum descriem leziunile de substanță albă la copii?', $data['understood']);
        self::assertContains('hiperintensitate', $data['searched']['terms']);
        self::assertCount(1, $data['sources']);
        $prompt = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('<question>Cum descriem leziunile de substanță albă la copii?</question>', $prompt);
        self::assertStringNotContainsString('și la copii?', $prompt);
    }

    public function testWithoutTheExpandPromptTheQuestionIsSearchedAsItIs(): void
    {
        (new FlatFile($this->dataRoot, $this->index))->delete('ai:profiles:reports:special:ask-expand', 'owner');
        $this->report('reports:mri:mioveni:260301-test-flair', $this->body('Aspect normal.', 'Zone de hiperintensitate periventriculară, fără efect de masă.'), [0.0, 0.0, 1.0, 0.0]);

        $data = $this->decode($this->ask('owner', ['question' => 'hiperintensitate periventriculară']));

        self::assertNull($data['understood']);
        self::assertCount(1, $data['sources']);
        self::assertSame([], $data['notices']);
    }

    public function testAFailedStepIsASayingNotTheEnd(): void
    {
        $this->config['ai']['servers'][0]['tiers']['lite']['model'] = 'fail-401';
        $this->config['ai']['embed_model'] = 'fail-embed';
        $this->report('reports:mri:mioveni:260301-test-flair', $this->body('Aspect normal.', 'Zone de hiperintensitate periventriculară, fără efect de masă.'), [0.0, 0.0, 1.0, 0.0]);

        $response = $this->ask('owner', ['question' => 'hiperintensitate periventriculară']);
        $data = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertNotNull($data['answer'], 'the words alone still find it');
        self::assertContains('The question could not be widened by the assistant: the search used its own words.', $data['notices']);
        self::assertContains('Search by meaning was not available: the search used words only.', $data['notices']);
    }

    public function testEarlierTurnsAreDeIdentifiedAndLoseTheirCitationsBeforeAnyModel(): void
    {
        $storage = new FlatFile($this->dataRoot, $this->index);
        $storage->create('reports:mri:mioveni:260110-ionescu-vasile', ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'IONESCU Vasile', 'cnp' => '1900101000002']], "# IONESCU Vasile\n\n## IRM\n\nText.\n", 'owner');
        $action = new Action('ask-expand', 'ask-expand', '', '', 'show', "{history}\n<question>{question}</question>", '');

        $prompt = (new Context($storage, $this->index))->expand($action, 'și la Ionescu?', [
            ['question' => 'Ce a avut Ionescu Vasile, CNP 1900101000002?', 'answer' => 'Leziuni de substanță albă [1][2]. Pacientul Ionescu a avut sechele [3].'],
        ]);

        self::assertStringContainsString('<history>', $prompt->user);
        self::assertStringContainsString('Leziuni de substanță albă. Pacientul', $prompt->user);
        foreach (['Ionescu', 'IONESCU', 'Vasile', '1900101000002', '[1]', '[2]', '[3]'] as $never) {
            self::assertStringNotContainsString($never, $prompt->user, $never);
        }
        self::assertSame(['1 turns', 'no patient identifiers'], $prompt->contextSet);
    }

    public function testAMalformedHistoryIsIgnoredAndOnlyTheLatestFiveTurnsCount(): void
    {
        $this->report('reports:mri:mioveni:260301-test-flair', $this->body('Aspect normal.', 'Zone de hiperintensitate periventriculară, fără efect de masă.'), [0.0, 0.0, 1.0, 0.0]);
        $turns = array_map(static fn (int $i): array => ['question' => "Întrebarea {$i}?", 'answer' => "Răspunsul {$i}."], range(1, 8));

        foreach ([['x'], 'text', [['question' => 5, 'answer' => []]], $turns] as $history) {
            $response = $this->ask('owner', ['question' => 'hiperintensitate', 'history' => $history]);
            self::assertSame(200, $response->status, json_encode($history) ?: '');
        }
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ask', cookies: $cookies, body: (string) json_encode($body)));
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        self::assertSame(200, $response->status, $response->body);

        return json_decode($response->body, true)['data'];
    }
}
