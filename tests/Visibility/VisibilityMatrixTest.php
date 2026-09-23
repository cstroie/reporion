<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Visibility;

use Reporion\Tests\Index\IndexTestCase;

/**
 * CLAUDE.md Testing: "for each of private/unlisted/public × owner/anonymous
 * × search/tree/sitemap/API, assert exactly what is reachable. Add a case
 * here before adding any new listing endpoint." One predicate
 * (Search\Query), asserted from every access pattern that exists so far.
 *
 * The rule under test (docs/architecture-storage-index.md Table 2): a
 * *listing* (search, tree, sitemap) shows an anonymous caller public pages
 * only — unlisted is reachable but never listed. *Direct* access to one
 * known path (the "API" pattern here) additionally allows unlisted; only
 * private is refused, indistinguishably from not existing (invariant 9).
 */
final class VisibilityMatrixTest extends IndexTestCase
{
    private const PRIVATE_PATH = 'reports:mri:mioveni:private-page';
    private const UNLISTED_PATH = 'reports:mri:mioveni:unlisted-page';
    private const PUBLIC_PATH = 'reports:mri:mioveni:public-page';

    public function testSearchListingObeysVisibility(): void
    {
        $path = $this->seedPages();
        $index = $this->reopen($path);

        self::assertSame(['p-private'], $this->pidsFrom($index->search('alpha', isOwner: true)));
        self::assertSame(['p-unlisted'], $this->pidsFrom($index->search('beta', isOwner: true)));
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', isOwner: true)));

        self::assertSame([], $this->pidsFrom($index->search('alpha', isOwner: false)), 'anonymous must never find a private page via search');
        self::assertSame([], $this->pidsFrom($index->search('beta', isOwner: false)), 'anonymous must never find an unlisted page via search (listed ≠ reachable)');
        self::assertSame(['p-public'], $this->pidsFrom($index->search('gamma', isOwner: false)));
    }

    public function testTreeListingObeysVisibility(): void
    {
        $path = $this->seedPages();
        $index = $this->reopen($path);

        self::assertSame(
            ['p-private', 'p-public', 'p-unlisted'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', isOwner: true))
        );

        self::assertSame(
            ['p-public'],
            $this->pidsFrom($index->listNamespace('reports:mri:mioveni', isOwner: false))
        );
    }

    public function testSitemapListingObeysVisibility(): void
    {
        $path = $this->seedPages();
        $index = $this->reopen($path);

        self::assertCount(3, $index->listSitemap(isOwner: true));

        $anonSitemap = $index->listSitemap(isOwner: false);
        self::assertCount(1, $anonSitemap);
        self::assertSame(self::PUBLIC_PATH, $anonSitemap[0]['path']);
    }

    public function testDirectApiAccessAllowsUnlistedButNotPrivateForAnonymous(): void
    {
        $path = $this->seedPages();
        $index = $this->reopen($path);

        self::assertNotNull($index->findByPath(self::PRIVATE_PATH, isOwner: true));
        self::assertNotNull($index->findByPath(self::UNLISTED_PATH, isOwner: true));
        self::assertNotNull($index->findByPath(self::PUBLIC_PATH, isOwner: true));

        self::assertNull($index->findByPath(self::PRIVATE_PATH, isOwner: false), 'private must 404 for anonymous, not just be hidden from listings');
        self::assertNotNull($index->findByPath(self::UNLISTED_PATH, isOwner: false), 'unlisted must remain reachable by direct path for anonymous');
        self::assertNotNull($index->findByPath(self::PUBLIC_PATH, isOwner: false));

        self::assertNull($index->findByPath('reports:mri:mioveni:does-not-exist', isOwner: false));
    }

    /**
     * Only the file path is returned: every call site reopens it via
     * reopen(), deliberately, to prove read methods work against a freshly
     * connected Sqlite instance and not just the one that wrote the data.
     */
    private function seedPages(): string
    {
        [$index, $path] = $this->newIndex();

        $index->index($this->snapshot('p-private', self::PRIVATE_PATH, [], 'alpha content', ['visibility' => 'private']));
        $index->index($this->snapshot('p-unlisted', self::UNLISTED_PATH, [], 'beta content', ['visibility' => 'unlisted']));
        $index->index($this->snapshot('p-public', self::PUBLIC_PATH, [], 'gamma content', ['visibility' => 'public']));

        return $path;
    }

    private function reopen(string $path): \Reporion\Index\Sqlite
    {
        return new \Reporion\Index\Sqlite($path, $this->migrationsDir);
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
