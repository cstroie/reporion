<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\LibraryMarks;

/**
 * An account's marks on library sources (Auth\LibraryMarks, 2026-11-15): cleaned, set one key at a
 * time, kept in the account's file and nowhere else.
 */
final class LibraryMarksTest extends TestCase
{
    private const A = '01J0000000000000000000000A';
    private const B = '01J0000000000000000000000B';

    public function testAStoredMapIsMadeSafe(): void
    {
        self::assertSame(
            [self::A => ['status' => 'toread'], self::B => ['status' => 'read', 'star' => true]],
            LibraryMarks::clean([
                self::A => ['status' => 'toread', 'star' => false, 'note' => 'x'],
                self::B => ['status' => 'read', 'star' => true],
                'library:key' => ['status' => 'read'],
                '01J0000000000000000000000C' => ['status' => 'finished'],
                '01J0000000000000000000000D' => 'read',
            ]),
        );
        self::assertSame([], LibraryMarks::clean('nonsense'));
    }

    public function testAMarkIsSetOneKeyAtATimeAndAnEmptyOneGoes(): void
    {
        $marks = LibraryMarks::set([], self::A, 'reading', null);
        self::assertSame([self::A => ['status' => 'reading']], $marks);
        $marks = LibraryMarks::set($marks, self::A, null, true);
        self::assertSame([self::A => ['status' => 'reading', 'star' => true]], $marks, 'the star leaves the status');
        $marks = LibraryMarks::set($marks, self::A, '', null);
        self::assertSame([self::A => ['star' => true]], $marks);
        self::assertSame([], LibraryMarks::set($marks, self::A, null, false), 'no status, no star: no mark');
        self::assertSame([], LibraryMarks::set([], self::A, 'finished', null), 'an unknown status is none');
    }

    public function testTheMarksAreKeptInTheAccountsFile(): void
    {
        $dir = sys_get_temp_dir() . '/reporion-marks-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $users = new FlatFileUserStore($dir);
            $user = $users->create('reader', password_hash('x', PASSWORD_ARGON2ID), false);
            self::assertSame([], $user->marks);
            $users->save($user->with(marks: [self::A => ['status' => 'toread', 'star' => true], 'bad' => ['status' => 'read']]));

            $again = (new FlatFileUserStore($dir))->find('reader');
            self::assertSame([self::A => ['status' => 'toread', 'star' => true]], $again->marks);
            self::assertSame($again->marks, $again->with(displayName: 'Reader')->marks, 'another change keeps them');
            self::assertSame($again->marks, $again->narrowedTo(['library'])->marks);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
