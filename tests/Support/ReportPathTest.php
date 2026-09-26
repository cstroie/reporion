<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ReportPath;

final class ReportPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'a report' => ['reports:mri:mioveni:260926-popescu-ana-maria', true];
        yield 'its collision form' => ['reports:mri:mioveni:260926-popescu-ana-maria-2', true];
        yield 'a report straight under reports:' => ['reports:250101-test-subject', true];
        yield 'a site description page' => ['reports:mri:mioveni', false];
        yield 'a namespace index page' => ['reports:mri:_index', false];
        yield 'a modality overview' => ['reports:mri:overview-2026', false];
        yield 'a report name outside reports:' => ['docs:260926-popescu-ana-maria', false];
        yield 'a template' => ['templates:mri:cerebral', false];
        yield 'five digits' => ['reports:mri:x:26092-popescu', false];
        yield 'digits only' => ['reports:mri:x:260926', false];
    }

    /**
     * @dataProvider paths
     */
    public function testIsReport(string $path, bool $expected): void
    {
        self::assertSame($expected, ReportPath::isReport($path));
    }

    public function testAReportNameAnywhereStillLooksLikeOne(): void
    {
        self::assertTrue(ReportPath::looksLikeReportName('260926-popescu-ana-maria'));
        self::assertFalse(ReportPath::looksLikeReportName('cerebral-normal'));
    }
}
