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

    /** Which server a page's assistant asks (ai.server_map, 2026-11-01): the most specific namespace, else the server in use */
    public function testTheMostSpecificNamespaceNamesTheServerAndTheRestUseTheDefault(): void
    {
        $config = ['ai' => [
            'server' => 1,
            'servers' => [['name' => 'Local', 'timeout' => 60], ['name' => 'Cloud', 'timeout' => 300], ['name' => 'Cloud', 'timeout' => 30]],
            'server_map' => ['library' => 2, 'library:deep' => 3, 'reports' => 1, 'bad' => 9, 'worse' => 'x'],
        ]];
        $ai = AiConfig::fromConfig($config);

        self::assertSame(['library' => 2, 'library:deep' => 3, 'reports' => 1], $ai->serverMap, 'a slot out of range is left out');
        self::assertSame('#2', $ai->serverFor('library:smith2020'), 'by its number');
        self::assertSame('#3', $ai->serverFor('library:deep:x'), 'more specific');
        self::assertSame(3, AiConfig::slotByName($config, '#3'), 'two servers called Cloud are never confused');
        self::assertSame(2, AiConfig::slotByName($config, 'Cloud'), 'a name is the first server so called, as before');
        self::assertSame('Cloud', $ai->forPath('library:deep:x')->serverName);
        self::assertSame(30, $ai->forPath('library:deep:x')->timeout);
        self::assertSame($ai, $ai->forPath('wiki:x'), 'the server in use');
        self::assertSame(300, $ai->longestTimeout(), 'the slowest server a page may ask');
        self::assertNull($ai->serverFor('reports:mri:x'), 'the server in use: no name needed');
        self::assertNull($ai->serverFor('librarything:x'), 'a namespace boundary, not a string prefix');
        self::assertNull($ai->serverFor('wiki:x'), 'everywhere else: the server in use');
    }
}
