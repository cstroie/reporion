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
use Reporion\Plugin\Dicom\Plugin;
use Reporion\Plugin\Dicom\Sr\ReportContent;
use Reporion\Plugin\Loader;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;
use Reporion\Tests\Support\CnpTest;

/**
 * Roadmap phase 22b (D39 as amended 2026-10-02): a signed report's DICOM SR
 * stored to its site's PACS with storescu — by a button, only the signed
 * current revision, only into the linked study, only at a site whose PACS
 * row ticks "send SR"; each attempt recorded in meta.json, never in the frontmatter.
 * The process runner is faked, then the real dcmtk storescu talks to a real
 * storescp when dcmtk is installed. Names, CNPs and UIDs are made up
 * (invariant 10).
 */
final class DicomSendTest extends HttpTestCase
{
    private const REPORT = 'reports:ct:mioveni:260928-ionescu-maria';
    private const UID1 = '1.2.826.0.1.3680043.2.1125.1.1';
    private const UID2 = '1.2.826.0.1.3680043.2.1125.1.2';
    private const BODY = "# IONESCU Maria\n\n## CT torace nativ\n\n### Descriere\n\nParenchim pulmonar normal.\n\n### Concluzii\n\nNormal.\n";

    private string $cnp;

    /** @var list<array{argv: list<string>, file: string}> what the fake storescu was given */
    private array $calls = [];

