<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Ai\AiConfig;

/** Which prompt profile a page uses (D47): the most specific namespace, else the fallback */
final class ProfileRoutingTest extends TestCase
{
    public function testTheMostSpecificNamespaceWinsAndTheFallbackTakesTheRest(): void
    {
        $ai = AiConfig::fromConfig(['ai' => [
            'prompt_profile' => 'reports',
            'namespaces' => ['reports'],
            'profile_map' => ['wiki' => 'writing', 'reports:mri:research' => 'research', 'bad' => 'Bad Name'],
        ]]);

        self::assertSame(['wiki' => 'writing', 'reports:mri:research' => 'research'], $ai->profileMap, 'an invalid profile name is left out');
        self::assertSame('reports', $ai->profileFor('reports:mri:s:260101-x'));
        self::assertSame('research', $ai->profileFor('reports:mri:research:study'), 'more specific than the main profile\'s namespace');
        self::assertSame('writing', $ai->profileFor('wiki:notes:x'));
        self::assertNull($ai->profileFor('wikipedia:x'), 'a namespace boundary, not a string prefix');
        self::assertNull($ai->profileFor('docs:x'), 'no fallback: no assistant there');

        $withFallback = AiConfig::fromConfig(['ai' => ['fallback_profile' => 'default', 'profile_map' => ['wiki' => 'writing']]]);
        self::assertSame('default', $withFallback->profileFor('docs:x'));
        self::assertSame('writing', $withFallback->profileFor('wiki'));
    }
}
