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
 * GET/POST /{path}/edit, end to end through the real Kernel
 * (Controller\EditorController) — the write UI gap this closes.
 */
final class EditorTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage('reports:mri:mioveni:a', 'private', 'v1 title', 'v1 body');
    }

    public function testOwnerSeesTheRawDocumentInTheTextareaInRawMode(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit', ['raw' => '1']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('v1 title', $response->body);
        self::assertStringContainsString('v1 body', $response->body);
        self::assertStringContainsString('name="base_rev" value="1"', $response->body);
        self::assertStringContainsString('<textarea class="wk-ta wk-mono" name="document"', $response->body);
    }

    public function testOwnerSeesTheDetailsPanelByDefault(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="editor-details"', $response->body);
        self::assertStringContainsString('value="v1 title"', $response->body);
        self::assertStringContainsString('<textarea class="wk-ta wk-mono" name="body"', $response->body);
        self::assertStringNotContainsString('<textarea class="wk-ta wk-mono" name="document"', $response->body);
        self::assertStringContainsString('v1 body', $response->body, 'the body textarea holds only the body');
    }

    public function testEditorShowsNoInertOrFakeControls(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');

        // No CDN on a VPN-only instance (D12): the vendored, conformance-tested marked
        self::assertStringNotContainsString('cdn.jsdelivr.net', $response->body);
        self::assertStringContainsString('/assets/marked.js', $response->body);
        self::assertStringContainsString('/assets/js/markdown-preview.js', $response->body);
        // D15: no AI rail while no provider is configured
        self::assertStringNotContainsString('wk-ai', $response->body);
        // Nothing reads this; "sign on save" would claim a signature that never happens
        self::assertStringNotContainsString('name="sign"', $response->body);
    }

    /**
     * "Minor edit" (invariant 3's one exception, Storage\FlatFile::saveMinor())
     * shows for an existing, unsigned page — nothing to squash into on a
     * page's first save (base_rev 0), and a signed revision's bytes can
     * never change under its signature (D3).
     */
    public function testMinorEditCheckboxShowsForAnExistingUnsignedPageOnly(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');
        self::assertStringContainsString('name="minor"', $response->body);

        $index = new \Reporion\Index\Sqlite((string) $this->config['paths']['index'], \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->sign('reports:mri:mioveni:a', 'owner', []);
        $signedResponse = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');
        self::assertStringNotContainsString('name="minor"', $signedResponse->body);
    }

    /**
     * The editor is full-bleed (design/mockup/WikiEditor.dc.html): no page
     * header and no tab row — Cancel in the save bar goes back to the
     * report, and a crumbs line carries the path, the revision it will
     * write, and the island's save status.
     */
    public function testTheEditorHasNoPageTabsAndCancelGoesBackToTheReport(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit');

        self::assertStringNotContainsString('wk-pagetabs', $response->body);
        self::assertStringNotContainsString('wk-pagehead', $response->body);
        self::assertStringContainsString('<body class="wk wk-read wk-editing', $response->body);
        self::assertStringContainsString('<main class="wk-panes" data-pad="edit">', $response->body);
        self::assertMatchesRegularExpression('#<div class="wk-savebar">.*<a class="btn btn-secondary" href="/reports:mri:mioveni:a">' . preg_quote(t('editor.cancel'), '#') . '</a>#s', $response->body);
        preg_match('#<div class="wk-crumbs wk-mono wk-edit-crumbs">.*?\n</div>#s', $response->body, $m);
        self::assertNotEmpty($m);
        self::assertStringContainsString('<b>reports:mri:mioveni:a</b>', $m[0]);
        self::assertStringContainsString(t('editor.rev_next', [1, 2]), $m[0]);
        self::assertStringContainsString('id="editor-status"', $m[0]);
        self::assertStringContainsString('href="/reports:mri:mioveni:a/edit?raw=1"', $m[0]);
        // Metadata, right of Raw edit: swaps the Details form in for the text; no Save of its own
        self::assertMatchesRegularExpression('#edit\?raw=1".*</a>\n<button type="button" class="btn btn-secondary btn-sm" id="editor-meta-toggle"#s', $m[0]);
        preg_match('#id="editor-details".*id="editor-body"#s', $response->body, $meta);
        self::assertNotEmpty($meta);
        self::assertStringNotContainsString('type="submit"', $meta[0], 'one Save, in the save bar, for text and metadata alike');

        $raw = $this->ownerRequest('GET', '/reports:mri:mioveni:a/edit?raw=1');
        self::assertStringNotContainsString('editor-meta-toggle', $raw->body, 'raw mode has no Metadata form');
    }

    /** A page not written yet opens the editor for a writer (decided 2026-09-27); nobody else learns anything */
    public function testAnUnknownPathOpensANewPageOnlyForAWriter(): void
    {
        self::assertSame(200, $this->ownerRequest('GET', '/reports:mri:mioveni:does-not-exist/edit')->status);
        self::assertSame(404, Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:does-not-exist/edit'))->status, 'anonymous');
        self::assertSame(404, $this->ownerRequest('GET', '/reports:mri:/edit')->status, 'not a page path');
        $this->createViewer('ana', 'reports:mri');
        self::assertSame(404, $this->authenticatedGet('ana', '/reports:mri:mioveni:does-not-exist/edit')->status, 'a viewer cannot create');
        self::assertSame(404, $this->ownerSubmit('/reports:mri:mioveni:does-not-exist/edit', ['document' => 'x', 'base_rev' => 1])->status, 'only base_rev 0 creates');
    }

    public function testAnonymousCannotSeeTheEditForm(): void
    {
        $response = Kernel::boot($this->config)->handle(new Request('GET', '/reports:mri:mioveni:a/edit'));

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotSeeTheEditForm(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a/edit');

        self::assertSame(404, $response->status);
    }

    public function testSavingANewDocumentWritesANewRevisionAndRedirects(): void
    {
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1, 'note' => 'correction'], ['raw' => '1']);

        self::assertSame(302, $response->status);
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);

        $followUp = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v2 body', $followUp->body);
    }

    /**
     * A report saved with no summary gets its conclusion's first sentence
     * (Support\ConclusionSummary); one that has a summary keeps it.
     */
    public function testSavingAReportWithoutASummaryTakesItFromTheConclusion(): void
    {
        $path = 'reports:mri:mioveni:260101-test-a';
        $this->storage()->create($path, ['title' => 'TEST', 'visibility' => 'private'], "draft\n", 'owner');
        $body = "## RM cerebral\n\n### Concluzii\n\nFără leziuni active. Control la 6 luni.\n";

        $this->ownerSubmit('/' . $path . '/edit', ['document' => "---\ntitle: TEST\nvisibility: private\n---\n\n" . $body, 'base_rev' => 1], ['raw' => '1']);
        self::assertSame('Fără leziuni active.', $this->storage()->read($path)->frontmatter['summary']);

        $this->ownerSubmit('/' . $path . '/edit', ['document' => "---\ntitle: TEST\nvisibility: private\nsummary: Scris de medic.\n---\n\n" . $body, 'base_rev' => 2], ['raw' => '1']);
        self::assertSame('Scris de medic.', $this->storage()->read($path)->frontmatter['summary']);
    }

    /**
     * Same CRLF bug as tests/Http/NewPageTest.php's — DocumentFormat::parse()
     * is shared by both controllers, so every real browser save through the
     * editor was hitting it too, not just page creation.
     * http_build_query() in the other tests here produces LF, which is why
     * the suite didn't catch this before — the raw body here keeps the CRLF.
     */
    public function testSavingWithCrlfLineEndingsFromABrowserSucceeds(): void
    {
        $document = "---\r\ntitle: v2\r\nvisibility: private\r\n---\r\n## v2";

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/edit',
            query: ['raw' => '1'],
            cookies: ['reporion' => $this->issueCookie('owner')],
            body: 'document=' . rawurlencode($document) . '&base_rev=1',
        ));

        self::assertSame(302, $response->status, 'expected a redirect, not the form re-rendered with an error');
        self::assertSame('/reports:mri:mioveni:a', $response->headers['Location']);
    }

    public function testEditorWithGrantCanSaveInTheirNamespace(): void
    {
        $this->createEditor('mihai', 'reports:mri');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(302, $response->status);
    }

    public function testEditorWithoutGrantCannotSave(): void
    {
        $this->createEditor('mihai', 'reports:ct');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('mihai', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(404, $response->status);
    }

    public function testViewerWithGrantCannotSave(): void
    {
        $this->createViewer('ana', 'reports:mri');
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = $this->authenticatedSubmit('ana', '/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(404, $response->status);
    }

    public function testAnonymousCannotSave(): void
    {
        $document = "---\ntitle: v2\nvisibility: private\n---\n\nv2 body\n";

        $response = Kernel::boot($this->config)->handle(new Request(
            'POST',
            '/reports:mri:mioveni:a/edit',
            query: ['raw' => '1'],
            body: http_build_query(['document' => $document, 'base_rev' => 1])
        ));

        self::assertSame(404, $response->status);

        // Anonymous denial must not have written anything either.
        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v1 body', $unchanged->body);
    }

    public function testMalformedDocumentReRendersWithAnErrorAndKeepsTheTypedText(): void
    {
        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => "---\ntitle: x\n\nno frontmatter end at all", 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('no frontmatter end at all', $response->body, 'the invalid text must not be lost');

        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v1 body', $unchanged->body, 'a parse failure must not write anything');
    }

    public function testInvalidYamlFrontmatterReRendersWithAnErrorAndKeepsTheTypedText(): void
    {
        $document = "---\ntitle: [unterminated\n---\n\nbody\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('unterminated', $response->body);
    }

    public function testFrontmatterThatIsNotAMappingReRendersWithAnError(): void
    {
        $document = "---\n- just\n- a\n- list\n---\n\nbody\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('YAML mapping', $response->body);
    }

    /**
     * The no-JavaScript conflict path: the submitted text must not be
     * lost, and the server's actual current document must be shown for
     * comparison — without either, a stale save is just a silent no-op
     * from the editor's point of view.
     */
    public function testStaleBaseRevReRendersWithTheTypedTextAndTheCurrentServerDocument(): void
    {
        // Someone else's edit lands first.
        $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'document' => "---\ntitle: v2\nvisibility: private\n---\n\nv2 server body\n",
            'base_rev' => 1,
        ], ['raw' => '1']);

        $stale = $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'document' => "---\ntitle: my edit\nvisibility: private\n---\n\nmy typed body\n",
            'base_rev' => 1,
        ], ['raw' => '1']);

        self::assertSame(200, $stale->status);
        self::assertStringContainsString('my typed body', $stale->body, 'the conflicting submission must not be lost');
        self::assertStringContainsString('v2 server body', $stale->body, 'the current server document must be shown for comparison');
        self::assertStringContainsString('name="base_rev" value="2"', $stale->body, 'a resubmit must target the now-current revision');

        // And the stale attempt must not have overwritten the real save.
        $unchanged = $this->ownerRequest('GET', '/reports:mri:mioveni:a');
        self::assertStringContainsString('v2 server body', $unchanged->body);
    }

    public function testCuratedSaveMergesFieldsAndKeepsWhatWasNotShown(): void
    {
        $index = new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->save('reports:mri:mioveni:a', ['title' => 'v1 title', 'visibility' => 'private', 'custom_key' => 'kept forever'], 'v1 body', 1, 'owner');

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'body' => 'v2 body',
            'fm' => ['title' => 'v2 title'],
            'fm_shown' => ['title', 'tags', 'template', 'summary'],
            'base_rev' => 2,
        ]);

        self::assertSame(302, $response->status);
        $fm = $this->storage()->read('reports:mri:mioveni:a')->frontmatter;
        self::assertSame('v2 title', $fm['title']);
        self::assertSame('kept forever', $fm['custom_key'], 'a field the form never mentioned is untouched');
        self::assertArrayNotHasKey('tags', $fm, 'shown but empty: cleared');
        self::assertSame("v2 body\n", $this->storage()->read('reports:mri:mioveni:a')->body);
    }

    public function testABodyThatLooksLikeAWholeDocumentIsRefused(): void
    {
        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', [
            'body' => "---\ntitle: sneaky\n---\n\nbody",
            'fm' => ['title' => 'v1 title'],
            'fm_shown' => ['title'],
            'base_rev' => 1,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString(t('details.err_body_looks_like_document'), $response->body);
        self::assertSame("v1 body\n", $this->storage()->read('reports:mri:mioveni:a')->body, 'nothing was written');
    }

    public function testCuratedModeShowsVisibilityAccessionAndExtraFieldsReadOnly(): void
    {
        $path = 'reports:mri:mioveni:260927-test-report';
        $this->createPage($path, 'private', 'TEST Report', 'body');
        $index = new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->save($path, ['title' => 'TEST Report', 'visibility' => 'private', 'accession' => 'MV-MR-26-0001', 'weird_field' => 'x'], 'body', 1, 'owner');

        $response = $this->ownerRequest('GET', '/' . $path . '/edit');

        self::assertMatchesRegularExpression('/<input type="radio" name="visibility" value="private" checked>/', $response->body);
        self::assertStringContainsString('class="wk-vispick"', $response->body);
        self::assertStringContainsString('MV-MR-26-0001', $response->body);
        self::assertStringContainsString('weird_field', $response->body);
        self::assertStringContainsString(t('details.raw_link'), $response->body);
    }

    /**
     * Roadmap phase 26: each exam's template `checklist` in the rail, even
     * with no AI provider; the ticks never reach a form.
     */
    public function testTheRailListsEachExamsTemplateChecklist(): void
    {
        $storage = $this->storage();
        $storage->create('templates:mri:genunchi', ['title' => 'IRM Genunchi', 'visibility' => 'private', 'checklist' => ['# Menisci', 'Menisc medial | menisc medial']], "Genunchi.\n", 'owner');
        $storage->create('templates:mri:umar', ['title' => 'IRM Umăr', 'visibility' => 'private', 'checklist' => "Coafa rotatorilor | supraspinos\n"], "Umăr.\n", 'owner');
        $path = 'reports:mri:mioveni:260927-test-unu';
        $storage->create($path, [
            'title' => 'TEST Patient Unu', 'visibility' => 'private',
            'exams' => [['title' => 'IRM genunchi', 'template' => 'templates:mri:genunchi'], ['title' => 'IRM umăr', 'template' => 'templates:mri:umar'], ['title' => 'IRM cot']],
        ], "# TEST Patient Unu\n\n## IRM genunchi\n\n## IRM umăr\n\n## IRM cot\n", 'owner');

        $body = $this->ownerRequest('GET', '/' . $path . '/edit')->body;

        self::assertStringContainsString('id="editor-checklist"', $body);
        self::assertStringContainsString('data-exam="0"', $body);
        self::assertStringContainsString('data-exam="1"', $body);
        self::assertStringNotContainsString('data-exam="2"', $body, 'an exam without a template has no list');
        self::assertStringContainsString('Menisc medial', $body);
        self::assertStringContainsString('Coafa rotatorilor', $body);
        self::assertStringContainsString('js/editor-checklist.js', $body);
        self::assertStringNotContainsString('name="check', $body, 'ticks are never posted');
        self::assertStringNotContainsString('id="ai-dialog"', $body, 'no AI without a provider');
    }

    public function testNoChecklistWithoutATemplateOrOutsideTemplates(): void
    {
        $storage = $this->storage();
        $storage->create('reports:mri:mioveni:not-a-template', ['title' => 'X', 'visibility' => 'private', 'checklist' => ['Secret item']], "x\n", 'owner');
        $path = 'reports:mri:mioveni:260927-test-doi';
        $storage->create($path, ['title' => 'TEST Patient Doi', 'visibility' => 'private', 'template' => 'reports:mri:mioveni:not-a-template'], "x\n", 'owner');

        foreach (['/reports:mri:mioveni:a/edit', '/' . $path . '/edit'] as $url) {
            $body = $this->ownerRequest('GET', $url)->body;
            self::assertStringNotContainsString('editor-checklist', $body, $url);
            self::assertStringNotContainsString('Secret item', $body, $url);
        }
    }

    private function storage(): \Reporion\Storage\FlatFile
    {
        return new \Reporion\Storage\FlatFile($this->dataRoot, new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    public function testAnExamAddedInTheEditorGetsItsAccessionOnSave(): void
    {
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV']];
        $path = 'reports:mri:mioveni:260927-test-unu';
        $single = "---\ntitle: 'TEST Patient Unu'\nvisibility: private\nmodality: [MR]\nsite: mioveni\nstudy_date: '2026-09-27'\naccession: MV-MR-26-0005\npatient:\n  name: 'TEST Patient Unu'\n---\n\n";
        $this->createPage($path, 'private', 'TEST Patient Unu', 'x');
        $this->ownerSubmit('/' . $path . '/edit', ['document' => $single . "# TEST Patient Unu\n\n## IRM cerebral\n", 'base_rev' => 1], ['raw' => '1']);

        // What the editor posts after its first Add exam: exams listed, no numbers
        $multi = str_replace("accession: MV-MR-26-0005\n", "accession: MV-MR-26-0005\nexams:\n  -\n    title: 'IRM cerebral'\n  -\n    title: 'IRM coloană cervicală'\n", $single)
            . "# TEST Patient Unu\n\n## IRM cerebral\n\n### Concluzii\n\n## IRM coloană cervicală\n\n### Concluzii\n";
        self::assertSame(302, $this->ownerSubmit('/' . $path . '/edit', ['document' => $multi, 'base_rev' => 2], ['raw' => '1'])->status);

        $fm = (new \Reporion\Storage\FlatFile($this->dataRoot, new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations')))->read($path)->frontmatter;
        self::assertSame('MV-MR-26-0005', $fm['accession'], 'the first exam\'s number stands for the page (derived, phase 27)');
        self::assertSame(['MV-MR-26-0005', 'MV-MR-26-0006'], array_column($fm['exams'], 'accession'), 'the new exam numbered after what is on disk');
    }

    public function testAnUnquotedStudyDateStillNumbersANewExam(): void
    {
        $this->config['sites'] = ['mioveni' => ['name' => 'Spital Test', 'accession_code' => 'MV']];
        $path = 'reports:mri:mioveni:260927-test-doi';
        $this->createPage($path, 'private', 'TEST Patient Doi', 'x');
        // As typed by hand: YAML reads an unquoted date as a timestamp
        $document = "---\ntitle: 'TEST Patient Doi'\nvisibility: private\nmodality: [MR]\nsite: mioveni\nstudy_date: 2026-09-27\nexams:\n  -\n    title: A\n  -\n    title: B\n---\n\n## A\n\n## B\n";
        self::assertSame(302, $this->ownerSubmit('/' . $path . '/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1'])->status);

        $fm = (new \Reporion\Storage\FlatFile($this->dataRoot, new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations')))->read($path)->frontmatter;
        self::assertSame(['MV-MR-26-0001', 'MV-MR-26-0002'], array_column($fm['exams'], 'accession'));
    }

    public function testEditLinkAppearsOnThePageViewForACallerWhoCanWrite(): void
    {
        $response = $this->ownerRequest('GET', '/reports:mri:mioveni:a');

        self::assertStringContainsString('/reports:mri:mioveni:a/edit', $response->body);
    }

    public function testEditLinkIsAbsentForAViewer(): void
    {
        $this->createViewer('ana', 'reports:mri');

        $response = $this->authenticatedGet('ana', '/reports:mri:mioveni:a');

        self::assertStringNotContainsString('/reports:mri:mioveni:a/edit', $response->body);
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

    /** @param array<string, string> $query */
    public function testTheMetadataViewChangesVisibilityWithTheSave(): void
    {
        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['body' => 'v1 body', 'base_rev' => 1, 'visibility' => 'unlisted']);

        self::assertSame(302, $response->status);
        self::assertSame('unlisted', $this->pageVisibility('reports:mri:mioveni:a'));
    }

    public function testPublicFromTheMetadataViewNeedsTheAcknowledgement(): void
    {
        $refused = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['body' => 'v2 body', 'base_rev' => 1, 'visibility' => 'public']);

        self::assertSame(200, $refused->status);
        self::assertStringContainsString('data-open="visibility"', $refused->body);
        self::assertStringContainsString('name="acknowledge"', $refused->body);
        self::assertMatchesRegularExpression('/value="public" checked/', $refused->body);
        self::assertStringContainsString('v2 body', $refused->body, 'the text being edited is kept');
        self::assertSame('private', $this->pageVisibility('reports:mri:mioveni:a'));

        $done = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['body' => 'v2 body', 'base_rev' => 1, 'visibility' => 'public', 'acknowledge' => '1']);

        self::assertSame(302, $done->status);
        self::assertSame('public', $this->pageVisibility('reports:mri:mioveni:a'));
        self::assertStringContainsString('"action":"page.publish"', (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson'));
    }

    public function testRawModeCannotMakeAPagePublic(): void
    {
        $document = "---\ntitle: v2\nvisibility: public\n---\n\nv2 body\n";

        $response = $this->ownerSubmit('/reports:mri:mioveni:a/edit', ['document' => $document, 'base_rev' => 1], ['raw' => '1']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString(htmlspecialchars(t('vis.err_raw_public'), ENT_QUOTES), $response->body);
        self::assertSame('private', $this->pageVisibility('reports:mri:mioveni:a'));
    }

    private function pageVisibility(string $path): string
    {
        $index = new \Reporion\Index\Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');

        return (new \Reporion\Storage\FlatFile($this->dataRoot, $index))->read($path)->visibility;
    }

    private function ownerRequest(string $method, string $path, array $query = []): Response
    {
        return $this->authenticatedGet('owner', $path, $method, $query);
    }

    /** @param array<string, string> $query */
    private function authenticatedGet(string $username, string $path, string $method = 'GET', array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request($method, $path, query: $query, cookies: ['reporion' => $this->issueCookie($username)]));
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $query
     */
    private function ownerSubmit(string $path, array $fields, array $query = []): Response
    {
        return $this->authenticatedSubmit('owner', $path, $fields, $query);
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $query
     */
    private function authenticatedSubmit(string $username, string $path, array $fields, array $query = []): Response
    {
        return Kernel::boot($this->config)->handle(new Request(
            'POST',
            $path,
            query: $query,
            cookies: ['reporion' => $this->issueCookie($username)],
            body: http_build_query($fields),
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
