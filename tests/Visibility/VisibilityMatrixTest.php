<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Visibility;

use Reporion\Auth\Grant;
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
 * public pages only — unlisted is reachable but never listed. *Direct*
 * access to one known path (the "API" pattern here) additionally allows
 * unlisted for anyone; only private is refused, indistinguishably from not
 * existing (invariant 9). A grant covering a page's namespace bypasses the
 * visibility filter entirely, in both patterns — the grant-holder sees
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

        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', $this->viewerWithGrant())), 'a viewer grant lists private pages too — read access, not write');

        self::assertSame([], $this->pidsFrom($index->search('alpha', $this->editorWithoutGrant())), 'a grant on a DIFFERENT namespace must not leak into this one');
        self::assertSame([], $this->pidsFrom($index->search('beta', $this->editorWithoutGrant())));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', $this->editorWithoutGrant())));

        self::assertSame([], $this->pidsFrom($index->search('alpha', null)), 'anonymous must never find a private page via search');
        self::assertSame([], $this->pidsFrom($index->search('beta', null)), 'anonymous must never find an unlisted page via search (listed ≠ reachable)');
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', null)));
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
        // result is an empty list — indistinguishable from a namespace
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
            'sees the public reports:mri page plus the private reports:ct page its OWN grant covers — never the private reports:mri one'
        );

        $anonSitemap = $index->listSitemap(null);
        self::assertCount(1, $anonSitemap);
        self::assertSame(self::PUBLIC_PATH, $anonSitemap[0]['path']);
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
     * grant access to an unrelated namespace that merely resembles it —
     * Search\Query::likeEscape() exists specifically for this.
     */
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

    private function seededIndex(): Sqlite
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot('p-private', self::PRIVATE_PATH, [], 'alpha content', ['visibility' => 'private']));
        $index->index($this->snapshot('p-unlisted', self::UNLISTED_PATH, [], 'beta content', ['visibility' => 'unlisted']));
        $index->index($this->snapshot('p-public', self::PUBLIC_PATH, [], 'gamma content', ['visibility' => 'public']));
        $index->index($this->snapshot('p-other-ns-private', self::OTHER_NS_PRIVATE_PATH, [], 'delta content', ['ns' => 'reports:ct:cervical', 'visibility' => 'private']));

        // Reopened, deliberately, to prove read methods work against a
        // freshly connected Sqlite instance and not just the one that
        // wrote the data.
        return new Sqlite($path, $this->migrationsDir);
    }

    private function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    private function editorWithGrant(): User
    {
        return new User('editor', 'x', false, [new Grant('reports:mri', GrantRole::Editor)], true, 'now', 'now');
    }

    private function viewerWithGrant(): User
    {
        return new User('viewer', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)], true, 'now', 'now');
    }

    /**
     * Holds a real grant, just not on reports:mri — the case that actually
     * discriminates the predicate: a namespace grant must not leak into an
     * unrelated namespace.
     */
    private function editorWithoutGrant(): User
    {
        return new User('editor2', 'x', false, [new Grant('reports:ct', GrantRole::Editor)], true, 'now', 'now');
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
