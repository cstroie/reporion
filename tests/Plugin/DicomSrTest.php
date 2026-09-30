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
use Reporion\Plugin\Dicom\Sr\Uid;
use Reporion\Plugin\Dicom\Sr\Writer;
use Reporion\Plugin\Loader;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;
use Reporion\Tests\Support\CnpTest;

/**
 * plugins/dicom's SR export: a signed report as a DICOM Basic Text SR file.
 * The file is read back by a small parser in this test (Explicit VR Little
 * Endian, as Sr\Writer writes it) and, when dcmtk is installed, by dcmdump
 * and dsr2html. Names, CNPs and UIDs are made up (invariant 10).
 */
final class DicomSrTest extends HttpTestCase
{
    private const REPORT = 'reports:ct:mioveni:260928-ionescu-maria';
    private const BODY = "# IONESCU Maria\n\n**Indicație:** dureri toracice.\n\n## CT torace nativ\n\n### Tehnică\n\nAcvizitie spirala, fara contrast.\n\n### Descriere\n\nParenchim pulmonar **normal** aerat.\n\n- fara noduli\n- fara colectii\n\n### Concluzii\n\nExamen CT toracic in limite normale.\n";

    private const LONG = ['OB', 'OD', 'OF', 'OL', 'OW', 'SQ', 'UC', 'UN', 'UR', 'UT'];

