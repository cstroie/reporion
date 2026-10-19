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
 * Ask HippoBridge (D38, D53): a question answered from the HIS's indexed
 * texts, through the `ask-archive` prompt, against a fake HippoBridge and the
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
            'description' => "Leziuni de substanță albă periventriculare la pacientul Popescu, hiperintense FLAIR, fără efect de masă.\nCNP " . self::CNP . ' fără alte modificări semnificative.',
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
    }

    public function testAnEpicrisisIsReadAsACheckoutEncounter(): void
    {
        $this->his['/fhir/Patient'] = [200, self::bundle('POPESCU Dan', [['epicrisis', 'CO1']])];
        $this->his['/fhir/Encounter/CO1'] = [200, [
            'resourceType' => 'Encounter',
            'id' => 'CO1',
            'subject' => ['display' => 'POPESCU Dan'],
            'period' => ['start' => '2026-02-01', 'end' => '2026-02-05'],
            'note' => [['text' => 'La externare: leziuni de substanță albă FLAIR, fără efect de masă la Popescu.']],
        ]];

        $data = json_decode($this->ask('owner', ['question' => self::QUESTION])->body, true)['data'];

        self::assertContains('/fhir/Encounter/CO1?type=checkout', $this->asked);
        self::assertSame('HIS epicrisis', $data['sources'][0]['modality']);
        self::assertSame('2026-02-01', $data['sources'][0]['study_date']);
        self::assertStringNotContainsStringIgnoringCase('popescu', $data['sources'][0]['text']);
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
        self::assertSame(404, $this->ask('viewer', ['question' => self::QUESTION])->status, 'not a radiologist: 404, never 403');
        self::assertSame(422, $this->ask('owner', ['question' => ''])->status);
        self::assertSame(422, $this->ask('owner', ['question' => str_repeat('a', 1001)])->status);
        self::assertSame(404, $this->get('viewer', '/x/hipobridge/ask')->status);
        self::assertSame(404, $this->get(null, '/x/hipobridge/ask')->status);

        $this->his['/fhir/Patient'] = [401, null];
        $response = $this->ask('owner', ['question' => self::QUESTION]);
        self::assertSame(502, $response->status);
        self::assertSame('his_auth', json_decode($response->body, true)['error']['code']);

        $this->storage()->delete('ai:profiles:reports:special:ask-archive', 'owner');
        self::assertSame(503, $this->ask('owner', ['question' => self::QUESTION])->status);
    }

    public function testTheScreenPostsToThePluginAndIsInTheNewReportSlot(): void
    {
        $screen = $this->get('owner', '/x/hipobridge/ask?q=' . rawurlencode('substanță albă'));

        self::assertSame(200, $screen->status);
        self::assertStringContainsString('data-island="ask"', $screen->body);
        self::assertStringContainsString('"endpoint":"\/x\/hipobridge\/ask"', $screen->body);
        self::assertStringContainsString('substanță albă</textarea>', $screen->body);
    }

    /** @param array<string, mixed> $body */
    private function ask(?string $user, array $body): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', '/x/hipobridge/ask', cookies: $this->cookies($user), body: (string) json_encode($body)));
    }

    private function get(?string $user, string $path): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request('GET', $route, query: array_map('strval', $params), cookies: $this->cookies($user)));
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
