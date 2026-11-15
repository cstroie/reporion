/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A citation's hover card (docs/library.md, 2026-11-15): the pointer resting on a `[1]` or an
 * `(Author 2020)` that links to its library source (`a.wk-ref-src`, shown only to a reader of
 * the library), or the keyboard's focus on it, shows the source's cover, reference, one-line
 * summary and the start of its abstract, with Open and the original file. One request a source
 * (GET /api/v1/library/card?key=), kept for the page's life; a source the reader may not see has
 * no card. Listens on the document, so citations in an answer shown later have theirs too.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;
  var url = script.getAttribute('data-url');
  var labels = { open: script.getAttribute('data-open') || 'Open', file: script.getAttribute('data-file') || '' };
  var cards = {};
  var card = null;
  var shownFor = null;
  var timer = null;

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function keyOf(link) {
    var m = /\/[a-z0-9:_.-]*:([a-z0-9][a-z0-9_.-]*)(?:#.*)?$/.exec(link.getAttribute('href') || '');
    return m ? m[1] : null;
  }

  function fetchCard(key) {
    if (!cards[key]) {
      cards[key] = fetch(url + '?key=' + encodeURIComponent(key), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; }, function () { return null; });
    }
    return cards[key];
  }

  function hide() {
    window.clearTimeout(timer);
    if (card) card.hidden = true;
    shownFor = null;
  }

  function show(link, data) {
    if (!card) {
      card = el('div', 'wk-lib-card');
      card.setAttribute('role', 'tooltip');
      card.addEventListener('mouseleave', function () { timer = window.setTimeout(hide, 200); });
      card.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
      document.body.appendChild(card);
    }
    card.textContent = '';
    if (data.cover) {
      var cover = el('span', 'wk-lib-cover');
      var img = el('img');
      img.src = data.cover;
      img.alt = '';
      img.addEventListener('error', function () { cover.remove(); });
      cover.appendChild(img);
      card.appendChild(cover);
    }
    var body = el('div', 'wk-lib-card-text');
    body.appendChild(el('p', 'wk-lib-card-ref', data.citation || data.title || ''));
    if (data.summary) body.appendChild(el('p', 'wk-lib-lead', data.summary));
    else if (data.abstract) body.appendChild(el('p', 'wk-dim wk-lib-card-abstract', data.abstract));
    var actions = el('p', 'wk-lib-card-actions');
    var open = el('a', '', labels.open);
    open.href = link.getAttribute('href');
    actions.appendChild(open);
    if (data.file && labels.file) {
      var file = el('a', '', labels.file);
      file.href = data.file;
      file.target = '_blank';
      file.rel = 'noopener';
      actions.appendChild(file);
    }
    body.appendChild(actions);
    card.appendChild(body);
    card.hidden = false;
    // Under the citation, kept inside the window; above it when there is no room below
    var rect = link.getBoundingClientRect();
    var width = card.offsetWidth;
    var left = Math.max(8, Math.min(rect.left, document.documentElement.clientWidth - width - 8));
    var below = rect.bottom + 6 + card.offsetHeight <= window.innerHeight;
    card.style.left = (window.scrollX + left) + 'px';
    card.style.top = (window.scrollY + (below ? rect.bottom + 6 : Math.max(8, rect.top - card.offsetHeight - 6))) + 'px';
  }

  function ask(link) {
    var key = keyOf(link);
    if (!key) return;
    shownFor = link;
    fetchCard(key).then(function (data) {
      if (data && shownFor === link) show(link, data);
    });
  }

  function citation(event) {
    return event.target && event.target.closest ? event.target.closest('a.wk-ref-src') : null;
  }

  document.addEventListener('mouseover', function (event) {
    var link = citation(event);
    if (!link || link === shownFor) return;
    window.clearTimeout(timer);
    // A rest of the pointer, not a pass over it
    timer = window.setTimeout(function () { ask(link); }, 350);
  });
  document.addEventListener('mouseout', function (event) {
    if (!citation(event)) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(hide, 250);
  });
  document.addEventListener('focusin', function (event) { var link = citation(event); if (link) ask(link); });
  document.addEventListener('focusout', function (event) { if (citation(event)) timer = window.setTimeout(hide, 250); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') hide(); });
}());
