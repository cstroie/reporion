<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\WritingChecks;

/**
 * The checks while writing and before signing (D51). Anonymised text only.
 */
final class WritingChecksTest extends TestCase
{
    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return list<array{0: string, 1: list<string>, 2: string}> [code, args, quote]
     */
    private static function found(string $body, array $frontmatter = []): array
    {
        return array_map(static fn (array $c): array => [$c['code'], $c['args'], $c['quote']], WritingChecks::check($body, $frontmatter));
    }

    /** @return list<string> */
    private static function codes(string $body, array $frontmatter = []): array
    {
        return array_column(WritingChecks::check($body, $frontmatter), 'code');
    }

    public function testACleanReportHasNone(): void
    {
        $body = "# Nume\n\n## IRM genunchi drept\n\n### Descriere\n\nFisură a meniscului medial drept, de 8 mm.\nLigamente încrucișate fără leziuni.\n\n### Concluzii\n\nFisură meniscală medială dreaptă.\n";

        self::assertSame([], self::codes($body, ['patient' => ['sex' => 'F']]));
    }

    public function testASharedConclusionIsCheckedAgainstAllTheExams(): void
    {
        $fm = ['exams' => [['title' => 'IRM genunchi drept'], ['title' => 'IRM genunchi stâng']]];
        $body = "# Nume\n\n## IRM genunchi drept\n\n### Descriere\n\nFisură a meniscului medial drept.\n\n## IRM genunchi stâng\n\n### Descriere\n\nLigamente fără leziuni.\n\n## Concluzii\n\nFisură meniscală medială dreaptă.\n";

        self::assertSame([], self::codes($body, $fm));
        $body = str_replace('Fisură meniscală medială dreaptă.', 'Ruptură de ligament încrucișat.', $body);
        self::assertContains('not_described', self::codes($body, $fm));
        self::assertNotContains('no_conclusion', self::codes($body, $fm));
    }

    public function testASharedConclusionOfASingleExamIsAWarningNotABlock(): void
    {
        $fm = ['exams' => [['title' => 'IRM genunchi drept']]];
        $body = "# Nume\n\n## IRM genunchi drept\n\nFisură a meniscului medial drept.\n\n## Concluzii\n\nFisură meniscală medială dreaptă.\n";

        self::assertContains('shared_single', self::codes($body, $fm));
        self::assertSame([], \Reporion\Support\Exams::problems($fm, $body));
    }

    public function testLateralityComesFirstAsBefore(): void
    {
        $body = "# Nume\n\n## IRM genunchi stâng\n\n### Descriere\n\nFisură meniscală.\n\n### Concluzii\n\nFisură meniscală dreaptă.\n";
        $checks = WritingChecks::check($body, []);

        self::assertSame('title_vs_conclusion', $checks[0]['code']);
        self::assertSame(['left'], $checks[0]['sides']);
    }

    public function testAnOrganOfTheOtherSexNotNegated(): void
    {
        $body = "# Nume\n\n## CT abdomen\n\n### Descriere\n\nUter de dimensiuni normale. Fără prostată vizibilă.\n\n### Concluzii\n\nAspect normal.\n";

        self::assertSame([['sex_mismatch', ['Uter'], 'Uter']], self::found($body, ['patient' => ['sex' => 'M']]));
        self::assertSame([], self::found($body, ['patient' => ['sex' => 'F']]), 'the prostate is negated');
        self::assertSame([], self::found($body), 'no sex: no check');
    }

    public function testBlanksButNotLinksTasksOrReferences(): void
    {
        $body = "# Nume\n\n## IRM\n\n### Descriere\n\nChist de [dimensiune] în [localizare].\nNodul de xx mm.\nVezi [ghidul](https://exemplu.ro) și [nota][1].\n- [ ] de verificat\n- [x] gata\n\n[1]: https://exemplu.ro\n\n### Concluzii\n\nChist.\n";
        $blanks = array_values(array_filter(self::found($body), static fn (array $c): bool => $c[0] === 'blank'));

        self::assertSame(['[dimensiune]', '[localizare]', 'xx'], array_column($blanks, 2));
    }

    public function testAnEmptySection(): void
    {
        $body = "# Nume\n\n## IRM\n\n### Descriere\n\n### Concluzii\n\nAspect normal.\n";

        self::assertContains(['empty_section', ['Descriere'], '### Descriere'], self::found($body));
    }

    public function testAFindingDescribedButNotConcluded(): void
    {
        $body = "# Nume\n\n## CT torace\n\n### Descriere\n\nNodul pulmonar de 6 mm în lobul superior drept.\nFără revărsat pleural.\nCord de dimensiuni normale, 120 mm.\n\n### Concluzii\n\nFără alte modificări semnificative.\n";

        self::assertSame([['not_concluded', ['Nodul pulmonar de 6 mm în lobul superior drept.'], 'Nodul pulmonar de 6 mm în lobul superior drept.']], self::found($body));
    }

    public function testAFindingConcludedButNotDescribed(): void
    {
        $body = "# Nume\n\n## IRM coloană lombară\n\n### Descriere\n\nDiscopatie L4-L5.\n\n### Concluzii\n\nHernie discală L4-L5.\n";

        self::assertSame([['not_described', ['hernie'], 'Hernie discală L4-L5.']], self::found($body));
    }

    public function testFencedCodeIsNoProse(): void
    {
        $body = "# Nume\n\n## IRM\n\n### Descriere\n\n```\n[dimensiune] xx\n```\n\nAspect normal.\n\n### Concluzii\n\nAspect normal.\n";

        self::assertSame([], self::codes($body));
    }

    public function testAtMostFiveOfAKindPerExam(): void
    {
        $lines = implode("\n", array_map(static fn (int $i): string => "Chist de {$i} mm în segmentul {$i}.", range(1, 8)));
        $body = "# Nume\n\n## IRM ficat\n\n### Descriere\n\n{$lines}\n\n### Concluzii\n\nAspect normal.\n";

        self::assertCount(5, array_filter(self::codes($body), static fn (string $c): bool => $c === 'not_concluded'));
    }
}
