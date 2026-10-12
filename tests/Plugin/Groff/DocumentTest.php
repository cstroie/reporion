<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Groff;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Ms\Document;
use Reporion\Plugin\Loader;
use Reporion\Storage\PageRecord;

/** The whole ms file of a report, built from made-up data (invariant 10). */
final class DocumentTest extends TestCase
{
    private const PREAMBLE = ".nr LL 17c\n.ds FAM {font}\n.so /etc/passwd\n\n.\\\" a comment\n.sy id";

    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'groff');
        reporion_plugin_strings(require \dirname(__DIR__, 3) . '/plugins/groff/lang/en.php');
    }

    private function record(array $fm = []): PageRecord
    {
        return new PageRecord(
            'PID', 'reports:mri:mioveni:260923-test-patient', 3, 'signed', 'private',
            $fm + [
                'title' => 'TEST PATIENT', 'exam_title' => 'RM genunchi drept', 'accession' => 'MV-MR-26-0001', 'site' => 'mioveni',
                'study_date' => '2026-09-23T10:15:00+03:00', 'indication' => 'Gonalgie dreaptă.', 'referrer' => 'Dr. Test', 'device' => 'MV-MR-01', 'protocol' => 'T2 fs',
                'patient' => ['name' => 'TEST PATIENT', 'born' => 1970, 'sex' => 'F'],
                'exams' => [['title' => 'RM genunchi drept', 'accession' => 'MV-MR-26-0001', 'pacs_accession' => 'PX2101']],
            ],
            "# TEST PATIENT\n\n## RM genunchi drept\n",
            [],
            [],
        );
    }

    /** @return list<string> */
    private function lines(?array $signer = ['name' => 'Dr. Test Signer', 'parafa' => 'P-9', 'at' => '2026-09-23 11:02']): array
    {
        $source = Document::build($this->record(), ['name' => 'Spital Test', 'devices' => ['MV-MR-01' => 'Siemens 3T']], ['.SH 1', 'RM genunchi drept', '.PP', 'Text.'], $signer, 'https://wiki.test/r/PID/3', self::PREAMBLE, 'BT');

        return explode("\n", rtrim($source, "\n"));
    }

    public function testTheFileFollowsTheHouseStyle(): void
    {
        $lines = $this->lines();

        self::assertSame('.\\" RM genunchi drept — exported from Reporion, rev 3', $lines[0]);
        self::assertSame(['.TL', 'RM genunchi drept', '.AU', 'Spital Test', '.AI', 'Buletin imagistic · PX2101 · 2026-09-23', '.AB no', 'Gonalgie dreaptă.', '.AE', '.PP'], \array_slice($lines, array_search('.TL', $lines, true), 10));
        self::assertContains('\\f[B]Pacient:\\f[R] TEST PATIENT, 56 ani (F)\\h\'1.5c\'\\f[B]Data examinării:\\f[R] 2026-09-23 10:15', $lines);
        self::assertContains('\\f[B]Trimis de:\\f[R] Dr. Test\\h\'1.5c\'\\f[B]Aparat:\\f[R] Siemens 3T', $lines);
        self::assertContains('\\f[B]Protocol:\\f[R] T2 fs', $lines);
        self::assertContains('.SH 1', $lines, 'the body');
        self::assertSame(['.LP', '\\f[B]Dr. Test Signer\\f[R] · Parafa P-9 · semnat electronic 2026-09-23 11:02', '.br', 'rev 3 · semnat', '.br', '\\s-2\\f[CR]\\%https://wiki.test/r/PID/3\\f[R]\\s0'], \array_slice($lines, -6));
    }

    public function testOnlyThePacsAccessionIsInTheFileNeverOurs(): void
    {
        $source = implode("\n", $this->lines());

        self::assertStringContainsString('PX2101', $source);
        self::assertStringNotContainsString('MV-MR-26-0001', $source);
    }

    public function testNeitherThePathNorThePatientNameIsInTheComments(): void
    {
        $source = implode("\n", $this->lines());

        self::assertStringNotContainsString('reports:mri', $source);
        self::assertStringNotContainsString('260923-test', $source);
        foreach ($this->lines() as $line) {
            if (str_starts_with($line, '.\\"')) {
                self::assertStringNotContainsString('PATIENT', $line);
            }
        }
        $titles = array_slice($this->lines(), (int) array_search('.TL', $this->lines(), true), 2);
        self::assertSame('RM genunchi drept', $titles[1], 'the exam titles the file, never the patient (D30)');
    }

    public function testThePreambleIsCheckedAgainAndTheFontFilled(): void
    {
        $lines = $this->lines();

        self::assertContains('.nr LL 17c', $lines);
        self::assertContains('.ds FAM BT', $lines, '{font} is the setting');
        self::assertContains('.\\" a comment', $lines);
        self::assertNotContains('.so /etc/passwd', $lines, 'a request that reads a file never gets in');
        self::assertNotContains('.sy id', $lines);
        self::assertSame(2, count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '.\\" (a preamble line was left out'))));
    }

    public function testAnUnsignedDocumentSaysSo(): void
    {
        self::assertSame(['.LP', 'nesemnat'], \array_slice($this->lines(null), -2));
    }

    public function testThePluginManifestAndThePhpGuardUseTheSamePattern(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/plugins/groff/plugin.json'), true);

        self::assertSame(Document::PREAMBLE_LINE, $manifest['settings']['preamble']['line_pattern']);
        self::assertStringContainsString('{font}', $manifest['settings']['preamble']['default']);
        $default = Document::preambleLines($manifest['settings']['preamble']['default'], 'T');
        self::assertSame(['.nr PO 2c', '.nr LL 17c', '.nr PS 11p', '.nr VS 14p', '.nr PD 4p', '.ds FAM T'], $default, 'the shipped preamble passes its own guard');
    }
}
