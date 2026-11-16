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

/** Workup (D63): four blocks gathered under the caller's visibility, one de-identified synthesis */
final class WorkupTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261001-popescu-ana';
    private const CNP = '2600101000006';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeServer();
        $tiers = ['model' => 'some-model'];
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => $tiers, 'expert' => $tiers, 'lite' => $tiers]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $storage = $this->storage();
        $storage->create('ai:profiles:reports:special:workup', ['visibility' => 'private'], "{indication}\n{findings}\n{blocks}\nRăspunde.\n", 'owner');
        $patient = ['name' => 'POPESCU Ana', 'cnp' => self::CNP, 'born' => 1960, 'sex' => 'F'];
        $storage->create(self::PATH, ['visibility' => 'private', 'modality' => ['MR'], 'region' => ['cerebral'], 'study_date' => '2026-10-01', 'indication' => 'Suspiciune accident vascular cerebral acut', 'patient' => $patient],
            "# POPESCU Ana\n\n## IRM cerebral\n\n### Descriere\n\nLeziune ischemică acută capsulară stângă la pacienta Popescu, hiperintensă în difuzie.\n\n### Concluzii\n\nInfarct lacunar acut capsular stâng.\n", 'mihai');
        // The same patient's earlier study, finished: context
        $storage->create('reports:mri:mioveni:250101-popescu-ana', ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'study_date' => '2025-01-01', 'patient' => $patient],
            "# POPESCU Ana\n\n## IRM cerebral\n\n### Concluzii\n\nFără leziuni ischemice. PRIORMARK.\n", 'mihai');
        // Another patient, finished, readable: similar
        $storage->create('reports:mri:mioveni:260110-ionescu-vasile', ['visibility' => 'private', 'status' => 'archived', 'modality' => ['MR'], 'study_date' => '2026-01-10', 'patient' => ['name' => 'IONESCU Vasile', 'cnp' => '1900101000002']],
            "# IONESCU Vasile\n\n## IRM cerebral\n\n### Descriere\n\nLeziune ischemică acută capsulară dreaptă la Ionescu, hiperintensă în difuzie.\n\n### Concluzii\n\nInfarct lacunar acut. SIMILARMARK.\n", 'mihai');
        // Outside mihai's grants: never shown
        $storage->create('reports:ct:mioveni:260112-dinu-ana', ['visibility' => 'private', 'status' => 'archived', 'modality' => ['CT'], 'patient' => ['name' => 'DINU Ana']],
            "# DINU Ana\n\n## CT cerebral\n\n### Descriere\n\nLeziune ischemică acută capsulară HIDDENCT.\n", 'owner');
        // A local protocol, readable by everyone
        $storage->create('protocols:avc', ['visibility' => 'public'], "# AVC acut\n\nAccident vascular acut: IRM cu difuzie, angio-CT dacă trombectomie. PROTOCOLMARK.\n", 'owner');
        Kernel::guide($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'))
            ->load((string) file_get_contents(\dirname(__DIR__) . '/fixtures/guide/guide-sample.csv'), 'GIRI@test', 'owner');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testTheBlocksAreGatheredUnderVisibilityAndTheSynthesisIsDeIdentified(): void
    {
        $response = $this->workup('mihai', ['path' => self::PATH]);
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        $blocks = $data['blocks'];

        self::assertStringContainsString('accident vascular', $blocks['context']['indication']);
        self::assertCount(1, $blocks['context']['studies']);
        self::assertStringContainsString('PRIORMARK', $blocks['context']['studies'][0]['text']);
        self::assertStringStartsWith('/r/', $blocks['context']['studies'][0]['href']);
        self::assertSame(['SIMILARMARK'], array_values(array_filter(array_map(static fn (array $s): string => str_contains($s['conclusion'], 'SIMILARMARK') ? 'SIMILARMARK' : '', $blocks['similar']))));
        self::assertStringNotContainsString('HIDDENCT', $response->body, 'outside the grants');
        self::assertStringNotContainsString('PRIORMARK', json_encode($blocks['similar']), 'the patient\'s own reports are context, not similar');
        self::assertSame('Accident vascular cerebral acut', $blocks['next']['guide'][0]['title']);
        self::assertSame([false, true], array_column($blocks['next']['guide'][0]['exams'], 'this'), 'the MR row');
        self::assertStringStartsWith('/guide/', $blocks['next']['guide'][0]['href']);
        self::assertStringContainsString('PROTOCOLMARK', $blocks['next']['protocols'][0]['text'] ?? '');
        self::assertStringNotContainsString('reports:', $response->body, 'reports by pid, never by path');
        self::assertNotNull($data['answer']);

        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        foreach (['[2.1]', '[3.1]', '[4.1] Ghid', '← acest examen', 'PROTOCOLMARK', 'hiperintensă în difuzie'] as $must) {
            self::assertStringContainsString($must, $user, $must);
        }
        foreach (['Popescu', 'POPESCU', 'Ionescu', 'IONESCU', self::CNP, '1900101000002', 'reports:', 'HIDDENCT'] as $never) {
            self::assertStringNotContainsString($never, $user, $never);
        }
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"ai.call"', $audit);
    }

    public function testTheEditorsTextIsReadAndWithoutSynthesisNoModelIsAsked(): void
    {
        $response = $this->workup('mihai', ['path' => self::PATH, 'text' => "# POPESCU Ana\n\n## IRM cerebral\n\n### Descriere\n\nLeziune ischemică acută capsulară.\n", 'synthesis' => false]);
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertNull($data['answer']);
        self::assertNull($this->server->lastRequest()['body'], 'no model asked');
        self::assertNotSame([], $data['blocks']['similar']);
    }

    public function testRefusals(): void
    {
        self::assertSame(404, $this->workup(null, ['path' => self::PATH])->status);
        self::assertSame(404, $this->workup('mihai', ['path' => 'reports:ct:mioveni:260112-dinu-ana'])->status);
        $this->storage()->delete('ai:profiles:reports:special:workup', 'owner');
        $data = json_decode($this->workup('mihai', ['path' => self::PATH])->body, true)['data'];
        self::assertNull($data['answer'], 'no prompt: the blocks alone');
        self::assertNotSame([], $data['notices']);
    }

    private function workup(?string $user, array $body): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/workup', cookies: $cookies, body: (string) json_encode($body)));
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
