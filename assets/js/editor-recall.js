/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Recall while writing (D50): the rail's Recall section. When the doctor
 * pauses on a line ("multiple leziuni de substanță albă"), POST
 * /api/v1/recall brings the passages of finished reports that say the
 * same at length (their own first) and what several of them say that the
 * text does not yet (efect de masă, FLAIR): a reminder. Nothing goes into
 * the text without a click: Insert puts a passage on the line after, with
 * execCommand so Ctrl+Z takes it back. No ghost text in the textarea:
 * external dictation types there (D24). The panel never takes the focus.
 *
 * The pure part: lineAt(), askable(), insertAfter(), replaceLine(): runs
 * in node (tools/run-editor-recall.js, tests/Render/EditorRecallTest).
 * editor.js mounts the page part with what only it knows: the textarea in
 * front, its applyEdit(), the assistant.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorRecall = factory();
  }
}(this, function () {
  'use strict';

  /** After typing stops this long on a line, it is recalled */
  var IDLE_MS = 1500;

  var RO = { 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't', 'ă': 'a', 'â': 'a', 'î': 'i' };
  var SHORT = ['t1', 't2', 'pd', 'dwi', 'adc', 'swi', 'flair', 'stir', 'tof', 'gre'];

  function fold(text) {
    var s = String(text).toLowerCase().replace(/[șşțţăâî]/g, function (c) { return RO[c]; });
    if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return s.replace(/\s+/g, ' ').trim();
  }

  /** The line around pos: its text and where it starts and ends */
  function lineAt(text, pos) {
    var value = String(text);
    var at = Math.max(0, Math.min(pos, value.length));
    var from = value.lastIndexOf('\n', at - 1) + 1;
    var to = value.indexOf('\n', at);
    if (to === -1) to = value.length;
    return { text: value.slice(from, to), from: from, to: to };
  }

  /**
   * Whether a line is worth asking about: not a heading, a rule, a
   * snippet trigger or the frontmatter's; two words that say something
   * (four letters or more, or T2, FLAIR…), or one of six letters or more.
   * The server has the last word (Support\RecallTerms::ofLine()).
   */
  function askable(line) {
    var bare = String(line).replace(/^\s*(?:[-*+]|\d+[.)])\s+/, '').trim();
    if (bare === '' || /^(#|---|;|\||```|~~~)/.test(bare) || /^[\w-]+:\s/.test(bare)) return false;
    var words = fold(bare).match(/[a-z0-9]+/g) || [];
    var meaningful = words.filter(function (w) { return SHORT.indexOf(w) !== -1 || /^[a-z]{4,}$/.test(w); });
    if (meaningful.length >= 2) return true;
    return meaningful.length === 1 && meaningful[0].length >= 6;
  }

  /**
   * A passage put on a new line after the line around pos: a list item
   * when that line is one (the same marker), the cursor at its end.
   */
  function insertAfter(text, pos, passage) {
    var line = lineAt(text, pos);
    var marker = /^(\s*[-*+]\s+)/.exec(line.text);
    var insert = '\n' + (marker ? marker[1] : '') + String(passage).trim();
    return { from: line.to, to: line.to, insert: insert, selStart: line.to + insert.length, selEnd: line.to + insert.length };
  }

  /** The line around pos replaced (Expand), its list marker kept */
  function replaceLine(text, pos, replacement) {
    var line = lineAt(text, pos);
    var marker = /^(\s*[-*+]\s+)/.exec(line.text);
    var insert = (marker ? marker[1] : '') + String(replacement).trim().replace(/^\s*[-*+]\s+/, '');
    return { from: line.from, to: line.to, insert: insert, selStart: line.from + insert.length, selEnd: line.from + insert.length };
  }

  /**
   * The page part. api: {basePath, path, config: {strings, expand, descriptors},
   * front(): the textarea in front, written(): the text in front,
   * apply(edit, target), ask(actionId, text, onDelta): Promise<string>,
   * assisted(actionId)}
   */
  function mount(api) {
    var box = document.getElementById('editor-recall');
    var list = document.getElementById('editor-recall-list');
    var missing = document.getElementById('editor-recall-missing');
    var chips = document.getElementById('editor-recall-chips');
    var status = document.getElementById('editor-recall-status');
    var count = document.getElementById('editor-recall-count');
    var auto = document.getElementById('editor-recall-auto');
    var pane = document.getElementById('editor-pane');
    var aiBox = document.getElementById('editor-recall-ai');
    var aiOut = document.getElementById('editor-recall-ai-out');
    var aiRow = document.getElementById('editor-recall-ai-row');
    if (!box || !list || !pane) return;
    var s = api.config.strings || {};
    var key = 'reporion.recall.auto';
    try { if (auto && localStorage.getItem(key) === 'off') auto.checked = false; } catch (e) { /* private window */ }

    var timer = null;
    var last = '';
    var controller = null;
    // The line the panel answers for: where Insert and Expand act
    var asked = null;

    function say(text) { if (status) status.textContent = text; }

    function clear() {
      list.innerHTML = '';
      if (chips) chips.innerHTML = '';
      if (missing) missing.hidden = true;
      if (count) count.textContent = '';
      if (aiBox) aiBox.hidden = true;
    }

    function recall(force) {
      var area = api.front();
      if (!area) return;
      var line = lineAt(area.value, area.selectionStart);
      if (!askable(line.text)) {
        if (force) say(s.tooShort || '');
        return;
      }
      if (!force && line.text.trim() === last) return;
      last = line.text.trim();
      asked = { area: area, text: line.text, pos: area.selectionStart };
      if (controller) controller.abort();
      controller = window.AbortController ? new AbortController() : null;
      say(s.searching || '');
      fetch(api.basePath + '/api/v1/recall', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ path: api.path, text: line.text, written: api.written(), form: api.form ? api.form() : '' }),
        signal: controller ? controller.signal : undefined
      }).then(function (r) {
        return r.json().then(function (json) { return { ok: r.ok, json: json }; });
      }).then(function (r) {
        if (!r.ok || !r.json || !r.json.data) { say(s.failed || ''); return; }
        show(r.json.data);
      }).catch(function (err) {
        if (!err || err.name !== 'AbortError') say(s.failed || '');
      });
    }

    function show(data) {
      clear();
      var passages = data.passages || [];
      if (count) count.textContent = passages.length > 0 ? String(passages.length) : '';
      say(passages.length > 0 ? (s.forLine || '').replace('%s', last) : (s.none || ''));
      passages.forEach(function (p) {
        var li = document.createElement('li');
        li.className = 'wk-recall-item';
        var meta = document.createElement('div');
        meta.className = 'wk-recall-meta wk-mono wk-dim';
        meta.textContent = [p.study_date ? String(p.study_date).slice(0, 10) : '', p.modality || '', p.heading || ''].filter(Boolean).join(' · ');
        if (p.mine) {
          var mine = document.createElement('span');
          mine.className = 'wk-chip wk-chip-on wk-recall-mine';
          mine.textContent = s.mine || 'mine';
          meta.insertBefore(mine, meta.firstChild);
        }
        var text = document.createElement('p');
        text.className = 'wk-recall-text';
        // The server escaped it and marked the words: Support\RecallTerms::highlight()
        text.innerHTML = p.html;
        var row = document.createElement('div');
        row.className = 'wk-recall-row';
        var insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'btn btn-secondary btn-sm';
        insert.innerHTML = '<i class="ph ph-arrow-line-down"></i> ';
        insert.appendChild(document.createTextNode(s.insert || 'Insert'));
        insert.addEventListener('click', function () { put(p.text); });
        var open = document.createElement('a');
        open.className = 'btn btn-secondary btn-sm';
        open.href = api.basePath + p.href;
        open.target = '_blank';
        open.rel = 'noopener';
        open.innerHTML = '<i class="ph ph-arrow-square-out"></i> ';
        open.appendChild(document.createTextNode(s.open || 'Open'));
        row.appendChild(insert);
        row.appendChild(open);
        li.appendChild(meta);
        li.appendChild(text);
        li.appendChild(row);
        list.appendChild(li);
      });
      var gaps = data.missing || [];
      if (missing && chips && gaps.length > 0) {
        gaps.forEach(function (word) {
          var chip = document.createElement('span');
          chip.className = 'wk-chip';
          chip.textContent = word;
          chips.appendChild(chip);
        });
        missing.hidden = false;
      }
      if (aiBox && passages.length > 0 && (api.config.expand || api.config.descriptors)) {
        aiBox.hidden = false;
        if (aiOut) { aiOut.hidden = true; aiOut.textContent = ''; }
        if (aiRow) aiRow.innerHTML = '';
      }
    }

    /** The line asked about, where it is now: null when it is gone */
    function where() {
      if (!asked) return null;
      var area = asked.area;
      var line = lineAt(area.value, Math.min(asked.pos, area.value.length));
      if (line.text === asked.text) return { area: area, pos: line.from };
      var at = area.value.indexOf(asked.text);
      return at === -1 ? null : { area: area, pos: at };
    }

    function put(passage) {
      var spot = where();
      if (!spot) { say(s.lineGone || ''); return; }
      api.apply(insertAfter(spot.area.value, spot.pos, passage), spot.area);
      last = '';
    }

    function run(action, button) {
      var spot = where();
      if (!spot || !api.ask) { say(s.lineGone || ''); return; }
      var line = asked.text;
      button.setAttribute('aria-busy', 'true');
      aiOut.hidden = false;
      aiOut.textContent = s.asking || '';
      aiRow.innerHTML = '';
      api.ask(action, line, function (sofar) { aiOut.textContent = sofar; }).then(function (answer) {
        button.removeAttribute('aria-busy');
        var text = String(answer || '').trim();
        if (text === '') { aiOut.textContent = s.aiFailed || ''; return; }
        aiOut.textContent = text;
        if (action !== 'recall-expand') return;
        var apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'btn btn-primary btn-sm';
        apply.textContent = s.replaceLine || 'Replace line';
        apply.addEventListener('click', function () {
          var now = where();
          if (!now) { say(s.lineGone || ''); return; }
          api.apply(replaceLine(now.area.value, now.pos, text), now.area);
          if (api.assisted) api.assisted('recall-expand');
          asked = null;
          last = '';
          aiRow.innerHTML = '';
        });
        aiRow.appendChild(apply);
      }, function (message) {
        button.removeAttribute('aria-busy');
        aiOut.textContent = message || s.aiFailed || '';
      });
    }

    pane.addEventListener('input', function () {
      clearTimeout(timer);
      if (!auto || auto.checked) timer = setTimeout(function () { recall(false); }, IDLE_MS);
    });
    pane.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && (event.key === ' ' || event.code === 'Space')) {
        event.preventDefault();
        clearTimeout(timer);
        recall(true);
      }
    });
    if (auto) {
      auto.addEventListener('change', function () {
        try { localStorage.setItem(key, auto.checked ? 'on' : 'off'); } catch (e) { /* private window */ }
      });
    }
    box.addEventListener('click', function (event) {
      var now = event.target.closest('[data-recall-now]');
      if (now) { recall(true); return; }
      var ai = event.target.closest('[data-recall-ai]');
      if (ai && ai.getAttribute('aria-busy') !== 'true') run(ai.getAttribute('data-recall-ai'), ai);
    });
    // The panel's buttons must not take the focus (and the cursor's line) from the text
    box.addEventListener('mousedown', function (event) {
      if (event.target.closest('button')) event.preventDefault();
    });
  }

  return { fold: fold, lineAt: lineAt, askable: askable, insertAfter: insertAfter, replaceLine: replaceLine, mount: mount, IDLE_MS: IDLE_MS };
}));
