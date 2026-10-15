<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\Signing;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * What blocks signing (D7: never saving) reads a report in every shape
 * (D48): the report's modality and day at the top, each exam's own region
 * and fields on the exam. A report of one exam names a missing field plainly,
 * one of several by exam.
 */
final class SigningMissingTest extends StorageTestCase
{
    private FlatFile $storage;
    private Signing $signing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
        $this->signing = new Signing($this->storage, new Loader(\dirname(__DIR__, 2) . '/resources/schema'), new AuditLog($this->dataRoot . '/audit'));
    }

    private function missing(array $frontmatter, string $body): array
    {
        $path = 'reports:mri:mioveni:260927-test-semnare';
        $this->storage->create($path, ['title' => 'TEST Semnare', 'visibility' => 'private', 'site' => 'mioveni', 'summary' => 's', 'indication' => 'Cefalee.', 'patient' => ['name' => 'TEST Semnare']] + $frontmatter, $body, 'owner');

        return $this->signing->missing($this->storage->read($path));
    }

    public function testAReportOfTheCurrentShapeWithItsRegionOnTheExamCanBeSigned(): void
    {
        $missing = $this->missing(
            ['modality' => ['MR'], 'study_date' => '2026-09-27', 'exams' => [['title' => 'IRM cerebral', 'time' => '09:00', 'region' => ['neuro'], 'template' => 'templates:mri:cerebral']]],
            "# TEST Semnare\n\n## IRM cerebral\n\n### Concluzii\n\nNormal.\n"
        );

        self::assertSame([], $missing, 'no top-level region, and none is asked for');
    }

    public function testAnExamWithoutARegionIsNamedPlainlyOnASingleExamReport(): void
    {
        $missing = $this->missing(
            ['modality' => ['MR'], 'study_date' => '2026-09-27', 'exams' => [['title' => 'IRM cerebral', 'template' => 'templates:mri:cerebral']]],
            "# TEST Semnare\n\n## IRM cerebral\n\n### Concluzii\n\nNormal.\n"
        );

        self::assertSame(['region'], $missing);
    }

    public function testAMissingRegionOnTheSecondExamIsNamedByExam(): void
    {
        $missing = $this->missing(
            ['modality' => ['MR'], 'study_date' => '2026-09-27', 'exams' => [
                ['title' => 'A', 'region' => ['msk'], 'template' => 'templates:mri:a'],
                ['title' => 'B', 'template' => 'templates:mri:b'],
            ]],
            "# TEST Semnare\n\n## A\n\n### Concluzii\n\nX.\n\n## B\n\n### Concluzii\n\nY.\n"
        );

        self::assertSame(['exams.2.region'], $missing);
    }

    public function testTheReportsModalityAndDayAreReadWhereverAnOlderShapeKeptThem(): void
    {
        $missing = $this->missing(
            ['exams' => [['title' => 'IRM', 'modality' => 'MR', 'region' => ['msk'], 'study_date' => '2026-09-27T09:00:00+03:00']]],
            "# TEST Semnare\n\n## IRM\n\n### Concluzii\n\nNormal.\n"
        );

        self::assertSame([], $missing, 'a modality and a day on the exam still count for the report');
    }

    public function testNoModalityOrDayIsMissingForTheReport(): void
    {
        $missing = $this->missing(
            ['exams' => [['title' => 'IRM', 'region' => ['msk']]]],
            "# TEST Semnare\n\n## IRM\n\n### Concluzii\n\nNormal.\n"
        );

        self::assertContains('modality', $missing);
        self::assertContains('study_date', $missing);
    }
}
