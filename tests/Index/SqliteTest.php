<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Index;

use Reporion\Support\PatientKey;

final class SqliteTest extends IndexTestCase
{
    public function testIndexInsertsPageRowWithFacetsAndPatientKeys(): void
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot(
            '01JB8X4MT7QK2V9Z0C3R5H6ND',
            'reports:mri:mioveni:260922-x',
            [
                'title' => 'RM cerebral nativ',
                'site' => 'mioveni',
                'device' => 'MV-MR-01',
                'accession' => 'MV-RM-26-0918',
                'study_date' => '2026-09-22T09:14:00+03:00',
                'protocol' => 'brain-demyelination-v3',
                'summary' => 'Stabil fata de examinarea anterioara.',
                'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'],
            ],
        ));

        $row = $this->fetchOne($path, "SELECT * FROM pages WHERE pid = '01JB8X4MT7QK2V9Z0C3R5H6ND'");

        self::assertSame('reports:mri:mioveni:260922-x', $row['path']);
        self::assertSame('reports:mri:mioveni', $row['ns']);
        self::assertSame('RM cerebral nativ', $row['title']);
        self::assertSame('mioveni', $row['site']);
        self::assertSame('MV-RM-26-0918', $row['accession']);
        self::assertSame(hash('sha256', '2740101123456'), $row['patient_key']);
        self::assertSame(PatientKey::weak('Ionescu Maria', 1974, 'F'), $row['patient_key_weak']);
    }

    /**
     * D29 — modality/region are lists, not scalars.
     */
    public function testIndexPopulatesModalityAndRegionChildTables(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:ct:mioveni:x', [
            'modality' => ['CT'],
            'region' => ['cerebral', 'cervical'],
        ]));

        $modalities = $this->fetchColumn($path, "SELECT modality FROM page_modalities WHERE pid = 'p1'");
        $regions = $this->fetchColumn($path, "SELECT region FROM page_regions WHERE pid = 'p1'");
        sort($regions);

        self::assertSame(['CT'], $modalities);
        self::assertSame(['cerebral', 'cervical'], $regions);
    }

    public function testIndexIsFullTextSearchable(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', ['title' => 'RM cerebral'], 'Fara leziuni demielinizante.'));

        $hit = $this->fetchColumn($path, "SELECT pid FROM fts WHERE fts MATCH 'demielinizante'");

        self::assertSame(['p1'], $hit);
    }

    public function testReindexingSamePidReplacesRowsRatherThanDuplicating(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', [
            'modality' => ['MR'],
            'tags' => ['demielinizare'],
        ], 'first body text', ['rev' => 1]));

        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', [
            'modality' => ['CT'],
            'tags' => ['follow-up'],
        ], 'second body text', ['rev' => 2]));

        self::assertSame([1], $this->fetchColumn($path, 'SELECT count(*) FROM pages'));
        self::assertSame(['CT'], $this->fetchColumn($path, "SELECT modality FROM page_modalities WHERE pid = 'p1'"));
        self::assertSame(['follow-up'], $this->fetchColumn($path, "SELECT tag FROM page_tags WHERE pid = 'p1'"));

        // Stale fts text must be gone, new text must be findable.
        self::assertSame([], $this->fetchColumn($path, "SELECT pid FROM fts WHERE fts MATCH 'first'"));
        self::assertSame(['p1'], $this->fetchColumn($path, "SELECT pid FROM fts WHERE fts MATCH 'second'"));
    }

    public function testRemoveDeletesPageChildRowsAndFtsEntry(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', [
            'modality' => ['MR'],
            'tags' => ['demielinizare'],
        ], 'searchable body'));

        $index->remove('p1');

        self::assertSame([0], $this->fetchColumn($path, 'SELECT count(*) FROM pages'));
        self::assertSame([0], $this->fetchColumn($path, "SELECT count(*) FROM page_modalities WHERE pid = 'p1'"));
        self::assertSame([0], $this->fetchColumn($path, "SELECT count(*) FROM page_tags WHERE pid = 'p1'"));
        self::assertSame([], $this->fetchColumn($path, "SELECT pid FROM fts WHERE fts MATCH 'searchable'"));
    }

    /**
     * The safety net for the whole cache-is-disposable claim: rebuilding
     * from a stream of snapshots must reproduce exactly the same rows as
     * indexing them one at a time — regardless of the order snapshots
     * arrive in, which is why the two passes deliberately use different
     * orders here. (`links` is asserted separately below: it is a known
     * exception to this equivalence, not covered by this test.)
     */
    public function testRebuildIsEquivalentToIncrementalIndexing(): void
    {
        $snapshots = [
            $this->snapshot('p1', 'reports:mri:mioveni:a', ['modality' => ['MR'], 'region' => ['neuro'], 'tags' => ['t1']], 'body one'),
            $this->snapshot('p2', 'reports:ct:mioveni:b', ['modality' => ['CT'], 'region' => ['abdomen'], 'tags' => ['t2']], 'body two'),
            $this->snapshot('p3', 'reports:mri:mioveni:c', ['modality' => ['MR', 'CT']], 'body three'),
        ];

        [$incremental, $incrementalPath] = $this->newIndex();
        foreach ($snapshots as $snapshot) {
            $incremental->index($snapshot);
        }

        [$rebuilt, $rebuiltPath] = $this->newIndex();
        $rebuilt->rebuild(array_reverse($snapshots));

        $pageColumns = 'pid, path, ns, title, rev, status, visibility, site, device, accession, '
            . 'study_date, protocol, summary, patient_key, patient_key_weak, bytes, mtime, body_sha, meta_json';

        self::assertSame(
            $this->fetchAll($incrementalPath, "SELECT {$pageColumns} FROM pages ORDER BY pid"),
            $this->fetchAll($rebuiltPath, "SELECT {$pageColumns} FROM pages ORDER BY pid")
        );
        self::assertSame(
            $this->fetchAll($incrementalPath, 'SELECT pid, modality FROM page_modalities ORDER BY pid, modality'),
            $this->fetchAll($rebuiltPath, 'SELECT pid, modality FROM page_modalities ORDER BY pid, modality')
        );
        self::assertSame(
            $this->fetchAll($incrementalPath, 'SELECT pid, region FROM page_regions ORDER BY pid, region'),
            $this->fetchAll($rebuiltPath, 'SELECT pid, region FROM page_regions ORDER BY pid, region')
        );
    }

    /**
     * Known limitation, documented rather than silently passed over:
     * `links.dst_pid` is resolved by looking up `pages` at index() time, so
     * a "prior" pointing at a page that has not been indexed yet resolves to
     * NULL — the schema's own definition of a broken link — and nothing
     * re-resolves it once the target does get indexed. A forward reference
     * in a rebuild (order not guaranteed) or an import batch can therefore
     * leave a permanent false-positive broken-link row. Fixing this needs a
     * link back-resolution pass and is out of scope here.
     */
    public function testForwardPriorReferenceResolvesToNullDstPid(): void
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', ['priors' => ['reports:mri:mioveni:b']]));
        $index->index($this->snapshot('p2', 'reports:mri:mioveni:b'));

        $row = $this->fetchOne($path, "SELECT dst_path, dst_pid FROM links WHERE src = 'p1' AND kind = 'prior'");

        self::assertSame('reports:mri:mioveni:b', $row['dst_path']);
        self::assertNull($row['dst_pid']);
    }

    public function testVerifyReportsOrphansMissingAndDrifted(): void
    {
        [$index] = $this->newIndex();
        $index->index($this->snapshot('kept', 'reports:mri:mioveni:kept', [], 'body', ['bytes' => 100, 'mtime' => 1000, 'bodySha' => 'aaa']));
        $index->index($this->snapshot('stale', 'reports:mri:mioveni:stale', [], 'body', ['bytes' => 100, 'mtime' => 1000, 'bodySha' => 'aaa']));
        $index->index($this->snapshot('drifted', 'reports:mri:mioveni:drifted', [], 'body', ['bytes' => 100, 'mtime' => 1000, 'bodySha' => 'aaa']));

        $report = $index->verify([
            ['pid' => 'kept', 'bytes' => 100, 'mtime' => 1000, 'bodySha' => 'aaa'],
            ['pid' => 'drifted', 'bytes' => 200, 'mtime' => 1000, 'bodySha' => 'aaa'],
            ['pid' => 'new-on-disk', 'bytes' => 50, 'mtime' => 900, 'bodySha' => 'zzz'],
        ]);

        self::assertSame(['stale'], $report['orphans']);
        self::assertSame(['new-on-disk'], $report['missing']);
        self::assertSame(['drifted'], $report['drifted']);
    }

    public function testReopeningAnExistingDatabaseDoesNotReapplyMigrations(): void
    {
        $path = sys_get_temp_dir() . '/reporion-index-test-' . bin2hex(random_bytes(6)) . '.sqlite';

        $first = new \Reporion\Index\Sqlite($path, $this->migrationsDir);
        $first->index($this->snapshot('p1', 'reports:mri:mioveni:x'));

        // A second Sqlite instance against the same file must not try to
        // CREATE TABLE again (which would throw) and must still see the row.
        $second = new \Reporion\Index\Sqlite($path, $this->migrationsDir);

        self::assertSame([1], $this->fetchColumn($path, 'SELECT count(*) FROM pages'));

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        unset($first, $second);
    }
}
