/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the archive (D53): POST /api/v1/ask {question, history}. The answer is
 * rendered by the server's one parser (render-client.js); its [n] become
 * numbered pills that jump to the source they cite (and light it up), the
 * sources are listed under it, each a link to its report by pid and rev (a
 * source from outside the wiki, such as the HIS, has no link). Sources are
 * shown with textContent. A follow-up sends the last HISTORY turns (question
 * and answer, kept in this page only: never stored, de-identified by the
 * server before any model); an earlier turn is folded under the new answer.
 * Shown only: nothing is written.
 */
(function () {
  'use strict';

  var HISTORY = 5;

  function mount(box) {
    var cfgEl = box.querySelector('script[type="application/json"]');
    if (!cfgEl) return;
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var form = box.querySelector('[data-ask-form]');
    var submit = box.querySelector('[data-ask-submit]');
    var fresh = box.querySelector('[data-ask-new]');
    var status = box.querySelector('[data-ask-status]');
    var examples = box.querySelector('[data-ask-examples]');
    var results = box.querySelector('[data-ask-results]');
    var asked = box.querySelector('[data-ask-asked]');
    var understood = box.querySelector('[data-ask-understood]');
    var terms = box.querySelector('[data-ask-terms]');
    var answer = box.querySelector('[data-ask-answer]');
    var context = box.querySelector('[data-ask-context]');
    var copy = box.querySelector('[data-ask-copy]');
    var sourcesBox = box.querySelector('[data-ask-sources]');
    var count = box.querySelector('[data-ask-count]');
    var thread = box.querySelector('[data-ask-thread]');
    var sources = sourcesBox.querySelector('ol');
    var question = form.elements.question;
    var placeholder = question.getAttribute('placeholder') || '';
    var history = [];
    var turn = 0;
    var shown = {};

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

    // The notice card (templates/partials/notice.php, docs/ui-notices.md)
    function notice(kind, text) {
      status.textContent = '';
      if (!text) return;
      var card = el('div', 'wk-notice wk-notice-' + kind);
      card.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
      card.appendChild(icon({ info: 'info', warn: 'warning', error: 'warning-octagon' }[kind] || 'info'));
      card.appendChild(el('div', '', text));
      status.appendChild(card);
    }

    function reset() {
      results.hidden = true;
      answer.textContent = '';
      context.hidden = true;
      sourcesBox.hidden = true;
      sources.textContent = '';
      understood.hidden = true;
      terms.hidden = true;
      terms.textContent = '';
      shown = {};
    }

    // The answer on screen is folded under the next one: a copy, which keeps its links but not its highlights
    function fold(q) {
      var copyOf = results.cloneNode(true);
      copyOf.removeAttribute('data-ask-results');
      copyOf.hidden = false;
      Array.prototype.forEach.call(copyOf.querySelectorAll('[data-ask-copy]'), function (b) { b.parentNode.removeChild(b); });
      var turnBox = el('details', 'wk-ask-turn');
      turnBox.appendChild(el('summary', '', q));
      turnBox.appendChild(copyOf);
      thread.insertBefore(turnBox, thread.children[1] || null);
      thread.hidden = false;
    }

    // One source and the pills that cite it light up together
    function light(n, on) {
      var li = document.getElementById('ask-src-' + turn + '-' + n);
      if (li) li.classList.toggle('is-hl', on);
      Array.prototype.forEach.call(answer.querySelectorAll('.wk-ask-cite[data-n="' + n + '"]'), function (a) { a.classList.toggle('is-hl', on); });
    }

    function showSources(list) {
      list.forEach(function (src) {
        shown[src.n] = true;
        var li = el('li', 'wk-ask-source');
        li.id = 'ask-src-' + turn + '-' + src.n;
        li.setAttribute('data-n', src.n);
        li.appendChild(el('span', 'wk-ask-num', String(src.n)));
        var head = el('div', 'wk-ask-source-meta');
        head.appendChild(icon(src.href ? 'file-text' : 'hospital'));
        var date = String(src.study_date || '').replace('T', ' ').slice(0, 16);
        if (date) head.appendChild(el('span', 'wk-mono', date));
        if (src.modality) head.appendChild(el('span', 'tag ' + (src.href ? 'tag-outline' : 'tag-neutral'), src.modality));
        if (src.heading) head.appendChild(el('span', '', src.heading));
        if (src.mine) head.appendChild(el('span', 'tag tag-accent', s.mine || ''));
        li.appendChild(head);
        li.appendChild(el('p', '', src.text));
        // A source from outside the wiki (HippoBridge's HIS, hook ask.sources) has no page to link to
        if (src.href) {
          var a = el('a', 'wk-ask-open', s.open || '');
          a.href = (cfg.basePath || '') + src.href;
          a.insertBefore(icon('arrow-square-out'), a.firstChild);
          li.appendChild(a);
        }
        li.addEventListener('mouseenter', function () { light(src.n, true); });
        li.addEventListener('mouseleave', function () { light(src.n, false); });
        sources.appendChild(li);
      });
      count.textContent = list.length ? String(list.length) : '';
      sourcesBox.hidden = list.length === 0;
    }

    // What was searched: the question as understood, and its words
    function showHow(q, data) {
      asked.textContent = q;
      if (data.understood) {
        understood.querySelector('[data-ask-understood-text]').textContent = data.understood;
        understood.hidden = false;
      }
      var searched = data.searched || {};
      (searched.terms || []).forEach(function (term) { terms.appendChild(el('span', 'wk-chip', term)); });
      if (searched.meaning) {
        var chip = el('span', 'wk-chip');
        chip.appendChild(icon('brain'));
        chip.appendChild(document.createTextNode(' ' + (s.meaning || '')));
        terms.appendChild(chip);
      }
      terms.hidden = terms.children.length === 0;
    }

    // [1] and [1][2] in the rendered answer become pills to the sources they cite
    function cite() {
      var walker = document.createTreeWalker(answer, NodeFilter.SHOW_TEXT, {
        acceptNode: function (node) {
          return node.parentNode.closest('a, code, pre') || !/\[\d{1,2}\]/.test(node.nodeValue) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        }
      });
      var nodes = [];
      while (walker.nextNode()) nodes.push(walker.currentNode);
      nodes.forEach(function (node) {
        var frag = document.createDocumentFragment();
        var group = null;
        var flush = function () { if (group) { frag.appendChild(group); group = null; } };
        node.nodeValue.split(/(\[\d{1,2}\])/).forEach(function (part) {
          if (part === '') return; // between two pills
          var m = /^\[(\d{1,2})\]$/.exec(part);
          if (m && shown[m[1]]) {
            // Pills and the punctuation right after them stay on one line
            group = group || el('span', 'wk-ask-nb');
            group.appendChild(pill(m[1]));
            return;
          }
          if (group) {
            var p = /^[.,;:!?)]+/.exec(part);
            if (p) { group.appendChild(document.createTextNode(p[0])); part = part.slice(p[0].length); }
          }
          flush();
          if (part) frag.appendChild(document.createTextNode(part));
        });
        flush();
        node.parentNode.replaceChild(frag, node);
      });
    }

    function pill(n) {
      var a = el('a', 'wk-ask-cite', n);
      a.href = '#ask-src-' + turn + '-' + n;
      a.setAttribute('data-n', n);
      a.addEventListener('mouseenter', function () { light(n, true); });
      a.addEventListener('mouseleave', function () { light(n, false); });
      a.addEventListener('focus', function () { light(n, true); });
      a.addEventListener('blur', function () { light(n, false); });
      var mine = turn;
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        var li = document.getElementById('ask-src-' + mine + '-' + n);
        if (li) li.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      });
      return a;
    }

    function conversation() {
      fresh.hidden = history.length === 0;
      question.setAttribute('placeholder', history.length ? (s.followup || placeholder) : placeholder);
    }

    function ask() {
      var q = question.value.trim();
      if (!q || submit.getAttribute('aria-busy') === 'true') return;
      var hadResults = !results.hidden;
      var previous = asked.textContent;
      submit.setAttribute('data-busy', '');
      submit.setAttribute('aria-busy', 'true');
      examples.hidden = true;
      notice('info', s.asking || '');
      fetch((cfg.basePath || '') + '/api/v1/ask', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ question: q, history: history.slice(-HISTORY) })
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (json) { return { ok: response.ok, json: json }; });
      }).then(function (r) {
        if (!r.ok || !r.json.data) {
          notice('error', r.json.error && r.json.error.message ? r.json.error.message : (s.failed || ''));
          return null;
        }
        var data = r.json.data;
        if (data.answer === null) {
          notice('info', [s.none || ''].concat(data.notices || []).join(' '));
          return null;
        }
        // The answer on screen goes under this one; a new turn numbers its sources apart
        if (hadResults) fold(previous);
        reset();
        turn += 1;
        // A source left out (HippoBridge down) is said, the answer still shown
        notice((data.notices || []).length ? 'warn' : '', (data.notices || []).join(' '));
        showSources(data.sources || []);
        showHow(q, data);
        if (data.context && data.context.length) {
          context.textContent = '';
          context.appendChild(icon('shield-check'));
          context.appendChild(document.createTextNode(' ' + (s.context || '') + ' ' + data.context.join(' · ') + (data.provider ? ' · ' + data.provider : '')));
          context.hidden = false;
        }
        history.push({ question: q, answer: data.answer });
        history = history.slice(-HISTORY);
        question.value = '';
        conversation();
        var done = function () { cite(); results.hidden = false; };
        return window.ReporionRender.html(cfg.basePath, data.answer).then(function (html) {
          answer.innerHTML = html;
          done();
        }, function () {
          answer.textContent = data.answer;
          done();
        });
      }).catch(function () {
        notice('error', s.failed || '');
      }).then(function () {
        submit.removeAttribute('aria-busy');
        submit.removeAttribute('data-busy');
        if (results.hidden && !history.length) examples.hidden = false;
      });
    }

    form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(); });
    question.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); ask(); }
    });
    Array.prototype.forEach.call(box.querySelectorAll('[data-ask-example]'), function (chip) {
      chip.addEventListener('click', function () {
        question.value = chip.getAttribute('data-ask-example');
        ask();
      });
    });
    fresh.addEventListener('click', function () {
      history = [];
      reset();
      notice('', '');
      Array.prototype.slice.call(thread.querySelectorAll('.wk-ask-turn')).forEach(function (t) { t.parentNode.removeChild(t); });
      thread.hidden = true;
      question.value = '';
      examples.hidden = false;
      conversation();
      question.focus();
    });
    if (copy) {
      copy.addEventListener('click', function () {
        var label = copy.querySelector('.wk-btn-label');
        var text = answer.innerText || answer.textContent;
        var ok = function () {
          var i = copy.querySelector('i');
          i.className = 'ph ph-check';
          if (label) label.textContent = s.copied || '';
          setTimeout(function () { i.className = 'ph ph-copy'; if (label) label.textContent = s.copy || ''; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok, function () {});
      });
    }
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="ask"]'), mount);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
