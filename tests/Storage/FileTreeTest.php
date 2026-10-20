<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use PDO;
use Reporion\Exception\PageExistsException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Storage\Attic;
use Reporion\Storage\FileTree;
use Reporion\Storage\Journal;
use Reporion\Support\Canonical;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Zip;

/**
 * D54 step 4: the 2.0 driver. A page is pages/{ns…}/{slug}.md, its history
 * attic/{ns…}/{slug}.zip, the rest a row in reporion.sqlite.
 */
final class FileTreeTest extends StorageTestCase
{
    private const PATH = 'reports:mri:mioveni:260922-test';
    private const FILE = '/pages/reports/mri/mioveni/260922-test.md';
    private const ZIP = '/attic/reports/mri/mioveni/260922-test.zip';

    public function testCreateWritesThePageFileTheAtticAndTheRow(): void
    {
        $storage = $this->storage($index);

        $record = $storage->create(self::PATH, $this->frontmatter(), "## Indicatie\nText.\n", 'owner');

        self::assertSame(1, $record->rev);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $record->pid);
        self::assertFileExists($this->dataRoot . self::FILE);
        self::assertFileExists($this->dataRoot . self::ZIP);
        self::assertDirectoryDoesNotExist($this->dataRoot . '/pages/reports/mri/mioveni/260922-test');

        $file = (string) file_get_contents($this->dataRoot . self::FILE);
        self::assertStringContainsString("pid: " . $record->pid . "\n", $file, 'the pid is in the page file (§5)');
        self::assertArrayNotHasKey('pid', $record->frontmatter, 'but never in the caller\'s frontmatter');
        self::assertSame($file, $storage->readRevision(self::PATH, 1), 'the page file is the newest revision (D2)');
        self::assertSame(['0001.md', 'revlog.json'], array_keys(Zip::read((string) file_get_contents($this->dataRoot . self::ZIP))));

        $row = $this->row($record->pid);
        self::assertSame(self::PATH, $row['path']);
        self::assertSame(1, (int) $row['rev']);
        self::assertSame(hash('sha256', $file), $row['file_sha']);
        self::assertSame(1, (int) $this->state()->query("SELECT COUNT(*) FROM revision WHERE pid = '" . $record->pid . "'")?->fetchColumn());

