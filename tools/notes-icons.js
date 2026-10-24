// SPDX-License-Identifier: GPL-3.0-or-later
//
// The notes app's PNG icons (D55), rendered from their SVG sources in
// assets/notes/ by the pre-installed Chromium:  node tools/notes-icons.js
// Run it after changing an SVG and commit the PNGs with it.
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, '..', 'assets', 'notes');
const jobs = [
  ['icon.svg', 'icon-192.png', 192],
  ['icon.svg', 'icon-512.png', 512],
  ['icon-maskable.svg', 'icon-maskable-512.png', 512],
  ['icon-apple.svg', 'apple-touch-icon.png', 180],
];

(async () => {
  const browser = await chromium.launch();
  for (const [source, out, size] of jobs) {
    const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 });
    const svg = fs.readFileSync(path.join(dir, source), 'utf8').replace('width="512" height="512"', `width="${size}" height="${size}"`);
    await page.setContent(`<html><body style="margin:0;background:transparent">${svg}</body></html>`);
    await page.locator('svg').screenshot({ path: path.join(dir, out), omitBackground: true });
    await page.close();
    console.log(`${out} ${size}x${size}`);
  }
  await browser.close();
})().catch((error) => { console.error(error); process.exit(1); });
