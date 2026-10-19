/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A page's comments (D52): the page view's Comments panel. Lists them
 * (GET /api/v1/pages/{path}/comments), open ones first, resolved ones
 * folded; adds one (POST …/comments {text, quote}); resolves, reopens and
 * deletes (POST …/comments/{id} {action}). Text selected in the report
 * when the comment box takes the focus becomes the comment's quote.
 * Everything is shown with textContent: a comment is never HTML.
 */
(function () {
  'use strict';

  function mount(box) {
    var cfgEl = box.querySelector('script[type="application/json"]');
    if (!cfgEl) return;
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent) || {}; } catch (e) { return; }
    var s = cfg.strings || {};
    var url = cfg.basePath + '/api/v1/pages/' + cfg.path + '/comments';
    var list = box.querySelector('[data-comments-list]');
    var resolvedBox = box.querySelector('[data-comments-resolved]');
    var resolvedList = resolvedBox ? resolvedBox.querySelector('ol') : null;
    var count = box.querySelector('[data-comments-count]');
    var form = box.querySelector('[data-comments-form]');
    var quoteEl = box.querySelector('[data-comments-quote]');
    var status = box.querySelector('[data-comments-status]');
    var quote = '';

    function el(tag, cls, text) {
      var node = document.createElement(tag);
      if (cls) node.className = cls;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    function when(ts) {
      var d = new Date(ts);
      return isNaN(d.getTime()) ? ts : d.toLocaleString();
    }

    function call(method, target, body) {
      return fetch(target, {
        method: method,
        credentials: 'same-origin',
        headers: body ? { 'Content-Type': 'application/json', 'Accept': 'application/json' } : { 'Accept': 'application/json' },
        body: body ? JSON.stringify(body) : undefined
      }).then(function (r) {
        return r.json().then(function (json) { return { ok: r.ok, json: json }; });
      });
    }

    function button(label, icon, onClick) {
      var b = el('button', 'btn btn-secondary btn-sm');
      b.type = 'button';
      b.appendChild(el('i', 'ph ph-' + icon));
      b.appendChild(document.createTextNode(' ' + label));
      b.addEventListener('click', onClick);
      return b;
    }

    function item(c) {
      var li = el('li', 'wk-comment' + (c.resolved ? ' is-resolved' : ''));
      var head = el('div', 'wk-comment-head wk-mono wk-dim', (c.by_name || c.by) + ' · ' + when(c.ts) + (c.rev ? ' · ' + (s.rev || 'rev %d').replace('%d', c.rev) : ''));
      li.appendChild(head);
      if (c.quote) li.appendChild(el('blockquote', 'wk-comment-quote', c.quote));
      li.appendChild(el('p', 'wk-comment-text', c.text));
      if (c.resolved) li.appendChild(el('p', 'wk-mono wk-dim', (s.resolvedBy || '%s').replace('%s', c.resolved_by_name || c.resolved.by)));
      var row = el('div', 'wk-recall-row');
      row.appendChild(button(c.resolved ? s.reopen : s.resolve, c.resolved ? 'arrow-counter-clockwise' : 'check', function () {
        act(c.id, c.resolved ? 'reopen' : 'resolve');
      }));
      if (c.by === cfg.me || cfg.owner) {
        row.appendChild(button(s.delete, 'trash', function () {
          if (window.confirm(s.deleteConfirm || '')) act(c.id, 'delete');
        }));
      }
      li.appendChild(row);
      return li;
    }

    function show(comments) {
      var open = comments.filter(function (c) { return !c.resolved; });
      var done = comments.filter(function (c) { return c.resolved; });
      list.innerHTML = '';
      if (open.length === 0) list.appendChild(el('li', 'wk-mono wk-dim', s.none || ''));
      open.forEach(function (c) { list.appendChild(item(c)); });
      if (count) count.textContent = open.length > 0 ? '(' + open.length + ')' : '';
      if (resolvedBox && resolvedList) {
        resolvedBox.hidden = done.length === 0;
        resolvedBox.querySelector('summary').textContent = (s.resolved || '%d').replace('%d', done.length);
        resolvedList.innerHTML = '';
        done.forEach(function (c) { resolvedList.appendChild(item(c)); });
      }
    }

    function failed(r) {
      if (status) status.textContent = r && r.json && r.json.error ? r.json.error.message : (s.failed || '');
    }

    function load() {
      call('GET', url).then(function (r) { if (r.ok) show(r.json.data || []); else failed(r); }, function () { failed(null); });
    }

    function act(id, action) {
      call('POST', url + '/' + encodeURIComponent(id), { action: action }).then(function (r) {
        if (r.ok) show(r.json.data || []); else failed(r);
      }, function () { failed(null); });
    }

    function setQuote(text) {
      quote = String(text).replace(/\s+/g, ' ').trim().slice(0, 300);
      if (quoteEl) {
        quoteEl.hidden = quote === '';
        quoteEl.textContent = quote !== '' ? (s.quote || '%s').replace('%s', quote) : '';
      }
    }
    // Text selected in the report (the page view), or in the editor's text, when the comment box is reached:
    // what the comment is about
    var doc = document.querySelector('.wk-docbody');
    document.addEventListener('selectionchange', function () {
      var sel = window.getSelection ? window.getSelection() : null;
      if (!sel || sel.isCollapsed || !doc || !doc.contains(sel.anchorNode)) return;
      setQuote(sel.toString());
    });
    var lastArea = null;
    document.addEventListener('focusout', function (event) {
      if (event.target && event.target.matches && event.target.matches('#editor-pane textarea')) lastArea = event.target;
    });
    if (form && form.elements.text) {
      form.elements.text.addEventListener('focus', function () {
        if (lastArea && lastArea.selectionStart !== lastArea.selectionEnd) setQuote(lastArea.value.slice(lastArea.selectionStart, lastArea.selectionEnd));
      });
    }

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var text = form.elements.text.value.trim();
        if (text === '') return;
        call('POST', url, { text: text, quote: quote }).then(function (r) {
          if (!r.ok) { failed(r); return; }
          form.elements.text.value = '';
          setQuote('');
          if (status) status.textContent = '';
          load();
        }, function () { failed(null); });
      });
    }

    load();
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-island="comments"]'), mount);
}());
