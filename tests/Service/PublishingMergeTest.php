<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Publishing;

/**
 * Publishing::merge() (phase 14): the one merge rule shared by every
 * frontmatter writer that is not a raw-document round trip — a key not
 * mentioned in $changes is never touched, null removes a key, `status`
 * is never set this way.
 */
final class PublishingMergeTest extends TestCase
{
    public function testAKeyNotMentionedIsUntouched(): void
    {
        $current = ['title' => 'RM genunchi', 'visibility' => 'private', 'tags' => ['a', 'b']];

        self::assertSame($current, Publishing::merge($current, ['summary' => null]));
    }

    public function testANullValueRemovesTheKey(): void
    {
        $current = ['title' => 'RM genunchi', 'device' => 'MV-MR-01'];

        self::assertSame(['title' => 'RM genunchi'], Publishing::merge($current, ['device' => null]));
    }

    public function testAnyOtherValueSetsTheKey(): void
    {
        $current = ['title' => 'RM genunchi', 'visibility' => 'private'];

        self::assertSame(
            ['title' => 'RM genunchi', 'visibility' => 'public', 'tags' => ['a']],
            Publishing::merge($current, ['visibility' => 'public', 'tags' => ['a']])
        );
    }

    public function testStatusIsNeverSetThisWay(): void
    {
        self::assertSame(['title' => 'RM'], Publishing::merge(['title' => 'RM'], ['status' => 'signed']));
    }

    public function testANewKeyIsAdded(): void
    {
        self::assertSame(['title' => 'RM', 'referrer' => 'Dr. X'], Publishing::merge(['title' => 'RM'], ['referrer' => 'Dr. X']));
    }

    public function testEmptyChangesIsANoOp(): void
    {
        $current = ['title' => 'RM', 'patient' => ['name' => 'X']];

        self::assertSame($current, Publishing::merge($current, []));
    }
}
