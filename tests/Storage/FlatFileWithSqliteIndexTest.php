<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * FlatFile and Index\Sqlite are built and tested independently (each has its
 * own test double / snapshot fixtures) — this is the one place that proves
 * the real production wiring between them actually works end to end.
 */
final class FlatFileWithSqliteIndexTest extends StorageTestCase
{
    public function testCreateAndSaveAreVisibleInTheRealSqliteIndex(): void
    {
        $databasePath = $this->dataRoot . '/index.sqlite';
        $migrationsDir = \dirname(__DIR__, 2) . '/migrations';

        $index = new Sqlite($databasePath, $migrationsDir);
        $storage = new FlatFile($this->dataRoot, $index);

        $storage->create('reports:mri:mioveni:260922-x', [
            'title' => 'RM cerebral nativ',
            'modality' => ['MR'],
            'region' => ['neuro'],
            'visibility' => 'private',
        ], 'Fara leziuni demielinizante.', 'owner');

        $pdo = new PDO('sqlite:' . $databasePath);
        $row = $pdo->query("SELECT pid, path, rev, visibility FROM pages WHERE path = 'reports:mri:mioveni:260922-x'")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame(1, (int) $row['rev']);
        self::assertSame('private', $row['visibility']);

        $storage->save('reports:mri:mioveni:260922-x', [
            'title' => 'RM cerebral nativ',
            'modality' => ['MR'],
            'region' => ['neuro'],
            'visibility' => 'public',
        ], 'Text revizuit, fara leziuni.', 1, 'owner');

        $updated = $pdo->query("SELECT rev, visibility FROM pages WHERE path = 'reports:mri:mioveni:260922-x'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $updated['rev']);
        self::assertSame('public', $updated['visibility']);

        $hit = $pdo->query("SELECT pid FROM fts WHERE fts MATCH 'revizuit'")->fetchColumn();
        self::assertNotFalse($hit);
    }
}
