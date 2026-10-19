/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the archive (D53): POST /api/v1/ask {question}. The answer is
 * rendered by the server's one parser (render-client.js); its [n] become
 * numbered pills that jump to the source they cite (and light it up), the
 * sources are listed beside it, each a link to its report by pid and rev
 * (a source from outside the wiki, such as the HIS, has no link). Sources
 * are shown with textContent. Shown only: nothing is written.
 */
(function () {
  'use strict';

  function mount(box) {
    var cfgEl = box.querySelector('script[type="application/json"]');
    if (!cfgEl) return;
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var form = box.querySelector('[data-ask-form]');
    var submit = box.querySelector('[data-ask-submit]');
    var status = box.querySelector('[data-ask-status]');
    var examples = box.querySelector('[data-ask-examples]');
    var results = box.querySelector('[data-ask-results]');
    var answer = box.querySelector('[data-ask-answer]');
    var context = box.querySelector('[data-ask-context]');
    var copy = box.querySelector('[data-ask-copy]');
    var sourcesBox = box.querySelector('[data-ask-sources]');
    var count = box.querySelector('[data-ask-count]');
    var sources = sourcesBox.querySelector('ol');
    var shown = {};

    function el(tag, cls, text) {
      var node = document.createElement(tag);
      if (cls) node.className = cls;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    // The notice card (templates/partials/notice.php, docs/ui-notices.md)
    function notice(kind, text) {
      status.textContent = '';
      if (!text) return;
      var card = el('div', 'wk-notice wk-notice-' + kind);
      card.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
      var icon = el('i', 'ph ph-' + ({ info: 'info', warn: 'warning', error: 'warning-octagon' }[kind] || 'info'));
      icon.setAttribute('aria-hidden', 'true');
      card.appendChild(icon);
      card.appendChild(el('div', '', text));
      status.appendChild(card);
    }

    function reset() {
      results.hidden = true;
      answer.textContent = '';
      context.hidden = true;
      sourcesBox.hidden = true;
      sources.textContent = '';
      shown = {};
    }

    // One source and the pills that cite it light up together
    function light(n, on) {
      var li = sources.querySelector('[data-n="' + n + '"]');
      if (li) li.classList.toggle('is-hl', on);
      Array.prototype.forEach.call(answer.querySelectorAll('.wk-ask-cite[data-n="' + n + '"]'), function (a) { a.classList.toggle('is-hl', on); });
    }

    function showSources(list) {
      list.forEach(function (src) {
        shown[src.n] = true;
        var li = el('li', 'wk-ask-source');
        li.id = 'ask-src-' + src.n;
        li.setAttribute('data-n', src.n);
        li.appendChild(el('span', 'wk-ask-num', String(src.n)));
        var head = el('div', 'wk-ask-source-meta');
        var icon = el('i', 'ph ph-' + (src.href ? 'file-text' : 'hospital'));
        icon.setAttribute('aria-hidden', 'true');
        head.appendChild(icon);
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
          var open = el('i', 'ph ph-arrow-square-out');
          open.setAttribute('aria-hidden', 'true');
          a.insertBefore(open, a.firstChild);
          li.appendChild(a);
        }
        li.addEventListener('mouseenter', function () { light(src.n, true); });
        li.addEventListener('mouseleave', function () { light(src.n, false); });
        sources.appendChild(li);
      });
      count.textContent = list.length ? String(list.length) : '';
      sourcesBox.hidden = list.length === 0;
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
        var parts = node.nodeValue.split(/(\[\d{1,2}\])/);
        var group = null;
        var flush = function () { if (group) { frag.appendChild(group); group = null; } };
        parts.forEach(function (part, i) {
          var m = /^\[(\d{1,2})\]$/.exec(part);
          if (m && shown[m[1]]) {
            // Pills and the punctuation right after them stay on one line
            group = group || el('span', 'wk-ask-nb');
            group.appendChild(pill(m[1]));
            return;
          }
          if (group && part) {
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
      a.href = '#ask-src-' + n;
      a.setAttribute('data-n', n);
      a.addEventListener('mouseenter', function () { light(n, true); });
      a.addEventListener('mouseleave', function () { light(n, false); });
      a.addEventListener('focus', function () { light(n, true); });
      a.addEventListener('blur', function () { light(n, false); });
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        var li = document.getElementById('ask-src-' + n);
        if (li) li.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      });
      return a;
    }

    function ask() {
      var question = form.elements.question.value.trim();
      if (!question || submit.getAttribute('aria-busy') === 'true') return;
      reset();
      examples.hidden = true;
      submit.setAttribute('data-busy', '');
      submit.setAttribute('aria-busy', 'true');
      notice('info', s.asking || '');
      fetch((cfg.basePath || '') + '/api/v1/ask', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ question: question })
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
        // A source left out (HippoBridge down) is said, the answer still shown
        notice((data.notices || []).length ? 'warn' : '', (data.notices || []).join(' '));
        showSources(data.sources || []);
        if (data.context && data.context.length) {
          context.textContent = '';
          var lock = el('i', 'ph ph-shield-check');
          lock.setAttribute('aria-hidden', 'true');
          context.appendChild(lock);
          context.appendChild(document.createTextNode(' ' + (s.context || '') + ' ' + data.context.join(' · ') + (data.provider ? ' · ' + data.provider : '')));
          context.hidden = false;
        }
        var done = function (node) { cite(); results.hidden = false; return node; };
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
        if (results.hidden) examples.hidden = false;
      });
    }

    form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(); });
    form.elements.question.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); ask(); }
    });
    Array.prototype.forEach.call(box.querySelectorAll('[data-ask-example]'), function (chip) {
      chip.addEventListener('click', function () {
        form.elements.question.value = chip.getAttribute('data-ask-example');
        ask();
      });
    });
    if (copy) {
      copy.addEventListener('click', function () {
        var label = copy.querySelector('.wk-btn-label');
        var text = answer.innerText || answer.textContent;
        var ok = function () {
          var icon = copy.querySelector('i');
          icon.className = 'ph ph-check';
          if (label) label.textContent = s.copied || '';
          setTimeout(function () { icon.className = 'ph ph-copy'; if (label) label.textContent = s.copy || ''; }, 1600);
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
