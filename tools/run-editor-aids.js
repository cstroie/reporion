#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Render/EditorAidsTest: reads a JSON list of
// cases on stdin: { fn, args }: runs each through
// assets/js/editor-aids.js and writes the results. Never invoked by the
// server.

const aids = require('../assets/js/editor-aids.js');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    process.stdout.write(JSON.stringify(JSON.parse(input).map(({ fn, args }) => aids[fn](...args))));
});
