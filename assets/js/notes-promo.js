/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The start page's card for the notes app (D55): shown until its reader
 * hides it, and not at all in a browser that already uses the app (the app
 * keeps its account under `reporion.notes.user`, the same origin) or runs
 * it installed. Both are this browser's own memory, kept in localStorage:
 * without it, or without JavaScript, the card stays hidden.
 */
(function () {
  'use strict';

  var KEY = 'reporion.notes.promo';
  var box = document.querySelector('[data-notes-promo]');
  var script = document.currentScript;
  if (!box) return;

  var seen;
  try { seen = localStorage.getItem(KEY) === 'hidden' || localStorage.getItem('reporion.notes.user') !== null; } catch (e) { return; }
  if (seen) return;

  var card = box.querySelector('.wk-notice');
  var hide = document.createElement('button');
  hide.type = 'button';
  hide.className = 'btn btn-ghost btn-sm wk-notes-promo-x';
  hide.textContent = (script && script.getAttribute('data-dismiss')) || 'Hide';
  hide.addEventListener('click', function () {
    try { localStorage.setItem(KEY, 'hidden'); } catch (e) { /* hidden for this visit */ }
    box.hidden = true;
  });
  card.appendChild(hide);
  box.hidden = false;
})();
