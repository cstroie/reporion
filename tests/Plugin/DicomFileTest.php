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
use Reporion\Kernel;
use Reporion\Plugin\Loader;
use Reporion\Tests\Http\HttpTestCase;
use Reporion\Tests\Support\CnpTest;
use Reporion\Tests\Support\DicomHeaderTest;

/**
 * "Start from a DICOM file" (plugins/dicom, GET|POST /x/dicom/file): the file
 * sent as the request body, kept under data/tmp/dicom until the guided form
 * has read it, and removed by that read. The files are built here (invariant
 * 10); names and CNPs are made up.
 */
final class DicomFileTest extends HttpTestCase
{
    private const UID = '1.2.826.0.1.3680043.2.1125.9.1';

    private string $cnp;

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $this->cnp = CnpTest::make(2, '800115');
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV', 'devices' => []]];
        $this->config['plugins'] = ['enabled' => ['dicom'], 'settings' => ['dicom' => []]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
    }

    private function dicom(string $sex = 'F'): string
    {
        return DicomHeaderTest::file('1.2.840.10008.1.2.1',
            DicomHeaderTest::ex(0x0008, 0x0005, 'CS', 'ISO_IR 100')
            . DicomHeaderTest::ex(0x0008, 0x0020, 'DA', '20260928')
            . DicomHeaderTest::ex(0x0008, 0x0030, 'TM', '101500')
            . DicomHeaderTest::ex(0x0008, 0x0050, 'SH', 'MV26777')
            . DicomHeaderTest::ex(0x0008, 0x0060, 'CS', 'MR')
            . DicomHeaderTest::ex(0x0008, 0x1030, 'LO', 'IRM CEREBRAL NATIV')
            . DicomHeaderTest::ex(0x0010, 0x0010, 'PN', 'IONESCU^MARIA')
            . DicomHeaderTest::ex(0x0010, 0x0020, 'LO', $this->cnp)
            . DicomHeaderTest::ex(0x0010, 0x0040, 'CS', $sex)
            . DicomHeaderTest::ex(0x0020, 0x000D, 'UI', self::UID)
            . DicomHeaderTest::ex(0x7FE0, 0x0010, 'OW', str_repeat('p', 64)));
    }

    private function send(?string $user, string $method, string $path, string $body = ''): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $cookies = $user !== null ? ['reporion' => (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user)] : [];

        return Kernel::boot($this->config)->handle(new Request($method, $route, query: array_map('strval', $params), cookies: $cookies, body: $body));
    }

    /** @return list<string> */
    private function stored(): array
    {
        return array_map('basename', glob($this->dataRoot . '/tmp/dicom/*') ?: []);
    }

    public function testTheFileIsKeptUntilTheFormReadsItAndThenRemoved(): void
    {
        $up = $this->send('owner', 'POST', '/x/dicom/file', $this->dicom());

        self::assertSame(201, $up->status);
        $url = (string) json_decode($up->body, true)['url'];
        self::assertMatchesRegularExpression('#^/new\?prefill=dicom-file&ref=[0-9a-f]{32}$#', $url);
        self::assertCount(1, $this->stored(), 'kept in data/tmp until used');
        self::assertStringNotContainsString('IONESCU', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), 'no patient data in the audit trail');

        $form = $this->send('owner', 'GET', $url);

        self::assertSame(200, $form->status);
        foreach (['value="IONESCU Maria"', 'value="' . $this->cnp . '"', 'value="2026-09-28"', 'value="10:15"', 'value="Irm Cerebral Nativ"',
            'name="study_uid" value="' . self::UID . '"', 'name="pacs_accession" value="MV26777"'] as $needle) {
            self::assertStringContainsString($needle, $form->body);
        }
        self::assertSame([], $this->stored(), 'removed once read');

        $again = $this->send('owner', 'GET', $url);
        self::assertStringNotContainsString('IONESCU', $again->body, 'a file is used once');
    }

    public function testSomethingThatIsNotDicomIsRefusedAndNotKept(): void
    {
        $up = $this->send('owner', 'POST', '/x/dicom/file', str_repeat('x', 400));

        self::assertSame(422, $up->status);
        self::assertSame([], $this->stored());
        self::assertSame(422, $this->send('owner', 'POST', '/x/dicom/file', '')->status);
    }

    public function testOnlyCallersWhoCreateReportsSeeItAndTheNewReportFormOffersIt(): void
    {
        foreach (['viewer', null] as $user) {
            self::assertSame(404, $this->send($user, 'GET', '/x/dicom/file')->status);
            self::assertSame(404, $this->send($user, 'POST', '/x/dicom/file', $this->dicom())->status);
        }
        self::assertSame([], $this->stored());

        $page = $this->send('owner', 'GET', '/x/dicom/file');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('type="file"', $page->body);

        $new = $this->send('owner', 'GET', '/new');
        self::assertStringContainsString('/x/dicom/file', $new->body);
        self::assertStringContainsString('/x/dicom/worklist', $new->body, 'the worklist button is still there: a slot holds a list');
    }

    public function testAFileThatWasNotReadInTimeIsGoneAndNobodyElseCanGuessIt(): void
    {
        $url = (string) json_decode($this->send('owner', 'POST', '/x/dicom/file', $this->dicom())->body, true)['url'];
        $file = (glob($this->dataRoot . '/tmp/dicom/*') ?: [''])[0];
        touch($file, time() - 7200);

        $form = $this->send('owner', 'GET', $url);

        self::assertStringNotContainsString('IONESCU', $form->body);
        self::assertSame([], $this->stored());
        self::assertSame(404, $this->send('owner', 'GET', '/new?prefill=dicom-file&ref=../../index')->status, 'a token that is not one finds nothing');
    }
}
