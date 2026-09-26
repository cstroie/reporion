<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Reporion\Auth\User;
use Reporion\Index\IndexInterface;

/**
 * One patient's studies the caller can read, newest first — the patient
 * timeline (`/{path}/timeline`) and the editor's Insert prior study
 * (phase 10). Matched on the page's strong patient key (sha256 of the CNP),
 * else its weak one (D11); the index query applies visibility and grants
 * (invariant 6), so nothing here filters after the fact.
 */
final class PatientStudies
{
    public function __construct(
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * @param array<string, mixed> $indexed the page's own index row (findByPath())
     *
     * @return list<array<string, mixed>>
     */
    public function forRow(array $indexed, ?User $principal): array
    {
        $strong = (string) ($indexed['patient_key'] ?? '');
        if ($strong !== '') {
            return $this->index->findByPatientKey($strong, $principal);
        }
        $weak = (string) ($indexed['patient_key_weak'] ?? '');

        return $weak !== '' ? $this->index->findByPatientKey($weak, $principal) : [];
    }
}
