/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A template's checklist in the Metadata view (roadmap phase 31,
 * templates/partials/editor-details.php): add an item or a section, move a
 * row up or down, remove it; and beside each item, how the editor will
 * treat it against this template's own text — its keywords already there
 * (flagged only if those lines are deleted), not there (flagged on every
 * new report), or no keywords (ticked by hand). The match is the editor's
 * own (Support\Checklist::fold(), assets/js/editor-checklist.js): lower
 * case, no diacritics, runs of spaces as one, plain substrings.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-island="details-checklist"]');
  if (!root) return;
  var list = root.querySelector('[data-cl-rows]');
  var configEl = root.querySelector('[data-cl-config]');
  var s;
  try { s = JSON.parse(configEl.textContent); } catch (e) { return; }
  var max = parseInt(root.getAttribute('data-max'), 10) || 80;
  var count = root.querySelector('[data-cl-count]');
  var body = document.querySelector('textarea[name="body"]');
  var next = 0;

  function fold(text) {
    var t = String(text).toLowerCase()
      .replace(/[șş]/g, 's').replace(/[țţ]/g, 't').replace(/[ăâ]/g, 'a').replace(/î/g, 'i');
    if (t.normalize) t = t.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return t.replace(/\s+/g, ' ').trim();
  }

  function fmt(template, n) { return template.replace('%d', String(n)); }

  function rows() { return Array.prototype.slice.call(list.querySelectorAll('[data-cl-row]')); }

  /** Each item's mark, and the count of lines that will be saved */
  function refresh() {
    var text = body ? fold(body.value) : '';
    var lines = 0;
    rows().forEach(function (row) {
      var label = row.querySelector('input[name$="[label]"]');
      var filled = row.classList.contains('wk-cl-raw') || (label && label.value.trim() !== '');
      if (filled) lines++;
      var kw = row.querySelector('[data-cl-keywords]');
      var mark = row.querySelector('[data-cl-mark]');
      if (!kw || !mark) return;
      var keywords = kw.value.split(',').map(function (k) { return fold(k); }).filter(Boolean);
      if (!filled) { mark.textContent = ''; return; }
      if (keywords.length === 0) {
        mark.textContent = s.byHand;
        row.removeAttribute('data-cl-state');
      } else if (keywords.some(function (k) { return text.indexOf(k) !== -1; })) {
        mark.textContent = s.inText;
        row.setAttribute('data-cl-state', 'in');
      } else {
        mark.textContent = s.notInText;
        row.setAttribute('data-cl-state', 'out');
      }
    });
    count.textContent = fmt(lines > max ? s.over : s.count, lines);
    count.classList.toggle('wk-cl-over', lines > max);
  }

  function add(kind) {
    var tpl = root.querySelector(kind === 'section' ? '[data-cl-blank-section]' : '[data-cl-blank-item]');
    var holder = document.createElement('ol');
    holder.innerHTML = tpl.innerHTML.replace(/__id__/g, 'n' + (next++));
    var row = holder.firstElementChild;
    showTools(row);
    list.appendChild(row);
    var first = row.querySelector('input[type="text"]');
    if (first) first.focus();
    refresh();
  }

  function showTools(row) {
    var tools = row.querySelector('.wk-cl-tools');
    if (tools) tools.hidden = false;
  }

  // The two blank rows are for a browser without JavaScript; here Add makes rows
  rows().forEach(function (row) {
    var label = row.querySelector('input[name$="[label]"]');
    var kw = row.querySelector('[data-cl-keywords]');
    if (/\[b\d+\]\[label\]$/.test(label ? label.name : '') && label.value === '' && (!kw || kw.value === '')) row.remove();
  });
  rows().forEach(showTools);
  root.querySelector('.wk-cl-add').hidden = false;

  root.addEventListener('click', function (ev) {
    var btn = ev.target.closest('button');
    if (!btn || !root.contains(btn)) return;
    var row = btn.closest('[data-cl-row]');
    if (btn.hasAttribute('data-cl-add')) {
      add(btn.getAttribute('data-cl-add'));
    } else if (btn.hasAttribute('data-cl-remove') && row) {
      row.remove();
      refresh();
    } else if (btn.hasAttribute('data-cl-move') && row) {
      var up = btn.getAttribute('data-cl-move') === '-1';
      var other = up ? row.previousElementSibling : row.nextElementSibling;
      if (other) {
        list.insertBefore(row, up ? other : other.nextElementSibling);
        btn.focus();
      }
    } else {
      return;
    }
    ev.preventDefault();
  });

  // Enter in a row's field adds an item after it, instead of submitting the editor
  root.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
    ev.preventDefault();
    add('item');
  });

  var timer = null;
  function later() { clearTimeout(timer); timer = setTimeout(refresh, 300); }
  root.addEventListener('input', later);
  if (body) body.addEventListener('input', later);
  refresh();
})();
