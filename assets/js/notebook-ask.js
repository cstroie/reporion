/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the notebook (docs/library.md, phase 5; templates/partials/notebook-ask.php) and Ask the
 * library (/library, 2026-11-02): the question goes to the island's endpoint
 * (POST /api/v1/notebook/ask or /api/v1/library/ask) with `stream: true`; the sources chosen are
 * listed as soon as they are known, the answer shows as it is written, and when it is done it is
 * rendered by the server (POST /api/v1/render, render-client.js) in the page's citation style, so
 * its [@key, p. N] become numbered citations and References. Ask the library keeps the last turns
 * in the browser (`history`). Nothing is saved unless the writer chooses, in a notebook (`keep`,
 * 2026-11-11): *Keep* posts the answer to /api/v1/notebook/keep (the kept answers beside the page),
 * *Insert* hands it to the editor (sessionStorage `reporion.editor.insert`, read once by editor.js),
 * which adds it at the end of the page for the writer to save.
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
    var keptLink = box.querySelector('[data-notebook-kept]');
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

    // The question as KeptAnswers::entry() writes it: a heading, and an outline section's questions as a list
    function entry(question) {
      var lines = question.split(/\r?\n/).map(function (l) { return l.replace(/\s+/g, ' ').trim(); }).filter(Boolean);
      var rest = lines.slice(1);
      var listed = rest.length > 0 && !/\?$/.test(lines[0]) && rest.every(function (l) { return /^[A-ZĂÂÎȘŞȚŢ].*\?$/.test(l); });
      var heading = (listed ? lines[0] : lines.join(' ')).replace(/^#+\s*/, '');
      return '## ' + heading + '\n\n' + (listed ? rest.map(function (l) { return '- ' + l; }).join('\n') + '\n\n' : '');
    }

    // Hands text to the editor of this page (editor.js reads it once) and opens it
    function toEditor(text, assisted) {
      try {
        window.sessionStorage.setItem('reporion.editor.insert', JSON.stringify({ path: cfg.path, text: text, assisted: assisted }));
      } catch (e) {
        return false;
      }
      window.location.href = cfg.editHref;
      return true;
    }

    var compose = box.querySelector('[data-notebook-compose]');
    if (compose && cfg.editHref && cfg.path) {
      var composeLabel = compose.lastChild.textContent;
      compose.addEventListener('click', function () {
        if (compose.disabled) return;
        compose.disabled = true;
        compose.lastChild.textContent = s.composing || composeLabel;
        fetch((cfg.basePath || '') + '/api/v1/notebook/compose', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ path: cfg.path })
        }).then(function (r) {
          return r.json().then(function (j) {
            if (!r.ok || !j.data) throw new Error(j.error && j.error.message);
            if (!j.data.text) {
              out.hidden = false;
              out.textContent = '';
              out.appendChild(notice('info', s.compose_empty));
              return;
            }
            toEditor(j.data.text, 'compose');
          });
        }).catch(function (err) {
          out.hidden = false;
          out.textContent = '';
          out.appendChild(notice('error', (err && err.message) || s.failed));
        }).then(function () {
          compose.disabled = false;
          compose.lastChild.textContent = composeLabel;
        });
      });
    } else if (compose) {
      compose.hidden = true;
    }

    // Keep and Insert, for an answer of a notebook
    function actions(question, answer) {
      var bar = el('footer', 'wk-actions');
      var keep = el('button', 'btn btn-ghost btn-sm');
      keep.type = 'button';
      keep.title = s.keep_title || '';
      keep.innerHTML = '<i class="ph ph-bookmark-simple" aria-hidden="true"></i>';
      keep.appendChild(document.createTextNode(s.keep || 'Keep'));
      keep.addEventListener('click', function () {
        if (keep.disabled) return;
        keep.disabled = true;
        keep.lastChild.textContent = s.keeping || '';
        fetch((cfg.basePath || '') + '/api/v1/notebook/keep', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ path: cfg.path, question: question, answer: answer })
        }).then(function (r) {
          return r.json().then(function (j) {
            if (!r.ok || !j.data) throw new Error(j.error && j.error.message);
            var done = el('a', 'btn btn-ghost btn-sm');
            done.href = j.data.href;
            done.innerHTML = '<i class="ph ph-check" aria-hidden="true"></i>';
            done.appendChild(document.createTextNode(s.kept || ''));
            bar.replaceChild(done, keep);
            if (keptLink) {
              keptLink.href = j.data.href;
              keptLink.hidden = false;
            }
          });
        }).catch(function (err) {
          keep.disabled = false;
          keep.lastChild.textContent = s.keep || 'Keep';
          out.appendChild(notice('error', (err && err.message) || s.failed));
        });
      });
      bar.appendChild(keep);
      if (cfg.editHref && window.sessionStorage) {
        var insert = el('button', 'btn btn-ghost btn-sm');
        insert.type = 'button';
        insert.title = s.insert_title || '';
        insert.innerHTML = '<i class="ph ph-note-pencil" aria-hidden="true"></i>';
        insert.appendChild(document.createTextNode(s.insert || ''));
        insert.addEventListener('click', function () {
          toEditor(entry(question) + answer, 'ask');
        });
        bar.appendChild(insert);
      }
      return bar;
    }

    function show(data, question) {
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
      if (cfg.keep && cfg.path && data.answer && question) out.appendChild(actions(question, data.answer));
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
            if (r.ok && j.data) { show(j.data, question); remember(question, j.data); return; }
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
                show(ev.data, question);
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

  /*
   * Studio's outline (2026-11-11): each heading followed by questions gets an Ask button that puts
   * the heading and its questions in this page's Ask box (citation marks left out), opens it and
   * moves to it; the writer asks, then keeps or inserts the answer. Called for a kept outline here,
   * and by notebook-studio.js for one just made.
   */
  function decorate(container) {
    var ask = document.querySelector('[data-island="notebook-ask"]');
    var field = ask && ask.querySelector('textarea[name="question"]');
    if (!container || !field) return;
    var cfgEl = ask.querySelector(':scope > script[type="application/json"]');
    var strings = {};
    try { strings = (JSON.parse(cfgEl.textContent) || {}).strings || {}; } catch (e) { strings = {}; }
    Array.prototype.forEach.call(container.querySelectorAll('h2, h3'), function (heading) {
      if (heading.querySelector('[data-outline-ask]')) return;
      var questions = [];
      for (var next = heading.nextElementSibling; next && !/^H[1-6]$/.test(next.tagName); next = next.nextElementSibling) {
        if (next.tagName !== 'UL' && next.tagName !== 'OL') continue;
        Array.prototype.forEach.call(next.children, function (li) {
          var text = li.textContent.replace(/\[\d+(?:\s*[,–-]\s*\d+)*\]/g, '').replace(/\[@[^\]]*\]/g, '').replace(/\s+/g, ' ').trim();
          if (text.indexOf('?') !== -1) questions.push(text);
        });
      }
      if (questions.length === 0) return;
      var title = heading.textContent.trim();
      var button = el('button', 'btn btn-ghost btn-sm wk-outline-ask');
      button.type = 'button';
      button.setAttribute('data-outline-ask', '');
      button.title = strings.fill_title || '';
      button.innerHTML = '<i class="ph ph-chats-circle" aria-hidden="true"></i>';
      button.appendChild(document.createTextNode(strings.fill || 'Ask'));
      button.addEventListener('click', function () {
        field.value = (title + '\n' + questions.join('\n')).slice(0, 1000);
        field.rows = Math.min(8, questions.length + 2);
        ask.open = true;
        ask.scrollIntoView({ behavior: 'smooth', block: 'start' });
        field.focus();
      });
      heading.appendChild(document.createTextNode(' '));
      heading.appendChild(button);
    });
  }
  window.ReporionNotebookAsk = { decorate: decorate };

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="notebook-ask"]'), mount);
    Array.prototype.forEach.call(document.querySelectorAll('[data-saved-prompt="studio-outline"] .wk-saved-text'), decorate);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
