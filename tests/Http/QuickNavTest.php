<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\Pins;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * The quick-navigation list (Http\QuickNav): the top nav's pin menu, the
 * palette's empty state, and POST /profile/pins.
 */
final class QuickNavTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->create('ana', 'x', false, [new Grant('reports:mri', GrantRole::Editor)]);
        $this->createPage('reports:mri:mioveni:260101-test-name', 'private', 'Test Name', "## Exam\n\nText.");
        $this->createPage('reports:ct:mioveni:260102-test-other', 'private', 'Test Other', "## Exam\n\nText.");
        $this->createPage('templates:mri:cerebral', 'private', 'Cerebral', 'Text.');
        $this->createPage('templates:snippets:mri:norm', 'private', 'Norm', 'Text.');
        $this->createPage('templates:ct:torace', 'private', 'Torace', 'Text.');
    }

    public function testPinningAndUnpinningANamespaceRoundTripsToTheMenu(): void
    {
        $pin = $this->as('owner', 'POST', '/profile/pins', ['ns' => 'reports:ct:', 'pin' => '1', 'return_to' => '/reports:ct:']);
        self::assertSame(302, $pin->status);
        self::assertSame('/reports:ct:', $pin->headers['Location']);
        self::assertSame(['reports:ct'], (new FlatFileUserStore($this->dataRoot))->find('owner')?->pins);

        $page = $this->as('owner', 'GET', '/reports:ct:');
        self::assertStringContainsString('href="/reports:ct:"><i class="ph ph-push-pin"></i>reports:ct</a>', $page->body);
        self::assertStringContainsString('Unpin reports:ct', $page->body);
        self::assertStringContainsString('"path":"reports:ct:","title":"reports:ct"', $page->body, 'palette empty state');

        $this->as('owner', 'POST', '/profile/pins', ['ns' => 'reports:ct', 'pin' => '0']);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('owner')?->pins);
    }

    public function testASavedViewRoundTripsToTheMenuWithOnlySearchParameters(): void
    {
        $save = $this->as('owner', 'POST', '/profile/views', ['name' => ' MR  unsigned ', 'query' => 'q=menisc&modality=MR&status=draft&page=3&evil=1', 'return_to' => '/search?q=menisc']);
        self::assertSame(302, $save->status);
        self::assertSame('/search?q=menisc', $save->headers['Location']);
        self::assertSame([['name' => 'MR  unsigned', 'query' => 'q=menisc&modality=MR&status=draft']], (new FlatFileUserStore($this->dataRoot))->find('owner')?->views, 'page and unknown keys dropped');

        $this->as('owner', 'POST', '/profile/views', ['name' => 'mr  UNSIGNED', 'query' => 'q=fisura']);
        self::assertSame([['name' => 'mr  UNSIGNED', 'query' => 'q=fisura']], (new FlatFileUserStore($this->dataRoot))->find('owner')?->views, 'the same name, any case: replaced');

        $page = $this->as('owner', 'GET', '/search?q=menisc');
        self::assertStringContainsString('href="/search?q=fisura"><i class="ph ph-funnel"></i>mr  UNSIGNED</a>', $page->body);
        self::assertStringContainsString('action="/profile/views"', $page->body, 'Save this search as a view');

        $this->as('owner', 'POST', '/profile/views', ['name' => 'MR UNSIGNED', 'remove' => '1']);
        self::assertSame([['name' => 'mr  UNSIGNED', 'query' => 'q=fisura']], (new FlatFileUserStore($this->dataRoot))->find('owner')?->views, 'a different name');
        $this->as('owner', 'POST', '/profile/views', ['name' => 'mr  unsigned', 'remove' => '1']);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('owner')?->views);

        self::assertSame(302, $this->as('owner', 'POST', '/profile/views', ['name' => 'Nothing', 'query' => 'page=2'])->status);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('owner')?->views, 'no search in it: nothing saved');
    }

    public function testAPinWhoseNamespaceIsGoneCanStillBeUnpinned(): void
    {
        $this->as('owner', 'POST', '/profile/pins', ['ns' => 'docs:gone', 'pin' => '1']);

        // Not the namespace on screen, and nothing there: only its own × removes it
        $body = $this->as('owner', 'GET', '/reports:ct:')->body;
        self::assertMatchesRegularExpression('#name="ns" value="docs:gone">\s*<input type="hidden" name="pin" value="0">#', $body);
        self::assertStringNotContainsString('Pin docs:gone', $body);

        $this->as('owner', 'POST', '/profile/pins', ['ns' => 'docs:gone', 'pin' => '0', 'return_to' => '/reports:ct:']);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('owner')?->pins);
    }

    public function testAReportLeafOrAnOffSiteReturnIsNeverStored(): void
    {
        $response = $this->as('owner', 'POST', '/profile/pins', ['ns' => 'reports:mri:mioveni:260101-test-name', 'pin' => '1', 'return_to' => '//evil.example/']);

        self::assertSame(302, $response->status);
        self::assertSame('/', $response->headers['Location']);
        self::assertSame([], (new FlatFileUserStore($this->dataRoot))->find('owner')?->pins);
    }

    public function testThePinListIsCapped(): void
    {
        for ($i = 0; $i <= Pins::MAX; $i++) {
            $this->as('owner', 'POST', '/profile/pins', ['ns' => 'docs:n' . $i, 'pin' => '1']);
        }

        self::assertCount(Pins::MAX, (new FlatFileUserStore($this->dataRoot))->find('owner')?->pins ?? []);
    }

    public function testAReportOffersItsModalitysTemplatesAndSnippets(): void
    {
        $body = $this->as('owner', 'GET', '/reports:mri:mioveni:260101-test-name')->body;

        self::assertStringContainsString('href="/templates:mri:"', $body);
        self::assertStringContainsString('href="/templates:snippets:mri:"', $body);
        self::assertStringNotContainsString('href="/templates:ct:"', $body);
        self::assertStringNotContainsString('260101-test-name:', $body, 'a report leaf is never a namespace link');
    }

    public function testATemplatesNamespaceOffersTheModalitysReports(): void
    {
        $body = $this->as('owner', 'GET', '/templates:mri:')->body;

        self::assertStringContainsString('href="/reports:mri:"', $body);
        self::assertStringContainsString('href="/templates:snippets:mri:"', $body);
    }

    public function testRelatedLinksOnlyNameNamespacesTheCallerCanSee(): void
    {
        // ana's grant is reports:mri only: the private templates are not hers to see
        $body = $this->as('ana', 'GET', '/reports:mri:mioveni:260101-test-name')->body;

        self::assertStringNotContainsString('href="/templates:mri:"', $body);
        self::assertStringNotContainsString('href="/templates:snippets:mri:"', $body);
        self::assertStringContainsString('Pin reports:mri:mioveni', $body);
    }

    public function testAnonymousGetsNoMenuAndCannotPin(): void
    {
        $this->createPage('docs:guide', 'public', 'Guide', 'Text.');
        $body = Kernel::boot($this->config)->handle(new Request('GET', '/docs:'))->body;

        self::assertStringNotContainsString('ph-push-pin', $body);
        self::assertStringNotContainsString('"quick":[{', $body);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('POST', '/profile/pins', body: 'ns=docs&pin=1'))->status);
    }

    /** @param array<string, string> $fields */
    private function as(string $username, string $method, string $path, array $fields = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue($username);

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: http_build_query($fields)));
    }
}
