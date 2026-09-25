<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\PdfExport;
use Reporion\Service\PrintView;
use Reporion\Storage\StorageInterface;

/**
 * GET /{path}/print and GET /export/{path}.pdf (docs/architecture-api.md) —
 * the same template (templates/print/report.php) as a printable page and as
 * a dompdf PDF (D34).
 *
 * Access is the page's own (Index::findByPath(), invariant 6); an anonymous
 * reader additionally gets the patient block left out (export.
 * pseudonymise_public), and a PDF only of a public page when
 * export.allow_public_export is on. A draft prints with a draft band but
 * is not exported as PDF unless export.allow_draft_export. Every PDF is
 * audited; its file name is the accession or pid, never the path
 * (invariant 8).
 */
final class ExportController
{
    /**
     * @param array{allow_draft_export?: bool, allow_public_export?: bool, pseudonymise_public?: bool} $options conf['export']
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly PrintView $printView,
        private readonly PdfExport $pdf,
        private readonly AuditLog $audit,
        private readonly array $options,
    ) {
    }

    public function print(Request $request, string $path, ?User $principal): Response
    {
        if ($this->index->findByPath($path, $principal) === null) {
            throw new PageNotFoundException();
        }
        $record = $this->storage->read($path);
        $vars = $this->printView->vars($record, $this->pseudonymise($principal));
        $vars['printAction'] = View::render(\dirname(__DIR__, 2) . '/templates/print/action.php', [
            'basePath' => $request->basePath,
            'path' => $path,
        ]);

        return Response::html(View::render(\dirname(__DIR__, 2) . '/templates/print/report.php', $vars));
    }

    public function pdf(Request $request, string $path, ?User $principal): Response
    {
        $indexed = $this->index->findByPath($path, $principal);
        if ($indexed === null) {
            throw new PageNotFoundException();
        }
        if ($principal === null && ($indexed['visibility'] !== 'public' || !($this->options['allow_public_export'] ?? true))) {
            throw new PageNotFoundException();
        }

        $record = $this->storage->read($path);
        if ($record->status === 'draft' && !($this->options['allow_draft_export'] ?? false)) {
            return $this->draftRefused($request, $principal, $indexed);
        }

        $bytes = $this->pdf->render(View::render(
            \dirname(__DIR__, 2) . '/templates/print/report.php',
            $this->printView->vars($record, $this->pseudonymise($principal))
        ));
        $this->audit->record('export', $principal?->username ?? 'anonymous', $request, $record->pid, $record->path, $record->rev, extra: ['format' => 'pdf']);

        return new Response(200, $bytes, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . PrintView::fileName($record, 'pdf') . '"',
            'Cache-Control' => 'private, no-store',
        ]);
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
