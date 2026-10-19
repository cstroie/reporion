<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Plugin\Hipobridge\Plugin;
use Reporion\Plugin\Loader;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Ai\FakeServer;
use Reporion\Tests\Http\HttpTestCase;

/**
 * Ask the archive with the HIS (D38, D53): `POST /api/v1/ask` also draws on
 * HippoBridge's indexed texts through the `ask.sources` hook, one answer,
 * against a fake HippoBridge (its passage searches `/fhir/ImagingStudy?content=` and
 * `/fhir/DocumentReference?content=`) and the fake OpenAI-compatible server. Every name and CNP is made up (invariant 10).
 */
final class HipobridgeAskTest extends HttpTestCase
{
    private const QUESTION = 'Cum descriem leziunile de substanță albă FLAIR?';
    private const CNP = '1900101000002';

    private FakeServer $server;

    /** @var list<string> every path?query the fake HIS was asked for */
    private array $asked = [];

    /** @var array<string, array{0: int, 1: mixed}|callable(array<string, string>): array{0: int, 1: mixed}> path → status, JSON; a callable takes the query and answers the same way */
    private array $his = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'hipobridge');
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->config['plugins'] = [
            'enabled' => ['hipobridge'],
            'settings' => ['hipobridge' => ['url' => 'http://his.test', 'username' => 'svc', 'password' => 'pw']],
        ];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('viewer', 'x', false, [new Grant('reports', GrantRole::Viewer)]);
        $this->storage()->create('ai:profiles:reports:special:ask-archive', ['visibility' => 'private'], "<question>{question}</question>\n{sources}\nRăspunde citând sursele.\n", 'owner');
        // A report of ours: its name words never go to HippoBridge
        $this->storage()->create('reports:mri:mioveni:260110-ionescu-vasile', ['visibility' => 'private', 'modality' => ['MR'], 'patient' => ['name' => 'IONESCU Vasile']], "# IONESCU Vasile\n\n## IRM\n\n### Descriere\n\nText.\n", 'owner');
        Plugin::$transport = function (string $url, array $headers): array {
            self::assertContainsEquals('Authorization: Basic ' . base64_encode('svc:pw'), $headers);
            $key = substr($url, \strlen('http://his.test'));
            $this->asked[] = $key;
            $answer = $this->his[strtok($key, '?')] ?? [404, null];
            if (\is_callable($answer)) {
                parse_str((string) parse_url($key, PHP_URL_QUERY), $query);
                $answer = $answer($query);
            }
            [$status, $json] = $answer;

            return [$status, (string) json_encode($json)];
        };
        $this->his['/fhir/ImagingStudy'] = [200, self::imaging('POPESCU Dan', ['Leziuni de substanță albă periventriculare, hiperintense FLAIR, fără efect de masă pe secvențele T2. CNP ' . self::CNP . ' fără alte modificări semnificative.'])];
        $this->his['/fhir/DocumentReference'] = [200, self::empty()];
    }

    /** @return array<string, mixed> */
    private static function empty(): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => 0, 'entry' => []];
    }

    /**
     * /fhir/ImagingStudy?content= as the spec describes it: one ImagingStudy per study, the best
     * passage(s) in note[0].text, the patient as the subject, a `search-passage` section per passage
     *
     * @param list<string> $passages
     *
     * @return array<string, mixed>
     */
    private static function imaging(string $name, array $passages, string $id = '77', string $when = '2026-03-04T10:00:00'): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => 1, 'entry' => [[
            'search' => ['mode' => 'match', 'score' => 2.4],
            'resource' => [
                'resourceType' => 'ImagingStudy',
                'id' => $id,
                'status' => 'available',
                'started' => $when,
                'description' => 'IRM cerebral CLINICONLY',
                'subject' => ['reference' => 'Patient/' . self::CNP, 'display' => $name],
                'note' => [['text' => implode("\n\n", $passages)]],
                'extension' => [
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-score', 'valueDecimal' => 2.4],
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-matched', 'valueInteger' => 2],
                    ...array_map(static fn (string $p): array => ['url' => 'http://his.test/fhir/StructureDefinition/search-passage', 'valueString' => 'Concluzii'], $passages),
                ],
            ],
        ]]];
    }

    /**
     * /fhir/DocumentReference?content=&kind=epicrisis: the text in content[0].attachment.data, the
     * patient in search-identifiers
     *
     * @param list<string> $passages
     * @param array<string, string> $identifiers
     *
     * @return array<string, mixed>
     */
    private static function epicrisis(array $passages, array $identifiers, string $id = 'CO1', string $when = '2026-02-01'): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => 1, 'entry' => [[
            'search' => ['mode' => 'match', 'score' => 1.9],
            'resource' => [
                'resourceType' => 'DocumentReference',
                'id' => $id,
                'type' => ['text' => 'epicrisis'],
                'date' => $when,
                'content' => [['attachment' => ['contentType' => 'text/plain', 'data' => implode("\n\n", $passages)]]],
                'extension' => [
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-score', 'valueDecimal' => 1.9],
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-passage', 'valueString' => 'Rezultate'],
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-identifiers', 'extension' => array_map(static fn (string $k, string $v): array => ['url' => $k, 'valueString' => $v], array_keys($identifiers), $identifiers)],
                ],
            ],
        ]]];
    }

    protected function tearDown(): void
    {
        Plugin::$transport = null;
        $this->server->stop();
        parent::tearDown();
    }

    /** @return list<array<string, string>> the query of every passage search the fake HIS was asked, by endpoint */
    private function searches(): array
    {
        $out = [];
        foreach ($this->asked as $a) {
            if (preg_match('#^/fhir/(ImagingStudy|DocumentReference)\?#', $a, $m) === 1) {
                parse_str((string) parse_url($a, PHP_URL_QUERY), $query);
                $out[] = ['endpoint' => $m[1]] + array_map('strval', $query);
            }
        }

        return $out;
    }

    /** @return list<string> the words of every search, the two endpoints' counted once */
    private function words(): array
    {
        return array_values(array_unique(array_column($this->searches(), 'content')));
    }

    public function testTheAnswerComesFromTheHisTextsWithNoIdentifierAnywhere(): void
    {
        $response = $this->ask('owner', ['question' => self::QUESTION]);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertNotNull($data['answer']);
        self::assertSame(1, $data['sources'][0]['n']);
        self::assertSame('2026-03-04', $data['sources'][0]['study_date']);
        self::assertSame('HIS imaging', $data['sources'][0]['modality']);
        self::assertSame('Concluzii', $data['sources'][0]['heading'], 'the section HippoBridge names');
        self::assertContains('1 sources', $data['context']);

        $prompt = (string) json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('hiperintense FLAIR, fără efect de masă', $prompt);
        self::assertStringContainsString('<source id=\"1\" date=\"2026-03-04\">', $prompt);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"ai.call"', $audit);
        foreach (['Popescu', 'POPESCU', self::CNP, 'CLINICONLY'] as $never) {
            foreach (['the prompt' => $prompt, 'the response' => $response->body, 'the audit' => $audit, 'the HIS queries' => implode(' ', $this->asked)] as $where => $text) {
                self::assertStringNotContainsStringIgnoringCase($never, $text, $never . ' in ' . $where);
            }
        }
    }

    public function testBothEndpointsAreAskedSideBySideWithTheContentWords(): void
    {
        $this->ask('owner', ['question' => self::QUESTION]);

        $searches = $this->searches();
        self::assertSame(['ImagingStudy', 'DocumentReference'], array_column($searches, 'endpoint'));
        foreach ($searches as $query) {
            self::assertSame('any', $query['match']);
            self::assertSame('2', $query['min-match'], 'two of the words in one passage');
            self::assertSame('900', $query['max-chars']);
            self::assertSame('1', $query['context']);
            self::assertStringContainsString('leziu', $query['content']);
            self::assertArrayNotHasKey('status', $query, 'validated only is HippoBridge\'s default');
        }
        self::assertArrayNotHasKey('kind', $searches[0], 'an ImagingStudy search is imaging already');
        self::assertSame('epicrisis', $searches[1]['kind']);
        self::assertSame([], array_filter($this->asked, static fn (string $a): bool => !str_starts_with($a, '/fhir/ImagingStudy?') && !str_starts_with($a, '/fhir/DocumentReference?')), 'no request per document');
    }

    public function testAnEpicrisisIsReadFromDocumentReferenceWithItsIdentifiersLearned(): void
    {
        $this->his['/fhir/ImagingStudy'] = [200, self::empty()];
        $this->his['/fhir/DocumentReference'] = [200, self::epicrisis(
            ['**La externare:** leziuni de substanță albă FLAIR, fără efect de masă la Popescu Dan. Domiciliu Str. Florilor nr. 3, Pitești, telefon 0722 123 456, popescu.dan@example.test.'],
            ['name' => 'POPESCU Dan', 'family-name' => 'POPESCU', 'given-name' => 'Dan', 'cnp' => self::CNP, 'address' => 'Str. Florilor nr. 3', 'city' => 'Pitești', 'phone' => '0722 123 456', 'email' => 'popescu.dan@example.test'],
        )];

        $response = $this->ask('owner', ['question' => self::QUESTION]);
        $data = json_decode($response->body, true)['data'];

        self::assertSame('HIS epicrisis', $data['sources'][0]['modality']);
        self::assertSame('2026-02-01', $data['sources'][0]['study_date']);
        self::assertSame('Rezultate', $data['sources'][0]['heading']);
        self::assertStringContainsString('leziuni de substanță albă FLAIR', $data['sources'][0]['text']);
        self::assertStringNotContainsString('**', $data['sources'][0]['text']);
        $prompt = (string) json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        foreach (['Popescu', 'Florilor', 'Pitești', '0722', 'example.test', self::CNP] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $prompt, $never . ' in the prompt');
            self::assertStringNotContainsStringIgnoringCase($never, $response->body, $never . ' in the response');
        }
    }

    public function testOneEndpointFailingLeavesTheOthersTexts(): void
    {
        $this->his['/fhir/DocumentReference'] = [500, null];

        $response = $this->ask('owner', ['question' => self::QUESTION]);
        $data = json_decode($response->body, true)['data'];

        self::assertNotNull($data['answer']);
        self::assertSame([], $data['notices'], 'a text was found: nothing is said');
    }

    public function testMorePassagesPerDocumentAreCutAtTheHisSlots(): void
    {
        $long = 'Leziuni de substanță albă periventriculare, hiperintense FLAIR, fără efect de masă. ' . str_repeat('Aspect stabil față de examinarea anterioară, fără leziuni noi. ', 11) . 'Recomandare: control IRM.';
        $this->his['/fhir/ImagingStudy'] = [200, self::imaging('POPESCU Dan', [$long . ' (a)', $long . ' (b)', $long . ' (c)'])];
        $this->his['/fhir/DocumentReference'] = [200, self::epicrisis([$long . ' (d)', $long . ' (e)'], ['name' => 'POPESCU Dan', 'cnp' => self::CNP])];

        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertCount(4, $data['sources'], 'two from each document, four of the eight places for the HIS');
        $longest = max(array_map(static fn (array $s): int => mb_strlen($s['text']), $data['sources']));
        self::assertGreaterThan(500, $longest);
        self::assertLessThanOrEqual(900, $longest);
    }

    public function testOnlyContentWordsAreSentToTheBridge(): void
    {
        $this->ask('owner', ['question' => 'Cum descriem leziunile la Ionescu? Ce spune Marinescu despre FLAIR 1900101000002?']);

        $sent = $this->words()[0] ?? '';
        self::assertStringContainsString('leziu', $sent);
        foreach (['ionescu', 'marinescu', '1900101000002', 'cum', 'ce'] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $sent, $never);
        }
    }

    public function testFillerWordsOfAQuestionAreNotSearched(): void
    {
        $this->ask('owner', ['question' => 'Cum descriem de obicei un meningiom în FLAIR? Ce formulări folosim?']);

        $sent = $this->words()[0] ?? '';
        self::assertStringContainsString('meningiom', $sent);
        self::assertStringContainsString('flair', $sent);
        foreach (['obicei', 'formulari', 'folosim'] as $filler) {
            self::assertStringNotContainsString($filler, $sent, $filler);
        }
    }

    public function testAnUnknownNameThatBeginsTheQuestionIsNotSentButAFindingWordIs(): void
    {
        $this->ask('owner', ['question' => 'Popescu are hernie de disc?']);
        self::assertStringNotContainsStringIgnoringCase('popescu', implode(' ', $this->words()));
        self::assertStringContainsString('hernie', implode(' ', $this->words()));

        // A capitalised first word the wiki itself uses in three pages is a finding word, not a name
        foreach (['unu', 'doi', 'trei'] as $n) {
            $this->storage()->create('notes:hernie-' . $n, ['visibility' => 'private'], "# Hernie {$n}\n\nHernia de disc discutată.\n", 'owner');
        }
        $this->asked = [];
        $this->ask('owner', ['question' => 'Hernia de disc L4 cum se descrie?']);
        self::assertStringContainsString('hernia', implode(' ', $this->words()));
    }

    public function testWhenTwoWordsTogetherFindNothingOneIsEnough(): void
    {
        $bundle = $this->his['/fhir/ImagingStudy'];
        $this->his['/fhir/ImagingStudy'] = static fn (array $q): array => $q['min-match'] === '1' ? [200, $bundle[1]] : [200, self::empty()];

        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertNotNull($data['answer']);
        self::assertSame(['2', '2', '1', '1'], array_column($this->searches(), 'min-match'), 'both endpoints at two, then at one');
    }

    public function testNothingToAnswerFromAsksNoModel(): void
    {
        $this->his['/fhir/ImagingStudy'] = [200, self::empty()];

        $response = $this->ask('owner', ['question' => self::QUESTION]);

        self::assertSame(200, $response->status);
        self::assertNull(json_decode($response->body, true)['data']['answer']);
        self::assertNull($this->server->lastRequest()['body'], 'no model asked');
        // HippoBridge without a text search answers an OperationOutcome: the same
        $this->his['/fhir/ImagingStudy'] = [404, ['resourceType' => 'OperationOutcome']];
        self::assertNull(json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data']['answer']);
        // No word that may leave the box: HippoBridge is not asked at all
        $this->asked = [];
        self::assertNull(json_decode($this->ask('owner', ['question' => 'Cum este Ionescu?'])->body, true)['data']['answer']);
        self::assertSame([], $this->asked);
    }

    public function testADocumentWithoutANameOrWithoutTextIsDropped(): void
    {
        $this->his['/fhir/ImagingStudy'] = [200, self::imaging('', ['Leziuni de substanță albă, hiperintense FLAIR, fără efect de masă.'])];
        $bare = $this->his['/fhir/ImagingStudy'][1];
        unset($bare['entry'][0]['resource']['subject']['display']);
        $this->his['/fhir/ImagingStudy'] = [200, $bare];
        self::assertNull(json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data']['answer'], 'nothing could de-identify it');

        $this->his['/fhir/ImagingStudy'] = [200, self::imaging('POPESCU Dan', [''])];
        self::assertNull(json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data']['answer']);
    }

    public function testRefusals(): void
    {
        self::assertSame(404, $this->ask(null, ['question' => self::QUESTION])->status);
        self::assertSame(422, $this->ask('owner', ['question' => ''])->status);

        $this->storage()->delete('ai:profiles:reports:special:ask-archive', 'owner');
        self::assertSame(503, $this->ask('owner', ['question' => self::QUESTION])->status);
    }

    public function testOnlyAReportWritingRadiologistGetsTheHis(): void
    {
        $data = json_decode($this->ask('viewer', ['question' => self::QUESTION])->body, true)['data'];

        self::assertNull($data['answer']);
        self::assertSame([], $this->asked, 'HippoBridge is not even asked');
    }

    public function testTheHisAndTheWikiAnswerTogetherWithOneNumbering(): void
    {
        $this->storage()->create(
            'reports:mri:mioveni:260115-marin-ion',
            ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'study_date' => '2026-01-15', 'patient' => ['name' => 'MARIN Ion']],
            "# MARIN Ion\n\n## IRM cerebral\n\n### Descriere\n\nLeziuni de substanță albă subcorticale, hiperintense FLAIR, fără efect de masă.\n",
            'owner',
        );

        $response = $this->ask('owner', ['question' => self::QUESTION]);
        $data = json_decode($response->body, true)['data'];

        self::assertNotNull($data['answer']);
        self::assertSame([1, 2], array_column($data['sources'], 'n'));
        self::assertStringStartsWith('/r/', $data['sources'][0]['href'], 'the wiki first, linked by pid');
        self::assertArrayNotHasKey('href', $data['sources'][1], 'the HIS has no page to link to');
        self::assertSame('HIS imaging', $data['sources'][1]['modality']);
        self::assertSame([], $data['notices']);
        self::assertContains('2 sources', $data['context']);
        $prompt = (string) json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('<source id=\"1\" date=\"2026-01-15\">', $prompt);
        self::assertStringContainsString('<source id=\"2\" date=\"2026-03-04\">', $prompt);
        foreach (['Popescu', 'POPESCU', 'Marin', 'MARIN', self::CNP] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $prompt, $never);
            self::assertStringNotContainsStringIgnoringCase($never, $response->body, $never);
        }
    }

    public function testHippoBridgeDownLeavesTheWikisAnswerAndAsksWithANotice(): void
    {
        $this->storage()->create(
            'reports:mri:mioveni:260115-marin-ion',
            ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'study_date' => '2026-01-15', 'patient' => ['name' => 'MARIN Ion']],
            "# MARIN Ion\n\n## IRM cerebral\n\n### Descriere\n\nLeziuni de substanță albă subcorticale, hiperintense FLAIR, fără efect de masă.\n",
            'owner',
        );
        $this->his['/fhir/ImagingStudy'] = [401, null];
        $this->his['/fhir/DocumentReference'] = [401, null];

        $response = $this->ask('owner', ['question' => self::QUESTION]);
        $data = json_decode($response->body, true)['data'];

        self::assertSame(200, $response->status);
        self::assertNotNull($data['answer']);
        self::assertCount(1, $data['sources']);
        self::assertStringContainsString('HippoBridge was left out of this answer', $data['notices'][0]);
        self::assertStringContainsString('refused the configured Hipocrate account', $data['notices'][0]);
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/ask', cookies: $this->cookies($user), body: (string) json_encode($body)));
    }

    /** @return array<string, string> */
    private function cookies(?string $user): array
    {
        return $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
