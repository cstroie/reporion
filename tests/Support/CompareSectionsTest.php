<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\CompareSections;

final class CompareSectionsTest extends TestCase
{
    public function testSectionsMeetByFirstWordWhateverTheExamIsCalled(): void
    {
        $rows = CompareSections::align(
            '<h2>IRM cerebral</h2><h3>Descriere</h3><p>a</p><h3>Concluzii</h3><p>b</p>',
            '<h2>IRM cerebral nativ</h2><h3>DESCRIERE:</h3><p>c</p><h3>Concluzie</h3><p>d</p>',
        );

        self::assertCount(3, $rows);
        self::assertSame(['<h2>IRM cerebral</h2>', '<h2>IRM cerebral nativ</h2>'], $rows[0]);
        self::assertStringContainsString('<p>a</p>', $rows[1][0]);
        self::assertStringContainsString('<p>c</p>', $rows[1][1]);
        self::assertStringContainsString('<p>b</p>', $rows[2][0]);
        self::assertStringContainsString('<p>d</p>', $rows[2][1]);
    }

    public function testAnOlderOnlySectionComesAfterTheOneItFollowed(): void
    {
        $rows = CompareSections::align(
            '<h3>Descriere</h3><p>a</p><h3>Concluzii</h3><p>b</p>',
            '<h3>Tehnică</h3><p>t</p><h3>Descriere</h3><p>c</p><h3>Concluzii</h3><p>d</p><h3>Recomandări</h3><p>r</p>',
        );

        self::assertSame(['', '<h3>Tehnică</h3><p>t</p>'], $rows[0], 'before everything: it led the older report');
        self::assertStringContainsString('<p>a</p>', $rows[1][0]);
        self::assertSame(['', '<h3>Recomandări</h3><p>r</p>'], $rows[3]);
    }

    public function testExamsPairByOrdinalAndASharedConclusionIsASection(): void
    {
        $rows = CompareSections::align(
            '<h2>RM coloană cervicală</h2><h3>Descriere</h3><p>1</p><h2>RM coloană lombară</h2><h3>Descriere</h3><p>2</p><h2>Concluzii</h2><p>c</p>',
            '<h2>RM cervical</h2><h3>Descriere</h3><p>3</p><h2>Concluzii</h2><p>d</p>',
        );

        $pairs = array_map(static fn (array $r): array => [strip_tags($r[0]), strip_tags($r[1])], $rows);
        self::assertSame([
            ['RM coloană cervicală', 'RM cervical'],
            ['Descriere1', 'Descriere3'],
            ['RM coloană lombară', ''],
            ['Descriere2', ''],
            ['Concluziic', 'Concluziid'],
        ], $pairs);
    }

    public function testPreambleAndEmptyInput(): void
    {
        self::assertSame([['<p>Indicație: control.</p>', '']], CompareSections::align('<p>Indicație: control.</p>', ''));
        self::assertSame([], CompareSections::align('', ''));
    }
}
