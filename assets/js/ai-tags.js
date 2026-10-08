/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The report tab's Suggest tags button (2026-10-08): the reserved `tags`
 * prompt, asked about the whole saved text (POST /api/v1/ai/complete with
 * source: "page"). The server parses the answer (Support\TagList) and sends
 * the list as `tags`; the report's own tags first, then the new ones, open
 * in a dialog the writer can edit, and Save writes them as `tags` through
 * PATCH /api/v1/pages/{path}/meta — one new revision. Nothing is saved
 * without the click.
 */
(function () {
  'use strict';

  var button = document.querySelector('[data-ai-tags]');
  var dialog = document.getElementById('ai-tags-modal');
  var configEl = document.getElementById('ai-tags-config');
  if (!button || !dialog || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;
  var meta = dialog.querySelector('[data-ai-tags-meta]');
  var error = dialog.querySelector('[data-ai-tags-error]');
  var field = dialog.querySelector('[data-ai-tags-field]');
  var input = dialog.querySelector('[data-ai-tags-input]');
  var apply = dialog.querySelector('[data-ai-tags-apply]');
  var wait = null;

  /** The field's text as a list: trimmed, no empties, each once */
  function split(text) {
    var seen = {};
    return String(text).split(',').map(function (t) { return t.trim(); }).filter(function (t) {
      var key = t.toLowerCase();
      if (t === '' || seen[key]) return false;
      seen[key] = true;
      return true;
    });
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

  button.addEventListener('click', function () {
    if (button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    state('', false);
    meta.textContent = '';
    field.hidden = true;
    apply.hidden = true;
    apply.disabled = false;
    var stopped = false;
    var controller = window.AbortController ? new AbortController() : null;
    wait = window.ReporionModal ? window.ReporionModal.busy(s.busy, function () {
      stopped = true;
      if (controller) controller.abort();
      button.removeAttribute('aria-busy');
      wait = null;
      open();
      state(s.timeout, true);
    }) : null;
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ path: config.path, action: 'tags', source: 'page', label: 'text' }),
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      if (stopped) return;
      button.removeAttribute('aria-busy');
      open();
      if (!r.ok || r.json.error) {
        state(r.json.error && r.json.error.message ? r.json.error.message : s.failed, true);
        return;
      }
      meta.textContent = (r.json.ms / 1000).toFixed(1) + ' s';
      var proposed = r.json.tags || [];
      if (proposed.length === 0) { state(s.empty, true); return; }
      input.value = split((config.tags || []).concat(proposed).join(',')).join(', ');
      field.hidden = false;
      apply.hidden = false;
      input.focus();
    }).catch(function () {
      if (stopped) return;
      button.removeAttribute('aria-busy');
      open();
      state(s.failed, true);
    });
  });

  apply.addEventListener('click', function () {
    var tags = split(input.value);
    apply.disabled = true;
    fetch(config.basePath + '/api/v1/pages/' + config.path + '/meta', {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      // An emptied field removes the tags
      body: JSON.stringify({ meta: { tags: tags.length ? tags : null }, base_rev: config.rev })
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
  dialog.querySelector('[data-ai-tags-close]').addEventListener('click', function () { dialog.close(); });
})();
