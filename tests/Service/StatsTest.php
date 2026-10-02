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
use Reporion\Storage\FlatFile;
use Reporion\Tests\Storage\StorageTestCase;

/**
 * Roadmap phase 24: the first signature reaches the index (24a) and /stats
 * counts over what the caller can list (24b), the start page card (24c).
 */
final class StatsTest extends StorageTestCase
{
    private Sqlite $index;
    private FlatFile $storage;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $this->storage = new FlatFile($this->dataRoot, $this->index);
        $this->now = new DateTimeImmutable();
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

        $mriOnly = new User('ed', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now');
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

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }
}
