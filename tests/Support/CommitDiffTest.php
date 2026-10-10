<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\CommitDiff;

final class CommitDiffTest extends TestCase
{
    private const BODY = "## IRM cerebral\n\n### Tehnică\n\nExaminare nativă.\n\n### Descriere\n\nSistem ventricular normal. Fără leziuni focale.\n\nStructuri mediane pe linia mediană.\n\n### Concluzie\n\nLeziune nodulară în lobul drept, 12 mm, fără adenopatii.";

    public function testAFewWordsChangedAreMarkedInlineUnderTheirSection(): void
    {
        self::assertSame(
            "@@ ### Concluzie\n Leziune nodulară în lobul drept, [-12-]{+15+} mm, fără adenopatii.",
            CommitDiff::build(self::BODY, str_replace('12 mm', '15 mm', self::BODY)),
        );
    }

    public function testParagraphsAddedAndRemovedAreLinesOfTheirOwn(): void
    {
        $to = str_replace(
            ["Examinare nativă.\n", "\n\nStructuri mediane pe linia mediană."],
            ["Examinare nativă.\n\nExaminare efectuată cu contrast i.v.\n", ''],
            self::BODY,
        );

        self::assertSame(
            "@@ ### Tehnică\n+Examinare efectuată cu contrast i.v.\n@@ ### Descriere\n-Structuri mediane pe linia mediană.",
            CommitDiff::build(self::BODY, $to),
        );
    }

    public function testAParagraphMostlyRewrittenIsRemovedAndAdded(): void
    {
        $to = str_replace('Examinare nativă.', 'Secvențe T1, T2 și FLAIR după gadolinium.', self::BODY);

        self::assertSame("@@ ### Tehnică\n-Examinare nativă.\n+Secvențe T1, T2 și FLAIR după gadolinium.", CommitDiff::build(self::BODY, $to));
    }

    public function testAHeadingAddedIsTheSectionOfWhatFollowsIt(): void
    {
        $to = self::BODY . "\n\n### Recomandări\n\nControl la 6 luni.";

        self::assertSame("@@ ### Concluzie\n+### Recomandări\n@@ ### Recomandări\n+Control la 6 luni.", CommitDiff::build(self::BODY, $to));
    }

    public function testNothingWhenNothingChangedOrOnlyLineEndingsAndBlankLines(): void
    {
        self::assertNull(CommitDiff::build(self::BODY, self::BODY));
        self::assertNull(CommitDiff::build(self::BODY, str_replace("\n", "\r\n", self::BODY) . "\n\n"));
    }

    public function testNothingForARewriteOfMostOfTheText(): void
    {
        self::assertNull(CommitDiff::build("### Concluzie\n\n…", self::BODY), 'a template filled in');
        self::assertNull(CommitDiff::build(self::BODY, 'Alt raport cu totul.'));
    }

    public function testALongDiffKeepsWholeHunksUpToTheCap(): void
    {
        $from = '';
        $to = '';
        for ($i = 1; $i <= 40; ++$i) {
            $from .= "### Secțiunea {$i}\n\nText neschimbat aici, cu destule cuvinte ca să rămână. Valoare {$i} mm.\n\n";
            $to .= "### Secțiunea {$i}\n\nText neschimbat aici, cu destule cuvinte ca să rămână. Valoare " . ($i + 1) . " mm.\n\n";
        }
        $diff = (string) CommitDiff::build($from, $to);

        self::assertLessThanOrEqual(CommitDiff::MAX, mb_strlen($diff));
        self::assertStringStartsWith("@@ ### Secțiunea 1\n", $diff);
        self::assertStringEndsWith('mm.', $diff, 'cut between hunks, never inside one');
    }
}
