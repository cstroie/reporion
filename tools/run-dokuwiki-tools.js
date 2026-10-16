#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only harness for tests/Plugin/DokuwikiToolsTest: reads a JSON list of cases on stdin:
// { fn, args }: runs each through the dokuwiki plugin's editor tools
// (plugins/dokuwiki/assets/tools.js, built on assets/js/editor-format.js) and writes, per case, the
// edit and the text after it. Never invoked by the server.

const format = require('../assets/js/editor-format.js');
const tools = require('../plugins/dokuwiki/assets/tools.js')(format);

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    const results = JSON.parse(input).map(({ fn, args }) => {
        const result = tools[fn](...args);
        return { result, text: format.apply(args[0], result) };
    });
    process.stdout.write(JSON.stringify(results));
});
