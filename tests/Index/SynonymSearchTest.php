<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Index;

use Reporion\Index\Sqlite;

/**
 * D28, wired in phase 20: a query term that belongs to a synonym group
 * (Service\TagDictionary::searchGroups()) matches any term of the group;
 * everything else searches exactly as before.
 */
final class SynonymSearchTest extends IndexTestCase
{
    public function testATermFindsItsWholeGroupAndOtherTermsAreUnchanged(): void
    {
        $path = sys_get_temp_dir() . '/reporion-index-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        $index = new Sqlite($path, $this->migrationsDir, static fn (): array => [['hernie', 'hernia', 'hernie de disc'], ['PI-RADS', 'pirads']]);
        $public = ['visibility' => 'public'];
        $index->index($this->snapshot('p1', 'reports:a', ['title' => 'A'], 'Hernia discala L4-L5.', $public));
        $index->index($this->snapshot('p2', 'reports:b', ['title' => 'B'], 'Protruzie; aspect de hernie de disc.', $public));
        $index->index($this->snapshot('p3', 'reports:c', ['title' => 'C'], 'Leziune PI-RADS 4 in zona periferica.', $public));
        $index->index($this->snapshot('p4', 'reports:d', ['title' => 'D'], 'Disc normal, fara hernii.', $public));

        $pids = static fn (array $rows): array => array_values(array_map('strval', array_column($rows, 'pid')));
        $sorted = static function (array $pids): array {
            sort($pids);

            return $pids;
        };

        self::assertSame(['p1', 'p2'], $sorted($pids($index->search('hernie', null))), 'hernie also finds hernia and the phrase');
        self::assertSame(['p1', 'p2'], $sorted($pids($index->search('Hérnia', null))), 'any term of the group, case and diacritics folded');
        self::assertSame(['p1'], $pids($index->search('hernie L5', null)), 'the other tokens still AND');
        self::assertSame(['p3'], $pids($index->search('pirads', null)), 'a hyphenated term through its group');
        self::assertSame(['p2', 'p4'], $sorted($pids($index->search('disc', null))), 'a term in no group: exactly as before');
        self::assertSame([], $index->search('"hernie" OR x', null), 'still no FTS5 syntax from the caller');

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            @unlink($file);
        }
    }
}
