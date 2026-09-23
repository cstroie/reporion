<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;
use Reporion\Support\Ulid;
use Symfony\Component\Yaml\Yaml;

final class FlatFileTest extends StorageTestCase
{
    public function testCreateWritesRevisionCurrentAndMeta(): void
    {
        $index = new RecordingIndex();
        $storage = new FlatFile($this->dataRoot, $index);

        $record = $storage->create(
            'reports:mri:mioveni:260922-ionescu-maria',
            $this->frontmatter(),
            "## Indicatie\nText.\n",
            'owner'
        );

        self::assertSame(1, $record->rev);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $record->pid);

        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/260922-ionescu-maria';
        self::assertFileExists($dir . '/rev/0001.md.gz');
        self::assertFileExists($dir . '/current.md');
        self::assertFileExists($dir . '/meta.json');

        self::assertStringContainsString('## Indicatie', (string) file_get_contents($dir . '/current.md'));

        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        self::assertCount(1, $meta['revlog']);
        self::assertSame('create', $meta['revlog'][0]['kind']);
        self::assertSame($record->pid, $meta['pid']);

        self::assertCount(1, $index->indexed);
        self::assertSame($record->pid, $index->indexed[0]->pid);
        self::assertSame(1, $index->indexed[0]->rev);
    }

    /**
     * docs/FORMATS.md §1 — two exams for the same patient on the same day.
     */
    public function testCreateAllocatesCollisionSuffixOnSamePath(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());

        $first = $storage->create('reports:mri:mioveni:260922-ionescu-maria', $this->frontmatter(), 'body one', 'owner');
        $second = $storage->create('reports:mri:mioveni:260922-ionescu-maria', $this->frontmatter(), 'body two', 'owner');
        $third = $storage->create('reports:mri:mioveni:260922-ionescu-maria', $this->frontmatter(), 'body three', 'owner');

        self::assertSame('reports:mri:mioveni:260922-ionescu-maria', $first->path);
        self::assertSame('reports:mri:mioveni:260922-ionescu-maria-2', $second->path);
        self::assertSame('reports:mri:mioveni:260922-ionescu-maria-3', $third->path);
        self::assertNotSame($first->pid, $second->pid);
    }

    public function testCreateRejectsPathWithSlashInSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlatFile($this->dataRoot, new RecordingIndex()))
            ->create('reports:mri:mio/veni:x', $this->frontmatter(), 'body', 'owner');
    }

    public function testSaveAppendsNewRevision(): void
    {
        $storage = new FlatFile($this->dataRoot, $index = new RecordingIndex());
        $created = $storage->create('reports:mri:mioveni:260922-x', $this->frontmatter(), 'v1 body', 'owner');

        $saved = $storage->save(
            'reports:mri:mioveni:260922-x',
            $this->frontmatter(['title' => 'updated']),
            'v2 body',
            1,
            'owner',
            'correction'
        );

        self::assertSame(2, $saved->rev);
        self::assertSame($created->pid, $saved->pid);
        self::assertCount(2, $saved->revlog);
        self::assertSame('edit', $saved->revlog[1]['kind']);
        self::assertSame('correction', $saved->revlog[1]['note']);

        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/260922-x';
        self::assertFileExists($dir . '/rev/0001.md.gz');
        self::assertFileExists($dir . '/rev/0002.md.gz');
        self::assertStringContainsString('v2 body', (string) file_get_contents($dir . '/current.md'));

        self::assertCount(2, $index->indexed);
    }

    public function testSaveWithStaleBaseRevThrowsConflict(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mri:mioveni:260922-x', $this->frontmatter(), 'v1', 'owner');
        $storage->save('reports:mri:mioveni:260922-x', $this->frontmatter(), 'v2', 1, 'owner');

        try {
            $storage->save('reports:mri:mioveni:260922-x', $this->frontmatter(), 'v3-conflicting', 1, 'owner');
            self::fail('Expected RevisionConflictException');
        } catch (RevisionConflictException $e) {
            self::assertSame(2, $e->current->rev);
            self::assertSame(1, $e->submittedBaseRev);
        }
    }

    /**
     * A retried save() (same request replayed, or a journal-replay-in-progress
     * intent re-run) can find rev/000N.md.gz already written from the first
     * attempt. current.md must then mirror exactly those existing bytes, not
     * the freshly re-encoded document from this call — otherwise current.md
     * stops being the newest revision byte-for-byte (CLAUDE.md D2).
     */
    public function testSaveWithPreexistingRevisionFileKeepsCurrentMdInSync(): void
    {
        $path = 'reports:mri:mioveni:260922-dup';
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create($path, $this->frontmatter(), 'v1 body', 'owner');

        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/260922-dup';
        $existingDocument = "---\ntitle: 'crashed attempt'\nvisibility: private\n---\n\ncrashed body\n";
        file_put_contents($dir . '/rev/0002.md.gz', gzencode($existingDocument, 9));

        $storage->save($path, $this->frontmatter(['title' => 'retried attempt']), 'retried body', 1, 'owner');

        self::assertSame($existingDocument, file_get_contents($dir . '/current.md'));
    }

    public function testReadUnknownPathThrowsPageNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        (new FlatFile($this->dataRoot, new RecordingIndex()))->read('reports:mri:mioveni:nope');
    }

    public function testReadRevisionReturnsHistoricalBody(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('reports:mri:mioveni:260922-x', $this->frontmatter(), 'original body', 'owner');
        $storage->save('reports:mri:mioveni:260922-x', $this->frontmatter(), 'revised body', 1, 'owner');

        $rev1 = $storage->readRevision('reports:mri:mioveni:260922-x', 1);
        $rev2 = $storage->readRevision('reports:mri:mioveni:260922-x', 2);

        self::assertStringContainsString('original body', $rev1);
        self::assertStringNotContainsString('revised body', $rev1);
        self::assertStringContainsString('revised body', $rev2);
    }

    /**
     * Crash test (CLAUDE.md "Storage" testing requirement): construct the
     * exact on-disk state a process death between "write rev file" and
     * "write current.md" would leave — rev/0001.md.gz durable, journal
     * intent durable, current.md and meta.json both absent — then assert
     * replay leaves no partial page and is idempotent on a second run.
     */
    public function testReplayRecoversWriteInterruptedBeforeCurrentMdAndMeta(): void
    {
        $path = 'reports:mri:mioveni:260922-x';
        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/260922-x';
        mkdir($dir . '/rev', 0775, true);

        $frontmatter = $this->frontmatter();
        $document = "---\n" . Yaml::dump($frontmatter, 4, 2) . "---\n\ncrashed body\n";
        $pid = Ulid::generate();
        $bodySha = hash('sha256', $document);

        file_put_contents($dir . '/rev/0001.md.gz', gzencode($document, 9));

        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('create', $pid, $path, 1, null, $bodySha, 'owner');

        self::assertFileDoesNotExist($dir . '/current.md');
        self::assertFileDoesNotExist($dir . '/meta.json');

        $index = new RecordingIndex();
        $storage = new FlatFile($this->dataRoot, $index);
        $outcomes = $storage->replayJournal();

        self::assertCount(1, $outcomes);
        self::assertSame('recovered', $outcomes[0]['outcome']);
        self::assertSame($pid, $outcomes[0]['pid']);

        self::assertFileExists($dir . '/current.md');
        self::assertSame($document, file_get_contents($dir . '/current.md'));

        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        self::assertSame(1, $meta['rev']);
        self::assertCount(1, $meta['revlog']);

        self::assertCount(1, $index->indexed);

        // Idempotent: the done line now covers this intent.
        self::assertSame([], $storage->replayJournal());
    }

    /**
     * Crash before the rev file itself became durable: nothing was ever
     * observably written, so replay must discard the intent, not fabricate
     * a page from thin air.
     */
    public function testReplayDiscardsIntentWithNoRevisionFile(): void
    {
        $path = 'reports:mri:mioveni:260922-y';
        $pid = Ulid::generate();

        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('create', $pid, $path, 1, null, hash('sha256', 'never written'), 'owner');

        $index = new RecordingIndex();
        $storage = new FlatFile($this->dataRoot, $index);
        $outcomes = $storage->replayJournal();

        self::assertCount(1, $outcomes);
        self::assertSame('discarded', $outcomes[0]['outcome']);
        self::assertSame([], $index->indexed);

        $dir = $this->dataRoot . '/pages/reports/mri/mioveni/260922-y';
        self::assertFileDoesNotExist($dir . '/current.md');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function frontmatter(array $overrides = []): array
    {
        return array_merge([
            'title' => 'RM cerebral nativ',
            'modality' => 'MR',
            'visibility' => 'private',
        ], $overrides);
    }
}
