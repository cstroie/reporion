<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * The server autosave (D25 amended 2026-10-12): one disposable draft of the
 * editor's form per user, `autosave.{username}.json` in the page's own
 * directory — never a revision, never indexed, removed by its author's next
 * write, carried with the page.
 */
final class AutosaveDraftTest extends StorageTestCase
{
    private const PATH = 'docs:note';

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    private function create(FlatFile $storage, string $path = self::PATH): void
    {
        $storage->create($path, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
    }

    private function file(string $user, string $path = self::PATH): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . '/autosave.' . $user . '.json';
    }

    public function testARoundTripPerUserNeverARevision(): void
    {
        $storage = $this->storage();
        $this->create($storage);

        $storage->saveDraft(self::PATH, 'ana', 1, false, 'body=typed&fm%5Btitle%5D=T');
        $storage->saveDraft(self::PATH, 'bob', 1, true, 'document=other');

        $ana = $storage->readDraft(self::PATH, 'ana');
        self::assertSame('body=typed&fm%5Btitle%5D=T', $ana['form']);
        self::assertSame([1, false, 1], [$ana['base_rev'], $ana['raw'], $ana['current_rev']]);
        self::assertSame('document=other', $storage->readDraft(self::PATH, 'bob')['form'], 'each user their own');
        self::assertNull($storage->readDraft(self::PATH, 'eve'));
        self::assertSame(1, $storage->read(self::PATH)->rev, 'not a revision');
        self::assertSame("text\n", $storage->read(self::PATH)->body);
        self::assertStringNotContainsString('docs:note', (string) file_get_contents($this->file('ana')), 'no path in the file (invariant 8)');

        $storage->discardDraft(self::PATH, 'ana');
        self::assertNull($storage->readDraft(self::PATH, 'ana'));
        self::assertNotNull($storage->readDraft(self::PATH, 'bob'));
    }

    public function testRefusals(): void
    {
        $storage = $this->storage();
        $this->create($storage);

        try {
            $storage->saveDraft('docs:none', 'ana', 1, false, 'x');
            self::fail('a page not written yet has no directory to keep a draft in');
        } catch (PageNotFoundException) {
            self::assertDirectoryDoesNotExist($this->dataRoot . '/pages/docs/none');
        }
        try {
            $storage->saveDraft(self::PATH, 'ana', 0, false, 'x');
            self::fail('the editor is behind');
        } catch (RevisionConflictException $e) {
            self::assertNull($storage->readDraft(self::PATH, 'ana'), 'nothing written');
            self::assertSame(1, $e->current->rev);
        }
        try {
            $storage->saveDraft(self::PATH, 'ana', 1, false, str_repeat('x', FlatFile::DRAFT_MAX_BYTES + 1));
            self::fail('too large');
        } catch (InvalidArgumentException) {
            self::assertNull($storage->readDraft(self::PATH, 'ana'));
        }
        foreach (['system:tagger', '../x', 'A B', ''] as $name) {
            try {
                $storage->saveDraft(self::PATH, $name, 1, false, 'x');
                self::fail($name . ' is not an account');
            } catch (PageNotFoundException) {
                self::assertNull($storage->readDraft(self::PATH, $name));
            }
        }
    }

    public function testTheAuthorsNextWriteRemovesTheirDraftOnly(): void
    {
        $storage = $this->storage();
        $this->create($storage);
        $storage->saveDraft(self::PATH, 'ana', 1, false, 'a');
        $storage->saveDraft(self::PATH, 'bob', 1, false, 'b');

        $storage->save(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text 2\n", 1, 'ana');
        self::assertNull($storage->readDraft(self::PATH, 'ana'), 'Save');
        $bob = $storage->readDraft(self::PATH, 'bob');
        self::assertSame([1, 2], [$bob['base_rev'], $bob['current_rev']], "bob's draft is kept, now of an older revision");

        $storage->saveDraft(self::PATH, 'ana', 2, false, 'a2');
        $storage->save(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text 3\n", 2, 'ana', minor: true);
        self::assertNull($storage->readDraft(self::PATH, 'ana'), 'a minor Save');

        $storage->saveDraft(self::PATH, 'ana', 2, false, 'a3');
        $storage->revert(self::PATH, 1, 'ana');
        self::assertNull($storage->readDraft(self::PATH, 'ana'), 'a revert');
    }

    public function testTheDraftTravelsWithThePageAndGoesWithPurge(): void
    {
        $storage = $this->storage();
        $this->create($storage);
        $storage->saveDraft(self::PATH, 'ana', 1, false, 'a');

        $storage->move(self::PATH, 'docs:moved', 'ana');
        self::assertSame('a', $storage->readDraft('docs:moved', 'ana')['form']);
        self::assertFileDoesNotExist($this->file('ana'), 'not left in the redirect stub');

        $pid = $storage->read('docs:moved')->pid;
        $storage->delete('docs:moved', 'ana');
        $storage->restore($pid, 'ana');
        self::assertSame('a', $storage->readDraft('docs:moved', 'ana')['form'], 'delete and restore');

        // Onto a namespace of the same name the page's entries are moved one by one
        $storage->create('docs:target:child', ['title' => 'C', 'visibility' => 'private'], "c\n", 'ana');
        $storage->move('docs:moved', 'docs:target', 'ana');
        self::assertSame('a', $storage->readDraft('docs:target', 'ana')['form'], 'into a namespace of the same name');

        // Purge removes it with the rest
        $this->create($storage, 'docs:solo');
        $storage->saveDraft('docs:solo', 'ana', 1, false, 'z');
        $pid = $storage->read('docs:solo')->pid;
        $storage->delete('docs:solo', 'ana');
        self::assertCount(1, glob($this->dataRoot . '/trash/*/*/autosave.ana.json') ?: glob($this->dataRoot . '/trash/*/autosave.ana.json') ?: [], 'in the trash with the page');
        $storage->purge($pid, 'ana', false);
        self::assertSame([], glob($this->dataRoot . '/trash/*/autosave.*.json') ?: []);
        self::assertSame([], glob($this->dataRoot . '/trash/*/*/autosave.*.json') ?: []);
    }

    public function testADraftNeverMakesAPathTakenOrNamesAPage(): void
    {
        $storage = $this->storage();
        $this->create($storage);
        $storage->saveDraft(self::PATH, 'ana', 1, false, 'a');

        // A child page cannot be named like a draft file
        foreach (['docs:note:autosave.ana.json', 'docs:autosave.x'] as $path) {
            try {
                $storage->create($path, ['title' => 'X', 'visibility' => 'private'], 'x', 'ana');
                self::fail($path . ' must be refused');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['docs:note'], iterator_to_array($storage->allPaths(), false));
        self::assertSame([], $storage->strayPaths());
    }
}
