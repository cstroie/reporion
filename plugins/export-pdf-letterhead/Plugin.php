<?php
declare(strict_types=1);

/**
 * Reference plugin — the shape every other plugin copies.
 *
 * Note what it receives: SERVICES from the container, never the filesystem and never the PDO
 * handle (D9). It renders through Render/Template, the same HTML the page view uses.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Reporion\Plugin\ExportPdfLetterhead;

use Dompdf\Dompdf;
use Reporion\Domain\Page;
use Reporion\Plugin\Container;
use Reporion\Plugin\Hooks;
use Reporion\Plugin\PluginInterface;
use Reporion\Service\Template;

final class Plugin implements PluginInterface
{
    private Template $template;

    /** @var array<string,mixed> */
    private array $settings;

    public function register(Hooks $hooks, Container $c): void
    {
        $this->template = $c->get(Template::class);
        $this->settings = $c->settings('export-pdf-letterhead');

        $hooks->on('export.pdf', [$this, 'export'], priority: 10);
        $hooks->on('page.validate', [$this, 'warnIfNoSite'], priority: 50);
    }

    /**
     * Owns the pdf format end to end. Returns the bytes; the core handles streaming,
     * filename, audit and the revision/verification line contract.
     */
    public function export(Page $page, array $opts): string
    {
        $html = $this->template->render('print/report.php', [
            'page' => $page,
            'site' => $opts['site'],
            'sig'  => $opts['signature'],
            'opts' => $opts,
        ]);

        $dompdf = new Dompdf([
            'isRemoteEnabled'     => false,   // never fetch over the network
            'defaultFont'         => 'DejaVu Sans',
            'dpi'                 => (int) $this->settings['dpi'],
            'chroot'              => $opts['media_root'],
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($this->settings['paper'], 'portrait');
        $dompdf->render();

        if ($page->status() === 'draft' && $this->settings['watermark_drafts']) {
            // dompdf has no CSS rotate(); the diagonal watermark is drawn on the canvas
            $canvas = $dompdf->getCanvas();
            $canvas->page_text(
                x: 140, y: 420, text: 'CIORNA',
                font: $canvas->get_cpdf() ? 'DejaVu Sans' : null,
                size: 56, color: [0.85, 0.85, 0.85], angle: 55.0
            );
        }

        return (string) $dompdf->output();
    }

    /** A soft warning, never a hard error: it must not block saving (D7). */
    public function warnIfNoSite(Page $page, array &$result): void
    {
        if (($page->meta()['site'] ?? null) === null) {
            $result['warnings'][] = 'No site set — the PDF will export without letterhead.';
        }
    }
}
