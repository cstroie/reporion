/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The report tab's Suggest tags button (2026-10-08): the reserved `tags`
 * prompt, asked about the whole saved text (POST /api/v1/ai/complete with
 * source: "page"). The server parses the answer (Support\TagList) and sends
 * the list as `tags`; the report's own tags first, then the new ones, open
 * in a dialog the writer can edit, and Save writes them as `tags` through
 * PATCH /api/v1/pages/{path}/meta: one new revision. Nothing is saved
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
  var again = dialog.querySelector('[data-ai-tags-again]');
  var LIMIT = 120000; // ms: a local model on a long report is slow, but not forever

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

  // The button shows its own spinner while the assistant works (the page stays
  // usable); the dialog opens on its answer or its error
  function busy(el, on) {
    if (on) { el.setAttribute('data-busy', ''); el.setAttribute('aria-busy', 'true'); }
    else { el.removeAttribute('data-busy'); el.removeAttribute('aria-busy'); }
  }

  function open() {
    if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
  }

  // Ask the assistant: from the panel's button, or Again in the dialog
  function ask(event) {
    var trigger = (event && event.currentTarget) || button;
    if (trigger.getAttribute('aria-busy') === 'true') return;
    busy(trigger, true);
    state('', false);
    meta.textContent = '';
    field.hidden = true;
    apply.hidden = true;
    apply.disabled = false;
    // Past the limit the request stops and the dialog says so
    var stopped = false;
    var controller = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () {
      stopped = true;
      if (controller) controller.abort();
      busy(trigger, false);
      open();
      state(s.timeout, true);
      if (again) again.hidden = false;
    }, LIMIT);
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
      clearTimeout(timer);
      busy(trigger, false);
      open();
      if (again) again.hidden = false;
      if (!r.ok || r.json.error) {
        state(r.json.error && r.json.error.message ? r.json.error.message : s.failed, true);
        return;
      }
      var j = r.json;
      meta.textContent = [j.provider || '', (j.ms / 1000).toFixed(1) + ' s'].concat(j.usage && j.usage.completion_tokens ? [j.usage.completion_tokens + ' tok'] : []).filter(Boolean).join(' · ');
      var proposed = r.json.tags || [];
      if (proposed.length === 0) { state(s.empty, true); return; }
      input.value = split((config.tags || []).concat(proposed).join(',')).join(', ');
      field.hidden = false;
      apply.hidden = false;
      input.focus();
    }).catch(function () {
      if (stopped) return;
      clearTimeout(timer);
      busy(trigger, false);
      open();
      if (again) again.hidden = false;
      state(s.failed, true);
    });
  }

  button.addEventListener('click', ask);
  if (again) again.addEventListener('click', ask);

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
