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
use Reporion\Plugin\Groff\Plugin;
use Reporion\Plugin\Loader;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Http\HttpTestCase;

/**
 * plugins/groff: a signed report as ms source and as a groff PDF — through the
 * real Kernel and routes, with groff itself replaced by a fake. Names are made
 * up (invariant 10).
 */
final class GroffExportTest extends HttpTestCase
{
    private const REPORT = 'reports:mri:mioveni:260923-test-patient';
    private const BODY = "# TEST PATIENT\n\n## RM genunchi drept\n\n### Descriere\n\nMenisc **normal**.\n\n- fără leziuni\n\n### Concluzii\n\nExamen în limite normale.\n";

    /** @var list<array{argv: list<string>, source: string}> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'groff');
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'devices' => ['MV-MR-01' => 'Siemens 3T']]];
        $this->config['plugins'] = ['enabled' => ['groff'], 'settings' => ['groff' => ['font' => 'BT', 'groff' => '/usr/bin/groff', 'base_url' => 'https://wiki.test']]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true, [], 'Dr. Test Signer');
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Viewer)]);
        $users->create('outsider', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Editor)]);
        Plugin::$runner = function (array $argv, string $out, int $timeout): array {
            $this->runs[] = ['argv' => $argv, 'source' => (string) file_get_contents($argv[\count($argv) - 1])];
            file_put_contents($out, "%PDF-1.7\nfake");

            return [0, ''];
        };
    }

    protected function tearDown(): void
    {
        Plugin::$runner = null;
        parent::tearDown();
    }

    public function testASignedReportDownloadsAsMsSource(): void
    {
        $this->signedReport();

        $response = $this->get('owner', '/x/groff/ms/' . $this->pid());

        self::assertSame(200, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="reports-mri-mioveni-260923-test-patient.ms"', $response->headers['Content-Disposition'], 'named by the page path, like every signed-in export');
        self::assertSame('private, no-store', $response->headers['Cache-Control']);

        $ms = $response->body;
        self::assertStringContainsString("\n.ds FAM BT\n", $ms, 'the preamble, with the font setting');
        self::assertStringContainsString("\n.TL\nRM genunchi drept\n.AU\nSpital Test\n", $ms);
        // The exam heading is the title above it (Support\ReportName::forExport), as in the PDF
        self::assertStringContainsString("\n.SH 2\nDescriere\n.PP\nMenisc \\f[B]normal\\f[R].\n.IP \\(bu 2\nfără leziuni\n", $ms);
        self::assertStringContainsString('\\f[B]Dr. Test Signer\\f[R]', $ms);
        self::assertStringContainsString('\\%https://wiki.test/r/' . $this->pid() . '/1', $ms);
        self::assertStringNotContainsString(self::REPORT, $ms, 'the path names the file, never the document (invariant 8)');
        self::assertStringNotContainsString('MV-MR-26-0001', $ms, 'our accession is never printed');
        self::assertSame([], $this->runs, 'the source needs no groff');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"export"', $audit);
        self::assertStringContainsString('"format":"ms"', $audit);
        self::assertStringNotContainsString(self::REPORT, $audit);
    }

    public function testThePdfIsWhatGroffMadeFromThatSource(): void
    {
        $this->signedReport();

        $response = $this->get('owner', '/x/groff/pdf/' . $this->pid());

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame('inline; filename="reports-mri-mioveni-260923-test-patient.pdf"', $response->headers['Content-Disposition']);
        self::assertSame("%PDF-1.7\nfake", $response->body);
        self::assertCount(1, $this->runs);
        self::assertSame(['/usr/bin/groff', '-t', '-K', 'utf-8', '-fBT', '-ms', '-T', 'pdf'], \array_slice($this->runs[0]['argv'], 0, 8));
        self::assertSame($this->get('owner', '/x/groff/ms/' . $this->pid())->body, $this->runs[0]['source'], 'the PDF is typeset from the very file the ms export gives');
        self::assertStringContainsString('"format":"groff-pdf"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testOnlyAReaderOfTheReportGetsIt(): void
    {
        $this->signedReport();
        $pid = $this->pid();

        foreach (['ms', 'pdf'] as $kind) {
            self::assertSame(200, $this->get('viewer', '/x/groff/' . $kind . '/' . $pid)->status, 'a signed-in reader with a grant');
            self::assertSame(404, $this->anonymous('/x/groff/' . $kind . '/' . $pid)->status, 'anonymous: not found, never forbidden (invariant 9)');
            self::assertSame(404, $this->get('outsider', '/x/groff/' . $kind . '/' . $pid)->status, 'no grant here (invariant 6)');
            self::assertSame(404, $this->get('owner', '/x/groff/' . $kind . '/01NOSUCHPIDXXXXXXXXXXXXXXX')->status);
        }
    }

    public function testAnUnsignedRevisionAndAPageThatIsNoReportAreRefused(): void
    {
        $this->storage()->create(self::REPORT, $this->frontmatter(), self::BODY, 'owner');
        self::assertSame(409, $this->get('owner', '/x/groff/ms/' . $this->pid())->status);
        self::assertSame(409, $this->get('owner', '/x/groff/pdf/' . $this->pid())->status);

        $this->storage()->create('docs:note', ['title' => 'Note', 'visibility' => 'private'], "Text.\n", 'owner');
        $this->storage()->sign('docs:note', 'owner', []);
        self::assertSame(404, $this->get('owner', '/x/groff/ms/' . $this->storage()->read('docs:note')->pid)->status);
    }

    public function testGroffFailuresAreShortAndKeepTheReportOutOfNonOwners(): void
    {
        $this->signedReport();
        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [1, "troff:/tmp/x/report.ms:12: error: can't find macro file s: TEST PATIENT text\n"];

        $owner = $this->get('owner', '/x/groff/pdf/' . $this->pid());
        self::assertSame(503, $owner->status);
        self::assertStringContainsString('groff could not typeset the report', $owner->body);
        self::assertStringContainsString("can't find macro file s", $owner->body, 'the owner sees what groff said');

        $viewer = $this->get('viewer', '/x/groff/pdf/' . $this->pid());
        self::assertSame(503, $viewer->status);
        self::assertStringNotContainsString("can't find", $viewer->body);
        self::assertStringNotContainsString('TEST PATIENT', $viewer->body);

        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [-1, ''];
        self::assertStringContainsString('groff cannot be run', $this->get('owner', '/x/groff/pdf/' . $this->pid())->body);
        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [-2, ''];
        self::assertSame(504, $this->get('owner', '/x/groff/pdf/' . $this->pid())->status);
        self::assertStringNotContainsString('"format":"groff-pdf"', (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), 'a failed export is not an export');
    }

    public function testTheExportMenuOffersBothOnASignedReport(): void
    {
        $this->signedReport();
        $page = $this->get('owner', '/' . self::REPORT)->body;

        self::assertStringContainsString('/x/groff/ms/' . $this->pid(), $page);
        self::assertStringContainsString('/x/groff/pdf/' . $this->pid(), $page);
        self::assertStringContainsString('Export groff (ms)', $page);
    }

    /** @return array<string, mixed> */
    private function frontmatter(): array
    {
        return [
            'title' => 'TEST PATIENT', 'exam_title' => 'RM genunchi drept', 'visibility' => 'private', 'accession' => 'MV-MR-26-0001',
            'site' => 'mioveni', 'modality' => ['MR'], 'study_date' => '2026-09-23T10:15:00+03:00', 'device' => 'MV-MR-01',
            'patient' => ['name' => 'TEST PATIENT', 'sex' => 'F', 'born' => 1970], 'referrer' => 'Dr. Test',
        ];
    }

    private function signedReport(): void
    {
        $this->storage()->create(self::REPORT, $this->frontmatter(), self::BODY, 'owner');
        $this->storage()->sign(self::REPORT, 'owner', [], 'P-9');
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
}
