<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\HeadPage;

final class HeadPageTest extends TestCase
{
    public function testHeadOfANamespaceIsThePageOfItsName(): void
    {
        self::assertSame('a:b', HeadPage::headOf('a:b'));
        self::assertSame('a:b', HeadPage::headOf('a:b:'));
        self::assertNull(HeadPage::headOf(''));
        self::assertSame('a:b', HeadPage::namespaceOf('a:b'));
    }

    public function testDescriptionPathsPutTheHeadBeforeTheLegacyIndex(): void
    {
        self::assertSame(['a:b', 'a:b:_index'], HeadPage::descriptionPaths('a:b'));
        self::assertSame(['_index'], HeadPage::descriptionPaths(''));
        self::assertTrue(HeadPage::isLegacy('a:_index'));
        self::assertTrue(HeadPage::isLegacy('_index'));
        self::assertFalse(HeadPage::isLegacy('a:my_index'));
    }

    public function testReportsAreLeftOut(): void
    {
        self::assertTrue(HeadPage::applies('wiki:mri'));
        self::assertFalse(HeadPage::applies('reports'));
        self::assertFalse(HeadPage::applies('reports:mri'));
        self::assertTrue(HeadPage::applies('reportsx'));
        self::assertFalse(HeadPage::applies(''));
    }

    public function testChildrenModeDefaultsToList(): void
    {
        self::assertSame('list', HeadPage::childrenMode(null));
        self::assertSame('list', HeadPage::childrenMode(['children' => 'bogus']));
        self::assertSame('chapters', HeadPage::childrenMode(['children' => ' chapters ']));
        self::assertSame('toc', HeadPage::childrenMode('{"children":"toc"}'));
        self::assertSame('none', HeadPage::childrenMode(['children' => 'none']));
    }
}
