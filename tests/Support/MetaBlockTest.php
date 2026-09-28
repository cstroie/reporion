<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\MetaBlock;

/**
 * The imported `~~META: … ~~` block (TODO.md idea 10): its eleven keys —
 * "nr" with no leading "&", always first, everything else with one — the
 * real archive's one malformed shape (a missing newline running two keys
 * together), and stripping the block once it is parsed. Fixture data is
 * fictitious throughout (invariant 10).
 */
final class MetaBlockTest extends TestCase
{
    private const BLOCK = "~~META:\nnr       = G195\n&date    = 27.09.2026\n&name    = TEST Patient\n&age     = 45 ani\n&sex     = F\n&section = Neurologie\n&medic   = Dr. Popescu\n&fo      = 4126\n&diag    = Cefalee\n&exam    = IRM cerebral\n&secv    = T1 SAG, T2 COR; FLAIR TRS\n~~\n";

    public function testNoBlockIsNull(): void
    {
        self::assertNull(MetaBlock::parse("# TEST\n\nText.\n"));
    }

    public function testAllElevenKeysParse(): void
    {
        $result = MetaBlock::parse("# TEST Patient\n\n" . self::BLOCK . "\n## Exam\n\nText.\n");

        self::assertTrue($result['ok']);
        self::assertSame([
            'nr' => 'G195', 'date' => '27.09.2026', 'name' => 'TEST Patient', 'age' => '45 ani', 'sex' => 'F',
            'section' => 'Neurologie', 'medic' => 'Dr. Popescu', 'fo' => '4126', 'diag' => 'Cefalee',
            'exam' => 'IRM cerebral', 'secv' => 'T1 SAG, T2 COR; FLAIR TRS',
        ], $result['fields']);
    }

    public function testNrHasNoLeadingAmpersandAndStillParses(): void
    {
        $result = MetaBlock::parse("# TEST\n\n~~META:\nnr       = G195\n&date    = 27.09.2026\n~~\n");

        self::assertTrue($result['ok']);
        self::assertSame('G195', $result['fields']['nr']);
    }

    public function testTheBlockIsStrippedLeavingOneBlankLineWhereItWas(): void
    {
        $result = MetaBlock::parse("# TEST Patient\n\n" . self::BLOCK . "\n## Exam\n\nText.\n");

        self::assertSame("# TEST Patient\n\n## Exam\n\nText.\n", $result['bodyWithoutBlock']);
    }

    public function testTheBlockAtTheVeryStartOfTheBodyLeavesNoLeadingBlankLine(): void
    {
        $result = MetaBlock::parse(self::BLOCK . "\n# TEST Patient\n");

        self::assertSame("# TEST Patient\n", $result['bodyWithoutBlock']);
    }

    public function testAMissingNewlineBetweenTwoKeysIsCaughtNotMisattributed(): void
    {
        // The real archive's corruption: "&fo" empty, run straight into "&diag"
        // with no newline between them — the value must never be read as fo's
        $broken = "~~META:\n&date    = 27.09.2026\n&name    = TEST Patient\n&fo      =&diag    = Sensitive text\n~~\n";

        $result = MetaBlock::parse("# TEST\n\n" . $broken . "\n");

        self::assertFalse($result['ok']);
        self::assertSame('', $result['fields']['fo'], 'never the other key\'s value');
        self::assertSame('', $result['fields']['diag']);
        self::assertNotEmpty($result['reasons']);
    }

    public function testALineThatIsNotKeyEqualsValueIsCaught(): void
    {
        $broken = "~~META:\n&date    = 27.09.2026\njust some stray text\n~~\n";

        $result = MetaBlock::parse("# TEST\n\n" . $broken);

        self::assertFalse($result['ok']);
    }

    public function testADuplicateKeyIsCaught(): void
    {
        $broken = "~~META:\n&date    = 27.09.2026\n&date    = 28.09.2026\n~~\n";

        $result = MetaBlock::parse("# TEST\n\n" . $broken);

        self::assertFalse($result['ok']);
    }

    public function testAnUnknownKeyIsCaught(): void
    {
        $broken = "~~META:\n&wat     = value\n~~\n";

        $result = MetaBlock::parse("# TEST\n\n" . $broken);

        self::assertFalse($result['ok']);
    }

    /**
     * @dataProvider ages
     */
    public function testParseAge(string $age, int $studyYear, ?int $expected): void
    {
        self::assertSame($expected, MetaBlock::parseAge($age, $studyYear));
    }

    public static function ages(): iterable
    {
        yield 'romanian years' => ['45 ani', 2026, 1981];
        yield 'single digit romanian' => ['3 ani', 2026, 2023];
        yield 'english Y' => ['45Y', 2026, 1981];
        yield 'months, under a year old' => ['3 luni', 2026, 2026];
        yield 'unrecognised' => ['unknown', 2026, null];
    }

    /**
     * @dataProvider dates
     */
    public function testParseDate(string $date, ?string $expected): void
    {
        self::assertSame($expected, MetaBlock::parseDate($date));
    }

    public static function dates(): iterable
    {
        yield 'well formed' => ['27.09.2026', '2026-09-27'];
        yield 'single digit day and month' => ['5.3.2026', '2026-03-05'];
        yield 'not a real date' => ['31.02.2026', null];
        yield 'not this shape at all' => ['2026-09-27', null];
    }

    public function testParseSequencesSplitsOnCommaAndSemicolon(): void
    {
        self::assertSame(['T1 SAG', 'T2 COR', 'FLAIR TRS'], MetaBlock::parseSequences('T1 SAG, T2 COR; FLAIR TRS'));
    }

    public function testParseSequencesDropsEmptyPartsAndDuplicates(): void
    {
        self::assertSame(['T1 SAG'], MetaBlock::parseSequences('T1 SAG,, T1 SAG'));
    }
}
