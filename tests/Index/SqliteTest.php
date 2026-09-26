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

    public function testIndexHandlesNestedArrayValuesInListFieldsWithoutWarnings(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:ct:mioveni:x', [
            'modality' => [['CT'], 'MR'],
            'region' => [['neuro'], 'spine'],
        ]));

        $modalities = $this->fetchColumn($path, "SELECT modality FROM page_modalities WHERE pid = 'p1'");
        $regions = $this->fetchColumn($path, "SELECT region FROM page_regions WHERE pid = 'p1'");
        sort($modalities);
        sort($regions);

        self::assertSame(['CT', 'MR'], $modalities);
        self::assertSame(['neuro', 'spine'], $regions);
    }

    public function testIndexIsFullTextSearchable(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', ['title' => 'RM cerebral'], 'Fara leziuni demielinizante.'));

        $hit = $this->fetchColumn($path, "SELECT pid FROM fts WHERE fts MATCH 'demielinizante'");

        self::assertSame(['p1'], $hit);
    }

    /**
     * The search results template shows modality per row (WikiSearch mockup);
     * it lives in the page_modalities child table (D29), not a plain column
     * on pages, so search() must join it back rather than leaving the
     * template with nothing to read.
     */
    public function testSearchReturnsCommaJoinedModalityFromTheChildTable(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:x', [
            'title' => 'RM cerebral',
            'modality' => ['MR', 'CT'],
        ], 'Fara leziuni demielinizante.', ['visibility' => 'public']));

        $results = $index->search('demielinizante', null);

        self::assertCount(1, $results);
        $modalities = explode(', ', $results[0]['modality']);
        sort($modalities);
        self::assertSame(['CT', 'MR'], $modalities);
    }

    /**
     * The namespace index template shows region per row (WikiNsIndex mockup);
     * it lives in the page_regions child table (D29), not a plain column on
     * pages, so listNamespace() must join it back the same way search()
     * joins modality.
     */
    public function testListNamespaceReturnsCommaJoinedRegionFromTheChildTable(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:ct:mioveni:x', [
            'region' => ['cerebral', 'cervical'],
        ], overrides: ['visibility' => 'public']));

        $rows = $index->listNamespace('reports:ct:mioveni', null);

        self::assertCount(1, $rows);
        $regions = explode(', ', $rows[0]['region']);
        sort($regions);
        self::assertSame(['cerebral', 'cervical'], $regions);
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
     * orders here — including links, whose targets arrive before or after
     * the page that links to them depending on the order.
     */
    public function testRebuildIsEquivalentToIncrementalIndexing(): void
    {
        $snapshots = [
            $this->snapshot('p1', 'reports:mri:mioveni:a', ['modality' => ['MR'], 'region' => ['neuro'], 'tags' => ['t1']], 'body one, see [c](reports:mri:mioveni:c)'),
            $this->snapshot('p2', 'reports:ct:mioveni:b', ['modality' => ['CT'], 'region' => ['abdomen'], 'tags' => ['t2'], 'priors' => ['reports:mri:mioveni:c']], 'body two [a](reports/mri/mioveni/a) [gone](x:y)'),
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
        $links = 'SELECT src, dst_path, dst_pid, kind FROM links ORDER BY src, kind, dst_path';
        self::assertSame($this->fetchAll($incrementalPath, $links), $this->fetchAll($rebuiltPath, $links));
        self::assertCount(4, $this->fetchAll($rebuiltPath, $links));
        self::assertSame([['dst_path' => 'x:y']], $this->fetchAll($rebuiltPath, 'SELECT dst_path FROM links WHERE dst_pid IS NULL'), 'only the link to a page that does not exist is broken');
    }

    /**
     * A link to a page not indexed yet — a forward reference in a rebuild,
     * an import batch, or a link written before its target was created —
     * resolves once the target is written. (It used to stay NULL, a
     * permanent false broken link.)
     */
    public function testAForwardReferenceResolvesWhenItsTargetIsIndexed(): void
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', ['priors' => ['reports:mri:mioveni:b']], 'see [b](reports:mri:mioveni:b)'));
        self::assertSame([null, null], $this->fetchColumn($path, "SELECT dst_pid FROM links WHERE src = 'p1'"));

        $index->index($this->snapshot('p2', 'reports:mri:mioveni:b'));
        self::assertSame(['p2', 'p2'], $this->fetchColumn($path, "SELECT dst_pid FROM links WHERE src = 'p1' ORDER BY kind"));

        $index->remove('p2');
        self::assertSame([null, null], $this->fetchColumn($path, "SELECT dst_pid FROM links WHERE src = 'p1'"), 'removed: broken again');
    }

    public function testBodyLinksFillTheBacklinksPanelWithinVisibility(): void
    {
        [$index] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', [], 'text'));
        $index->index($this->snapshot('p2', 'site:public-note', [], 'see [a](reports:mri:mioveni:a)', ['visibility' => 'public']));
        $index->index($this->snapshot('p3', 'site:private-note', [], 'also [a](/reports:mri:mioveni:a#x)', ['visibility' => 'private']));

        self::assertSame(['site:public-note'], array_column($index->backlinks('p1', null), 'path'), 'anonymous: public linkers only');
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

    public function testFindByPatientKeyReturnsOnlyVisiblePages(): void
    {
        [$index, $path] = $this->newIndex();
        $this->snapshot('p1', 'reports:mri:mioveni:a', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body a');
        $this->snapshot('p2', 'reports:ct:mioveni:b', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body b');
        $this->snapshot('p3', 'reports:mri:mioveni:c', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body c', ['visibility' => 'public']);

        $index->index($this->snapshot('p1', 'reports:mri:mioveni:a', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body a'));
        $index->index($this->snapshot('p2', 'reports:ct:mioveni:b', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body b'));
        $index->index($this->snapshot('p3', 'reports:mri:mioveni:c', ['patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456']], 'body c', ['visibility' => 'public']));

        $visible = $index->findByPatientKey(hash('sha256', '2740101123456'), null);
        $pids = array_column($visible, 'pid');

        self::assertSame(['p3'], $pids, 'anonymous sees only public pages');
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
