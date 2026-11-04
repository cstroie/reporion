/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The library's tools (docs/library.md, D60), one island each on `[data-island="library-tools"]`,
 * told apart by `data-tool`; their config is the <script type="application/json"> inside the mount point:
 *
 * - related (a source's page): the card's "Related sources" list, fetched when it is first opened
 *   (GET /api/v1/library/{pid}/related), so the page itself stays as fast as before.
 * - the Find full text button on a source's card (`[data-lib-fulltext]`, POST /api/v1/library/{pid}/fulltext),
 *   its answer shown in `[data-lib-fulltext-status]`; the page reloads when a file was attached.
 * - online (GET /library, an editor, `library.search` on): PubMed searched by the words typed
 *   (GET /api/v1/library/online?q=), each result with Add (POST /api/v1/library/online {records}).
 * - duplicates (GET /library, an editor): groups of sources that look like one article; the radio
 *   picks the one to keep, Merge sends POST /api/v1/library/{keep}/merge {from} for each of the others.
 */
(function () {
  'use strict';

  function config(box) {
    var cfgEl = box.querySelector(':scope > script[type="application/json"]');
    try { return cfgEl ? (JSON.parse(cfgEl.textContent) || {}) : {}; } catch (e) { return {}; }
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  // The notice card (templates/partials/notice.php, docs/ui-notices.md)
  function notice(kind, text) {
    var card = el('div', 'wk-notice wk-notice-' + kind);
    card.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
    var i = el('i', 'ph ph-' + ({ info: 'info', ok: 'check-circle', warn: 'warning', error: 'warning-octagon' }[kind] || 'info'));
    i.setAttribute('aria-hidden', 'true');
    card.appendChild(i);
    card.appendChild(el('div', '', text));
    return card;
  }

  function api(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        return { ok: response.ok, status: response.status, json: json };
      });
    });
  }

  function errorOf(result, fallback) {
    return (result.json && result.json.error && result.json.error.message) || fallback;
  }

  function post(url, body) {
    return api(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  }

  // ---- Duplicates: keep one, fold the others into it ----

  function mountDuplicates(box) {
    var cfg = config(box);
    var s = cfg.strings || {};
    var status = box.querySelector('[data-dup-status]');
    Array.prototype.forEach.call(box.querySelectorAll('[data-dup-group]'), function (group) {
      var button = group.querySelector('[data-dup-merge]');
      button.addEventListener('click', function () {
        var radios = Array.prototype.slice.call(group.querySelectorAll('input[type="radio"]'));
        var keep = radios.filter(function (r) { return r.checked; })[0];
        if (!keep || !window.confirm(s.confirm)) return;
        var others = radios.filter(function (r) { return r !== keep; }).map(function (r) { return r.value; });
        button.disabled = true;
        status.textContent = '';
        status.appendChild(notice('info', s.merging));
        var relinked = 0;
        var signed = 0;
        // One at a time: each is a revision of the one kept
        var chain = others.reduce(function (previous, from) {
          return previous.then(function () {
            return post(cfg.basePath + '/api/v1/library/' + encodeURIComponent(keep.value) + '/merge', { from: from }).then(function (result) {
              if (!result.ok) throw new Error(errorOf(result, s.failed));
              relinked += result.json.relinked || 0;
              signed += result.json.left_signed || 0;
            });
          });
        }, Promise.resolve());
        chain.then(function () {
          status.textContent = '';
          status.appendChild(notice('ok', (s.merged || '').replace('%s', relinked).replace('%s', signed)));
          group.remove();
        }, function (error) {
          status.textContent = '';
          status.appendChild(notice('error', error.message || s.failed));
          button.disabled = false;
        });
      });
    });
  }

  // ---- Related sources: loaded when the card is opened ----

  function mountRelated(box) {
    var cfg = config(box);
    var s = cfg.strings || {};
    var list = box.querySelector('[data-related-list]');
    var loaded = false;
    box.addEventListener('toggle', function () {
      if (!box.open || loaded) return;
      loaded = true;
      list.textContent = '';
      list.appendChild(el('li', 'wk-dim', s.loading));
      api(cfg.url).then(function (result) {
        list.textContent = '';
        if (!result.ok) { list.appendChild(el('li', 'wk-dim', errorOf(result, s.failed))); loaded = false; return; }
        var rows = result.json.data || [];
        if (rows.length === 0) { list.appendChild(el('li', 'wk-dim', s.none)); return; }
        rows.forEach(function (row) {
          var item = el('li');
          var a = el('a', '', row.title);
          a.href = row.href;
          item.appendChild(a);
          var by = [row.authors, row.year].filter(Boolean).join(' ');
          var reasons = (row.why || []).map(function (w) { return s[w]; }).filter(Boolean).join(', ');
          item.appendChild(el('span', 'wk-dim', ' ' + [by, reasons].filter(Boolean).join(' · ')));
          list.appendChild(item);
        });
      }, function () { list.textContent = ''; list.appendChild(el('li', 'wk-dim', s.failed)); loaded = false; });
    });
  }

  // ---- Find full text: Unpaywall's free PDF, attached to the record ----

  function mountFulltext(button) {
    var status = document.querySelector('[data-lib-fulltext-status]');
    var s = {};
    try { s = JSON.parse(status.getAttribute('data-strings')) || {}; } catch (e) { s = {}; }
    button.addEventListener('click', function () {
      button.disabled = true;
      status.textContent = '';
      status.appendChild(notice('info', s.working));
      post(button.getAttribute('data-url')).then(function (result) {
        status.textContent = '';
        if (!result.ok) {
          status.appendChild(notice('error', errorOf(result, s.failed)));
          button.disabled = false;
          return;
        }
        var answer = result.json.status;
        if (answer === 'attached') {
          status.appendChild(notice('ok', s.attached));
          window.setTimeout(function () { window.location.reload(); }, 800);
          return;
        }
        status.appendChild(notice(answer === 'failed' ? 'error' : 'warn', s[answer] || s.failed));
        button.disabled = false;
      }, function () {
        status.textContent = '';
        status.appendChild(notice('error', s.failed));
        button.disabled = false;
      });
    });
  }

  // ---- Add by search: PubMed asked by the words typed ----

  function oneLine(csl) {
    var authors = (csl.author || []).slice(0, 3).map(function (a) { return a.family ? a.family + (a.given ? ' ' + a.given : '') : (a.literal || ''); }).filter(Boolean).join(', ');
    if ((csl.author || []).length > 3) authors += ', et al.';
    var year = csl.issued && csl.issued['date-parts'] && csl.issued['date-parts'][0] ? csl.issued['date-parts'][0][0] : '';
    var journal = csl['container-title-short'] || csl['container-title'] || '';
    return [authors, journal, year].filter(Boolean).join(' · ');
  }

  function mountOnline(box) {
    var cfg = config(box);
    var s = cfg.strings || {};
    var base = cfg.basePath || '';
    // A record just added that has a DOI: its free PDF asked of Unpaywall and attached (POST
    // /api/v1/library/{pid}/fulltext, as Find full text on the source's page), the answer beside it
    function fullText(done, text) {
      var line = el('span', 'wk-dim wk-text-sm', s.ft_working);
      text.appendChild(line);
      post(base + '/api/v1/library/' + encodeURIComponent(done.pid) + '/fulltext').then(function (result) {
        var answer = result.ok ? result.json.status : 'failed';
        if (answer === 'attached') {
          var chip = el('a', 'wk-chip wk-chip-on', s.ft_attached);
          chip.href = done.href;
          line.replaceWith(chip);
          return;
        }
        line.textContent = s['ft_' + answer] || (result.ok ? '' : errorOf(result, s.ft_failed));
      }, function () { line.textContent = s.ft_failed; });
    }
    var form = box.querySelector('[data-online-form]');
    var status = box.querySelector('[data-online-status]');
    var list = box.querySelector('[data-online-list]');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var q = form.elements.q.value.trim();
      if (!q) return;
      list.textContent = '';
      status.textContent = '';
      status.appendChild(notice('info', s.searching));
      api(base + '/api/v1/library/online?q=' + encodeURIComponent(q)).then(function (result) {
        status.textContent = '';
        if (!result.ok) { status.appendChild(notice('error', errorOf(result, s.failed))); return; }
        var rows = result.json.data || [];
        if (rows.length === 0) { status.appendChild(notice('info', s.none)); return; }
        rows.forEach(function (row) {
          var item = el('li', 'wk-lib-online-row');
          var text = el('div');
          text.appendChild(el('strong', '', row.csl.title || ''));
          text.appendChild(el('div', 'wk-dim wk-text-sm', oneLine(row.csl)));
          var button = el('button', 'btn btn-secondary btn-sm', s.add);
          button.type = 'button';
          if (row.in_library) {
            var have = el('a', 'wk-chip', row.in_library.by === 'title' ? s.by_title : s.have);
            have.href = row.in_library.href;
            text.appendChild(have);
            button.hidden = row.in_library.by !== 'title';
          }
          button.addEventListener('click', function () {
            button.disabled = true;
            button.textContent = s.adding;
            post(base + '/api/v1/library/online', { records: [row.csl] }).then(function (added) {
              if (!added.ok) { button.disabled = false; button.textContent = s.add; status.textContent = ''; status.appendChild(notice('error', errorOf(added, s.failed))); return; }
              var done = (added.json.added || [])[0];
              button.remove();
              if (done) {
                var open = el('a', 'wk-chip', s.added);
                open.href = done.href;
                text.appendChild(open);
                if (cfg.fullText && row.csl.DOI) fullText(done, text);
              } else {
                text.appendChild(el('span', 'wk-dim wk-text-sm', s.skipped));
              }
            }, function () { button.disabled = false; button.textContent = s.add; status.textContent = ''; status.appendChild(notice('error', s.failed)); });
          });
          item.appendChild(text);
          item.appendChild(button);
          list.appendChild(item);
        });
      }, function () { status.textContent = ''; status.appendChild(notice('error', s.failed)); });
    });
  }

  var TOOLS = { duplicates: mountDuplicates, related: mountRelated, online: mountOnline };

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="library-tools"]'), function (box) {
      var mount = TOOLS[box.getAttribute('data-tool')];
      if (mount) mount(box);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-lib-fulltext]'), mountFulltext);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
