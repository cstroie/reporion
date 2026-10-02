/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's rail as an accordion (2026-10-02): Reference, Checklist,
 * Assistant — one section open at a time. <details name="editor-rail">
 * already does that in current browsers; this repeats it for older ones,
 * remembers the section last opened (this browser only), and lets a
 * [data-rail-open] button (the crumbs line's Reference) open a section and
 * bring it into view.
 */
(function () {
  'use strict';
  var rail = document.getElementById('editor-ai');
  if (!rail) return;
  var sections = Array.prototype.slice.call(rail.querySelectorAll('.wk-rail-sec'));
  var KEY = 'reporion.editor.rail';

  function store(value) {
    try { window.localStorage.setItem(KEY, value); } catch (e) { /* private mode: not remembered */ }
  }

  function open(name, scroll) {
    sections.forEach(function (sec) { sec.open = sec.getAttribute('data-rail') === name; });
    var target = rail.querySelector('[data-rail="' + name + '"]');
    if (target && scroll) target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  var saved = null;
  try { saved = window.localStorage.getItem(KEY); } catch (e) { saved = null; }
  if (saved && rail.querySelector('[data-rail="' + saved + '"]')) open(saved, false);

  sections.forEach(function (sec) {
    sec.addEventListener('toggle', function () {
      if (!sec.open) return;
      sections.forEach(function (other) { if (other !== sec && other.open) other.open = false; });
      store(sec.getAttribute('data-rail'));
    });
  });

  document.querySelectorAll('[data-rail-open]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      open(button.getAttribute('data-rail-open'), true);
    });
  });
}());
