<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\AudioScript;
use Reporion\Support\Wav;

/** The audio overview's script and the joining of its turns (docs/library.md, phase 8) */
final class AudioScriptTest extends TestCase
{
    public function testTurnsAreMergedByHostAndCutAtSentences(): void
    {
        $long = str_repeat('A sentence of a long turn. ', 200);
        $turns = AudioScript::turns("Intro the model added.\nA: Hello.\n**B:** Hi\nthere.\nB: Again.\n\nA: " . $long);

        self::assertFalse($turns['cut']);
        self::assertSame([0, 1], array_column(\array_slice($turns['turns'], 0, 2), 'speaker'));
        self::assertSame('Hi there. Again.', $turns['turns'][1]['text']);
        self::assertGreaterThan(3, \count($turns['turns']), 'the long turn in pieces');
        foreach ($turns['turns'] as $turn) {
            self::assertLessThanOrEqual(AudioScript::MAX_CHARS, mb_strlen($turn['text']));
        }
        self::assertTrue(AudioScript::turns(str_repeat("A: One.\nB: Two.\n", AudioScript::MAX_TURNS))['cut']);
    }

    public function testWavsAreJoinedUnderOneHeaderAndOtherFormatsRefused(): void
    {
        $wav = static fn (int $channels, int $samples): string => 'RIFF' . pack('V', 36 + $samples * 2 * $channels) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, 8000, 16000 * $channels, 2 * $channels, 16)
            . 'LIST' . pack('V', 4) . 'INFO'
            . 'data' . pack('V', $samples * 2 * $channels) . str_repeat("\1\0", $samples * $channels);

        $joined = Wav::join([$wav(1, 800), $wav(1, 400)], 0.5);

        self::assertSame(44 + (800 + 4000 + 400) * 2, \strlen($joined), 'the two and half a second of silence');
        self::assertSame(\strlen($joined) - 8, unpack('V', substr($joined, 4, 4))[1]);
        self::assertEqualsWithDelta(0.65, Wav::seconds($joined), 0.001);
        $this->expectException(\InvalidArgumentException::class);
        Wav::join([$wav(1, 10), $wav(2, 10)]);
    }
}
