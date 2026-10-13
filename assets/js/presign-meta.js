/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The Sign screen's Summary and tags panel (2026-10-12). Each field has a
 * Suggest: the assistant's reserved `summary` / `tags` prompt asked about the
 * saved text (POST /api/v1/ai/complete, source: "page"), or, when the
 * profile has none: the rules' own answer the server rendered into the
 * config (the conclusion's first sentence; the RADS categories it states).
 * Save writes both through PATCH /api/v1/pages/{path}/meta with minor: true:
 * this very revision is changed in place, so the revision being signed keeps
 * its number. Signing with unsaved changes saves them first. Nothing here
 * blocks signing.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-presign-meta]');
  var configEl = document.getElementById('presign-meta-config');
  if (!root || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;
  var summaryInput = root.querySelector('[data-meta-summary]');
  var tagsInput = root.querySelector('[data-meta-tags]');
  var saveBtn = root.querySelector('[data-meta-save]');
  var status = root.querySelector('[data-meta-status]');
  var signForm = document.querySelector('form[action$="/sign"]');
  var signBtn = document.querySelector('[data-sign-submit]');
  var baseRevInput = signForm ? signForm.querySelector('input[name="base_rev"]') : null;
  var saved = { summary: config.summary || '', tags: split((config.tags || []).join(',')).join(', ') };
  var saving = false;

  /** The field's text as a list: trimmed, no empties, each once */
  function split(text) {
    var seen = {};
    return String(text).split(',').map(function (t) { return t.trim(); }).filter(function (t) {
      if (t === '' || seen[t]) return false;
      seen[t] = true;
      return true;
    });
  }

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

  function say(text, isError) {
    status.textContent = text;
    status.style.color = isError ? 'var(--state-error)' : '';
  }

  function current() {
    return { summary: summaryInput.value.trim(), tags: split(tagsInput.value).join(', ') };
  }

  function dirty() {
    var now = current();
    return now.summary !== saved.summary || now.tags !== saved.tags;
  }

  function refresh() { saveBtn.disabled = saving || !dirty(); }

  var LIMIT = 120000; // ms: a local model on a long report is slow, but not forever

  function busy(button, on) {
    if (on) {
      button.setAttribute('data-busy', '');
      button.setAttribute('aria-busy', 'true');
    } else {
      button.removeAttribute('data-busy');
      button.removeAttribute('aria-busy');
    }
  }

  summaryInput.addEventListener('input', function () { say('', false); refresh(); });
  tagsInput.addEventListener('input', function () { say('', false); refresh(); });

  // --- Suggest ------------------------------------------------------------

  function suggest(kind, button) {
    if (button.getAttribute('aria-busy') === 'true') return;
    say('', false);
    var ai = kind === 'summary' ? config.aiSummary : config.aiTags;
    if (!ai) { fromRules(kind); return; }

    busy(button, true);
    var stopped = false;
    var controller = window.AbortController ? new AbortController() : null;
    // Past the limit the request stops and the field says so
    var timer = setTimeout(function () {
      stopped = true;
      if (controller) controller.abort();
      busy(button, false);
      say(s.timeout, true);
    }, LIMIT);
    function done() {
      clearTimeout(timer);
      busy(button, false);
    }
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ path: config.path, action: kind, source: 'page', label: 'text' }),
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      if (stopped) return;
      done();
      if (!r.ok || r.json.error) {
        say(r.json.error && r.json.error.message ? r.json.error.message : s.failed, true);
        return;
      }
      if (kind === 'summary') {
        var line = tidy(r.json.result || '');
        if (line === '') { say(s.emptySummary, true); return; }
        summaryInput.value = line;
      } else {
        var proposed = r.json.tags || [];
        if (proposed.length === 0) { say(s.emptyTags, true); return; }
        // The report's own tags first, then the new ones
        tagsInput.value = split(split(tagsInput.value).concat(proposed).join(',')).join(', ');
      }
      refresh();
    }).catch(function () {
      if (stopped) return;
      done();
      say(s.failed, true);
    });
  }

  /** No assistant for this field: the conclusion's first sentence, the RADS categories it states */
  function fromRules(kind) {
    if (kind === 'summary') {
      if (!config.ruleSummary) { say(s.noRule, true); return; }
      summaryInput.value = config.ruleSummary;
    } else {
      var rules = config.ruleTags || [];
      if (rules.length === 0) { say(s.noRule, true); return; }
      tagsInput.value = split(split(tagsInput.value).concat(rules).join(',')).join(', ');
    }
    refresh();
  }

  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-meta-suggest]');
    if (button) suggest(button.getAttribute('data-meta-suggest'), button);
  });

  // --- Save ---------------------------------------------------------------

  /** Calls back with true once the panel's values are on the report (or nothing changed) */
  function save(then) {
    if (saving) return;
    if (!dirty()) { if (then) then(true); return; }
    var now = current();
    var tags = split(now.tags);
    saving = true;
    refresh();
    busy(saveBtn, true);
    // An emptied field removes the key
    fetch(config.basePath + '/api/v1/pages/' + config.path + '/meta', {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ meta: { summary: now.summary !== '' ? now.summary : null, tags: tags.length ? tags : null }, base_rev: config.rev, minor: true })
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      saving = false;
      busy(saveBtn, false);
      if (!r.ok) {
        say(r.json.error && r.json.error.code === 'conflict' ? s.conflict : (r.json.error && r.json.error.message ? r.json.error.message : s.saveFailed), true);
        refresh();
        if (then) then(false);
        return;
      }
      // A minor edit keeps the revision; if the server wrote a new one, follow it
      if (typeof r.json.rev === 'number') {
        config.rev = r.json.rev;
        if (baseRevInput) baseRevInput.value = String(r.json.rev);
        if (signBtn) {
          var label = signBtn.querySelector('span');
          if (label) label.textContent = label.textContent.replace(/\d+/, String(r.json.rev));
        }
      }
      saved = now;
      if (config.reload) { window.location.reload(); return; }
      say(s.saved, false);
      refresh();
      if (then) then(true);
    }).catch(function () {
      saving = false;
      busy(saveBtn, false);
      say(s.saveFailed, true);
      refresh();
      if (then) then(false);
    });
  }

  saveBtn.addEventListener('click', function () { save(null); });
  [summaryInput, tagsInput].forEach(function (input) {
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); save(null); }
    });
  });

  // Signing with unsaved changes saves them first; if that fails, nothing is signed
  if (signForm) {
    signForm.addEventListener('submit', function (event) {
      if (!dirty() && !saving) return;
      event.preventDefault();
      var submitter = event.submitter;
      if (saving) return;
      if (submitter) busy(submitter, true);
      save(function (ok) {
        if (submitter) busy(submitter, false);
        if (!ok) return;
        if (typeof signForm.requestSubmit === 'function') signForm.requestSubmit(submitter || undefined); else signForm.submit();
      });
    });
  }
  refresh();
})();
