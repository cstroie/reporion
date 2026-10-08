/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The patient timeline's Evolution panel (2026-10-07): the reserved
 * `evolution` prompt, asked about this report's saved text (POST
 * /api/v1/ai/complete, source: "page") — its {history} brings the patient's
 * other reports, de-identified on the server (Service\Ai\Context). The
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
  var configured = false;

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
    meta.textContent = s.working;
    meta.setAttribute('data-working', '');
    copy.hidden = true;
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ path: config.path, action: 'evolution', source: 'page', label: 'text' })
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      button.removeAttribute('aria-busy');
      meta.removeAttribute('data-working');
      if (!r.ok || r.json.error) { fail(r.json.error ? r.json.error.message : ''); return; }
      answer = String(r.json.result || '').trim();
      if (answer === '') { fail(''); return; }
      render(answer);
      meta.textContent = (r.json.ms / 1000).toFixed(1) + ' s' + (r.json.provider ? ' · ' + r.json.provider : '');
      copy.hidden = false;
    }).catch(function () {
      button.removeAttribute('aria-busy');
      meta.removeAttribute('data-working');
      fail('');
    });
  });

  copy.addEventListener('click', function () {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(answer).then(function () { meta.textContent = s.copied; });
    }
  });
})();
