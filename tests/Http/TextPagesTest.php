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
use Reporion\Storage\FlatFile;

/**
 * Text pages (D40, docs/FORMATS.md §3j): `format: text` is shown exactly
 * as typed — escaped and preformatted — in the page view, print, PDF and
 * ODT; nothing in it is read as markup, so it links nowhere. The editor's
 * Metadata view picks the format, and Markdown leaves the key out.
 */
final class TextPagesTest extends HttpTestCase
{
    private const PAGE = 'docs:plain-note';
    private const BODY = "# not a heading\n\nCol A    Col B\n  indented <script>alert(1)</script> **not bold**\n[a link](docs:other)\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        $this->createPage('docs:other', 'private', 'Other', "Other page.\n");
        $this->storage()->create(self::PAGE, ['title' => 'Plain note', 'visibility' => 'private', 'format' => 'text'], self::BODY, 'owner');
    }

    public function testThePageViewShowsTheTextAsTypedAndEscaped(): void
    {
        $html = $this->staff('GET', '/' . self::PAGE)->body;

        self::assertStringContainsString('<pre class="wk-plaintext"># not a heading' . "\n\nCol A    Col B\n  indented &lt;script&gt;alert(1)&lt;/script&gt; **not bold**\n[a link](docs:other)</pre>", $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<strong>not bold', $html);
        self::assertStringNotContainsString('>a link</a>', $html, 'no link is read from text');
        self::assertStringNotContainsString('id="not-a-heading"', $html, 'no heading, so no table of contents entry');
    }

    public function testTheMetadataPanelNamesTheBodyFormat(): void
    {
        self::assertStringContainsString('<span class="wk-mono wk-dim">text</span></summary>', $this->staff('GET', '/' . self::PAGE)->body);
        self::assertStringContainsString('<span class="wk-mono wk-dim">markdown</span></summary>', $this->staff('GET', '/docs:other')->body);
    }

    public function testItLinksNowhereInTheIndex(): void
    {
        $index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations');
        $other = $index->findByPath('docs:other', $this->owner());
        self::assertNotNull($other);

        self::assertSame([], $index->backlinks((string) $other['pid'], $this->owner()));
    }

    public function testPrintPdfAndOdtCarryTheTextAsTyped(): void
    {
        $print = $this->staff('GET', '/export/' . self::PAGE . '.html')->body;
        self::assertStringContainsString('<pre class="wk-plaintext"># not a heading', $print);
        self::assertStringContainsString('pre.wk-plaintext {', $print, 'its print rule is in the inlined stylesheet');

        $pdf = $this->staff('GET', '/export/' . self::PAGE . '.pdf');
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF', $pdf->body);

        $odt = $this->staff('GET', '/export/' . self::PAGE . '.odt');
        self::assertSame(200, $odt->status);
        // content.xml through the PclZip PHPWord bundles, as ExportTest reads it (PHP may have no zip extension)
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = $this->dataRoot . '/out.odt';
        file_put_contents($file, $odt->body);
        $entries = (new \PclZip($file))->extract(PCLZIP_OPT_BY_NAME, 'content.xml', PCLZIP_OPT_EXTRACT_AS_STRING);
        $content = \is_array($entries) ? (string) ($entries[0]['content'] ?? '') : '';
        self::assertStringContainsString("Col\u{00A0}A\u{00A0}\u{00A0}\u{00A0}\u{00A0}Col\u{00A0}B", $content, 'one paragraph per line, its spaces kept');
        self::assertStringContainsString('&lt;script&gt;', $content);
    }

    public function testTheEditorPicksTheFormatAndMarkdownLeavesTheKeyOut(): void
    {
        $edit = $this->staff('GET', '/' . self::PAGE . '/edit')->body;
        self::assertMatchesRegularExpression('~<select class="input" name="fm\[format\]"><option value="">Markdown</option><option value="text" selected>~', $edit);

        $save = fn (string $format, int $base): Response => $this->staff('POST', '/' . self::PAGE . '/edit', [
            'body' => self::BODY,
            'fm' => ['title' => 'Plain note', 'format' => $format],
            'fm_shown' => ['title', 'format'],
            'base_rev' => $base,
        ]);

        self::assertSame(302, $save('', 1)->status);
        self::assertArrayNotHasKey('format', $this->storage()->read(self::PAGE)->frontmatter, 'Markdown: the key left out');
        self::assertStringContainsString('<h1 id="not-a-heading">', $this->staff('GET', '/' . self::PAGE)->body);

        self::assertSame(302, $save('weird', 2)->status);
        self::assertArrayNotHasKey('format', $this->storage()->read(self::PAGE)->frontmatter, 'only text, or nothing');

        self::assertSame(302, $save('text', 3)->status);
        self::assertSame('text', $this->storage()->read(self::PAGE)->frontmatter['format']);
    }

    public function testATextReportIsNotHeldBackForExamHeadingsItCannotHave(): void
    {
        $path = 'reports:mri:mioveni:260101-test-subject';
        $this->storage()->create($path, [
            'title' => 'TEST SUBJECT', 'visibility' => 'private', 'format' => 'text',
            'exams' => [['title' => 'IRM genunchi', 'modality' => ['MR']]],
        ], "IRM genunchi\n\nConcluzii: fără modificări.\n", 'owner');

        self::assertStringNotContainsString(t('page.exam_conclusion', [1]), $this->staff('GET', '/' . $path)->body);
        self::assertSame([], \Reporion\Support\Exams::problems($this->storage()->read($path)->frontmatter, $this->storage()->read($path)->body));
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    private function owner(): \Reporion\Auth\User
    {
        $user = (new FlatFileUserStore($this->dataRoot))->find('owner');
        self::assertNotNull($user);

        return $user;
    }

    /** @param array<string, mixed> $fields */
    private function staff(string $method, string $path, array $fields = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: $fields !== [] ? http_build_query($fields) : ''));
    }
}
