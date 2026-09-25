<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF with dompdf (D34). The HTML is templates/print/report.php —
 * the same markup the print preview shows. dompdf never fetches anything
 * over the network or outside its chroot; the stylesheet is inlined and
 * DejaVu Sans (bundled with dompdf) covers Romanian diacritics.
 */
final class PdfExport
{
    public function __construct(private readonly string $chroot)
    {
    }

    public function render(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot($this->chroot);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
