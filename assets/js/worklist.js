/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page view's left panel (templates/partials/worklist.php): the search
 * box, the modality / last 30 days / mine chips and the sort button only hide
 * or reorder the rows already in the page. Nothing is fetched. Without this
 * script the panel is a plain list of links. It opens filtered to the open
 * page's modality and the last 30 days, newest first.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-island="worklist"]');
  if (!root) return;

  var holder = root.querySelector('[data-wl-rows]');
  var rows = Array.prototype.slice.call(root.querySelectorAll('[data-wl-row]'));
  var box = root.querySelector('[data-wl-q]');
  var chips = root.querySelector('[data-wl-chips]');
  var sortBtn = root.querySelector('[data-wl-sort]');
  var empty = root.querySelector('[data-wl-empty]');
  var me = root.getAttribute('data-me') || '';
  var original = rows.slice();
  var DAY = 86400000;

  var state = { q: '', mods: {}, recent: false, mine: false, byTitle: false };

  chips.hidden = false;

  function visible(row) {
    if (row.classList.contains('wk-sel')) return true; // the open page always stays in its list
    if (state.q) {
      var hay = (row.getAttribute('data-title') + ' ' + row.textContent).toLowerCase();
      if (state.q.split(/\s+/).some(function (word) { return word && hay.indexOf(word) === -1; })) return false;
    }
    var picked = Object.keys(state.mods);
    if (picked.length) {
      var have = (row.getAttribute('data-mod') || '').split(',');
      if (!picked.some(function (mod) { return have.indexOf(mod) !== -1; })) return false;
    }
    if (state.recent) {
      var when = Date.parse(row.getAttribute('data-day'));
      if (isNaN(when) || Date.now() - when > 30 * DAY) return false;
    }
    if (state.mine && row.getAttribute('data-by') !== me) return false;
    return true;
  }

  function apply() {
    var shown = 0;
    var ordered = state.byTitle
      ? original.slice().sort(function (a, b) { return a.getAttribute('data-title').localeCompare(b.getAttribute('data-title')); })
      : original;
    ordered.forEach(function (row) {
      holder.appendChild(row);
      var ok = visible(row);
      row.hidden = !ok;
      if (ok) shown++;
    });
    empty.hidden = shown !== 0;
  }

  function toggle(btn, on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.classList.toggle('wk-chip-on', on);
  }

  box.addEventListener('input', function () {
    state.q = box.value.trim().toLowerCase();
    apply();
  });

  chips.addEventListener('click', function (event) {
    var btn = event.target.closest('button');
    if (!btn) return;
    var on = btn.getAttribute('aria-pressed') !== 'true';
    toggle(btn, on);
    if (btn.hasAttribute('data-wl-mod')) {
      var mod = btn.getAttribute('data-wl-mod').toUpperCase().replace(/\s+/g, '');
      if (on) state.mods[mod] = true; else delete state.mods[mod];
    } else if (btn.hasAttribute('data-wl-recent')) {
      state.recent = on;
    } else if (btn.hasAttribute('data-wl-mine')) {
      state.mine = on;
    }
    apply();
  });

  sortBtn.addEventListener('click', function () {
    state.byTitle = !state.byTitle;
    sortBtn.setAttribute('aria-pressed', state.byTitle ? 'true' : 'false');
    sortBtn.querySelector('i').className = 'ph ' + (state.byTitle ? 'ph-sort-descending' : 'ph-sort-ascending');
    apply();
  });

  // By default: the open page's own modality(ies), the last 30 days, newest first (the server's order)
  var current = root.querySelector('.wk-sel');
  if (current) {
    (current.getAttribute('data-mod') || '').split(',').forEach(function (mod) {
      var chip = mod && chips.querySelector('[data-wl-mod="' + mod + '" i]');
      if (chip) { state.mods[mod] = true; toggle(chip, true); }
    });
  }
  state.recent = true;
  toggle(chips.querySelector('[data-wl-recent]'), true);
  apply();

  // The open page comes into view in a long list
  if (current && current.scrollIntoView) current.scrollIntoView({ block: 'center' });
})();
