<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Library\SourceAsk;
use Reporion\Support\SpellingVariants;

final class SpellingVariantsTest extends TestCase
{
    public function testEachSpellingFindsTheOther(): void
    {
        self::assertSame('paediatric', SpellingVariants::other('pediatric'));
        self::assertSame('pediatric', SpellingVariants::other('paediatric'));
        self::assertSame('haemorrhage', SpellingVariants::other('hemorrhage'));
        self::assertSame('oedema', SpellingVariants::other('edema'));
        self::assertSame('ischemia', SpellingVariants::other('ischaemia'));
    }

    public function testOnlyTheStartOfAListedWordIsChanged(): void
    {
        self::assertNull(SpellingVariants::other('hemisphere'));
        self::assertNull(SpellingVariants::other('chemotherapy'));
        self::assertNull(SpellingVariants::other('head'));
    }

    public function testWidenKeepsTheOrderAndAddsNoDuplicate(): void
    {
        self::assertSame(['pediatric', 'paediatric', 'head'], SpellingVariants::widen(['pediatric', 'head', 'paediatric']));
    }

    public function testALibraryQuestionKeepsItsShortWords(): void
    {
        self::assertSame(['bands', 'pediatric', 'paediatric', 'head', 'exams', 'age', 'ct'], SourceAsk::terms('What are the age bands for pediatric head CT exams?'));
        self::assertSame(['doza', 'drl', 'rmn'], SourceAsk::terms('Ce DRL are doza la RMN?'), 'Romanian function words left out');
    }
}
