/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the archive (D53): POST /api/v1/ask {question}. The answer is
 * rendered by the server's one parser (render-client.js); its [n] are the
 * numbered sources under it, each a link to its report by pid and rev.
 * Sources are shown with textContent. Shown only: nothing is written.
 */
(function () {
  'use strict';

  function mount(box) {
    var cfgEl = box.querySelector('script[type="application/json"]');
    if (!cfgEl) return;
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var form = box.querySelector('[data-ask-form]');
    var submit = box.querySelector('[data-ask-submit]');
    var status = box.querySelector('[data-ask-status]');
    var answer = box.querySelector('[data-ask-answer]');
    var context = box.querySelector('[data-ask-context]');
    var sourcesBox = box.querySelector('[data-ask-sources]');
    var sources = sourcesBox.querySelector('ol');

    function el(tag, cls, text) {
      var node = document.createElement(tag);
      if (cls) node.className = cls;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    // The notice card (templates/partials/notice.php, docs/ui-notices.md)
    function notice(kind, text) {
      status.textContent = '';
      if (!text) return;
      var card = el('div', 'wk-notice wk-notice-' + kind);
      card.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
      var icon = el('i', 'ph ph-' + ({ info: 'info', warn: 'warning', error: 'warning-octagon' }[kind] || 'info'));
      icon.setAttribute('aria-hidden', 'true');
      card.appendChild(icon);
      card.appendChild(el('div', '', text));
      status.appendChild(card);
    }

    function reset() {
      answer.hidden = true;
      answer.textContent = '';
      context.hidden = true;
      sourcesBox.hidden = true;
      sources.textContent = '';
    }

    function showSources(list) {
      list.forEach(function (src) {
        var li = el('li', 'wk-ask-source');
        li.id = 'ask-src-' + src.n;
        li.value = src.n;
        var head = el('div', 'wk-mono wk-dim wk-text-xs');
        head.textContent = [src.study_date || '', src.modality || '', src.heading || ''].filter(Boolean).join(' · ');
        if (src.mine) head.appendChild(el('span', 'tag tag-neutral', s.mine || ''));
        li.appendChild(head);
        li.appendChild(el('p', 'wk-text-sm', src.text));
        var a = el('a', 'wk-text-xs', s.open || '');
        a.href = (cfg.basePath || '') + src.href;
        li.appendChild(a);
        sources.appendChild(li);
      });
      sourcesBox.hidden = list.length === 0;
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var question = form.elements.question.value.trim();
      if (!question) return;
      reset();
      submit.disabled = true;
      notice('info', s.asking || '');
      fetch((cfg.basePath || '') + '/api/v1/ask', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ question: question })
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (json) { return { ok: response.ok, json: json }; });
      }).then(function (r) {
        if (!r.ok || !r.json.data) {
          notice('error', r.json.error && r.json.error.message ? r.json.error.message : (s.failed || ''));
          return null;
        }
        var data = r.json.data;
        if (data.answer === null) {
          notice('info', s.none || '');
          return null;
        }
        notice('', '');
        showSources(data.sources || []);
        if (data.context && data.context.length) {
          context.textContent = (s.context || '') + ' ' + data.context.join(' · ') + (data.provider ? ' · ' + data.provider : '');
          context.hidden = false;
        }
        return window.ReporionRender.html(cfg.basePath, data.answer).then(function (html) {
          answer.innerHTML = html;
          answer.hidden = false;
        }, function () {
          answer.textContent = data.answer;
          answer.hidden = false;
        });
      }).catch(function () {
        notice('error', s.failed || '');
      }).then(function () {
        submit.disabled = false;
      });
    });
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="ask"]'), mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
