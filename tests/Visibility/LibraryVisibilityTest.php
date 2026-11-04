<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Visibility;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\Library\OnlineSearch;
use Reporion\Service\Library\Related;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * CLAUDE.md Testing: "Add a case here before adding any new listing endpoint." The library's
 * listings of 2026-11-04 (D60): possible duplicates, related sources, the `in_library` mark of an
 * online search, and the co-citation query under them. A library source is always private, so the
 * grant on the `library` namespace decides who sees it; a page citing it (`wiki:secret`) is private to
 * its own namespace, and what it cites together must not show to a caller who cannot read it.
 * The sources are made up.
 */
final class LibraryVisibilityTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->library = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        $csl = static fn (string $title, array $more = []): array => ['title' => $title, 'issued' => ['date-parts' => [[2020]]]] + $more;
        foreach (['main2020' => ['A made-up study of test lesions', ['DOI' => '10.9999/x']], 'twin2020' => ['A made-up study of test lesions', ['DOI' => '10.9999/x']], 'co2020' => ['A companion made-up study', []]] as $key => [$title, $more]) {
            $this->storage->create('library:' . $key, ['title' => $title, 'visibility' => 'private', 'csl' => $csl($title, $more), 'tags' => ['mri']], '', 'owner');
        }
        // Cited together only by a private page of another namespace, and by nothing else
        $this->storage->create('wiki:secret', ['title' => 'Secret', 'visibility' => 'private'], "[@main2020] [@co2020]\n", 'owner');
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    private function editorWithGrant(): User
    {
        return new User('editor', 'x', false, [new Grant('library', GrantRole::Editor), new Grant('wiki', GrantRole::Editor)], true, 'now', 'now');
    }

    private function viewerWithLibraryOnly(): User
    {
        return new User('viewer', 'x', false, [new Grant('library', GrantRole::Viewer)], true, 'now', 'now');
    }

    /** Holds a real grant, just not on the library: the case that catches a grant leaking into another namespace */
    private function editorWithoutGrant(): User
    {
        return new User('editor2', 'x', false, [new Grant('wiki', GrantRole::Editor)], true, 'now', 'now');
    }

    private function nobody(): User
    {
        return new User('nobody', 'x', false, [], true, 'now', 'now');
    }

    private function pid(string $key): string
    {
        return $this->storage->read('library:' . $key)->pid;
    }

    public function testPossibleDuplicatesAreListedOnlyToWhoCanReadTheSources(): void
    {
        foreach ([$this->owner(), $this->editorWithGrant(), $this->viewerWithLibraryOnly()] as $reader) {
            $groups = $this->library->duplicateGroups($reader);
            self::assertCount(1, $groups, $reader->username);
            self::assertEqualsCanonicalizing(['main2020', 'twin2020'], array_column($groups[0]['sources'], 'key'), $reader->username);
        }
        foreach ([$this->editorWithoutGrant(), $this->nobody()] as $other) {
            self::assertSame([], $this->library->duplicateGroups($other), $other->username . ': not a title, a count or a key');
        }
    }

    public function testRelatedSourcesAndTheirCitingPagesAreReadUnderTheCallersGrants(): void
    {
        $related = new Related($this->index);

        $owner = $related->of($this->pid('main2020'), $this->owner());
        self::assertContains('cited', array_merge(...array_column($owner, 'why')), 'the owner sees what the private page cites together');
        self::assertContains('co2020', array_column($owner, 'key'));

        // A library reader without the wiki grant: the sources are theirs, the private citing page is not
        $viewer = $related->of($this->pid('main2020'), $this->viewerWithLibraryOnly());
        self::assertNotContains('cited', array_merge(...array_column($viewer, 'why') ?: [[]]), 'a private citing page contributes nothing to a caller who cannot read it');
        self::assertEqualsCanonicalizing(['co2020', 'twin2020'], array_column($viewer, 'key'), 'only through the tags they share');

        foreach ([$this->editorWithoutGrant(), $this->nobody()] as $other) {
            self::assertSame([], $related->of($this->pid('main2020'), $other), $other->username);
        }
    }

    public function testTheCoCitationQueryNeverCountsAPageTheCallerCannotRead(): void
    {
        $main = $this->pid('main2020');

        self::assertSame([$this->pid('co2020')], array_column($this->index->coCited($main, 'library', $this->owner()), 'pid'));
        self::assertSame([$this->pid('co2020')], array_column($this->index->coCited($main, 'library', $this->editorWithGrant()), 'pid'));
        self::assertSame([], $this->index->coCited($main, 'library', $this->viewerWithLibraryOnly()), 'the citing page is private to wiki');
        self::assertSame([], $this->index->coCited($main, 'library', $this->editorWithoutGrant()), 'the cited sources are private to the library');
        self::assertSame([], $this->index->coCited($main, 'library', null), 'anonymous');
    }

    public function testTheInLibraryMarkOfAnOnlineSearchNamesOnlyWhatTheCallerReads(): void
    {
        $lookup = new Lookup(static fn (string $url): ?string => str_contains($url, 'esearch')
            ? '{"esearchresult":{"idlist":["12345678"]}}'
            : '{"result":{"uids":["12345678"],"12345678":{"uid":"12345678","pubdate":"2020","title":"A made-up study of test lesions.","articleids":[{"idtype":"doi","value":"10.9999/x"}]}}}');
        $search = new OnlineSearch($this->library, $lookup);

        foreach ([$this->owner(), $this->editorWithGrant(), $this->viewerWithLibraryOnly()] as $reader) {
            self::assertNotNull($search->find('steatosis', $reader)['results'][0]['in_library'], $reader->username);
        }
        foreach ([$this->editorWithoutGrant(), $this->nobody()] as $other) {
            self::assertNull($search->find('steatosis', $other)['results'][0]['in_library'], $other->username . ': it is not told that the library holds it');
        }
    }
}
