/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's writing aids (D51): two rail sections beside Recall.
 *
 * - Checks: a pause after typing sends the text in front to POST
 *   /api/v1/checks (Support\WritingChecks: the Sign screen's list); each
 *   item finds its line on a click.
 * - Prior: the report's prior (GET /api/v1/pages/{path}/prior), at the
 *   `###` section the cursor is in; when the cursor's line states a size,
 *   the prior's passage that names the same thing with its size, and
 *   *Insert comparison* adds ", comparativ cu 8 mm la examinarea din
 *   10.03.2025" to the line (execCommand: Ctrl+Z takes it back).
 *
 * Nothing changes the text without a click, nothing takes the focus
 * unless clicked, no ghost text (D24). The pure part: sectionAt(),
 * pairHeading(), pickExam(), measurements() (Support\Measurements' twin),
 * priorFor(), compareEdit(): runs in node (tools/run-editor-aids.js,
 * tests/Render/EditorAidsTest).
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorAids = factory();
  }
}(this, function () {
  'use strict';

  /** After typing stops this long, the text is checked */
  var IDLE_MS = 1500;

  var RO = { 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't', 'ă': 'a', 'â': 'a', 'î': 'i' };
  /** Words too common to say what a size is of */
  var STOP = ['dimensiuni', 'dimensiune', 'diametru', 'masoara', 'aproximativ', 'maxim', 'maxima', 'prezinta', 'nivelul', 'pentru', 'catre', 'dreapta', 'stanga', 'drept', 'stang'];

  function fold(text) {
    var s = String(text).toLowerCase().replace(/[șşțţăâî]/g, function (c) { return RO[c]; });
    if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return s.replace(/\s+/g, ' ').trim();
  }

  /** A heading as compared: folded, no punctuation */
  function headingKey(heading) {
    return fold(heading).replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  /** The `###` heading the position is under, '' when none; a `##` (an exam) ends it */
  function sectionAt(text, pos) {
    var lines = String(text).slice(0, Math.max(0, pos)).split('\n');
    for (var i = lines.length - 1; i >= 0; i--) {
      var m = /^ {0,3}(#{2,6})[ \t]+(.+?)[ \t#]*$/.exec(lines[i]);
      if (m) return m[1].length >= 3 ? m[2].trim() : '';
    }
    return '';
  }

  /**
   * The index of the section a heading pairs with, -1 when none:
   * Support\CompareSections' rule: the same name, folded, else the same
   * first word cut to six letters ("Concluzii" meets "Concluzie")
   */
  function pairHeading(heading, sections) {
    var key = headingKey(heading);
    if (key === '') return -1;
    var i;
    for (i = 0; i < sections.length; i++) {
      if (headingKey(sections[i].heading) === key) return i;
    }
    var first = key.split(' ')[0].slice(0, 6);
    for (i = 0; i < sections.length; i++) {
      if (headingKey(sections[i].heading).split(' ')[0].slice(0, 6) === first) return i;
    }
    return -1;
  }

  /** The prior's exam for the one in front: the same title, else the same place, else the first */
  function pickExam(exams, title, index) {
    if (!exams || exams.length === 0) return -1;
    var key = headingKey(title || '');
    for (var i = 0; key !== '' && i < exams.length; i++) {
      if (headingKey(exams[i].title) === key) return i;
    }
    return index >= 0 && index < exams.length ? index : 0;
  }

  /** Support\Measurements::of(): the sizes written, each with its largest dimension in mm */
  function measurements(text) {
    var re = /(^|[^\p{L}\p{N}.,])(\d+(?:[.,]\d+)?(?:\s*[x×*\/]\s*\d+(?:[.,]\d+)?){0,2})\s*(mm|cm)(?![\p{L}\p{N}])/giu;
    var out = [];
    var m;
    while ((m = re.exec(String(text))) !== null) {
      var unit = m[3].toLowerCase();
      var dims = m[2].split(/[x×*\/]/).map(function (d) { return parseFloat(d.trim().replace(',', '.')); });
      var largest = Math.max.apply(null, dims);
      out.push({ text: m[0].slice(m[1].length).trim(), mm: Math.round((unit === 'cm' ? largest * 10 : largest) * 100) / 100, unit: unit });
    }
    return out;
  }

  /** The words that say what a line is about: folded, four letters or more, common ones left out */
  function terms(text) {
    var words = fold(text).match(/[a-z]{4,}/g) || [];
    return words.filter(function (w, i) { return STOP.indexOf(w) === -1 && words.indexOf(w) === i; });
  }

  /**
   * For a line that states a size, the prior's passage that states one too
   * and shares most words with it (by their first five letters); null when
   * the line has no size or no passage shares a word
   */
  function priorFor(line, sections) {
    if (measurements(line).length === 0) return null;
    var stems = terms(line).map(function (w) { return w.slice(0, 5); });
    if (stems.length === 0) return null;
    var best = null;
    (sections || []).forEach(function (section) {
      (section.passages || []).forEach(function (passage) {
        if (!passage.sizes || passage.sizes.length === 0) return;
        var words = terms(passage.text);
        var score = stems.filter(function (s) { return words.some(function (w) { return w.slice(0, 5) === s; }); }).length;
        if (score > 0 && (best === null || score > best.score)) best = { score: score, text: passage.text, size: passage.sizes[0].text, heading: section.heading };
      });
    });
    return best;
  }

  /** The comparison put at the end of the line around pos, before its full stop */
  function compareEdit(text, pos, phrase) {
    var value = String(text);
    var at = Math.max(0, Math.min(pos, value.length));
    var end = value.indexOf('\n', at);
    if (end === -1) end = value.length;
    var line = value.slice(value.lastIndexOf('\n', at - 1) + 1, end);
    var trimmed = line.replace(/\s+$/, '');
    var stop = /[.;]$/.test(trimmed) ? 1 : 0;
    var where = end - (line.length - trimmed.length) - stop;
    var insert = ', ' + String(phrase).trim();
    return { from: where, to: where, insert: insert, selStart: where + insert.length + stop, selEnd: where + insert.length + stop };
  }

  /** 2025-03-10 → 10.03.2025 */
  function roDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    return m ? m[3] + '.' + m[2] + '.' + m[1] : String(iso || '');
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  /**
   * The page part. api: {basePath, path, config: {strings}, front(): the
   * textarea in front, document(): the whole text, exam(): {index, title}
   * of the exam in front, locate(quote): finds and selects it,
   * apply(edit, target), form: where `reporion:exam` says the exam in
   * front changed}
   */
  function mount(api) {
    var s = (api.config && api.config.strings) || {};
    var pane = document.getElementById('editor-pane');
    if (!pane) return;

    function toggle(id, key) {
      var box = document.getElementById(id);
      if (!box) return null;
      try { if (localStorage.getItem(key) === 'off') box.checked = false; } catch (e) { /* private window */ }
      box.addEventListener('change', function () {
        try { localStorage.setItem(key, box.checked ? 'on' : 'off'); } catch (e) { /* private window */ }
      });
      return box;
    }

    // Checks
    var checkList = document.getElementById('editor-checks-list');
    var checkCount = document.getElementById('editor-checks-count');
    var checkStatus = document.getElementById('editor-checks-status');
    var checkAuto = toggle('editor-checks-auto', 'reporion.checks.auto');
    var checkTimer = null;
    var checkController = null;

    function runChecks() {
      if (!checkList) return;
      if (checkController) checkController.abort();
      checkController = window.AbortController ? new AbortController() : null;
      fetch(api.basePath + '/api/v1/checks', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ path: api.path, text: api.document() }),
        signal: checkController ? checkController.signal : undefined
      }).then(function (r) {
        return r.json().then(function (json) { return { ok: r.ok, json: json }; });
      }).then(function (r) {
        if (!r.ok || !r.json || !r.json.data) { if (checkStatus) checkStatus.textContent = s.checksFailed || ''; return; }
        showChecks(r.json.data);
      }).catch(function (err) {
        if ((!err || err.name !== 'AbortError') && checkStatus) checkStatus.textContent = s.checksFailed || '';
      });
    }

    function showChecks(items) {
      checkList.innerHTML = '';
      if (checkCount) checkCount.textContent = items.length > 0 ? String(items.length) : '';
      if (checkStatus) checkStatus.textContent = items.length > 0 ? '' : (s.checksNone || '');
      items.forEach(function (item) {
        var li = el('li', 'wk-aid-check');
        var button = el('button', 'wk-aid-check-btn');
        button.type = 'button';
        button.appendChild(el('i', 'ph ph-warning'));
        var text = el('span', '', item.message);
        if (item.exam) text.appendChild(el('span', 'wk-dim', ' · ' + item.exam));
        button.appendChild(text);
        if (item.quote) {
          button.title = s.jump || '';
          button.addEventListener('click', function () {
            if (!api.locate(item.quote) && checkStatus) checkStatus.textContent = s.notFound || '';
          });
        } else {
          button.disabled = true;
        }
        li.appendChild(button);
        checkList.appendChild(li);
      });
    }

    // Prior
    var priorBox = document.getElementById('editor-prior');
    var priorHead = document.getElementById('editor-prior-head');
    var priorHint = document.getElementById('editor-prior-hint');
    var priorBody = document.getElementById('editor-prior-body');
    var priorMeta = document.getElementById('editor-prior-meta');
    var priorAuto = toggle('editor-prior-auto', 'reporion.prior.auto');
    var prior = null;
    var priorExam = null;
    var shownKey = null;

    function loadPrior() {
      if (!priorBox) return;
      var exam = api.exam();
      fetch(api.basePath + '/api/v1/pages/' + api.path + '/prior?exam=' + (exam.index + 1), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (json) {
        prior = json && json.data ? json.data : null;
        shownKey = null;
        if (!prior) {
          if (priorMeta) priorMeta.textContent = s.priorNone || '';
          if (priorBody) priorBody.textContent = '';
          return;
        }
        if (priorMeta) {
          priorMeta.innerHTML = '';
          priorMeta.appendChild(document.createTextNode((s.priorOf || '%s').replace('%s', roDate(prior.study_date) + (prior.exam_title ? ' · ' + prior.exam_title : ''))));
          var open = el('a', 'wk-aid-open', s.open || 'Open');
          open.href = api.basePath + prior.href;
          open.target = '_blank';
          open.rel = 'noopener';
          priorMeta.appendChild(document.createTextNode(' '));
          priorMeta.appendChild(open);
        }
        follow();
      }).catch(function () { if (priorMeta) priorMeta.textContent = s.priorFailed || ''; });
    }

    /** The prior's section and size for where the cursor is now */
    function follow() {
      if (!prior || !priorBody || (priorAuto && !priorAuto.checked)) return;
      var area = api.front();
      if (!area) return;
      var exam = api.exam();
      var e = pickExam(prior.exams, exam.title, exam.index);
      priorExam = e >= 0 ? prior.exams[e] : null;
      if (!priorExam) return;
      var heading = sectionAt(area.value, area.selectionStart);
      var i = pairHeading(heading, priorExam.sections);
      var key = e + '|' + i;
      if (key !== shownKey) {
        shownKey = key;
        priorBody.innerHTML = '';
        var sections = i >= 0 ? [priorExam.sections[i]] : priorExam.sections;
        sections.forEach(function (section) {
          if (section.heading) priorBody.appendChild(el('h4', 'wk-aid-prior-h', section.heading));
          priorBody.appendChild(el('p', 'wk-aid-prior-text', section.text));
        });
        if (priorHead) priorHead.textContent = i >= 0 ? priorExam.sections[i].heading : (s.priorWhole || '');
      }
      hint(area);
    }

    function hint(area) {
      if (!priorHint) return;
      var value = area.value;
      var pos = area.selectionStart;
      var from = value.lastIndexOf('\n', pos - 1) + 1;
      var to = value.indexOf('\n', pos);
      var line = value.slice(from, to === -1 ? value.length : to);
      var match = priorFor(line, priorExam ? priorExam.sections : []);
      priorHint.innerHTML = '';
      priorHint.hidden = match === null;
      if (!match) return;
      priorHint.appendChild(el('span', 'wk-eyebrow', (s.priorSize || '%s').replace('%s', roDate(prior.study_date))));
      priorHint.appendChild(el('p', 'wk-aid-prior-text', match.text));
      var insert = el('button', 'btn btn-secondary btn-sm');
      insert.type = 'button';
      insert.appendChild(el('i', 'ph ph-arrow-line-down'));
      insert.appendChild(document.createTextNode(' ' + (s.compare || 'Insert comparison')));
      insert.addEventListener('click', function () {
        var phrase = (s.comparePhrase || 'comparativ cu %1$s la examinarea din %2$s').replace('%1$s', match.size).replace('%2$s', roDate(prior.study_date));
        api.apply(compareEdit(area.value, area.selectionStart, phrase), area);
      });
      priorHint.appendChild(insert);
    }

    pane.addEventListener('input', function () {
      clearTimeout(checkTimer);
      if (!checkAuto || checkAuto.checked) checkTimer = setTimeout(runChecks, IDLE_MS);
    });
    // The cursor moved: the prior follows it (keys, clicks, a new tab)
    ['keyup', 'click', 'focusin'].forEach(function (type) {
      pane.addEventListener(type, function () { follow(); });
    });
    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-checks-now]')) runChecks();
    });
    // Another exam in front: its own prior (its region), from the top
    if (api.form) api.form.addEventListener('reporion:exam', function () { loadPrior(); });
    // The rail's buttons must not take the focus (and the cursor's line) from the text
    ['editor-checks', 'editor-prior'].forEach(function (id) {
      var box = document.getElementById(id);
      if (box) box.addEventListener('mousedown', function (event) { if (event.target.closest('button')) event.preventDefault(); });
    });

    runChecks();
    loadPrior();
  }

  return {
    fold: fold, sectionAt: sectionAt, pairHeading: pairHeading, pickExam: pickExam, measurements: measurements,
    priorFor: priorFor, compareEdit: compareEdit, roDate: roDate, mount: mount, IDLE_MS: IDLE_MS
  };
}));
