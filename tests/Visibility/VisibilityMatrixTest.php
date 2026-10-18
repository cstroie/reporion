<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Visibility;

use Reporion\Auth\Grant;
use Reporion\Auth\Group;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Index\Sqlite;
use Reporion\Tests\Index\IndexTestCase;

/**
 * CLAUDE.md Testing: "for each of private/unlisted/public ×
 * {owner, editor-with-grant, editor-without-grant, viewer-with-grant,
 * anonymous} × search/tree/sitemap/API, assert exactly what is reachable.
 * Add a case here before adding any new listing endpoint." One predicate
 * (Search\Query), asserted from every access pattern that exists so far.
 *
 * The rule under test (docs/architecture-storage-index.md Table 2): a
 * *listing* (search, tree, sitemap) shows a caller with no covering grant
 * public pages only: unlisted is reachable but never listed. *Direct*
 * access to one known path (the "API" pattern here) additionally allows
 * unlisted for anyone; only private is refused, indistinguishably from not
 * existing (invariant 9). A grant covering a page's namespace bypasses the
 * visibility filter entirely, in both patterns: the grant-holder sees
 * private pages the same as the owner does, but *only inside their own
 * namespace*: the discriminating case this suite exists to catch is a
 * grant on one namespace leaking into an unrelated one.
 */
final class VisibilityMatrixTest extends IndexTestCase
{
    private const PRIVATE_PATH = 'reports:mri:mioveni:private-page';
    private const UNLISTED_PATH = 'reports:mri:mioveni:unlisted-page';
    private const PUBLIC_PATH = 'reports:mri:mioveni:public-page';
    private const OTHER_NS_PRIVATE_PATH = 'reports:ct:cervical:private-page';

