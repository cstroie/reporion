<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Service\FrontmatterGuess;
use Reporion\Support\DocumentFormat;

/**
 * A new page sent without frontmatter gets what the page itself says
 * (decided 2026-09-27), nothing invented.
 */
final class FrontmatterGuessTest extends TestCase
{
    public function testAPageIsTitledByItsFirstHeadingAndPrivate(): void
    {
        self::assertSame(
            ['title' => 'Medima', 'visibility' => 'private'],
            FrontmatterGuess::forNewPage('docs:it:medima', "# Medima\n\n## RDP\n\nText.\n")
        );
    }

    public function testWithoutAHeadingThePageNameTitlesIt(): void
    {
        self::assertSame(['title' => 'Remote desktop', 'visibility' => 'private'], FrontmatterGuess::forNewPage('docs:remote-desktop', "Just text.\n"));
    }

    public function testAReportTakesItsPathAndHeadings(): void
    {
        $fm = FrontmatterGuess::forNewPage(
            'reports:mri:mioveni:260927-test-unu',
            "# TEST Patient Unu\n\n## IRM cerebral\n\n```\n# not a heading\n```\n\n### Concluzii\n"
        );

        self::assertSame([
            'title' => 'TEST Patient Unu',
            'exam_title' => 'IRM cerebral',
            'visibility' => 'private',
            'modality' => ['MR'],
            'site' => 'mioveni',
            'study_date' => '2026-09-27',
            'patient' => ['name' => 'TEST Patient Unu'],
        ], $fm);
    }

    public function testAnImpossibleDateOrAnUnknownModalityIsLeftOut(): void
    {
        $fm = FrontmatterGuess::forNewPage('reports:pet:mioveni:261399-test', "Text.\n");

        self::assertArrayNotHasKey('study_date', $fm);
        self::assertArrayNotHasKey('modality', $fm);
        self::assertSame('mioveni', $fm['site']);
    }

    public function testADocumentWithoutABlockIsBareAndAHalfTypedBlockIsAnError(): void
    {
        self::assertSame([null, "# X\n\nbody"], DocumentFormat::parseOrBare("\r\n# X\r\n\r\nbody"));
        self::assertSame([['title' => 'a'], 'b'], DocumentFormat::parseOrBare("---\ntitle: a\n---\n\nb"));

        $this->expectException(\RuntimeException::class);
        DocumentFormat::parseOrBare("---\ntitle: a\n\nno closing line");
    }
}
