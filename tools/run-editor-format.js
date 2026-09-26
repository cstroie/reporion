#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Js/EditorFormatTest: reads a JSON list of
// cases on stdin — { fn, args } — runs each through
// assets/js/editor-format.js and writes, per case, the edit and the text
// after it. Never invoked by the server.

const format = require('../assets/js/editor-format.js');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    const results = JSON.parse(input).map(({ fn, args }) => {
        const result = format[fn](...args);
        const isEdit = result !== null && typeof result === 'object' && 'from' in result;
        return { result, text: isEdit ? format.apply(args[0], result) : null };
    });
    process.stdout.write(JSON.stringify(results));
});
