<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\IntegrityVerifyTask;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Revisions;
use Reporion\Storage\FileTree;
use Reporion\Storage\Journal;
use Reporion\Storage\V1Converter;
use Reporion\Support\Canonical;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Revlog;
use RuntimeException;

/**
 * tools/migrate-v2.php (D54 step 8, docs/architecture-v2.md §11): a 1.x data
 * directory, written here file by file as 1.x wrote it, converted into a new
 * one and read back through FileTree; the source is never touched. Fixture
 * data is fictitious (invariant 10).
 */
final class V1ConverterTest extends StorageTestCase
{
    private const REPORT = 'reports:mri:mioveni:260920-test-unu';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $v1;
    private string $v2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v1 = $this->dataRoot . '/data';
        $this->v2 = $this->dataRoot . '/data.v2';
        mkdir($this->v1 . '/pages', 0775, true);
    }

    public function testAWholeArchiveConvertsAndReadsBackThroughFileTree(): void
    {
        $png = (string) base64_decode(self::PNG, true);
        $sha = hash('sha256', $png);
        $report = $this->v1Page(self::REPORT, [
            "---\ntitle: TEST Unu\nvisibility: private\n---\n\n# TEST Unu\n\nPrima.\n",
            "---\ntitle: TEST Unu\nvisibility: private\n---\n\n# TEST Unu\n\nA doua.\n",
        ], signed: [2], extra: [
            'moves' => [['from' => 'reports:mri:old', 'to' => self::REPORT, 'ts' => '2026-10-01T10:00:00+03:00', 'by' => 'owner']],
            'deliveries' => [['to' => 'pacs', 'rev' => 2, 'at' => '2026-10-02T10:00:00+03:00', 'by' => 'owner', 'outcome' => 'ok', 'study_uid' => '1.2.3']],
        ]);
        $dir = $this->v1 . '/pages/' . str_replace(':', '/', self::REPORT);
        file_put_contents($dir . '/media.json', json_encode([['sha256' => $sha, 'ext' => 'png', 'name' => 'scan.png', 'bytes' => \strlen($png), 'w' => 1, 'h' => 1, 'added' => 't', 'by' => 'owner']]));
        file_put_contents($dir . '/comments.json', json_encode(['v' => 1, 'comments' => [
            ['id' => '01JB8X4MT7QK2V9Z0C3R5H6ND1', 'by' => 'ana', 'ts' => 't1', 'text' => 'De verificat', 'quote' => 'A doua', 'rev' => 2, 'resolved' => null],
            ['id' => '01JB8X4MT7QK2V9Z0C3R5H6ND2', 'by' => 'bob', 'ts' => 't2', 'text' => 'Gata', 'quote' => '', 'rev' => 2, 'resolved' => ['by' => 'ana', 'ts' => 't3']],
        ]]));
        file_put_contents($dir . '/autosave.ana.json', json_encode(['v' => 1, 'base_rev' => 2, 'ts' => 't4', 'raw' => false, 'form' => 'body=typed']));
        mkdir($this->v1 . '/media/2026', 0775, true);
        file_put_contents($this->v1 . '/media/2026/' . $sha . '.png', $png);
        // A redirect stub left by the move, a page that is also a namespace, a stray
        mkdir($this->v1 . '/pages/reports/mri/old', 0775, true);
        file_put_contents($this->v1 . '/pages/reports/mri/old/redirect', self::REPORT . "\n");
        $this->v1Page('docs:protocol', ["---\ntitle: Protocol\nvisibility: public\n---\n\nProtocol.\n"]);
        $this->v1Page('docs:protocol:child', ["---\ntitle: Child\nvisibility: private\n---\n\nChild.\n"]);
        mkdir($this->v1 . '/pages/docs/stray', 0775, true);
        file_put_contents($this->v1 . '/pages/docs/stray/current.md', "---\ntitle: Old welcome\n---\n\nBun venit.\n");
        // A trashed page, and its deletion in the journal
        $gone = $this->v1Page('docs:gone', ["---\ntitle: Gone\nvisibility: private\n---\n\nGone.\n"]);
        rename($this->v1 . '/pages/docs/gone', $this->v1 . '/trash/gone.' . $gone);
        $journal = new Journal($this->v1 . '/journal');
        $journal->appendIntent('delete', $gone, 'docs:gone', 1, null, '', 'mihai');
        $journal->appendDone($gone, 1, 'delete');
        // The rest of data/
        file_put_contents($this->v1 . '/counters.json', json_encode(['scuc:MR:26' => 41]));
        mkdir($this->v1 . '/users', 0775);
        file_put_contents($this->v1 . '/users/owner.json', '{"username":"owner"}');
        file_put_contents($this->v1 . '/settings.yaml', "site:\n  title: Test\n");
        file_put_contents($this->v1 . '/index.sqlite', 'a cache');
        $before = $this->treeHash($this->v1);

        $result = $this->convert();

        self::assertSame([], $result['failures']);
        $counts = $result['counts'];
        self::assertSame([4, 5, 1, 0, 2, 1, 1, 1, 1, 1, 1, 1], [
            $counts['pages'], $counts['revisions'], $counts['signatures'], $counts['signatures_not_matching'], $counts['comments'],
            $counts['media'], $counts['drafts'], $counts['moves'], $counts['deliveries'], $counts['redirects'], $counts['trash'], $counts['counters'],
        ]);
        self::assertSame($before, $this->treeHash($this->v1), '--from is never touched');

        // Read back through the 2.0 driver
        $storage = new FileTree($this->v2, new RecordingIndex());
        $page = $storage->read(self::REPORT);
        self::assertSame([$report, 2, 'signed', "# TEST Unu\n\nA doua.\n"], [$page->pid, $page->rev, $page->status, $page->body]);
        self::assertSame((string) file_get_contents($dir . '/current.md'), (string) file_get_contents($this->v2 . '/pages/reports/mri/mioveni/260920-test-unu.md'), 'the page file is current.md byte for byte');
        self::assertSame((string) gzdecode((string) file_get_contents($dir . '/rev/0001.md.gz')), $storage->readRevision(self::REPORT, 1));
        self::assertTrue((new Revisions($storage, $this->schemas()))->signature($page, 2)['matches'] ?? false, 'the signature still verifies');
        self::assertSame(['De verificat', 'Gata'], array_column($storage->comments(self::REPORT), 'text'));
        self::assertSame(['by' => 'ana', 'ts' => 't3'], $storage->comments(self::REPORT)[1]['resolved']);
        self::assertSame('scan.png', $storage->mediaOf(self::REPORT)[0]['name']);
        self::assertSame('body=typed', $storage->readDraft(self::REPORT, 'ana')['form'] ?? null);
        self::assertSame('1.2.3', $page->meta['deliveries'][0]['study_uid']);
        self::assertSame('reports:mri:old', $page->meta['moves'][0]['from']);
        self::assertSame(self::REPORT, $storage->redirectTarget('reports:mri:old'));
        self::assertSame('public', $storage->read('docs:protocol')->visibility);
        self::assertSame("Child.\n", $storage->read('docs:protocol:child')->body);
        self::assertSame([[$gone, 'docs:gone', 'mihai']], array_map(static fn (array $t): array => [$t['pid'], $t['path'], $t['deletedBy']], $storage->trash()));
        self::assertSame('docs:gone', $storage->restore($gone, 'owner')->path);
        self::assertSame(['docs:stray'], $storage->strayPaths(), 'the stray kept as a page file to adopt');
        self::assertSame(['scuc:MR:26' => 41], $storage->accessionCounters());
        self::assertFileExists($this->v2 . '/users/owner.json');
        self::assertFileExists($this->v2 . '/media/2026/' . $sha . '.png');
        self::assertSame(fileinode($this->v1 . '/users/owner.json'), fileinode($this->v2 . '/users/owner.json'), 'hard-linked, not copied');
        self::assertFileDoesNotExist($this->v2 . '/index.sqlite', 'the cache is rebuilt, not carried');
        self::assertFileDoesNotExist($this->v2 . '/counters.json');
        self::assertDirectoryDoesNotExist($this->v2 . '/pages/' . str_replace(':', '/', self::REPORT));

        // The index rebuilt there, integrity:verify finds nothing but the stray
        $index = new Sqlite($this->v2 . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $storage = new FileTree($this->v2, $index);
        (new IndexMaintenance($storage, $index, $this->v2, ''))->rebuild();
        $summary = (new IntegrityVerifyTask($storage, new Revisions($storage, $this->schemas()), new IndexMaintenance($storage, $index, $this->v2, ''), $this->v2))
            ->run(MaintenanceTask::CHECK, 'cli', ['backup' => ''])->summary();
        self::assertSame(['problems' => 1, 'stray_files' => 1], array_intersect_key($summary, ['problems' => 1, 'stray_files' => 1]));
    }

    public function testATamperedRevisionFailsThatPageByPidAndTheSourceStaysAsItWas(): void
    {
        $pid = $this->v1Page(self::REPORT, ["---\ntitle: X\n---\n\nOne.\n", "---\ntitle: X\n---\n\nTwo.\n"]);
        $this->v1Page('docs:fine', ["---\ntitle: Fine\n---\n\nFine.\n"]);
        file_put_contents($this->v1 . '/pages/' . str_replace(':', '/', self::REPORT) . '/rev/0001.md.gz', (string) gzencode("changed\n"));
        $before = $this->treeHash($this->v1);

        $result = $this->convert();

        self::assertSame([['pid' => $pid, 'path_hash' => null, 'problem' => 'rev_changed']], $result['failures']);
        self::assertSame(1, $result['counts']['pages'], 'the others still convert');
        self::assertSame($before, $this->treeHash($this->v1));
        self::assertStringNotContainsString('test-unu', (string) json_encode($result), 'never a path (invariant 8)');
    }

    public function testFaultsThe1xArchiveHadAreCarriedAndCounted(): void
    {
        $this->v1Page(self::REPORT, ["---\ntitle: X\n---\n\nOne.\n"], signed: [1]);
        $dir = $this->v1 . '/pages/' . str_replace(':', '/', self::REPORT);
        file_put_contents($dir . '/current.md', "---\ntitle: X\n---\n\nEdited by hand.\n");
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        $meta['signatures'][0]['digest'] = str_repeat('0', 64);
        file_put_contents($dir . '/meta.json', json_encode($meta));

        $result = $this->convert();

        self::assertSame([], $result['failures']);
        self::assertSame([1, 1], [$result['counts']['current_stale'], $result['counts']['signatures_not_matching']]);
        self::assertSame("Edited by hand.\n", (new FileTree($this->v2, new RecordingIndex()))->read(self::REPORT)->body, 'as it was');
    }

    public function testADryRunWritesNothing(): void
    {
        $this->v1Page(self::REPORT, ["---\ntitle: X\n---\n\nOne.\n"]);

        $result = $this->convert(dryRun: true);

        self::assertSame(1, $result['counts']['pages']);
        self::assertFileDoesNotExist($this->v2);
    }

    public function testItRefusesToStartOnAnUnfinishedWriteAnExistingTargetOrA2xSource(): void
    {
        $this->v1Page(self::REPORT, ["---\ntitle: X\n---\n\nOne.\n"]);
        (new Journal($this->v1 . '/journal'))->appendIntent('save', 'P', self::REPORT, 2, 1, 'x', 'owner');
        $this->assertRefused('unfinished writes');

        (new Journal($this->v1 . '/journal'))->appendDone('P', 2);
        mkdir($this->v2);
        $this->assertRefused('--to exists');

        rmdir($this->v2);
        mkdir($this->v1 . '/attic');
        $this->assertRefused('2.0 layout');
    }

    public function testAPathTheNewLayoutCannotHoldFailsThatPage(): void
    {
        $pid = $this->v1Page('docs:notes.md', ["---\ntitle: X\n---\n\nOne.\n"]);

        self::assertSame([['pid' => $pid, 'path_hash' => null, 'problem' => 'path_invalid']], $this->convert()['failures']);
    }

    /** @return array{counts: array<string, int>, failures: list<array<string, ?string>>} */
    private function convert(bool $dryRun = false): array
    {
        return (new V1Converter($this->v1, $this->v2, $this->schemas()))->run($dryRun);
    }

    private function schemas(): Loader
    {
        return new Loader(\dirname(__DIR__, 2) . '/resources/schema');
    }

    private function assertRefused(string $why): void
    {
        try {
            $this->convert();
            self::fail('expected a refusal: ' . $why);
        } catch (RuntimeException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
    }

    /**
     * A page as 1.x Storage\FlatFile wrote it: current.md, rev/NNNN.md.gz,
     * meta.json with a chained revlog and, for $signed revisions, a signature
     * whose digest is computed as signing did (an empty field order)
     *
     * @param list<string>          $documents revisions 1..N
     * @param list<int>             $signed
     * @param array<string, mixed>  $extra     more meta.json keys
     *
     * @return string the pid
     */
    private function v1Page(string $path, array $documents, array $signed = [], array $extra = []): string
    {
        $pid = \Reporion\Support\Ulid::generate();
        $dir = $this->v1 . '/pages/' . str_replace(':', '/', $path);
        mkdir($dir . '/rev', 0775, true);
        $revlog = [];
        foreach ($documents as $i => $document) {
            $n = $i + 1;
            file_put_contents(sprintf('%s/rev/%04d.md.gz', $dir, $n), (string) gzencode($document, 9));
            $revlog[] = Revlog::chained(['n' => $n, 'ts' => sprintf('2026-09-%02dT10:00:00+03:00', $n), 'by' => 'owner', 'note' => null, 'bytes' => \strlen($document), 'sha256' => hash('sha256', $document), 'minor' => false, 'kind' => $n === 1 ? 'create' : 'edit'], $revlog, $pid);
        }
        file_put_contents($dir . '/current.md', $documents[array_key_last($documents)]);
        $signatures = [];
        foreach ($signed as $rev) {
            [$frontmatter, $body] = DocumentFormat::parse($documents[$rev - 1]);
            $signatures[] = ['rev' => $rev, 'by' => 'owner', 'ts' => '2026-09-30T10:00:00+03:00', 'alg' => 'sha256', 'digest' => hash('sha256', Canonical::bytes($frontmatter, $body, [])), 'parafa' => null, 'field_order' => []];
        }
        [$frontmatter] = DocumentFormat::parse($documents[array_key_last($documents)]);
        file_put_contents($dir . '/meta.json', json_encode([
            'pid' => $pid,
            'created' => $revlog[0]['ts'],
            'created_by' => 'owner',
            'path' => $path,
            'rev' => \count($documents),
            'revlog' => $revlog,
            'signatures' => $signatures,
            'status' => $signed !== [] && end($signed) === \count($documents) ? 'signed' : 'draft',
            'visibility' => $frontmatter['visibility'] ?? 'private',
            'share_token' => null,
            'locks' => null,
        ] + $extra));
        @mkdir($this->v1 . '/trash', 0775);

        return $pid;
    }

    private function treeHash(string $dir): string
    {
        $parts = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $parts[] = substr($item->getPathname(), \strlen($dir)) . ':' . hash_file('sha256', $item->getPathname());
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }
}