        self::assertCount(1, $index->indexed);
        self::assertSame($record->pid, $index->indexed[0]->pid);
        self::assertArrayNotHasKey('pid', $index->indexed[0]->frontmatter);
    }

    public function testAPidInTheCallersFrontmatterIsNeverWritten(): void
    {
        $storage = $this->storage();
        $record = $storage->create(self::PATH, $this->frontmatter(['pid' => '01JB8X4MT7QK2V9Z0C3R5H6ND0']), 'body', 'owner');
        $saved = $storage->save(self::PATH, ['pid' => 'forged'] + $this->frontmatter(), 'body 2', 1, 'owner');

        self::assertNotSame('01JB8X4MT7QK2V9Z0C3R5H6ND0', $record->pid);
        self::assertStringContainsString('pid: ' . $record->pid, (string) file_get_contents($this->dataRoot . self::FILE));
        self::assertSame($record->pid, $saved->pid);
    }

    public function testCreateReturnsExactlyWhatReadReturns(): void
    {
        $storage = $this->storage();
        $record = $storage->create(self::PATH, $this->frontmatter(), 'body without trailing newline', 'owner');

        self::assertEquals($storage->read(self::PATH), $record);
        self::assertStringEndsWith("\n", $record->body);
    }

    public function testCreateAllocatesACollisionSuffix(): void
    {
        $storage = $this->storage();
        $first = $storage->create(self::PATH, $this->frontmatter(), 'one', 'owner');
        $second = $storage->create(self::PATH, $this->frontmatter(), 'two', 'owner');
        $third = $storage->create(self::PATH, $this->frontmatter(), 'three', 'owner');

        self::assertSame([self::PATH, self::PATH . '-2', self::PATH . '-3'], [$first->path, $second->path, $third->path]);
    }

    public function testExclusiveCreateNeverSuffixesAndANamespaceOfTheSameNameIsNoCollision(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'one', 'owner');
        try {
            $storage->create(self::PATH, $this->frontmatter(), 'two', 'owner', exclusive: true);
            self::fail('a page there must refuse an exclusive create');
        } catch (PageExistsException $e) {
            self::assertSame(self::PATH, $e->path);
        }

        // reports:mri is a namespace (a directory); its page is reports/mri.md beside it
        self::assertSame('reports:mri', $storage->create('reports:mri', $this->frontmatter(), 'description', 'owner', exclusive: true)->path);
        self::assertFileExists($this->dataRoot . '/pages/reports/mri.md');
        self::assertDirectoryExists($this->dataRoot . '/pages/reports/mri');
    }

    public function testPathsThatWouldCollideWithAPageFileAreRefused(): void
    {
        $storage = $this->storage();
        foreach (['reports:x.md:y', 'reports:a.zip', 'reports:mio/veni:x', 'reports:.hidden', 'reports:x.md.tmp-1a'] as $bad) {
            self::assertFalse(FileTree::isValidPath($bad), $bad);
            try {
                $storage->create($bad, $this->frontmatter(), 'body', 'owner');
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue(FileTree::isValidPath('reports:rev:meta.json'), 'the 1.x page-directory names are ordinary segments now');
    }

    public function testDotDotSegmentsNeverReachAnotherFile(): void
    {
        $storage = $this->storage();
        $storage->create('reports:mri:260922-x', $this->frontmatter(), 'reports body', 'owner');
        $storage->create('other:260922-y', $this->frontmatter(), 'other body', 'owner');
        $before = (string) file_get_contents($this->dataRoot . '/pages/other/260922-y.md');
        $attempts = [
            'read' => static fn () => $storage->read('reports:..:other:260922-y'),
            'readRevision' => static fn () => $storage->readRevision('reports:..:other:260922-y', 1),
            'save' => fn () => $storage->save('reports:..:other:260922-y', $this->frontmatter(), 'overwritten', 1, 'owner'),
            'delete' => static fn () => $storage->delete('reports:.:..:other:260922-y', 'owner'),
            'move from' => static fn () => $storage->move('reports:..:other:260922-y', 'reports:moved', 'owner'),
            'comments' => static fn () => $storage->comments('reports:..:other:260922-y'),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail($name . ' accepted a ".." path');
            } catch (PageNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($before, (string) file_get_contents($this->dataRoot . '/pages/other/260922-y.md'));
    }

    public function testSaveAppendsARevision(): void
    {
        $storage = $this->storage($index);
        $created = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');

        $saved = $storage->save(self::PATH, $this->frontmatter(['title' => 'updated']), 'v2 body', 1, 'owner', 'correction');

        self::assertSame(2, $saved->rev);
        self::assertSame($created->pid, $saved->pid);
        self::assertSame('edit', $saved->revlog[1]['kind']);
        self::assertSame('correction', $saved->revlog[1]['note']);
        self::assertStringContainsString('v1 body', $storage->readRevision(self::PATH, 1));
        self::assertStringContainsString('v2 body', (string) file_get_contents($this->dataRoot . self::FILE));
        self::assertSame([], (new Attic())->problems($this->dataRoot . self::ZIP));
        self::assertSame(2, (int) $this->row($created->pid)['rev']);
        self::assertCount(2, $index->indexed);
    }

    public function testAMinorEditReplacesTheUnsignedNewestRevision(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');

        $saved = $storage->save(self::PATH, $this->frontmatter(), 'v1 body, typo fixed', 1, 'owner', 'typo', minor: true);

        self::assertSame(1, $saved->rev);
        self::assertCount(1, $saved->revlog);
        self::assertTrue($saved->revlog[0]['minor']);
        self::assertSame('typo', $saved->revlog[0]['note']);
        self::assertStringContainsString('typo fixed', $storage->readRevision(self::PATH, 1));
        self::assertSame([], (new Attic())->problems($this->dataRoot . self::ZIP));
    }

    public function testAMinorEditOfASignedRevisionIsANewRevision(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $saved = $storage->save(self::PATH, $this->frontmatter(), 'v2 body', 1, 'owner', minor: true);

        self::assertSame(2, $saved->rev);
        self::assertFalse($saved->revlog[1]['minor']);
        self::assertSame('resign', $saved->revlog[1]['kind']);
        self::assertSame('draft', $saved->status);
    }

    public function testAStaleBaseRevIsAConflict(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'v1', 'owner');
        $storage->save(self::PATH, $this->frontmatter(), 'v2', 1, 'owner');

        try {
            $storage->save(self::PATH, $this->frontmatter(), 'v3', 1, 'owner');
            self::fail('expected a conflict');
        } catch (RevisionConflictException $e) {
            self::assertSame(2, $e->current->rev);
            self::assertSame(1, $e->submittedBaseRev);
        }
    }

    public function testRevertWritesTheOldBytesAsANewUnsignedRevision(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $storage->save(self::PATH, $this->frontmatter(['title' => 'v2']), 'v2 body', 1, 'owner');
        $storage->sign(self::PATH, 'owner', []);

        $reverted = $storage->revert(self::PATH, 1, 'owner', 'back to v1');

        self::assertSame(3, $reverted->rev);
        self::assertSame($storage->readRevision(self::PATH, 1), $storage->readRevision(self::PATH, 3));
        self::assertSame('revert', $reverted->revlog[2]['kind']);
        self::assertSame('draft', $reverted->status);
        self::assertCount(1, $reverted->meta['signatures'], 'the old signature stays (D3b)');
    }

    public function testRevertOfAnUnknownRevisionOrPageIsNotFound(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'v1', 'owner');
        foreach ([static fn () => $storage->revert(self::PATH, 99, 'owner'), static fn () => $storage->revert('reports:nope', 1, 'owner')] as $attempt) {
            try {
                $attempt();
                self::fail('expected not found');
            } catch (PageNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSignRecordsTheDigestOfTheStoredRevisionInTheAtticAndTheMirror(): void
    {
        $storage = $this->storage($index);
        $created = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $schemaFields = ['modality' => [], 'title' => []];

        $signed = $storage->sign(self::PATH, 'owner', $schemaFields, 'AG-04127');

        self::assertSame('signed', $signed->status);
        self::assertSame(1, $signed->rev);
        $signature = $signed->meta['signatures'][0];
        self::assertSame(['owner', 'AG-04127', 1], [$signature['by'], $signature['parafa'], $signature['rev']]);
        [$frontmatter, $body] = DocumentFormat::parse($storage->readRevision(self::PATH, 1));
        self::assertSame(hash('sha256', Canonical::bytes($frontmatter, $body, $schemaFields)), $signature['digest'], 'the digest Revisions::signature() recomputes');
        self::assertSame(Canonical::orderSpec($schemaFields), $signature['field_order']);
        self::assertSame('signed', (new Attic())->history($this->dataRoot . self::ZIP)['status']);
        self::assertSame('signed', $this->row($created->pid)['status']);
        self::assertSame(1, (int) $this->state()->query('SELECT COUNT(*) FROM signature')?->fetchColumn());
        self::assertSame('signed', $index->indexed[array_key_last($index->indexed)]->status);

        self::assertCount(1, $storage->sign(self::PATH, 'owner', $schemaFields)->meta['signatures'], 'signing again is a no-op');
    }

    public function testDeleteTrashesTheFilesAndRestorePutsThemBack(): void
    {
        $storage = $this->storage($index);
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        $storage->save(self::PATH, $this->frontmatter(), 'body two', 1, 'owner');

        $storage->delete(self::PATH, 'cleaner');

        self::assertFileDoesNotExist($this->dataRoot . self::FILE);
        self::assertFileDoesNotExist($this->dataRoot . self::ZIP);
        self::assertFileExists($this->dataRoot . '/trash/' . $created->pid . '/page.md');
        self::assertFileExists($this->dataRoot . '/trash/' . $created->pid . '/attic.zip');
        self::assertSame([$created->pid], $index->removed);
        $trash = $storage->trash();
        self::assertSame([$created->pid, self::PATH, 'cleaner', false], [$trash[0]['pid'], $trash[0]['path'], $trash[0]['deletedBy'], $trash[0]['signed']]);
        self::assertSame([], iterator_to_array($storage->allPaths()));

        $restored = $storage->restore($created->pid, 'owner');
        self::assertSame(self::PATH, $restored->path);
        self::assertSame(2, $restored->rev);
        self::assertSame([], $storage->trash());
        self::assertDirectoryDoesNotExist($this->dataRoot . '/trash/' . $created->pid);
    }

    public function testRestoreOntoATakenPathTakesTheNextFreeOne(): void
    {
        $storage = $this->storage();
        $first = $storage->create(self::PATH, $this->frontmatter(), 'first', 'owner');
        $storage->delete(self::PATH, 'owner');
        $storage->create(self::PATH, $this->frontmatter(), 'second', 'owner');

        self::assertSame(self::PATH . '-2', $storage->restore($first->pid, 'owner')->path);
    }

    public function testPurgeRemovesAPageForGoodAndASignedOneOnlyWithTheOverride(): void
    {
        $storage = $this->storage();
        $plain = $storage->create(self::PATH, $this->frontmatter(), 'plain', 'owner');
        $signed = $storage->create('reports:mri:signed', $this->frontmatter(), 'signed', 'owner');
        $storage->sign('reports:mri:signed', 'owner', []);
        $storage->delete(self::PATH, 'owner');
        $storage->delete('reports:mri:signed', 'owner');

        $storage->purge($plain->pid, 'owner');
        try {
            $storage->purge($signed->pid, 'owner');
            self::fail('a signed page was purged without the override');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $storage->purge($signed->pid, 'owner', includeSigned: true);

        self::assertSame([], $storage->trash());
        self::assertDirectoryDoesNotExist($this->dataRoot . '/trash/' . $plain->pid);
        self::assertSame(0, (int) $this->state()->query('SELECT COUNT(*) FROM page')?->fetchColumn());
        self::assertSame(0, (int) $this->state()->query('SELECT COUNT(*) FROM signature')?->fetchColumn());
    }

    public function testMoveCarriesTheFileTheAtticAndEverythingKeyedByPid(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        $storage->updateComments(self::PATH, static fn (array $c): array => [...$c, ['id' => '01JB8X4MT7QK2V9Z0C3R5H6ND1', 'by' => 'a', 'ts' => 't', 'text' => 'hello', 'quote' => '', 'rev' => 1, 'resolved' => null]]);
        $storage->saveDraft(self::PATH, 'owner', 1, false, 'draft form');

        $moved = $storage->move(self::PATH, 'reports:mri:pitesti:260922-test', 'owner');

        self::assertSame($created->pid, $moved->pid);
        self::assertFileDoesNotExist($this->dataRoot . self::FILE);
        self::assertFileDoesNotExist($this->dataRoot . self::ZIP);
        self::assertFileExists($this->dataRoot . '/attic/reports/mri/pitesti/260922-test.zip');
        self::assertSame('reports:mri:pitesti:260922-test', $storage->redirectTarget(self::PATH));
        self::assertSame([['from' => self::PATH, 'to' => 'reports:mri:pitesti:260922-test']], array_map(static fn (array $m): array => ['from' => $m['from'], 'to' => $m['to']], $moved->meta['moves']));
        self::assertSame('hello', $storage->comments('reports:mri:pitesti:260922-test')[0]['text']);
        self::assertSame('draft form', $storage->readDraft('reports:mri:pitesti:260922-test', 'owner')['form'] ?? null);

        // moved again: the first path follows the pid, never a chain
        $storage->move('reports:mri:pitesti:260922-test', 'reports:mri:arges:260922-test', 'owner');
        self::assertSame('reports:mri:arges:260922-test', $storage->redirectTarget(self::PATH));

        // a page created at an old path takes the name; the redirect goes
        $storage->create(self::PATH, $this->frontmatter(), 'new page', 'owner');
        self::assertNull($storage->redirectTarget(self::PATH));
    }

    public function testAPageWithPagesUnderItMovesAndDeletesAlone(): void
    {
        $storage = $this->storage();
        $storage->create('teaching:mri', $this->frontmatter(), 'namespace page', 'owner');
        $storage->create('teaching:mri:case-1', $this->frontmatter(), 'a case', 'owner');

        $storage->move('teaching:mri', 'teaching:rm', 'owner');
        self::assertSame("a case\n", $storage->read('teaching:mri:case-1')->body);
        $storage->delete('teaching:rm', 'owner');
        self::assertSame("a case\n", $storage->read('teaching:mri:case-1')->body);
    }

    public function testMoveOntoATakenPathIsRefused(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'one', 'owner');
        $storage->create('reports:other', $this->frontmatter(), 'two', 'owner');

        $this->expectException(InvalidArgumentException::class);
        $storage->move(self::PATH, 'reports:other', 'owner');
    }

    public function testMediaCommentsDeliveriesAndDraftsLiveInTheStateDatabase(): void
    {
        $storage = $this->storage($index);
        $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);

        $entry = $storage->attachMedia(self::PATH, $png, '../Axial T2 [x].png', 'owner');
        self::assertSame($entry, $storage->attachMedia(self::PATH, $png, 'again.png', 'owner'), 'the same bytes return the entry');
        self::assertSame('Axial T2 x.png', $entry['name']);
        self::assertSame([$entry], $storage->mediaOf(self::PATH));
        self::assertNotNull($storage->mediaFile($entry['sha256'], 'png'));
        self::assertSame([$entry['sha256'] . '.png'], $index->indexed[array_key_last($index->indexed)]->media);

        $storage->recordDelivery(self::PATH, ['to' => 'pacs', 'rev' => 1, 'at' => 't', 'by' => 'owner', 'outcome' => 'ok', 'study_uid' => '1.2.3', 'nested' => ['dropped']]);
        self::assertSame([['to' => 'pacs', 'rev' => 1, 'at' => 't', 'by' => 'owner', 'outcome' => 'ok', 'study_uid' => '1.2.3']], $storage->read(self::PATH)->meta['deliveries']);

        $storage->saveDraft(self::PATH, 'owner', 1, true, 'form text');
        self::assertSame(['base_rev' => 1, 'raw' => true, 'form' => 'form text', 'current_rev' => 1], array_diff_key((array) $storage->readDraft(self::PATH, 'owner'), ['ts' => 1]));
        self::assertNull($storage->readDraft(self::PATH, 'system:tagger'));
        $storage->save(self::PATH, $this->frontmatter(), 'body 2', 1, 'owner');
        self::assertNull($storage->readDraft(self::PATH, 'owner'), 'its author\'s save supersedes the draft');
        try {
            $storage->saveDraft(self::PATH, 'owner', 1, false, 'behind');
            self::fail('a draft of an old revision was kept');
        } catch (RevisionConflictException) {
            self::addToAssertionCount(1);
        }

        $kept = $storage->updateComments(self::PATH, static fn (array $c): array => [...$c,
            ['id' => '01JB8X4MT7QK2V9Z0C3R5H6ND2', 'by' => 'a', 'ts' => '1', 'text' => 'one', 'quote' => 'q', 'rev' => 2, 'resolved' => null],
            ['id' => '01JB8X4MT7QK2V9Z0C3R5H6ND3', 'by' => 'b', 'ts' => '2', 'text' => 'two', 'quote' => '', 'rev' => 2, 'resolved' => ['by' => 'a', 'ts' => '3']],
            ['by' => 'malformed'],
        ]);
        self::assertSame(['one', 'two'], array_column($kept, 'text'));
        self::assertSame(['by' => 'a', 'ts' => '3'], $storage->comments(self::PATH)[1]['resolved']);
    }

    public function testAllPathsIsAQueryAndSkipsARowWhoseFileIsGone(): void
    {
        $storage = $this->storage();
        $storage->create('reports:mri:a', $this->frontmatter(), 'A', 'owner');
        $storage->create('site:home', $this->frontmatter(), 'Home', 'owner');
        $storage->create('reports:mri:gone', $this->frontmatter(), 'gone', 'owner');
        unlink($this->dataRoot . '/pages/reports/mri/gone.md');
        file_put_contents($this->dataRoot . '/pages/reports/mri/hand-made.md', "---\ntitle: x\n---\n\nby hand\n");

        self::assertSame(['reports:mri:a', 'site:home'], iterator_to_array($storage->allPaths(), false));
        self::assertSame(['reports:mri:hand-made'], $storage->strayPaths());
    }

    public function testSnapshotOfMatchesWhatTheWriteIndexed(): void
    {
        $storage = $this->storage($index);
        $storage->create(self::PATH, $this->frontmatter(), 'Text.', 'owner');

        self::assertEquals($index->indexed[0], $storage->snapshotOf(self::PATH));
    }

    public function testArchivedStatusFromFrontmatterIsKeptAndSignedIsNot(): void
    {
        $storage = $this->storage();
        self::assertSame('archived', $storage->create('reports:a', $this->frontmatter(['status' => 'archived']), 'x', 'owner')->status);
        self::assertSame('archived', $storage->save('reports:a', $this->frontmatter(['status' => 'archived']), 'y', 1, 'owner')->status);
        self::assertSame('draft', $storage->create('reports:b', $this->frontmatter(['status' => 'signed']), 'x', 'owner')->status);
    }

    public function testVisibilityIsTheFrontmattersOrWhatThePageHad(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(['visibility' => 'public']), 'x', 'owner');
        $fm = $this->frontmatter();
        unset($fm['visibility']);

        self::assertSame('public', $storage->save(self::PATH, $fm, 'y', 1, 'owner')->visibility);
    }

    /** §5: a page file moved on disk by hand is rejoined to its row by the pid it carries */
    public function testAHandMovedPageFileIsRejoinedByItsPid(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        mkdir($this->dataRoot . '/pages/reports/mri/arges', 0775, true);
        rename($this->dataRoot . self::FILE, $this->dataRoot . '/pages/reports/mri/arges/260922-test.md');

        self::assertSame(['reports:mri:arges:260922-test'], $storage->strayPaths());
        self::assertSame(self::PATH, $storage->storedPath('reports:mri:arges:260922-test'));
        self::assertSame(FileTree::PATH_REPAIRED, $storage->reconcilePath('reports:mri:arges:260922-test', 'system:admin'));

        $record = $storage->read('reports:mri:arges:260922-test');
        self::assertSame($created->pid, $record->pid);
        self::assertFileExists($this->dataRoot . '/attic/reports/mri/arges/260922-test.zip');
        self::assertSame('reports:mri:arges:260922-test', $storage->redirectTarget(self::PATH));
        self::assertSame([], $storage->strayPaths());
        self::assertSame(FileTree::PATH_OK, $storage->reconcilePath('reports:mri:arges:260922-test', 'system:admin'));
    }

    /** A file written before 2.0 has no pid: rejoined by the bytes its page last had */
    public function testAPageFileWithoutAPidIsRejoinedByItsBytes(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        $bytes = (string) file_get_contents($this->dataRoot . self::FILE);
        unlink($this->dataRoot . self::FILE);
        // the same bytes, as a 1.x file without its pid line would have them: forge the row's file_sha to match
        $legacy = str_replace("pid: " . $created->pid . "\n", '', $bytes);
        $this->state()->prepare('UPDATE page SET file_sha = ? WHERE pid = ?')->execute([hash('sha256', $legacy), $created->pid]);
        file_put_contents($this->dataRoot . '/pages/reports/moved.md', $legacy);

        self::assertSame(FileTree::PATH_REPAIRED, $storage->reconcilePath('reports:moved', 'system:admin'));
        self::assertSame($created->pid, $storage->read('reports:moved')->pid);
    }

    public function testACopiedPageFileIsLeftAlone(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        copy($this->dataRoot . self::FILE, $this->dataRoot . '/pages/reports/copy.md');

        self::assertSame(FileTree::PATH_COPY, $storage->reconcilePath('reports:copy', 'system:admin'));
        self::assertFalse($storage->adopt('reports:copy'));
    }

    public function testAPageFileNoPageIsKnownForIsAdoptedAsRevisionOne(): void
    {
        $storage = $this->storage($index);
        mkdir($this->dataRoot . '/pages/protocols', 0775, true);
        $text = "---\ntitle: Protocol\nvisibility: private\n---\n\nwritten by hand\n";
        file_put_contents($this->dataRoot . '/pages/protocols/knee.md', $text);

        self::assertTrue($storage->adopt('protocols:knee'));

        $record = $storage->read('protocols:knee');
        self::assertSame(1, $record->rev);
        self::assertSame($text, $storage->readRevision('protocols:knee', 1), 'adopted as it is');
        self::assertTrue($record->revlog[0]['auto'] ?? false);
        self::assertSame('protocols:knee', $index->indexed[array_key_last($index->indexed)]->path);
    }

    /**
     * Crash test: a save killed after its attic zip was renamed and before
     * the page file: replay writes the page file from the zip and the row
     * from its record.
     */
    public function testReplayFinishesASaveThatStoppedAfterTheAttic(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'v1 body', 'owner');
        $storage->sign(self::PATH, 'owner', []);
        $v1 = (string) file_get_contents($this->dataRoot . self::FILE);
        // what the crashed save had written: revision 2 in the attic, nothing else
        $document = str_replace('v1 body', 'v2 body', $v1);
        $history = (new Attic())->history($this->dataRoot . self::ZIP);
        $history['revlog'][] = \Reporion\Support\Revlog::chained(['n' => 2, 'ts' => '2026-10-20T10:00:00+03:00', 'by' => 'owner', 'note' => null, 'bytes' => \strlen($document), 'sha256' => hash('sha256', $document), 'minor' => false, 'kind' => 'resign'], $history['revlog'], $created->pid);
        $history['status'] = 'draft';
        (new Attic())->append($this->dataRoot . self::ZIP, $document, $history, new \DateTimeImmutable());
        (new Journal($this->dataRoot . '/journal'))->appendIntent('save', $created->pid, self::PATH, 2, 1, hash('sha256', $document), 'owner');

        $outcomes = $this->storage($index)->replayJournal();

        self::assertSame('recovered', $outcomes[0]['outcome']);
        self::assertSame($document, file_get_contents($this->dataRoot . self::FILE));
        $record = $storage->read(self::PATH);
        self::assertSame(2, $record->rev);
        self::assertSame('resign', $record->revlog[1]['kind']);
        self::assertSame('draft', $this->row($created->pid)['status']);
        self::assertSame(2, (int) $this->row($created->pid)['rev']);
        self::assertSame(2, $index->indexed[0]->rev);
        self::assertSame([], $storage->replayJournal(), 'idempotent');
    }

    /** A create killed after its attic and page file, before the row: replay makes the row */
    public function testReplayFinishesACreateThatStoppedBeforeTheRow(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        $this->state()->prepare('DELETE FROM page WHERE pid = ?')->execute([$created->pid]);
        (new Journal($this->dataRoot . '/journal'))->appendIntent('create', $created->pid, self::PATH, 1, null, '', 'owner');

        self::assertSame('recovered', $storage->replayJournal()[0]['outcome']);
        self::assertSame($created->pid, $storage->read(self::PATH)->pid);
        self::assertSame('owner', $this->row($created->pid)['created_by']);
    }

    public function testReplayDiscardsAWriteThatNeverReachedTheAttic(): void
    {
        $storage = $this->storage($index);
        (new Journal($this->dataRoot . '/journal'))->appendIntent('create', '01JB8X4MT7QK2V9Z0C3R5H6ND4', self::PATH, 1, null, 'x', 'owner');

        self::assertSame('discarded', $storage->replayJournal()[0]['outcome']);
        self::assertSame([], $index->indexed);
        self::assertFileDoesNotExist($this->dataRoot . self::FILE);
        self::assertSame([], $storage->replayJournal());
    }

    public function testReplayFinishesAMoveThatStoppedBetweenItsRenames(): void
    {
        $storage = $this->storage();
        $created = $storage->create(self::PATH, $this->frontmatter(), 'body', 'owner');
        // the page file was renamed, the zip not yet, the row not yet
        mkdir($this->dataRoot . '/pages/reports/new', 0775, true);
        rename($this->dataRoot . self::FILE, $this->dataRoot . '/pages/reports/new/x.md');
        (new Journal($this->dataRoot . '/journal'))->appendIntent('move', $created->pid, 'reports:new:x', 1, null, '', 'owner', ['from' => self::PATH]);

        self::assertSame('recovered', $storage->replayJournal()[0]['outcome']);
        self::assertSame($created->pid, $storage->read('reports:new:x')->pid);
        self::assertFileExists($this->dataRoot . '/attic/reports/new/x.zip');
        self::assertSame('reports:new:x', $storage->redirectTarget(self::PATH));
    }

    public function testReplayFinishesADeleteAndASignStoppedHalfWay(): void
    {
        $storage = $this->storage();
        $deleted = $storage->create('reports:del', $this->frontmatter(), 'body', 'owner');
        mkdir($this->dataRoot . '/trash/' . $deleted->pid, 0775, true);
        rename($this->dataRoot . '/pages/reports/del.md', $this->dataRoot . '/trash/' . $deleted->pid . '/page.md');
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('delete', $deleted->pid, 'reports:del', 1, null, '', 'owner');

        $signed = $storage->create('reports:sig', $this->frontmatter(), 'body', 'owner');
        $history = (new Attic())->history($this->dataRoot . '/attic/reports/sig.zip');
        $history['status'] = 'signed';
        $history['signatures'][] = ['rev' => 1, 'by' => 'owner', 'ts' => 't', 'alg' => 'sha256', 'digest' => str_repeat('a', 64), 'parafa' => null, 'field_order' => []];
        (new Attic())->writeHistory($this->dataRoot . '/attic/reports/sig.zip', $history, new \DateTimeImmutable());
        $journal->appendIntent('sign', $signed->pid, 'reports:sig', 1, null, '', 'owner');

        self::assertSame(['recovered', 'recovered'], array_column($storage->replayJournal(), 'outcome'));
        self::assertSame([$deleted->pid], array_column($storage->trash(), 'pid'));
        self::assertFileExists($this->dataRoot . '/trash/' . $deleted->pid . '/attic.zip');
        self::assertSame('signed', $this->row($signed->pid)['status']);
    }

    public function testAccessionCountersAreAllocatedInTheStateDatabase(): void
    {
        $storage = $this->storage();

        self::assertSame(1, $storage->nextAccession('scuc:MR:26', 0));
        self::assertSame(2, $storage->nextAccession('scuc:MR:26', 0));
        self::assertSame(1765, $storage->nextAccession('scuc:MR:26', 1764), 'never below what disk already holds');
        self::assertSame(['scuc:MR:26' => 1765], $storage->accessionCounters());
    }

    private function storage(?RecordingIndex &$index = null): FileTree
    {
        $index = new RecordingIndex();

        return new FileTree($this->dataRoot, $index);
    }

    private function state(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dataRoot . '/reporion.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        $stmt = $this->state()->prepare('SELECT * FROM page WHERE pid = ?');
        $stmt->execute([$pid]);

        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function frontmatter(array $overrides = []): array
    {
        return array_merge(['title' => 'RM cerebral nativ', 'modality' => 'MR', 'visibility' => 'private'], $overrides);
    }
}
