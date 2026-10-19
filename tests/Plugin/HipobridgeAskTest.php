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
 * against a fake HippoBridge and the
 * fake OpenAI-compatible server. Every name and CNP is made up (invariant 10).
 */
final class HipobridgeAskTest extends HttpTestCase
{
    private const QUESTION = 'Cum descriem leziunile de substanță albă FLAIR?';
    private const CNP = '1900101000002';

    private FakeServer $server;

    /** @var list<string> every path?query the fake HIS was asked for */
    private array $asked = [];

    /** @var array<string, array{0: int, 1: mixed}> path → status, JSON */
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
            [$status, $json] = $this->his[strtok($key, '?')] ?? [404, null];

            return [$status, (string) json_encode($json)];
        };
        $this->his['/fhir/Patient'] = [200, self::bundle('POPESCU Dan', [['imaging', '77']])];
        $this->his['/fhir/ImagingStudy/77'] = [200, [
            'resourceType' => 'ImagingStudy',
            'id' => '77',
            'status' => 'available',
            'started' => '2026-03-04T10:00:00',
            'subject' => ['display' => 'POPESCU Dan'],
            'reason' => [['text' => 'Diagnostic clinic CLINICONLY']],
            'series' => [['description' => 'IRM cerebral cu contrast SERIESONLY']],
            'note' => [
                ['category' => [['text' => 'clinical-indication']], 'text' => 'Justificare: cefalee, INDICATIONONLY, pacientul Popescu Dan.'],
                ['text' => "Leziuni de substanță albă periventriculare la pacientul Popescu, hiperintense FLAIR, fără efect de masă.\nCNP " . self::CNP . ' fără alte modificări semnificative.'],
            ],
        ]];
    }

    /**
     * A /fhir/Patient?content= searchset Bundle as the spec shows it: a
     * Patient stub (id = CNP) with its search extensions on the resource
     *
     * @param list<array{0: string, 1: string}> $documents kind, id
     *
     * @return array<string, mixed>
     */
    private static function bundle(string $name, array $documents): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => 1, 'entry' => [[
            'fullUrl' => 'http://his.test/fhir/Patient/' . self::CNP,
            'search' => ['mode' => 'match'],
            'resource' => [
                'resourceType' => 'Patient',
                'id' => self::CNP,
                'identifier' => [['system' => 'http://his.test/fhir/NamingSystem/patient-cnp', 'value' => self::CNP]],
                'name' => [['text' => $name]],
                'extension' => [
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-snippet', 'valueString' => '<mark>leziuni</mark> de substanță albă'],
                    ['url' => 'http://his.test/fhir/StructureDefinition/search-count', 'valueInteger' => 1],
                    ...array_map(static fn (array $d): array => ['url' => 'http://his.test/fhir/StructureDefinition/search-document', 'extension' => [
                        ['url' => 'kind', 'valueString' => $d[0]],
                        ['url' => 'id', 'valueString' => $d[1]],
                    ]], $documents),
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

    public function testTheAnswerComesFromTheHisTextsWithNoIdentifierAnywhere(): void
    {
        $response = $this->ask('owner', ['question' => self::QUESTION]);

        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertNotNull($data['answer']);
        self::assertSame(1, $data['sources'][0]['n']);
        self::assertSame('2026-03-04', $data['sources'][0]['study_date']);
        self::assertSame('HIS imaging', $data['sources'][0]['modality']);
        self::assertContains('1 sources', $data['context']);

        $prompt = (string) json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('hiperintense FLAIR, fără efect de masă', $prompt);
        self::assertStringContainsString('<source id=\"1\" date=\"2026-03-04\">', $prompt);
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"ai.call"', $audit);
        foreach (['Popescu', 'POPESCU', self::CNP] as $never) {
            foreach (['the prompt' => $prompt, 'the response' => $response->body, 'the audit' => $audit, 'the HIS queries' => implode(' ', $this->asked)] as $where => $text) {
                self::assertStringNotContainsStringIgnoringCase($never, $text, $never . ' in ' . $where);
            }
        }
        self::assertStringNotContainsString('<mark>', $prompt);
        foreach (['INDICATIONONLY', 'CLINICONLY', 'SERIESONLY'] as $notReport) {
            self::assertStringNotContainsString($notReport, $prompt, 'only the report notes are read: ' . $notReport);
        }
    }

    public function testAnEpicrisisIsReadAsACheckoutEncounter(): void
    {
        $this->his['/fhir/Patient'] = [200, self::bundle('POPESCU Dan', [['epicrisis', 'CO1']])];
        $this->his['/fhir/Encounter/CO1'] = [200, [
            'resourceType' => 'Encounter',
            'id' => 'CO1',
            'subject' => ['display' => 'POPESCU Dan'],
            'period' => ['start' => '2026-02-01', 'end' => '2026-02-05'],
            'note' => [['text' => '**La externare:** leziuni de substanță albă FLAIR, fără efect de masă la Popescu.']],
        ]];

        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertContains('/fhir/Encounter/CO1?type=checkout', $this->asked);
        self::assertSame('HIS epicrisis', $data['sources'][0]['modality']);
        self::assertSame('2026-02-01', $data['sources'][0]['study_date']);
        self::assertStringNotContainsStringIgnoringCase('popescu', $data['sources'][0]['text']);
        self::assertStringNotContainsString('**', $data['sources'][0]['text']);
    }

    public function testLongerPassagesAndAFewMoreSlotsForTheHis(): void
    {
        $this->his['/fhir/Patient'] = [200, self::bundle('POPESCU Dan', [['imaging', '77'], ['imaging', '78']])];
        $long = 'Leziuni de substanță albă periventriculare, hiperintense FLAIR, fără efect de masă. ' . str_repeat('Aspect stabil față de examinarea anterioară, fără leziuni noi. ', 11) . 'Recomandare: control IRM la 6 luni.';
        $this->his['/fhir/ImagingStudy/77'] = [200, ['resourceType' => 'ImagingStudy', 'id' => '77', 'note' => array_map(static fn (int $i): array => ['text' => $long . " (examenul {$i})"], [1, 2, 3])]];
        $this->his['/fhir/ImagingStudy/78'] = [200, ['resourceType' => 'ImagingStudy', 'id' => '78', 'note' => array_map(static fn (int $i): array => ['text' => $long . " (controlul {$i})"], [1, 2, 3])]];

        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertCount(4, $data['sources'], 'the HIS keeps four of the eight places');
        self::assertGreaterThan(500, max(array_map(static fn (array $s): int => mb_strlen($s['text']), $data['sources'])), 'a passage holds more than 500 characters');
        self::assertLessThanOrEqual(900, max(array_map(static fn (array $s): int => mb_strlen($s['text']), $data['sources'])));
    }

    public function testOnlyContentWordsAreSentToTheBridge(): void
    {
        $this->ask('owner', ['question' => 'Cum descriem leziunile la Ionescu? Ce spune Marinescu despre FLAIR 1900101000002?']);

        $search = array_values(array_filter($this->asked, static fn (string $a): bool => str_starts_with($a, '/fhir/Patient?content=')));
        self::assertNotSame([], $search);
        parse_str((string) parse_url($search[0], PHP_URL_QUERY), $query);
        $sent = (string) $query['content'];
        self::assertStringContainsString('leziu', $sent);
        foreach (['ionescu', 'marinescu', '1900101000002', 'cum', 'ce'] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $sent, $never);
        }
    }

    /** @return list<string> the `content=` of every search the fake HIS was asked */
    private function searched(): array
    {
        $out = [];
        foreach ($this->asked as $a) {
            if (str_starts_with($a, '/fhir/Patient?content=')) {
                parse_str((string) parse_url($a, PHP_URL_QUERY), $query);
                $out[] = (string) $query['content'];
            }
        }

        return $out;
    }

    public function testFillerWordsOfAQuestionAreNotSearched(): void
    {
        $this->ask('owner', ['question' => 'Cum descriem de obicei un meningiom în FLAIR? Ce formulări folosim?']);

        $sent = $this->searched()[0] ?? '';
        self::assertStringContainsString('meningiom', $sent);
        self::assertStringContainsString('flair', $sent);
        foreach (['obicei', 'formulari', 'folosim'] as $filler) {
            self::assertStringNotContainsString($filler, $sent, $filler);
        }
    }

    public function testAnUnknownNameThatBeginsTheQuestionIsNotSentButAFindingWordIs(): void
    {
        $this->ask('owner', ['question' => 'Popescu are hernie de disc?']);
        self::assertStringNotContainsStringIgnoringCase('popescu', implode(' ', $this->searched()));
        self::assertStringContainsString('hernie', implode(' ', $this->searched()));

        // A capitalised first word the wiki itself uses in three pages is a finding word, not a name
        foreach (['unu', 'doi', 'trei'] as $n) {
            $this->storage()->create('notes:hernie-' . $n, ['visibility' => 'private'], "# Hernie {$n}\n\nHernia de disc discutată.\n", 'owner');
        }
        $this->asked = [];
        $this->ask('owner', ['question' => 'Hernia de disc L4 cum se descrie?']);
        self::assertStringContainsString('hernia', implode(' ', $this->searched()));
    }

    public function testWhenAllTheWordsTogetherFindNothingTheLongestAreSearchedAloneAndMerged(): void
    {
        $bundle = self::bundle('POPESCU Dan', [['imaging', '77']]);
        Plugin::$transport = function (string $url) use ($bundle): array {
            $key = substr($url, \strlen('http://his.test'));
            $this->asked[] = $key;
            parse_str((string) parse_url($key, PHP_URL_QUERY), $query);
            if (str_starts_with($key, '/fhir/Patient')) {
                // Every word must match: only a single word does
                return [200, (string) json_encode(str_contains((string) $query['content'], ' ') ? ['resourceType' => 'Bundle', 'entry' => []] : $bundle)];
            }

            [$status, $json] = $this->his[strtok($key, '?')] ?? [404, null];

            return [$status, (string) json_encode($json)];
        };

        $data = json_decode($this->ask('owner', ['question' => 'Cum descriem leziunile de substanță albă periventriculare FLAIR?'])->body, true)['data'];

        self::assertNotNull($data['answer']);
        $searches = $this->searched();
        self::assertStringContainsString(' ', $searches[0], 'all together first');
        $alone = array_slice($searches, 1);
        self::assertCount(3, $alone, 'then the three longest, one each');
        foreach ($alone as $one) {
            self::assertStringNotContainsString(' ', $one);
        }
        self::assertCount(1, $data['sources'], 'a patient found by three words is one hit');
    }

    public function testNothingToAnswerFromAsksNoModel(): void
    {
        $this->his['/fhir/Patient'] = [200, ['enabled' => false, 'results' => []]];

        $response = $this->ask('owner', ['question' => self::QUESTION]);

        self::assertSame(200, $response->status);
        self::assertNull(json_decode($response->body, true)['data']['answer']);
        self::assertNull($this->server->lastRequest()['body'], 'no model asked');
        // No word that may leave the box: HippoBridge is not asked at all
        $this->asked = [];
        self::assertNull(json_decode($this->ask('owner', ['question' => 'Cum este Ionescu?'])->body, true)['data']['answer']);
        self::assertSame([], $this->asked);
    }

    public function testAHitWithoutANameIsDroppedAndAnUnreadableDocumentSkipped(): void
    {
        $this->his['/fhir/Patient'] = [200, self::bundle('', [['imaging', '77']])];
        self::assertNull(json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data']['answer']);

        $this->his['/fhir/Patient'] = [200, self::bundle('POPESCU Dan', [['imaging', '77']])];
        $this->his['/fhir/ImagingStudy/77'] = [500, null];
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
        $this->his['/fhir/Patient'] = [401, null];

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
