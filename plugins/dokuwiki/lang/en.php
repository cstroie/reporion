<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

return [
    'dokuwiki.tool.links' => 'DokuWiki links to Markdown',
    'dokuwiki.tool.links_help' => 'Turns [[http://example.com|Example]] into [Example](http://example.com), and [[ns:page|Text]] into a page link. Acts on the selection, or on the whole text when nothing is selected. Code is left alone.',
    'dokuwiki.tool.markup' => 'DokuWiki formatting to Markdown',
    'dokuwiki.tool.markup_help' => 'Headings (====== H ======), <code python file.py> blocks as fenced code, definition lists (; term, : definition), //italic//, \'\'mono\'\', <sup>, <sub>, forced line breaks and the two-space * and - lists. Links and tables have their own tools. A text that already has Markdown lists keeps them.',
    'dokuwiki.tool.tables' => 'DokuWiki tables to Markdown',
    'dokuwiki.tool.tables_help' => '^ head ^ and | cell | rows become a Markdown table: alignment from the spaces around a cell, a column span (||) as <<, a row header in bold. A table with a row span (:::) is left as it is.',
];
