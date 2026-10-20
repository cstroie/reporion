<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use PHPUnit\Framework\TestCase;
use Reporion\Storage\Databases;

/**
 * D54 step 2: the three SQLite files, their migrations and the one attached
 * connection (docs/architecture-v2.md §6–7).
 */
final class DatabasesTest extends TestCase
{
    private string $dir;
    private string $migrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/reporion-databases-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->migrations = \dirname(__DIR__, 2) . '/migrations';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
        parent::tearDown();
    }

    public function testAFreshDataDirectoryGetsThreeFilesEachWithItsOwnTables(): void
    {
        $pdo = Databases::beside($this->dir . '/index.sqlite', $this->migrations)->connect();

        foreach (['reporion.sqlite', 'index.sqlite', 'vectors.sqlite'] as $file) {
            self::assertFileExists($this->dir . '/' . $file);
        }
        self::assertContains('page', self::tables($pdo, 'main'));
        self::assertContains('comment', self::tables($pdo, 'main'));
        self::assertNotContains('pages', self::tables($pdo, 'main'));
        self::assertContains('pages', self::tables($pdo, 'idx'));
        self::assertNotContains('page_vectors', self::tables($pdo, 'idx'));
        self::assertSame(['page_vectors', 'schema_meta'], self::tables($pdo, 'vec'));

        self::assertSame(self::latest('state'), self::version($pdo, 'main'));
        self::assertSame(self::latest('index'), self::version($pdo, 'idx'));
        self::assertSame(1, self::version($pdo, 'vec'));
    }

    public function testTheConnectionJoinsAcrossFilesWithoutASchemaPrefix(): void
    {
        $pdo = Databases::beside($this->dir . '/index.sqlite', $this->migrations)->connect();
        $pdo->exec("INSERT INTO pages (pid, path, ns, title, rev, status, visibility, updated, updated_by, bytes, mtime, body_sha, meta_json)
                    VALUES ('P1', 'a:b', 'a', 'B', 1, 'draft', 'private', 't', 'u', 1, 1, 's', '{}')");
        $pdo->exec("INSERT INTO page_vectors (pid, model, sha, dim, vec) VALUES ('P1', 'm', 's', 1, x'0000803f')");
        $pdo->exec("INSERT INTO page (pid, path, rev, status, created, created_by, file_sha, file_mtime, file_bytes)
                    VALUES ('P1', 'a:b', 1, 'draft', 't', 'u', 's', 1, 1)");

        $row = $pdo->query('SELECT g.path, p.title, v.model FROM page g JOIN pages p ON p.pid = g.pid JOIN page_vectors v ON v.pid = g.pid')?->fetch(PDO::FETCH_NUM);

        self::assertSame(['a:b', 'B', 'm'], $row);
    }

    public function testThePermanentFileSyncsOnEveryCommitAndTheCachesDoNot(): void
    {
        $pdo = Databases::beside($this->dir . '/index.sqlite', $this->migrations)->connect();

        self::assertSame(2, (int) $pdo->query('PRAGMA main.synchronous')?->fetchColumn());
        self::assertSame(1, (int) $pdo->query('PRAGMA idx.synchronous')?->fetchColumn());
        self::assertSame(1, (int) $pdo->query('PRAGMA vec.synchronous')?->fetchColumn());
        foreach (['main', 'idx', 'vec'] as $schema) {
            self::assertSame('wal', $pdo->query('PRAGMA ' . $schema . '.journal_mode')?->fetchColumn());
        }
    }

    public function testOpeningAgainAppliesNothingTwice(): void
    {
        $databases = Databases::beside($this->dir . '/index.sqlite', $this->migrations);
        $databases->connect()->exec("INSERT INTO counter (key, value) VALUES ('scuc:MR:26', 7)");

        $pdo = $databases->connect();

        self::assertSame(7, (int) $pdo->query("SELECT value FROM counter WHERE key = 'scuc:MR:26'")?->fetchColumn());
        self::assertSame(self::latest('index'), self::version($pdo, 'idx'));
    }

    public function testAnIndexFromBeforeD54KeepsItsVectorsInTheNewFile(): void
    {
        // an index at version 5: page_vectors still inside it
        $old = $this->dir . '/old-migrations';
        mkdir($old . '/index', 0777, true);
        foreach (glob($this->migrations . '/index/00[1-5]_*.sql') ?: [] as $file) {
            copy($file, $old . '/index/' . basename($file));
        }
        $index = new PDO('sqlite:' . $this->dir . '/index.sqlite');
        foreach (glob($old . '/index/*.sql') ?: [] as $file) {
            $index->exec((string) file_get_contents($file));
        }
        $index->exec("INSERT INTO schema_meta (key, value) VALUES ('schema_version', '5') ON CONFLICT(key) DO UPDATE SET value = '5'");
        $index->exec("INSERT INTO page_vectors (pid, model, sha, dim, vec) VALUES ('P1', 'm', 'abc', 1, x'0000803f')");
        unset($index);

        $pdo = Databases::beside($this->dir . '/index.sqlite', $this->migrations)->connect();

        self::assertNotContains('page_vectors', self::tables($pdo, 'idx'));
        self::assertSame(['P1', 'abc'], $pdo->query('SELECT pid, sha FROM vec.page_vectors')?->fetch(PDO::FETCH_NUM));
    }

    public function testIndexesSharingADirectoryShareNoOtherFile(): void
    {
        Databases::beside($this->dir . '/one.sqlite', $this->migrations)->connect()->exec("INSERT INTO counter (key, value) VALUES ('k', 1)");
        $two = Databases::beside($this->dir . '/two.sqlite', $this->migrations)->connect();

        self::assertFileExists($this->dir . '/one.state.sqlite');
        self::assertFileExists($this->dir . '/two.vectors.sqlite');
        self::assertSame(0, (int) $two->query('SELECT COUNT(*) FROM counter')?->fetchColumn());
    }

    public function testExplicitPathsWin(): void
    {
        (new Databases($this->dir . '/s.sqlite', $this->dir . '/i.sqlite', $this->dir . '/v.sqlite', $this->migrations))->connect();

        self::assertSame(['i.sqlite', 's.sqlite', 'v.sqlite'], array_values(array_filter(scandir($this->dir) ?: [], static fn (string $f): bool => str_ends_with($f, '.sqlite'))));
    }

    /** @return list<string> */
    private static function tables(PDO $pdo, string $schema): array
    {
        $names = $pdo->query("SELECT name FROM {$schema}.sqlite_master WHERE type = 'table' AND name NOT LIKE 'fts_%' AND name NOT LIKE 'sqlite_%' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN) ?? [];

        return array_map('strval', $names);
    }

    private static function version(PDO $pdo, string $schema): int
    {
        return (int) $pdo->query("SELECT value FROM {$schema}.schema_meta WHERE key = 'schema_version'")?->fetchColumn();
    }

    private function latest(string $schema): int
    {
        $files = glob($this->migrations . '/' . $schema . '/[0-9]*_*.sql') ?: [];
        sort($files);

        return (int) basename((string) end($files));
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
