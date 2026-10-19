<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Ai\FakeServer;

/**
 * The editor's rail before a page's first Save (Service\EditorPage,
 * 2026-10-19): its path is known, so the rail is the same as a saved
 * page's, and the assistant, Recall, Checks and Prior answer for the page
 * as its first Save would write it, from the editor's form. Nothing is
 * written. Fixture data is fictitious (invariant 10).
 */
final class UnsavedPageRailTest extends HttpTestCase
{
    private const NEW = 'reports:mri:mioveni:261019-test-ionescu';
    private const OLDER = 'reports:mri:mioveni:250310-test-ionescu';
    private const CNP = '1900101000001';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => ['model' => 'normal-model']]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner(radiologist: false);
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $this->makeRadiologist('ana');
        $storage = $this->storage();
        $storage->create('ai:profiles:reports', ['visibility' => 'private'], "| ID | Label | Tooltip | Icon | Result |\n|---|---|---|---|---|\n| conclusion | Conclusion | Write the conclusion | file-text | append |\n", 'owner');
        $storage->create('ai:profiles:reports:conclusion', ['visibility' => 'private'], "<raport>\n{text}\n</raport>\n", 'owner');
        $storage->create(self::OLDER, ['visibility' => 'private', 'modality' => ['MR'], 'study_date' => '2025-03-10', 'patient' => ['name' => 'TEST Ionescu', 'cnp' => self::CNP]], "# TEST Ionescu\n\n## IRM cerebral\n\n### Descriere\n\nLeziune frontală de 8 mm.\n\n### Concluzii\n\nLeziune frontală.\n", 'mihai');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testTheEditorOfAPageNotWrittenYetHasItsRail(): void
    {
        $page = $this->call('GET', '/' . self::NEW . '/edit', 'mihai')->body;

        self::assertStringContainsString('data-rail="assistant"', $page);
        self::assertStringContainsString('data-ai-action="conclusion"', $page);
        self::assertStringContainsString('data-rail="recall"', $page);
        self::assertStringContainsString('data-rail="checks"', $page);
        self::assertStringContainsString('data-rail="prior"', $page);
        self::assertStringContainsString('"baseRev":0', $page);
        self::assertFalse(is_dir($this->dataRoot . '/pages/reports/mri/mioveni/261019-test-ionescu'), 'nothing written');
    }

    public function testTheAssistantAnswersForItAndTheNameFromTheFormStaysHome(): void
    {
        $form = http_build_query(['body' => "# TEST Ionescu\n\n## IRM cerebral\n\nPacientul Ionescu, CNP " . self::CNP . '.', 'carried' => json_encode(['modality' => ['MR'], 'patient' => ['name' => 'TEST Ionescu', 'cnp' => self::CNP]])]);
        $response = $this->call('POST', '/api/v1/ai/complete', 'mihai', ['path' => self::NEW, 'action' => 'conclusion', 'text' => 'Pacientul Ionescu, CNP ' . self::CNP . ', leziune.', 'form' => $form]);

        self::assertSame(200, $response->status, $response->body);
        $sent = json_encode($this->server->lastRequest()['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CNP, $sent);
        self::assertStringNotContainsString('Pacientul Ionescu', $sent, 'the name the form carries is redacted');
        self::assertFalse(is_dir($this->dataRoot . '/pages/reports/mri/mioveni/261019-test-ionescu'), 'nothing written');
        self::assertStringNotContainsString('"pid":""', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), 'no empty pid in the audit');
    }

    public function testThePriorAndTheChecksAnswerFromTheForm(): void
    {
        $form = http_build_query(['body' => "# TEST Ionescu\n\n## IRM cerebral\n\n### Descriere\n\nChist de [dimensiune].\n", 'carried' => json_encode(['modality' => ['MR'], 'study_date' => '2026-10-19', 'patient' => ['name' => 'TEST Ionescu', 'cnp' => self::CNP, 'sex' => 'M']])]);

        $prior = json_decode($this->call('POST', '/api/v1/pages/' . self::NEW . '/prior', 'mihai', ['exam' => 1, 'form' => $form])->body, true)['data'];
        self::assertSame('2025-03-10', $prior['study_date'], 'the patient named in the form');
        self::assertNull(json_decode($this->call('GET', '/api/v1/pages/' . self::NEW . '/prior', 'mihai')->body, true)['data'], 'no form: no patient, no prior');

        $checks = json_decode($this->call('POST', '/api/v1/checks', 'mihai', ['path' => self::NEW, 'text' => "## IRM cerebral\n\n### Descriere\n\nChist de [dimensiune]. Uter normal.\n\n### Concluzii\n\nChist.\n", 'form' => $form])->body, true)['data'];
        self::assertContains('sex_mismatch', array_column($checks, 'code'), 'the sex from the form');
        self::assertContains('blank', array_column($checks, 'code'));
    }

    public function testOnlyWhoMayWriteThere(): void
    {
        self::assertSame(404, $this->call('POST', '/api/v1/checks', 'ana', ['path' => self::NEW, 'text' => 'x'])->status, 'a viewer may not make it');
        self::assertSame(404, $this->call('POST', '/api/v1/ai/complete', 'ana', ['path' => self::NEW, 'action' => 'conclusion', 'text' => 'x'])->status);
        self::assertSame(404, $this->call('POST', '/api/v1/recall', 'owner', ['path' => self::NEW, 'text' => 'leziune frontala'])->status, 'no radiologist, nothing stored to read: 404');
        self::assertSame(404, $this->call('POST', '/api/v1/checks', 'mihai', ['path' => 'reports:mri:mioveni:Not A Path', 'text' => 'x'])->status, 'not a valid path');
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $url, ?string $user, array $body = []): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request($method, $url, cookies: $cookies, body: $body !== [] ? (string) json_encode($body) : ''));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
