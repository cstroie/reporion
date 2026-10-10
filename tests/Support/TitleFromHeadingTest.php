<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TitleFromHeading;

/**
 * A page saved with no title takes its first `# ` heading (2026-10-10):
 * a blank title only, a markdown page only, never a heading inside code.
 */
final class TitleFromHeadingTest extends TestCase
{
    public function testABlankTitleTakesTheFirstLevelOneHeading(): void
    {
        $body = "Intro line\n\n## Not this\n\n# Ecografie abdominală #\n\n# Second\n";

        self::assertSame('Ecografie abdominală', TitleFromHeading::fill([], $body)['title']);
        self::assertSame('Ecografie abdominală', TitleFromHeading::fill(['title' => '  '], $body)['title']);
    }

    public function testATitleAlreadySetIsKept(): void
    {
        self::assertSame(['title' => 'Mine'], TitleFromHeading::fill(['title' => 'Mine'], "# Other\n"));
    }

    public function testNoLevelOneHeadingLeavesTheTitleUnset(): void
    {
        self::assertSame([], TitleFromHeading::fill([], "## Only two\n\n#hashtag, not a heading\n\n#\n"));
    }

    public function testAHeadingInsideFencedCodeIsNotOne(): void
    {
        $body = "```\n# a shell comment\n```\n\n~~~~\n# also code\n~~~\n~~~~\n\n# Real\n";

        self::assertSame('Real', TitleFromHeading::first($body));
    }

    public function testATextPageIsLeftAlone(): void
    {
        self::assertSame(['format' => 'text'], TitleFromHeading::fill(['format' => 'text'], "# Looks like a heading\n"));
    }
}
