<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Cli\Output;
use Reporion\Cli\PagesArchiveCommand;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

final class PagesArchiveCommandTest extends TestCase
{
    private string $dir;
    private FlatFile $storage;
    private Sqlite $index;
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/reporion-pages-archive-' . uniqid();
        mkdir($this->dir . '/pages', 0755, true);
        $this->index = new Sqlite($this->dir . '/index.sqlite', __DIR__ . '/../../migrations');
        $this->storage = new FlatFile($this->dir, $this->index);
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');

        $this->storage->create('reports:mri:2019:old', ['title' => 'Old', 'imported_from' => 'dokuwiki:a', 'import_batch' => 'b1'], 'text', 'import');
        $this->storage->create('reports:ct:2019:other', ['title' => 'Other', 'import_batch' => 'b2'], 'text', 'import');
        $this->storage->create('reports:mri:2019:done', ['title' => 'Done', 'imported_from' => 'dokuwiki:c', 'status' => 'archived'], 'text', 'import');
        $this->storage->create('reports:mri:2019:signed', ['title' => 'Signed', 'imported_from' => 'dokuwiki:d'], 'text', 'import');
        $this->storage->sign('reports:mri:2019:signed', 'owner', []);
        $this->storage->create('reports:mri:2026:native', ['title' => 'Native'], 'text', 'owner');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testWithoutApplyItOnlyLists(): void
    {
        self::assertSame(0, $this->runCommand([]));
        $out = $this->stdout();
        self::assertStringContainsString('would archive reports:mri:2019:old', $out);
        self::assertStringContainsString('would archive reports:ct:2019:other', $out);
        self::assertStringNotContainsString('native', $out);
        self::assertStringNotContainsString(':done', $out);
        self::assertStringContainsString('2 imported draft(s) would be archived — 1 imported page(s) signed here left as they are', $out);
        self::assertSame('draft', $this->storage->read('reports:mri:2019:old')->status);
    }

    public function testApplyArchivesImportedDraftsOnly(): void
    {
        self::assertSame(0, $this->runCommand(['--apply', '--actor=owner']));

        $old = $this->storage->read('reports:mri:2019:old');
        self::assertSame('archived', $old->status);
        self::assertSame(1, $old->rev, 'no new revision');
        self::assertSame('owner', $old->meta['archived']['by']);
        self::assertSame('archived', $this->storage->read('reports:ct:2019:other')->status);
        self::assertSame('signed', $this->storage->read('reports:mri:2019:signed')->status);
        self::assertSame('draft', $this->storage->read('reports:mri:2026:native')->status);

        $owner = new User('owner', 'x', true, [], true, 'now', 'now');
        self::assertSame('archived', $this->index->findByPath('reports:mri:2019:old', $owner)['status']);

        $audit = implode('', array_map('file_get_contents', glob($this->dir . '/audit/*') ?: []));
        self::assertSame(2, substr_count($audit, '"page.archive"'));
        self::assertStringNotContainsString('reports:mri', $audit, 'no path in the audit (invariant 8)');
    }

    public function testFiltersByNamespaceAndBatch(): void
    {
        $this->runCommand(['--apply', '--actor=owner', '--namespace=reports:ct']);
        self::assertSame('archived', $this->storage->read('reports:ct:2019:other')->status);
        self::assertSame('draft', $this->storage->read('reports:mri:2019:old')->status);

        $this->runCommand(['--apply', '--actor=owner', '--batch=b1']);
        self::assertSame('archived', $this->storage->read('reports:mri:2019:old')->status);
    }

    public function testApplyNeedsAnActorAndUnknownOptionsAreRefused(): void
    {
        self::assertSame(1, $this->runCommand(['--apply']));
        self::assertSame(1, $this->runCommand(['--force']));
        self::assertSame('draft', $this->storage->read('reports:mri:2019:old')->status);
    }

    public function testStorageRefusesASignedPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->archive('reports:mri:2019:signed', 'owner');
    }

    /** @param list<string> $args */
    private function runCommand(array $args): int
    {
        return (new PagesArchiveCommand($this->storage, new AuditLog($this->dir . '/audit')))->run($args, new Output($this->out, $this->err));
    }

    private function stdout(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }
}