    /** What the fake storescu answers: exit code and log */
    private array $answer = [0, "I: Received Store Response (Success)\n"];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $this->cnp = CnpTest::make(2, '800115');
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'devices' => []], 'scuc' => ['name' => 'Alt Spital', 'devices' => []]];
        $this->config['plugins'] = ['enabled' => ['dicom'], 'settings' => ['dicom' => [
            'findscu' => '/opt/dcmtk/bin/findscu',
            'servers' => [
                'mioveni' => ['host' => '10.0.0.5', 'port' => 104, 'aet' => 'MVPACS', 'calling_aet' => 'RP_MIOVENI', 'send_sr' => true],
                'scuc' => ['host' => '10.0.1.5', 'port' => 104, 'aet' => 'SCPACS', 'calling_aet' => 'RP_SCUC'],
            ],
        ]]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('editor', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
        Plugin::$runner = function (array $argv, int $timeout, bool $withStdout = false): array {
            if (basename($argv[0]) !== 'storescu') {
                return [0, '']; // the tab's own findscu lookup: no studies
            }
            $file = (string) end($argv);
            $this->calls[] = ['argv' => $argv, 'file' => is_file($file) ? (string) file_get_contents($file) : ''];

            return $this->answer;
        };
    }

    protected function tearDown(): void
    {
        Plugin::$runner = null;
        parent::tearDown();
    }

    public function testASignedLinkedReportIsStoredIntoItsStudyAndRecordedOutsideTheFrontmatter(): void
    {
        $this->report(['study_uid' => self::UID1], sign: true);
        $before = $this->storage()->read(self::REPORT);

        $response = $this->post('editor', '/x/dicom/send/' . $before->pid);

        self::assertSame(302, $response->status);
        self::assertStringEndsWith('?sent=1#sr', $response->headers['Location']);
        self::assertCount(1, $this->calls);
        $argv = $this->calls[0]['argv'];
        self::assertSame('/opt/dcmtk/bin/storescu', $argv[0], 'next to findscu');
        self::assertContains('--required', $argv);
        self::assertSame(['RP_MIOVENI', 'MVPACS'], [$argv[array_search('-aet', $argv, true) + 1], $argv[array_search('-aec', $argv, true) + 1]]);
        self::assertStringNotContainsString('IONESCU', implode(' ', $argv), 'the patient travels in the file, never on the command line');
        self::assertStringContainsString(self::UID1, $this->calls[0]['file']);
        self::assertStringContainsString(ReportContent::instanceUid($before), $this->calls[0]['file'], 'the same instance as the download');
        self::assertFileDoesNotExist((string) end($argv), 'the temporary file is removed');

        $after = $this->storage()->read(self::REPORT);
        self::assertSame($before->rev, $after->rev, 'no revision made');
        self::assertSame($before->frontmatter, $after->frontmatter);
        self::assertSame('signed', $after->status, 'still signed (D3)');
        $delivery = $after->meta['deliveries'][0];
        self::assertSame(['pacs', 1, 'editor', 'ok', 'mioveni', self::UID1], [$delivery['to'], $delivery['rev'], $delivery['by'], $delivery['outcome'], $delivery['site'], $delivery['study_uid']]);

        $audit = (string) file_get_contents((string) glob($this->dataRoot . '/audit/*.ndjson')[0]);
        self::assertStringContainsString('"report.deliver"', $audit);
        self::assertStringNotContainsString('ionescu', $audit, 'by pid, never the path');

        $tab = $this->get('editor', '/x/dicom/study/' . $before->pid . '?sent=1')->body;
        self::assertStringContainsString(t('dicom.sr.done'), $tab);
        self::assertStringContainsString(t('dicom.sr.sent_rev', [1]), $tab);
    }

    public function testOnlyASignedLinkedReportAtAnAllowedSiteHasTheButton(): void
    {
        $this->report(['study_uid' => self::UID1], sign: false);
        $pid = $this->storage()->read(self::REPORT)->pid;
        $tab = $this->get('owner', '/x/dicom/study/' . $pid)->body;
        self::assertStringNotContainsString('/x/dicom/send/', $tab);
        self::assertStringContainsString(t('dicom.sr.why.unsigned'), $tab);
        self::assertSame(422, $this->post('owner', '/x/dicom/send/' . $pid)->status, 'a hand-made POST is refused too');
        self::assertSame([], $this->calls);
        self::assertArrayNotHasKey('deliveries', $this->storage()->read(self::REPORT)->meta, 'nothing tried, nothing recorded');

        $this->storage()->sign(self::REPORT, 'owner', []);
        self::assertStringContainsString('/x/dicom/send/', $this->get('owner', '/x/dicom/study/' . $pid)->body);

        $this->config['plugins']['settings']['dicom']['servers']['mioveni']['send_sr'] = false;
        self::assertStringContainsString(htmlspecialchars(t('dicom.sr.why.sr-off', ['mioveni']), ENT_QUOTES), $this->get('owner', '/x/dicom/study/' . $pid)->body, 'off unless the owner ticks the site');
    }

    public function testAnUnlinkedReportIsNeverSentAndAViewerCannotSend(): void
    {
        $this->report([], sign: true);
        $pid = $this->storage()->read(self::REPORT)->pid;
        self::assertStringContainsString(t('dicom.sr.why.unlinked'), $this->get('owner', '/x/dicom/study/' . $pid)->body);
        self::assertSame(422, $this->post('owner', '/x/dicom/send/' . $pid)->status);

        $this->report(['study_uid' => self::UID1], sign: true, path: 'reports:ct:mioveni:260928-ionescu-maria-2');
        self::assertSame(404, $this->post('viewer', '/x/dicom/send/' . $this->storage()->read('reports:ct:mioveni:260928-ionescu-maria-2')->pid)->status);
        self::assertSame([], $this->calls);
    }

    public function testARefusalIsRecordedAndOnlyTheOwnerSeesTheLog(): void
    {
        $this->report(['study_uid' => self::UID1], sign: true);
        $pid = $this->storage()->read(self::REPORT)->pid;
        $this->answer = [0, "I: Association Accepted\nE: Store Failed, file: /tmp/x/report.dcm:\nE: 0006:0208 DIMSE Failed to send message\n"];

        $owner = $this->post('owner', '/x/dicom/send/' . $pid);
        self::assertSame(422, $owner->status);
        self::assertStringContainsString(t('dicom.err.refused'), $owner->body);
        self::assertStringContainsString('DIMSE Failed to send message', $owner->body);
        self::assertSame('refused', $this->storage()->read(self::REPORT)->meta['deliveries'][0]['outcome']);

        $editor = $this->post('editor', '/x/dicom/send/' . $pid);
        self::assertStringContainsString(t('dicom.err.refused'), $editor->body);
        self::assertStringNotContainsString('DIMSE Failed', $editor->body, 'the log is the owner\'s');
    }

    public function testACorrectedRevisionIsANewInstanceInTheSameSeries(): void
    {
        $this->report(['study_uid' => self::UID1], sign: true);
        $storage = $this->storage();
        $pid = $storage->read(self::REPORT)->pid;
        $this->post('owner', '/x/dicom/send/' . $pid);
        $first = $storage->read(self::REPORT);
        $storage->save(self::REPORT, $first->frontmatter, self::BODY . "\nAddendum.\n", $first->rev, 'owner');
        $storage->sign(self::REPORT, 'owner', []);

        self::assertStringContainsString(t('dicom.sr.not_sent_rev', [2]), $this->get('owner', '/x/dicom/study/' . $pid)->body);
        $this->post('owner', '/x/dicom/send/' . $pid);

        $deliveries = $storage->read(self::REPORT)->meta['deliveries'];
        self::assertSame([1, 2], array_column($deliveries, 'rev'));
        self::assertNotSame($deliveries[0]['sop_instance'], $deliveries[1]['sop_instance']);
        self::assertSame(self::series($this->calls[0]['file']), self::series($this->calls[1]['file']), 'the same series');
    }

    public function testAMultiExamReportSendsOneSrPerStudy(): void
    {
        $this->report(['exams' => [
            ['title' => 'CT torace nativ', 'accession' => 'MV-CT-26-1', 'study_uid' => self::UID1],
            ['title' => 'CT abdomen', 'accession' => 'MV-CT-26-2', 'study_uid' => self::UID2],
        ]], sign: true, body: "# IONESCU Maria\n\n## CT torace nativ\n\n### Concluzii\n\nNormal.\n\n## CT abdomen\n\n### Concluzii\n\nNormal.\n");
        $this->post('owner', '/x/dicom/send/' . $this->storage()->read(self::REPORT)->pid);

        self::assertCount(2, $this->calls);
        self::assertStringContainsString(self::UID1, $this->calls[0]['file']);
        self::assertStringContainsString('MV-CT-26-2', $this->calls[1]['file'], 'each study its own accession');
        $deliveries = $this->storage()->read(self::REPORT)->meta['deliveries'];
        self::assertSame([self::UID1, self::UID2], array_column($deliveries, 'study_uid'));
        self::assertNotSame($deliveries[0]['sop_instance'], $deliveries[1]['sop_instance']);
    }

    public function testRealStorescuToARealStorescp(): void
    {
        $tools = array_map(static fn (string $tool): string => trim((string) shell_exec('command -v ' . $tool . ' 2>/dev/null')), ['storescp', 'storescu', 'dcmdump']);
        if (\in_array('', $tools, true)) {
            self::markTestSkipped('dcmtk is not installed');
        }
        Plugin::$runner = null;
        $port = random_int(20000, 40000);
        $inbox = $this->dataRoot . '/inbox';
        mkdir($inbox);
        $scp = proc_open(['storescp', '-aet', 'TESTPACS', '-od', $inbox, (string) $port], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($scp);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; ++$i) {
                usleep(100000);
            }
            $this->config['plugins']['settings']['dicom']['findscu'] = \dirname((string) $tools[2]) . '/findscu';
            $this->config['plugins']['settings']['dicom']['servers']['mioveni'] = ['host' => '127.0.0.1', 'port' => $port, 'aet' => 'TESTPACS', 'calling_aet' => 'RP_MIOVENI', 'send_sr' => true];
            $this->report(['study_uid' => self::UID1], sign: true);

            self::assertSame(302, $this->post('owner', '/x/dicom/send/' . $this->storage()->read(self::REPORT)->pid)->status);

            $received = glob($inbox . '/*') ?: [];
            self::assertCount(1, $received);
            exec('dcmdump ' . escapeshellarg($received[0]), $dump);
            $dump = implode("\n", $dump);
            self::assertStringContainsString('=BasicTextSRStorage', $dump);
            self::assertStringContainsString(self::UID1, $dump);
            self::assertStringContainsString('VERIFIED', $dump);

            $this->config['plugins']['settings']['dicom']['servers']['mioveni']['port'] = $port === 40000 ? 39999 : $port + 1;
            $down = $this->post('owner', '/x/dicom/send/' . $this->storage()->read(self::REPORT)->pid);
            self::assertSame(422, $down->status, 'nothing listens there');
            self::assertStringContainsString(t('dicom.err.unreachable'), $down->body);
            self::assertSame(['ok', 'unreachable'], array_column($this->storage()->read(self::REPORT)->meta['deliveries'], 'outcome'));
        } finally {
            proc_terminate($scp, 9);
            proc_close($scp);
        }
    }

    /** @param array<string, mixed> $extra */
    private function report(array $extra, bool $sign, string $path = self::REPORT, string $body = self::BODY): void
    {
        $this->storage()->create($path, [
            'title' => 'IONESCU Maria', 'exam_title' => 'CT torace nativ', 'visibility' => 'private', 'accession' => 'MV-CT-26-1',
            'site' => 'mioveni', 'modality' => ['CT'], 'study_date' => '2026-09-28 10:15:00',
            'patient' => ['name' => 'IONESCU Maria', 'cnp' => $this->cnp, 'sex' => 'F'],
        ] + $extra, $body, 'owner');
        if ($sign) {
            $this->storage()->sign($path, 'owner', []);
        }
    }

    /** The Series Instance UID (0020,000E) in an SR file's bytes */
    private static function series(string $bytes): string
    {
        $at = strpos($bytes, "\x20\x00\x0E\x00UI");
        self::assertNotFalse($at);
        $length = unpack('v', substr($bytes, $at + 6, 2))[1];

        return rtrim(substr($bytes, $at + 8, $length), "\0");
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function post(string $user, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('POST', $path, cookies: ['reporion' => $this->cookie($user)]));
    }

    private function get(string $user, string $path): Response
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        return Kernel::boot($this->config)->handle(new Request('GET', $route, query: array_map('strval', $params), cookies: ['reporion' => $this->cookie($user)]));
    }

    private function cookie(string $user): string
    {
        return (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);
    }
}
