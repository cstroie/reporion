<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Service\PageMoves;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** Renaming a library page re-cites the pages that cite it (docs/library.md) */
final class PageMovesCitationsTest extends StorageTestCase
{
    public function testRenamingASourceRewritesItsCitationsInUnsignedPagesOnly(): void
    {
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $storage = new FileTree($this->dataRoot, $index);
        $csl = ['title' => 'T', 'issued' => ['date-parts' => [[2020]]]];
        $storage->create('library:old2020', ['title' => 'T', 'visibility' => 'private', 'csl' => $csl], 'Text.', 'u');
        $storage->create('library:other2020', ['title' => 'O', 'visibility' => 'private', 'csl' => $csl], 'Text.', 'u');
        $storage->create('wiki:note', ['title' => 'N', 'visibility' => 'private'], "A [@old2020] and [@old2020, p. 2] and [@other2020; @old2020, ch. 3] and [@old2020x] and `x` old2020 @old2020.\n", 'u');

        $result = (new PageMoves($storage, new AuditLog($this->dataRoot . '/audit')))->move('library:old2020', 'library:new2020', 'u');

        self::assertCount(1, $result['fixed']);
        self::assertSame(
            "A [@new2020] and [@new2020, p. 2] and [@other2020; @new2020, ch. 3] and [@old2020x] and `x` old2020 @old2020.\n",
            $storage->read('wiki:note')->body,
            'only inside a citation, and only the whole key',
        );
    }

    public function testNoRenamedKeysLeavesTheBodyAndARenamedOneIsRewritten(): void
    {
        $body = 'A [@old2020].';
        self::assertSame($body, PageMoves::rewriteCitations($body, []));
        self::assertSame('A [@new2020].', PageMoves::rewriteCitations($body, ['old2020' => 'new2020']));
    }
}
