/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The Sign screen's assistant check (roadmap phase 34a): the reserved
 * `presign` prompt asked about the saved report (POST /api/v1/ai/complete,
 * source: "page"), its answer shown as a list of possible problems —
 * warnings, never a block. Asked once per revision: the answer is kept for
 * this browser tab (sessionStorage, by path and revision), *Again* asks anew.
 * How many points it raised goes with the Sign form (presign_ai), for the
 * audit's count — never their words.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-presign]');
  var configEl = document.getElementById('presign-config');
  if (!box || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;
  var out = box.querySelector('[data-presign-out]');
  var meta = box.querySelector('[data-presign-meta]');
  var again = box.querySelector('[data-presign-again]');
  var count = document.querySelector('[data-presign-count]');
  var key = 'reporion.presign:' + config.path + '@' + config.rev;
  box.hidden = false;

  /** The answer's points: its list lines, else its non-empty lines; "none"-like answers are no points */
  function points(answer) {
    var lines = String(answer).replace(/\r\n?/g, '\n').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    var items = lines.filter(function (l) { return /^([-*•]|\d+[.)])\s+/.test(l); }).map(function (l) { return l.replace(/^([-*•]|\d+[.)])\s+/, ''); });
    if (items.length === 0) items = lines;
    items = items.map(function (l) { return l.replace(/\*\*/g, ''); });
    if (items.length === 1 && /^(none|nimic|fără probleme|nu (am |s-au )?(găsit|identificat))/i.test(items[0])) return [];
    return items;
  }

  function show(answer, info) {
    var list = points(answer);
    out.innerHTML = '';
    if (list.length === 0) {
      out.textContent = s.none;
    } else {
      var ul = document.createElement('ul');
      ul.className = 'wk-presign-list';
      list.forEach(function (text) {
        var li = document.createElement('li');
        var icon = document.createElement('i');
        icon.className = 'ph ph-sparkle';
        icon.setAttribute('aria-hidden', 'true');
        var span = document.createElement('span');
        span.textContent = text;
        li.appendChild(icon);
        li.appendChild(span);
        ul.appendChild(li);
      });
      out.appendChild(ul);
    }
    meta.textContent = info || '';
    if (count) count.value = String(list.length);
  }

  function ask() {
    again.disabled = true;
    meta.textContent = s.working;
    meta.setAttribute('data-working', '');
    out.textContent = '';
    fetch(config.basePath + '/api/v1/ai/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ path: config.path, action: 'presign', source: 'page', label: 'text' })
    }).then(function (response) {
      return response.json().then(function (json) { return { ok: response.ok, json: json }; });
    }).then(function (r) {
      again.disabled = false;
      meta.removeAttribute('data-working');
      if (!r.ok || r.json.error) {
        meta.textContent = '';
        out.textContent = r.json.error && r.json.error.message ? r.json.error.message : s.failed;
        return;
      }
      var info = (r.json.ms / 1000).toFixed(1) + ' s';
      try { sessionStorage.setItem(key, JSON.stringify({ result: r.json.result || '', info: info })); } catch (e) { /* kept for this view only */ }
      show(r.json.result || '', info);
    }).catch(function () {
      again.disabled = false;
      meta.removeAttribute('data-working');
      meta.textContent = '';
      out.textContent = s.failed;
    });
  }

  again.addEventListener('click', ask);
  var kept = null;
  try { kept = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { kept = null; }
  if (kept && typeof kept.result === 'string') show(kept.result, kept.info); else ask();
})();
