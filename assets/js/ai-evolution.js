/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The patient timeline's Evolution panel (2026-10-07): the reserved
 * `evolution` prompt, asked about this report's saved text (POST
 * /api/v1/ai/complete, source: "page"): its {history} brings the patient's
 * other reports, de-identified on the server (Service\Ai\Context): on
 * the compare screen (phase 17a) only the one in `config.with`. The
 * answer is rendered in the panel with the editor preview's marked.js
 * setup; nothing is written.
 */
(function () {
  'use strict';

  var button = document.querySelector('[data-ai-evo]');
  var configEl = document.getElementById('ai-evo-config');
  if (!button || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;
  var panel = button.closest('.wk-ai-evo');
  var body = panel.querySelector('[data-ai-evo-body]');
  var meta = panel.querySelector('[data-ai-evo-meta]');
  var copy = panel.querySelector('[data-ai-evo-copy]');
  var answer = '';
  var info = '';
  var configured = false;

  /** The call's details for the footer: server · model, time, tokens out, speed, tries, failover */
  function details(json) {
    var usage = json.usage || {};
    var seconds = json.ms / 1000;
    var parts = [json.provider || '', seconds.toFixed(1) + ' s'];
    if (usage.completion_tokens) {
      parts.push(usage.completion_tokens + ' tok');
      if (seconds > 0) parts.push((usage.completion_tokens / seconds).toFixed(1) + ' tok/s');
    }
    if (json.attempt > 1) parts.push(s.tries.replace('%d', String(json.attempt)));
    if (json.failover) parts.push(json.failover.from + ' → ' + json.failover.to);
    return parts.filter(Boolean).join(' · ');
  }

  function render(markdown) {
    if (window.marked && window.ReporionPreview) {
      if (!configured) { ReporionPreview.configure(marked, { basePath: config.basePath, examIds: false }); configured = true; }
      body.innerHTML = marked.parse(markdown);
      ReporionPreview.sanitize(body);
    } else {
      body.textContent = markdown;
      body.style.whiteSpace = 'pre-wrap';
    }
  }

  function fail(message) {
    panel.setAttribute('data-state', 'error');
    body.textContent = message || s.failed;
    meta.textContent = '';
  }

  button.addEventListener('click', function () {
    if (button.getAttribute('aria-busy') === 'true') return;
    button.setAttribute('aria-busy', 'true');
    panel.removeAttribute('data-state');
    meta.textContent = '';
    copy.hidden = true;
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ path: config.path, action: 'evolution', source: 'page', label: 'text' }, config.with ? { with: config.with } : {}))
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      button.removeAttribute('aria-busy');
      if (!r.ok || r.json.error) { fail(r.json.error ? r.json.error.message : ''); return; }
      answer = String(r.json.result || '').trim();
      if (answer === '') { fail(''); return; }
      render(answer);
      info = details(r.json);
      meta.textContent = info;
      copy.hidden = false;
    }).catch(function () {
      button.removeAttribute('aria-busy');
      fail('');
    });
  });

  // The Clipboard API needs a secure origin (https or localhost); over plain http, or when it
  // refuses, a hidden textarea and execCommand('copy') do the same
  function fallbackCopy(text) {
    var tmp = document.createElement('textarea');
    tmp.value = text;
    tmp.setAttribute('readonly', '');
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    tmp.remove();
    return ok;
  }

  function copied(ok) {
    meta.textContent = ok ? s.copied : s.copyFailed;
    setTimeout(function () { meta.textContent = info; }, 2000);
  }

  copy.addEventListener('click', function () {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(answer).then(function () { copied(true); }, function () { copied(fallbackCopy(answer)); });
    } else {
      copied(fallbackCopy(answer));
    }
  });
})();
