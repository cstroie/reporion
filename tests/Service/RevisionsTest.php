<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Schema\Loader;
use Reporion\Service\Revisions;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\RecordingIndex;

/**
 * `Revisions::signature()`'s `matches` recomputes a signed revision's
 * digest — it must do so against the schema field order recorded on the
 * signature itself (`FlatFile::sign()`, `Canonical::orderSpec()`), not
 * whatever `conf/schema/*.json` says today (docs/FORMATS.md §8): the
 * schema is ordinary deployed config a future change can reorder, while a
 * signature must keep verifying an unchanged document forever (invariant 3).
 */
final class RevisionsTest extends TestCase
{
    private string $dataRoot;
    private string $schemaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataRoot = sys_get_temp_dir() . '/reporion-test-' . bin2hex(random_bytes(6));
        $this->schemaDir = sys_get_temp_dir() . '/reporion-test-schema-' . bin2hex(random_bytes(6));
        mkdir($this->dataRoot, 0775, true);
        mkdir($this->schemaDir, 0775, true);
        $this->writeBaseSchema(['title', 'modality']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataRoot);
        $this->removeDirectory($this->schemaDir);
        parent::tearDown();
    }

    public function testMatchesStaysTrueAfterTheSchemaFieldOrderChangesSinceSigning(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mri:mioveni:x', ['title' => 'RM cerebral', 'modality' => 'MR', 'visibility' => 'private'], 'v1 body', 'owner');

        $schemaFields = (new Loader($this->schemaDir))->fieldsFor([]);
        $storage->sign('reports:mri:mioveni:x', 'owner', $schemaFields);

        // Schema evolves after signing: base.json now declares the fields
        // in the opposite order — a config change, not a document edit.
        $this->writeBaseSchema(['modality', 'title']);

        $current = $storage->read('reports:mri:mioveni:x');
        $revisions = new Revisions($storage, new Loader($this->schemaDir));
        $signature = $revisions->signature($current, 1);

        self::assertNotNull($signature);
        self::assertTrue($signature['matches'], 'a schema reorder after signing must not turn an unchanged document into "does not match"');
    }

    public function testFallsBackToTodaysSchemaForASignatureRecordedBeforeFieldOrderExisted(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mri:mioveni:x', ['title' => 'RM cerebral', 'modality' => 'MR', 'visibility' => 'private'], 'v1 body', 'owner');

        $schemaFields = (new Loader($this->schemaDir))->fieldsFor([]);
        $signed = $storage->sign('reports:mri:mioveni:x', 'owner', $schemaFields);

        // Simulate a signature recorded before `field_order` existed.
        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/x';
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        unset($meta['signatures'][0]['field_order']);
        file_put_contents($dir . '/meta.json', json_encode($meta));

        $current = $storage->read('reports:mri:mioveni:x');
        $revisions = new Revisions($storage, new Loader($this->schemaDir));
        $signature = $revisions->signature($current, 1);

        self::assertNotNull($signature);
        self::assertTrue($signature['matches'], 'unchanged schema, no field_order recorded: must still verify via a fresh resolve');
    }

    /** @param list<string> $order */
    private function writeBaseSchema(array $order): void
    {
        $fields = [];
        foreach ($order as $name) {
            $fields[$name] = [];
        }
        file_put_contents($this->schemaDir . '/base.json', (string) json_encode(['fields' => $fields]));
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