    public function testSearchListingObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();

        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $this->owner())));
        self::assertSame(['p-unlisted'], $this->pidsFrom($index->search('beta', $this->owner())));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', $this->owner())));

        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $this->editorWithGrant())), 'a grant covering the namespace must list its private pages');
        self::assertSame(['p-unlisted'], $this->pidsFrom($index->search('beta', $this->editorWithGrant())));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', $this->editorWithGrant())));

        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $this->viewerWithGrant())), 'a viewer grant lists private pages too: read access, not write');

        self::assertSame([], $this->pidsFrom($index->search('alpha', $this->editorWithoutGrant())), 'a grant on a DIFFERENT namespace must not leak into this one');
        self::assertSame([], $this->pidsFrom($index->search('beta', $this->editorWithoutGrant())));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', $this->editorWithoutGrant())));

        self::assertSame([], $this->pidsFrom($index->search('alpha', null)), 'anonymous must never find a private page via search');
        self::assertSame([], $this->pidsFrom($index->search('beta', null)), 'anonymous must never find an unlisted page via search (listed ≠ reachable)');
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', null)));
    }

    /**
     * D47: patient pages are a radiologist's. An account that is not one keeps its grants
     * elsewhere, but a grant on a report namespace is dropped: search, tree, sitemap, worklist and
     * direct lookup see only what anonymous sees there (public, and unlisted by direct path).
     */
    public function testANonRadiologistWithAReportGrantSeesOnlyWhatAnonymousSeesThere(): void
    {
        $index = $this->seededIndex();
        $writer = $this->writerWithReportGrant();

        self::assertSame([], $this->pidsFrom($index->search('alpha', $writer)), 'a report grant without being a radiologist lists nothing private');
        self::assertSame([], $this->pidsFrom($index->search('beta', $writer)));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', $writer)));
        self::assertNull($index->findByPath(self::PRIVATE_PATH, $writer), 'nor reaches a private report directly');
        self::assertSame($this->pidsFrom($index->listSitemap(null)), $this->pidsFrom($index->listSitemap($writer)));
        self::assertFalse($writer->canRead('reports:mri:mioveni'));
        self::assertFalse($writer->canWrite('reports:mri:mioveni'));
        self::assertTrue($writer->canWrite('wiki:notes'), 'grants outside reports: are untouched');

        $radiologist = $writer->with(radiologist: true);
        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $radiologist)));
        self::assertNotNull($index->findByPath(self::PRIVATE_PATH, $radiologist));
    }

    /** D47: a group's grants and its radiologist flag reach its members, exactly as their own would */
    public function testAGroupsGrantsAndRadiologistFlagReachItsMembers(): void
    {
        $index = $this->seededIndex();
        $radiologists = new Group('radiologists', '', [new Grant('reports:mri', GrantRole::Editor)], true, '', '');
        $writers = new Group('writers', '', [new Grant('reports:mri', GrantRole::Viewer), new Grant('wiki', GrantRole::Editor)], false, '', '');
        $groups = [$radiologists, $writers];

        $rad = (new User('rad', 'x', false, [], true, 'now', 'now', groups: ['radiologists']))->withGroups($groups);
        self::assertTrue($rad->isRadiologist());
        self::assertTrue($rad->canSign('reports:mri:mioveni:260101-x'));
        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $rad)));

        $wri = (new User('wri', 'x', false, [], true, 'now', 'now', groups: ['writers', 'gone']))->withGroups($groups);
        self::assertFalse($wri->isRadiologist());
        self::assertTrue($wri->canWrite('wiki:x'));
        self::assertFalse($wri->canRead('reports:mri:mioveni'), "the writers' report grant is dropped: not radiologists");
        self::assertSame([], $this->pidsFrom($index->search('alpha', $wri)));

        $notMember = (new User('x', 'x', false, [], true, 'now', 'now'))->withGroups($groups);
        self::assertFalse($notMember->isRadiologist(), 'a group reaches only the accounts that name it');
        self::assertFalse($notMember->canWrite('wiki:x'));
    }

    public function testAccessionSearchObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();

        foreach ([$this->owner(), $this->editorWithGrant(), $this->viewerWithGrant()] as $principal) {
            self::assertSame(['p-private'], $this->pidsFrom($index->search('X-MR-26-PRIV2', $principal)));
            self::assertSame(['p-unlisted'], $this->pidsFrom($index->search('X-MR-26-UNL2', $principal)));
        }
        self::assertSame([], $this->pidsFrom($index->search('X-MR-26-PRIV2', $this->editorWithoutGrant())), 'another namespace\'s grant finds nothing');
        self::assertSame([], $this->pidsFrom($index->search('X-MR-26-PRIV2', null)), 'anonymous never finds a private report by its number');
        self::assertSame([], $this->pidsFrom($index->search('X-MR-26-UNL2', null)), 'nor an unlisted one');
        self::assertSame(['p-public'], $this->pidsFrom($index->search('X-MR-26-PUB2', null)));
    }

    public function testTreeListingObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();

        self::assertSame(
            ['p-private', 'p-public', 'p-unlisted'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', $this->owner()))
        );
        self::assertSame(
            ['p-private', 'p-public', 'p-unlisted'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', $this->editorWithGrant()))
        );
        self::assertSame(
            ['p-private', 'p-public', 'p-unlisted'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', $this->viewerWithGrant()))
        );
        self::assertSame(
            ['p-public'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', $this->editorWithoutGrant()))
        );
        self::assertSame(
            ['p-public'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', null))
        );

        // The case this suite exists to catch: a namespace the caller has
        // no grant on. It has no public page of its own, so the correct
        // result is an empty list: indistinguishable from a namespace
        // that does not exist at all (invariant 9), not an error and not
        // a peek at what the namespace privately contains.
        self::assertSame(
            [],
            $this->pidsFrom($index->listNamespace('reports:ct:cervical', $this->editorWithGrant()))
        );
        self::assertSame(
            [],
            $this->pidsFrom($index->listNamespace('reports:ct:cervical', null))
        );
    }

    /**
     * listNamespaceYears() applies the same visibility predicate as
     * listNamespace(), inside the aggregate before GROUP BY: same shape as
     * testSubnamespacesOfMioveniForAnEditorWithNoGrantThereOnlyCountsPublic():
     * a caller with no grant here must never see a private page's year
     * counted at all, not even folded into a public page's count for the
     * same year.
     */
    public function testNamespaceYearsListingObeysVisibilityAndGrants(): void
    {
        [$index, ] = $this->newIndex();
        $index->index($this->snapshot('p-priv-25', 'reports:mri:mioveni:250110-a', ['study_date' => '2025-01-10T09:00:00+03:00'], overrides: ['visibility' => 'private']));
        $index->index($this->snapshot('p-pub-26', 'reports:mri:mioveni:260305-a', ['study_date' => '2026-03-05T09:00:00+03:00'], overrides: ['visibility' => 'public']));

        self::assertSame(
            [['year' => '2026', 'count' => 1], ['year' => '2025', 'count' => 1]],
            $index->listNamespaceYears('reports:mri:mioveni', $this->owner())
        );
        self::assertSame(
            [['year' => '2026', 'count' => 1], ['year' => '2025', 'count' => 1]],
            $index->listNamespaceYears('reports:mri:mioveni', $this->editorWithGrant())
        );
        self::assertSame(
            [['year' => '2026', 'count' => 1]],
            $index->listNamespaceYears('reports:mri:mioveni', $this->editorWithoutGrant()),
            'no grant here: the private page\'s year must not appear at all, public-only'
        );
        self::assertSame(
            [['year' => '2026', 'count' => 1]],
            $index->listNamespaceYears('reports:mri:mioveni', null)
        );
    }

    /**
     * listWorklist() shares listNamespace()'s visibility predicate exactly
     * (Query::visibilityClause()): same test shape as
     * testTreeListingObeysVisibilityAndGrants(), proving the sidebar
     * doesn't accidentally use a looser or different clause.
     */
    public function testWorklistListingObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();

        self::assertSame(
            ['p-public'],
            $this->pidsFrom($index->listWorklist('reports:mri:mioveni', $this->editorWithoutGrant()))
        );
        self::assertSame(
            ['p-public'],
            $this->pidsFrom($index->listWorklist('reports:mri:mioveni', null))
        );
        self::assertSame(
            [],
            $this->pidsFrom($index->listWorklist('reports:ct:cervical', $this->editorWithGrant())),
            'no grant on reports:ct:cervical and no public page there either: same invariant-9 empty result as the tree listing'
        );
    }

    public function testWorklistOrdersMostRecentlyUpdatedFirstAndRespectsLimit(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p-old', 'reports:mri:mioveni:a', [], 'text', ['visibility' => 'public', 'updated' => '2026-01-01T00:00:00+00:00']));
        $index->index($this->snapshot('p-new', 'reports:mri:mioveni:b', [], 'text', ['visibility' => 'public', 'updated' => '2026-06-01T00:00:00+00:00']));
        $index->index($this->snapshot('p-newest', 'reports:mri:mioveni:c', [], 'text', ['visibility' => 'public', 'updated' => '2026-09-01T00:00:00+00:00']));
        $index = new Sqlite($path, $this->migrationsDir);

        // pidsFrom() sorts alphabetically (right for the set-equality
        // assertions everywhere else in this file, wrong here: it would
        // silently turn "p-newest, p-new" into "p-new, p-newest" and hide
        // exactly the bug this test exists to catch): extract pids
        // directly instead, preserving listWorklist()'s own order.
        $orderedPids = static fn (array $rows): array => array_map(static fn (array $row): string => (string) $row['pid'], $rows);

        self::assertSame(
            ['p-newest', 'p-new', 'p-old'],
            $orderedPids($index->listWorklist('reports:mri:mioveni', null))
        );
        self::assertSame(
            ['p-newest', 'p-new'],
            $orderedPids($index->listWorklist('reports:mri:mioveni', null, limit: 2))
        );
    }

    public function testSitemapListingObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();

        self::assertCount(4, $index->listSitemap($this->owner()));
        self::assertCount(3, $index->listSitemap($this->editorWithGrant()), 'sees its own 3 pages in reports:mri, not the private one in reports:ct');
        self::assertCount(3, $index->listSitemap($this->viewerWithGrant()));

        $noGrantSitemap = $index->listSitemap($this->editorWithoutGrant());
        $noGrantPaths = array_column($noGrantSitemap, 'path');
        sort($noGrantPaths);
        self::assertSame(
            [self::OTHER_NS_PRIVATE_PATH, self::PUBLIC_PATH],
            $noGrantPaths,
            'sees the public reports:mri page plus the private reports:ct page its OWN grant covers: never the private reports:mri one'
        );

        $anonSitemap = $index->listSitemap(null);
        self::assertCount(1, $anonSitemap);
        self::assertSame(self::PUBLIC_PATH, $anonSitemap[0]['path']);
    }

    /**
     * Admin → Sites' report counts per site are a listing too: a count of what the caller may list
     */
    public function testSiteCountsObeyVisibilityAndGrants(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p-private', self::PRIVATE_PATH, ['site' => 'mioveni'], 'alpha content', ['visibility' => 'private']));
        $index->index($this->snapshot('p-unlisted', self::UNLISTED_PATH, ['site' => 'mioveni'], 'beta content', ['visibility' => 'unlisted']));
        $index->index($this->snapshot('p-public', self::PUBLIC_PATH, ['site' => 'mioveni'], 'gamma content', ['visibility' => 'public']));
        $index->index($this->snapshot('p-other', self::OTHER_NS_PRIVATE_PATH, ['site' => 'cervical'], 'delta content', ['ns' => 'reports:ct:cervical', 'visibility' => 'private']));
        $index->index($this->snapshot('p-none', 'docs:notes:one', ['site' => 'mioveni'], 'epsilon content', ['visibility' => 'public']));
        $index = new Sqlite($path, $this->migrationsDir);

        self::assertSame(['cervical' => 1, 'mioveni' => 3], self::sorted($index->siteCounts($this->owner())), 'only reports are counted');
        self::assertSame(['mioveni' => 3], $index->siteCounts($this->editorWithGrant()));
        self::assertSame(['mioveni' => 3], $index->siteCounts($this->viewerWithGrant()));
        self::assertSame(['cervical' => 1, 'mioveni' => 1], self::sorted($index->siteCounts($this->editorWithoutGrant())), 'the grant elsewhere leaks nothing here');
        self::assertSame(['mioveni' => 1], $index->siteCounts(null), 'anonymous counts the public report only');
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * Similar reports (phase 34e, GET /api/v1/pages/{path}/similar) is a
     * listing: from the public report, the nearest others are exactly those
     * the caller may list: the same vector on every page, so only the
     * predicate decides.
     */
    public function testSimilarListingObeysVisibilityAndGrants(): void
    {
        $index = $this->seededIndex();
        foreach (['p-private', 'p-unlisted', 'p-public', 'p-other-ns-private'] as $pid) {
            $index->putVector($pid, 'embed', 'sha-' . $pid, [0.6, 0.8]);
        }
        $similar = fn (?User $principal): array => $this->pidsFrom($index->similar('p-public', 'embed', $principal));

        self::assertSame(['p-other-ns-private', 'p-private', 'p-unlisted'], $similar($this->owner()));
        self::assertSame(['p-private', 'p-unlisted'], $similar($this->editorWithGrant()));
        self::assertSame(['p-private', 'p-unlisted'], $similar($this->viewerWithGrant()));
        self::assertSame(['p-other-ns-private'], $similar($this->editorWithoutGrant()), 'its own namespace only; unlisted is never listed');
        self::assertSame([], $similar(null), 'anonymous never gets a private or unlisted report');
    }

    /**
     * Recall (D50, POST /api/v1/recall) is a listing: the finished reports
     * sharing the line's words are exactly those the caller may list, the
     * same text on every page, so only the predicate decides.
     */
    public function testRecallListingObeysVisibilityAndGrants(): void
    {
        [$index, $path] = $this->newIndex();
        $pages = ['p-private' => [self::PRIVATE_PATH, 'private'], 'p-unlisted' => [self::UNLISTED_PATH, 'unlisted'], 'p-public' => [self::PUBLIC_PATH, 'public'], 'p-other-ns-private' => [self::OTHER_NS_PRIVATE_PATH, 'private']];
        foreach ($pages as $pid => [$pagePath, $visibility]) {
            $index->index($this->snapshot($pid, $pagePath, [], "### Descriere\n\nLeziuni de substanță albă.\n", ['visibility' => $visibility, 'status' => 'archived']));
        }
        $index = new Sqlite($path, $this->migrationsDir);
        $recall = fn (?User $principal): array => $this->pidsFrom($index->recallCandidates(['leziuni', 'substanta'], $principal->username ?? '', 'MR', 'p-none', null, null, $principal));

        self::assertSame(['p-other-ns-private', 'p-private', 'p-public', 'p-unlisted'], $recall($this->owner()));
        self::assertSame(['p-private', 'p-public', 'p-unlisted'], $recall($this->editorWithGrant()));
        self::assertSame(['p-private', 'p-public', 'p-unlisted'], $recall($this->viewerWithGrant()));
        self::assertSame(['p-other-ns-private', 'p-public'], $recall($this->editorWithoutGrant()), 'its own namespace only; unlisted is never listed');
        self::assertSame(['p-public'], $recall($this->writerWithReportGrant()), 'no radiologist: the report grant is dropped (D47)');
        self::assertSame(['p-public'], $recall(null), 'anonymous never gets a private or unlisted report');
    }

    /**
     * listRecent(): the signed-in dashboard: is a listing: for every
     * principal it must return exactly the pages the sitemap listing does,
     * and its filters must never widen that set.
     */
    public function testRecentListingMatchesTheSitemapListingForEveryPrincipal(): void
    {
        $index = $this->seededIndex();
        $principals = [$this->owner(), $this->editorWithGrant(), $this->viewerWithGrant(), $this->editorWithoutGrant(), null];

        foreach ($principals as $principal) {
            $expected = array_column($index->listSitemap($principal), 'path');
            $actual = array_column($index->listRecent($principal), 'path');
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, $principal?->username ?? 'anonymous');

            $filtered = array_column($index->listRecent($principal, ['status' => 'draft', 'since' => '2000-01-01']), 'path');
            self::assertSame([], array_diff($filtered, $expected), 'a filter never widens the visible set');
        }
    }

    /**
     * findByPid(): the /r/{pid}/{rev} permalink: must give exactly the
     * same answer as findByPath() for every visibility × principal.
     */
    public function testLookupByPidMatchesLookupByPathForEveryPrincipal(): void
    {
        $index = $this->seededIndex();
        $principals = [
            'owner' => $this->owner(),
            'editor-with-grant' => $this->editorWithGrant(),
            'viewer-with-grant' => $this->viewerWithGrant(),
            'editor-without-grant' => $this->editorWithoutGrant(),
            'anonymous' => null,
        ];

        foreach ([self::PRIVATE_PATH, self::UNLISTED_PATH, self::PUBLIC_PATH] as $path) {
            $pid = (string) $index->findByPath($path, $this->owner())['pid'];
            foreach ($principals as $who => $principal) {
                self::assertSame(
                    $index->findByPath($path, $principal) !== null,
                    $index->findByPid($pid, $principal) !== null,
                    "{$path} as {$who}"
                );
            }
        }
        self::assertNull($index->findByPid('01NOTAPID00000000000000000', $this->owner()));
    }

    /**
     * findByPaths(): the namespace page's one query for its sub-namespaces'
     * descriptions: must reach exactly what findByPath() reaches, path by
     * path, for every visibility × principal.
     */
    public function testBatchLookupByPathsMatchesLookupByPathForEveryPrincipal(): void
    {
        $index = $this->seededIndex();
        $paths = [self::PRIVATE_PATH, self::UNLISTED_PATH, self::PUBLIC_PATH, 'reports:mri:mioveni:does-not-exist'];
        $principals = [
            'owner' => $this->owner(),
            'editor-with-grant' => $this->editorWithGrant(),
            'viewer-with-grant' => $this->viewerWithGrant(),
            'editor-without-grant' => $this->editorWithoutGrant(),
            'anonymous' => null,
        ];

        foreach ($principals as $who => $principal) {
            $expected = array_values(array_filter($paths, static fn (string $path): bool => $index->findByPath($path, $principal) !== null));
            $batch = $index->findByPaths([...$paths, self::PUBLIC_PATH], $principal);
            self::assertSame($expected, array_values(array_filter($paths, static fn (string $path): bool => isset($batch[$path]))), $who);
            self::assertCount(\count($expected), $batch, $who);
        }
        self::assertSame([], $index->findByPaths([], $this->owner()));
    }

    public function testDirectApiAccessAllowsUnlistedForAnyoneAndPrivateOnlyWithAGrant(): void
    {
        $index = $this->seededIndex();

        self::assertNotNull($index->findByPath(self::PRIVATE_PATH, $this->owner()));
        self::assertNotNull($index->findByPath(self::UNLISTED_PATH, $this->owner()));
        self::assertNotNull($index->findByPath(self::PUBLIC_PATH, $this->owner()));

        self::assertNotNull($index->findByPath(self::PRIVATE_PATH, $this->editorWithGrant()), 'a covering grant reaches a private page directly, same as the owner');
        self::assertNotNull($index->findByPath(self::PRIVATE_PATH, $this->viewerWithGrant()));

        self::assertNull($index->findByPath(self::PRIVATE_PATH, $this->editorWithoutGrant()), 'a grant on a different namespace must not reach this private page');
        self::assertNotNull($index->findByPath(self::UNLISTED_PATH, $this->editorWithoutGrant()), 'unlisted remains reachable by direct path for anyone, grant or not');
        self::assertNotNull($index->findByPath(self::PUBLIC_PATH, $this->editorWithoutGrant()));

        self::assertNull($index->findByPath(self::PRIVATE_PATH, null), 'private must 404 for anonymous, not just be hidden from listings');
        self::assertNotNull($index->findByPath(self::UNLISTED_PATH, null), 'unlisted must remain reachable by direct path for anonymous');
        self::assertNotNull($index->findByPath(self::PUBLIC_PATH, null));

        self::assertNull($index->findByPath('reports:mri:mioveni:does-not-exist', null));
    }

    /**
     * The detail almost everyone misses: a namespace containing "_" (a SQL
     * LIKE wildcard matching any single character) must not accidentally
     * grant access to an unrelated namespace that merely resembles it
     * Search\Query::likeEscape() exists specifically for this.
     */
    public function testSubnamespacesOfReportsForOwnerListsBothMriAndCt(): void
    {
        $index = $this->seededIndex();

        $subs = $this->namesFrom($index->listSubnamespaces('reports', $this->owner()));

        self::assertSame(['ct', 'mri'], $subs);
    }

    /**
     * The case this suite exists to catch: an editor granted only on
     * reports:mri must see that subnamespace's real count, but must not
     * even see "ct" exists, not as a zero, not as any count, because
     * every page under it is private and outside their grant. A leaked
     * non-zero (or zero) count for "ct" here would be exactly the
     * "dangerous failure mode" the visibility predicate exists to prevent.
     */
    public function testSubnamespacesOfReportsForAnEditorGrantedOnlyOnMriNeverMentionsCt(): void
    {
        $index = $this->seededIndex();

        $subs = $index->listSubnamespaces('reports', $this->editorWithGrant());

        self::assertSame(['mri'], $this->namesFrom($subs));
        self::assertSame(3, $subs[0]['count'], 'sees all 3 of their own pages in reports:mri, private included');
    }

    public function testSubnamespacesOfReportsForAnonymousOnlyCountsPublicPages(): void
    {
        $index = $this->seededIndex();

        $subs = $index->listSubnamespaces('reports', null);

        self::assertSame(['mri'], $this->namesFrom($subs), '"ct" has no public page at all, so it must not appear');
        self::assertSame(1, $subs[0]['count']);
    }

    public function testSubnamespacesOfMioveniForAnEditorWithNoGrantThereOnlyCountsPublic(): void
    {
        $index = $this->seededIndex();

        // editorWithoutGrant() holds a grant on reports:ct, not reports:mri
        // irrelevant one level down, so they see exactly what anonymous
        // would see: the one public page directly in reports:mri:mioveni.
        // reports:mri:mioveni has no sub-namespaces of its own in this
        // fixture, so the correct result is an empty list, not an error.
        self::assertSame([], $index->listSubnamespaces('reports:mri:mioveni', $this->editorWithoutGrant()));
    }

    /**
     * The grouping must truncate at the FIRST colon past $ns, not include
     * everything below it: a page two levels deeper than the namespace
     * being listed must still be counted under its immediate
     * sub-namespace, not its own leaf sub-sub-namespace.
     */
    public function testSubnamespaceGroupingTruncatesAtTheImmediateNextSegmentOnly(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p-deep', 'reports:mri:mioveni:2026q3:x', [], 'text', ['ns' => 'reports:mri:mioveni:2026q3', 'visibility' => 'public']));
        $index = new Sqlite($path, $this->migrationsDir);

        $subs = $index->listSubnamespaces('reports:mri', null);

        self::assertSame(['mioveni'], $this->namesFrom($subs), 'must group as "mioveni", not "mioveni:2026q3"');
        self::assertSame(1, $subs[0]['count']);
    }

    /**
     * SQLite's SUBSTR/INSTR count UTF-8 characters, not bytes: a namespace
     * segment is not restricted to ASCII (assertValidPath() only rejects
     * "/" and empty segments), so a byte-length prefix offset would slice a
     * multibyte segment mid-character and either garble the sub-namespace
     * name or crash on the resulting invalid UTF-8.
     */
    public function testSubnamespaceGroupingHandlesAMultibyteNamespaceSegment(): void
    {
        // The multibyte character must be in $ns itself, not merely in a
        // child segment: $ns is what the byte-vs-character prefix offset
        // was measuring, and "rapoarte:măgurele" (17 chars, 18 bytes) is
        // exactly long enough that a byte-length offset lands one
        // character short, slicing "cranio" into "ranio".
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p-ro', 'rapoarte:măgurele:cranio:x', [], 'text', ['ns' => 'rapoarte:măgurele:cranio', 'visibility' => 'public']));
        $index = new Sqlite($path, $this->migrationsDir);

        $subs = $index->listSubnamespaces('rapoarte:măgurele', null);

        self::assertSame(['cranio'], $this->namesFrom($subs));
    }

    /**
     * @param list<array{name: string, count: int}> $subs
     *
     * @return list<string>
     */
    private function namesFrom(array $subs): array
    {
        return array_column($subs, 'name');
    }

    public function testGrantNamespaceWithAnUnderscoreDoesNotWildcardMatch(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('p-real', 'reports_mri:mioveni:x', [], 'delta content', ['ns' => 'reports_mri:mioveni', 'visibility' => 'private']));
        $index->index($this->snapshot('p-decoy', 'reportsXmri:mioveni:x', [], 'delta content', ['ns' => 'reportsXmri:mioveni', 'visibility' => 'private']));
        $index = new Sqlite($path, $this->migrationsDir);

        $grantee = new User('e', 'x', false, [new Grant('reports_mri', GrantRole::Editor)], true, 'now', 'now');

        self::assertNotNull($index->findByPath('reports_mri:mioveni:x', $grantee));
        self::assertNull($index->findByPath('reportsXmri:mioveni:x', $grantee), '"_" in the grant namespace must be escaped, not treated as a SQL LIKE wildcard');
    }

    /**
     * Phase 19a: search facet counts are a listing too: a private page's
     * tag or modality is never counted for a caller who could not list the
     * page, not even folded into a public page's count for the same value.
     */
    public function testSearchFacetCountsObeyVisibilityAndGrants(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('f-private', self::PRIVATE_PATH, ['modality' => ['MR'], 'tags' => ['shared', 'secret']], 'omega content', ['visibility' => 'private']));
        $index->index($this->snapshot('f-unlisted', self::UNLISTED_PATH, ['modality' => ['MR'], 'tags' => ['shared']], 'omega content', ['visibility' => 'unlisted']));
        $index->index($this->snapshot('f-public', self::PUBLIC_PATH, ['modality' => ['MR'], 'tags' => ['shared']], 'omega content', ['visibility' => 'public']));
        $index->index($this->snapshot('f-other', self::OTHER_NS_PRIVATE_PATH, ['modality' => ['CT'], 'tags' => ['shared', 'secret']], 'omega content', ['ns' => 'reports:ct:cervical', 'visibility' => 'private']));
        $index = new Sqlite($path, $this->migrationsDir);
        $counts = static function (array $facets, string $facet): array {
            $out = [];
            foreach ($facets[$facet] as $row) {
                $out[$row['value']] = $row['n'];
            }

            return $out;
        };

        $owner = $index->searchFacets('omega', $this->owner());
        self::assertSame(['shared' => 4, 'secret' => 2], $counts($owner, 'tag'));
        self::assertSame(['MR' => 3, 'CT' => 1], $counts($owner, 'modality'));
        self::assertSame(4, $index->searchCount('omega', $this->owner()));

        $editor = $index->searchFacets('omega', $this->editorWithGrant());
        self::assertSame(['shared' => 3, 'secret' => 1], $counts($editor, 'tag'), 'its own namespace, private included; not reports:ct');
        self::assertSame(['MR' => 3], $counts($editor, 'modality'));

        $outsider = $index->searchFacets('omega', $this->editorWithoutGrant());
        self::assertSame(['shared' => 2, 'secret' => 1], $counts($outsider, 'tag'), 'the public page and its own namespace: never reports:mri\'s private or unlisted');
        self::assertSame(['CT' => 1, 'MR' => 1], $counts($outsider, 'modality'));
        self::assertSame(2, $index->searchCount('omega', $this->editorWithoutGrant()));

        $anonymous = $index->searchFacets('omega', null);
        self::assertSame(['shared' => 1], $counts($anonymous, 'tag'), 'no secret counted, not even inside shared');
        self::assertSame(['MR' => 1], $counts($anonymous, 'modality'));
        self::assertSame(1, $index->searchCount('omega', null));

        // A filter narrows the results and the other facets, never its own
        self::assertSame(['f-other', 'f-private'], $this->pidsFrom($index->search('omega', $this->owner(), 'relevance', '', ['tag' => 'secret'])));
        $filtered = $index->searchFacets('omega', $this->owner(), '', ['tag' => 'secret']);
        self::assertSame(['CT' => 1, 'MR' => 1], $counts($filtered, 'modality'));
        self::assertSame(['shared' => 4, 'secret' => 2], $counts($filtered, 'tag'), 'its own facet unfiltered, to switch value');
    }

    /**
     * D46: every account is an editor of `users:{username}`, derived: never stored. Its private pages are
     * invisible to everyone else (a grantee elsewhere, another account, anonymous), a public one is for
     * all, an owner sees everything, and a pseudouser or reserved name gets no namespace at all.
     */
    public function testAPersonalNamespaceIsItsAccountsAndNoOnesElse(): void
    {
        [$index, $path] = $this->newIndex();
        $index->index($this->snapshot('u-alice-private', 'users:alice:notes', [], 'notes alpha', ['visibility' => 'private']));
        $index->index($this->snapshot('u-alice-public', 'users:alice:handout', [], 'handout alpha', ['visibility' => 'public']));
        $index->index($this->snapshot('u-bob-private', 'users:bob:notes', [], 'notes alpha bob', ['visibility' => 'private']));
        $index = new Sqlite($path, $this->migrationsDir);

        $alice = new User('alice', 'x', false, [], true, 'now', 'now');
        $bob = new User('bob', 'x', false, [], true, 'now', 'now');
        $grantee = $this->editorWithGrant();

        $listed = fn (?User $who): array => $this->pidsFrom($index->listNamespace('users:alice', $who));
        self::assertSame(['u-alice-private', 'u-alice-public'], $listed($alice), 'the account sees its own pages');
        self::assertSame(['u-alice-private', 'u-alice-public'], $listed($this->owner()), 'an instance owner sees them too');
        self::assertSame(['u-alice-public'], $listed($bob), 'another account sees only what is public');
        self::assertSame(['u-alice-public'], $listed($grantee), 'a grant elsewhere gives nothing here');
        self::assertSame(['u-alice-public'], $listed(null), 'anonymous sees only what is public');

        self::assertNull($index->findByPath('users:bob:notes', $alice), 'alice cannot reach bob\'s private page');
        self::assertNotNull($index->findByPath('users:alice:notes', $alice));
        self::assertSame(['u-alice-private', 'u-alice-public'], $this->pidsFrom($index->search('alpha', $alice, 'relevance', 'users:alice')));
        self::assertNotContains('u-alice-private', $this->pidsFrom($index->search('alpha', $bob)));

        // An explicit grant on someone's namespace opens it, as on any namespace (D36)
        $reader = new User('carol', 'x', false, [new Grant('users:alice', GrantRole::Viewer)], true, 'now', 'now');
        self::assertSame(['u-alice-private', 'u-alice-public'], $listed($reader));
        self::assertFalse($reader->canWrite('users:alice:notes'));
        self::assertTrue($alice->canWrite('users:alice:notes'));
        self::assertFalse($alice->canWrite('users:bob:notes'));
    }

    public function testOnlyAnAccountNamedLikeAUsableSegmentHasAPersonalNamespace(): void
    {
        self::assertSame('users:alice', (new User('alice', 'x', false, [], true, 'now', 'now'))->personalNamespace());
        self::assertSame('users:a.b-c', (new User('a.b-c', 'x', false, [], true, 'now', 'now'))->personalNamespace());
        self::assertNull((new User('rev', 'x', false, [], true, 'now', 'now'))->personalNamespace(), 'a page-directory entry cannot be a segment');
        self::assertNull((new User('autosave.x', 'x', false, [], true, 'now', 'now'))->personalNamespace());
        self::assertNull((new User('system:summarizer', '', true, [], true, '', ''))->personalNamespace(), 'a pseudouser is not an account');
        // Nothing is added for an owner (instance-wide already) and the explicit grants stay as they are
        self::assertSame([], (new User('root', 'x', true, [], true, 'now', 'now'))->effectiveGrants());
        $alice = new User('alice', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now', radiologist: true);
        self::assertCount(1, $alice->grants);
        self::assertCount(2, $alice->effectiveGrants());
        self::assertTrue($alice->hasAnyWriteAccess(), 'every account can write somewhere: its own namespace');
        self::assertTrue($alice->writesOnlyInOwnNamespace(), 'a viewer elsewhere still writes only at home');
        self::assertFalse($this->editorWithGrant()->writesOnlyInOwnNamespace());
    }

    private function seededIndex(): Sqlite
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot('p-private', self::PRIVATE_PATH, self::exams('PRIV'), 'alpha content', ['visibility' => 'private']));
        $index->index($this->snapshot('p-unlisted', self::UNLISTED_PATH, self::exams('UNL'), 'beta content', ['visibility' => 'unlisted']));
        $index->index($this->snapshot('p-public', self::PUBLIC_PATH, self::exams('PUB'), 'gamma content', ['visibility' => 'public']));
        $index->index($this->snapshot('p-other-ns-private', self::OTHER_NS_PRIVATE_PATH, [], 'delta content', ['ns' => 'reports:ct:cervical', 'visibility' => 'private']));

        // Reopened, deliberately, to prove read methods work against a
        // freshly connected Sqlite instance and not just the one that
        // wrote the data.
        return new Sqlite($path, $this->migrationsDir);
    }

    /**
     * A multi-exam report's 2nd accession, searchable (phase 12)
     *
     * @return array<string, mixed>
     */
    private static function exams(string $code): array
    {
        return ['exams' => [['title' => 'IRM A', 'accession' => 'X-MR-26-' . $code . '1'], ['title' => 'IRM B', 'accession' => 'X-MR-26-' . $code . '2']]];
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    /** The same grants as editorWithGrant(), not a radiologist (D47): the report grant is dropped */
    private function writerWithReportGrant(): User
    {
        return new User('writer', 'x', false, [new Grant('reports:mri', GrantRole::Editor), new Grant('wiki', GrantRole::Editor)], true, 'now', 'now');
    }

    private function editorWithGrant(): User
    {
        return new User('editor', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now', radiologist: true);
    }

    private function viewerWithGrant(): User
    {
        return new User('viewer', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now', radiologist: true);
    }

    /**
     * Holds a real grant, just not on reports:mri: the case that actually
     * discriminates the predicate: a namespace grant must not leak into an
     * unrelated namespace.
     */
    private function editorWithoutGrant(): User
    {
        return new User('editor2', 'x', false, [new Grant('reports:ct', GrantRole::Editor)], true, 'now', 'now', radiologist: true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function pidsFrom(array $rows): array
    {
        $pids = array_map(static fn (array $row): string => (string) $row['pid'], $rows);
        sort($pids);

        return $pids;
    }
}
