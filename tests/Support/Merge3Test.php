<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Merge3;

final class Merge3Test extends TestCase
{
    public function testUnchangedSidesGiveTheBase(): void
    {
        self::assertSame(['text' => "a\nb\n", 'conflicts' => 0], Merge3::merge("a\nb\n", "a\nb\n", "a\nb\n"));
    }

    public function testAChangeOnOneSideIsTaken(): void
    {
        self::assertSame("a\nB\nc\n", Merge3::merge("a\nb\nc\n", "a\nb\nc\n", "a\nB\nc\n")['text']);
        self::assertSame("a\nB\nc\n", Merge3::merge("a\nb\nc\n", "a\nB\nc\n", "a\nb\nc\n")['text']);
    }

    public function testChangesOnSeparateLinesAreBothKept(): void
    {
        $result = Merge3::merge("a\nb\nc\nd\ne\n", "A\nb\nc\nd\ne\n", "a\nb\nc\nd\nE\n");

        self::assertSame(['text' => "A\nb\nc\nd\nE\n", 'conflicts' => 0], $result);
    }

    public function testAdditionsAtBothEndsAreBothKept(): void
    {
        $result = Merge3::merge("a\nb\n", "top\na\nb\n", "a\nb\nbottom\n");

        self::assertSame(['text' => "top\na\nb\nbottom\n", 'conflicts' => 0], $result);
    }

    public function testTheSameChangeOnBothSidesIsTakenOnce(): void
    {
        self::assertSame(['text' => "a\nX\nc\n", 'conflicts' => 0], Merge3::merge("a\nb\nc\n", "a\nX\nc\n", "a\nX\nc\n"));
    }

    public function testOverlappingChangesAreAConflictWithMarkers(): void
    {
        $result = Merge3::merge("a\nb\nc\n", "a\nserver\nc\n", "a\ndevice\nc\n");

        self::assertSame(1, $result['conflicts']);
        self::assertSame("a\n<<<<<<< server\nserver\n=======\ndevice\n>>>>>>> this device\nc\n", $result['text']);
        self::assertTrue(Merge3::hasMarkers($result['text']));
    }

    public function testDeleteAgainstEditIsAConflict(): void
    {
        $result = Merge3::merge("a\nb\nc\n", "a\nc\n", "a\nB\nc\n");

        self::assertSame(1, $result['conflicts']);
        self::assertSame("a\n<<<<<<< server\n=======\nB\n>>>>>>> this device\nc\n", $result['text']);
    }

    public function testDeleteOnOneSideIsTaken(): void
    {
        self::assertSame("a\nc\n", Merge3::merge("a\nb\nc\n", "a\nc\n", "a\nb\nc\n")['text']);
    }

    public function testEmptyBaseWithDifferentTextsIsOneConflict(): void
    {
        $result = Merge3::merge('', "one\n", "two\n");

        self::assertSame(1, $result['conflicts']);
        self::assertSame("<<<<<<< server\none\n=======\ntwo\n>>>>>>> this device\n", $result['text']);
    }

    public function testLineEndingsAndTheFinalNewlineDoNotCountAsChanges(): void
    {
        $result = Merge3::merge("a\nb\n", "a\nB\n", "a\r\nb");

        self::assertSame(['text' => "a\nB\n", 'conflicts' => 0], $result);
    }

    public function testEverythingEmptiedIsEmpty(): void
    {
        self::assertSame(['text' => '', 'conflicts' => 0], Merge3::merge("a\n", '', "a\n"));
    }

    public function testTwoConflictsAreCountedApart(): void
    {
        $result = Merge3::merge("a\nb\nc\nd\ne\n", "1\nb\nc\nd\n5\n", "x\nb\nc\nd\ny\n");

        self::assertSame(2, $result['conflicts']);
    }

    public function testTextPastTheDiffEditLimitIsOneConflictNotACrash(): void
    {
        $base = implode("\n", array_map(static fn (int $i): string => "base {$i}", range(1, 2000))) . "\n";
        $ours = implode("\n", array_map(static fn (int $i): string => "ours {$i}", range(1, 2000))) . "\n";
        $theirs = implode("\n", array_map(static fn (int $i): string => "theirs {$i}", range(1, 2000))) . "\n";

        $result = Merge3::merge($base, $ours, $theirs);

        self::assertSame(1, $result['conflicts']);
        self::assertStringStartsWith("<<<<<<< server\nours 1\n", $result['text']);
    }

    public function testCustomLabels(): void
    {
        $result = Merge3::merge("a\n", "b\n", "c\n", 'rev 3', 'mine');

        self::assertSame("<<<<<<< rev 3\nb\n=======\nc\n>>>>>>> mine\n", $result['text']);
    }
}
