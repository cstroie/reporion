<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Laterality;

/**
 * The pre-sign check's rules (roadmap phase 34a): sides that disagree
 * between title, indication, description and conclusion, and an exam with
 * no conclusion. Anonymised text only.
 */
final class LateralityTest extends TestCase
{
    /** @return list<string> */
    private static function codes(string $body, array $frontmatter = []): array
    {
        return array_column(Laterality::check($body, $frontmatter), 'code');
    }

    public function testTheTitleAndTheConclusionNamingOppositeSides(): void
    {
        $body = "# Nume\n\n## IRM genunchi stâng\n\n### Descriere\n\nMenisc medial fisurat, genunchi drept.\n\n### Concluzii\n\nFisură meniscală medială dreaptă.\n";
        $warnings = Laterality::check($body, []);

        self::assertSame('title_vs_conclusion', $warnings[0]['code']);
        self::assertSame([Laterality::LEFT], $warnings[0]['sides']);
        self::assertSame([Laterality::RIGHT], $warnings[0]['other']);
        self::assertSame('IRM genunchi stâng', $warnings[0]['exam']);
    }

    public function testAnAgreeingReportHasNoWarning(): void
    {
        self::assertSame([], self::codes("## IRM genunchi stâng\n\n### Indicație\n\nDurere genunchi stâng.\n\n### Descriere\n\nGenunchi stâng: menisc fisurat.\n\n### Concluzii\n\nFisură meniscală genunchi stâng.\n"));
        self::assertSame([], self::codes("## RM genunchi bilateral\n\n### Descriere\n\nAmbele genunchi.\n\n### Concluzii\n\nGonartroză dreaptă.\n"), 'a bilateral exam may conclude on one side');
        self::assertSame([], self::codes("## CT\n\n### Descriere\n\nZonă dreptunghiulară stângă.\n\n### Concluzii\n\nLeziune stângă.\n"), 'dreptunghiular is not a side');
        self::assertSame([], self::codes("## Left knee MRI\n\n### Findings\n\nLeft knee: torn meniscus.\n\n### Conclusion\n\nLeft medial meniscal tear.\n"), 'English');
    }

    public function testTheIndicationFromItsSectionOrTheFrontmatter(): void
    {
        $body = "## IRM umăr\n\n### Descriere\n\nUmăr drept: tendinopatie.\n\n### Concluzii\n\nTendinopatie supraspinos dreaptă.\n";

        self::assertSame(['indication_vs_conclusion'], self::codes($body, ['indication' => 'Durere umăr stâng']));
        self::assertSame([], self::codes($body, ['indication' => 'Durere umăr drept']));
    }

    public function testAConclusionSideTheDescriptionNeverNames(): void
    {
        self::assertSame(['conclusion_not_described'], self::codes("## CT torace\n\n### Descriere\n\nNodul lob superior drept.\n\n### Concluzii\n\nNodul pulmonar stâng.\n"));
        self::assertSame([], self::codes("## CT torace\n\n### Descriere\n\nNodul pulmonar.\n\n### Concluzii\n\nNodul pulmonar stâng.\n"), 'a description naming no side is not contradicted');
    }

    public function testEachExamOfAMultiExamReportAndOneWithNoConclusion(): void
    {
        $body = "# Nume\n\n## CT cerebral\n\n### Descriere\n\nFără leziuni.\n\n## IRM genunchi drept\n\n### Descriere\n\nGenunchi drept.\n\n### Concluzii\n\nLeziune stângă.\n";
        $warnings = Laterality::check($body, []);

        self::assertSame(['no_conclusion', 'title_vs_conclusion', 'conclusion_not_described'], array_column($warnings, 'code'));
        self::assertSame(['CT cerebral', 'IRM genunchi drept', 'IRM genunchi drept'], array_column($warnings, 'exam'));
    }

    public function testASingleExamReportTakesItsTitleFromTheFrontmatter(): void
    {
        self::assertSame(['title_vs_conclusion', 'conclusion_not_described'], self::codes("# Nume\n\n### Descriere\n\nRinichi stâng.\n\n### Concluzii\n\nChist renal drept.\n", ['exam_title' => 'Ecografie rinichi stâng']));
    }
}