    private string $cnp;

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $this->cnp = CnpTest::make(2, '800115');
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'devices' => []]];
        $this->config['plugins'] = ['enabled' => ['dicom'], 'settings' => ['dicom' => ['servers' => []]]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true);
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
        $users->create('outsider', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Editor)]);
    }

    public function testASignedReportDownloadsAsAnSrFileThatReadsBack(): void
    {
        $this->signedReport();

        $response = $this->get('owner', '/x/dicom/sr/' . $this->pid());

        self::assertSame(200, $response->status);
        self::assertSame('application/dicom', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="MV-CT-26-1-rev1.dcm"', $response->headers['Content-Disposition'], 'accession + rev, never the path');
        $bytes = $response->body;
        self::assertSame('DICM', substr($bytes, 128, 4));
        self::assertSame(str_repeat("\0", 128), substr($bytes, 0, 128));

        [$meta, $set] = $this->read($bytes);
        self::assertSame('1.2.840.10008.5.1.4.1.1.88.11', $set[0x00080016][1]);
        self::assertSame($set[0x00080016][1], $meta[0x00020002][1]);
        self::assertSame($set[0x00080018][1], $meta[0x00020003][1], 'the file meta names the same instance');
        self::assertSame('1.2.840.10008.1.2.1', $meta[0x00020010][1]);
        self::assertSame('SR', $set[0x00080060][1]);
        self::assertSame('ISO_IR 192', $set[0x00080005][1]);
        self::assertSame('MV-CT-26-1', $set[0x00080050][1]);
        self::assertSame('20260928', $set[0x00080020][1]);
        self::assertSame('101500', $set[0x00080030][1]);
        self::assertSame('IONESCU^Maria', $set[0x00100010][1]);
        self::assertSame($this->cnp, $set[0x00100020][1], 'the PACS plugin looks patients up by CNP');
        self::assertSame('F', $set[0x00100040][1]);
        self::assertSame('COMPLETE', $set[0x0040A491][1]);
        self::assertSame('VERIFIED', $set[0x0040A493][1]);
        self::assertSame('owner', $set[0x0040A073][1][0][0x0040A075][1]);
        self::assertMatchesRegularExpression('/^\d{14}[+-]\d{4}$/', $set[0x0040A073][1][0][0x0040A030][1]);
        self::assertSame('CONTAINER', $set[0x0040A040][1]);
        self::assertSame('18748-4', $set[0x0040A043][1][0][0x00080100][1]);
        self::assertArrayNotHasKey(0x0040A010, $set, 'the root has no relationship');

        foreach ([0x00080018, 0x0020000D, 0x0020000E] as $tag) {
            self::assertTrue(Uid::isValid($set[$tag][1]), dechex($tag));
        }

        $texts = $this->texts($set[0x0040A730][1]);
        self::assertContains('CT torace nativ', $texts);
        self::assertContains('Acvizitie spirala, fara contrast.', $texts, 'the technique');
        self::assertContains("Parenchim pulmonar normal aerat.", $texts, 'emphasis marks are not text');
        self::assertContains("- fara noduli\r\n- fara colectii", $texts, 'a list stays one item');
        self::assertContains('Examen CT toracic in limite normale.', $texts);
        self::assertContains('Indicație: dureri toracice.', $texts, 'diacritics survive (UTF-8)');
        self::assertNotContains('IONESCU Maria', $texts, 'the name heading is the patient block, not content');
        self::assertContains('rev 1 · /r/' . $this->pid() . '/1', $texts);

        $codes = $this->headings($set[0x0040A730][1]);
        // Heading containers are CID 7001 (LOINC) ...
        self::assertEqualsCanonicalizing(['55111-9', '11329-0', '59776-5', '19005-8'], array_values(array_unique($codes)), 'procedures, history, findings, impressions');
        // ... and the narrative items below them CID 7002
        $narrative = $this->narrativeCodes($set[0x0040A730][1]);
        self::assertEqualsCanonicalizing(['121065', '11329-0', '121071', '121073'], array_values(array_unique($narrative)));
        self::assertSame('DCMR', $set[0x0040A504][1][0][0x00080105][1], 'the template mapping resource');
        self::assertSame('2000', $set[0x0040A504][1][0][0x0040DB00][1], 'TID 2000');
        self::assertArrayHasKey(0x00081111, $set, 'Type 2, empty');
    }

    public function testTheSameRevisionExportsToTheSameBytes(): void
    {
        $this->signedReport();

        self::assertSame($this->get('owner', '/x/dicom/sr/' . $this->pid())->body, $this->get('viewer', '/x/dicom/sr/' . $this->pid())->body);
    }

    public function testOnlyASignedRevisionOfAReadableReportIsExported(): void
    {
        $this->storage()->create(self::REPORT, $this->frontmatter(), self::BODY, 'owner');
        $pid = $this->pid();

        $draft = $this->get('owner', '/x/dicom/sr/' . $pid);
        self::assertSame(409, $draft->status, 'a draft is never the verified document');

        $this->storage()->sign(self::REPORT, 'owner', []);
        self::assertSame(200, $this->get('viewer', '/x/dicom/sr/' . $pid)->status, 'a reader with a grant');
        self::assertSame(404, $this->get('outsider', '/x/dicom/sr/' . $pid)->status, 'no grant on the namespace');
        self::assertSame(404, $this->anonymous('/x/dicom/sr/' . $pid)->status);

        $this->storage()->save(self::REPORT, $this->frontmatter(), self::BODY . "\nAdaos.\n", 1, 'owner');
        self::assertSame(409, $this->get('owner', '/x/dicom/sr/' . $pid)->status, 'the new revision is not signed yet');
    }

    public function testTheExportMenuOffersTheSrOnlyForASignedReportAndASignedInReader(): void
    {
        $this->storage()->create(self::REPORT, $this->frontmatter('public'), self::BODY, 'owner');
        $link = '/x/dicom/sr/' . $this->pid();
        $page = '/' . self::REPORT;

        self::assertStringNotContainsString($link, $this->get('owner', $page)->body, 'a draft has no SR');

        $this->storage()->sign(self::REPORT, 'owner', []);
        self::assertStringContainsString($link, $this->get('viewer', $page)->body, 'a reader finds it in Export');
        self::assertStringNotContainsString($link, $this->anonymous($page)->body, 'an anonymous reader would get a 404');
    }

    public function testAPublicReportIsStillNotForAnonymousCallersAndOtherPagesAreNoReports(): void
    {
        $this->signedReport(visibility: 'public');
        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'public'], "Text\n", 'owner');
        $note = $this->storage()->read('docs:note')->pid;

        self::assertSame(404, $this->anonymous('/x/dicom/sr/' . $this->pid())->status, 'the file names the patient');
        self::assertSame(404, $this->get('owner', '/x/dicom/sr/' . $note)->status);
        self::assertSame(404, $this->get('owner', '/x/dicom/sr/01NOSUCHPID000000000000000')->status);
    }

    public function testTheExportIsAuditedByPidNeverByPath(): void
    {
        $this->signedReport();
        $this->get('owner', '/x/dicom/sr/' . $this->pid());

        $log = '';
        foreach (glob($this->dataRoot . '/audit/*') ?: [] as $file) {
            $log .= (string) file_get_contents($file);
        }
        self::assertStringContainsString('"action":"export"', $log);
        self::assertStringContainsString('"format":"dcm"', $log);
        self::assertStringNotContainsString('ionescu', strtolower($log), 'invariant 8');
    }

    public function testTheFileNeverCarriesThePath(): void
    {
        $this->signedReport();
        $bytes = $this->get('owner', '/x/dicom/sr/' . $this->pid())->body;

        self::assertStringNotContainsString('260928-ionescu', $bytes);
        self::assertStringNotContainsString(self::REPORT, $bytes);
    }

    public function testUidsAreDeterministicValidAndDecimalOfTheirHash(): void
    {
        $uid = Uid::derive('a', '1');

        self::assertSame($uid, Uid::derive('a', '1'));
        self::assertNotSame($uid, Uid::derive('a', '2'));
        self::assertTrue(Uid::isValid($uid));
        self::assertStringStartsWith('2.25.', $uid);
        if (\function_exists('gmp_strval')) {
            self::assertSame('2.25.' . gmp_strval(gmp_init(substr(hash('sha256', 'a|1'), 0, 32), 16)), $uid);
        }
        self::assertFalse(Uid::isValid('1.02.3'), 'no zero-led component');
        self::assertFalse(Uid::isValid('1..3'));
        self::assertFalse(Uid::isValid(str_repeat('1.', 33) . '1'), 'over 64');
    }

    public function testTheWriterPadsToEvenLengthsAndCutsToTheVrLimit(): void
    {
        [, $set] = $this->read(Writer::file('1.2.3', '1.2.4', [
            [0x00080050, 'SH', 'ABC'],
            [0x00080060, 'CS', str_repeat('X', 40)],
            [0x00080018, 'UI', '1.2.4'],
        ]));

        self::assertSame('ABC', $set[0x00080050][1]);
        self::assertSame(16, \strlen($set[0x00080060][1]), 'CS is at most 16');
        self::assertSame('1.2.4', $set[0x00080018][1]);
    }

    public function testDcmtkAcceptsTheFile(): void
    {
        $dcmdump = trim((string) shell_exec('command -v dcmdump'));
        $dsr2html = trim((string) shell_exec('command -v dsr2html'));
        if ($dcmdump === '' || $dsr2html === '') {
            self::markTestSkipped('dcmtk is not installed');
        }
        $this->signedReport();
        $file = $this->dataRoot . '/report.dcm';
        file_put_contents($file, $this->get('owner', '/x/dicom/sr/' . $this->pid())->body);

        foreach (['dsr2html', 'dsrdump'] as $tool) {
            $binary = trim((string) shell_exec('command -v ' . $tool));
            if ($binary === '') {
                continue;
            }
            $lines = [];
            exec(escapeshellarg($binary) . ' -v ' . escapeshellarg($file) . ' 2>&1', $lines, $status);
            $output = implode("\n", $lines);
            self::assertSame(0, $status, $tool . ': ' . $output);
            // dcmtk exits 0 on a document it merely warns about; its VR checker cannot judge UTF-8 text, which is not ours to fix
            $problems = array_filter($lines, static fn (string $l): bool => preg_match('/^[WE]: /', $l) === 1 && !str_contains($l, 'does not support this Specific Character Set'));
            self::assertSame([], array_values($problems), $tool);
            // dsrdump cuts long text short; dsr2html prints it whole
            self::assertStringContainsString($tool === 'dsr2html' ? 'Parenchim pulmonar normal aerat.' : 'Impression', $output, $tool);
            self::assertStringContainsString('Findings', $output, $tool);
        }

        exec(escapeshellarg($dcmdump) . ' ' . escapeshellarg($file) . ' 2>&1', $dump, $status);
        self::assertSame(0, $status);
        self::assertStringNotContainsString('Warning', implode("\n", $dump));
    }

    /** @return array<string, mixed> */
    private function frontmatter(string $visibility = 'private'): array
    {
        return [
            'title' => 'IONESCU Maria',
            'exam_title' => 'CT torace nativ',
            'visibility' => $visibility,
            'accession' => 'MV-CT-26-1',
            'site' => 'mioveni',
            'modality' => ['CT'],
            'study_date' => '2026-09-28 10:15:00',
            'patient' => ['name' => 'IONESCU Maria', 'cnp' => $this->cnp, 'sex' => 'F', 'born' => 1980],
            'referrer' => 'Popescu Ion',
        ];
    }

    private function signedReport(string $visibility = 'private'): void
    {
        $this->storage()->create(self::REPORT, $this->frontmatter($visibility), self::BODY, 'owner');
        $this->storage()->sign(self::REPORT, 'owner', []);
    }

    private function pid(): string
    {
        return $this->storage()->read(self::REPORT)->pid;
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function get(string $user, string $path): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($user);

        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $cookie]));
    }

    private function anonymous(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }

    /**
     * @return array{0: array<int, array{0: string, 1: mixed}>, 1: array<int, array{0: string, 1: mixed}>} file meta, dataset
     */
    private function read(string $bytes): array
    {
        self::assertSame('DICM', substr($bytes, 128, 4));
        $pos = 132;
        $meta = self::set($bytes, $pos, \strlen($bytes), 0x0002);
        // (0002,0000) counts the bytes after itself, up to the end of the group
        self::assertSame($pos - 132 - 12, unpack('V', (string) $meta[0x00020000][1])[1], 'the group length');
        return [$meta, self::set($bytes, $pos, \strlen($bytes))];
    }

    /** @return array<int, array{0: string, 1: mixed}> */
    private static function set(string $bytes, int &$pos, int $end, ?int $onlyGroup = null): array
    {
        $out = [];
        while ($pos < $end) {
            [$group, $element] = array_values(unpack('v2', substr($bytes, $pos, 4)));
            if ($onlyGroup !== null && $group !== $onlyGroup) {
                break;
            }
            $vr = substr($bytes, $pos + 4, 2);
            if (\in_array($vr, self::LONG, true)) {
                $length = unpack('V', substr($bytes, $pos + 8, 4))[1];
                $pos += 12;
            } else {
                $length = unpack('v', substr($bytes, $pos + 6, 2))[1];
                $pos += 8;
            }
            $tag = ($group << 16) | $element;
            self::assertSame(0, $length % 2, 'even length for ' . dechex($tag));
            if ($vr === 'SQ') {
                $items = [];
                $stop = $pos + $length;
                while ($pos < $stop) {
                    self::assertSame([0xFFFE, 0xE000], array_values(unpack('v2', substr($bytes, $pos, 4))));
                    $itemLength = unpack('V', substr($bytes, $pos + 4, 4))[1];
                    $pos += 8;
                    $itemEnd = $pos + $itemLength;
                    $items[] = self::set($bytes, $pos, $itemEnd);
                }
                $out[$tag] = [$vr, $items];
                continue;
            }
            $raw = substr($bytes, $pos, $length);
            $pos += $length;
            $out[$tag] = [$vr, match ($vr) {
                'UL' => (string) $raw,
                'OB' => $raw,
                default => rtrim($raw, " \0"),
            }];
            if ($vr === 'UL') {
                $out[$tag] = [$vr, $raw];
            }
        }
        if ($onlyGroup === null) {
            self::assertSame($end, $pos, 'the dataset ends where its length says');
        }

        return $out;
    }

    /**
     * @param list<array<int, array{0: string, 1: mixed}>> $items
     *
     * @return list<string>
     */
    private function texts(array $items): array
    {
        $found = [];
        foreach ($items as $item) {
            if (isset($item[0x0040A160])) {
                $found[] = $item[0x0040A160][1];
            }
            if (isset($item[0x0040A730])) {
                $found = [...$found, ...$this->texts($item[0x0040A730][1])];
            }
        }

        return $found;
    }

    /**
     * @param list<array<int, array{0: string, 1: mixed}>> $items
     *
     * @return list<string> the concept code of every TEXT item below, the trailing comment aside
     */
    private function narrativeCodes(array $items): array
    {
        $found = [];
        foreach ($items as $item) {
            if (($item[0x0040A040][1] ?? '') === 'TEXT' && $item[0x0040A043][1][0][0x00080100][1] !== '121106') {
                $found[] = $item[0x0040A043][1][0][0x00080100][1];
            }
            if (isset($item[0x0040A730])) {
                $found = [...$found, ...$this->narrativeCodes($item[0x0040A730][1])];
            }
        }

        return $found;
    }

    /**
     * @param list<array<int, array{0: string, 1: mixed}>> $items
     *
     * @return list<string> the concept code of every container below
     */
    private function headings(array $items): array
    {
        $found = [];
        foreach ($items as $item) {
            if (($item[0x0040A040][1] ?? '') === 'CONTAINER') {
                $found[] = $item[0x0040A043][1][0][0x00080100][1];
                $found = [...$found, ...$this->headings($item[0x0040A730][1])];
            }
        }

        return $found;
    }
}
