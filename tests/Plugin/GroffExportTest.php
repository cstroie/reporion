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
use Reporion\Storage\FileTree;
use Reporion\Tests\Http\HttpTestCase;

/**
 * plugins/groff: an ordinary page as ms source and as a groff PDF: through the
 * real Kernel and routes, with groff itself replaced by a fake. It must never
 * touch a report or a page with a patient block. Names are made up (invariant 10).
 */
final class GroffExportTest extends HttpTestCase
{
    private const PAGE = 'docs:procedures:contrast';
    private const REPORT = 'reports:mri:mioveni:260923-test-patient';
    private const BODY = "# Contrast procedure\n\nGive the contrast **slowly**.\n\n## Steps\n\n- check the kidneys\n- start the pump\n\n### Notes\n\nSee the protocol.\n";

    /** @var list<array{argv: list<string>, source: string}> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'groff');
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'devices' => []]];
        $this->config['plugins'] = ['enabled' => ['groff'], 'settings' => ['groff' => ['font' => 'BT', 'groff' => '/usr/bin/groff']]];
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('owner', password_hash('x', PASSWORD_ARGON2ID), true, [], 'Dr. Test Owner');
        $users->create('viewer', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('docs', GrantRole::Viewer)]);
        $users->create('outsider', password_hash('x', PASSWORD_ARGON2ID), false, [new Grant('reports', GrantRole::Editor)]);
        Plugin::$runner = function (array $argv, string $out, int $timeout): array {
            $this->runs[] = ['argv' => $argv, 'source' => (string) file_get_contents($argv[\count($argv) - 1])];
            file_put_contents($out, "%PDF-1.7\nfake");

            return [0, ''];
        };
        $this->storage()->create(self::PAGE, ['title' => 'Contrast procedure', 'summary' => 'How to give the contrast.', 'visibility' => 'private'], self::BODY, 'owner');
    }

    protected function tearDown(): void
    {
        Plugin::$runner = null;
        parent::tearDown();
    }

    public function testAPageDownloadsAsMsSourceSignedOrNot(): void
    {
        $response = $this->get('owner', '/x/groff/ms/' . $this->pid());

        self::assertSame(200, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="docs-procedures-contrast.ms"', $response->headers['Content-Disposition']);
        self::assertSame('private, no-store', $response->headers['Cache-Control']);

        $ms = $response->body;
        self::assertStringContainsString("\n.ds FAM BT\n", $ms, 'the preamble, with the font setting');
        self::assertStringContainsString("\n.TL\nContrast procedure\n.AU\nDr. Test Owner\n.AI\nrev 1 · " . date('Y-m-d') . "\n.AB no\nHow to give the contrast.\n.AE\n", $ms);
        self::assertStringContainsString(".PP\nGive the contrast \\f[B]slowly\\f[R].\n.SH 2\nSteps\n.IP \\(bu 2\ncheck the kidneys\n.IP \\(bu 2\nstart the pump\n.SH 3\nNotes\n.PP\nSee the protocol.\n", $ms, 'the title heading is not repeated, ## is .SH 2');
        self::assertStringNotContainsString('.SH 1', $ms);
        self::assertStringNotContainsString('procedures', $ms, 'the address names the file, never the document (invariant 8)');
        self::assertSame([], $this->runs, 'the source needs no groff');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"format":"ms"', $audit);
        self::assertStringNotContainsString(self::PAGE, $audit);
    }

    public function testThePdfIsWhatGroffMadeFromThatSource(): void
    {
        $response = $this->get('owner', '/x/groff/pdf/' . $this->pid());

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertSame('inline; filename="docs-procedures-contrast.pdf"', $response->headers['Content-Disposition']);
        self::assertSame("%PDF-1.7\nfake", $response->body);
        self::assertSame(['/usr/bin/groff', '-t', '-K', 'utf-8', '-fBT', '-ms', '-T', 'pdf'], \array_slice($this->runs[0]['argv'], 0, 8));
        self::assertSame($this->get('owner', '/x/groff/ms/' . $this->pid())->body, $this->runs[0]['source']);
        self::assertStringContainsString('"format":"groff-pdf"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testOnlyASignedInReaderOfThePageGetsIt(): void
    {
        $pid = $this->pid();
        foreach (['ms', 'pdf'] as $kind) {
            self::assertSame(200, $this->get('viewer', '/x/groff/' . $kind . '/' . $pid)->status, 'a reader with a grant');
            self::assertSame(404, $this->anonymous('/x/groff/' . $kind . '/' . $pid)->status, 'anonymous: not found, never forbidden (invariant 9)');
            self::assertSame(404, $this->get('outsider', '/x/groff/' . $kind . '/' . $pid)->status, 'no grant here (invariant 6)');
            self::assertSame(404, $this->get('owner', '/x/groff/' . $kind . '/01NOSUCHPIDXXXXXXXXXXXXXXX')->status);
        }
    }

    public function testNeverAReportNorAPageWithAPatientBlock(): void
    {
        $this->storage()->create(self::REPORT, ['title' => 'TEST PATIENT', 'visibility' => 'private', 'site' => 'mioveni', 'modality' => ['MR'], 'patient' => ['name' => 'TEST PATIENT']], "# TEST PATIENT\n\n## RM\n", 'owner');
        $this->storage()->sign(self::REPORT, 'owner', [], 'P-9');
        $this->storage()->create('docs:with-patient', ['title' => 'Oops', 'visibility' => 'private', 'patient' => ['name' => 'TEST PATIENT']], "Text.\n", 'owner');

        foreach ([self::REPORT, 'docs:with-patient'] as $path) {
            foreach (['ms', 'pdf'] as $kind) {
                self::assertSame(404, $this->get('owner', '/x/groff/' . $kind . '/' . $this->storage()->read($path)->pid)->status, $path . ' ' . $kind);
            }
        }
        self::assertSame([], $this->runs, 'groff never saw them');
    }

    public function testGroffFailuresAreShortAndKeepThePageOutOfNonOwners(): void
    {
        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [1, "troff:/tmp/x/report.ms:12: error: can't find macro file s: Give the contrast\n"];

        $owner = $this->get('owner', '/x/groff/pdf/' . $this->pid());
        self::assertSame(503, $owner->status);
        self::assertStringContainsString('groff could not typeset the page', $owner->body);
        self::assertStringContainsString("can't find macro file s", $owner->body, 'the owner sees what groff said');

        $viewer = $this->get('viewer', '/x/groff/pdf/' . $this->pid());
        self::assertSame(503, $viewer->status);
        self::assertStringNotContainsString("can't find", $viewer->body);
        self::assertStringNotContainsString('Give the contrast', $viewer->body);

        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [-1, ''];
        self::assertStringContainsString('groff cannot be run', $this->get('owner', '/x/groff/pdf/' . $this->pid())->body);
        Plugin::$runner = static fn (array $argv, string $out, int $timeout): array => [-2, ''];
        self::assertSame(504, $this->get('owner', '/x/groff/pdf/' . $this->pid())->status);
        self::assertStringNotContainsString('"format":"groff-pdf"', (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'), 'a failed export is not an export');
    }

    public function testTheExportMenuOffersGroffOnPagesAndTheSrOnReportsOnly(): void
    {
        $this->config['plugins'] = ['enabled' => ['groff', 'dicom'], 'settings' => ['groff' => ['font' => 'BT'], 'dicom' => ['servers' => []]]];
        Loader::registerAutoload(\dirname(__DIR__, 2) . '/plugins', 'dicom');
        $this->storage()->create(self::REPORT, ['title' => 'TEST PATIENT', 'visibility' => 'private', 'site' => 'mioveni', 'modality' => ['MR'], 'patient' => ['name' => 'TEST PATIENT']], "# TEST PATIENT\n\n## RM\n", 'owner');
        $this->storage()->sign(self::REPORT, 'owner', [], 'P-9');

        $page = $this->get('owner', '/' . self::PAGE)->body;
        self::assertStringContainsString('/x/groff/ms/' . $this->pid(), $page);
        self::assertStringContainsString('/x/groff/pdf/' . $this->pid(), $page);
        self::assertStringContainsString('Export groff (ms)', $page);
        self::assertStringNotContainsString('/x/dicom/sr/', $page, 'the SR is for reports');

        $report = $this->get('owner', '/' . self::REPORT)->body;
        self::assertStringContainsString('/x/dicom/sr/' . $this->storage()->read(self::REPORT)->pid, $report);
        self::assertStringNotContainsString('/x/groff/', $report, 'groff never offers itself on a report');
        self::assertStringNotContainsString('/x/groff/', $this->anonymous('/' . self::PAGE)->body, 'nor for anonymous visitors');
    }

    private function pid(): string
    {
        return $this->storage()->read(self::PAGE)->pid;
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
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
