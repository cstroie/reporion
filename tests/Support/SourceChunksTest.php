<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SourceChunks;

final class SourceChunksTest extends TestCase
{
    public function testATextThatFitsIsOnePartAndNothingIsNone(): void
    {
        self::assertSame(['A short text.'], SourceChunks::split("  A short text.\n", 100));
        self::assertSame([], SourceChunks::split("  \n", 100));
    }

    public function testALongTextIsCutAtItsHeadingsAndNothingExceedsTheBudget(): void
    {
        $section = static fn (string $name): string => "## {$name}\n\n" . str_repeat("{$name} sentence number one. ", 12) . "\n\n" . str_repeat("{$name} another paragraph here. ", 10);
        $text = "# Title\n\nIntro text.\n\n" . $section('Methods') . "\n\n" . $section('Results') . "\n\n" . $section('Discussion');

        $parts = SourceChunks::split($text, 900);

        self::assertGreaterThan(1, \count($parts));
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(900, mb_strlen($part));
        }
        self::assertSame(trim((string) preg_replace('/\s+/', ' ', $text)), trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts))), 'every word is in some part, in order');
        self::assertStringStartsWith('## Results', implode("\n", array_filter($parts, static fn (string $p): bool => str_starts_with($p, '## Results'))) ?: 'none', 'a section starts a part when it does not fit the one before');
    }

    public function testAHugeParagraphIsCutAtSentencesThenAnywhere(): void
    {
        $paragraph = str_repeat('A sentence of some length. ', 100);
        foreach (SourceChunks::split($paragraph, 300) as $part) {
            self::assertLessThanOrEqual(300, mb_strlen($part));
        }
        $blob = str_repeat('x', 1000);
        self::assertSame([str_repeat('x', 400), str_repeat('x', 400), str_repeat('x', 200)], SourceChunks::split($blob, 400));
    }

    public function testEachLaterPartOpensWithThePageMarkerInForce(): void
    {
        $text = "*— p. 1 —*\n\n" . str_repeat("Page one text. ", 20) . "\n\n*— p. 2 —*\n\n" . str_repeat("Page two text. ", 20) . "\n\n" . str_repeat("Still page two. ", 20);

        $parts = SourceChunks::split($text, 400);

        self::assertGreaterThan(2, \count($parts));
        self::assertStringStartsWith('*— p. 1 —*', $parts[0]);
        foreach (\array_slice($parts, 1) as $part) {
            self::assertMatchesRegularExpression('/\A\*— p\. [12] —\*/u', $part, 'a part without its own marker gets the one in force');
        }
        $last = $parts[\count($parts) - 1];
        self::assertStringStartsWith('*— p. 2 —*', $last);
    }

    public function testTheReferenceListTheLibraryKeepsAtTheEndIsLeftOut(): void
    {
        $body = "Text.\n\n## Results\n\nMore.\n\n## References\n\n```text\n1. Nobody A. A paper.\n```\n";

        self::assertSame("Text.\n\n## Results\n\nMore.", SourceChunks::withoutReferences($body));
        self::assertSame("Text.\n\n## References\n\nOur own heading.", SourceChunks::withoutReferences("Text.\n\n## References\n\nOur own heading."), 'only the fenced list the library writes');
    }

    public function testTheBudgetIsTheContextLessTheReserveInCharacters(): void
    {
        self::assertSame((32000 - 3000) * 3, SourceChunks::budget(32000));
        self::assertSame(2000, SourceChunks::budget(100));
    }

    public function testThePageOfAnOffset(): void
    {
        $text = "*— p. 3 —*\nabc *— p. 4 —*\ndef";

        self::assertNull(SourceChunks::pageAt($text, 0));
        self::assertSame(3, SourceChunks::pageAt($text, 15));
        self::assertSame(4, SourceChunks::pageAt($text, \strlen($text)));
    }
}
