#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Render/EditorChecklistTest: reads a JSON list of
// cases on stdin — { fn, args } — runs each through
// assets/js/editor-checklist.js and writes the results. Never invoked by the
// server.

const checklist = require('../assets/js/editor-checklist.js');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    process.stdout.write(JSON.stringify(JSON.parse(input).map(({ fn, args }) => checklist[fn](...args))));
});
