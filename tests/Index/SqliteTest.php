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
     * TODO 13: a sort control on /search — 'recent' orders by updated desc
     * instead of FTS5 rank, everything else about the query unchanged.
     */
    public function testSearchSortRecentOrdersByUpdatedInsteadOfRank(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('older', 'reports:mri:mioveni:a', ['title' => 'RM cerebral demielinizant'], 'demielinizant', [
            'visibility' => 'public',
            'updated' => '2026-09-01T09:00:00+03:00',
        ]));
        $index->index($this->snapshot('newer', 'reports:mri:mioveni:b', ['title' => 'RM cerebral'], 'leziuni demielinizant minore', [
            'visibility' => 'public',
            'updated' => '2026-09-20T09:00:00+03:00',
        ]));

        $recent = $index->search('demielinizant', null, 'recent');

        self::assertSame(['newer', 'older'], array_column($recent, 'pid'));
    }

    /**
     * TODO 13: a namespace filter on /search, prefix-matched like a grant
     * (D36) — reports:mri also covers reports:mri:mioveni.
     */
    public function testSearchNsFilterIsAPrefixMatch(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('mri1', 'reports:mri:mioveni:a', ['title' => 'RM cerebral'], 'demielinizant', ['visibility' => 'public']));
        $index->index($this->snapshot('ct1', 'reports:ct:mioveni:a', ['title' => 'CT cerebral'], 'demielinizant', ['visibility' => 'public']));

        self::assertSame(['mri1'], array_column($index->search('demielinizant', null, 'relevance', 'reports:mri'), 'pid'));
        self::assertSame(['mri1'], array_column($index->search('demielinizant', null, 'relevance', 'reports:mri:mioveni'), 'pid'));
        self::assertSame([], $index->search('demielinizant', null, 'relevance', 'reports:xr'));
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

    /**
     * The namespace index's year-filter cards (`GET /{ns}:`) — grouped by
     * the study_date's year, most recent first, and a page with no
     * study_date at all (a manually created page, not a report) never
     * counts under any year.
     */
    public function testListNamespaceYearsGroupsByYearMostRecentFirstAndExcludesUndated(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:ct:scuc:250110-a', ['study_date' => '2025-01-10T09:00:00+03:00'], overrides: ['visibility' => 'public']));
        $index->index($this->snapshot('p2', 'reports:ct:scuc:250611-a', ['study_date' => '2025-06-11T09:00:00+03:00'], overrides: ['visibility' => 'public']));
        $index->index($this->snapshot('p3', 'reports:ct:scuc:260305-a', ['study_date' => '2026-03-05T09:00:00+03:00'], overrides: ['visibility' => 'public']));
        $index->index($this->snapshot('p4', 'reports:ct:scuc:_index', [], overrides: ['visibility' => 'public']));

        $years = $index->listNamespaceYears('reports:ct:scuc', null);

        self::assertSame([
            ['year' => '2026', 'count' => 1],
            ['year' => '2025', 'count' => 2],
        ], $years);
    }

    /**
     * listNamespace()'s $year param — a plain "same year of study_date"
     * narrowing, the query behind each year-filter card.
     */
    public function testListNamespaceFiltersByYearOfStudyDate(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p1', 'reports:ct:scuc:250110-a', ['study_date' => '2025-01-10T09:00:00+03:00'], overrides: ['visibility' => 'public']));
        $index->index($this->snapshot('p2', 'reports:ct:scuc:260305-a', ['study_date' => '2026-03-05T09:00:00+03:00'], overrides: ['visibility' => 'public']));

        self::assertSame(['p1'], array_column($index->listNamespace('reports:ct:scuc', null, '2025'), 'pid'));
        self::assertSame(['p2'], array_column($index->listNamespace('reports:ct:scuc', null, '2026'), 'pid'));
        self::assertSame(['p1', 'p2'], array_column($index->listNamespace('reports:ct:scuc', null), 'pid'));
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
            // A page named like the namespace its reports live in (2026-09-26)
            $this->snapshot('p4', 'reports:mri:mioveni', [], 'the site, see [a](reports:mri:mioveni:a)'),
            // A multi-exam report (phase 12)
            $this->snapshot('p5', 'reports:mri:mioveni:d', ['modality' => ['MR'], 'region' => ['msk'], 'exams' => [
                ['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0007'],
                ['title' => 'IRM coloană lombară', 'region' => ['spine'], 'accession' => 'MV-MR-26-0008'],
            ]], "## IRM genunchi drept\n\n## IRM coloană lombară\n"),
            // A text page (D40): what looks like a link in it is not one
            $this->snapshot('p6', 'docs:plain', ['format' => 'text'], 'as typed, not a link: [a](reports:mri:mioveni:a)'),
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
        $exams = 'SELECT pid, n, title, accession FROM page_exams ORDER BY pid, n';
        self::assertSame($this->fetchAll($incrementalPath, $exams), $this->fetchAll($rebuiltPath, $exams));
        self::assertCount(2, $this->fetchAll($rebuiltPath, $exams));
        $links = 'SELECT src, dst_path, dst_pid, kind FROM links ORDER BY src, kind, dst_path';
        self::assertSame($this->fetchAll($incrementalPath, $links), $this->fetchAll($rebuiltPath, $links));
        self::assertCount(5, $this->fetchAll($rebuiltPath, $links));
        self::assertSame([['dst_path' => 'x:y']], $this->fetchAll($rebuiltPath, 'SELECT dst_path FROM links WHERE dst_pid IS NULL'), 'only the link to a page that does not exist is broken');
    }

    public function testAMultiExamReportIndexesItsExamsFacetsAndAccessions(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('m1', 'reports:mri:mioveni:260927-test', ['visibility' => 'private', 'region' => ['msk'], 'exams' => [
            ['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0011'],
            ['title' => 'IRM coloană lombară', 'region' => ['spine'], 'accession' => 'MV-MR-26-0012'],
        ]], 'text'));

        self::assertSame(['msk', 'spine'], $this->fetchColumn($path, "SELECT region FROM page_regions WHERE pid = 'm1' ORDER BY region"), 'the facets take every exam');
        self::assertSame(['MV-MR-26-0011'], $this->fetchColumn($path, "SELECT accession FROM pages WHERE pid = 'm1'"), 'the first exam stands for the page');
        self::assertSame(['MV-MR-26-0011', 'MV-MR-26-0012'], $index->accessionsStartingWith('MV-MR-26-'), 'D20 seeds from every exam');

        $owner = new \Reporion\Auth\User('owner', 'x', true, [], true, 'now', 'now');
        self::assertSame(['m1'], array_column($index->search('mv-mr-26-0012', $owner), 'pid'), 'the 2nd exam finds the report');
        self::assertSame([], $index->search('MV-MR-26-0012', null), 'within visibility: a private report stays hidden');

        $index->index($this->snapshot('m1', 'reports:mri:mioveni:260927-test', ['exams' => [['title' => 'IRM genunchi drept']]], 'text'));
        self::assertSame([['n' => 1, 'title' => 'IRM genunchi drept', 'accession' => null]], $this->fetchAll($path, "SELECT n, title, accession FROM page_exams WHERE pid = 'm1'"));
        $index->remove('m1');
        self::assertSame([], $this->fetchAll($path, 'SELECT * FROM page_exams'));
    }

    /**
     * docs/FORMATS.md §12: a multi-exam report holds a `study_uid` per exam,
     * and the PACS worklist must still find its report by any of them —
     * through the same listing predicate as everything else (invariant 6).
     */
    public function testAStudyUidHeldByAnExamFindsItsReportWithinVisibility(): void
    {
        [$index] = $this->newIndex();
        $index->index($this->snapshot('m1', 'reports:ct:mioveni:260928-test', ['exams' => [
            ['title' => 'CT torace', 'study_uid' => '1.2.3'],
            ['title' => 'CT craniu', 'study_uid' => '1.2.4'],
        ]], 'text', ['visibility' => 'public']));
        $index->index($this->snapshot('s1', 'reports:ct:mioveni:260928-single', ['study_uid' => '1.2.5'], 'text'));
        $index->index($this->snapshot('m2', 'reports:ct:mioveni:260928-hidden', ['exams' => [['title' => 'CT gat', 'study_uid' => '1.2.6']]], 'text'));
        $owner = new \Reporion\Auth\User('owner', 'x', true, [], true, 'now', 'now');

        $found = $index->findByStudyUids(['1.2.3', '1.2.4', '1.2.5', '1.2.6', '9.9'], $owner);
        ksort($found);
        self::assertSame(
            ['1.2.3' => 'reports:ct:mioveni:260928-test', '1.2.4' => 'reports:ct:mioveni:260928-test', '1.2.5' => 'reports:ct:mioveni:260928-single', '1.2.6' => 'reports:ct:mioveni:260928-hidden'],
            $found,
            'an exam\'s study and a single-exam report\'s own'
        );
        $public = $index->findByStudyUids(['1.2.3', '1.2.4', '1.2.5', '1.2.6'], null);
        ksort($public);
        self::assertSame(['1.2.3' => 'reports:ct:mioveni:260928-test', '1.2.4' => 'reports:ct:mioveni:260928-test'], $public, 'anonymous: public only');

        $rows = array_column($index->listNamespace('reports:ct:mioveni', $owner), 'study_uid', 'pid');
        ksort($rows);
        self::assertSame(['m1' => '1.2.3', 'm2' => '1.2.6', 's1' => '1.2.5'], $rows, 'the namespace list shows the PACS link for a multi-exam report too');
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

    /**
     * TODO 13: the timeline's "other exams that might be the same patient"
     * — matched by title (the patient's name, D30), not the exact
     * patient_key, since that is exactly what an exact-key match misses:
     * a CNP present on one report and not the other.
     */
    public function testFindPossiblePatientMatchesByNameIgnoresExactKeyMatches(): void
    {
        [$index, ] = $this->newIndex();
        // Same person, with a CNP this time — the confirmed set (excluded by key)
        $index->index($this->snapshot('with-cnp', 'reports:mri:mioveni:a', [
            'title' => 'Ionescu Maria',
            'patient' => ['name' => 'Ionescu Maria', 'born' => 1974, 'sex' => 'F', 'cnp' => '2740101123456'],
        ], 'body a', ['visibility' => 'public']));
        // Same name, no CNP that day — a different weak key, the case this method exists for
        $index->index($this->snapshot('no-cnp', 'reports:ct:mioveni:b', [
            'title' => 'Ionescu Maria',
            'patient' => ['name' => 'Ionescu Maria', 'born' => 1975, 'sex' => 'F'],
        ], 'body b', ['visibility' => 'public']));
        // A different patient entirely, name unrelated
        $index->index($this->snapshot('unrelated', 'reports:mri:mioveni:c', [
            'title' => 'Popescu Ana',
            'patient' => ['name' => 'Popescu Ana', 'born' => 1980, 'sex' => 'F'],
        ], 'body c', ['visibility' => 'public']));

        $withCnpRow = $index->findByPath('reports:mri:mioveni:a', null);
        $matches = $index->findPossiblePatientMatches(
            'Ionescu Maria',
            [(string) $withCnpRow['patient_key'], (string) $withCnpRow['patient_key_weak']],
            'with-cnp',
            null,
        );

        self::assertSame(['no-cnp'], array_column($matches, 'pid'));
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
