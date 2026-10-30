/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the notebook (docs/library.md, phase 5; templates/partials/notebook-ask.php): the question
 * goes to POST /api/v1/notebook/ask; the answer is rendered by the server (POST /api/v1/render,
 * render-client.js) in the page's citation style, so its [@key, p. N] become the page's numbered
 * citations and References; the sources read are listed with their pages. Nothing is saved.
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function notice(kind, text) {
    var card = el('div', 'wk-notice wk-notice-' + kind);
    card.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    var i = el('i', 'ph ph-' + (kind === 'error' ? 'warning-octagon' : 'info'));
    i.setAttribute('aria-hidden', 'true');
    card.appendChild(i);
    card.appendChild(el('div', '', text));
    return card;
  }

  function mount(box) {
    var cfgEl = box.querySelector(':scope > script[type="application/json"]');
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var form = box.querySelector('[data-notebook-form]');
    var out = box.querySelector('[data-notebook-out]');
    var button = box.querySelector('[data-notebook-ask]');

    function show(data) {
      out.textContent = '';
      if (data.understood) out.appendChild(el('p', 'wk-dim wk-text-sm', (s.understood || '%s').replace('%s', data.understood)));
      (data.notices || []).forEach(function (n) { out.appendChild(notice('info', n)); });
      var body = el('div', 'wk-notebook-answer wk-prose');
      out.appendChild(body);
      var extra = { citation_style: cfg.citationStyle || '', language: cfg.language || '' };
      if (window.ReporionRender) {
        window.ReporionRender.html(cfg.basePath || '', data.answer || '', false, false, extra)
          .then(function (html) { body.innerHTML = html; }, function () { body.textContent = data.answer || ''; });
      } else {
        body.textContent = data.answer || '';
      }
      if (data.dropped > 0) out.appendChild(notice('info', (s.dropped || '%s').replace('%s', data.dropped)));
      var read = el('details', 'wk-notebook-sources');
      read.appendChild(el('summary', 'wk-dim wk-text-sm', (s.sources || '') + ' (' + (data.sources || []).length + ') · ' + (data.whole ? s.whole : s.passages)));
      var list = el('ol', 'wk-text-sm');
      (data.sources || []).forEach(function (src) {
        var li = el('li');
        var a = el('a', '', src.title + (src.key ? ' [@' + src.key + ']' : ''));
        a.href = src.href;
        li.appendChild(a);
        if (src.page) li.appendChild(document.createTextNode(', p. ' + src.page));
        list.appendChild(li);
      });
      read.appendChild(list);
      out.appendChild(read);
      out.appendChild(el('p', 'wk-dim wk-text-xs', s.note + (data.provider ? ' · ' + data.provider + ' · ' + ((data.ms || 0) / 1000).toFixed(1) + ' s' : '')));
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var question = form.elements.question.value.trim();
      if (!question || button.disabled) return;
      button.disabled = true;
      out.hidden = false;
      out.textContent = '';
      out.appendChild(notice('info', s.asking));
      fetch((cfg.basePath || '') + '/api/v1/notebook/ask', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ path: cfg.path, question: question })
      }).then(function (r) {
        return r.json().then(function (j) {
          if (r.ok && j.data) { show(j.data); return; }
          out.textContent = '';
          out.appendChild(notice('error', (j.error && j.error.message) || s.failed));
        });
      }).catch(function () {
        out.textContent = '';
        out.appendChild(notice('error', s.failed));
      }).then(function () { button.disabled = false; });
    });
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="notebook-ask"]'), mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
