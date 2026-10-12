<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

return [
    'groff.action.ms' => 'Export groff (ms)',
    'groff.action.pdf' => 'Export PDF (groff)',
    'groff.exported' => 'exported from Reporion, rev %d',
    'groff.rev' => 'rev %d',
    'groff.err.no-tool' => 'groff cannot be run — check its full path in Admin → Plugins and that the groff package (the ms macros and gropdf) is installed.',
    'groff.err.failed' => 'groff could not typeset the page. Is the font family set in Admin → Plugins installed, and the groff package complete (ms macros, gropdf)?',
    'groff.err.timeout' => 'groff did not finish in time and was stopped.',
    'groff.err.empty' => 'groff ran but produced no PDF — is the PDF device (gropdf) installed?',
    'groff.err.log' => 'groff said:',
];
