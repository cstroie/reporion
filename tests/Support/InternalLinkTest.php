<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\InternalLink;

final class InternalLinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function hrefs(): iterable
    {
        yield 'colon path' => ['reports:mri:a', '/app/reports:mri:a'];
        yield 'slash-prefixed colon path' => ['/reports:mri:a', '/app/reports:mri:a'];
        yield 'with fragment' => ['reports:mri:a#concluzie', '/app/reports:mri:a#concluzie'];
        yield 'imported slash form' => ['reports/mri/a', '/app/reports:mri:a'];
        yield 'imported mixed case' => ['Site/Home', '/app/site:home'];
        yield 'https' => ['https://example.org/a:b', null];
        yield 'tel looks like a path' => ['tel:0722000000', null];
        yield 'mailto' => ['mailto:a@example.org', null];
        yield 'media is not a page' => ['media:abc.png', null];
        yield 'media slash form' => ['media/scan', null];
        yield 'bare word' => ['home', null];
        yield 'file' => ['scan.pdf', null];
        yield 'dot-relative' => ['./x/y', null];
        yield 'query string' => ['ns:page?x=1', null];
        yield 'host with port' => ['example.org:8080', null];
    }

    /**
     * @dataProvider hrefs
     */
    public function testHref(string $url, ?string $expected): void
    {
        self::assertSame($expected, InternalLink::href($url, '/app'));
    }

    public function testExtractListsEachLinkedPageOnce(): void
    {
        $body = "See [a](reports:mri:a) and [a again](/reports:mri:a#x), [b](<site/home>),\n"
            . "[web](https://example.org) and ![img](media/x).\n";

        self::assertSame(['reports:mri:a', 'site:home'], InternalLink::extract($body));
    }
}
