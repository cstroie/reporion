/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The library's screens (docs/library.md, phase 2). Three islands:
 *
 * - library-add (GET /library, an editor): files dropped or chosen are sent one at a time, each
 *   as the request body of POST /api/v1/library/uploads; its proposed record fills a copy of the
 *   record form (templates/partials/library-record-form.php) to correct, and Add sends it with
 *   the token to POST /api/v1/library. Discard keeps nothing (the upload expires on the server).
 * - library-list: the text filter over the table's rows (the sort and the tag are links).
 * - library-record (a source's page): Copy reference, and for a writer Edit record, the same
 *   form filled from the record, saved by PUT /api/v1/library/{pid}/record, then the page reloads.
 *
 * The record's fields the form does not show (abstract, ISSN, a month…) are kept: the form's
 * values are laid over the record it started from.
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

  function icon(name) {
    var i = el('i', 'ph ph-' + name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  // The notice card (templates/partials/notice.php, docs/ui-notices.md)
  function notice(kind, text, link) {
    var card = el('div', 'wk-notice wk-notice-' + kind);
    card.setAttribute('role', kind === 'error' || kind === 'warn' ? 'alert' : 'status');
    card.appendChild(icon({ info: 'info', ok: 'check-circle', warn: 'warning', error: 'warning-octagon' }[kind] || 'info'));
    var body = el('div', '', text);
    if (link) {
      body.appendChild(document.createTextNode(' '));
      var a = el('a', '', link[1]);
      a.href = link[0];
      body.appendChild(a);
    }
    card.appendChild(body);
    return card;
  }

  function api(url, options) {
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

  // ---- The record form: a CSL-JSON record in and out ----

  function authorsText(authors) {
    return (authors || []).map(function (a) {
      if (a.family) return a.family + (a.given ? ', ' + a.given : '');
      return a.literal || '';
    }).filter(Boolean).join('\n');
  }

  function authorsOf(text) {
    return text.split('\n').map(function (line) { return line.trim(); }).filter(Boolean).map(function (line) {
      var at = line.indexOf(',');
      if (at < 0) return { literal: line };
      var family = line.slice(0, at).trim();
      var given = line.slice(at + 1).trim();
      return given ? { family: family, given: given } : { family: family };
    });
  }

  var TEXT_FIELDS = ['title', 'type', 'container-title', 'volume', 'issue', 'page', 'DOI', 'PMID'];

  function fill(form, csl, extra) {
    TEXT_FIELDS.forEach(function (name) {
      var input = form.elements[name];
      if (!input) return;
      var value = csl[name] !== undefined && csl[name] !== null ? String(csl[name]) : '';
      if (name === 'type' && !value) value = 'article-journal';
      input.value = value;
    });
    form.elements.authors.value = authorsText(csl.author);
    var year = csl.issued && csl.issued['date-parts'] && csl.issued['date-parts'][0] ? csl.issued['date-parts'][0][0] : '';
    form.elements.year.value = year ? String(year) : '';
    if (form.elements.key && extra.key !== undefined) form.elements.key.value = extra.key;
    if (form.elements.tags) form.elements.tags.value = (extra.tags || []).join(', ');
  }

  // The form's values laid over the record it started from: fields it does not show are kept
  function read(form, start) {
    var csl = JSON.parse(JSON.stringify(start || {}));
    TEXT_FIELDS.forEach(function (name) {
      var input = form.elements[name];
      if (!input) return;
      var value = input.value.trim();
      if (value) csl[name] = value; else delete csl[name];
    });
    var authors = authorsOf(form.elements.authors.value);
    if (authors.length) csl.author = authors; else delete csl.author;
    var year = form.elements.year.value.trim();
    var parts = csl.issued && csl.issued['date-parts'] && csl.issued['date-parts'][0];
    if (/^\d{4}$/.test(year)) {
      // The month and day stay when the year is the one they belong to
      csl.issued = { 'date-parts': [parts && String(parts[0]) === year ? parts : [parseInt(year, 10)]] };
    } else {
      delete csl.issued;
    }
    var tags = form.elements.tags ? form.elements.tags.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean) : [];
    return { csl: csl, tags: tags, key: form.elements.key ? form.elements.key.value.trim() : '' };
  }

  function formFrom(box) {
    var template = box.querySelector('template[data-lib-form-template]');
    return template ? template.content.firstElementChild.cloneNode(true) : null;
  }

  function busy(form, on) {
    Array.prototype.forEach.call(form.querySelectorAll('button'), function (b) { b.disabled = on; });
  }

  // ---- library-add ----

  function mountAdd(box) {
    var cfg = config(box);
    var s = cfg.strings || {};
    var base = cfg.basePath || '';
    var input = box.querySelector('[data-lib-files]');
    var drop = box.querySelector('[data-lib-drop]');
    var queue = box.querySelector('[data-lib-queue]');
    var opener = document.querySelector('[data-lib-open]');
    var extensions = cfg.extensions || [];
    var waiting = [];
    var running = false;

    if (opener) {
      opener.addEventListener('click', function () {
        box.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        input.click();
      });
    }

    function card(file) {
      var item = el('article', 'wk-lib-item');
      var head = el('header', 'wk-lib-item-h');
      head.appendChild(icon('file'));
      head.appendChild(el('span', 'wk-lib-item-name', file.name));
      var meta = el('span', 'wk-dim wk-text-xs wk-mono', Math.max(1, Math.round(file.size / 1024)) + ' KB');
      head.appendChild(meta);
      item.appendChild(head);
      var status = el('div', 'wk-lib-item-status');
      status.setAttribute('aria-live', 'polite');
      item.appendChild(status);
      queue.appendChild(item);
      return { item: item, head: head, meta: meta, status: status };
    }

    function say(slot, kind, text, link) {
      slot.status.textContent = '';
      if (text) slot.status.appendChild(notice(kind, text, link));
    }

    function enqueue(files) {
      Array.prototype.forEach.call(files, function (file) {
        var slot = card(file);
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        // A reference manager's export (BibTeX, RIS, CSL-JSON): records with no file
        var records = file.name.indexOf('.') >= 0 && (cfg.importExtensions || []).indexOf(ext) >= 0;
        if (!records && (extensions.indexOf(ext) < 0 || file.name.indexOf('.') < 0)) { say(slot, 'error', s.unsupported); return; }
        if (cfg.maxBytes && file.size > cfg.maxBytes) { say(slot, 'error', s.too_large); return; }
        say(slot, 'info', records ? s.importing : s.reading);
        waiting.push({ file: file, slot: slot, records: records });
      });
      next();
    }

    // One file at a time: the server reads each with external programs
    function next() {
      if (running || !waiting.length) return;
      running = true;
      var job = waiting.shift();
      if (job.records) {
        api(base + '/api/v1/library/import', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: job.file }).then(function (result) {
          if (result.status === 200 || result.status === 201) {
            var added = (result.json.added || []).length;
            var skipped = (result.json.skipped || []).length;
            say(job.slot, 'ok', (s.imported || '%s / %s').replace('%s', added).replace('%s', skipped));
          } else {
            say(job.slot, 'error', errorOf(result, s.failed));
          }
        }, function () {
          say(job.slot, 'error', s.failed);
        }).then(function () {
          running = false;
          next();
        });
        return;
      }
      api(base + '/api/v1/library/uploads?name=' + encodeURIComponent(job.file.name), {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream' },
        body: job.file
      }).then(function (result) {
        if (result.status === 201) propose(job.slot, result.json);
        else say(job.slot, 'error', errorOf(result, s.failed));
      }, function () {
        say(job.slot, 'error', s.failed);
      }).then(function () {
        running = false;
        next();
      });
    }

    function propose(slot, proposal) {
      slot.status.textContent = '';
      slot.meta.textContent = slot.meta.textContent + (proposal.pages > 1 ? ' · ' + (s.pages || '%s').replace('%s', proposal.pages) : '');
      var form = formFrom(box);
      if (!form) return;
      fill(form, proposal.csl || {}, { key: proposal.key || '', tags: proposal.tags || [] });
      var dup = form.querySelector('[data-lib-dup]');
      var blocking = false;
      if (proposal.duplicate) {
        // A record kept with no file takes this file: nothing to refuse
        var attach = proposal.duplicate.has_original === false && (proposal.duplicate.by === 'doi' || proposal.duplicate.by === 'pmid');
        blocking = proposal.duplicate.by !== 'title' && !attach;
        dup.appendChild(notice(blocking ? 'warn' : 'info', attach ? s.attach : (blocking ? s.duplicate : s.duplicate_title),
          [base + '/' + proposal.duplicate.path, proposal.duplicate.title || proposal.duplicate.path]));
      }
      // What the online lookup did (Admin → Settings → Library), if it is on
      if (proposal.lookup === 'failed' || proposal.lookup === 'none') {
        dup.appendChild(notice('info', proposal.lookup === 'failed' ? s.lookup_failed : s.lookup_none));
      } else if (proposal.lookup) {
        dup.appendChild(notice('info', (s.lookup_done || '%s').replace('%s', proposal.lookup.replace('+', ' + ').replace('crossref', 'CrossRef').replace('pubmed', 'PubMed'))));
      }
      var preview = form.querySelector('[data-lib-preview]');
      if (preview && proposal.preview) {
        preview.hidden = false;
        preview.querySelector('[data-lib-preview-text]').textContent = proposal.preview;
      }
      var status = form.querySelector('[data-lib-status]');
      var submit = form.querySelector('[data-lib-submit]');
      if (blocking) submit.hidden = true;
      form.querySelector('[data-lib-cancel]').addEventListener('click', function () {
        form.remove();
        say(slot, 'info', s.discarded);
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var record = read(form, proposal.csl);
        status.textContent = '';
        if (!record.csl.title) { status.appendChild(notice('error', s.title_needed)); form.elements.title.focus(); return; }
        status.appendChild(notice('info', s.adding));
        busy(form, true);
        var body = { token: proposal.token, csl: record.csl, tags: record.tags };
        if (record.key && record.key !== proposal.key) body.key = record.key;
        api(base + '/api/v1/library', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        }).then(function (result) {
          busy(form, false);
          status.textContent = '';
          if (result.status === 201) {
            form.remove();
            say(slot, 'ok', s.added, [base + '/' + result.json.path, s.open]);
            return;
          }
          var fields = result.json && result.json.error && result.json.error.fields;
          status.appendChild(notice('error', errorOf(result, s.failed), fields && fields.path ? [base + '/' + fields.path, fields.path] : null));
        }, function () {
          busy(form, false);
          status.textContent = '';
          status.appendChild(notice('error', s.failed));
        });
      });
      slot.item.appendChild(form);
      form.elements.title.focus();
    }

    input.addEventListener('change', function () {
      if (input.files && input.files.length) enqueue(input.files);
      input.value = '';
    });
    ['dragenter', 'dragover'].forEach(function (type) {
      drop.addEventListener(type, function (event) {
        if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') < 0) return;
        event.preventDefault();
        drop.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      drop.addEventListener(type, function () { drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (event) {
      if (!event.dataTransfer || !event.dataTransfer.files.length) return;
      event.preventDefault();
      enqueue(event.dataTransfer.files);
    });
  }

  // ---- library-list ----

  function mountList(box) {
    var filter = box.querySelector('[data-lib-filter]');
    var count = box.querySelector('[data-lib-count]');
    var none = box.querySelector('[data-lib-none]');
    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-lib-row]'));
    if (!filter) return;
    var fold = function (text) { return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
    var texts = rows.map(function (row) { return fold(row.textContent); });
    filter.addEventListener('input', function () {
      var words = fold(filter.value).split(/\s+/).filter(Boolean);
      var shown = 0;
      rows.forEach(function (row, i) {
        var hit = words.every(function (w) { return texts[i].indexOf(w) >= 0; });
        row.hidden = !hit;
        if (hit) shown++;
      });
      if (count) count.textContent = String(shown);
      if (none) none.hidden = shown > 0;
    });
  }

  // ---- library-record ----

  function mountRecord(box) {
    var cfg = config(box);
    var s = cfg.strings || {};
    var copy = box.querySelector('[data-lib-copy]');
    var ref = box.querySelector('[data-lib-ref]');
    if (copy && ref && navigator.clipboard) {
      copy.addEventListener('click', function () {
        navigator.clipboard.writeText(ref.textContent.trim()).then(function () {
          copy.setAttribute('title', s.copied || '');
          copy.classList.add('is-done');
          setTimeout(function () { copy.classList.remove('is-done'); }, 1500);
        });
      });
    }
    var edit = box.querySelector('[data-lib-edit]');
    var host = box.querySelector('[data-lib-edit-host]');
    if (!edit || !host) return;
    edit.addEventListener('click', function () {
      if (!host.hidden) return;
      var form = formFrom(box);
      if (!form) return;
      fill(form, cfg.csl || {}, { tags: cfg.tags || [] });
      var status = form.querySelector('[data-lib-status]');
      form.querySelector('[data-lib-cancel]').addEventListener('click', function () {
        host.textContent = '';
        host.hidden = true;
        edit.hidden = false;
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var record = read(form, cfg.csl);
        status.textContent = '';
        if (!record.csl.title) { status.appendChild(notice('error', s.title_needed)); form.elements.title.focus(); return; }
        status.appendChild(notice('info', s.saving));
        busy(form, true);
        api((cfg.basePath || '') + '/api/v1/library/' + encodeURIComponent(cfg.pid) + '/record', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csl: record.csl, tags: record.tags, base_rev: cfg.rev })
        }).then(function (result) {
          if (result.ok) { window.location.reload(); return; }
          busy(form, false);
          status.textContent = '';
          var fields = result.json && result.json.error && result.json.error.fields;
          status.appendChild(notice('error', errorOf(result, s.failed), fields && fields.path ? [(cfg.basePath || '') + '/' + fields.path, fields.path] : null));
        }, function () {
          busy(form, false);
          status.textContent = '';
          status.appendChild(notice('error', s.failed));
        });
      });
      host.appendChild(form);
      host.hidden = false;
      edit.hidden = true;
      form.elements.title.focus();
    });
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="library-add"]'), mountAdd);
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="library-list"]'), mountList);
    Array.prototype.forEach.call(document.querySelectorAll('[data-island="library-record"]'), mountRecord);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
