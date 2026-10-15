/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A table of contents for the admin screens (templates/admin-tabs.php loads
 * this before assets/js/toc.js): when a screen has two or more panels, their
 * titles become the same <nav class="wk-toc"> a report has, beside the panels
 * on a wide screen, the section in view marked by toc.js. The panels are
 * moved into the same grid a report's text sits in; below that width the
 * stylesheet hides the contents and nothing else changes. Progressive
 * enhancement: without this script the screen is the panels, as before.
 */
(function () {
  'use strict';

  var doc = document.querySelector('.wk-doc');
  var tabs = doc && doc.querySelector('.wk-pagetabs');
  var script = document.currentScript;
  if (!tabs || tabs.parentNode !== doc) return;

  function title(panel) {
    var h2 = panel.querySelector('.wk-panel-h h2');
    if (!h2 || panel.hidden) return '';
    var clone = h2.cloneNode(true);
    Array.prototype.forEach.call(clone.querySelectorAll('.wk-mono, .wk-dim, i, .tag'), function (el) { el.remove(); });
    var text = clone.textContent.replace(/\s+/g, ' ').replace(/(\s·){2,}/g, ' ·').replace(/^[\s·]+|[\s·]+$/g, '');
    return text || h2.textContent.replace(/\s+/g, ' ').trim();
  }

  // A screen that renders the grid itself (Admin → Tags): the contents only go into it
  var built = doc.querySelector('.wk-docbody > .wk-docgrid');

  var entries = [];
  Array.prototype.forEach.call(doc.querySelectorAll('.wk-panel'), function (panel, index) {
    var text = title(panel);
    if (text === '') return;
    if (!panel.id) panel.id = 'panel-' + (index + 1);
    entries.push({ panel: panel, text: text });
  });
  if (entries.length < 2) {
    if (built) built.classList.remove('wk-has-toc');
    return;
  }

  var label = (script && script.getAttribute('data-label')) || '';
  var nav = document.createElement('nav');
  nav.className = 'wk-toc';
  nav.setAttribute('data-island', 'toc');
  nav.setAttribute('aria-label', label);
  var eyebrow = document.createElement('span');
  eyebrow.className = 'wk-eyebrow';
  eyebrow.textContent = label;
  var list = document.createElement('ul');
  entries.forEach(function (entry) {
    var item = document.createElement('li');
    item.setAttribute('data-depth', '0');
    var link = document.createElement('a');
    link.href = '#' + encodeURIComponent(entry.panel.id);
    link.textContent = entry.text;
    item.appendChild(link);
    list.appendChild(item);
  });
  nav.appendChild(eyebrow);
  nav.appendChild(list);

  if (built) {
    built.insertBefore(nav, built.firstChild);
    return;
  }

  var main = document.createElement('div');
  main.className = 'wk-docmain';
  while (tabs.nextSibling) main.appendChild(tabs.nextSibling);
  var grid = document.createElement('div');
  grid.className = 'wk-docgrid wk-has-toc';
  grid.appendChild(nav);
  grid.appendChild(main);
  var body = document.createElement('div');
  body.className = 'wk-docbody wk-admintoc';
  body.appendChild(grid);
  doc.appendChild(body);
}());
