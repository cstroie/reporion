/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Workup (D63): the editor rail's evidence for the report in front. *Gather* sends the text to
 * POST /api/v1/workup (stream: true) and shows, as they come, the four blocks (library, the
 * patient's other studies, similar finished reports, the imaging guide and protocols), each
 * item linked, then the assistant's synthesis. Nothing changes the text without a click: *Insert
 * at cursor* puts the synthesis where the cursor is (one undo step) and marks the save
 * `assisted: workup` (D8); *Copy* copies it. No ghost text (D24).
 */
(function (root) {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function link(href, text) {
    var a = el('a', 'wk-aid-open', text);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener';
    return a;
  }

  /** The server-sent events of a response, one callback per event */
  function readEvents(response, onEvent) {
    var reader = response.body.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    function pump() {
      return reader.read().then(function (r) {
        if (r.done) return;
        buffer += decoder.decode(r.value, { stream: true });
        var parts = buffer.split('\n\n');
        buffer = parts.pop();
        parts.forEach(function (part) {
          var m = /^event: (\S+)\ndata: ([\s\S]*)$/.exec(part.trim());
          if (m) {
            try { onEvent(m[1], JSON.parse(m[2])); } catch (e) { /* a broken line is skipped */ }
          }
        });
        return pump();
      });
    }
    return pump();
  }

  function mount(api) {
    var s = (api.config && api.config.workup) || {};
    var box = document.getElementById('editor-workup');
    if (!box) return;
    var status = document.getElementById('editor-workup-status');
    var out = document.getElementById('editor-workup-out');
    var button = box.querySelector('[data-workup-run]');
    var answer = '';
    var answerBox = null;

    function block(title, items) {
      var sec = el('div', 'wk-workup-block');
      sec.appendChild(el('h4', 'wk-aid-prior-h', title));
      if (items.length === 0) {
        sec.appendChild(el('p', 'wk-dim', s.none));
        return sec;
      }
      var ol = el('ol', 'wk-workup-list');
      items.forEach(function (li) { ol.appendChild(li); });
      sec.appendChild(ol);
      return sec;
    }

    function item(head, text, href, extra) {
      var li = el('li', 'wk-workup-item');
      var top = el('div', 'wk-workup-head');
      top.appendChild(el('strong', '', head));
      if (href) { top.appendChild(document.createTextNode(' ')); top.appendChild(link(href, s.open || 'Open')); }
      li.appendChild(top);
      if (text) li.appendChild(el('p', 'wk-aid-prior-text', text));
      if (extra) li.appendChild(extra);
      return li;
    }

    function showBlocks(b) {
      out.innerHTML = '';
      out.appendChild(block(s.library, b.library.map(function (p) {
        return item((p.key ? '[@' + p.key + '] ' : '') + p.title + (p.page ? ' (p. ' + p.page + ')' : ''), p.text, p.href);
      })));
      var ctx = b.context.studies.map(function (st) {
        return item([st.date, st.exam, st.status === 'archived' ? 'HIS' : ''].filter(Boolean).join(' · '), st.text, st.href);
      });
      var ctxBlock = block(s.context, ctx);
      if (b.context.indication) {
        var ind = el('p', 'wk-aid-prior-text');
        ind.appendChild(el('strong', '', (s.indication || 'Indication') + ': '));
        ind.appendChild(document.createTextNode(b.context.indication));
        ctxBlock.insertBefore(ind, ctxBlock.children[1]);
      }
      out.appendChild(ctxBlock);
      out.appendChild(block(s.similar, b.similar.map(function (r) {
        var extra = r.conclusion ? el('p', 'wk-dim', (s.concluded || 'Concluded') + ': ' + r.conclusion) : null;
        return item([r.date, r.modality, r.mine ? s.mine : ''].filter(Boolean).join(' · '), r.text, r.href, extra);
      })));
      var next = b.next.guide.map(function (g) {
        var table = el('ul', 'wk-workup-guide');
        g.exams.forEach(function (x) {
          var row = el('li', x.this ? 'wk-workup-this' : '');
          row.appendChild(document.createTextNode(x.exam + ': '));
          row.appendChild(el('span', 'wk-guide-ind wk-guide-ind-' + (x.indication_code || 'other'), x.indication));
          if (x.grade) row.appendChild(document.createTextNode(' (' + x.grade + ')'));
          if (x.this) row.appendChild(el('span', 'wk-dim', ' ← ' + (s.thisExam || 'this exam')));
          table.appendChild(row);
        });
        return item(g.chapter + ' · ' + g.title, '', g.href, table);
      }).concat(b.next.protocols.map(function (p) { return item(p.title, p.text, p.href); }));
      out.appendChild(block(s.next, next));
    }

    function showAnswer(text) {
      if (!answerBox) {
        answerBox = el('div', 'wk-workup-block wk-workup-answer');
        answerBox.appendChild(el('h4', 'wk-aid-prior-h', s.synthesis));
        answerBox.appendChild(el('div', 'wk-aid-prior-text wk-workup-text'));
        var row = el('div', 'wk-recall-row');
        var copy = el('button', 'btn btn-ghost btn-sm', s.copy);
        copy.type = 'button';
        copy.addEventListener('click', function () {
          if (navigator.clipboard) navigator.clipboard.writeText(answer).then(function () { status.textContent = s.copied; });
        });
        var insert = el('button', 'btn btn-secondary btn-sm', s.insert);
        insert.type = 'button';
        insert.addEventListener('click', function () {
          var area = api.front();
          if (!area || !answer) return;
          var at = area.selectionStart;
          var text = (at > 0 && area.value.charAt(at - 1) !== '\n' ? '\n\n' : '') + answer.trim() + '\n';
          api.apply({ from: at, to: area.selectionEnd, insert: text, selStart: at + text.length, selEnd: at + text.length }, area);
          if (api.assisted) api.assisted('workup');
        });
        row.appendChild(copy);
        row.appendChild(insert);
        answerBox.appendChild(row);
        out.appendChild(answerBox);
      }
      answerBox.querySelector('.wk-workup-text').textContent = text;
    }

    function run() {
      button.disabled = true;
      out.innerHTML = '';
      answer = '';
      answerBox = null;
      status.textContent = (s.steps && s.steps.library) || '…';
      fetch(api.basePath + '/api/v1/workup', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json' },
        body: JSON.stringify({ path: api.path, text: api.document(), form: api.unsaved ? api.unsaved() : '', stream: true })
      }).then(function (response) {
        if (!response.ok || !response.body) {
          return response.json().then(function (json) {
            status.textContent = (json && json.error && json.error.message) || s.failed;
          }, function () { status.textContent = s.failed; });
        }
        return readEvents(response, function (event, data) {
          if (event === 'progress') status.textContent = (s.steps && s.steps[data.step]) || '';
          else if (event === 'blocks') showBlocks(data.blocks);
          else if (event === 'delta') { answer += data.text || ''; showAnswer(answer); }
          else if (event === 'done') {
            status.textContent = (data.notices || []).join(' ');
            if (data.answer) { answer = data.answer; showAnswer(answer); }
          } else if (event === 'error') status.textContent = data.message || s.failed;
        });
      }).catch(function () { status.textContent = s.failed; }).then(function () { button.disabled = false; });
    }

    button.addEventListener('click', run);
    // The rail's buttons must not take the focus (and the cursor) from the text
    box.addEventListener('mousedown', function (event) { if (event.target.closest('button')) event.preventDefault(); });
  }

  /**
   * Dossier (D64): a patient's folder (PDF or image) sent to POST /api/v1/dossier (stream: true),
   * read page by page on the server (OCR for scans), then summed up by the chosen prompt. The
   * file goes in the request only; nothing is kept. Insert at cursor marks `assisted: dossier`.
   */
  function mountDossier(api) {
    var s = (api.config && api.config.dossier) || {};
    var box = document.getElementById('editor-dossier');
    if (!box) return;
    var status = document.getElementById('editor-dossier-status');
    var out = document.getElementById('editor-dossier-out');
    var button = box.querySelector('[data-dossier-run]');
    var input = box.querySelector('[data-dossier-file]');
    var choice = box.querySelector('[data-dossier-prompt]');
    var answer = '';
    var answerBox = null;

    function showAnswer(text) {
      if (!answerBox) {
        answerBox = el('div', 'wk-workup-block wk-workup-answer');
        answerBox.appendChild(el('h4', 'wk-aid-prior-h', s.title));
        answerBox.appendChild(el('div', 'wk-aid-prior-text wk-workup-text'));
        var row = el('div', 'wk-recall-row');
        var copy = el('button', 'btn btn-ghost btn-sm', s.copy);
        copy.type = 'button';
        copy.addEventListener('click', function () {
          if (navigator.clipboard) navigator.clipboard.writeText(answer).then(function () { status.textContent = s.copied; });
        });
        var insert = el('button', 'btn btn-secondary btn-sm', s.insert);
        insert.type = 'button';
        insert.addEventListener('click', function () {
          var area = api.front();
          if (!area || !answer) return;
          var at = area.selectionStart;
          var text = (at > 0 && area.value.charAt(at - 1) !== '\n' ? '\n\n' : '') + answer.trim() + '\n';
          api.apply({ from: at, to: area.selectionEnd, insert: text, selStart: at + text.length, selEnd: at + text.length }, area);
          if (api.assisted) api.assisted('dossier');
        });
        row.appendChild(copy);
        row.appendChild(insert);
        answerBox.appendChild(row);
        out.appendChild(answerBox);
      }
      answerBox.querySelector('.wk-workup-text').textContent = text;
    }

    function fill(template, values) {
      var i = 0;
      return String(template || '').replace(/%s/g, function () { return values[i++]; });
    }

    function send(file, base64) {
      fetch(api.basePath + '/api/v1/dossier', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json' },
        body: JSON.stringify({ path: api.path, text: api.document(), form: api.unsaved ? api.unsaved() : '', prompt: choice.value, name: file.name, file: base64, stream: true })
      }).then(function (response) {
        if (!response.ok || !response.body) {
          return response.json().then(function (json) {
            status.textContent = (json && json.error && json.error.message) || s.failed;
          }, function () { status.textContent = s.failed; });
        }
        return readEvents(response, function (event, data) {
          if (event === 'progress') {
            if (data.step === 'answering') status.textContent = [s.answering].concat(data.notices || []).join(' ');
            else status.textContent = fill(data.ocr ? s.ocr : s.reading, [data.page, data.pages]);
          } else if (event === 'delta') { answer += data.text || ''; showAnswer(answer); }
          else if (event === 'done') {
            status.textContent = [fill(s.done, [data.pages, data.ocr])].concat(data.notices || []).join(' ');
            if (data.answer) { answer = data.answer; showAnswer(answer); }
          } else if (event === 'error') status.textContent = data.message || s.failed;
        });
      }).catch(function () { status.textContent = s.failed; }).then(function () { button.disabled = false; });
    }

    function run() {
      var file = input.files && input.files[0];
      if (!file) { status.textContent = s.noFile; return; }
      if (s.maxBytes && file.size > s.maxBytes) { status.textContent = s.tooLarge; return; }
      button.disabled = true;
      out.innerHTML = '';
      answer = '';
      answerBox = null;
      status.textContent = fill(s.reading, ['…', '…']);
      var reader = new FileReader();
      reader.onload = function () {
        var url = String(reader.result || '');
        send(file, url.slice(url.indexOf(',') + 1));
      };
      reader.onerror = function () { status.textContent = s.failed; button.disabled = false; };
      reader.readAsDataURL(file);
    }

    button.addEventListener('click', run);
    box.addEventListener('mousedown', function (event) { if (event.target.closest('button')) event.preventDefault(); });
  }

  root.ReporionEditorWorkup = { mount: function (api) { mount(api); mountDossier(api); } };
}(this));
