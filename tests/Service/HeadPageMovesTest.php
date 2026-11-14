<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use PHPUnit\Framework\TestCase;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\SubtreeMoveException;
use Reporion\Index\Sqlite;
use Reporion\Service\PageMoves;
use Reporion\Storage\FileTree;

/** D61: a head page moves and goes to the trash with the pages under it, or not at all */
final class HeadPageMovesTest extends TestCase
{
    private string $root;
    private FileTree $storage;
    private PageMoves $moves;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/reporion-headmoves-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->storage = new FileTree($this->root, new Sqlite($this->root . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
        $this->moves = new PageMoves($this->storage, new AuditLog($this->root . '/audit'));
        $this->storage->create('wiki:book', ['title' => 'Book'], 'Head.', 'owner');
        $this->storage->create('wiki:book:one', ['title' => 'One'], 'One, see [two](wiki:book:part:two).', 'owner');
        $this->storage->create('wiki:book:part:two', ['title' => 'Two'], 'Two.', 'owner');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheSubtreeMovesWithItsHead(): void
    {
        $this->moves->move('wiki:book', 'wiki:tome', 'owner');

        self::assertSame('Two.', trim($this->storage->read('wiki:tome:part:two')->body));
        self::assertStringContainsString('wiki:tome:part:two', $this->storage->read('wiki:tome:one')->body, 'links follow');
        $this->expectException(PageNotFoundException::class);
        $this->storage->read('wiki:book:one');
    }

    public function testATakenPathRefusesTheWholeMove(): void
    {
        $this->storage->create('wiki:tome:one', ['title' => 'Other'], 'Other.', 'owner');

        try {
            $this->moves->move('wiki:book', 'wiki:tome', 'owner');
            self::fail('refused');
        } catch (SubtreeMoveException $e) {
            self::assertSame('taken', $e->reason);
        }
        self::assertSame('Head.', trim($this->storage->read('wiki:book')->body), 'nothing moved');
        self::assertSame('Two.', trim($this->storage->read('wiki:book:part:two')->body));
    }

    public function testASignedPageUnderItRefusesMoveAndDelete(): void
    {
        $this->storage->create('wiki:book:signed', ['title' => 'S'], 'S.', 'owner');
        $this->storage->sign('wiki:book:signed', 'owner', []);

        foreach ([fn () => $this->moves->move('wiki:book', 'wiki:tome', 'owner'), fn () => $this->moves->trash('wiki:book', 'owner')] as $try) {
            try {
                $try();
                self::fail('refused');
            } catch (SubtreeMoveException) {
            }
        }
        self::assertSame('Head.', trim($this->storage->read('wiki:book')->body));
    }

    public function testTrashTakesTheSubtree(): void
    {
        $result = $this->moves->trash('wiki:book', 'owner');

        self::assertSame(2, $result['along']);
        self::assertCount(3, $this->storage->trash());
    }

    public function testReportsTreeKeepsPerPageMoves(): void
    {
        $this->storage->create('reports:mri:site', ['title' => 'Site'], 'Site.', 'owner');
        $this->storage->create('reports:mri:site:note', ['title' => 'Note'], 'Note.', 'owner');

        $this->moves->move('reports:mri:site', 'reports:mri:place', 'owner');

        self::assertSame('Note.', trim($this->storage->read('reports:mri:site:note')->body));
    }
}
