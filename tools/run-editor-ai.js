#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Ai/EditorAiTest: reads a JSON list of cases on
// stdin — { fn, args } — runs each through assets/js/editor-ai.js and writes,
// per case, the result and, for an edit, the text after it.

const ai = require('../assets/js/editor-ai.js');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    process.stdout.write(JSON.stringify(JSON.parse(input).map(({ fn, args }) => {
        const result = ai[fn](...args);
        const isEdit = result !== null && typeof result === 'object' && 'from' in result;
        const text = fn === 'apply' ? args[1] : null;
        return { result, text: isEdit && text !== null ? text.slice(0, result.from) + result.insert + text.slice(result.to) : null };
    })));
});
