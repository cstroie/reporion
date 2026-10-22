/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The Metadata view's exam cards (roadmap phase 28b, partials/editor-details.php):
 * add, remove and move exams, with the report's text kept in step: each
 * exam is a `##` section of the body (docs/FORMATS.md §12), cut by the same
 * line rule as Support\Exams (assets/js/editor-exams.js's splitBody). A
 * card's order is posted with it (`fm[exam_order][]`), so the server needs
 * nothing from this script: without it the cards are edited in place, and
 * adding, removing and moving are left to raw mode.
 *
 * The text follows only while it has one `##` per card (a single-exam
 * report: one, or none before the first Add exam); otherwise the tools say
 * why and leave it.
 */
(function () {
  'use strict';

  var EX = window.ReporionEditorExams;
  var box = document.querySelector('[data-exam-cards]');
  var blank = document.querySelector('template[data-exam-blank]');
  var addBtn = document.querySelector('[data-exam-add]');
  var why = document.querySelector('[data-exam-why]');
  var body = document.querySelector('#editor-pane textarea[name="body"]');
  if (!EX || !box || !blank || !addBtn || !body) return;

  var fresh = 0;

  // Picking a template: its regions are ticked in the card (D45: the template's region is the exam's default),
  // and the card's link to the template follows the select (a template is a page: base/path)
  box.addEventListener('change', function (event) {
    var select = event.target;
    if (!select.matches || !select.matches('select[name$="[template]"]')) return;
    var card = select.closest('.wk-examcard');
    var option = select.options[select.selectedIndex];
    var wanted = option ? (option.getAttribute('data-region') || '').split(', ').filter(Boolean) : [];
    // A template with no region (or none picked) leaves the ticked ones as they are
    if (wanted.length) {
      Array.prototype.forEach.call(card.querySelectorAll('input[type="checkbox"][name$="[region][]"]'), function (tick) {
        var on = wanted.indexOf(tick.value) !== -1;
        if (tick.checked === on) return;
        tick.checked = on;
        // The form's draft, autosave and Save button listen for change
        tick.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
    var link = card.querySelector('[data-exam-template-link]');
    if (!link) return;
    var root = link.getAttribute('data-root');
    link.hidden = select.value === '';
    link.setAttribute('href', root + '/' + select.value);
  });

  function cards() {
    return Array.prototype.slice.call(box.querySelectorAll('.wk-examcard'));
  }

  function split() {
    return EX.splitBody(body.value);
  }

  /** Whether the text's ## sections are the cards, one to one */
  function inStep(parts) {
    return parts.length === cards().length;
  }

  function setBody(head, parts, shared) {
    var text = head;
    if (shared) parts = parts.concat([shared.text]);
    parts.forEach(function (p, i) {
      if (text !== '' && !/\n\n$/.test(text)) text = text.replace(/\n?$/, '\n\n');
      text += i < parts.length - 1 ? p.replace(/\n*$/, '\n') : p;
    });
    body.value = text;
    // The editor's draft, preview and checklist listen for input
    body.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function say(text) {
    if (!why) return;
    why.textContent = text || '';
    why.hidden = !text;
  }

  function renumber() {
    var all = cards();
    all.forEach(function (card, i) {
      var n = card.querySelector('.wk-examcard-n');
      if (n) n.textContent = n.textContent.replace(/\d+/, String(i + 1));
      var tools = card.querySelector('.wk-examcard-tools');
      if (tools) {
        tools.hidden = false;
        tools.querySelector('[data-exam-move="-1"]').disabled = i === 0;
        tools.querySelector('[data-exam-move="1"]').disabled = i === all.length - 1;
        tools.querySelector('[data-exam-remove]').disabled = all.length === 1;
      }
    });
  }

  function titleOf(card) {
    var input = card.querySelector('input[name$="[title]"]');
    return input ? input.value.trim() : '';
  }

  function add() {
    var parts = split();
    var n = cards().length;
    // A single-exam report with no ## yet: its whole text becomes exam 1's, under its title
    if (n === 1 && parts.parts.length === 0) {
      var first = titleOf(cards()[0]) || box.getAttribute('data-new-title');
      parts = { head: parts.head.replace(/\s*$/, '\n\n') + '## ' + first + '\n\n', parts: [] };
      parts = EX.splitBody(parts.head);
      if (parts.parts.length !== 1) { say(box.getAttribute('data-shape')); return; }
    }
    if (!inStep(parts.parts)) { say(box.getAttribute('data-shape')); return; }
    say('');
    fresh += 1;
    var id = 'n' + fresh;
    var html = blank.innerHTML.split('__new__').join(id);
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var card = holder.firstElementChild;
    box.appendChild(card);
    var title = box.getAttribute('data-new-title');
    var input = card.querySelector('input[name$="[title]"]');
    if (input) input.value = title;
    var texts = parts.parts.map(function (p) { return p.text; });
    texts.push('## ' + title + '\n\n### Descriere\n\n### Concluzii\n');
    setBody(parts.head, texts, parts.shared);
    wire(card);
    renumber();
    if (input) { input.focus(); input.select(); }
  }

  function remove(card) {
    var all = cards();
    var i = all.indexOf(card);
    if (all.length < 2 || !window.confirm(box.getAttribute('data-confirm-remove'))) return;
    var parts = split();
    if (!inStep(parts.parts)) { say(box.getAttribute('data-shape')); return; }
    say('');
    card.remove();
    var texts = parts.parts.map(function (p) { return p.text; });
    texts.splice(i, 1);
    setBody(parts.head, texts, parts.shared);
    renumber();
  }

  function move(card, delta) {
    var all = cards();
    var i = all.indexOf(card);
    var to = i + delta;
    if (to < 0 || to >= all.length) return;
    var parts = split();
    if (!inStep(parts.parts)) { say(box.getAttribute('data-shape')); return; }
    say('');
    if (delta < 0) box.insertBefore(card, all[to]); else box.insertBefore(card, all[to].nextSibling);
    var texts = parts.parts.map(function (p) { return p.text.replace(/\n*$/, '\n'); });
    texts.splice(to, 0, texts.splice(i, 1)[0]);
    setBody(parts.head, texts, parts.shared);
    renumber();
    card.scrollIntoView({ block: 'nearest' });
  }

  /** A card's title is its section's ## heading: typing one renames the other */
  function retitle(card) {
    var parts = split();
    var i = cards().indexOf(card);
    if (!inStep(parts.parts) || i < 0) return;
    var texts = parts.parts.map(function (p) { return p.text; });
    texts[i] = texts[i].replace(/^[^\n]*/, '## ' + titleOf(card));
    setBody(parts.head, texts, parts.shared);
  }

  function wire(card) {
    var up = card.querySelector('[data-exam-move="-1"]');
    var down = card.querySelector('[data-exam-move="1"]');
    var rm = card.querySelector('[data-exam-remove]');
    if (up) up.addEventListener('click', function () { move(card, -1); });
    if (down) down.addEventListener('click', function () { move(card, 1); });
    if (rm) rm.addEventListener('click', function () { remove(card); });
    var title = card.querySelector('input[name$="[title]"]');
    if (title) title.addEventListener('change', function () { retitle(card); });
  }

  cards().forEach(wire);
  addBtn.hidden = false;
  addBtn.addEventListener('click', add);
  if (cards().length > 1) renumber();
}());
