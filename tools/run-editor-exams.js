#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Render/EditorExamsTest: reads a JSON list of
// cases on stdin — { fn, args } — runs each through
// assets/js/editor-exams.js and writes the results. Never invoked by the
// server.

const exams = require('../assets/js/editor-exams.js');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    process.stdout.write(JSON.stringify(JSON.parse(input).map(({ fn, args }) => exams[fn](...args))));
});
