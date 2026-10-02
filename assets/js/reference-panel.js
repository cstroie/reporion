/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Reference pages (roadmap phase 25). Wherever they are shown — the report
 * view's slide-in panel, the editor rail's section — several pages get a
 * switch that shows one at a time. The report view's panel: a
 * [data-ref-open] button slides it in from the right and out again; its ×,
 * Escape and the backdrop (narrow screens only) close it. Without
 * JavaScript the button is a link to the page.
 */
(function () {
  'use strict';

  document.querySelectorAll('[data-ref-pages]').forEach(function (box) {
    box.querySelectorAll('[data-ref-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var n = tab.getAttribute('data-ref-tab');
        box.querySelectorAll('[data-ref-tab]').forEach(function (other) {
          var on = other === tab;
          other.classList.toggle('seg-on', on);
          other.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        box.querySelectorAll('[data-ref-page]').forEach(function (page) {
          page.hidden = page.getAttribute('data-ref-page') !== n;
        });
      });
    });
  });

  var panel = document.getElementById('reference-panel');
  if (!panel) return;
  var backdrop = document.querySelector('.wk-ref-backdrop');
  // Out of the page's column: a CSS container there would hold `position: fixed` to it
  if (backdrop) document.body.appendChild(backdrop);
  document.body.appendChild(panel);
  var triggers = document.querySelectorAll('[data-ref-open]');

  function setOpen(open) {
    panel.hidden = !open;
    if (backdrop) backdrop.hidden = !open;
    triggers.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open) {
      var close = panel.querySelector('[data-ref-close]');
      if (close) close.focus();
    }
  }

  triggers.forEach(function (t) {
    t.setAttribute('aria-controls', 'reference-panel');
    t.setAttribute('aria-expanded', 'false');
    t.addEventListener('click', function (event) {
      event.preventDefault();
      setOpen(panel.hidden);
    });
  });
  document.querySelectorAll('[data-ref-close]').forEach(function (el) {
    el.addEventListener('click', function () { setOpen(false); });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden) setOpen(false);
  });
}());
