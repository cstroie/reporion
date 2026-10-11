<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use DateTimeImmutable;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;
use Reporion\Plugin\Dicom\Plugin;
use Reporion\Plugin\Loader;
use Reporion\Tests\Http\HttpTestCase;
use Reporion\Tests\Support\CnpTest;

/**
 * The dicom plugin's worklist end to end against a real PACS — real findscu,
 * a dcmqrscp holding made-up studies (invariant 10) — where DicomTest's fake
 * findscu answers by its own matching rules. Skipped, saying why, where no
 * dcmtk is found (tests/Plugin/Dcmtk, DCMTK_BIN).
 */
final class DicomPacsTest extends HttpTestCase
{
    private const UID_CT_SR = '1.2.826.0.1.3680043.2.1125.7.1';
    private const UID_MR = '1.2.826.0.1.3680043.2.1125.8.1';

    private string $bin;
    private string $pacsDir;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        [$bin, $why] = Dcmtk::bin(['findscu', 'dcmqrscp', 'dcmqridx', 'dump2dcm']);
        if ($bin === null) {
            self::markTestSkipped($why);
        }
        $this->bin = $bin;
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');

        $this->pacsDir = $this->dataRoot . '/pacs';
        mkdir($this->pacsDir . '/db', 0700, true);
        $cnp = CnpTest::make(1, '700101');
        // One study, two series: a CT image and the SR of its report — ModalitiesInStudy CT\SR
        $files = [
            $this->instance('ct', self::UID_CT_SR, '1.2.840.10008.5.1.4.1.1.2', 'CT', 'MARIN^DAN', $cnp),
            $this->instance('sr', self::UID_CT_SR, '1.2.840.10008.5.1.4.1.1.88.11', 'SR', 'MARIN^DAN', $cnp),
            $this->instance('mr', self::UID_MR, '1.2.840.10008.5.1.4.1.1.4', 'MR', 'VLAD^ANA', CnpTest::make(2, '810202')),
        ];
        exec(escapeshellarg($this->bin . '/dcmqridx') . ' ' . escapeshellarg($this->pacsDir . '/db') . ' ' . implode(' ', array_map('escapeshellarg', $files)));

        $port = random_int(20000, 40000);
        file_put_contents($this->pacsDir . '/qr.cfg', "NetworkTCPPort = {$port}\nMaxPDUSize = 16384\nMaxAssociations = 16\n"
            . "HostTable BEGIN\nHostTable END\nVendorTable BEGIN\nVendorTable END\nAETable BEGIN\nTESTPACS {$this->pacsDir}/db RW (200, 1024mb) ANY\nAETable END\n");
        $this->server = proc_open([$this->bin . '/dcmqrscp', '-c', $this->pacsDir . '/qr.cfg', (string) $port], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes) ?: null;
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; ++$i) {
            usleep(100000);
        }

        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV', 'devices' => []]];
        $this->config['plugins'] = [
            'enabled' => ['dicom'],
            'settings' => ['dicom' => [
                'findscu' => $this->bin . '/findscu',
                'modalities' => 'CT, MR',
                'servers' => ['mioveni' => ['host' => '127.0.0.1', 'port' => $port, 'aet' => 'TESTPACS', 'calling_aet' => 'RP_MIOVENI']],
            ]],
        ];
        (new FlatFileUserStore($this->dataRoot))->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        Plugin::$runner = null;
        Plugin::$today = new DateTimeImmutable('2026-09-29');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        Plugin::$today = null;
        parent::tearDown();
    }

    public function testTheWorklistFindsAStudyOfSeveralModalitiesUnderEachOfItsOwn(): void
    {
        $ct = $this->get('/x/dicom/worklist?site=mioveni&modality=CT&from=2026-09-28&to=2026-09-28');
        self::assertSame(200, $ct->status);
        self::assertStringNotContainsString('The PACS did not answer', $ct->body);
        self::assertStringContainsString('MARIN Dan', $ct->body, 'a CT query finds the CT\\SR study');
        self::assertStringContainsString('ref=' . rawurlencode('mioveni:CT:' . self::UID_CT_SR), $ct->body);
        // dcmqrscp neither matches nor returns ModalitiesInStudy, so it cannot
        // show a CT query leaving the MR study out: docs/test-doubles-audit.md §4

        $mr = $this->get('/x/dicom/worklist?site=mioveni&modality=MR&from=2026-09-28&to=2026-09-28');
        self::assertStringContainsString('VLAD Ana', $mr->body);
        self::assertStringContainsString('ref=' . rawurlencode('mioveni:MR:' . self::UID_MR), $mr->body);
        self::assertStringNotContainsString(rawurlencode('mioveni:SR:'), $mr->body, 'SR is not a modality the settings list');
    }

    private function get(string $path): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request('GET', $route, query: array_map('strval', $params), cookies: ['reporion' => $cookie]));
    }

    /** One instance of a made-up study on 2026-09-28, as a .dcm file */
    private function instance(string $name, string $studyUid, string $sopClass, string $modality, string $patient, string $id): string
    {
        $series = $studyUid . '.' . \strlen($name) . crc32($name) % 1000;
        file_put_contents($this->pacsDir . '/' . $name . '.dump', implode("\n", [
            '(0008,0005) CS [ISO_IR 100]',
            '(0008,0016) UI [' . $sopClass . ']',
            '(0008,0018) UI [' . $series . '.1]',
            '(0008,0020) DA [20260928]',
            '(0008,0030) TM [101500]',
            '(0008,0050) SH [ACC' . strtoupper($name) . ']',
            '(0008,0060) CS [' . $modality . ']',
            '(0008,1030) LO [TEST STUDY]',
            '(0010,0010) PN [' . $patient . ']',
            '(0010,0020) LO [' . $id . ']',
            '(0020,000d) UI [' . $studyUid . ']',
            '(0020,000e) UI [' . $series . ']',
        ]) . "\n");
        $file = $this->pacsDir . '/' . $name . '.dcm';
        exec(escapeshellarg($this->bin . '/dump2dcm') . ' -q ' . escapeshellarg($this->pacsDir . '/' . $name . '.dump') . ' ' . escapeshellarg($file));

        return $file;
    }
}
