<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Http;

use Reporion\Auth\FlatFileUserStore;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\Session;
use Reporion\Kernel;

/**
 * GET /{path}/print and GET /export/{path}.pdf — one template, two outputs
 * (D34), the page's own access rules, drafts refused as PDF, anonymous
 * readers only of public pages and without the patient block, and the
 * path never in a file name (invariant 8).
 */
final class ExportTest extends HttpTestCase
{
    private const PRIV = 'reports:mri:mioveni:260923-export-subject';
    private const PUB = 'docs:public-case';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOwner();
        (new FlatFileUserStore($this->dataRoot))->save(
            (new FlatFileUserStore($this->dataRoot))->find('owner')->with(displayName: 'Dr. Test Signer', title: 'Medic primar')
        );
    }

    public function testASignedReportExportsAsPdfNamedByAccessionAndIsAudited(): void
    {
        $pid = $this->signedReport(self::PRIV, 'private');

        $response = $this->owner('GET', '/export/' . self::PRIV . '.pdf');

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertStringStartsWith('%PDF', $response->body);
        self::assertSame('inline; filename="MV-MR-26-0001-rev1.pdf"', $response->headers['Content-Disposition']);
        self::assertStringNotContainsString('export-subject', $response->headers['Content-Disposition']);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"action":"export"', $audit);
        self::assertStringContainsString('"format":"pdf"', $audit);
        self::assertStringContainsString('"pid":"' . $pid . '"', $audit);
    }

    public function testAMultiExamReportPrintsEveryExamWithItsNumber(): void
    {
        $meta = $this->meta('private');
        unset($meta['accession']);
        $meta['exam_title'] = 'IRM genunchi drept + IRM genunchi stâng';
        $meta['exams'] = [
            ['title' => 'IRM genunchi drept', 'region' => ['msk'], 'accession' => 'MV-MR-26-0021'],
            ['title' => 'IRM genunchi stâng', 'region' => ['msk'], 'accession' => 'MV-MR-26-0022'],
        ];
        $body = "# TEST PATIENT\n\n## IRM genunchi drept\n\nA.\n\n### Concluzii\n\nB.\n\n## IRM genunchi stâng\n\nC.\n\n### Concluzii\n\nD.\n";
        $this->owner('POST', '/api/v1/pages', ['path' => self::PRIV, 'meta' => $meta, 'body' => $body]);

        $print = $this->owner('GET', '/export/' . self::PRIV . '.html')->body;
        self::assertStringContainsString('MV-MR-26-0021<br>MV-MR-26-0022<br>', $print, 'every exam\'s number in the header');
        self::assertStringContainsString('<h2 id="exam-1">IRM genunchi drept</h2>', $print);
        self::assertStringContainsString('<h2 id="exam-2">IRM genunchi stâng</h2>', $print);
        self::assertSame(1, substr_count($print, 'TEST PATIENT'), 'the name once, in the patient block');

        $view = $this->owner('GET', '/' . self::PRIV)->body;
        self::assertStringContainsString('href="#exam-2">2. IRM genunchi stâng</a>', $view, 'the metadata panel lists the exams');
        self::assertStringContainsString('/' . self::PRIV . '/edit?exam=2"', $view);

        self::assertSame(200, $this->owner('POST', '/api/v1/pages/' . self::PRIV . '/sign', [])->status, 'whole exams sign');
        self::assertSame('inline; filename="MV-MR-26-0021-rev1.pdf"', $this->owner('GET', '/export/' . self::PRIV . '.pdf')->headers['Content-Disposition']);
    }

    public function testASignedReportExportsAsAnEditableOdtFromTheSameHtml(): void
    {
        $pid = $this->signedReport(self::PRIV, 'private');

        $response = $this->owner('GET', '/export/' . self::PRIV . '.odt');

        self::assertSame(200, $response->status);
        self::assertSame('application/vnd.oasis.opendocument.text', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="MV-MR-26-0001-rev1.odt"', $response->headers['Content-Disposition']);
        self::assertStringStartsWith('PK', $response->body);

        $content = self::odtContent($response->body);
        self::assertStringContainsString('RM cerebral nativ', $content);
        self::assertStringContainsString('Cefalee cronica.', $content, 'the indication row, as printed');
        self::assertStringContainsString('Dr. Test Signer', $content);
        self::assertStringContainsString('/r/' . $pid . '/1', $content);
        self::assertStringNotContainsString(self::PRIV, $content, 'never the path (invariant 8)');

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"format":"odt"', $audit);
    }

    public function testAnonymousGetsNoOdtOfAPrivateReport(): void
    {
        $this->signedReport(self::PRIV, 'private');

        self::assertSame(404, $this->anonymous('/export/' . self::PRIV . '.odt')->status);
    }

    /**
     * .md is the raw document, not the rendered print template — the
     * exact "---\nfrontmatter\n---\n\nbody" the raw editor would show.
     */
    public function testASignedReportExportsAsRawMarkdownNamedByAccession(): void
    {
        $this->signedReport(self::PRIV, 'private');

        $response = $this->owner('GET', '/export/' . self::PRIV . '.md');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/markdown', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="MV-MR-26-0001-rev1.md"', $response->headers['Content-Disposition']);
        self::assertStringNotContainsString('export-subject', $response->headers['Content-Disposition'], 'never the path (invariant 8)');
        self::assertStringStartsWith("---\n", $response->body);
        self::assertStringContainsString('TEST PATIENT', $response->body, 'the caller has read access, so the raw patient block is not stripped');
        self::assertStringContainsString('## Descriere', $response->body);

        $audit = (string) file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
        self::assertStringContainsString('"format":"md"', $audit);
    }

    public function testAnonymousGetsNoMarkdownOfAPrivateOrPublicReport(): void
    {
        $this->signedReport(self::PRIV, 'private');
        $publicReport = 'reports:mri:mioveni:260923-export-public';
        $this->signedReport($publicReport, 'public');

        self::assertSame(404, $this->anonymous('/export/' . self::PRIV . '.md')->status);
        self::assertSame(404, $this->anonymous('/export/' . $publicReport . '.md')->status, 'no redaction exists for raw frontmatter, unlike pdf/odt');
    }

    public function testAnonymousGetsRawMarkdownOfANonReportPublicPage(): void
    {
        $this->owner('POST', '/api/v1/pages', ['path' => self::PUB, 'meta' => ['title' => 'A public doc', 'visibility' => 'public'], 'body' => 'plain text']);

        $response = $this->anonymous('/export/' . self::PUB . '.md');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('plain text', $response->body);
    }

    public function testADraftIsNotExportedAsMarkdown(): void
    {
        $this->owner('POST', '/api/v1/pages', ['path' => self::PRIV, 'meta' => $this->meta('private'), 'body' => 'draft body']);

        self::assertSame(409, $this->owner('GET', '/export/' . self::PRIV . '.md')->status);
    }

    public function testThePrintPreviewCarriesTheSignerAndTheVerificationLink(): void
    {
        $pid = $this->signedReport(self::PRIV, 'private');

        $response = $this->owner('GET', '/export/' . self::PRIV . '.html');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Dr. Test Signer', $response->body);
        self::assertStringContainsString('Parafa P-9', $response->body);
        self::assertStringContainsString('/r/' . $pid . '/1', $response->body);
        self::assertStringContainsString('TEST PATIENT', $response->body);
        self::assertStringNotContainsString('<link', $response->body, 'the stylesheet is inlined, never fetched');
    }

    /** The reason for examination is printed from frontmatter, and shown in the page's metadata panel */
    public function testTheIndicationIsPrintedAndShownInTheMetadataPanel(): void
    {
        $this->signedReport(self::PRIV, 'private');

        $print = $this->owner('GET', '/export/' . self::PRIV . '.html');
        self::assertMatchesRegularExpression('/Indicație<\/td>\s*<td class="pt-v" colspan="3">Cefalee cronica\.<\/td>/u', $print->body);

        $view = $this->owner('GET', '/' . self::PRIV);
        self::assertMatchesRegularExpression('/<dt>Indication<\/dt><dd>Cefalee cronica\.<\/dd>/', $view->body);
    }

    public function testADraftPrintsWithABandButIsNotExportedAsPdf(): void
    {
        $this->owner('POST', '/api/v1/pages', ['path' => self::PRIV, 'meta' => $this->meta('private'), 'body' => 'draft body']);

        $print = $this->owner('GET', '/export/' . self::PRIV . '.html');
        $pdf = $this->owner('GET', '/export/' . self::PRIV . '.pdf');

        self::assertStringContainsString(t('print.draft_band'), $print->body);
        self::assertSame(409, $pdf->status);
        self::assertStringNotContainsString('%PDF', $pdf->body);
    }

    public function testThePrintPreviewFramesTheDocumentUnderThePageHeader(): void
    {
        $this->signedReport(self::PRIV, 'private');

        $preview = $this->owner('GET', '/' . self::PRIV . '/print');

        self::assertSame(200, $preview->status);
        self::assertStringContainsString('wk-pagehead', $preview->body, 'in the app, under the page header');
        self::assertStringNotContainsString('wk-pagetabs', $preview->body, 'crumbs, title and badges only: no tab bar');
        self::assertStringContainsString('<iframe id="print-sheet" src="/export/' . self::PRIV . '.html"', $preview->body);
        self::assertStringContainsString('href="/export/' . self::PRIV . '.pdf"', $preview->body);
        self::assertStringNotContainsString('Parafa P-9', $preview->body, 'the document is in the frame, not the page');
    }

    public function testADraftsPreviewOffersPrintButNotPdfOrOdt(): void
    {
        $this->owner('POST', '/api/v1/pages', ['path' => self::PRIV, 'meta' => $this->meta('private'), 'body' => 'draft body']);

        $preview = $this->owner('GET', '/' . self::PRIV . '/print')->body;

        self::assertStringContainsString('data-print', $preview);
        self::assertStringNotContainsString('<span class="wk-btn-label">PDF</span>', $preview, 'no PDF button in the preview row');
        self::assertStringContainsString(t('print.draft_print_only'), $preview);
    }

    public function testAnonymousCannotReadAPrivateDocument(): void
    {
        $this->signedReport(self::PRIV, 'private');

        self::assertSame(404, $this->anonymous('/export/' . self::PRIV . '.html')->status);
    }

    public function testAnonymousGetsNothingOfAPrivateReport(): void
    {
        $this->signedReport(self::PRIV, 'private');

        self::assertSame(404, $this->anonymous('/export/' . self::PRIV . '.pdf')->status);
        self::assertSame(404, $this->anonymous('/' . self::PRIV . '/print')->status);
    }

    public function testAnonymousExportOfAPublicReportLeavesThePatientOut(): void
    {
        $this->signedReport(self::PUB, 'public');

        $print = $this->anonymous('/' . self::PUB . '/print');
        $pdf = $this->anonymous('/export/' . self::PUB . '.pdf');

        self::assertSame(200, $print->status);
        self::assertStringNotContainsString('TEST PATIENT', $print->body);
        self::assertSame(200, $pdf->status);
        self::assertStringStartsWith('%PDF', $pdf->body);
    }

    private function signedReport(string $path, string $visibility): string
    {
        $created = json_decode($this->owner('POST', '/api/v1/pages', [
            'path' => $path, 'meta' => $this->meta($visibility), 'body' => "## Descriere\n\nText.\n",
        ])->body, true);
        self::assertSame(200, $this->owner('POST', '/api/v1/pages/' . $path . '/sign', ['parafa' => 'P-9'])->status);

        return (string) $created['pid'];
    }

    /** @return array<string, mixed> */
    private function meta(string $visibility): array
    {
        return [
            'title' => 'RM cerebral nativ', 'visibility' => $visibility, 'modality' => ['MR'],
            'region' => ['neuro'], 'site' => 'mioveni', 'study_date' => '2026-09-23',
            'summary' => 'Fara leziuni active.', 'indication' => 'Cefalee cronica.',
            'accession' => 'MV-MR-26-0001', 'patient' => ['name' => 'TEST PATIENT', 'born' => 1970, 'sex' => 'F'],
        ];
    }

    /** @param array<string, mixed> $body */
    private function owner(string $method, string $path, array $body = []): Response
    {
        $cookie = (new Session('test-secret', 'reporion', 3600, new FlatFileUserStore($this->dataRoot)))->issue('owner');

        return Kernel::boot($this->config)->handle(new Request($method, $path, cookies: ['reporion' => $cookie], body: $body === [] ? '' : (string) json_encode($body)));
    }

    /** content.xml of an ODT, read with the PclZip PHPWord bundles (PHP may have no zip extension) */
    private static function odtContent(string $odt): string
    {
        require_once \dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php';
        $file = (string) tempnam(sys_get_temp_dir(), 'odt-test-');
        file_put_contents($file, $odt);
        try {
            $entries = (new \PclZip($file))->extract(PCLZIP_OPT_BY_NAME, 'content.xml', PCLZIP_OPT_EXTRACT_AS_STRING);
        } finally {
            unlink($file);
        }

        return \is_array($entries) ? (string) ($entries[0]['content'] ?? '') : '';
    }

    private function anonymous(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }
}
