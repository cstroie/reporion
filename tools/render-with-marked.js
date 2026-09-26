#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/RenderConformanceTest (D17): reads markdown on
// stdin, renders it with marked.js configured for the same dialect the PHP
// side commits to (generic CommonMark plus GFM tables), and writes the HTML
// to stdout. Never invoked by the server — only by the PHPUnit conformance
// test, which shells out to this via `node`.

const { marked } = require('marked');
const preview = require('../assets/js/markdown-preview.js');

// The browser preview's own configuration, not a copy of it; --base=/path
// mounts it like the app, for links between pages
const baseArg = process.argv.find((arg) => arg.startsWith('--base='));
preview.configure(marked, { basePath: baseArg ? baseArg.slice(7) : '' });

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    process.stdout.write(marked.parse(input));
});
