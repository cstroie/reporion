<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Dicom\DicomException;
use Reporion\Plugin\Dicom\Scu;
use Reporion\Plugin\Loader;

/**
 * plugins/dicom's Scu against real dcmtk: findscu/echoscu querying a
 * dcmqrscp test PACS holding two made-up studies (invariant 10). Skipped
 * where dcmtk is not installed.
 */
final class DicomScuTest extends TestCase
{
    private const UID1 = '1.2.826.0.1.3680043.2.1125.1.1';

    private string $dir;
    private string $bin;
    private int $port;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $findscu = trim((string) shell_exec('command -v findscu 2>/dev/null'));
        $this->bin = \dirname($findscu);
        foreach (['findscu', 'echoscu', 'dcmqrscp', 'dcmqridx', 'dump2dcm'] as $tool) {
            if ($findscu === '' || !is_executable($this->bin . '/' . $tool)) {
                self::markTestSkipped('dcmtk is not installed');
            }
        }
        $this->dir = sys_get_temp_dir() . '/reporion-pacs-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/db', 0700, true);
        $this->makeStudy('s1', self::UID1, '20260928', 'CT', 'IONESCU^MARIA', '2800115401234');
        $this->makeStudy('s2', '1.2.826.0.1.3680043.2.1125.2.1', '20260927', 'MR', "POPA^ANDR\xC9", 'PACS00042');
        exec(escapeshellarg($this->bin . '/dcmqridx') . ' ' . escapeshellarg($this->dir . '/db') . ' ' . escapeshellarg($this->dir . '/s1.dcm') . ' ' . escapeshellarg($this->dir . '/s2.dcm'));

        $this->port = random_int(20000, 40000);
        file_put_contents($this->dir . '/qr.cfg', "NetworkTCPPort = {$this->port}\nMaxPDUSize = 16384\nMaxAssociations = 16\n"
            . "HostTable BEGIN\nHostTable END\nVendorTable BEGIN\nVendorTable END\nAETable BEGIN\nTESTPACS {$this->dir}/db RW (200, 1024mb) ANY\nAETable END\n");
        $this->server = proc_open([$this->bin . '/dcmqrscp', '-c', $this->dir . '/qr.cfg', (string) $this->port], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes) ?: null;
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; ++$i) {
            usleep(100000);
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testAStudyLevelQueryByDateRangeAndByUid(): void
    {
        $scu = new Scu($this->bin . '/findscu', 'REPORION', 5);
        $pacs = ['host' => '127.0.0.1', 'port' => $this->port, 'aet' => 'TESTPACS'];

        $rows = $scu->findStudies($pacs, ['StudyDate' => '20260926-20260929']);
        self::assertCount(2, $rows);
        $byUid = array_column($rows, null, 'StudyInstanceUID');
        self::assertSame('IONESCU^MARIA', $byUid[self::UID1]['PatientName']);
        self::assertSame('2800115401234', $byUid[self::UID1]['PatientID']);
        self::assertSame('20260928', $byUid[self::UID1]['StudyDate']);
        self::assertSame('POPA^ANDRÉ', $byUid['1.2.826.0.1.3680043.2.1125.2.1']['PatientName'], 'ISO_IR 100 comes back as UTF-8');

        $one = $scu->findStudies($pacs, ['StudyInstanceUID' => self::UID1]);
        self::assertSame([self::UID1], array_column($one, 'StudyInstanceUID'));
        self::assertSame([], $scu->findStudies($pacs, ['StudyDate' => '20250101']));
        self::assertSame([], glob(sys_get_temp_dir() . '/reporion-dicom-*') ?: [], 'no answer file left behind');

        $scu->echo($pacs);
    }

    public function testFailuresAreFixedCodes(): void
    {
        $pacs = ['host' => '127.0.0.1', 'port' => $this->port, 'aet' => 'TESTPACS'];
        $cases = [
            'rejected' => [new Scu($this->bin . '/findscu', 'REPORION', 5), ['aet' => 'WRONG'] + $pacs],
            'unreachable' => [new Scu($this->bin . '/findscu', 'REPORION', 3), ['port' => 1] + $pacs],
            'no-tool' => [new Scu('/nonexistent/findscu', 'REPORION', 3), $pacs],
        ];
        foreach ($cases as $code => [$scu, $server]) {
            try {
                $scu->findStudies($server, []);
                self::fail('expected ' . $code);
            } catch (DicomException $e) {
                self::assertSame($code, $e->getMessage());
            }
        }
        try {
            (new Scu($this->bin . '/findscu', 'REPORION', 5))->echo(['aet' => 'WRONG'] + $pacs);
            self::fail('expected rejected');
        } catch (DicomException $e) {
            self::assertSame('rejected', $e->getMessage());
        }
    }

    private function makeStudy(string $file, string $uid, string $date, string $modality, string $name, string $id): void
    {
        file_put_contents($this->dir . '/' . $file . '.dump', implode("\n", [
            '(0008,0005) CS [ISO_IR 100]',
            '(0008,0016) UI [1.2.840.10008.5.1.4.1.1.2]',
            '(0008,0018) UI [' . $uid . '.1.1]',
            '(0008,0020) DA [' . $date . ']',
            '(0008,0030) TM [101500]',
            '(0008,0050) SH [ACC' . substr($date, 4) . ']',
            '(0008,0060) CS [' . $modality . ']',
            '(0008,1030) LO [TEST STUDY]',
            '(0010,0010) PN [' . $name . ']',
            '(0010,0020) LO [' . $id . ']',
            '(0020,000d) UI [' . $uid . ']',
            '(0020,000e) UI [' . $uid . '.1]',
        ]) . "\n");
        exec(escapeshellarg($this->bin . '/dump2dcm') . ' -q ' . escapeshellarg($this->dir . '/' . $file . '.dump') . ' ' . escapeshellarg($this->dir . '/' . $file . '.dcm'));
    }
}
