<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\CompareSections;

final class CompareSectionsTest extends TestCase
{
    public function testSectionsMeetByNameThenByFirstWord(): void
    {
        $aligned = CompareSections::align(
            '<h2>IRM cerebral</h2><h3>Descriere</h3><p>a</p><h3>Concluzii</h3><p>b</p>',
            '<h2>IRM cerebral nativ</h2><h3>DESCRIERE:</h3><p>c</p><h3>Concluzie</h3><p>d</p>',
        );

        self::assertSame([
            ['IRM cerebral', 'IRM cerebral nativ'],
            ['Descrierea', 'DESCRIERE:c'],
            ['Concluziib', 'Concluzied'],
        ], self::text($aligned['rows']));
        self::assertFalse($aligned['reordered']);
    }

    public function testAnOlderOnlySectionComesAfterTheOneItFollowed(): void
    {
        $aligned = CompareSections::align(
            '<h3>Descriere</h3><p>a</p><h3>Concluzii</h3><p>b</p>',
            '<h3>Tehnică</h3><p>t</p><h3>Descriere</h3><p>c</p><h3>Concluzii</h3><p>d</p><h3>Recomandări</h3><p>r</p>',
        );

        self::assertSame([
            ['', 'Tehnicăt'],
            ['Descrierea', 'Descrierec'],
            ['Concluziib', 'Concluziid'],
            ['', 'Recomandărir'],
        ], self::text($aligned['rows']));
        self::assertFalse($aligned['reordered'], 'nothing moved: one side only lacks some');
    }

    public function testThePriorsSectionsAreMovedToThisReportsOrderAndItIsSaid(): void
    {
        $aligned = CompareSections::align(
            '<h3>Descriere</h3><p>a</p><h3>Concluzii</h3><p>b</p>',
            '<h3>Concluzii</h3><p>d</p><h3>Descriere</h3><p>c</p>',
        );

        self::assertSame([['Descrierea', 'Descrierec'], ['Concluziib', 'Concluziid']], self::text($aligned['rows']));
        self::assertTrue($aligned['reordered']);
    }

    public function testExamsPairByNameWhateverTheirOrderOrCase(): void
    {
        $aligned = CompareSections::align(
            '<h2>CT cerebral</h2><h3>Descriere</h3><p>1</p><h2>CT torace</h2><h3>Descriere</h3><p>2</p>',
            '<h2>CT Torace:</h2><h3>Descriere</h3><p>3</p><h2>CT Cerebral</h2><h3>Descriere</h3><p>4</p>',
        );

        self::assertSame([
            ['CT cerebral', 'CT Cerebral'],
            ['Descriere1', 'Descriere4'],
            ['CT torace', 'CT Torace:'],
            ['Descriere2', 'Descriere3'],
        ], self::text($aligned['rows']));
        self::assertTrue($aligned['reordered']);
    }

    public function testUnnamedExamsPairByPositionAndASharedConclusionIsItsOwnGroup(): void
    {
        $aligned = CompareSections::align(
            '<h2>RM coloană cervicală</h2><h3>Descriere</h3><p>1</p><h2>RM coloană lombară</h2><h3>Descriere</h3><p>2</p><h2>Concluzii</h2><p>c</p>',
            '<h2>RM cervical</h2><h3>Descriere</h3><p>3</p><h2>Concluzii</h2><p>d</p>',
        );

        self::assertSame([
            ['RM coloană cervicală', 'RM cervical'],
            ['Descriere1', 'Descriere3'],
            ['RM coloană lombară', ''],
            ['Descriere2', ''],
            ['Concluziic', 'Concluziid'],
        ], self::text($aligned['rows']));
        self::assertFalse($aligned['reordered']);
    }

    public function testPreambleAndEmptyInput(): void
    {
        self::assertSame([['<p>Indicație: control.</p>', '']], CompareSections::align('<p>Indicație: control.</p>', '')['rows']);
        self::assertSame(['rows' => [], 'reordered' => false], CompareSections::align('', ''));
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function text(array $rows): array
    {
        return array_map(static fn (array $r): array => [strip_tags($r[0]), strip_tags($r[1])], $rows);
    }
}
