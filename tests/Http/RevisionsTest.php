<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Http\Request;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET /{path}/revisions, POST /{path}/revisions/revert, end to end through
 * the real Kernel (Controller\RevisionsController).
 */
final class RevisionsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
    }

    public function testOwnerSeesTheRevisionList(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('There are 2 revisions', $response->body);
        self::assertStringContainsString('current', $response->body);
    }

    /**
     * The page header's Revisions tab must be lit, and there is no
     * standalone "Back to page" button — the Report tab is that link.
     */
    public function testPageTabsShowRevisionsActiveAndBackButtonIsGone(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertStringContainsString('wk-tab" data-on="1" aria-current="page" href="/reports:mri:mioveni:a/revisions"', $response->body);
        self::assertStringContainsString('wk-tab" data-on="" href="/reports:mri:mioveni:a"', $response->body);
        self::assertStringNotContainsString(t('page.back'), $response->body);
    }

    public function testLineStyleDiffPanelRendersWhenFromAndToAreGiven(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'line one',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'line one changed',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            query: ['from' => '1', 'to' => '2', 'style' => 'line'],
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-difftext', $response->body);
        self::assertStringContainsString('line one changed', $response->body);
        preg_match('~<pre class="wk-mono wk-difftext">(.*?)</pre>~s', $response->body, $pre);
        self::assertStringNotContainsString("\n", $pre[1], 'no newline between the lines: in a <pre> it would be a blank line');
    }

    /**
     * Absorbed from the old Compare tab (2026-09-30): word is the default
     * style, a track-changes read rather than a line-oriented patch, and
     * from/to default to previous→current with no query string at all.
     */
    public function testWordStyleIsTheDefaultAndFromToDefaultToPreviousAndCurrent(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'the leziuni are stabile',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'the leziuni sunt stabile',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-worddiff', $response->body);
        self::assertStringNotContainsString('wk-difftext', $response->body);
        self::assertStringContainsString('<del>are</del>', $response->body);
        self::assertStringContainsString('<ins>sunt</ins>', $response->body);
    }

    /**
     * The third style, restored from the old Compare tab's other render
     * (2026-09-30): two full pages through the canonical renderer, side by
     * side — a reading view, not a change view, so no <ins>/<del> at all.
     */
    public function testSideStyleRendersBothRevisionsThroughTheCanonicalRenderer(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => '## Concluzii

Normal.',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => '## Concluzii

Leziune nouă.',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            query: ['from' => '1', 'to' => '2', 'style' => 'side'],
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('wk-cmp', $response->body);
        self::assertStringNotContainsString('wk-worddiff', $response->body);
        self::assertStringNotContainsString('wk-difftext', $response->body);
        self::assertStringContainsString('<p>Normal.</p>', $response->body);
        self::assertStringContainsString('<p>Leziune nouă.</p>', $response->body);
        self::assertStringNotContainsString('<ins>', $response->body);
        self::assertStringNotContainsString('<del>', $response->body);
    }

    public function testNoDiffPanelForASingleRevision(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertStringNotContainsString('wk-difftext', $response->body);
        self::assertStringNotContainsString('wk-worddiff', $response->body);
        self::assertStringContainsString('only one revision', $response->body);
    }

    /**
     * A body too large for Diff::wordsFits() (2026-09-30 incident) falls
     * back to the line style automatically, with a note saying so — never
     * a crash, and never a silent, unexplained style switch.
     */
    public function testWordStyleFallsBackToLineForATooLargeBody(): void
    {
        $big = str_repeat('word ', 3040);
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => $big,
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => $big . 'more',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            query: ['from' => '1', 'to' => '2'],
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('wk-worddiff', $response->body);
        self::assertStringContainsString('wk-difftext', $response->body);
        self::assertStringContainsString(t('revisions.style_fallback'), $response->body);
    }

    public function testRevisionsOfAnUnknownPathIs404(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:does-not-exist/revisions',
            cookies: ['reporion' => $this->issueCookie('owner')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSeeRevisionsOfAPrivatePage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/revisions'));

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCanSeeRevisionsOfAPublicPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'public'],
            'body' => 'v1 body',
        ]);

        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/revisions'));

        self::assertSame(200, $response->status);
    }

    public function testEditorWithGrantCanSeeRevisionsOfAPrivatePageInTheirNamespace(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createEditor('mihai', 'reports:mri');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('mihai')]
        ));

        self::assertSame(200, $response->status);
    }

    public function testEditorWithoutGrantCannotSeeRevisionsOfAPrivatePage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->createEditor('mihai', 'reports:ct');

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('mihai')]
        ));

        self::assertSame(404, $response->status);
    }

    public function testOwnerCanRestoreAnOldRevisionFromTheRevisionsPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/revisions/revert',
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: 'to=1'
        ));

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a/revisions', $response->headers['Location']);

        $followUp = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a', cookies: ['reporion' => $this->issueCookie('owner')]));
        self::assertStringContainsString('v1 body', $followUp->body);
    }

    public function testViewerWithGrantCannotRestoreFromTheRevisionsPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Viewer)]
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/revisions/revert',
            cookies: ['reporion' => $this->issueCookie('ana')],
            body: 'to=1'
        ));

        self::assertSame(404, $response->status);
    }

    public function testViewerSeesNoRestoreButtonOnTheRevisionsPage(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', [
            'path' => 'reports:mri:mioveni:a',
            'meta' => ['title' => 'v1', 'visibility' => 'private'],
            'body' => 'v1 body',
        ]);
        $this->ownerRequest('PUT', '/api/v1/pages/reports:mri:mioveni:a', [
            'meta' => ['title' => 'v2', 'visibility' => 'private'],
            'body' => 'v2 body',
            'base_rev' => 1,
        ]);
        (new FlatFileUserStore($this->dataRoot))->create(
            'ana',
            password_hash('x', PASSWORD_ARGON2ID),
            false,
            [new Grant('reports:mri', GrantRole::Viewer)]
        );

        $response = Kernel::boot($this->config)->handle(new Request(
            'GET',
            '/reports:mri:mioveni:a/revisions',
            cookies: ['reporion' => $this->issueCookie('ana')]
        ));

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('/revisions/revert', $response->body, 'the restore form itself, not just its label, must be absent');
    }

    public function testATemplateIsRevisionZeroAndComparesBodiesOnly(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', ['path' => 'templates:mri:genunchi', 'meta' => ['title' => 'IRM Genunchi', 'visibility' => 'private'], 'body' => "Meniscuri normale.\n\n### Concluzii\n\nFără leziuni."]);
        $this->ownerRequest('POST', '/api/v1/pages', ['path' => 'reports:mri:mioveni:260101-test-a', 'meta' => ['title' => 'Test A', 'visibility' => 'private', 'template' => 'templates:mri:genunchi', 'patient' => ['name' => 'Test A']], 'body' => "# Test A\n\nMeniscuri normale.\n\n### Concluzii\n\nRuptură de menisc medial."]);

        $list = $this->ownerRequest('GET', '/reports:mri:mioveni:260101-test-a/revisions', [])->body;
        self::assertStringContainsString('class="wk-rev-zero"', $list, 'the template, listed below rev 1');
        self::assertStringContainsString('href="/templates:mri:genunchi"', $list);
        self::assertStringContainsString('href="?from=0&amp;to=1', $list);

        $diff = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:260101-test-a/revisions', query: ['from' => '0', 'to' => '1', 'style' => 'line'], cookies: ['reporion' => $this->issueCookie('owner')]))->body;
        self::assertStringContainsString('Ruptură de menisc medial.', $diff);
        self::assertStringNotContainsString('# Test A', $diff, 'the name heading is not compared');
        self::assertStringNotContainsString('visibility: private', $diff, 'nor the frontmatter');
        self::assertStringNotContainsString('name="to" value="0"', $diff, 'revision zero is never restored');
    }

    public function testNoRevisionZeroWithoutAReadableTemplate(): void
    {
        $this->ownerRequest('POST', '/api/v1/pages', ['path' => 'templates:ct:torace', 'meta' => ['title' => 'CT', 'visibility' => 'private'], 'body' => 'Plămâni normali.']);
        $this->ownerRequest('POST', '/api/v1/pages', ['path' => 'reports:mri:mioveni:260101-test-b', 'meta' => ['title' => 'Test B', 'visibility' => 'private', 'template' => 'templates:ct:torace'], 'body' => 'Text.']);
        $this->ownerRequest('POST', '/api/v1/pages', ['path' => 'reports:mri:mioveni:260101-test-c', 'meta' => ['title' => 'Test C', 'visibility' => 'private'], 'body' => 'Text.']);
        $this->createEditor('mihai', 'reports:mri');

        $noGrant = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:260101-test-b/revisions', query: ['from' => '0', 'to' => '1'], cookies: ['reporion' => $this->issueCookie('mihai')]))->body;
        self::assertStringNotContainsString('wk-rev-zero', $noGrant, 'a template the reader cannot read is not shown');
        self::assertStringNotContainsString('Plămâni', $noGrant);

        $none = $this->ownerRequest('GET', '/reports:mri:mioveni:260101-test-c/revisions', [])->body;
        self::assertStringNotContainsString('wk-rev-zero', $none);
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

    /**
     * @param array<string, mixed> $body
     */
    private function ownerRequest(string $method, string $path, array $body): \Reporion\Http\Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            $method,
            $path,
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: (string) json_encode($body),
        ));
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
