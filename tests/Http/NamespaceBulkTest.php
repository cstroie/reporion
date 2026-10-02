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
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FlatFile;

/**
 * Roadmap phase 18: the namespace index's bulk Move / Tag (POST /{ns}:,
 * confirm then apply) and Export (POST /export/bundle.zip), and its
 * "Recent activity here" panel. Access is checked per page and, for a
 * move, at the destination too.
 */
final class NamespaceBulkTest extends HttpTestCase
{
    private const NS = 'reports:mri:mioveni';
    private const A = 'reports:mri:mioveni:260101-test-a';
    private const B = 'reports:mri:mioveni:260102-test-b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $users = new FlatFileUserStore($this->dataRoot);
        $users->create('mihai', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $users->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Viewer)]);
        $storage = $this->storage();
        $storage->create(self::A, ['title' => 'Exam A', 'visibility' => 'private', 'accession' => 'MV-MR-26-0001'], 'Text A.', 'owner');
        $storage->create(self::B, ['title' => 'Exam B', 'visibility' => 'private', 'accession' => 'MV-MR-26-0002'], 'Text B.', 'owner');
    }

    public function testMoveShowsTheSelectionThenMovesEveryPageAndFixesLinksInOnePass(): void
    {
        $this->storage()->create('docs:links', ['title' => 'Links', 'visibility' => 'private'], '[a](' . self::A . ') and [b](/' . self::B . ')', 'owner');

        $confirm = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'move', 'paths' => [self::A, self::B]]));
        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('Move 2 page(s)', $confirm->body);
        self::assertStringContainsString('name="step" value="apply"', $confirm->body);

        $apply = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'move', 'step' => 'apply', 'to' => 'reports:mri:pitesti', 'paths' => [self::A, self::B]]));
        self::assertSame(302, $apply->status);
        self::assertSame('/' . self::NS . ':?done=move&n=2&failed=0', $apply->headers['Location']);

        self::assertSame(200, $this->as('mihai', 'GET', '/reports:mri:pitesti:260101-test-a')->status);
        self::assertSame(301, $this->as('mihai', 'GET', '/' . self::A)->status, 'the old path keeps redirecting');
        $links = $this->storage()->read('docs:links');
        self::assertSame("[a](reports:mri:pitesti:260101-test-a) and [b](/reports:mri:pitesti:260102-test-b)\n", $links->body);
        self::assertSame(2, $links->rev, 'both links fixed in one new revision, not one per moved page');
    }

    public function testMoveIntoANamespaceTheEditorCannotWriteIsRefusedAndMovesNothing(): void
    {
        $apply = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'move', 'step' => 'apply', 'to' => 'reports:ct:pitesti', 'paths' => [self::A]]));

        self::assertSame(422, $apply->status);
        self::assertSame(200, $this->as('owner', 'GET', '/' . self::A)->status);
    }

    public function testACollisionFailsThatPageOnlyAndIsCounted(): void
    {
        $this->storage()->create('reports:mri:pitesti:260101-test-a', ['title' => 'Already there', 'visibility' => 'private'], 'x', 'owner');

        $apply = $this->as('owner', 'POST', '/' . self::NS . ':', $this->form(['action' => 'move', 'step' => 'apply', 'to' => 'reports:mri:pitesti', 'paths' => [self::A, self::B]]));

        self::assertSame('/' . self::NS . ':?done=move&n=1&failed=1', $apply->headers['Location']);
        $index = $this->as('owner', 'GET', '/' . self::NS . ':', query: ['done' => 'move', 'n' => '1', 'failed' => '1']);
        self::assertStringContainsString('Moved 1 page(s).', $index->body);
        self::assertStringContainsString('1 could not be moved', $index->body);
    }

    /**
     * paths[] is caller input: a page in another namespace, or one the
     * caller cannot see, is dropped — never acted on.
     */
    public function testPathsOutsideThisNamespaceOrOutOfReachAreDropped(): void
    {
        $this->storage()->create('reports:mri:other:260103-test-c', ['title' => 'C', 'visibility' => 'private'], 'x', 'owner');
        $this->storage()->create('reports:ct:x:260104-test-d', ['title' => 'D', 'visibility' => 'private'], 'x', 'owner');

        $apply = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'tag', 'step' => 'apply', 'tag' => 'bulk', 'paths' => [self::A, 'reports:mri:other:260103-test-c', 'reports:ct:x:260104-test-d']]));

        self::assertStringContainsString('n=1', $apply->headers['Location']);
        self::assertSame(['bulk'], $this->storage()->read(self::A)->frontmatter['tags']);
        self::assertArrayNotHasKey('tags', $this->storage()->read('reports:mri:other:260103-test-c')->frontmatter);
        self::assertArrayNotHasKey('tags', $this->storage()->read('reports:ct:x:260104-test-d')->frontmatter);
    }

    public function testTagAddsAndRemovesLeavingSignedReportsAlone(): void
    {
        $this->storage()->sign(self::B, 'owner', []);

        $add = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'tag', 'step' => 'apply', 'tag' => 'teaching', 'op' => 'add', 'paths' => [self::A, self::B]]));
        self::assertSame('/' . self::NS . ':?done=tag&n=1&signed=1', $add->headers['Location']);
        self::assertSame(['teaching'], $this->storage()->read(self::A)->frontmatter['tags']);
        self::assertSame('signed', $this->storage()->read(self::B)->status, 'a signed report is not turned back into a draft (D3)');

        $remove = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'tag', 'step' => 'apply', 'tag' => 'teaching', 'op' => 'remove', 'paths' => [self::A]]));
        self::assertStringContainsString('done=untag&n=1', $remove->headers['Location']);
        self::assertArrayNotHasKey('tags', $this->storage()->read(self::A)->frontmatter);
    }

    public function testDeleteAsksFirstThenSendsUnsignedPagesToTheTrashAndLeavesSignedOnes(): void
    {
        $this->storage()->sign(self::B, 'owner', []);

        $confirm = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'delete', 'paths' => [self::A, self::B]]));
        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('Delete 2 page(s)', $confirm->body);
        self::assertSame(200, $this->as('owner', 'GET', '/' . self::A)->status, 'nothing deleted by the first step');

        $apply = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'delete', 'step' => 'apply', 'paths' => [self::A, self::B]]));
        self::assertSame('/' . self::NS . ':?done=delete&n=1&failed=0&signed=1', $apply->headers['Location']);
        self::assertSame(404, $this->as('owner', 'GET', '/' . self::A)->status);
        self::assertSame(200, $this->as('owner', 'GET', '/' . self::B)->status, 'a signed report is left as it is');
        self::assertStringContainsString('"action":"page.delete"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
        $index = $this->as('owner', 'GET', '/' . self::NS . ':', query: ['done' => 'delete', 'n' => '1', 'failed' => '0', 'signed' => '1']);
        self::assertStringContainsString('Deleted 1 page(s).', $index->body);
    }

    public function testAViewerCannotDeleteInBulk(): void
    {
        self::assertSame(404, $this->as('ana', 'POST', '/' . self::NS . ':', $this->form(['action' => 'delete', 'step' => 'apply', 'paths' => [self::A]]))->status);
        self::assertSame(200, $this->as('owner', 'GET', '/' . self::A)->status);
    }

    public function testAnEmptyTagIsRefusedOnTheConfirmPage(): void
    {
        $apply = $this->as('mihai', 'POST', '/' . self::NS . ':', $this->form(['action' => 'tag', 'step' => 'apply', 'tag' => '  ', 'paths' => [self::A]]));

        self::assertSame(422, $apply->status);
        self::assertStringContainsString('role="alert"', $apply->body);
    }

    public function testAViewerCanSelectAndExportButNotMoveOrTag(): void
    {
        $index = $this->as('ana', 'GET', '/' . self::NS . ':', query: ['year' => 'all']);
        self::assertStringContainsString('name="paths[]"', $index->body);
        self::assertStringContainsString('/export/bundle.zip', $index->body);
        self::assertStringNotContainsString('value="move"', $index->body);

        self::assertSame(404, $this->as('ana', 'POST', '/' . self::NS . ':', $this->form(['action' => 'move', 'paths' => [self::A]]))->status);
    }

    public function testAnonymousGetsNoSelectionAndNoBulkRoutes(): void
    {
        $this->storage()->create(self::NS . ':260105-test-e', ['title' => 'Public', 'visibility' => 'public'], 'x', 'owner');

        $index = Kernel::boot($this->config)->handle(new Request('GET', '/' . self::NS . ':'));
        self::assertSame(200, $index->status);
        self::assertStringNotContainsString('name="paths[]"', $index->body);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('POST', '/' . self::NS . ':', body: $this->form(['action' => 'tag', 'paths' => [self::A]])))->status);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('POST', '/export/bundle.zip', body: $this->form(['paths' => [self::A]])))->status);
    }

    public function testExportIsAZipOfEachSignedReportsOwnPdfAndCountsTheDraftsLeftOut(): void
    {
        $this->storage()->sign(self::A, 'owner', []);

        $zip = $this->as('ana', 'POST', '/export/bundle.zip', $this->form(['ns' => self::NS, 'paths' => [self::A, self::B, 'reports:ct:x:260104-nope']]));

        self::assertSame(200, $zip->status);
        self::assertSame('application/zip', $zip->headers['Content-Type']);
        self::assertStringNotContainsString('test-a', $zip->headers['Content-Disposition'], 'never the patient path (invariant 8)');
        $entries = $this->unzip($zip->body);
        self::assertSame(['MV-MR-26-0001-rev1.pdf', 'NOT-INCLUDED.txt'], array_keys($entries));
        self::assertStringStartsWith('%PDF', $entries['MV-MR-26-0001-rev1.pdf']);
        self::assertStringContainsString('1 selected report(s)', $entries['NOT-INCLUDED.txt']);
        self::assertStringNotContainsString('test-b', $entries['NOT-INCLUDED.txt']);
    }

    public function testExportWithOnlyDraftsOrTooManyPagesExplainsInsteadOfDownloading(): void
    {
        $drafts = $this->as('ana', 'POST', '/export/bundle.zip', $this->form(['ns' => self::NS, 'paths' => [self::A, self::B]]));
        self::assertSame(409, $drafts->status);
        self::assertStringContainsString('the 2 selected report(s) are unsigned drafts', $drafts->body);

        $tooMany = $this->as('ana', 'POST', '/export/bundle.zip', $this->form(['paths' => array_map(static fn (int $i): string => self::NS . ':x' . $i, range(1, 51))]));
        self::assertSame(422, $tooMany->status);
    }

    public function testRecentActivityListsTheNewestChangesHere(): void
    {
        $index = $this->as('mihai', 'GET', '/' . self::NS . ':');

        self::assertStringContainsString('Recent activity here', $index->body);
        self::assertMatchesRegularExpression('#rev 1 · <a href="/' . preg_quote(self::B, '#') . '">260102-test-b</a>#', $index->body);
    }

    /** @param array<string, mixed> $fields */
    private function form(array $fields): string
    {
        return http_build_query($fields);
    }

    /**
     * Read back with PhpWord's bundled PCLZip — a reader independent of
     * Support\Zip, which wrote it (the server has no ext-zip).
     *
     * @return array<string, string>
     */
    private function unzip(string $bytes): array
    {
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = $this->dataRoot . '/bundle.zip';
        file_put_contents($file, $bytes);
        $list = (new \PclZip($file))->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
        self::assertIsArray($list);
        $entries = [];
        foreach ($list as $entry) {
            self::assertSame('ok', $entry['status']);
            $entries[$entry['filename']] = $entry['content'];
        }

        return $entries;
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @param array<string, string> $query */
    private function as(string $username, string $method, string $path, string $body = '', array $query = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $cookie], body: $body));
    }
}
