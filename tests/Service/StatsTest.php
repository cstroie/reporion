<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service;

use DateTimeImmutable;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Service\Stats;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Roadmap phase 24: the first signature reaches the index (24a) and /stats
 * counts over what the caller can list (24b), the start page card (24c).
 */
final class StatsTest extends StorageTestCase
{
    private Sqlite $index;
    private FileTree $storage;
    private DateTimeImmutable $now;

    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        // Another test may have booted the kernel, which sets the default time zone
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->now = new DateTimeImmutable();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    public function testTheFirstSignatureIsIndexedAndKeptOnAResign(): void
    {
        $path = $this->report('mri:mioveni', 'a', 'MR', 'mioveni', 0);
        self::assertNull($this->index->findByPath($path, $this->owner())['signed_at']);

        $this->storage->sign($path, 'dr.a', []);
        $row = $this->index->findByPath($path, $this->owner());
        self::assertNotNull($row['signed_at']);
        self::assertSame('dr.a', $row['signed_by']);

        $this->storage->save($path, ['title' => 'TEST A', 'visibility' => 'private', 'modality' => ['MR'], 'site' => 'mioveni', 'study_date' => $this->now->format('Y-m-d')], "Text 2.\n", 1, 'dr.b');
        $this->storage->sign($path, 'dr.b', []);
        self::assertSame('dr.a', $this->index->findByPath($path, $this->owner())['signed_by'], 'turnaround counts from the first signature');
    }

    public function testAnIndexRowWithoutTheSignatureIsDrift(): void
    {
        $path = $this->report('mri:mioveni', 'a', 'MR', 'mioveni', 0);
        $this->storage->sign($path, 'dr.a', []);
        $pid = (string) $this->index->findByPath($path, $this->owner())['pid'];
        $snapshot = $this->storage->snapshotOf($path);
        $fact = ['pid' => $pid, 'bytes' => $snapshot->bytes, 'mtime' => $snapshot->mtime, 'bodySha' => $snapshot->bodySha, 'signedAt' => $snapshot->signedAt];

        self::assertSame([], $this->index->verify([$fact])['drifted']);
        (new \PDO('sqlite:' . $this->dataRoot . '/index.sqlite'))->exec('UPDATE pages SET signed_at = NULL');
        self::assertSame([$pid], $this->index->verify([$fact])['drifted'], 'indexed before migration 004: rebuild');
    }

