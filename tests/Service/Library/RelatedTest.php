<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Related;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** The sources are made up (docs/library.md, D60) */
final class RelatedTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations', vectorsPath: $this->dataRoot . '/vectors.sqlite');
        $this->storage = new FileTree($this->dataRoot, $this->index);
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    /** @param list<string> $tags */
    private function source(string $key, string $title, array $tags = []): string
    {
        return $this->storage->create('library:' . $key, ['title' => $title, 'visibility' => 'private', 'tags' => $tags, 'csl' => ['title' => $title, 'issued' => ['date-parts' => [[2020]]]]], '', 'owner')->pid;
    }

    private function pid(string $key): string
    {
        return $this->storage->read('library:' . $key)->pid;
    }

    public function testSourcesCitedByTheSamePagesRankFirstThenTheOnesSharingTags(): void
    {
        $this->source('main2020', 'Main study of lesions', ['mri', 'lesions']);
        $this->source('co2020', 'Companion study', []);
        $this->source('co2021', 'Another companion', []);
        $this->source('tag2019', 'Tagged alike', ['mri', 'lesions']);
        $this->source('far2018', 'Unrelated', ['ct']);
        $this->storage->create('wiki:one', ['title' => 'One', 'visibility' => 'private'], "[@main2020] [@co2020] [@co2021]\n", 'owner');
        $this->storage->create('wiki:two', ['title' => 'Two', 'visibility' => 'private'], "[@main2020; @co2020]\n", 'owner');

        $related = (new Related($this->index))->of($this->pid('main2020'), self::owner());

        self::assertSame(['co2020', 'tag2019', 'co2021'], array_column($related, 'key'), 'cited twice first; then the tags and the one shared page tie by rank, the tags ahead; the unrelated is out');
        self::assertSame(['cited', 'tags'], [$related[0]['why'][0], $related[1]['why'][0]]);
        self::assertSame(['cited'], $related[2]['why']);
        self::assertNotContains($this->pid('main2020'), array_column($related, 'pid'));
    }

    public function testSourcesNearInMeaningAreFoundFromThePassageVectors(): void
    {
        $this->source('main2020', 'Main study of lesions');
        $this->source('near2020', 'Near in meaning');
        $this->source('away2020', 'Away');
        $this->index->putPassages($this->pid('main2020'), 'm', [['n' => 0, 'sha' => 'a', 'page' => 1, 'vec' => [1.0, 0.0]], ['n' => 1, 'sha' => 'b', 'page' => 2, 'vec' => [0.0, 1.0]]]);
        $this->index->putPassages($this->pid('near2020'), 'm', [['n' => 0, 'sha' => 'c', 'page' => 1, 'vec' => [0.7071, 0.7071]]]);
        $this->index->putPassages($this->pid('away2020'), 'm', [['n' => 0, 'sha' => 'd', 'page' => 1, 'vec' => [-0.7071, -0.7071]]]);

        $related = (new Related($this->index, minScore: 0.5))->of($this->pid('main2020'), self::owner());

        self::assertSame(['near2020'], array_column($related, 'key'));
        self::assertSame(['meaning'], $related[0]['why']);
    }

    public function testNothingPrivateToTheCallerIsListedOrCounted(): void
    {
        $this->source('main2020', 'Main study of lesions', ['mri']);
        $this->source('secret2020', 'Not for this reader', ['mri']);
        $this->storage->create('wiki:one', ['title' => 'One', 'visibility' => 'private'], "[@main2020] [@secret2020]\n", 'owner');
        $reader = new User('reader', 'x', false, [new Grant('library:main2020', GrantRole::Viewer), new Grant('wiki', GrantRole::Viewer)], true, 'now', 'now');

        self::assertSame(['secret2020'], array_column((new Related($this->index))->of($this->pid('main2020'), self::owner()), 'key'));
        self::assertSame([], (new Related($this->index))->of($this->pid('main2020'), $reader), 'no grant on the other source: it is not there');
        self::assertSame([], (new Related($this->index))->of($this->pid('main2020'), new User('x', 'x', false, [], true, 'now', 'now')));
    }

    public function testASourceNoOneCitesAndNothingSharesHasNoRelated(): void
    {
        $this->source('alone2020', 'Alone');

        self::assertSame([], (new Related($this->index))->of($this->pid('alone2020'), self::owner()));
    }
}
