<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Maintenance;

use Reporion\Audit\AuditLog;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\Maintenance\LibraryNoticesTask;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** The sources are made up (docs/library.md) */
final class LibraryNoticesTaskTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    private int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        foreach (['aa', 'bb', 'cc'] as $i => $key) {
            $this->storage->create('library:' . $key, ['title' => 'Made-up study ' . $key, 'visibility' => 'private', 'csl' => ['title' => 'Made-up study ' . $key, 'DOI' => '10.9999/' . $key]], '', 'owner', 'x');
        }
        $this->storage->create('library:nodoi', ['title' => 'No identifier', 'visibility' => 'private', 'csl' => ['title' => 'No identifier']], '', 'owner', 'x');
    }

    private function task(bool $lookups = true): LibraryNoticesTask
    {
        $lookup = $lookups ? new Lookup(function (string $url): ?string {
            ++$this->asked;

            return str_contains($url, 'crossref') ? (str_contains($url, '10.9999%2Fbb')
                ? '{"message":{"type":"journal-article","title":["B"],"updated-by":[{"DOI":"10.9999/r","type":"retraction","updated":{"date-parts":[[2025,1,1]]}}]}}'
                : '{"message":{"type":"journal-article","title":["X"]}}') : '{"esearchresult":{"idlist":[]}}';
        }) : null;

        return new LibraryNoticesTask(new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, $lookup, audit: new AuditLog($this->dataRoot . '/audit')), $this->dataRoot . '/maintenance/library-notices.json');
    }

    public function testACheckSaysWhatWouldChangeAndWritesNothing(): void
    {
        $task = $this->task();

        $report = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));

        self::assertSame(1, $report->summary()['would_flag']);
        self::assertSame(1, $report->summary()['no_id']);
        self::assertArrayNotHasKey('notice', $this->storage->read('library:bb')->frontmatter);
        self::assertFileDoesNotExist($this->dataRoot . '/maintenance/library-notices.json');
    }

    public function testTheRunFlagsAsTheLibrarianAndAFreshAnswerIsNotAskedAgain(): void
    {
        $task = $this->task();

        $run = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));

        self::assertSame(1, $run->summary()['flagged']);
        $page = $this->storage->read('library:bb');
        self::assertSame('retracted', $page->frontmatter['notice']['status']);
        self::assertSame('system:librarian', ($page->revlog[\count($page->revlog) - 1]['by'] ?? null));

        // The pseudouser's revision is audited with the person who ran it (D41)
        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertMatchesRegularExpression('/"actor":"system:librarian","action":"page.save".*"with":"notice","status":"retracted","on_behalf_of":"owner"/', $audit);
        self::assertSame(1, substr_count($audit, '"with":"notice"'));

        $asked = $this->asked;
        $again = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame($asked, $this->asked, 'asked within 30 days: passed over');
        self::assertSame(4, $again->summary()['recent'], 'the one with no identifier is remembered too');

        $all = $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['all' => '1']));
        self::assertGreaterThan($asked, $this->asked);
        self::assertSame(3, $all->summary()['unchanged'], 'all three answered again, the retracted one as kept');
        self::assertSame(0, $all->summary()['recent']);
    }

    public function testALimitBoundsTheRequestsAndTheNextRunCarriesOn(): void
    {
        $task = $this->task();

        $first = $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['limit' => '2']));
        self::assertSame(2, $first->summary()['remaining']);
        $second = $task->run(MaintenanceTask::APPLY, 'owner', $task->options(['limit' => '2']));

        self::assertSame(2, $second->summary()['recent'], 'the two asked before are passed over');
        self::assertArrayHasKey('notice', $this->storage->read('library:bb')->frontmatter);
    }

    public function testWithTheLookupsOffNothingIsAskedAndTheReportSaysWhy(): void
    {
        $task = $this->task(false);

        $report = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));

        self::assertSame(0, $this->asked);
        self::assertNotSame([], $report->notes());
    }
}
