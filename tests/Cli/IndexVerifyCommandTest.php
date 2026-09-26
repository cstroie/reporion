<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Cli\IndexVerifyCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;
use Reporion\Audit\AuditLog;
use Reporion\Service\Maintenance\MaintenanceRunner;

final class IndexVerifyCommandTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-index-verify-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testCleanIndexExitsZero(): void
    {
        [$storage, $index] = $this->wiring();
        $storage->create('reports:mri:mioveni:260922-a', $this->frontmatter(), 'Text A.', 'owner');

        [$exitCode, $output] = $this->runCommand(new IndexVerifyCommand(MaintenanceRunner::standard($storage, $index, new AuditLog($this->dataRoot . '/audit'), $this->dataRoot, 30)));

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('orphans: 0, missing: 0, drifted: 0', $output);
        self::assertStringContainsString('index is clean', $output);
    }

    public function testPageOnDiskButNotYetIndexedIsReportedAsMissing(): void
    {
        [$storage, $index] = $this->wiring();
        $storage->create('reports:mri:mioveni:260922-a', $this->frontmatter(), 'Text A.', 'owner');

        // Simulate a page that reached disk but never reached the index —
        // exactly the drift window FlatFile::delete()'s docblock names
        // index:verify as the fix for.
        $pdo = new \PDO('sqlite:' . $this->dataRoot . '/index.sqlite');
        $pdo->exec('DELETE FROM pages');
        $pdo->exec('DELETE FROM fts');

        [$exitCode, $output] = $this->runCommand(new IndexVerifyCommand(MaintenanceRunner::standard($storage, $index, new AuditLog($this->dataRoot . '/audit'), $this->dataRoot, 30)));

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('orphans: 0, missing: 1, drifted: 0', $output);
        self::assertStringContainsString('missing (on disk, not indexed): ', $output);
    }

    public function testIndexedPageRemovedFromDiskIsReportedAsOrphan(): void
    {
        [$storage, $index] = $this->wiring();
        $storage->create('reports:mri:mioveni:260922-a', $this->frontmatter(), 'Text A.', 'owner');

        // Remove straight from disk, bypassing Storage\FlatFile::delete()
        // entirely, so the index still has the row.
        $this->removeDirectory($this->dataRoot . '/pages/reports/mri/mioveni/260922-a');

        [$exitCode, $output] = $this->runCommand(new IndexVerifyCommand(MaintenanceRunner::standard($storage, $index, new AuditLog($this->dataRoot . '/audit'), $this->dataRoot, 30)));

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('orphans: 1', $output);
    }

    /**
     * @return array{0: FlatFile, 1: Sqlite}
     */
    private function wiring(): array
    {
        $migrationsDir = \dirname(__DIR__, 2) . '/migrations';
        $index = new Sqlite($this->dataRoot . '/index.sqlite', $migrationsDir);
        $storage = new FlatFile($this->dataRoot, $index);

        return [$storage, $index];
    }

    /**
     * @return array<string, mixed>
     */
    private function frontmatter(): array
    {
        return [
            'title' => 'RM cerebral nativ',
            'modality' => 'MR',
            'visibility' => 'private',
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(IndexVerifyCommand $command): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = $command->run([], new Output($stdout, $stderr));

        rewind($stdout);
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        return [$exitCode, $output];
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
}
