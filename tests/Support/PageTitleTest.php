<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\PageTitle;

/**
 * The frontmatter title is an override; the first heading is the title
 * otherwise (2026-10-13), and a heading edited later moves it.
 */
final class PageTitleTest extends TestCase
{
    public function testTheFirstHeadingIsTheTitleWhenThereIsNone(): void
    {
        self::assertSame('Ecografie', PageTitle::of([], "# Ecografie\n"));
        self::assertSame('Ecografie', PageTitle::of(['title' => '  '], "# Ecografie\n"));
        self::assertSame('Ecografie 2', PageTitle::of([], "# Ecografie 2\n"), 'it follows the heading');
    }

    public function testATitleSetIsAnOverrideAndKept(): void
    {
        self::assertSame('Mine', PageTitle::of(['title' => 'Mine'], "# Other\n"));
    }

    public function testNoHeadingMeansNoTitle(): void
    {
        self::assertSame('', PageTitle::of([], "## Only two\n"));
    }

    public function testATextPageHasNoHeadings(): void
    {
        self::assertSame('', PageTitle::of(['format' => 'text'], "# Looks like a heading\n"));
        self::assertSame('Set', PageTitle::of(['format' => 'text', 'title' => 'Set'], "# Looks like a heading\n"));
    }
}
