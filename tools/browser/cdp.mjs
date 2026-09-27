#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Test-only: drive headless Chrome over the DevTools protocol against a
// tools/browser/start.sh instance — no puppeteer, Node's own WebSocket.
//
// As a library:
//   import { open } from './tools/browser/cdp.mjs';
//   const b = await open({ dir, base: 'http://127.0.0.1:8791', width: 1440, height: 900 });
//   await b.go('/reports:mri:mioveni:260927-test-multi/edit');
//   await b.eval(`document.title`); await b.type('text'); await b.key('z', { ctrl: true, command: 'undo' });
//   await b.shot('/path/out.png'); b.close();
// As a command:
//   node tools/browser/cdp.mjs shot <fixture dir> <url path> <out.png> [width] [height]
//     prints the page's scrollWidth vs viewport (a phone layout must not scroll sideways)
//
// Each open() uses a free debugging port and a profile under <dir>, so it
// never attaches to another session's Chrome.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const freePort = () => new Promise((resolve) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); }); });

export async function open({ dir, base = 'http://127.0.0.1:8791', width = 1440, height = 900, mobile = false, cookie = true }) {
  const port = await freePort();
  const chrome = spawn('google-chrome', ['--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${port}`, `--user-data-dir=${dir}/chrome-${port}`, 'about:blank'], { stdio: 'ignore' });
  let wsUrl;
  for (let i = 0; i < 60 && !wsUrl; i++) { await sleep(200); try { wsUrl = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page')?.webSocketDebuggerUrl; } catch {} }
  if (!wsUrl) { chrome.kill(); throw new Error('Chrome did not start'); }
  const ws = new WebSocket(wsUrl);
  await new Promise((r) => ws.addEventListener('open', r));
  let id = 0; const pending = new Map(); const waiters = []; const logs = [];
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') logs.push('exception: ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
    if (m.method === 'Log.entryAdded') logs.push(`${m.params.entry.level}: ${m.params.entry.text} ${m.params.entry.url || ''}`);
    waiters.filter((w) => w.method === m.method).forEach((w) => w.resolve(m));
  });
  const send = (method, params = {}) => new Promise((resolve) => { const i = ++id; pending.set(i, resolve); ws.send(JSON.stringify({ id: i, method, params })); });
  const once = (method) => new Promise((resolve) => { const w = { method, resolve: (m) => { waiters.splice(waiters.indexOf(w), 1); resolve(m); } }; waiters.push(w); });
  await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable'); await send('Network.enable');
  if (cookie) await send('Network.setCookie', { name: 'reporion', value: readFileSync(`${dir}/cookie`, 'utf8').trim(), url: base });
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });

  const api = {
    logs,
    send,
    async go(path) { const loaded = once('Page.loadEventFired'); await send('Page.navigate', { url: base + path }); await loaded; await sleep(400); },
    async eval(expression) {
      const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (r.result.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text);
      return r.result.result.value;
    },
    async submit(selector) { const loaded = once('Page.loadEventFired'); await api.eval(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`); await loaded; await sleep(300); },
    /** Types as a person (and a dictation program) would: one character at a time */
    async type(text) { for (const ch of text) { await send('Input.insertText', { text: ch }); await sleep(15); } await sleep(100); },
    /** A real key press; command is the editing command Chrome should run (undo, redo) */
    async key(key, { ctrl = false, shift = false, alt = false, command } = {}) {
      const modifiers = (alt ? 1 : 0) | (ctrl ? 2 : 0) | (shift ? 8 : 0);
      const code = key.length === 1 ? 'Key' + key.toUpperCase() : key;
      await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key, code, windowsVirtualKeyCode: key.length === 1 ? key.toUpperCase().charCodeAt(0) : 0, modifiers, commands: command ? [command] : [] });
      await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, modifiers });
      await sleep(150);
    },
    async shot(out) { writeFileSync(out, Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).result.data, 'base64')); },
    async overflow() { return api.eval('({ scrollWidth: document.documentElement.scrollWidth, viewport: innerWidth })'); },
    close() { ws.close(); chrome.kill(); },
  };
  return api;
}

if (import.meta.url === `file://${process.argv[1]}` && process.argv[2] === 'shot') {
  const [, , , dir, path, out, w = '1440', h = '900'] = process.argv;
  const b = await open({ dir, width: +w, height: +h, mobile: +w < 700 });
  try {
    await b.go(path);
    await b.shot(out);
    const o = await b.overflow();
    console.log(`${out}  scrollWidth ${o.scrollWidth} / viewport ${o.viewport}${o.scrollWidth > o.viewport ? '  ← overflows' : ''}`);
    if (b.logs.length) console.log(b.logs.join('\n'));
  } finally { b.close(); }
}
