/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The report tab's Summarize button (2026-10-07): the reserved `summary`
 * prompt, asked about the saved text (POST /api/v1/ai/complete with
 * source: "page") — every conclusion, each under its exam
 * (Service\Ai\ReportSummary); the answer, tidied to one line, opens in a dialog the
 * writer can edit, and Save writes it as `summary` through
 * PATCH /api/v1/pages/{path}/meta — one new revision, as any metadata
 * change. Nothing is saved without the click.
 */
(function () {
  'use strict';

  var button = document.querySelector('[data-ai-summary]');
  var dialog = document.getElementById('ai-summary-modal');
  var configEl = document.getElementById('ai-summary-config');
  if (!button || !dialog || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;
  var meta = dialog.querySelector('[data-ai-summary-meta]');
  var error = dialog.querySelector('[data-ai-summary-error]');
  var field = dialog.querySelector('[data-ai-summary-field]');
  var input = dialog.querySelector('[data-ai-summary-input]');
  var apply = dialog.querySelector('[data-ai-summary-apply]');
  var again = dialog.querySelector('[data-ai-summary-again]');
  var wait = null;

  /** One line: no markdown marker, label or quotes around it, at most 160 characters */
  function tidy(answer) {
    var lines = String(answer).replace(/\r\n?/g, '\n').split('\n').map(function (l) { return l.trim(); })
      .filter(function (l) { return l !== '' && !/^```/.test(l); });
    var line = (lines[0] || '')
      .replace(/^(#{1,6}|[-*•]|\d+[.)])\s+/, '')
      .replace(/\*\*/g, '')
      .replace(/^(rezumat|summary)\s*:\s*/i, '')
      .replace(/^["'„“”«»`]+|["'„“”«»`]+$/g, '')
      .trim();
    return line.length > 160 ? line.slice(0, 159).replace(/\s+\S*$/, '') + '…' : line;
  }

  function state(text, isError) {
    error.hidden = !isError;
    error.textContent = isError ? text : '';
    if (isError) dialog.setAttribute('data-state', 'error'); else dialog.removeAttribute('data-state');
  }

  // The busy modal (assets/js/modal.js) while the assistant works; the
  // dialog opens on its answer or its error
  function open() {
    if (wait) { wait.close(); wait = null; }
    if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
  }

  // Ask the assistant: from the panel's button, or Again in the dialog
  function ask() {
    if (button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    state('', false);
    meta.textContent = '';
    field.hidden = true;
    apply.hidden = true;
    if (again) again.hidden = true;
    apply.disabled = false;
    // Past the busy modal's limit: the request stops, the dialog says so
    var stopped = false;
    var controller = window.AbortController ? new AbortController() : null;
    wait = window.ReporionModal ? window.ReporionModal.busy(s.busy, function () {
      stopped = true;
      if (controller) controller.abort();
      button.removeAttribute('aria-busy');
      wait = null;
      open();
      state(s.timeout, true);
      if (again) again.hidden = false;
    }) : null;
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ path: config.path, action: 'summary', source: 'page', label: 'text' }),
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      if (stopped) return;
      button.removeAttribute('aria-busy');
      open();
      if (again) again.hidden = false;
      if (!r.ok || r.json.error) {
        meta.textContent = '';
        state(r.json.error && r.json.error.message ? r.json.error.message : s.failed, true);
        return;
      }
      meta.textContent = (r.json.ms / 1000).toFixed(1) + ' s';
      var line = tidy(r.json.result || '');
      if (line === '') { state(s.empty, true); return; }
      input.value = line;
      field.hidden = false;
      apply.hidden = false;
      input.focus();
      input.select();
    }).catch(function () {
      if (stopped) return;
      button.removeAttribute('aria-busy');
      open();
      if (again) again.hidden = false;
      meta.textContent = '';
      state(s.failed, true);
    });
  }

  button.addEventListener('click', ask);
  if (again) again.addEventListener('click', ask);

  apply.addEventListener('click', function () {
    var value = input.value.trim();
    if (value === '') return;
    apply.disabled = true;
    fetch(config.basePath + '/api/v1/pages/' + config.path + '/meta', {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ meta: { summary: value }, base_rev: config.rev })
    }).then(function (response) {
      if (response.ok) { window.location.reload(); return; }
      return response.json().then(function (json) {
        apply.disabled = false;
        state(json.error && json.error.code === 'conflict' ? s.conflict : (json.error && json.error.message ? json.error.message : s.saveFailed), true);
      });
    }).catch(function () {
      apply.disabled = false;
      state(s.saveFailed, true);
    });
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); apply.click(); }
  });
  dialog.querySelector('[data-ai-summary-close]').addEventListener('click', function () { dialog.close(); });
})();
