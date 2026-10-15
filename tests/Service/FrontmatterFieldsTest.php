<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use Reporion\Index\Sqlite;
use Reporion\Schema\Loader;
use Reporion\Service\FrontmatterFields;
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * The editor's Details panel (phase 14): a curated field list built from
 * the schema, an "extra" bucket for everything else, and the reverse
 * `changesFrom()`, which never touches a field the form did not render.
 */
final class FrontmatterFieldsTest extends FrontmatterFieldsTestCase
{
    public function testANonReportPageGetsOnlyTheBaseFields(): void
    {
        $this->storage->create('docs:protocol', ['title' => 'Protocol v3', 'visibility' => 'public', 'tags' => ['x'], 'custom_key' => 'kept'], 'body', 'owner');
        $page = $this->storage->read('docs:protocol');

        $result = $this->fields->forPage('docs:protocol', $page->frontmatter, null);

        // TODO 13: 'template' means nothing on a non-report page, so it is
        // a report-only field now, not curated here; 'priority' is the
        // mirror image: curated only on a non-report page (the
        // sub-namespace card tint), meaningless on a report
        self::assertSame(['title', 'tags', 'summary', 'format', 'priority'], array_column($result['fields'], 'key'));
        self::assertNull($result['patient']);
        self::assertNull($result['accession']);
        self::assertSame('public', $result['visibility']);
        self::assertSame(['custom_key' => 'kept'], $result['extra'], 'a field with no picker is never dropped, just listed');
    }

    public function testExamsDeclaredOnlyForAnExamsListOnAReport(): void
    {
        $path = 'reports:mri:mioveni:260101-test';

        self::assertFalse($this->fields->forPage($path, ['modality' => ['mri']], null)['examsDeclared'], 'a lone exam is no declared list');
        self::assertFalse($this->fields->forPage($path, ['exams' => [['title' => 'A']]], null)['examsDeclared'], 'one exam is a single-exam report (Exams::declared)');
        self::assertTrue($this->fields->forPage($path, ['exams' => [['title' => 'A'], ['title' => 'B']]], null)['examsDeclared']);
        self::assertFalse($this->fields->forPage('docs:protocol', ['exams' => [['title' => 'A'], ['title' => 'B']]], null)['examsDeclared'], 'not a report');
    }

    /**
     * 'priority' (TODO 13) is a select with a fixed low/medium/high
     * option list, not schema-driven like 'site'/'device'/'template':
     * and round-trips through changesFrom() like any other select.
     */
    public function testPriorityIsASelectWithLowMediumHighOptions(): void
    {
        $this->storage->create('docs:protocol', ['title' => 'Protocol v3', 'visibility' => 'public', 'priority' => 'high'], 'body', 'owner');
        $page = $this->storage->read('docs:protocol');

        $result = $this->fields->forPage('docs:protocol', $page->frontmatter, null);
        $priority = array_values(array_filter($result['fields'], static fn (array $f): bool => $f['key'] === 'priority'))[0];

        self::assertSame('select', $priority['widget']);
        self::assertSame('high', $priority['value']);
        self::assertSame(['', 'low', 'medium', 'high'], array_column($priority['options'], 'value'));

        $changes = $this->fields->changesFrom(['priority' => 'medium'], ['priority'], $page->frontmatter, 'docs:protocol');
        self::assertSame('medium', $changes['priority']);

        $cleared = $this->fields->changesFrom(['priority' => ''], ['priority'], $page->frontmatter, 'docs:protocol');
        self::assertNull($cleared['priority']);
    }

