/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the notebook (docs/library.md, phase 5; templates/partials/notebook-ask.php) and Ask the
 * library (/library, 2026-11-02): the question goes to the island's endpoint
 * (POST /api/v1/notebook/ask or /api/v1/library/ask) with `stream: true`; the sources chosen are
 * listed as soon as they are known, the answer shows as it is written, and when it is done it is
 * rendered by the server (POST /api/v1/render, render-client.js) in the page's citation style, so
 * its [@key, p. N] become numbered citations and References. Ask the library keeps the last turns
 * in the browser (`history`). Nothing is saved.
 */
(function () {
  'use strict';

  var TURNS = 5;

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

  // Server-sent events out of a buffer: the complete ones, and what is left
  function parseEvents(buffer) {
    var events = [];
    var blocks = buffer.split('\n\n');
    var rest = blocks.pop();
    blocks.forEach(function (block) {
      var ev = { event: 'message', data: null };
      block.split('\n').forEach(function (line) {
        if (line.indexOf('event: ') === 0) ev.event = line.slice(7);
        else if (line.indexOf('data: ') === 0) {
          try { ev.data = JSON.parse(line.slice(6)); } catch (e) { ev.data = null; }
        }
      });
      events.push(ev);
    });
    return { events: events, rest: rest };
  }

  function mount(box) {
    var cfgEl = box.querySelector(':scope > script[type="application/json"]');
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var form = box.querySelector('[data-notebook-form]');
    var out = box.querySelector('[data-notebook-out]');
    var button = box.querySelector('[data-notebook-ask]');
    var clear = box.querySelector('[data-notebook-clear]');
    var history = [];

    function sourcesList(sources, whole) {
      var read = el('details', 'wk-notebook-sources');
      read.appendChild(el('summary', 'wk-dim wk-text-sm', (s.sources || '') + ' (' + (sources || []).length + ') · ' + (whole ? s.whole : s.passages)));
      var list = el('ol', 'wk-text-sm');
      (sources || []).forEach(function (src) {
        var li = el('li');
        var a = el('a', '', src.title + (src.key ? ' [@' + src.key + ']' : ''));
        a.href = src.href;
        li.appendChild(a);
        if (src.page) li.appendChild(document.createTextNode(', p. ' + src.page));
        list.appendChild(li);
      });
      read.appendChild(list);
      return read;
    }

    function show(data) {
      out.textContent = '';
      if (data.understood) out.appendChild(el('p', 'wk-dim wk-text-sm', (s.understood || '%s').replace('%s', data.understood)));
      (data.notices || []).forEach(function (n) { out.appendChild(notice('info', n)); });
      if (data.answer === null) return;
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
      out.appendChild(sourcesList(data.sources, data.whole));
      out.appendChild(el('p', 'wk-dim wk-text-xs', s.note + (data.provider ? ' · ' + data.provider + ' · ' + ((data.ms || 0) / 1000).toFixed(1) + ' s' : '')));
    }

    function failed(message) {
      out.textContent = '';
      out.appendChild(notice('error', message || s.failed));
    }

    function remember(question, data) {
      if (!cfg.history || !data || !data.answer) return;
      history.push({ question: question, answer: data.answer });
      if (history.length > TURNS) history.shift();
      if (clear) clear.hidden = false;
    }

    if (clear) {
      clear.addEventListener('click', function () {
        history = [];
        clear.hidden = true;
        out.textContent = '';
        out.hidden = true;
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var question = form.elements.question.value.trim();
      if (!question || button.disabled) return;
      button.disabled = true;
      out.hidden = false;
      out.textContent = '';
      var status = notice('info', s.asking);
      out.appendChild(status);
      var live = null;
      var payload = { question: question, stream: true };
      if (cfg.path) payload.path = cfg.path;
      if (cfg.history) payload.history = history.slice();

      function step(name) {
        var text = s['step_' + name];
        if (text) status.lastChild.textContent = text;
      }

      fetch((cfg.basePath || '') + (cfg.endpoint || '/api/v1/notebook/ask'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json' },
        body: JSON.stringify(payload)
      }).then(function (r) {
        var type = r.headers.get('Content-Type') || '';
        if (type.indexOf('text/event-stream') === -1 || !r.body || !window.TextDecoder) {
          return r.json().then(function (j) {
            if (r.ok && j.data) { show(j.data); remember(question, j.data); return; }
            failed(j.error && j.error.message);
          });
        }
        var reader = r.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        function pump() {
          return reader.read().then(function (chunk) {
            if (chunk.done) return;
            buffer += decoder.decode(chunk.value, { stream: true });
            var parsed = parseEvents(buffer);
            buffer = parsed.rest;
            var ended = false;
            parsed.events.forEach(function (ev) {
              if (!ev.data) return;
              if (ev.event === 'progress') {
                step(ev.data.step);
              } else if (ev.event === 'sources') {
                // What will be read, before the model answers
                out.appendChild(sourcesList(ev.data.sources, ev.data.whole));
              } else if (ev.event === 'delta') {
                if (!live) {
                  live = el('div', 'wk-notebook-answer wk-notebook-live');
                  out.insertBefore(live, out.firstChild.nextSibling);
                }
                live.textContent += ev.data.text || '';
              } else if (ev.event === 'done') {
                ended = true;
                show(ev.data);
                remember(question, ev.data);
              } else if (ev.event === 'error') {
                ended = true;
                failed(ev.data.message);
              }
            });
            return ended ? undefined : pump();
          });
        }
        return pump();
      }).catch(function () { failed(); })
        .then(function () { button.disabled = false; });
    });
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="notebook-ask"]'), mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
