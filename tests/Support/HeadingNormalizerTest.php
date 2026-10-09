<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\HeadingNormalizer;
use Reporion\Service\Render;

/**
 * The one heading shape (docs/FORMATS.md §11) from the shapes the imported
 * archive has. Bodies are synthetic; the shapes are the archive's.
 */
final class HeadingNormalizerTest extends TestCase
{
    private const FM = ['patient' => ['name' => 'TEST Patient Unu']];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalized(): iterable
    {
        yield 'one exam, sections beside it (the usual archive shape)' => [
            "## TEST Patient Unu\n\n**Cefalee**\n*01.02.2024*\n\n### IRM Cerebral\n\nText.\n\n### Concluzii\n\nFără leziuni.\n",
            "# TEST Patient Unu\n\n**Cefalee**\n*01.02.2024*\n\n## IRM Cerebral\n\nText.\n\n### Concluzii\n\nFără leziuni.\n",
        ];
        yield 'the name alone at ###' => [
            "### TEST Patient Unu\n**TCC**  \n*24.07.2021*  \nText.\n",
            "# TEST Patient Unu\n**TCC**  \n*24.07.2021*  \nText.\n",
        ];
        yield 'several exams and one shared conclusion' => [
            "## TEST Patient Unu\n\n### CT Cerebral\n\nA.\n\n### CT Torace\n\nB.\n\n### Concluzii\n\nC.\n",
            "# TEST Patient Unu\n\n## CT Cerebral\n\nA.\n\n## CT Torace\n\nB.\n\n## Concluzii\n\nC.\n",
        ];
        yield 'a conclusion per exam' => [
            "## TEST Patient Unu\n\n### IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n### IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n",
            "# TEST Patient Unu\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n",
        ];
        yield 'sub-parts keep their depth under the section level' => [
            "## TEST Patient Unu\n\n### IRM Coloană totală\n\n#### Segment cervical\n\nA.\n\n### Concluzii\n\nB.\n",
            "# TEST Patient Unu\n\n## IRM Coloană totală\n\n#### Segment cervical\n\nA.\n\n### Concluzii\n\nB.\n",
        ];
        yield 'an exam with no sections' => [
            "## TEST Patient Unu\n\n### CT picior stâng\n\nA.\n\n### CT picior drept\n\nB.\n",
            "# TEST Patient Unu\n\n## CT picior stâng\n\nA.\n\n## CT picior drept\n\nB.\n",
        ];
        yield 'a hash line inside fenced code is not a heading' => [
            "## TEST Patient Unu\n\n### CT Cerebral\n\n```\n## not a heading\n```\n\n### Concluzii\n\nC.\n",
            "# TEST Patient Unu\n\n## CT Cerebral\n\n```\n## not a heading\n```\n\n### Concluzii\n\nC.\n",
        ];
    }

    /**
     * @dataProvider normalized
     */
    public function testItBringsTheArchiveShapesToTheOneShape(string $before, string $after): void
    {
        $result = HeadingNormalizer::normalize($before, self::FM);

        self::assertSame(HeadingNormalizer::NORMALIZED, $result['outcome'], implode(', ', $result['reasons']));
        self::assertSame($after, $result['body']);
    }

    /**
     * @dataProvider normalized
     */
    public function testOnlyHeadingMarksChangeNeverTheText(string $before): void
    {
        $render = new Render();
        $text = static fn (string $body): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags($render->toHtml($body)->html)));

        self::assertSame($text($before), $text(HeadingNormalizer::normalize($before, self::FM)['body']));
    }

    /**
     * @dataProvider normalized
     */
    public function testASecondRunChangesNothing(string $before): void
    {
        $once = HeadingNormalizer::normalize($before, self::FM)['body'];

        self::assertSame(HeadingNormalizer::UNCHANGED, HeadingNormalizer::normalize($once, self::FM)['outcome']);
    }

    public function testTheExamTitlesComeBackInOrder(): void
    {
        $result = HeadingNormalizer::normalize("## TEST Patient Unu\n\n### CT Cerebral\n\nA.\n\n### CT *Torace*\n\nB.\n\n### Concluzii\n\nC.\n", self::FM);

        self::assertSame(['CT Cerebral', 'CT Torace'], $result['exams']);
    }

    public function testTheNameMatchIgnoresCaseAndSpacing(): void
    {
        $result = HeadingNormalizer::normalize("##   test  PATIENT unu\n\n### CT Cerebral\n", self::FM);

        self::assertSame("#   test  PATIENT unu\n\n## CT Cerebral\n", $result['body']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forReview(): iterable
    {
        yield 'first heading is not the name' => ["## Politraumatism\n\n### CT Cerebral\n", 'first heading is not the patient name'];
        yield 'the name heading repeats' => ["## TEST Patient Unu\n\n### CT Cerebral\n\n## TEST Patient Unu\n\n### Concluzii\n", 'the name heading repeats'];
        yield 'sections and no exam heading' => ["## TEST Patient Unu\n\n### Segment cervical\n\nA.\n\n### Concluzii\n", 'sections with no exam heading'];
        yield 'a heading before the first exam' => ["## TEST Patient Unu\n\n### Date pacient\n\nA.\n\n### CT Cerebral\n", 'a heading before the first exam'];
        yield 'exams at different levels' => ["## TEST Patient Unu\n\n### CT Cerebral\n\n#### CT Torace\n", 'exam headings at different levels'];
        yield 'a heading above the sections' => ["## TEST Patient Unu\n\n### CT Cerebral\n\nA.\n\n### Concluzii\n\n## Templates\n", 'sections not directly under the exams'];
        yield 'a setext heading' => ["## TEST Patient Unu\n\n### CT Cerebral\n\nA line\n---\n", 'setext heading'];
        yield 'a heading in a quote' => ["## TEST Patient Unu\n\n### CT Cerebral\n\n> ### Concluzii\n", 'heading inside a quote or list'];
        yield 'a heading with no letters' => ["## TEST Patient Unu\n\n### CT Cerebral\n\n### 2\n", 'heading without letters'];
        yield 'an exam heading without its modality' => ["## TEST Patient Unu\n\n### IRM Cerebral\n\nA.\n\n### Coloană cervicală\n\nB.\n", 'an exam heading without its modality'];
        yield 'text before the name heading' => ["Preambul.\n\n## TEST Patient Unu\n\n### CT Cerebral\n", 'text before the name heading'];
    }

    /**
     * @dataProvider forReview
     */
    public function testWhatTheRulesCannotPlaceIsLeftForReview(string $body, string $reason): void
    {
        $result = HeadingNormalizer::normalize($body, self::FM);

        self::assertSame(HeadingNormalizer::REVIEW, $result['outcome']);
        self::assertContains($reason, $result['reasons']);
        self::assertSame($body, $result['body'], 'left as it is');
    }

    public function testWithoutAPatientNameNothingIsGuessed(): void
    {
        $result = HeadingNormalizer::normalize("## Someone\n\n### CT Cerebral\n", []);

        self::assertSame(HeadingNormalizer::REVIEW, $result['outcome']);
        self::assertSame(['no patient name to match'], $result['reasons']);
    }

    public function testTheShapeNamesNoText(): void
    {
        $result = HeadingNormalizer::normalize("## TEST Patient Unu\n\n### CT Cerebral\n\n#### Segment\n\n### Concluzii\n", self::FM);

        self::assertSame('N2 E3 O4 S3', $result['shape']);
    }
}
