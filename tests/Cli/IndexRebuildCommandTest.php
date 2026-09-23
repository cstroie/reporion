<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Cli\IndexRebuildCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

final class IndexRebuildCommandTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-index-rebuild-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        parent::tearDown();
    }

    public function testRebuildRepopulatesTheIndexFromDiskAlone(): void
    {
        $migrationsDir = \dirname(__DIR__, 2) . '/migrations';
        $index = new Sqlite($this->dataRoot . '/index.sqlite', $migrationsDir);
        $storage = new FlatFile($this->dataRoot, $index);

        $storage->create('reports:mri:mioveni:260922-a', $this->frontmatter(), 'Text A cu revizuit.', 'owner');
        $storage->create('reports:ct:cervical:260922-b', $this->frontmatter(), 'Text B.', 'owner');

        $pdo = new PDO('sqlite:' . $this->dataRoot . '/index.sqlite');
        $pdo->exec('DELETE FROM pages');
        $pdo->exec('DELETE FROM fts');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn());

        [$exitCode, $output] = $this->runCommand(new IndexRebuildCommand($storage, $index));

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('rebuilt index from 2 page(s)', $output);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn());

        $hit = $pdo->query("SELECT pid FROM fts WHERE fts MATCH 'revizuit'")->fetchColumn();
        self::assertNotFalse($hit);
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
    private function runCommand(IndexRebuildCommand $command): array
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
