<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Import;

use PHPUnit\Framework\TestCase;
use Reporion\Import\TemplateConverter;

/**
 * DokuWiki report templates → template pages (D19). Made-up template text.
 */
final class TemplateConverterTest extends TestCase
{
    private const REGIONS = ['cap' => ['neuro'], 'articulatii' => ['msk'], 'coloana' => ['spine']];

    public function testOneExamHeadingBecomesTheTitleAndTheBodyIsTheText(): void
    {
        $result = TemplateConverter::convert("====== Articulații: Genunchi ======\n\n===== IRM Genunchi =====\n\nRaporturi articulare **normale**.\nMenisc intern normal.\n", self::REGIONS);

        self::assertSame('IRM Genunchi', $result['title']);
        self::assertSame('Articulații: Genunchi', $result['label']);
        self::assertSame(['msk'], $result['regions'], 'from the category, diacritics folded');
        self::assertSame("Raporturi articulare **normale**.\nMenisc intern normal.\n", $result['body'], 'no heading left in the text');
        self::assertSame([], $result['notes']);
    }

    public function testSeveralSectionsKeepTheirHeadingsUnderTheCatalogueLabel(): void
    {
        $result = TemplateConverter::convert("====== Coloană: Totală ======\n\n===== Segment cervical =====\n\nText A.\n\n===== Segment lombar =====\n\nText B.\n", self::REGIONS);

        self::assertSame('Coloană: Totală', $result['title']);
        self::assertSame(['spine'], $result['regions']);
        self::assertSame("## Segment cervical\n\nText A.\n\n## Segment lombar\n\nText B.\n", $result['body']);
        self::assertSame(['2 sections kept as headings'], $result['notes']);
    }

    public function testMacrosAreDroppedAndNotedAndAnUnknownCategoryGetsNoRegion(): void
    {
        $result = TemplateConverter::convert("====== Onco: Abdomen ======\n~~LLM_TEMPLATE:reports:mri:templates:x~~\n===== IRM Abdomen =====\n\nText.\n", self::REGIONS);

        self::assertSame([], $result['regions'], 'no guess');
        self::assertStringNotContainsString('LLM_TEMPLATE', $result['body']);
        self::assertSame(['macro dropped: ~~LLM_TEMPLATE:reports:mri:templates:x~~'], $result['notes']);
    }
}
