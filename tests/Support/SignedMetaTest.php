<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\SignedMeta;

final class SignedMetaTest extends TestCase
{
    private const BASE = [
        'modality' => ['MR'],
        'patient' => ['sex' => 'F', 'born' => 1970],
        'exams' => [['title' => 'RM cerebral', 'region' => ['cap']]],
    ];

    public function testOnlyTheAllowlistedKeysThatChangedAreNamed(): void
    {
        $new = self::BASE + ['summary' => 'Normal.', 'tags' => ['normal'], 'priors' => ['reports:x']];
        $new['exams'][0]['study_uid'] = '1.2.3';
        $new['exams'][0]['order_ref'] = 'O-1';

        self::assertSame(['summary', 'tags', 'priors', 'exams.study_uid', 'exams.order_ref'], SignedMeta::changedKeys(self::BASE, $new));
        self::assertSame([], SignedMeta::changedKeys(self::BASE, self::BASE));
    }

    public function testBlanksAndKeyOrderAreNoChange(): void
    {
        $new = ['summary' => '', 'tags' => [], 'exams' => [['region' => ['cap'], 'title' => 'RM cerebral']], 'patient' => ['born' => 1970, 'sex' => 'F'], 'modality' => ['MR']];

        self::assertSame([], SignedMeta::changedKeys(self::BASE, $new));
    }

    public function testAnythingThatPrintsIsRefused(): void
    {
        foreach ([
            ['patient' => ['sex' => 'F', 'born' => 1970, 'cnp' => '1']],
            ['exams' => [['title' => 'RM cerebral', 'region' => ['cap'], 'pacs_accession' => 'A1']]],
            ['exams' => [['title' => 'RM cerebral', 'region' => ['cap']], ['title' => 'RM cervical']]],
            ['study_date' => '2026-11-20'],
            ['device' => 'mri1'],
        ] as $change) {
            self::assertNull(SignedMeta::changedKeys(self::BASE, $change + self::BASE), json_encode($change) ?: '');
        }
    }
}
