<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Http\View;
use Reporion\Support\Feeds;

/**
 * The feeds a page's <head> advertises (Support\Feeds, partials/head-assets.php):
 * the whole-site feed, and the one covering the page's namespace — never for
 * `reports`, and never a link to a feed the controller would answer 404 for.
 */
final class FeedsTest extends TestCase
{
    public function testCleanDropsReportsEmptiesAndDuplicates(): void
    {
        self::assertSame(
            ['docs', 'teaching:mri'],
            Feeds::clean([' docs ', 'reports', 'reports:mri', '', 'docs', 'teaching:mri:', ':']),
        );
    }

    public function testCoveringIsTheNearestConfiguredAncestorOrSelf(): void
    {
        $namespaces = ['docs', 'teaching:mri'];

        self::assertSame('docs', Feeds::covering($namespaces, 'docs'));
        self::assertSame('docs', Feeds::covering($namespaces, 'docs:guides:deep'));
        self::assertSame('teaching:mri', Feeds::covering($namespaces, 'teaching:mri:case'));
        self::assertNull(Feeds::covering($namespaces, 'teaching'), 'a parent of a feed has none of its own');
        self::assertNull(Feeds::covering($namespaces, ''));
        self::assertNull(Feeds::covering([], 'docs'));
    }

    public function testAlternatesAreTheSiteFeedAndTheCoveringOne(): void
    {
        self::assertSame([], Feeds::alternates([], 'docs', '/r'), 'no feeds configured, nothing advertised');

        $links = Feeds::alternates(['docs'], 'docs:guides', '/r');
        self::assertSame(['/r/feed.atom', '/r/feed/docs.atom'], array_column($links, 'href'));

        $site = Feeds::alternates(['docs'], '', '/r');
        self::assertSame(['/r/feed.atom'], array_column($site, 'href'), 'a page outside every feed advertises the site feed only');
    }

    public function testTheHeadPrintsEachFeedAsAnAlternateLink(): void
    {
        $html = View::render(
            \dirname(__DIR__, 2) . '/templates/partials/head-assets.php',
            ['basePath' => '/r', 'feedLinks' => Feeds::alternates(['docs'], 'docs', '/r')],
        );

        self::assertSame(2, substr_count($html, 'rel="alternate" type="application/atom+xml"'));
        self::assertStringContainsString('href="/r/feed.atom"', $html);
        self::assertStringContainsString('href="/r/feed/docs.atom"', $html);
    }

    public function testTheHeadPrintsNothingWithoutFeeds(): void
    {
        $html = View::render(\dirname(__DIR__, 2) . '/templates/partials/head-assets.php', ['basePath' => '/r']);

        self::assertStringNotContainsString('application/atom+xml', $html);
    }
}
