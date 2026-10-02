/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's checklists (roadmap phase 26): each exam's template list in
 * the rail. The pure part — fold(), mentioned(), examTexts() — is the same
 * rule as Support\Checklist and Support\Exams, run in node by
 * tests/Render/EditorChecklistTest (tools/run-editor-checklist.js). The
 * page part marks an item "not mentioned" while none of its keywords is in
 * its exam's text, and keeps the ticks in this browser only — never sent,
 * never saved (D18: the prose is the report). They belong to the revision
 * being edited: once a save has made a newer one, they start over.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorChecklist = factory();
  }
}(this, function () {
  'use strict';

  var FENCE = /^ {0,3}(`{3,}|~{3,})/;
  var BOUNDARY = /^ {0,3}##(?:[ \t]|$)/;
  var RO = { 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't', 'ă': 'a', 'â': 'a', 'î': 'i' };

  /** Lower case, no diacritics, single spaces — Support\Checklist::fold() */
  function fold(text) {
    var s = String(text).toLowerCase().replace(/[șşțţăâî]/g, function (c) { return RO[c]; });
    if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return s.replace(/\s+/g, ' ').trim();
  }

  /** true / false when the item has keywords, null when it has none */
  function mentioned(keywords, text) {
    if (!keywords || keywords.length === 0) return null;
    var hay = fold(text);
    for (var i = 0; i < keywords.length; i++) {
      var needle = fold(keywords[i]);
      if (needle !== '' && hay.indexOf(needle) !== -1) return true;
    }
    return false;
  }

  /**
   * The text of each exam: the body cut at its `##` lines outside fenced
   * code (Support\Exams::split()); a body without any is one exam. A
   * leading frontmatter block is left out.
   */
  function examTexts(text) {
    var body = String(text);
    if (body.slice(0, 4) === '---\n') {
      var close = body.indexOf('\n---\n', 3);
      if (close !== -1) body = body.slice(close + 5);
    }
    var lines = body.match(/[^\n]*\n|[^\n]+$/g) || [];
    var parts = [];
    var fence = null;
    lines.forEach(function (line) {
      var bare = line.replace(/\r?\n$/, '');
      var m;
      if (fence !== null) {
        m = bare.match(FENCE);
        if (m && m[1].charAt(0) === fence.charAt(0) && m[1].length >= fence.length && bare.trim().slice(m[1].length).trim() === '') fence = null;
      } else if ((m = bare.match(FENCE))) {
        fence = m[1];
      } else if (BOUNDARY.test(bare)) {
        parts.push('');
      }
      if (parts.length > 0) parts[parts.length - 1] += line;
    });
    return parts.length > 0 ? parts : [body];
  }

  function init() {
    var box = document.getElementById('editor-checklist');
    var pane = document.getElementById('editor-pane');
    if (!box || !pane) return;
    var cfgEl = document.getElementById('editor-config');
    var cfg = {};
    try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}') || {}; } catch (e) { cfg = {}; }
    var key = 'reporion.checklist.' + (cfg.path || location.pathname);
    var count = document.getElementById('editor-check-count');

    var rev = cfg.baseRev || 0;
    var ticks = {};
    try {
      var kept = JSON.parse(localStorage.getItem(key) || 'null');
      if (kept && kept.rev === rev && kept.ticks) ticks = kept.ticks;
    } catch (e) { ticks = {}; }
    var boxes = box.querySelectorAll('input[data-check]');
    Array.prototype.forEach.call(boxes, function (input) {
      input.checked = ticks[input.getAttribute('data-check')] === true;
      input.addEventListener('change', function () {
        ticks[input.getAttribute('data-check')] = input.checked;
        try { localStorage.setItem(key, JSON.stringify({ rev: rev, ticks: ticks })); } catch (e) { /* private window: ticks just do not last */ }
        tally();
      });
    });

    /** The text being edited: the exam panes when the tabs split it, else the one textarea */
    function currentText() {
      var areas = pane.querySelectorAll('textarea');
      var panes = Array.prototype.filter.call(areas, function (a) { return !a.hasAttribute('name'); });
      var use = panes.length > 0 ? panes : Array.prototype.filter.call(areas, function (a) { return a.hasAttribute('name'); });
      return use.map(function (a) { return a.value; }).join('');
    }

    function tally() {
      var done = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
      if (count) count.textContent = done + ' / ' + boxes.length;
    }

    function check() {
      var texts = examTexts(currentText());
      Array.prototype.forEach.call(box.querySelectorAll('.wk-check-exam'), function (exam) {
        var n = parseInt(exam.getAttribute('data-exam'), 10) || 0;
        var text = texts.length === 1 ? texts[0] : (texts[n] || '');
        Array.prototype.forEach.call(exam.querySelectorAll('.wk-check-item'), function (item) {
          var keywords = [];
          try { keywords = JSON.parse(item.getAttribute('data-keywords') || '[]'); } catch (e) { keywords = []; }
          var seen = mentioned(keywords, text);
          var miss = item.querySelector('.wk-check-miss');
          item.classList.toggle('is-missing', seen === false);
          if (miss) miss.hidden = seen !== false;
        });
      });
      tally();
    }

    var timer = null;
    pane.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(check, 300);
    });
    check();
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  }

  return { fold: fold, mentioned: mentioned, examTexts: examTexts };
}));
