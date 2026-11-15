/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The reader of a library source's text (docs/library.md, *The reader*;
 * templates/partials/library-reader.php). A long article is a long page: this makes it one to
 * move about in, on the text exactly as the server rendered it (nothing is fetched, nothing
 * written):
 *
 * - page marks: the extracted text's `— p. 12 —` lines become rules that open the PDF at that
 *   page, each with the anchor `#p12`;
 * - an outline: every `##` section (or, in a text with no headings, every page) folds to its
 *   heading with its length and page; a long source opens folded, and Outline / Open all is
 *   remembered in this browser;
 * - find in this article: every match marked (case and diacritics apart), counted, stepped
 *   through with Enter and Shift+Enter, the sections holding one opened and counted;
 * - the page in view, and a jump to any other; the PDF opened at the page in view;
 * - a selection's actions: Copy with citation (`> text [@key, p. N]`), and the comments
 *   panel's Add passage and Comment without scrolling to the end of the page to find them.
 */
(function () {
  'use strict';

  var LONG = 20000;      // characters: a longer source opens as an outline
  var MAX_HITS = 2000;
  var KEPT = 'reporion.library.reader';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function icon(name) {
    var i = el('i', 'ph ph-' + name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  function fmt(text) {
    var args = Array.prototype.slice.call(arguments, 1);
    return String(text || '%s').replace(/%s/g, function () { return args.length ? String(args.shift()) : ''; });
  }

  // Lower case, diacritics dropped, one character for one: `Ţesut` is found by `tesut`.
  // Returns the folded text and, for each of its characters, the offset in the original
  function folded(text) {
    var out = '';
    var map = [];
    for (var i = 0; i < text.length; i++) {
      var c = text.charAt(i).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
      for (var j = 0; j < c.length; j++) { out += c.charAt(j); map.push(i); }
    }
    return { text: out, map: map };
  }

  function words(text) {
    var found = text.match(/\S+/g);
    return found ? found.length : 0;
  }

  function mount(bar) {
    var main = bar.parentNode;
    var prose = main.querySelector('.wk-prose');
    if (!prose) return;
    var cfg = {};
    var cfgEl = bar.querySelector(':scope > script[type="application/json"]');
    try { cfg = cfgEl ? (JSON.parse(cfgEl.textContent) || {}) : {}; } catch (e) { cfg = {}; }
    var s = cfg.strings || {};
    bar.hidden = false;
    main.classList.add('wk-reading');
    // What is scrolled to stops under the bar, however many lines the bar takes (a phone: two)
    var measure = function () { main.style.setProperty('--wk-reader-h', bar.offsetHeight + 'px'); };
    measure();
    window.addEventListener('resize', measure);

    // ---- page marks ----

    var marks = [];
    Array.prototype.slice.call(prose.children).forEach(function (p) {
      if (p.tagName !== 'P' || p.childNodes.length !== 1 || !p.firstChild.tagName || p.firstChild.tagName !== 'EM') return;
      var m = /^—\s*p\.\s*(\d+)\s*—$/.exec(p.textContent.trim());
      if (!m) return;
      var mark = el('div', 'wk-pagemark');
      mark.setAttribute('data-page', m[1]);
      if (!document.getElementById('p' + m[1])) mark.id = 'p' + m[1];
      var label;
      if (cfg.pdf) {
        label = el('a', '', fmt(s.page, m[1]));
        label.href = cfg.pdf + '#page=' + m[1];
        label.target = '_blank';
        label.rel = 'noopener';
        label.title = fmt(s.open_page, m[1]);
      } else {
        label = el('span', '', fmt(s.page, m[1]));
      }
      mark.appendChild(label);
      p.parentNode.replaceChild(mark, p);
      marks.push(mark);
    });

    // ---- sections: the `##` headings, or the pages of a text that has none ----

    var heads = Array.prototype.filter.call(prose.children, function (n) { return n.tagName === 'H2'; });
    var byPage = heads.length < 3 && marks.length >= 3;
    var bounds = byPage ? marks : heads;
    var sections = [];
    var sectionOf = new Map();
    var current = null;
    var page = null;
    var total = 0;
    var length = 0;
    Array.prototype.slice.call(prose.children).forEach(function (node) {
      if (node.classList.contains('wk-pagemark')) page = node.getAttribute('data-page');
      if (bounds.indexOf(node) >= 0) {
        current = { head: node, nodes: [], words: 0, page: page, folded: false, hits: 0, lead: '', meta: '' };
        sections.push(current);
        sectionOf.set(node, current);
        return;
      }
      var text = node.classList.contains('wk-pagemark') ? '' : node.textContent;
      total += words(text);
      length += text.length;
      if (!current) return;
      current.nodes.push(node);
      current.words += words(text);
      if (current.lead === '' && text.trim() !== '') current.lead = text.trim().replace(/\s+/g, ' ').slice(0, 110);
      sectionOf.set(node, current);
    });

    function setFolded(section, fold) {
      section.folded = fold;
      section.head.classList.toggle('is-folded', fold);
      section.toggle.setAttribute('aria-expanded', fold ? 'false' : 'true');
      section.nodes.forEach(function (n) { n.hidden = fold; });
    }

    sections.forEach(function (section) {
      var head = section.head;
      head.classList.add('wk-sect-h');
      var toggle = el('button', 'wk-sect-toggle');
      toggle.type = 'button';
      toggle.title = s.toggle || '';
      toggle.setAttribute('aria-label', s.toggle || '');
      toggle.setAttribute('aria-expanded', 'true');
      toggle.appendChild(icon('caret-down'));
      head.insertBefore(toggle, head.firstChild);
      section.toggle = toggle;
      // Its length and page are drawn from attributes (CSS), so they are never part of the text:
      // not selected, not copied with the section, not searched
      var facts = section.words > 0 ? [fmt(s.words, section.words)] : [];
      if (!byPage && section.page) facts.push(fmt(s.page, section.page));
      section.meta = facts.join(' · ');
      // A page has no heading to say what it holds: its first words do
      if (byPage && section.lead !== '') section.meta = section.lead + '… · ' + section.meta;
      head.setAttribute('data-meta', section.meta);
      head.addEventListener('click', function (event) {
        var inner = event.target.closest ? event.target.closest('a, button') : null;
        if (inner && inner !== toggle) return;
        // A click that ends a text selection is not a fold
        var sel = window.getSelection ? window.getSelection() : null;
        if (sel && !sel.isCollapsed && inner !== toggle) return;
        setFolded(section, !section.folded);
        foldLabel();
      });
    });

    var foldBtn = bar.querySelector('[data-reader-fold]');
    var foldText = bar.querySelector('[data-reader-fold-label]');
    function allFolded() { return sections.length > 0 && sections.every(function (x) { return x.folded; }); }
    function foldLabel() {
      if (!foldBtn) return;
      var all = allFolded();
      foldText.textContent = all ? (s.unfold || '') : (s.fold || '');
      foldBtn.title = foldText.textContent;
      foldBtn.setAttribute('aria-pressed', all ? 'true' : 'false');
    }
    function foldAll(fold) {
      sections.forEach(function (x) { setFolded(x, fold); });
      foldLabel();
    }
    if (foldBtn && sections.length >= 2) {
      foldBtn.hidden = false;
      foldBtn.addEventListener('click', function () {
        var fold = !allFolded();
        foldAll(fold);
        try { window.localStorage.setItem(KEPT, fold ? 'folded' : 'open'); } catch (e) { /* no storage */ }
        if (fold) main.scrollIntoView({ block: 'start' });
      });
      var kept = null;
      try { kept = window.localStorage.getItem(KEPT); } catch (e) { kept = null; }
      if (kept === 'folded' || (kept === null && length > LONG && sections.length >= 3)) foldAll(true);
      foldLabel();
    }

    var stat = bar.querySelector('[data-reader-stat]');
    if (stat && total > 0) stat.textContent = fmt(s.words, total.toLocaleString()) + ' · ' + fmt(s.minutes, Math.max(1, Math.round(total / 230)));

    // Opens the section that holds a node, so a link, a match or a page can be shown
    function reveal(node) {
      var top = node;
      while (top && top.parentNode !== prose) top = top.parentNode;
      var section = top ? sectionOf.get(top) : null;
      if (section && section.folded && section.head !== node) { setFolded(section, false); foldLabel(); }
      return section;
    }

    function fromHash() {
      var id = '';
      try { id = decodeURIComponent(window.location.hash.slice(1)); } catch (e) { id = ''; }
      var target = id !== '' ? document.getElementById(id) : null;
      if (!target || !prose.contains(target)) return;
      var section = reveal(target);
      // The contents' link to a heading opens what is under it
      if (section && section.head === target && section.folded) { setFolded(section, false); foldLabel(); }
      target.scrollIntoView({ block: 'start' });
    }
    window.addEventListener('hashchange', fromHash);
    fromHash();

    // ---- the page in view ----

    var pageBox = bar.querySelector('[data-reader-pagebox]');
    var pageSelect = bar.querySelector('[data-reader-page]');
    var pdfLink = bar.querySelector('[data-reader-pdf]');
    function shownTop(mark) {
      // A mark inside a folded section stands where its heading does
      var section = mark.hidden ? sectionOf.get(mark) : null;
      return (section ? section.head : mark).getBoundingClientRect().top;
    }
    function pageInView() {
      var line = bar.getBoundingClientRect().bottom + 32;
      var at = marks.length ? marks[0] : null;
      for (var i = 0; i < marks.length; i++) {
        if (shownTop(marks[i]) <= line) at = marks[i]; else break;
      }
      return at ? at.getAttribute('data-page') : null;
    }
    if (marks.length >= 2 && pageBox && pageSelect) {
      pageBox.hidden = false;
      marks.forEach(function (mark, i) {
        var option = el('option', '', mark.getAttribute('data-page') + ' / ' + marks[marks.length - 1].getAttribute('data-page'));
        option.value = String(i);
        pageSelect.appendChild(option);
      });
      var waiting = false;
      var follow = function () {
        waiting = false;
        var n = pageInView();
        for (var i = 0; i < marks.length; i++) {
          if (marks[i].getAttribute('data-page') === n) { pageSelect.value = String(i); break; }
        }
        if (pdfLink && n) pdfLink.href = cfg.pdf + '#page=' + n;
      };
      window.addEventListener('scroll', function () {
        if (!waiting) { waiting = true; window.requestAnimationFrame(follow); }
      }, { passive: true });
      follow();
      pageSelect.addEventListener('change', function () {
        var mark = marks[Number(pageSelect.value)];
        if (!mark) return;
        reveal(mark);
        mark.scrollIntoView({ block: 'start' });
      });
    }

    // ---- find in this article ----

    var find = bar.querySelector('[data-reader-find]');
    var hitsEl = bar.querySelector('[data-reader-hits]');
    var prev = bar.querySelector('[data-reader-prev]');
    var next = bar.querySelector('[data-reader-next]');
    var hits = [];
    var at = -1;

    function clear() {
      hits.forEach(function (mark) {
        var parent = mark.parentNode;
        if (!parent) return;
        parent.replaceChild(document.createTextNode(mark.textContent), mark);
        parent.normalize();
      });
      hits = [];
      at = -1;
      sections.forEach(function (x) { x.hits = 0; x.head.setAttribute('data-meta', x.meta); x.head.classList.remove('has-hits'); });
    }

    function go(i) {
      if (hits.length === 0) return;
      if (at >= 0 && hits[at]) hits[at].classList.remove('is-current');
      at = (i + hits.length) % hits.length;
      var mark = hits[at];
      mark.classList.add('is-current');
      reveal(mark);
      mark.scrollIntoView({ block: 'center' });
      hitsEl.textContent = fmt(s.hits, at + 1, hits.length);
    }

    function search() {
      clear();
      var query = folded(find.value.trim()).text;
      if (query.length < 2) {
        hitsEl.textContent = '';
        prev.disabled = next.disabled = true;
        return;
      }
      var walker = document.createTreeWalker(prose, NodeFilter.SHOW_TEXT, {
        acceptNode: function (node) {
          var parent = node.parentNode;
          return parent && parent.closest && parent.closest('.wk-pagemark, button, script, style')
            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        }
      });
      var nodes = [];
      while (walker.nextNode()) nodes.push(walker.currentNode);
      nodes.forEach(function (node) {
        if (hits.length >= MAX_HITS) return;
        var f = folded(node.nodeValue);
        var found = [];
        var from = f.text.indexOf(query);
        while (from >= 0) {
          found.push(from);
          from = f.text.indexOf(query, from + query.length);
        }
        // From the last match back, so the offsets before it stay good as the text node is split
        var made = [];
        for (var i = found.length - 1; i >= 0; i--) {
          var range = document.createRange();
          range.setStart(node, f.map[found[i]]);
          range.setEnd(node, f.map[found[i] + query.length - 1] + 1);
          var mark = el('mark', 'wk-find');
          try { range.surroundContents(mark); } catch (e) { continue; }
          made.unshift(mark);
        }
        hits = hits.concat(made);
      });
      prev.disabled = next.disabled = hits.length === 0;
      if (hits.length === 0) {
        hitsEl.textContent = s.no_hits || '';
        return;
      }
      // Which sections hold it: counted on their headings, and opened
      hits.forEach(function (mark) {
        var top = mark;
        while (top && top.parentNode !== prose) top = top.parentNode;
        var section = top ? sectionOf.get(top) : null;
        if (section) section.hits++;
      });
      sections.forEach(function (x) {
        x.head.classList.toggle('has-hits', x.hits > 0);
        if (x.hits > 0) x.head.setAttribute('data-meta', fmt(s.section_hits, x.hits) + ' · ' + x.meta);
        if (x.hits > 0 && x.folded) setFolded(x, false);
      });
      foldLabel();
      // The first match at or below what is being read, else the first
      var line = bar.getBoundingClientRect().bottom;
      var first = 0;
      for (var i = 0; i < hits.length; i++) {
        if (hits[i].getBoundingClientRect().top >= line) { first = i; break; }
      }
      go(first);
    }

    if (find) {
      var timer = null;
      find.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 250);
      });
      find.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          if (hits.length === 0) { window.clearTimeout(timer); search(); } else go(at + (event.shiftKey ? -1 : 1));
        } else if (event.key === 'Escape' && find.value !== '') {
          event.preventDefault();
          find.value = '';
          search();
        }
      });
      prev.addEventListener('click', function () { go(at - 1); });
      next.addEventListener('click', function () { go(at + 1); });
    }

    // ---- a selection's actions ----

    var passageBox = document.querySelector('[data-passage]');
    var commentText = document.querySelector('[data-comments-form] textarea');
    var pop = el('div', 'wk-reader-pop');
    pop.hidden = true;
    var picked = { text: '', page: null };

    function action(label, name, run) {
      var b = el('button', 'btn btn-ghost btn-sm');
      b.type = 'button';
      b.appendChild(icon(name));
      b.appendChild(el('span', '', label));
      b.addEventListener('click', function () { run(b); });
      pop.appendChild(b);
    }

    function copy(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
      var tmp = el('textarea');
      tmp.value = text;
      tmp.setAttribute('readonly', '');
      tmp.style.position = 'fixed';
      tmp.style.opacity = '0';
      document.body.appendChild(tmp);
      tmp.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(tmp);
      return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }

    action(s.copy_cite || '', 'quotes', function (b) {
      var cite = '> ' + picked.text + ' [@' + cfg.key + (picked.page ? ', p. ' + picked.page : '') + ']';
      copy(cite).then(function () {
        var label = b.lastChild;
        var was = label.textContent;
        label.textContent = s.copied || was;
        window.setTimeout(function () { label.textContent = was; pop.hidden = true; }, 900);
      }, function () { /* nothing copied: the label stays */ });
    });
    if (passageBox) {
      // The comments panel holds the selection already (comments.js); this only goes to it
      action(s.passage || '', 'bookmark-simple', function () {
        pop.hidden = true;
        passageBox.hidden = false;
        passageBox.scrollIntoView({ block: 'center' });
        var to = passageBox.querySelector('[data-passage-add]');
        if (to) to.focus({ preventScroll: true });
      });
    }
    if (commentText) {
      action(s.comment || '', 'chat-circle', function () {
        pop.hidden = true;
        commentText.scrollIntoView({ block: 'center' });
        commentText.focus({ preventScroll: true });
      });
    }
    // Pressing a button must not drop the selection it acts on
    pop.addEventListener('mousedown', function (event) { event.preventDefault(); });
    document.body.appendChild(pop);

    function offer() {
      var sel = window.getSelection ? window.getSelection() : null;
      if (!sel || sel.isCollapsed || sel.rangeCount === 0 || !prose.contains(sel.anchorNode) || !prose.contains(sel.focusNode)) { pop.hidden = true; return; }
      var text = sel.toString().replace(/\s+/g, ' ').trim();
      if (text.length < 3) { pop.hidden = true; return; }
      var range = sel.getRangeAt(0);
      var number = null;
      marks.forEach(function (mark) {
        if (mark.compareDocumentPosition(range.startContainer) & Node.DOCUMENT_POSITION_FOLLOWING) number = mark.getAttribute('data-page');
      });
      picked = { text: text.slice(0, 4000), page: number };
      var rect = range.getBoundingClientRect();
      pop.hidden = false;
      var width = pop.offsetWidth;
      var left = Math.max(8, Math.min(window.scrollX + rect.left, window.scrollX + document.documentElement.clientWidth - width - 8));
      pop.style.left = left + 'px';
      pop.style.top = (window.scrollY + rect.bottom + 8) + 'px';
    }
    document.addEventListener('mouseup', function (event) {
      if (pop.contains(event.target)) return;
      window.setTimeout(offer, 0);
    });
    document.addEventListener('keyup', function (event) {
      if (event.shiftKey || event.key === 'Shift') window.setTimeout(offer, 0);
    });
    document.addEventListener('touchend', function () { window.setTimeout(offer, 50); }, { passive: true });
    document.addEventListener('selectionchange', function () {
      var sel = window.getSelection ? window.getSelection() : null;
      if (!sel || sel.isCollapsed) pop.hidden = true;
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-island="library-reader"]'), mount);
}());
