<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET /{ns}: end to end through the real Kernel (Controller\NamespaceController).
 */
final class NamespaceIndexTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testAddDescriptionIsAButtonBesideNewPageUntilThereIsOne(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');

        $without = $this->ownerRequest('/reports:mri:')->body;
        self::assertSame(1, preg_match('#<div class="wk-actions">(.*?)</div>#s', $without, $m));
        // TODO 13: "Add description" jumps straight to the edit form, no /new step
        self::assertStringContainsString('href="/reports:mri/edit"', $m[1], 'in the header actions, with New page');
        self::assertStringContainsString('Add description', $m[1]);

        $this->createPage('reports:mri', 'private', 'MRI', 'All MRI reports.');
        $with = $this->ownerRequest('/reports:mri:')->body;
        self::assertStringNotContainsString('Add description', $with, 'gone once the namespace has its description');
        self::assertStringContainsString('href="/reports:mri/edit"', $with, '"Edit description" instead, beside New page');
        self::assertStringContainsString('All MRI reports.', $with);
        // No card/panel around the description body (TODO 13)
        self::assertStringNotContainsString('Namespace description', $with);
    }

    public function testANamespaceWithADescriptionIsCalledByItsTitle(): void
    {
        $this->createPage('reports:mri:medicline:a', 'private', 'Exam A', 'body a');
        $this->createPage('reports:mri:medicline', 'private', 'MEDIC line', 'The Medicline site.');

        $own = $this->ownerRequest('/reports:mri:medicline:')->body;
        self::assertStringContainsString('<h1 class="wk-doc-title">MEDIC line</h1>', $own);
        self::assertStringContainsString('<title>MEDIC line', $own, 'the browser tab too');
        self::assertStringContainsString('<span aria-current="page">medicline</span>', $own, 'the path stays in the crumbs');

        $parent = $this->ownerRequest('/reports:mri:')->body;
        self::assertMatchesRegularExpression('#<b>MEDIC line</b>\s*<span class="wk-dim wk-mono">medicline</span>#', $parent, 'the card on the parent namespace');
    }

    /**
     * TODO 13: the "by" column shows the account's display name, not the
     * bare username — set once per boot (Kernel::boot(), display_name()).
     */
    public function testByColumnShowsTheAccountsDisplayName(): void
    {
        (new FlatFileUserStore($this->dataRoot))->save(
            (new FlatFileUserStore($this->dataRoot))->find('owner')->with(displayName: 'Dr. Ana Popescu')
        );
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');

        $body = $this->ownerRequest('/reports:mri:mioveni:')->body;

        self::assertStringContainsString('<td class="wk-mono">Dr. Ana Popescu</td>', $body);
        self::assertStringNotContainsString('<td class="wk-mono">owner</td>', $body);
    }

    /** The PACS column, right after the patient name: an icon when the report has a study_uid */
    public function testReportsNamespaceShowsAPacsLinkColumn(): void
    {
        $storage = new \Reporion\Storage\FlatFile($this->dataRoot, new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
        $storage->create('reports:mri:mioveni:260928-linked', ['title' => 'LINKED', 'visibility' => 'private', 'study_uid' => '1.2.826.0.1.3680043.2.1125.1.1'], "# LINKED\n", 'owner');
        $storage->create('reports:mri:mioveni:260928-plain', ['title' => 'PLAIN', 'visibility' => 'private'], "# PLAIN\n", 'owner');

        $body = $this->ownerRequest('/reports:mri:mioveni:')->body;

        self::assertStringContainsString('<th>PACS</th>', $body);
        self::assertSame(1, substr_count($body, 'ph ph-link wk-signed-mark'), 'only the linked report');
        self::assertMatchesRegularExpression('#LINKED</a>.*?</td>\s*<td><i class="ph ph-link#s', $body, 'in the cell after the name');
    }

    /**
     * A subnamespace card shows its description page's summary as a subtitle
     * (TODO 13) — an ordinary, already-generic frontmatter field, no schema change.
     */
    public function testSubnamespaceCardShowsSummary(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->create(
            'reports:mri:mioveni',
            ['title' => 'Mioveni', 'visibility' => 'private', 'summary' => 'The MRI site in Mioveni.'],
            'Spitalul din Mioveni.',
            'owner'
        );

        $body = $this->ownerRequest('/reports:mri:')->body;

        self::assertStringContainsString('<span class="wk-row-s">The MRI site in Mioveni.</span>', $body);
    }

    /**
     * A subnamespace card's background tint (TODO 13) follows its
     * description page's `priority` — decorative only, unset by default,
     * read from disk (not an indexed column, unlike title/summary).
     */
    public function testSubnamespaceCardCarriesItsPriority(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $this->createPage('reports:mri:urgent:a', 'private', 'Exam A', 'body a');
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        $storage = new \Reporion\Storage\FlatFile($this->dataRoot, $index);
        $storage->create('reports:mri:mioveni', ['title' => 'Mioveni', 'visibility' => 'private'], 'no priority set', 'owner');
        $storage->create('reports:mri:urgent', ['title' => 'Urgent', 'visibility' => 'private', 'priority' => 'high'], 'flagged', 'owner');

        $body = $this->ownerRequest('/reports:mri:')->body;

        self::assertStringContainsString('data-priority=""', $body, 'no priority set: the attribute is present but empty');
        self::assertStringContainsString('data-priority="high"', $body);
    }

    public function testOwnerSeesSubnamespacesAndPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $this->createPage('reports:mri:campulung:b', 'private', 'Exam B', 'body b');

        $response = $this->ownerRequest('/reports:mri:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('mioveni', $response->body);
        self::assertStringContainsString('campulung', $response->body);
    }

    /**
     * GET /: — the root namespace ($ns === ''): every top-level namespace
     * in the tree is one of its "sub-namespaces" (Index\Sqlite::
     * listSubnamespaces() special-cases $ns === '').
     */
    public function testRootShowsLevelOneNamespaces(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');
        $this->createPage('templates:mri:default', 'private', 'Template', 'body b');

        $response = $this->ownerRequest('/:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('>reports<', $response->body);
        self::assertStringContainsString('>templates<', $response->body);
    }

    /**
     * A page with no namespace of its own (ns === '') is top-level, not a
     * sub-namespace of the root — it must show up in the root's own pages
     * table, never as a card.
     */
    public function testRootListsTopLevelPagesDirectly(): void
    {
        $this->createPage('home', 'private', 'Home', 'body');

        $response = $this->ownerRequest('/:');

        self::assertSame(200, $response->status);
        // A row in the pages table (not only a line in "Recent activity here")
        self::assertMatchesRegularExpression('#<td><a href="/home">Home</a></td>#', $response->body);
    }

    public function testOwnerSeesDirectChildPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body a');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('reports:mri:mioveni:a', $response->body);
        self::assertStringContainsString('Exam A', $response->body);
    }

    /**
     * WikiNsIndex mockup columns: page, title, region, status, updated, by.
     * region lives in the page_regions child table (D29); "by" is the
     * updated_by column that listNamespace() already selected but the
     * template never rendered.
     */
    public function testPagesTableShowsRegionAndUpdatedBy(): void
    {
        $this->createPageWithRegion('reports:mri:mioveni:a', 'private', 'Exam A', 'body a', ['neuro'], 'barbu');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('neuro', $response->body);
        self::assertStringContainsString('barbu', $response->body);
    }

    private function createPageWithRegion(string $path, string $visibility, string $title, string $body, array $region, string $author): void
    {
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->create(
            $path,
            ['title' => $title, 'visibility' => $visibility, 'region' => $region],
            $body,
            $author
        );
    }

    /**
     * Year-filter cards above the pages table, one per distinct study_date
     * year — only rendered once there's more than one year to filter by.
     * No explicit ?year= defaults to the most recent year (an archive
     * namespace shouldn't dump every page on first load); "All"
     * (?year=all) is its own explicit choice that shows everything.
     */
    public function testYearCardsDefaultToTheMostRecentYear(): void
    {
        $this->createPageWithStudyDate('reports:ct:scuc:250110-a', 'Exam 2025', 'body', '2025-01-10T09:00:00+03:00');
        $this->createPageWithStudyDate('reports:ct:scuc:260305-b', 'Exam 2026', 'body', '2026-03-05T09:00:00+03:00');

        $default = $this->ownerRequest('/reports:ct:scuc:');
        self::assertSame(200, $default->status);
        self::assertStringContainsString('wk-yearcard', $default->body);
        // The drawer's unrelated "recently updated here" list (Http\
        // ChromeVars::shell() -> listWorklist(), no year filter) mentions
        // both regardless, so assert on the table row markup specifically.
        self::assertStringContainsString('<td><a href="/reports:ct:scuc:260305-b">Exam 2026</a></td>', $default->body);
        self::assertStringNotContainsString('<td><a href="/reports:ct:scuc:250110-a">Exam 2025</a></td>', $default->body);

        $only2025 = Kernel::boot($this->config)->handle(new Request('GET', '/reports:ct:scuc:', query: ['year' => '2025'], cookies: ['reporion' => $this->issueCookie('owner')]));
        self::assertStringContainsString('<td><a href="/reports:ct:scuc:250110-a">Exam 2025</a></td>', $only2025->body);
        self::assertStringNotContainsString('<td><a href="/reports:ct:scuc:260305-b">Exam 2026</a></td>', $only2025->body);
        self::assertStringContainsString('wk-yearcard-on', $only2025->body);

        $everything = Kernel::boot($this->config)->handle(new Request('GET', '/reports:ct:scuc:', query: ['year' => 'all'], cookies: ['reporion' => $this->issueCookie('owner')]));
        self::assertStringContainsString('<td><a href="/reports:ct:scuc:250110-a">Exam 2025</a></td>', $everything->body);
        self::assertStringContainsString('<td><a href="/reports:ct:scuc:260305-b">Exam 2026</a></td>', $everything->body);
    }

    /**
     * Under reports: the table is newest study first (undated last); any
     * other namespace stays alphabetical by path.
     */
    public function testReportsAreListedNewestStudyFirst(): void
    {
        $this->createPageWithStudyDate('reports:ct:scuc:260105-a', 'Exam Jan', 'body', '2026-01-05T09:00:00+03:00');
        $this->createPageWithStudyDate('reports:ct:scuc:260305-b', 'Exam Mar', 'body', '2026-03-05');
        $this->createPageWithStudyDate('reports:ct:scuc:260210-c', 'Exam Feb', 'body', '2026-02-10T10:00:00+03:00');
        $this->createPage('reports:ct:scuc:notes', 'private', 'Notes', 'body');
        $this->createPage('docs:b', 'private', 'Doc B', 'body');
        $this->createPage('docs:a', 'private', 'Doc A', 'body');

        $reports = $this->ownerRequest('/reports:ct:scuc:')->body;
        preg_match_all('#<td><a href="/reports:ct:scuc:([^"]+)">#', $reports, $m);
        self::assertSame(['260305-b', '260210-c', '260105-a', 'notes'], $m[1]);

        $docs = $this->ownerRequest('/docs:')->body;
        preg_match_all('#<td><a href="/docs:([^"]+)">#', $docs, $m);
        self::assertSame(['a', 'b'], $m[1]);
    }

    /**
     * Under reports: the date column is the exam date (study_date, date
     * only), not the page's last update; elsewhere it stays "updated".
     */
    public function testReportsShowTheExamDateInsteadOfTheLastUpdate(): void
    {
        $this->createPageWithStudyDate('reports:ct:scuc:260210-c', 'Exam Feb', 'body', '2026-02-10T10:00:00+03:00');
        $this->createPage('docs:a', 'private', 'Doc A', 'body');

        $reports = $this->ownerRequest('/reports:ct:scuc:')->body;
        self::assertStringContainsString('<th>Exam Date</th>', $reports);
        self::assertStringNotContainsString('<th>updated</th>', $reports);
        self::assertStringContainsString('<td class="wk-mono">10 Feb 2026</td>', $reports);

        $docs = $this->ownerRequest('/docs:')->body;
        self::assertStringContainsString('<th>updated</th>', $docs);
        self::assertStringNotContainsString('Exam Date', $docs);
    }

    /**
     * A single distinct year gives nothing worth filtering — no cards.
     */
    public function testYearCardsAreOmittedWhenOnlyOneYearIsPresent(): void
    {
        $this->createPageWithStudyDate('reports:ct:scuc:250110-a', 'Exam 2025', 'body', '2025-01-10T09:00:00+03:00');
        $this->createPage('reports:ct:scuc:notes', 'private', 'Notes', 'body');

        $response = $this->ownerRequest('/reports:ct:scuc:');

        self::assertStringNotContainsString('wk-yearcards', $response->body);
        // …and no default narrowing to that year either: with no "All" card
        // to click, an undated page would otherwise be unreachable here
        self::assertStringContainsString('<td><a href="/reports:ct:scuc:notes">Notes</a></td>', $response->body);
    }

    private function createPageWithStudyDate(string $path, string $title, string $body, string $studyDate): void
    {
        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->create(
            $path,
            ['title' => $title, 'visibility' => 'private', 'study_date' => $studyDate],
            $body,
            'owner'
        );
    }

    public function testEmptyNamespaceForThisCaller404s(): void
    {
        $response = $this->ownerRequest('/reports:mri:');

        self::assertSame(404, $response->status);
    }

    public function testAnonymousOnlySeesPublicPages(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'public', 'Public exam', 'body');
        $this->createPage('reports:mri:mioveni:b', 'private', 'Private exam', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Public exam', $response->body);
        self::assertStringNotContainsString('Private exam', $response->body);
    }

    public function testAnonymousGetsNotFoundWhenNothingIsPublicHere(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Private exam', 'body');

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:'));

        self::assertSame(404, $response->status);
    }

    /**
     * The leak-prevention requirement carried over from
     * Index\Sqlite::listSubnamespaces() itself: an editor granted only on
     * reports:mri must never see reports:ct mentioned anywhere on this page,
     * even indirectly via a sub-namespace card.
     */
    public function testEditorGrantedOnlyOnMriNeverSeesCtMentioned(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');
        $this->createPage('reports:ct:mioveni:b', 'private', 'Exam B', 'body');

        $response = $this->authenticatedGet('mihai', '/reports:');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('>mri<', $response->body);
        self::assertStringNotContainsString('reports:ct', $response->body);
        self::assertStringNotContainsString('>ct<', $response->body);
    }

    public function testViewerWithGrantCanSeeButNotCreate(): void
    {
        $this->createViewer('ana', 'reports:mri');
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('/new"', $response->body);
    }

    public function testNewPageLinkPrefillsThePathFromTheNamespace(): void
    {
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        $response = $this->ownerRequest('/reports:mri:mioveni:');

        self::assertStringContainsString('href="/reports:mri:mioveni/new" title="New report"><i class="ph ph-plus"></i><span class="wk-btn-label">New report<', $response->body);
    }

    public function testRegionColumnIsForReportsOnly(): void
    {
        $this->createPage('docs:a', 'private', 'Doc A', 'body');
        $this->createPage('reports:mri:mioveni:a', 'private', 'Exam A', 'body');

        self::assertStringNotContainsString('>region</th>', $this->ownerRequest('/docs:')->body);
        self::assertStringContainsString('>region</th>', $this->ownerRequest('/reports:mri:mioveni:')->body);
    }

    public function testNewButtonOutsideReportsSaysNewPage(): void
    {
        $this->createPage('docs:a', 'private', 'Doc A', 'body');

        $response = $this->ownerRequest('/docs:');

        self::assertStringContainsString('href="/docs/new" title="New page">', $response->body);
    }

    private function createEditor(string $username, string $namespace): void
    {
        (new FlatFileUserStore($this->dataRoot))->create(
            $username,
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant($namespace, GrantRole::Editor)]
        );
    }

    private function createViewer(string $username, string $namespace): void
    {
        (new FlatFileUserStore($this->dataRoot))->create(
            $username,
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant($namespace, GrantRole::Viewer)]
        );
    }

    private function ownerRequest(string $path): Response
    {
        return $this->authenticatedGet('owner', $path);
    }

    private function authenticatedGet(string $username, string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: ['reporion' => $this->issueCookie($username)]));
    }

    private function issueCookie(string $username): string
    {
        return (new Session(
            (string) $this->config['auth']['session_secret'],
            (string) $this->config['auth']['session_name'],
            (int) $this->config['auth']['session_lifetime'],
            new FlatFileUserStore($this->dataRoot),
        ))->issue($username);
    }
}
