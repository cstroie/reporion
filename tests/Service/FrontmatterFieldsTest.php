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
 * the schema, an "extra" bucket for everything else, and the reverse —
 * `changesFrom()` — which never touches a field the form did not render.
 */
final class FrontmatterFieldsTest extends FrontmatterFieldsTestCase
{
    public function testANonReportPageGetsOnlyTheBaseFields(): void
    {
        $this->storage->create('docs:protocol', ['title' => 'Protocol v3', 'visibility' => 'public', 'tags' => ['x'], 'custom_key' => 'kept'], 'body', 'owner');
        $page = $this->storage->read('docs:protocol');

        $result = $this->fields->forPage('docs:protocol', $page->frontmatter, null);

        // TODO 13: 'template' means nothing on a non-report page, so it is
        // a report-only field now, not curated here
        self::assertSame(['title', 'tags', 'summary'], array_column($result['fields'], 'key'));
        self::assertNull($result['patient']);
        self::assertNull($result['accession']);
        self::assertSame('public', $result['visibility']);
        self::assertSame(['custom_key' => 'kept'], $result['extra'], 'a field with no picker is never dropped, just listed');
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
        self::assertContains('modality', $keys);
        self::assertContains('indication', $keys, 'the MR schema declares it');
        self::assertNotNull($result['patient']);
        self::assertSame('1980', $result['patient'][1]['value'], 'born');
        self::assertSame('MV-MR-26-0001', $result['accession']);
        self::assertSame('private', $result['visibility']);
        self::assertArrayNotHasKey('exams', $result['extra']);
        self::assertArrayNotHasKey('visibility', $result['extra'], 'shown separately, not as extra');
        self::assertArrayNotHasKey('status', $result['extra']);
        self::assertArrayNotHasKey('pid', $result['extra']);
        self::assertArrayNotHasKey('accession', $result['extra'], 'shown separately, not as extra');

        $modality = $result['fields'][array_search('modality', $keys, true)];
        self::assertSame('checkboxes', $modality['widget']);
        self::assertSame(['MR'], $modality['value']);
        self::assertContains(['value' => 'CT', 'label' => 'CT'], $modality['options']);

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

    public function testTagsSplitsOnCommasAndModalitySplitsOnCheckboxValues(): void
    {
        $changes = $this->fields->changesFrom(
            ['tags' => ' a , b ,, c', 'modality' => ['MR', 'CT']],
            ['tags', 'modality'],
            [],
            self::PATH
        );

        self::assertSame(['a', 'b', 'c'], $changes['tags']);
        self::assertSame(['MR', 'CT'], $changes['modality']);
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