    public function testAReportGetsTheReportFieldsPatientAndAccession(): void
    {
        $fm = [
            'title' => 'POPESCU Ana', 'visibility' => 'private', 'modality' => ['MR'], 'region' => ['msk'],
            'site' => 'mioveni', 'device' => 'MV-MR-01', 'study_date' => '2026-09-27', 'accession' => 'MV-MR-26-0001',
            'patient' => ['name' => 'POPESCU Ana', 'born' => 1980, 'sex' => 'F'],
            'exams' => ['should not appear'], 'status' => 'draft', 'pid' => 'x',
        ];
        $this->storage->create(self::PATH, $fm, 'body', 'owner');
        $page = $this->storage->read(self::PATH);

        $result = $this->fields->forPage(self::PATH, $page->frontmatter, null);

        $keys = array_column($result['fields'], 'key');
        foreach (['modality', 'study_date', 'device'] as $shared) {
            self::assertContains($shared, $keys, $shared . ' is the report\'s, shared by its exams');
        }
        $report = array_column($result['fields'], null, 'key');
        self::assertSame('MR', $report['modality']['value']);
        self::assertSame('2026-09-27', $report['study_date']['value']);
        self::assertContains('indication', $keys, 'the MR schema declares it');
        self::assertNotNull($result['patient']);
        self::assertSame('1980', $result['patient'][1]['value'], 'born');
        self::assertSame('private', $result['visibility']);
        self::assertArrayNotHasKey('exams', $result['extra']);
        self::assertArrayNotHasKey('visibility', $result['extra'], 'shown separately, not as extra');
        self::assertArrayNotHasKey('status', $result['extra']);
        self::assertArrayNotHasKey('pid', $result['extra']);
        self::assertArrayNotHasKey('accession', $result['extra'], 'shown separately, not as extra');

        self::assertCount(1, $result['exams'], 'one card for its one exam');
        $card = array_column($result['exams'][0]['fields'], null, 'key');
        self::assertArrayNotHasKey('exams.0.modality', $card, 'an exam has no modality of its own');
        self::assertArrayNotHasKey('exams.0.device', $card);
        self::assertSame('time', $card['exams.0.time']['widget']);
        self::assertContains(['value' => 'CT', 'label' => 'CT'], $report['modality']['options']);
        self::assertSame(['msk'], $card['exams.0.region']['value']);
        self::assertSame('MV-MR-26-0001', $result['exams'][0]['accession']);

        $site = $result['fields'][array_search('site', $keys, true)];
        self::assertSame([['value' => 'mioveni', 'label' => 'Mioveni']], $site['options']);
    }

    public function testChangesFromOnlyTouchesShownFields(): void
    {
        $current = ['title' => 'Old', 'visibility' => 'private', 'summary' => 'kept', 'custom_key' => 'kept'];

        $changes = $this->fields->changesFrom(['title' => 'New'], ['title'], $current, 'docs:protocol');

        self::assertSame(['title' => 'New'], $changes, 'summary was not shown, so absent from changes entirely');
    }

    public function testAShownButEmptyFieldClearsIt(): void
    {
        $changes = $this->fields->changesFrom(['tags' => ''], ['tags'], ['tags' => ['a']], 'docs:protocol');

        self::assertSame(['tags' => null], $changes);
    }

    public function testAnExamsTimeIsHourAndMinuteOrNothingAndTheSharedFieldsAreTheReports(): void
    {
        $current = ['modality' => ['MR'], 'exams' => [['title' => 'A', 'template' => 'templates:mri:a', 'time' => '09:00']]];

        $changes = $this->fields->changesFrom(
            ['modality' => 'CT', 'exam_order' => ['0', 'n1'], 'exams' => ['0' => ['time' => '9:05'], 'n1' => ['time' => '25:99', 'title' => 'B']]],
            ['modality', 'exam_order', 'exams.0.time', 'exams.n1.time', 'exams.n1.title'],
            $current,
            self::PATH
        );

        self::assertSame('CT', $changes['modality']);
        self::assertSame('09:05', $changes['exams'][0]['time']);
        self::assertSame('templates:mri:a', $changes['exams'][0]['template'], 'an exam keeps its own template when its card does not post one');
        self::assertArrayNotHasKey('time', $changes['exams'][1], 'a malformed time is dropped');
    }

    public function testTagsSplitsOnCommasAndRegionsOnCheckboxValues(): void
    {
        $changes = $this->fields->changesFrom(
            ['tags' => ' a , b ,, c', 'exam_order' => ['0'], 'exams' => ['0' => ['region' => ['msk', 'spine']]]],
            ['tags', 'exam_order', 'exams.0.region'],
            [],
            self::PATH
        );

        self::assertSame(['a', 'b', 'c'], $changes['tags']);
        self::assertSame([['region' => ['msk', 'spine']]], $changes['exams'], 'an exam card posts its own fields');
    }

    public function testPatientSubFieldsMergeByIdentityKeepingUnshownOnesAndClearingEmptyOnes(): void
    {
        $current = ['patient' => ['name' => 'X', 'born' => 1980, 'cnp' => '123']];

        $changes = $this->fields->changesFrom(
            ['patient' => ['name' => 'Y', 'cnp' => '']],
            ['patient.name', 'patient.cnp'],
            $current,
            self::PATH
        );

        self::assertSame(['name' => 'Y', 'born' => 1980], $changes['patient'], 'sex/born untouched, name updated, cnp cleared');
    }

    public function testPatientIsNullWhenEveryShownSubFieldIsCleared(): void
    {
        $current = ['patient' => ['name' => 'X']];

        $changes = $this->fields->changesFrom(['patient' => ['name' => '']], ['patient.name'], $current, self::PATH);

        self::assertSame(['patient' => null], $changes);
    }

    public function testNoPatientKeyAtAllWhenNoPatientSubFieldWasShown(): void
    {
        $changes = $this->fields->changesFrom(['title' => 'X'], ['title'], ['patient' => ['name' => 'X']], self::PATH);

        self::assertArrayNotHasKey('patient', $changes);
    }
}
