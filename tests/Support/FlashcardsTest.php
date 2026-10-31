<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\Flashcards;

/** Flashcards (docs/library.md, phase 8): the cards are made up */
final class FlashcardsTest extends TestCase
{
    public function testCardsAreReadAsTheModelWritesThem(): void
    {
        $text = "Here are the cards.\n\nQ: What grades does Fazekas give?\nA: 0 to 3 [@abel2020, p. 4]\n\n**Q:** Where?\n**A:** Deep white\nmatter.\n\n- Î: Câte?\n- R: 128\n\nQ: A question with no answer\n\nI. Introduction is no card.";

        self::assertSame([
            ['front' => 'What grades does Fazekas give?', 'back' => '0 to 3 [@abel2020, p. 4]'],
            ['front' => 'Where?', 'back' => "Deep white\nmatter."],
            ['front' => 'Câte?', 'back' => '128'],
        ], Flashcards::parse($text));
    }

    public function testAnkisFileIsEscapedAndCitesByAuthorAndYear(): void
    {
        $cite = static fn (string $key, ?string $at): string => ($key === 'abel2020' ? 'Abel, 2020' : $key) . ($at !== null ? ', ' . $at : '');

        $file = Flashcards::anki([
            ['front' => "Tab\there <b>bold</b>?", 'back' => "Line one\nline two [@abel2020, p. 4; @cole2021]"],
        ], ['reporion', 'white matter'], $cite);

        self::assertSame("#separator:tab\n#html:true\n#notetype:Basic\n#tags:reporion white-matter\n"
            . "Tab here &lt;b&gt;bold&lt;/b&gt;?\tLine one<br>line two (Abel, 2020, p. 4; cole2021)\n", $file);
    }
}
