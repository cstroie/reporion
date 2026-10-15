<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Support;

use PHPUnit\Framework\TestCase;
use Reporion\Support\ExamTemplates;

/**
 * Which template each exam was made from (D48): an exam's own, never another's.
 */
final class ExamTemplatesTest extends TestCase
{
    public function testEachExamHasItsOwnTemplate(): void
    {
        $fm = ['exams' => [['title' => 'A', 'template' => 'templates:mri:a'], ['title' => 'B', 'template' => 'templates:mri:b']]];

        self::assertSame([0 => ['A', 'templates:mri:a'], 1 => ['B', 'templates:mri:b']], ExamTemplates::of($fm));
    }

    public function testAnExamWithoutOneHasNoneWhateverTheTopLevelSays(): void
    {
        // An older save: the first exam's template copied to the top level
        $fm = ['template' => 'templates:mri:a', 'exams' => [['title' => 'A', 'template' => 'templates:mri:a'], ['title' => 'B']]];

        self::assertSame([0 => ['A', 'templates:mri:a'], 1 => ['B', '']], ExamTemplates::of($fm));
    }

    public function testAnOlderReportWithoutExamsIsOneExam(): void
    {
        self::assertSame([0 => ['IRM genunchi', 'templates:mri:g']], ExamTemplates::of(['exam_title' => 'IRM genunchi', 'template' => 'templates:mri:g']));
        self::assertSame([0 => ['', '']], ExamTemplates::of([]));
    }

    public function testAReportOfOneExamStillTakesTheTopLevelTemplateOfAnOlderSave(): void
    {
        self::assertSame([0 => ['A', 'templates:mri:a']], ExamTemplates::of(['template' => 'templates:mri:a', 'exams' => [['title' => 'A']]]));
    }
}
