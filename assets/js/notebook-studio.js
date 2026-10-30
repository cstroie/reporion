/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Studio (docs/library.md, phase 6; templates/partials/notebook-studio.php): one button per text
 * the assistant can make from the notebook and its sources. POST /api/v1/notebook/studio as
 * server-sent events (progress "step k of n", then done or error); the result is rendered by the
 * server (POST /api/v1/render, render-client.js) in the page's citation style, with Copy. Nothing
 * is saved. A template is shown with its checklist and the shape warnings (never a block, D7); the
 * writer names its page under templates: and makes it (POST /api/v1/pages, `reference` this
 * notebook, the note `assisted: studio-template`, D8), then edits it in the editor.
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
    var i = el('i', 'ph ph-' + (kind === 'error' ? 'warning-octagon' : (kind === 'warning' ? 'warning' : 'info')));
    i.setAttribute('aria-hidden', 'true');
    card.appendChild(i);
    card.appendChild(el('div', '', text));
    return card;
  }

  function copyButton(label, done, text) {
    var copy = el('button', 'btn btn-secondary btn-sm', label);
    copy.type = 'button';
    copy.addEventListener('click', function () {
      var ok = function () { copy.textContent = done; };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok);
      else { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); ok(); } catch (e) { /* the text is on the screen */ } t.remove(); }
    });
    return copy;
  }

  // A template's page: under templates:, not snippets or readable page templates
  function templatePath(value) {
    var path = value.trim().toLowerCase().replace(/^:+|:+$/g, '');
    if (!/^templates(:[a-z0-9][a-z0-9_.-]*)+$/.test(path)) return null;
    if (/^templates:(snippets|pages)(:|$)/.test(path)) return null;
    return path;
  }

  function mount(box) {
    var cfgEl = box.querySelector(':scope > script[type="application/json"]');
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var base = cfg.basePath || '';
    var buttons = Array.prototype.slice.call(box.querySelectorAll('[data-studio-task], [data-studio-compare]'));
    var out = box.querySelector('[data-studio-out]');
    var questionsBox = box.querySelector('[data-studio-questions]');
    var running = false;

    function busy(on) {
      running = on;
      buttons.forEach(function (b) { b.disabled = on; });
    }

    function rendered(markdown, cls) {
      var body = el('div', cls + ' wk-prose');
      var extra = { citation_style: cfg.citationStyle || '', language: cfg.language || '' };
      if (window.ReporionRender) {
        window.ReporionRender.html(base, markdown, false, false, extra)
          .then(function (html) { body.innerHTML = html; }, function () { body.textContent = markdown; });
      } else {
        body.textContent = markdown;
      }
      return body;
    }

    function templateForm(data) {
      var tpl = data.template;
      var wrap = el('div', 'wk-studio-template');
      if (tpl.checklist && tpl.checklist.length) {
        var list = el('details', 'wk-notebook-sources');
        list.appendChild(el('summary', 'wk-dim wk-text-sm', (s.tpl_checklist || '%s').replace('%s', tpl.checklist.length)));
        var pre = el('pre', 'wk-text-sm', tpl.checklist.join('\n'));
        list.appendChild(pre);
        wrap.appendChild(list);
      }
      if (tpl.problems && tpl.problems.length) {
        wrap.appendChild(notice('warning', s.tpl_problems + ' ' + tpl.problems.join('; ')));
      }
      var field = el('div', 'field');
      var label = el('label', '', s.tpl_path);
      var input = el('input', 'input');
      input.type = 'text';
      input.value = cfg.templatePath || 'templates:';
      input.spellcheck = false;
      input.setAttribute('autocapitalize', 'off');
      label.appendChild(input);
      label.appendChild(el('small', '', s.tpl_path_help));
      field.appendChild(label);
      wrap.appendChild(field);
      var status = el('div');
      var row = el('div', 'wk-actions');
      var create = el('button', 'btn btn-primary btn-sm', s.tpl_create);
      create.type = 'button';
      create.addEventListener('click', function () {
        var path = templatePath(input.value);
        status.textContent = '';
        if (!path) { status.appendChild(notice('error', s.tpl_bad_path)); return; }
        create.disabled = true;
        var meta = { visibility: 'private', reference: tpl.reference };
        if (tpl.checklist && tpl.checklist.length) meta.checklist = tpl.checklist;
        fetch(base + '/api/v1/pages', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ path: path, meta: meta, body: tpl.body + '\n', note: 'assisted: studio-template' })
        }).then(function (r) {
          if (r.status === 201) { window.location.href = base + '/' + path + '/edit'; return; }
          create.disabled = false;
          return r.json().then(function (j) {
            status.appendChild(notice('error', r.status === 409 ? s.tpl_exists : (r.status === 404 ? s.tpl_no_grant : ((j && j.error && j.error.message) || s.failed))));
          }, function () { status.appendChild(notice('error', s.failed)); });
        }, function () { create.disabled = false; status.appendChild(notice('error', s.failed)); });
      });
      row.appendChild(create);
      wrap.appendChild(row);
      wrap.appendChild(status);
      wrap.appendChild(el('p', 'wk-dim wk-text-xs', s.tpl_note));
      return wrap;
    }

    function show(data) {
      out.textContent = '';
      (data.notices || []).forEach(function (n) { out.appendChild(notice('info', n)); });
      if (!data.result) return;
      var markdown = data.template ? data.template.body : data.result;
      out.appendChild(rendered(markdown, 'wk-notebook-answer'));
      if (data.dropped > 0) out.appendChild(notice('info', (s.dropped || '%s').replace('%s', data.dropped)));
      if (data.saved) {
        var kept = el('p', 'wk-dim wk-text-xs');
        var parts = (s.saved || '%s').split('%s');
        kept.appendChild(document.createTextNode(parts[0]));
        var link = el('a', '', data.saved.path);
        link.href = data.saved.href;
        kept.appendChild(link);
        kept.appendChild(document.createTextNode(parts[1] || ''));
        out.appendChild(kept);
      }
      var row = el('div', 'wk-actions');
      row.appendChild(copyButton(s.copy, s.copied, data.result));
      out.appendChild(row);
      if (data.template) out.appendChild(templateForm(data));
      var read = el('details', 'wk-notebook-sources');
      read.appendChild(el('summary', 'wk-dim wk-text-sm', (s.sources || '') + ' (' + (data.sources || []).length + ') · ' + (data.whole ? s.whole : s.condensed)));
      var list = el('ol', 'wk-text-sm');
      (data.sources || []).forEach(function (src) {
        var li = el('li');
        var a = el('a', '', src.title + ' [@' + src.key + ']');
        a.href = src.href;
        li.appendChild(a);
        list.appendChild(li);
      });
      read.appendChild(list);
      out.appendChild(read);
      var meta = [data.provider || '', ((data.ms || 0) / 1000).toFixed(1) + ' s'].concat(data.requests > 1 ? [data.requests + ' ×'] : []).filter(Boolean).join(' · ');
      out.appendChild(el('p', 'wk-dim wk-text-xs', s.note + (meta ? ' · ' + meta : '')));
    }

    function fail(message) {
      out.textContent = '';
      out.appendChild(notice('error', message || s.failed));
    }

    function run(task, questions) {
      if (running) return;
      busy(true);
      out.hidden = false;
      out.textContent = '';
      out.appendChild(notice('info', s.working));
      var payload = { path: cfg.path, task: task, stream: true };
      if (questions) payload.questions = questions;
      fetch(base + '/api/v1/notebook/studio', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
        body: JSON.stringify(payload)
      }).then(function (response) {
        if (!response.ok || !response.body) {
          return response.json().then(function (j) { fail(j && j.error && j.error.message); }, function () { fail(); });
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var finished = false;
        function handle(block) {
          var m = /^event: (\w+)\ndata: (.*)$/m.exec(block);
          if (!m) return;
          var data;
          try { data = JSON.parse(m[2]); } catch (e) { return; }
          if (m[1] === 'progress') {
            out.textContent = '';
            out.appendChild(notice('info', data.steps > 1 ? (s.progress || '%s / %s').replace('%s', data.step).replace('%s', data.steps) : s.working));
          } else if (m[1] === 'done') {
            finished = true;
            show(data);
          } else if (m[1] === 'error') {
            finished = true;
            fail(data.message);
          }
        }
        function pump() {
          return reader.read().then(function (chunk) {
            if (chunk.done) { if (!finished) fail(); return; }
            buffer += decoder.decode(chunk.value, { stream: true });
            var blocks = buffer.split('\n\n');
            buffer = blocks.pop();
            blocks.forEach(handle);
            return pump();
          });
        }
        return pump();
      }).catch(function () { fail(); }).then(function () { busy(false); });
    }

    Array.prototype.forEach.call(box.querySelectorAll('[data-studio-task]'), function (b) {
      b.addEventListener('click', function () {
        var task = b.getAttribute('data-studio-task');
        // The comparison asks for its columns first
        if (task === 'compare' && questionsBox) {
          questionsBox.hidden = false;
          questionsBox.querySelector('textarea').focus();
          return;
        }
        if (questionsBox) questionsBox.hidden = true;
        run(task);
      });
    });
    var compare = box.querySelector('[data-studio-compare]');
    if (compare) {
      compare.addEventListener('click', function () {
        var lines = questionsBox.querySelector('textarea').value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
        if (!lines.length) { out.hidden = false; out.textContent = ''; out.appendChild(notice('error', s.questions_needed)); return; }
        run('compare', lines);
      });
    }
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="notebook-studio"]'), mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
