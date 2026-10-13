<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TitleFromHeading;

/**
 * The first level-1 heading of a body, outside fenced code, setext and
 * hashtags not counted.
 */
final class TitleFromHeadingTest extends TestCase
{
    public function testTheFirstLevelOneHeadingWithItsClosingMarksDropped(): void
    {
        $body = "Intro line\n\n## Not this\n\n# Ecografie abdominală #\n\n# Second\n";

        self::assertSame('Ecografie abdominală', TitleFromHeading::first($body));
    }

    public function testNoLevelOneHeadingIsNull(): void
    {
        self::assertNull(TitleFromHeading::first("## Only two\n\n#hashtag, not a heading\n\n#\n"));
    }

    public function testAHeadingInsideFencedCodeIsNotOne(): void
    {
        $body = "```\n# a shell comment\n```\n\n~~~~\n# also code\n~~~\n~~~~\n\n# Real\n";

        self::assertSame('Real', TitleFromHeading::first($body));
    }
}
