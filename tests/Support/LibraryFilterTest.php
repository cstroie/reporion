<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Auth\SavedViews;
use Reporion\Support\LibraryFilter;

final class LibraryFilterTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function entries(): array
    {
        return [
            ['key' => 'a', 'year' => 2019, 'journal' => 'Radiology', 'ext' => 'pdf', 'cited' => 2, 'notice' => null],
            ['key' => 'b', 'year' => 2021, 'journal' => 'European Radiology', 'ext' => '', 'cited' => 0, 'notice' => 'retracted'],
            ['key' => 'c', 'year' => 2023, 'journal' => 'Lancet', 'ext' => 'pdf', 'cited' => 0, 'notice' => 'corrected'],
            ['key' => 'd', 'year' => null, 'journal' => '', 'ext' => '', 'cited' => 0, 'notice' => null],
        ];
    }

    /** @param array<string, string> $query */
    private static function keys(array $query): array
    {
        return array_column(LibraryFilter::apply(self::entries(), LibraryFilter::of($query)), 'key');
    }

    public function testNothingAskedKeepsEveryEntry(): void
    {
        self::assertSame(['a', 'b', 'c', 'd'], self::keys([]));
        self::assertSame(['a', 'b', 'c', 'd'], self::keys(['year' => 'soon', 'file' => 'maybe', 'notice' => 'x', 'uncited' => '0', 'journal' => '']), 'what it cannot read is no filter');
    }

    public function testYearsAreOneARangeOrOpenEnded(): void
    {
        self::assertSame(['b'], self::keys(['year' => '2021']));
        self::assertSame(['a', 'b'], self::keys(['year' => '2018-2021']));
        self::assertSame(['b', 'c'], self::keys(['year' => '2020-']));
        self::assertSame(['a'], self::keys(['year' => '-2020']));
        self::assertSame(['year' => '2020-'], LibraryFilter::of(['year' => ' 2020- ']));
        self::assertSame([], LibraryFilter::of(['year' => '-']));
    }

    public function testJournalFileNoticeAndUncited(): void
    {
        self::assertSame(['a', 'b'], self::keys(['journal' => 'radiology']));
        self::assertSame(['a', 'c'], self::keys(['file' => 'yes']));
        self::assertSame(['b', 'd'], self::keys(['file' => 'no']));
        self::assertSame(['b', 'c'], self::keys(['notice' => 'any']));
        self::assertSame(['b'], self::keys(['notice' => 'retracted']));
        self::assertSame(['b', 'c', 'd'], self::keys(['uncited' => '1']));
        self::assertSame(['b'], self::keys(['uncited' => '1', 'notice' => 'any', 'file' => 'no', 'year' => '2020-2022', 'journal' => 'euro']), 'all of them together');
    }

    public function testACollectionKeepsLibraryParametersAndNothingElse(): void
    {
        $view = SavedViews::normalize('Retracted', 'tag=mri&notice=retracted&year=2020-&sort=bogus&ns=reports&page=2&evil=1&file=maybe', SavedViews::LIBRARY);

        self::assertSame(['name' => 'Retracted', 'query' => 'tag=mri&year=2020-&notice=retracted', 'kind' => 'library'], $view);
        self::assertNull(SavedViews::normalize('Nothing', 'page=2&ns=reports', SavedViews::LIBRARY), 'no library parameter in it');
        self::assertSame(['name' => 'Search', 'query' => 'q=x&ns=reports'], SavedViews::normalize('Search', 'q=x&ns=reports&notice=any'), 'a search view is as before');
        self::assertSame([$view], SavedViews::clean([$view, ['name' => 'Retracted', 'query' => 'q=other', 'kind' => 'library'], ['name' => 'Bad', 'query' => 'page=1', 'kind' => 'library']]));
    }
}
