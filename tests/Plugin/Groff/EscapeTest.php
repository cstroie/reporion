<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Plugin\Groff;

use PHPUnit\Framework\TestCase;
use Reporion\Plugin\Groff\Ms\Escape;
use Reporion\Plugin\Loader;

/** What troff reads in a line of text, made harmless. */
final class EscapeTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::registerAutoload(\dirname(__DIR__, 3) . '/plugins', 'groff');
    }

    public function testTheBackslashAndTheGraveAccentAreLiteral(): void
    {
        self::assertSame('a\\eb \\e\\e c\\(gad', Escape::text('a\\b \\\\ c`d'));
        self::assertSame('x\\e(bu y\\e*[AU]', Escape::text('x\\(bu y\\*[AU]'), 'no escape typed by the text reaches troff');
    }

    public function testAMinusIsAMinusSignButAHyphenIsNot(): void
    {
        self::assertSame('\\-5 mm, 10-12 cm, bi-parietal, T1-ponderat, 2026-09-23', Escape::text('-5 mm, 10-12 cm, bi-parietal, T1-ponderat, 2026-09-23'));
    }

    public function testControlCharactersBecomeSpacesAndSpacesCollapse(): void
    {
        self::assertSame('a b c', Escape::text("a\x00b\x1b  \t c"));
        self::assertSame('ăîșț', Escape::text('ăîșț'), 'UTF-8 passes: groff reads it with -K utf-8');
    }

    public function testALineThatWouldBeARequestIsShielded(): void
    {
        self::assertSame('\\&.so /etc/passwd', Escape::line('.so /etc/passwd'));
        self::assertSame("\\&'cmd", Escape::line("'cmd"));
        self::assertSame('plain', Escape::line('   plain'), 'leading blanks go: they would break the line');
        self::assertSame('a.b', Escape::line('a.b'));
        self::assertSame('', Escape::line(''));
    }

    public function testALiteralLineKeepsItsSpacesAndShieldsItsStart(): void
    {
        self::assertSame('  a\\eb \\-c', Escape::literal('  a\\b -c'));
        self::assertSame('\\&.x', Escape::literal('.x'));
        self::assertSame('        tab', Escape::literal("\ttab"));
        self::assertSame('', Escape::literal(''));
    }

    public function testACommentCannotCarryARequest(): void
    {
        self::assertSame('.\\" a b', Escape::comment("a\nb"));
        self::assertSame('.\\" x y', Escape::comment('x\\y'), 'a backslash is dropped');
    }
}