    public function testCountsFollowTheCallersListing(): void
    {
        $mr = $this->report('mri:mioveni', 'a', 'MR', 'mioveni', 2);
        $this->report('mri:mioveni', 'b', 'MR', 'mioveni', 1);
        $ct = $this->report('ct:pitesti', 'c', 'CT', 'pitesti', 3);
        $this->storage->create('reports:mri:mioveni:overview', ['title' => 'Not a report', 'visibility' => 'private'], "x\n", 'owner');
        $this->storage->sign($mr, 'dr.a', []);
        $this->storage->sign($ct, 'dr.b', []);
        $stats = new Stats($this->index, 3);

        $all = $stats->compute($this->owner(), $this->now, 12);
        self::assertSame(3, array_sum(array_column($all['months'], 'exams')), 'the overview page is not a report');
        self::assertSame(2, $all['turnaround']['n']);
        self::assertSame(['draft' => 1, 'signed' => 2, 'archived' => 0], $all['status']);
        self::assertSame(['MR', 'CT'], array_column($all['modality'], 'key'));
        // Exam date at midnight → signed now: three days and part of today
        $pitesti = $all['site'][array_search('pitesti', array_column($all['site'], 'key'), true)]['median'];
        self::assertGreaterThanOrEqual(3.0, $pitesti);
        self::assertLessThan(4.0, $pitesti);

        $mriOnly = new User('ed', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now', radiologist: true);
        $mine = $stats->compute($mriOnly, $this->now, 12);
        self::assertSame(2, array_sum(array_column($mine['months'], 'exams')));
        self::assertSame(['dr.a'], array_column($mine['signer'], 'key'));

        $filtered = $stats->compute($this->owner(), $this->now, 12, ['modality' => 'CT']);
        self::assertSame(1, array_sum(array_column($filtered['months'], 'exams')));
        self::assertCount(12, $filtered['months']);

        $card = $stats->thisMonth($this->owner(), $this->now);
        self::assertSame(2, $card['signed']);
    }

    public function testStaleDraftsOldestFirst(): void
    {
        $this->report('mri:mioveni', 'a', 'MR', 'mioveni', 1);
        $stats = (new Stats($this->index, 0))->compute($this->owner(), $this->now->modify('+1 day'), 12);

        self::assertSame(1, $stats['staleTotal']);
        self::assertSame('reports:mri:mioveni:' . $this->now->format('ymd') . '-test-a', $stats['stale'][0]['path']);
    }

    public function testTurnaroundAndPercentiles(): void
    {
        self::assertEqualsWithDelta(1.5, Stats::turnaround('2026-09-01', '2026-09-02T12:00:00+00:00'), 0.05);
        self::assertNull(Stats::turnaround('2026-09-03', '2026-09-02T12:00:00+00:00'), 'signed before the exam date');
        self::assertNull(Stats::turnaround(null, '2026-09-02T12:00:00+00:00'));
        self::assertNull(Stats::turnaround('not a date', '2026-09-02T12:00:00+00:00'));
        self::assertSame(2.5, Stats::percentile([4.0, 1.0, 3.0, 2.0], 50));
        self::assertEqualsWithDelta(3.7, Stats::percentile([1.0, 2.0, 3.0, 4.0], 90), 0.001);
        self::assertNull(Stats::percentile([], 50));
    }

    /** A report whose exam was $daysAgo days ago */
    private function report(string $ns, string $name, string $modality, string $site, int $daysAgo): string
    {
        $path = 'reports:' . $ns . ':' . $this->now->format('ymd') . '-test-' . $name;
        $this->storage->create($path, [
            'title' => 'TEST ' . strtoupper($name), 'visibility' => 'private', 'modality' => [$modality], 'site' => $site,
            'study_date' => $this->now->modify('-' . $daysAgo . ' days')->format('Y-m-d'),
        ], "Text.\n", 'owner');

        return $path;
    }

    public function testInsightsFromRows(): void
    {
        // Wednesday 2026-11-18 15:00 UTC: 21 working days in November, 12 passed
        $now = new DateTimeImmutable('2026-11-18T15:00:00+00:00');
        $row = static fn (array $r): array => $r + ['path' => 'reports:ct:x', 'title' => 't', 'status' => 'signed', 'site' => 'A', 'study_date' => null, 'signed_at' => null, 'signed_by' => null, 'updated' => '2026-11-18T10:00:00+00:00', 'modalities' => ['CT']];
        $rows = [
            // This month: two mine (0.5 d and 2 d), one the team's (0.25 d)
            $row(['study_date' => '2026-11-16', 'signed_at' => '2026-11-16T12:00:00+00:00', 'signed_by' => 'me']),
            $row(['study_date' => '2026-11-10', 'signed_at' => '2026-11-12T00:00:00+00:00', 'signed_by' => 'me', 'modalities' => ['MRI'], 'site' => 'B']),
            $row(['study_date' => '2026-11-17', 'signed_at' => '2026-11-17T06:00:00+00:00', 'signed_by' => 'bob']),
            // October: 4 days, the months before
            $row(['study_date' => '2026-10-01', 'signed_at' => '2026-10-05T00:00:00+00:00', 'signed_by' => 'me']),
            // Drafts: one hour old, three days, 60 days
            $row(['status' => 'draft', 'updated' => '2026-11-18T14:00:00+00:00']),
            $row(['status' => 'draft', 'updated' => '2026-11-15T15:00:00+00:00']),
            $row(['status' => 'draft', 'updated' => '2026-09-19T15:00:00+00:00']),
        ];
        $ins = Stats::insightsFrom($rows, $now, 'me');

        self::assertCount(12, $ins['weeks']);
        self::assertSame(['week' => '2026-W47', 'mine' => 1, 'team' => 1], $ins['weeks'][11]);
        self::assertSame(['week' => '2026-W46', 'mine' => 1, 'team' => 0], $ins['weeks'][10]);
        self::assertSame(3, $ins['signed']);
        self::assertSame(2, $ins['mine']);
        self::assertEqualsWithDelta(2 / 3, $ins['share'], 1e-9);
        self::assertEqualsWithDelta(0.5, $ins['trend']['median'], 1e-9);
        self::assertEqualsWithDelta(4.0, $ins['trend']['before'], 1e-9);
        self::assertEqualsWithDelta(-0.875, $ins['trend']['change'], 1e-9);
        self::assertEqualsWithDelta(2 / 3, $ins['sameDay'], 1e-9);
        self::assertSame(['day' => 1, 'week' => 1, 'month' => 0, 'older' => 1], $ins['backlog']['buckets']);
        self::assertSame(3, $ins['backlog']['total']);
        self::assertEqualsWithDelta(60.0, $ins['backlog']['oldest'], 1e-9);
        // Exams this month: everything with a November study date, drafts have none
        self::assertSame(['key' => 'CT', 'n' => 2, 'share' => 2 / 3], $ins['modality'][0]);
        self::assertSame('A', $ins['site'][0]['key']);
        self::assertSame(5, $ins['pace']);
        self::assertNotNull($ins['weekday']);
    }

    public function testInsightsFromNothing(): void
    {
        $ins = Stats::insightsFrom([], new DateTimeImmutable('2026-11-18T15:00:00+00:00'), 'me');

        self::assertSame(0, $ins['signed']);
        self::assertNull($ins['share']);
        self::assertNull($ins['sameDay']);
        self::assertNull($ins['trend']['median']);
        self::assertNull($ins['trend']['change']);
        self::assertNull($ins['weekday']);
        self::assertNull($ins['backlog']['oldest']);
        self::assertSame([], $ins['modality']);
    }

    public function testPaceIsNullOnTheFirstWorkingDay(): void
    {
        // Monday 2026-06-01: no working day has ended yet
        self::assertNull(Stats::pace(4, new DateTimeImmutable('2026-06-01T10:00:00+00:00')));
        // Tuesday: one of 22 passed
        self::assertSame(88, Stats::pace(4, new DateTimeImmutable('2026-06-02T10:00:00+00:00')));
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
