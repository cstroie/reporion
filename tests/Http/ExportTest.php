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

    public function testThePrintPreviewCarriesTheSignerAndTheVerificationLink(): void
    {
        $pid = $this->signedReport(self::PRIV, 'private');

        $response = $this->owner('GET', '/' . self::PRIV . '/print');

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

        $print = $this->owner('GET', '/' . self::PRIV . '/print');
        self::assertMatchesRegularExpression('/Indicație<\/td>\s*<td class="pt-v" colspan="3">Cefalee cronica\.<\/td>/u', $print->body);

        $view = $this->owner('GET', '/' . self::PRIV);
        self::assertMatchesRegularExpression('/<span>Indication<\/span><b>Cefalee cronica\.<\/b>/', $view->body);
    }

    public function testADraftPrintsWithABandButIsNotExportedAsPdf(): void
    {
        $this->owner('POST', '/api/v1/pages', ['path' => self::PRIV, 'meta' => $this->meta('private'), 'body' => 'draft body']);

        $print = $this->owner('GET', '/' . self::PRIV . '/print');
        $pdf = $this->owner('GET', '/export/' . self::PRIV . '.pdf');

        self::assertStringContainsString(t('print.draft_band'), $print->body);
        self::assertSame(409, $pdf->status);
        self::assertStringNotContainsString('%PDF', $pdf->body);
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

    private function anonymous(string $path): Response
    {
        return Kernel::boot($this->config)->handle(new Request('GET', $path));
    }
}
