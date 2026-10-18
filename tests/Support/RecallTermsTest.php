<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\RecallTerms;

final class RecallTermsTest extends TestCase
{
    public function testALinesTermsAreFoldedInOrderWithShortImagingWords(): void
    {
        self::assertSame(
            ['leziuni', 'substanta', 'alba', 'hiperintense', 't2', 'flair'],
            RecallTerms::ofLine('Multiple leziuni de substanță albă, hiperintense T2 și FLAIR, leziuni')
        );
    }

    public function testFrequentKeepsLongWordsMostFrequentFirstWithoutStopWords(): void
    {
        self::assertSame(['menisc', 'fisura'], RecallTerms::frequent('Fisură menisc, menisc normal, aspect.', 5));
    }

    public function testMatchedIsByStemSoAnEndingDoesNotMatter(): void
    {
        self::assertSame(['leziuni', 'alba'], RecallTerms::matched(['leziuni', 'alba', 'flair'], 'O leziunea de substanță albă.'));
        self::assertSame([], RecallTerms::matched(['t2'], 'T21 și T1'), 'a short term only as a whole word');
        self::assertSame(['t2'], RecallTerms::matched(['t2'], 'hiperintens T2.'));
    }

    public function testSectionsDropTheNameAndExamHeadings(): void
    {
        $sections = RecallTerms::sections("# POPESCU Ana\n\n## IRM cerebral\n\nIntro.\n\n### Descriere\n\nText.\n");

        self::assertSame(['Intro.', "### Descriere\n\nText."], $sections);
    }

    public function testPassagesAreParagraphsAndListItemsUnderTheirHeading(): void
    {
        $body = "# POPESCU Ana\n\n## IRM cerebral\n\n### Descriere\n\nPrimul paragraf\ncontinuat.\n\n- item unu\n- item doi\n\n### Concluzii\n\nConcluzie.\n";

        self::assertSame([
            ['heading' => 'Descriere', 'text' => 'Primul paragraf continuat.'],
            ['heading' => 'Descriere', 'text' => 'item unu'],
            ['heading' => 'Descriere', 'text' => 'item doi'],
            ['heading' => 'Concluzii', 'text' => 'Concluzie.'],
        ], RecallTerms::passages($body));
    }

    public function testALongParagraphIsCutAtSentences(): void
    {
        $sentence = str_repeat('cuvant ', 30) . 'final.';
        $passages = RecallTerms::passages("### D\n\n" . implode(' ', array_fill(0, 4, ucfirst($sentence))));

        self::assertGreaterThan(1, \count($passages));
        foreach ($passages as $passage) {
            self::assertLessThanOrEqual(500, mb_strlen($passage['text']));
            self::assertStringEndsWith('final.', $passage['text']);
        }
    }

    public function testMissingIsWhatSeveralPassagesSayAndTheTextDoesNot(): void
    {
        $passages = [
            'Leziuni de substanță albă, fără efect de masă, hiperintense FLAIR, fără restricție de difuzie.',
            'Multiple leziuni ale substanței albe, fără efect de masă, hiperintense FLAIR, distribuție simetrică.',
            'Leziuni punctiforme, fără efect de masă.',
        ];
        $missing = RecallTerms::missing($passages, "Multiple leziuni de substanță albă, hiperintense.\nAlt rând.", ['leziuni', 'substanta', 'alba']);

        self::assertSame('efect de masă', $missing[0], 'the most shared first, as written, a phrase over its words');
        self::assertContains('FLAIR', $missing);
        self::assertNotContains('efect', $missing);
        self::assertNotContains('masă', $missing);
        self::assertNotContains('hiperintense', $missing, 'already written');
        self::assertNotContains('substanței', $missing, 'the line\'s own term');
        self::assertNotContains('simetrică', $missing, 'in one passage only');
    }

    public function testHighlightMarksMatchedWordsAndEscapes(): void
    {
        self::assertSame(
            '<mark>Leziunea</mark> &lt;b&gt; în <mark>T2</mark> T21',
            RecallTerms::highlight('Leziunea <b> în T2 T21', ['leziuni', 't2'])
        );
    }
}
