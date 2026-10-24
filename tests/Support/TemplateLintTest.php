<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\TemplateLint;

final class TemplateLintTest extends TestCase
{
    private const HEAD = "# Genunchi\n\n## IRM Genunchi\n\n";

    public function testATemplateInTheBracketNotationHasNoProblems(): void
    {
        $body = self::HEAD
            . "[Meniscurile sunt normale. / Meniscul [medial / lateral] prezintă ruptură [orizontală / radială / [...]]; meniscul [lateral / medial] este normal.]\n"
            . "[Chist Baker de [...] / [...] / [...] mm (LL/AP/CC).]\n"
            . "Formațiune de [...] / [...] / [...] mm, cu semnal T2/STIR crescut.\n"
            . "[Revărsat articular [minim / moderat].]\n";

        self::assertSame([], TemplateLint::problems([], $body));
    }

    public function testEachStructuralMistakeIsFoundOnItsLine(): void
    {
        $body = self::HEAD
            . "Ficat: [dimensiuni, structură — omogen / neomogen].\n"   // 5: dash
            . "[Coledoc dilatat, cu diametrul de [...] mm.\n"           // 6: bracket
            . "Revărsat articular absent / prezent.\n"                    // 7: slash
            . "Sistem ventricular [normal / modificări].\n"               // 8: vague
            . "[Splina are aspect normal. / [...]]\n"                     // 9: vague
            . "### Secțiune [opțională]\n";                               // 10: heading

        $found = array_map(static fn (array $p): string => $p['line'] . ' ' . $p['code'], TemplateLint::problems([], $body));

        self::assertSame(['5 dash', '6 bracket', '7 slash', '8 vague', '9 vague', '10 heading'], $found);
    }

    public function testABlankAmongAdjectivesIsAChoiceNotAVagueOne(): void
    {
        self::assertSame([], TemplateLint::problems([], self::HEAD . "Tub de dren cu capătul intern [subhepatic / în pelvis / [...]].\n"));
    }

    public function testTheHeadingShapeIsReportedToo(): void
    {
        $codes = array_column(TemplateLint::problems([], "## IRM Genunchi\n\nText.\n"), 'code');

        self::assertSame(['shape.name.missing'], $codes);
    }

    public function testCodeBlocksAreLeftAlone(): void
    {
        self::assertSame([], TemplateLint::problems([], self::HEAD . "```\na / b — [c\n```\n"));
    }

    public function testOnlyReportTemplatesAreChecked(): void
    {
        self::assertTrue(TemplateLint::applies('templates:mri:genunchi', []));
        self::assertFalse(TemplateLint::applies('templates:snippets:norm', []));
        self::assertFalse(TemplateLint::applies('templates:pages:meeting', []));
        self::assertFalse(TemplateLint::applies('templates:mri:genunchi', ['format' => 'text']));
        self::assertFalse(TemplateLint::applies('reports:mri:a', []));
    }
}
