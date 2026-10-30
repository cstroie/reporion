/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The browser's way to show markdown: the server's own renderer, over
 * POST /api/v1/render (Service\Render, the one parser: D17 amended
 * 2026-10-15, marked.js is gone). The editor's split preview, the
 * assistant's `show` answers and the Evolution panel use it.
 *
 *   ReporionRender.html(basePath, markdown, exams, lines) -> Promise<string>
 *       lines: each block carries data-line, its source line (the split preview's sync)
 *       extra: more request fields, e.g. { citation_style, language } of the page (citations, docs/library.md)
 *   ReporionRender.body(documentText)    -> the text below the frontmatter
 *   ReporionRender.skipped(documentText) -> how many lines body() leaves out
 *
 * The HTML comes back escaped and with unsafe URLs dropped, so it is set
 * as innerHTML as it is.
 */
(function (root) {
  'use strict';

  function html(basePath, markdown, exams, lines, extra) {
    return root.fetch((basePath || '') + '/api/v1/render', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ markdown: markdown, exams: !!exams, lines: !!lines }, extra || {}))
    }).then(function (response) {
      if (!response.ok) throw new Error('render ' + response.status);
      return response.json();
    }).then(function (json) {
      if (typeof json.html !== 'string') throw new Error('render: no html');
      return json.html;
    });
  }

  // Same shape Support\DocumentFormat::parse() accepts: "---\n…\n---\n\n?body"
  function body(documentText) {
    var normalised = String(documentText).replace(/\r\n?/g, '\n');
    var match = /^---\n[\s\S]*?\n---\n\n?/.exec(normalised);
    return match ? normalised.slice(match[0].length) : normalised;
  }

  function skipped(documentText) {
    var normalised = String(documentText).replace(/\r\n?/g, '\n');
    var match = /^---\n[\s\S]*?\n---\n\n?/.exec(normalised);
    return match ? match[0].split('\n').length - 1 : 0;
  }

  root.ReporionRender = { html: html, body: body, skipped: skipped };
}(this));
