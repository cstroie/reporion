/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Sites (templates/admin-sites.php): each card's preview of the printed
 * letterhead follows the fields as they are typed (the server renders the same
 * lines to begin with, an empty name showing the app's name as the print view
 * does), and an accession code is kept in capitals. The page works without it.
 */
(function () {
  'use strict';

  Array.prototype.forEach.call(document.querySelectorAll('.wk-site-body'), function (form) {
    var preview = form.querySelector('[data-lh-preview]');
    if (!preview) return;
    var field = {};
    Array.prototype.forEach.call(form.querySelectorAll('[data-lh-field]'), function (input) {
      field[input.getAttribute('data-lh-field')] = input;
    });
    var out = {
      name: preview.querySelector('[data-lh="name"]'),
      dept: preview.querySelector('[data-lh="dept"]'),
      line: preview.querySelector('[data-lh="line"]')
    };
    function value(name) { return field[name] ? field[name].value.trim() : ''; }
    function update() {
      out.name.textContent = value('name') || preview.getAttribute('data-lh-fallback') || '';
      out.dept.textContent = value('dept');
      out.line.textContent = [value('address'), value('phone')].filter(Boolean).join(' · ');
    }
    form.addEventListener('input', update);
    // The preview shows what is printed: dimmed while the letterhead is off
    var sw = form.querySelector('[data-lh-switch]');
    if (sw) {
      sw.addEventListener('change', function () { preview.classList.toggle('wk-letterhead-off', !sw.checked); });
    }
  });

  Array.prototype.forEach.call(document.querySelectorAll('input[data-upper]'), function (input) {
    input.addEventListener('input', function () {
      var start = input.selectionStart;
      var end = input.selectionEnd;
      input.value = input.value.toUpperCase();
      try { input.setSelectionRange(start, end); } catch (e) { /* not selectable: leave it */ }
    });
  });
}());
