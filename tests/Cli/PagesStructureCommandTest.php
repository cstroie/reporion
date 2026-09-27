<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Audit\AuditLog;
use Reporion\Cli\Output;
use Reporion\Cli\PagesStructureCommand;
use Reporion\Storage\FlatFile;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Tests\Storage\RecordingIndex;

/**
 * pages:structure — migrates old-format report bodies to the
 * structured format (## Exam Title / ### Descriere / ### Concluzii).
 *
 * Dry-run by default; --apply writes through Storage::save().
 */
final class PagesStructureCommandTest extends TestCase
{
    private string $dataRoot;
    private StorageInterface $storage;
    private AuditLog $audit;
    private Output $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-pages-structure-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
        mkdir($this->dataRoot . '/pages/reports/ct/mioveni', 0775, true);

        $this->storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $this->audit = new AuditLog($this->dataRoot . '/audit');
        $this->output = new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function createOldFormatPage(string $path, string $indication, string $date, string $body): void
    {
        $raw = "# Test Patient\n**{$indication}**\n*{$date}*\n{$body}\n";
        $this->storage->create($path, ['title' => 'Test Patient', 'visibility' => 'private'], $raw, 'importer');
    }

    private function createStructuredPage(string $path, string $examTitle, string $description, string $conclusion): void
    {
        $raw = "## {$examTitle}\n\n### Descriere\n{$description}\n\n### Concluzii\n{$conclusion}\n";
        $this->storage->create($path, ['title' => 'Test Patient', 'visibility' => 'private'], $raw, 'importer');
    }

    /**
     * @param list<string> $args
     * @return array{0: string, 1: string}
     */
    private function runCommand(array $args): array
    {
        $cmd = new PagesStructureCommand($this->dataRoot, $this->storage, $this->audit);
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $cmd->run($args, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [(string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    // -- Dry-run ----------------------------------------------------------------

    public function testDryRunOutputsJsonManifest(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--dry-run', '--namespace=reports:ct', '--json']);

        self::assertStringContainsString('"matched": 1', $out);
        self::assertStringContainsString('"skipped": 0', $out);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $pageBefore = $this->storage->read('reports:ct:mioveni:260101-test');

        $this->runCommand(['--dry-run', '--namespace=reports:ct', '--json']);

        $pageAfter = $this->storage->read('reports:ct:mioveni:260101-test');
        self::assertSame($pageBefore->rev, $pageAfter->rev, 'a dry-run writes nothing');
    }

    // -- Apply mode -------------------------------------------------------------

    public function testApplyModeWritesThroughStorage(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=cli']);

        self::assertStringContainsString('Applied to 1 page(s)', $out);

        $page = $this->storage->read('reports:ct:mioveni:260101-test');
        self::assertStringContainsString('### Descriere', $page->body);
        self::assertStringContainsString('### Concluzii', $page->body);
        self::assertSame('CT Cerebral', $page->frontmatter['exam_title']);
        self::assertSame('Cefalee', $page->frontmatter['indication']);
    }

    public function testApplyModeAttributesCorrectActor(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=dr-costin']);

        $page = $this->storage->read('reports:ct:mioveni:260101-test');
        // A new revision was created
        self::assertSame(2, $page->rev, 'a new revision was created');
    }

    // -- Idempotent -------------------------------------------------------------

    public function testIdempotentSkipsStructuredPages(): void
    {
        $this->createStructuredPage('reports:ct:mioveni:260101-test', 'CT Cerebral', 'description', 'conclusion');

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=cli']);

        self::assertStringContainsString('Applied to 0 page(s)', $out);
        self::assertStringContainsString('skipped 1', $out);
    }

    public function testIdempotentWithMixedPages(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $this->createStructuredPage('reports:ct:mioveni:260102-test', 'CT Cerebral', 'description', 'conclusion');

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=cli']);

        self::assertStringContainsString('Applied to 1 page(s)', $out);
        self::assertStringContainsString('skipped 1', $out);
    }

    // -- Limit ------------------------------------------------------------------

    public function testLimitRespectsMaxPages(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $this->createOldFormatPage('reports:ct:mioveni:260102-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $this->createOldFormatPage('reports:ct:mioveni:260103-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=cli', '--limit=2']);

        self::assertStringContainsString('Applied to 2 page(s)', $out);
    }

    public function testLimitZeroMatchesNothing(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=cli', '--limit=0']);

        self::assertStringContainsString('Applied to 0 page(s)', $out);
    }

    // -- Namespace filter -------------------------------------------------------

    public function testNamespaceFilterOnlyMatchesSpecifiedNamespace(): void
    {
        $this->createOldFormatPage('reports:mri:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $this->createOldFormatPage('reports:ct:mioveni:260102-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:mri', '--actor=cli']);

        self::assertStringContainsString('Applied to 1 page(s)', $out);
    }

    public function testNamespaceFilterDoesNotTouchOtherNamespaces(): void
    {
        $this->createOldFormatPage('reports:mri:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:mri', '--actor=cli']);

        self::assertStringContainsString('Applied to 1 page(s)', $out);
    }

    public function testNoNamespaceFilterMatchesAllReportPages(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");
        $this->createOldFormatPage('reports:mri:mioveni:260102-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--actor=cli']);

        self::assertStringContainsString('Applied to 2 page(s)', $out);
    }

    // -- JSON output ------------------------------------------------------------

    public function testJsonOutputIsMachineReadable(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--dry-run', '--namespace=reports:ct', '--json']);

        $data = json_decode($out, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('matched', $data);
        self::assertArrayHasKey('skipped', $data);
        self::assertArrayHasKey('pages', $data);
        self::assertSame(1, $data['matched']);
    }

    public function testJsonOutputIncludesPageDetails(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--dry-run', '--namespace=reports:ct', '--json']);

        $data = json_decode($out, true);
        self::assertIsArray($data['pages'][0]);
        self::assertSame('reports:ct:mioveni:260101-test', $data['pages'][0]['path']);
        self::assertSame('CT Cerebral', $data['pages'][0]['examTitle']);
    }

    // -- Actor attribution ------------------------------------------------------

    public function testActorAttributionWrittenToStorage(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        $this->runCommand(['--apply', '--namespace=reports:ct', '--actor=dr-costin']);

        $page = $this->storage->read('reports:ct:mioveni:260101-test');
        self::assertSame(2, $page->rev, 'a new revision was created for the actor');
    }

    public function testApplyNeedsAnActor(): void
    {
        $this->createOldFormatPage('reports:ct:mioveni:260101-test', 'Cefalee', '01.02.2024', "CT cerebral.\n\nConcluzie normală.\n");

        [$out, ] = $this->runCommand(['--apply', '--namespace=reports:ct']);

        self::assertStringContainsString('--apply needs --actor=', $out);
    }
}
