<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Maintenance\LibraryDigestTask;

/**
 * library:digest's summary line (2026-11-15): the assistant's answer cut to the one line a
 * source's `summary` holds, as the card's *Use as summary* does in the browser.
 */
final class LibraryDigestTaskTest extends TestCase
{
    public function testTheAnswerIsCutToOneCleanLine(): void
    {
        self::assertSame('Fazekas grades correlate with age.', LibraryDigestTask::line("**Summary:** \"Fazekas grades correlate with age.\"\n\nMore text."));
        self::assertSame('Un singur rând.', LibraryDigestTask::line("```\n- Rezumat: „Un singur rând.”\n```"));
        self::assertSame('', LibraryDigestTask::line("\n \n"));
        $long = LibraryDigestTask::line(str_repeat('word ', 60));
        self::assertLessThanOrEqual(160, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }
}
