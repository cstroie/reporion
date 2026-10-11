<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use PHPUnit\Framework\TestCase;
use Reporion\Service\Maintenance\MaintenanceReport;

final class MaintenanceReportTest extends TestCase
{
    /** @dataProvider failing */
    public function testARunWithFailedOrUnreadableItemsIsNotOk(string $key): void
    {
        $report = new MaintenanceReport('t', 'apply', 'a', [], '2026-01-01T00:00:00+00:00');
        $report->count($key);

        self::assertSame(1, $report->finish()->exit());
    }

    /** @return list<array{string}> */
    public static function failing(): array
    {
        return [['failed'], ['error'], ['unreadable']];
    }

    public function testACleanRunStaysOk(): void
    {
        $report = new MaintenanceReport('t', 'apply', 'a', [], '2026-01-01T00:00:00+00:00');
        $report->count('failed', 0);
        $report->count('done');

        self::assertSame(0, $report->finish()->exit());
    }
}
