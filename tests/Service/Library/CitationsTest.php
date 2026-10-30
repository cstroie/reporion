<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Index\Sqlite;
use Reporion\Service\Library\Citations;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** The library as Render's citation source; the records are made up */
final class CitationsTest extends StorageTestCase
{
    private Citations $citations;

    protected function setUp(): void
    {
        parent::setUp();
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $storage = new FileTree($this->dataRoot, $index);
        $storage->create('library:abel2020', ['title' => 'Another', 'visibility' => 'private', 'csl' => ['type' => 'article-journal', 'title' => 'Another', 'issued' => ['date-parts' => [[2020]]]]], 'Text.', 'tester');
        $storage->create('library:notasource', ['title' => 'Plain page', 'visibility' => 'private'], 'Text.', 'tester');
        $this->citations = new Citations($storage, 'library');
    }

    public function testAKeyIsASourcePageAndGivesItsRecordOnly(): void
    {
        self::assertSame('Another', $this->citations->record('abel2020')['title']);
        self::assertSame('library:abel2020', $this->citations->path('abel2020'));
    }

    public function testNoPageNoSourceNotAKeyAreNoRecord(): void
    {
        self::assertNull($this->citations->record('missing2020'));
        self::assertNull($this->citations->record('notasource'));
        self::assertNull($this->citations->record('../x'));
        self::assertNull($this->citations->record('Bad Key'));
    }
}
