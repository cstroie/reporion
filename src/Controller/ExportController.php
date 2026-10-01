<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\OdtExport;
use Reporion\Service\PdfExport;
use Reporion\Service\PrintView;
use Reporion\Storage\PageRecord;
use Reporion\Storage\StorageInterface;
use Reporion\Support\DocumentFormat;
use Reporion\Support\ReportPath;
use Reporion\Support\Zip;

/**
 * GET /{path}/print, GET /export/{path}.pdf, GET /export/{path}.odt and
 * GET /export/{path}.md (docs/architecture-api.md) — the same template
 * (templates/print/report.php) as a printable page, a dompdf PDF (D34)
 * and an OpenDocument text; .md is the one exception, the raw document
 * text rather than that rendered template (see md()'s own docblock).
 *
 * Access is the page's own (Index::findByPath(), invariant 6); an anonymous
 * reader additionally gets the patient block left out (export.
 * pseudonymise_public) for pdf/odt, and a PDF only of a public page when
 * export.allow_public_export is on. A draft prints with a draft band but
 * is not exported (PDF, ODT or MD) unless export.allow_draft_export. Every
 * export is audited; its file name is the accession or pid, never the path
 * (invariant 8).
 *
 * POST /export/bundle.zip (roadmap phase 18) — several pages at once, the
 * namespace index's bulk Export: a zip with each page's own PDF, exactly as
 * GET /export/{path}.pdf makes it (its own rev and verification link, D3),
 * under the same file name. Signed-in callers only.
 */
final class ExportController
{
    /** dompdf renders one PDF at a time, inside the request */
    public const BUNDLE_MAX = 50;

