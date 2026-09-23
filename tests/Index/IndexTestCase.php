<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Index;

use PDO;
use PHPUnit\Framework\TestCase;
use Reporion\Index\PageSnapshot;
use Reporion\Index\Sqlite;

abstract class IndexTestCase extends TestCase
{
    protected string $migrationsDir;

    /** @var list<string> */
    private array $databasePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationsDir = \dirname(__DIR__, 2) . '/migrations';
    }

    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    /**
     * @return array{0: Sqlite, 1: string} the index and the file path backing it
     */
    protected function newIndex(): array
    {
        $path = sys_get_temp_dir() . '/reporion-index-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->databasePaths[] = $path;

        return [new Sqlite($path, $this->migrationsDir), $path];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchOne(string $databasePath, string $sql): array
    {
        $row = (new PDO('sqlite:' . $databasePath))->query($sql)->fetch(PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : [];
    }

    /**
     * @return list<mixed>
     */
    protected function fetchColumn(string $databasePath, string $sql): array
    {
        return (new PDO('sqlite:' . $databasePath))->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $databasePath, string $sql): array
    {
        $rows = (new PDO('sqlite:' . $databasePath))->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $overrides
     */
    protected function snapshot(string $pid, string $path, array $frontmatter = [], string $body = 'body text', array $overrides = []): PageSnapshot
    {
        $segments = explode(':', $path);
        array_pop($segments);

        $defaults = [
            'ns' => implode(':', $segments),
            'rev' => 1,
            'status' => 'draft',
            'visibility' => 'private',
            'bytes' => \strlen($body),
            'mtime' => 1_700_000_000,
            'bodySha' => hash('sha256', $body),
            'updated' => '2026-09-22T09:14:00+03:00',
            'updatedBy' => 'owner',
            'note' => null,
            'kind' => 'create',
        ];
        $values = array_merge($defaults, $overrides);

        return new PageSnapshot(
            pid: $pid,
            path: $path,
            ns: $values['ns'],
            rev: $values['rev'],
            status: $values['status'],
            visibility: $values['visibility'],
            frontmatter: $frontmatter,
            body: $body,
            bytes: $values['bytes'],
            mtime: $values['mtime'],
            bodySha: $values['bodySha'],
            updated: $values['updated'],
            updatedBy: $values['updatedBy'],
            note: $values['note'],
            kind: $values['kind'],
        );
    }
}
