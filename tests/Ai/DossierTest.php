<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use Dompdf\Dompdf;
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

/** The Dossier (D64): a patient's folder read and summed up for the report in front, de-identified, nothing kept */
final class DossierTest extends HttpTestCase
{
    private const PATH = 'reports:mri:mioveni:261001-popescu-ana';
    private const CNP = '2600101000006';

    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/usr/bin/pdftotext') || !is_executable('/usr/bin/pdfinfo')) {
            self::markTestSkipped('poppler-utils not installed');
        }
        $this->server = new FakeServer();
        $tiers = ['model' => 'some-model'];
        $this->config['ai'] = ['enabled' => true, 'servers' => [['endpoint' => $this->server->url, 'tiers' => ['normal' => $tiers, 'expert' => $tiers, 'lite' => $tiers]]], 'retry_delays' => [0, 0, 0]];
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->makeRadiologist('mihai');
        $storage = $this->storage();
        $storage->create('ai:profiles:reports:panels:dossier-onco', ['visibility' => 'private'], "{exam}\n{pages}\n{dossier}\nRăspunde.\n", 'owner');
        $storage->create(self::PATH, ['visibility' => 'private', 'modality' => ['MR'], 'study_date' => '2026-10-01', 'patient' => ['name' => 'POPESCU Ana', 'cnp' => self::CNP, 'born' => 2015, 'sex' => 'F']],
            "# POPESCU Ana\n\n## IRM cerebral\n\n### Concluzii\n\n", 'mihai');
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
        parent::tearDown();
    }

    public function testTheFolderIsReadAndSentWithoutThePatientsIdentifiers(): void
    {
        $pdf = self::pdf('<p>Bilet de externare. Pacienta POPESCU Ana, CNP ' . self::CNP . ', telefon 0722 123 456.</p>'
            . '<p>Diagnostic: meduloblastom DOSARMARK, operat 2025-03-10. Mama: Popesou Ana. Alt CNP 1900101000002.</p>');
        $response = $this->dossier('mihai', ['path' => self::PATH, 'prompt' => 'dossier-onco', 'name' => 'dosar.pdf', 'file' => base64_encode($pdf)]);
        self::assertSame(200, $response->status, $response->body);
        $data = json_decode($response->body, true)['data'];
        self::assertSame(1, $data['pages']);
        self::assertSame(0, $data['ocr']);
        self::assertNotSame('', $data['answer']);

        $user = $this->server->lastRequest()['body']['messages'][1]['content'];
        self::assertStringContainsString('DOSARMARK', $user);
        self::assertStringContainsString('=== p. 1 ===', $user);
        foreach (['POPESCU', 'Popescu', 'Popesou', self::CNP, '1900101000002', '0722', 'reports:'] as $never) {
            self::assertStringNotContainsString($never, $user, $never);
        }
        self::assertSame([], glob($this->dataRoot . '/tmp/library-*') ?: [], 'nothing kept');
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"ai.call"', $audit);
        self::assertStringNotContainsString('DOSARMARK', $audit);
    }

    public function testRefusals(): void
    {
        $file = base64_encode(self::pdf('<p>Text</p>'));
        self::assertSame(404, $this->dossier(null, ['path' => self::PATH, 'name' => 'a.pdf', 'file' => $file])->status);
        self::assertSame(422, $this->dossier('mihai', ['path' => self::PATH, 'prompt' => 'dossier-onco', 'name' => 'a.docx', 'file' => $file])->status);
        $none = $this->dossier('mihai', ['path' => self::PATH, 'prompt' => 'dossier-neuro', 'name' => 'a.pdf', 'file' => $file]);
        self::assertSame(422, $none->status);
        self::assertSame('no_prompt', json_decode($none->body, true)['error']['code']);
        self::assertSame(422, $this->dossier('mihai', ['path' => self::PATH, 'prompt' => 'dossier-onco', 'name' => 'a.pdf', 'file' => '%%%'])->status);
        self::assertNull($this->server->lastRequest()['body'], 'no model asked');
    }

    private static function pdf(string $html): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<html><body>' . $html . '</body></html>');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param array<string, mixed> $body */
    private function dossier(?string $user, array $body): Response
    {
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request('POST', '/api/v1/dossier', cookies: $cookies, body: (string) json_encode($body)));
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }
}