    /**
     * @param array{allow_draft_export?: bool, allow_public_export?: bool, pseudonymise_public?: bool} $options conf['export']
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PrintView $printView,
        private readonly PdfExport $pdf,
        private readonly OdtExport $odt,
        private readonly AuditLog $audit,
        private readonly array $options,
    ) {
    }

    /**
     * GET /{path}/print — for a signed-in reader, the preview page: the app
     * shell, the page header, and the document on a sheet
     * (templates/print-preview.php, which frames /export/{path}.html). An
     * anonymous reader of a public page gets the bare document as before,
     * with its own Back · Print line: the app shell is not theirs.
     */
    public function print(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read($path);
        if ($principal === null) {
            return Response::html($this->document($record, $principal, [
                'printAction' => View::render(\dirname(__DIR__, 2) . '/templates/print/action.php', [
                    'basePath' => $request->basePath,
                    'path' => $path,
                ]),
            ]));
        }

        $isDraft = $record->status === 'draft' && ReportPath::isReport($record->path);

        return Response::html(View::page(
            \dirname(__DIR__, 2) . '/templates/print-preview.php',
            [
                'basePath' => $request->basePath,
                'path' => $path,
                'docUrl' => $request->basePath . '/export/' . $path . '.html',
                'isDraft' => $isDraft,
                // the same rule export() applies to PDF and ODT
                'canExport' => !$isDraft || ($this->options['allow_draft_export'] ?? false),
            ] + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf($path))
              + ChromeVars::pageHeaderFromRow($indexed, $principal, 'print'),
            t('page.print_preview'),
        ));
    }

    /**
     * GET /export/{path}.html — the printed document itself, exactly as dompdf
     * gets it (print.css inlined): what the print preview frames, and what a
     * PDF or ODT is made from. Not an export in the audit sense — the preview
     * never was; a draft shows as a draft, as on paper.
     */
    public function html(Request $request, string $path, ?User $principal): Response
    {
        if ($this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }

        return new Response(200, $this->document($this->storage->read($path), $principal), [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function pdf(Request $request, string $path, ?User $principal): Response
    {
        return $this->export($request, $path, $principal, 'pdf');
    }

    /** The same document as an editable OpenDocument text (Service\OdtExport) */
    public function odt(Request $request, string $path, ?User $principal): Response
    {
        return $this->export($request, $path, $principal, 'odt');
    }

    /**
     * GET /export/{path}.md — the raw document (frontmatter + body,
     * `Support\DocumentFormat::encode()`), unrendered, exactly as the raw
     * editor would show it. Unlike pdf()/odt(), which go through
     * PrintView's pseudonymised HTML for an anonymous caller
     * (export.pseudonymise_public), there is no equivalent redaction here
     * for raw frontmatter — so an anonymous caller only gets a non-report
     * page this way (docs, protocols: no patient block to protect);
     * a report always needs an authenticated reader (invariant 8).
     */
    public function md(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        $isReport = ReportPath::isReport($path);
        if ($principal === null && ($isReport || $indexed['visibility'] !== 'public' || !($this->options['allow_public_export'] ?? true))) {
            throw new PageNotFoundException();
        }

        $record = $this->storage->read($path);
        if ($record->status === 'draft' && $isReport && !($this->options['allow_draft_export'] ?? false)) {
            return $this->draftRefused($request, $principal, $indexed);
        }

        $document = DocumentFormat::encode($record->frontmatter, $record->body);
        $this->audit->record('export', $principal?->username ?? 'anonymous', $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'md']);

        return new Response(200, $document, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . PrintView::fileName($record, 'md') . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * POST /export/bundle.zip { paths[] } — what the caller cannot read is
     * left out silently (invariant 6: as if it did not exist); a draft report
     * is left out unless export.allow_draft_export, and the zip then says how
     * many were — a count, never which (no path or name in an export,
     * invariant 8). Each page is audited as its own export.
     */
    public function bundle(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $paths = array_values(array_unique(array_filter((array) ($fields['paths'] ?? []), 'is_string')));
        $back = $request->basePath . '/' . (\is_string($fields['ns'] ?? null) ? $fields['ns'] : '') . ':';
        if ($paths === [] || \count($paths) > self::BUNDLE_MAX) {
            return $this->notice($request, $principal, t('export.bundle_count', [self::BUNDLE_MAX]), $back, 422);
        }

        $files = [];
        $drafts = 0;
        foreach ($paths as $path) {
            if ($this->index->findByPath($path, $principal) === null) {
                continue;
            }
            $record = $this->storage->read($path);
            if ($record->status === 'draft' && ReportPath::isReport($record->path) && !($this->options['allow_draft_export'] ?? false)) {
                ++$drafts;
                continue;
            }
            $name = PrintView::fileName($record, 'pdf');
            for ($i = 2; isset($files[$name]); ++$i) {
                $name = preg_replace('/(-\d+)?\.pdf$/', '-' . $i . '.pdf', $name);
            }
            $files[$name] = $this->pdf->render($this->document($record, $principal));
            // dompdf's frame tree is reference cycles: without this they pile
            // up across renders until PHP's own GC threshold, past FPM's memory_limit
            gc_collect_cycles();
            $this->audit->record('export', $principal->username, $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'pdf', 'bundle' => \count($paths)]);
        }
        if ($files === []) {
            return $this->notice($request, $principal, t('export.bundle_nothing', [$drafts]), $back, 409);
        }
        if ($drafts > 0) {
            $files['NOT-INCLUDED.txt'] = t('export.bundle_skipped', [$drafts]) . "\n";
        }

        $now = new DateTimeImmutable('now');

        return new Response(200, Zip::build($files, $now), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="reporion-' . $now->format('Ymd-His') . '.zip"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function notice(Request $request, User $principal, string $message, string $back, int $status): Response
    {
        $content = '<div class="wk-doc"><div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div>'
            . htmlspecialchars($message, ENT_QUOTES)
            . ' <a href="' . htmlspecialchars($back, ENT_QUOTES) . '">' . htmlspecialchars(t('export.bundle_back'), ENT_QUOTES) . '</a></div></div></div>';

        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/layout.php',
            ['basePath' => $request->basePath, 'content' => $content, 'pageTitle' => t('page.export')]
                + ChromeVars::shell($request, $principal, $this->index, '')
        ), $status);
    }

    private function export(Request $request, string $path, ?User $principal, string $format): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        if ($principal === null && ($indexed['visibility'] !== 'public' || !($this->options['allow_public_export'] ?? true))) {
            throw new PageNotFoundException();
        }

        $record = $this->storage->read($path);
        // Only a report waits for its signature; other pages are never signed
        if ($record->status === 'draft' && ReportPath::isReport($record->path) && !($this->options['allow_draft_export'] ?? false)) {
            return $this->draftRefused($request, $principal, $indexed);
        }

        $html = $this->document($record, $principal);
        $bytes = $format === 'odt' ? $this->odt->render($html) : $this->pdf->render($html);
        $this->audit->record('export', $principal?->username ?? 'anonymous', $request, $record->pid, $record->path, $record->rev, extra: ['format' => $format]);

        return new Response(200, $bytes, [
            'Content-Type' => $format === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/pdf',
            // A PDF opens in the browser; an ODT is for editing, so it downloads
            'Content-Disposition' => ($format === 'odt' ? 'attachment' : 'inline') . '; filename="' . PrintView::fileName($record, $format) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The printed document: templates/print/report.php for a report,
     * templates/print/page.php — title, text, revision — for any other page
     * (Support\ReportPath).
     *
     * @param array<string, mixed> $extra
     */
    private function document(PageRecord $record, ?User $principal, array $extra = []): string
    {
        $isReport = ReportPath::isReport($record->path);
        $vars = ($isReport ? $this->printView->vars($record, $this->pseudonymise($principal)) : $this->printView->pageVars($record)) + $extra;

        return View::render(\dirname(__DIR__, 2) . '/templates/print/' . ($isReport ? 'report' : 'page') . '.php', $vars);
    }

    private function pseudonymise(?User $principal): bool
    {
        return $principal === null && ($this->options['pseudonymise_public'] ?? true);
    }

    /**
     * @param array<string, mixed> $indexed
     */
    private function draftRefused(Request $request, ?User $principal, array $indexed): Response
    {
        $content = '<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div>'
            . htmlspecialchars(t('print.draft_refused'), ENT_QUOTES)
            . ' <a href="' . htmlspecialchars($request->basePath . '/' . $indexed['path'] . '/print', ENT_QUOTES) . '">'
            . htmlspecialchars(t('page.print_preview'), ENT_QUOTES) . '</a></div></div>';
        $vars = ['basePath' => $request->basePath, 'content' => $content]
            + ChromeVars::shell($request, $principal, $this->index, ChromeVars::namespaceOf((string) $indexed['path']))
            + ChromeVars::pageHeaderFromRow($indexed, $principal, 'export');

        return Response::html(View::render(
            \dirname(__DIR__, 2) . '/templates/layout.php',
            $vars + ['pageTitle' => t('page.export')]
        ), 409);
    }
}
