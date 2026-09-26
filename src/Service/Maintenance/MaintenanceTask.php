<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

/**
 * One maintenance job the owner can run from Admin → Maintenance and an
 * operator from bin/reporion — the same code either way. `check` only
 * reads; `apply` writes and runs under the maintenance lock
 * (MaintenanceRunner). Options are typed per task, never free-form
 * command-line arguments.
 */
interface MaintenanceTask
{
    public const CHECK = 'check';
    public const APPLY = 'apply';

    /** The command name, e.g. "journal:replay" */
    public function name(): string;

    /** @return list<string> the modes this task supports (CHECK, APPLY) */
    public function modes(): array;

    /**
     * @param array<string, int|bool> $options already validated by options()
     */
    public function run(string $mode, string $actor, array $options): MaintenanceReport;

    /**
     * Typed options: raw values (a form, argv) in, validated values out,
     * defaults filled in.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, int|bool>
     */
    public function options(array $raw): array;
}
