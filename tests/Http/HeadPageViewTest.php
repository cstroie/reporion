<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Storage\FileTree;

/** D61: a head page shows the namespace of its name under its text, in its `children` mode */
final class HeadPageViewTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $s = $this->storage();
        $s->create('wiki:guide:intro', ['title' => 'Introducere', 'visibility' => 'public'], "# Introducere\n\nPrimul capitol.\n\n## Detalii\n\nText.", 'owner');
        $s->create('wiki:guide:part', ['title' => 'Partea a doua', 'visibility' => 'public'], "# Partea a doua\n\nAl doilea.", 'owner');
        $s->create('wiki:guide:part:deep', ['title' => 'Adânc', 'visibility' => 'public'], 'Adânc.', 'owner');
        $s->create('wiki:guide:secret', ['title' => 'Secret', 'visibility' => 'private'], 'Ascuns.', 'owner');
        $s->create('wiki:guide:_index', ['title' => 'Vechi'], 'Vechi.', 'owner');
    }

    public function testListModeShowsCardsButNotTheLegacyIndex(): void
    {
        $this->head('list');
        $page = $this->get('/wiki:guide', true);

        self::assertStringContainsString('class="wk-children"', $page->body);
        self::assertStringContainsString('href="/wiki:guide:intro"', $page->body);
        self::assertStringContainsString('href="/wiki:guide:part"', $page->body);
        self::assertStringContainsString('href="/wiki:guide:secret"', $page->body);
        self::assertStringNotContainsString('href="/wiki:guide:_index"', $page->body);
        self::assertStringContainsString('href="/wiki:guide:"', $page->body, 'the browsing view is linked');
    }

    public function testAnAnonymousReaderSeesOnlyWhatTheyMayList(): void
    {
        $this->head('list', 'public');
        $page = $this->get('/wiki:guide', false);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('href="/wiki:guide:intro"', $page->body);
        self::assertStringNotContainsString('wiki:guide:secret', $page->body);
        self::assertStringNotContainsString('Ascuns', $page->body);
    }

    public function testTocModeNestsSubHeads(): void
    {
        $this->head('toc');
        $page = $this->get('/wiki:guide', true);

        self::assertStringContainsString('class="wk-children-toc"', $page->body);
        self::assertMatchesRegularExpression('#href="/wiki:guide:part">Partea a doua</a><ul><li><a href="/wiki:guide:part:deep">#', $page->body);
    }

    public function testChaptersModeRendersEachChildWithoutItsTitle(): void
    {
        $this->head('chapters');
        $page = $this->get('/wiki:guide', true);

        self::assertStringContainsString('Primul capitol.', $page->body);
        self::assertStringContainsString('Al doilea.', $page->body);
        self::assertStringContainsString('Adânc.', $page->body, 'a sub-head nests');
        self::assertSame(1, substr_count($page->body, '>Introducere</a></h2>'), 'the chapter heading carries the title once');
        self::assertStringContainsString('id="ch-1-detalii"', $page->body, 'heading ids are prefixed per chapter');
        self::assertStringContainsString('href="#ch-1"', $page->body, 'the table of contents lists the chapters');
    }

    public function testNoneModeOnlyCountsThem(): void
    {
        $this->head('none');
        $page = $this->get('/wiki:guide', true);

        self::assertStringNotContainsString('href="/wiki:guide:intro"', $page->body);
        self::assertStringContainsString('3 page(s) here', $page->body);
    }

    public function testAReportTreeHasNoChildrenSection(): void
    {
        $this->storage()->create('reports:mri:site', ['title' => 'Site'], 'Site.', 'owner');
        $this->storage()->create('reports:mri:site:note', ['title' => 'Note'], 'Note.', 'owner');

        self::assertStringNotContainsString('class="wk-children"', $this->get('/reports:mri:site', true)->body);
    }

    public function testACrumbLinksToItsHeadPageWhenThereIsOne(): void
    {
        $this->head('list');
        $page = $this->get('/wiki:guide:part:deep', true);

        self::assertStringContainsString('<a href="/wiki:guide">guide</a>', $page->body);
        self::assertStringContainsString('<a href="/wiki:guide:part">part</a>', $page->body);
        self::assertStringContainsString('<a href="/wiki:">wiki</a>', $page->body, 'no head page: the listing');
    }

    public function testEditingTheDescriptionAdoptsTheLegacyIndex(): void
    {
        $pid = $this->storage()->read('wiki:guide:_index')->pid;
        self::assertStringContainsString('href="/wiki:guide/edit"', $this->get('/wiki:guide:', true)->body);

        self::assertSame(200, $this->get('/wiki:guide/edit', true)->status);

        $head = $this->storage()->read('wiki:guide');
        self::assertSame($pid, $head->pid, 'the same page, history and all');
        self::assertStringContainsString('Vechi.', $head->body);
        self::assertStringContainsString('href="/wiki:guide"', $this->get('/wiki:guide:', true)->body, 'Open page');
    }

    private function head(string $mode, string $visibility = 'private'): void
    {
        $this->storage()->create('wiki:guide', ['title' => 'Ghid', 'visibility' => $visibility, 'children' => $mode, 'sort' => 'name'], "# Ghid\n\nCapul.", 'owner');
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations'));
    }

    private function get(string $path, bool $signedIn): Response
    {
        $cookies = [];
        if ($signedIn) {
            $cookies[(string) $this->config['auth']['session_name']] = (new Session(
                (string) $this->config['auth']['session_secret'],
                (string) $this->config['auth']['session_name'],
                (int) $this->config['auth']['session_lifetime'],
                new FlatFileUserStore($this->dataRoot),
            ))->issue('owner');
        }

        return Kernel::boot($this->config)->handle(new Request('GET', $path, cookies: $cookies));
    }
}
