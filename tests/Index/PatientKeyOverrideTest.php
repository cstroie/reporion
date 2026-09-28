<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Index;

use Reporion\Support\PatientKey;

/**
 * TODO 13, D11's escape hatch: an explicit `patient.key` on the page wins
 * over the cnp-derived key, so Service\PatientMerge confirming a possible
 * match (Controller\PatientMergeController) actually links the two pages
 * at the next index pass. Deleting the field goes back to the computed key
 * — no separate conf/patient_merges.json.
 */
final class PatientKeyOverrideTest extends IndexTestCase
{
    public function testAnExplicitKeyOverridesTheCnpDerivedOne(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', [
            'title' => 'Ionescu Maria',
            'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456', 'key' => 'shared-key'],
        ], 'body', ['visibility' => 'public']));

        $row = $this->fetchOne($path, "SELECT patient_key, patient_key_weak FROM pages WHERE pid = 'p1'");

        self::assertSame('shared-key', $row['patient_key']);
        self::assertSame(PatientKey::weak('Ionescu Maria', 1974, 'F'), $row['patient_key_weak'], 'the weak key is still computed, only the strong slot is overridden');
    }

    public function testTheOverrideLinksTwoOtherwiseUnmatchedPages(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', [
            'title' => 'Ionescu Maria',
            'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'],
        ], 'body a', ['visibility' => 'public']));
        // Confirmed as the same patient: given p1's own strong key
        $index->index($this->snapshot('p2', 'reports:ct:mioveni:b', [
            'title' => 'Maria Ionescu',
            'patient' => ['name' => 'Maria Ionescu', 'born' => 1975, 'sex' => 'F', 'key' => hash('sha256', '2740101123456')],
        ], 'body b', ['visibility' => 'public']));

        $linked = $index->findByPatientKey(hash('sha256', '2740101123456'), null);
        $pids = array_column($linked, 'pid');
        sort($pids);

        self::assertSame(['p1', 'p2'], $pids, 'both pages now resolve under the same strong key');
    }
}
