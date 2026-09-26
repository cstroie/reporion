/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor island — local drafts, save on request, image paste, and the
 * formatting toolbar (phase 10).
 * Mounts on every [data-island="editor"] form on the page.
 *
 * Degrades cleanly: without JS the form is a plain POST to the SSR save
 * route, which also handles conflicts (the stale-base_rev panel).
 *
 * A revision is written only when the user saves — the Save button or
 * Ctrl+S, both the form's own POST (decided 2026-09-26: autosaving to the
 * server wrote a revision every few seconds of typing, 21 on one page).
 * What autosaves is a local draft: a second after typing stops, the
 * document goes to IndexedDB with the revision it was made on, so a
 * crash or a dropped VPN loses nothing (D25). Leaving with unsaved
 * changes asks first.
 *
 * A draft is offered back — never applied by itself — only when it was
 * made on the revision now being edited. Any other draft is stale (the
 * page has moved on: saved, reverted, edited elsewhere) and is dropped:
 * applying it silently used to put an old text back over a newer one.
 */
(function () {
  'use strict';

  var DRAFT_DELAY_MS = 1000;
  var DB_NAME = 'reporion-editor';
  var DB_STORE = 'drafts';
  var DB_VERSION = 1;

  function openDB() {
    return new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        req.result.createObjectStore(DB_STORE, { keyPath: 'path' });
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  function dbPut(db, draft) {
    return new Promise(function (resolve, reject) {
      var tx = db.transaction(DB_STORE, 'readwrite');
      tx.objectStore(DB_STORE).put(draft);
      tx.oncomplete = function () { resolve(); };
      tx.onerror = function () { reject(tx.error); };
    });
  }

  function dbGet(db, path) {
    return new Promise(function (resolve, reject) {
      var tx = db.transaction(DB_STORE, 'readonly');
      var req = tx.objectStore(DB_STORE).get(path);
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  function dbDelete(db, path) {
    return new Promise(function (resolve, reject) {
      var tx = db.transaction(DB_STORE, 'readwrite');
      tx.objectStore(DB_STORE).delete(path);
      tx.oncomplete = function () { resolve(); };
      tx.onerror = function () { reject(tx.error); };
    });
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function mount(form) {
    var configEl = document.getElementById(form.getAttribute('data-config-id'));
    var config = {};
    try {
      config = configEl ? JSON.parse(configEl.textContent) : {};
    } catch (e) {
      return;
    }
    var basePath = typeof config.basePath === 'string' ? config.basePath : '';
    var path = typeof config.path === 'string' ? config.path : '';
    var baseRev = typeof config.baseRev === 'number' ? config.baseRev : 0;
    var s = config.strings || {};

    var textarea = form.querySelector('textarea[name="document"]');
    var statusEl = document.getElementById('editor-status');
    var draftBanner = document.getElementById('editor-draft-banner');
    var draftDismiss = document.getElementById('editor-draft-dismiss');

    if (!textarea || !path) return;

    var db = null;
    var timer = null;
    var submitting = false;
    var savedDoc = textarea.value;
    var restoreBtn = document.getElementById('editor-draft-restore');
    var draftWhen = document.getElementById('editor-draft-when');
    var offered = null;

    function setStatus(html) {
      if (statusEl) statusEl.innerHTML = html;
    }

    function showStatus() {
      if (textarea.value === savedDoc) {
        setStatus('<span data-editor-status="saved">' + esc(s.saved) + '</span>');
      } else {
        setStatus('<span data-editor-status="unsaved">' + esc(s.unsaved) + '</span>');
      }
    }

    openDB().then(function (d) {
      db = d;
      return dbGet(db, path);
    }).then(function (draft) {
      if (!draft) return;
      if (draft.baseRev !== baseRev || draft.doc === textarea.value) {
        // Stale (made on another revision) or nothing to restore
        return dbDelete(db, path);
      }
      offered = draft;
      if (draftWhen) draftWhen.textContent = new Date(draft.ts).toLocaleString();
      if (draftBanner) draftBanner.hidden = false;
    }).catch(function () {
      /* IndexedDB unavailable — the unsaved-changes warning still protects the text */
    });

    if (restoreBtn) {
      restoreBtn.addEventListener('click', function () {
        if (offered) {
          textarea.value = offered.doc;
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (draftBanner) draftBanner.hidden = true;
      });
    }
    if (draftDismiss) {
      draftDismiss.addEventListener('click', function () {
        offered = null;
        if (db) dbDelete(db, path).catch(function () {});
        if (draftBanner) draftBanner.hidden = true;
      });
    }

    // The local draft: kept while the text differs from what was saved, gone when it does not
    function keepDraft() {
      if (!db) return;
      if (textarea.value === savedDoc) {
        dbDelete(db, path).catch(function () {});
      } else {
        dbPut(db, { path: path, doc: textarea.value, baseRev: baseRev, ts: Date.now() }).catch(function () {});
      }
    }

    function scheduleDraft() {
      showStatus();
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(keepDraft, DRAFT_DELAY_MS);
    }

    // Ctrl+S / Cmd+S saves (the form's own POST, as the Save button does)
    document.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S')) {
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });

    form.addEventListener('submit', function () {
      // The draft stays until the save is confirmed: once the page is at a
      // newer revision it is stale and dropped on the next visit; if the
      // save failed, it is offered back
      if (timer) window.clearTimeout(timer);
      keepDraft();
      submitting = true;
    });

    window.addEventListener('beforeunload', function (event) {
      if (!submitting && textarea.value !== savedDoc) {
        event.preventDefault();
        event.returnValue = '';
      }
    });

    textarea.addEventListener('input', scheduleDraft);

    // Images by clipboard paste or file drag (D27): each is uploaded and
    // attached to the page, and a placeholder at the cursor becomes its
    // markdown. Text pastes are left alone — external dictation keeps
    // typing into this textarea (D24).
    var uploads = 0;

    function imageFiles(list) {
      return Array.prototype.filter.call(list || [], function (file) {
        return /^image\/(png|jpeg|gif|webp)$/.test(file.type);
      });
    }

    function replaceText(from, to) {
      var at = textarea.value.indexOf(from);
      if (at === -1) return;
      textarea.setRangeText(to, at, at + from.length, 'preserve');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function upload(file) {
      var placeholder = '![' + s.mediaUploading + '](#upload-' + (++uploads) + ')';
      // A paragraph of its own: a blank line before and after
      var before = textarea.value.slice(0, textarea.selectionStart);
      var after = textarea.value.slice(textarea.selectionEnd);
      var prefix = before === '' || /\n\n$/.test(before) ? '' : (/\n$/.test(before) ? '\n' : '\n\n');
      var suffix = /^\n\n/.test(after) ? '' : (/^\n/.test(after) ? '\n' : '\n\n');
      textarea.setRangeText(prefix + placeholder + suffix, textarea.selectionStart, textarea.selectionEnd, 'end');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));

      var url = basePath + '/api/v1/media?page=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(file.name || 'image');
      fetch(url, { method: 'POST', body: file, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (response) {
          return response.json().then(function (json) { return { ok: response.ok, json: json }; });
        })
        .then(function (result) {
          if (result.ok && result.json && typeof result.json.markdown === 'string') {
            replaceText(placeholder, result.json.markdown);
            return;
          }
          replaceText(placeholder, '');
          var message = result.json && result.json.error ? result.json.error.message : s.mediaFailed;
          setStatus('<span data-editor-status="error">' + esc(message) + '</span>');
        })
        .catch(function () {
          replaceText(placeholder, '');
          setStatus('<span data-editor-status="error">' + esc(s.mediaFailed) + '</span>');
        });
    }

    textarea.addEventListener('paste', function (event) {
      var files = imageFiles(event.clipboardData && event.clipboardData.files);
      if (files.length === 0) return;
      event.preventDefault();
      files.forEach(upload);
    });
    textarea.addEventListener('dragover', function (event) {
      if (event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1) {
        event.preventDefault();
      }
    });
    textarea.addEventListener('drop', function (event) {
      var files = imageFiles(event.dataTransfer && event.dataTransfer.files);
      if (files.length === 0) return;
      event.preventDefault();
      textarea.focus();
      files.forEach(upload);
    });

    // The formatting toolbar (phase 10). Each button turns the document
    // and selection into one edit (assets/js/editor-format.js), applied
    // through execCommand so the browser's undo keeps it; the input event
    // that follows reaches the local draft like typing does. Only Ctrl+B /
    // Ctrl+I are bound — dictation typing into the textarea is untouched.
    var F = window.ReporionEditorFormat;
    var toolbar = document.getElementById('editor-toolbar');
    var charsEl = document.getElementById('editor-chars');
    var imageInput = document.getElementById('editor-image-file');
    var picker = null;

    function showChars() {
      if (charsEl && s.chars) charsEl.textContent = s.chars.replace('%d', String(textarea.value.length));
    }

    function applyEdit(e) {
      var before = textarea.value;
      textarea.focus();
      textarea.setSelectionRange(e.from, e.to);
      var done = false;
      try {
        done = e.insert === '' ? document.execCommand('delete') : document.execCommand('insertText', false, e.insert);
      } catch (err) {
        done = false;
      }
      if (!done || textarea.value === before) {
        textarea.setRangeText(e.insert, e.from, e.to, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (e.selStart !== null && e.selStart !== undefined) {
        textarea.setSelectionRange(e.selStart, e.selEnd);
      }
    }

    function closePicker(refocus) {
      if (!picker) return;
      picker.remove();
      picker = null;
      if (refocus) textarea.focus();
    }

    /*
     * A small list under the toolbar with a filter box: source(query, show)
     * calls show([{ title, meta, value }]); choosing one calls choose(value).
     */
    function openPicker(source, choose, initialQuery) {
      closePicker(false);
      var box = document.createElement('div');
      box.className = 'wk-pal-drop';
      box.setAttribute('role', 'dialog');
      var input = document.createElement('input');
      input.className = 'input';
      input.type = 'search';
      input.placeholder = s.filter || '';
      input.value = initialQuery || '';
      input.setAttribute('autocomplete', 'off');
      var listEl = document.createElement('div');
      listEl.setAttribute('role', 'listbox');
      box.appendChild(input);
      box.appendChild(listEl);
      toolbar.appendChild(box);
      picker = box;

      var items = [];
      var active = 0;

      function render() {
        listEl.innerHTML = '';
        if (items.length === 0) {
          var none = document.createElement('div');
          none.className = 'wk-pal-row wk-dim';
          none.textContent = s.noMatch || '';
          listEl.appendChild(none);
          return;
        }
        items.forEach(function (item, index) {
          var row = document.createElement('div');
          row.className = 'wk-pal-row' + (index === active ? ' wk-sel' : '');
          row.setAttribute('role', 'option');
          var t = document.createElement('div');
          t.className = 'wk-row-t';
          t.textContent = item.title;
          row.appendChild(t);
          if (item.meta) {
            var m = document.createElement('div');
            m.className = 'wk-row-m wk-mono';
            m.textContent = item.meta;
            row.appendChild(m);
          }
          row.addEventListener('mousedown', function (event) {
            event.preventDefault();
            pick(index);
          });
          listEl.appendChild(row);
        });
      }

      function show(list) {
        if (picker !== box) return;
        items = list;
        active = 0;
        render();
      }

      function pick(index) {
        var item = items[index];
        closePicker(true);
        if (item) choose(item.value);
      }

      input.addEventListener('input', function () { source(input.value, show); });
      input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          if (items.length === 0) return;
          active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
          render();
        } else if (event.key === 'Enter') {
          event.preventDefault();
          pick(active);
        } else if (event.key === 'Escape') {
          event.preventDefault();
          closePicker(true);
        }
      });
      source(input.value, show);
      input.focus();
    }

    document.addEventListener('mousedown', function (event) {
      if (picker && !picker.contains(event.target)) closePicker(false);
    });

    function localSource(all) {
      return function (query, show) {
        var q = query.trim().toLowerCase();
        show(all.filter(function (item) {
          return q === '' || (item.title + ' ' + (item.meta || '')).toLowerCase().indexOf(q) !== -1;
        }));
      };
    }

    var searchTimer = null;
    var searchSeq = 0;
    function searchSource(query, show) {
      if (searchTimer) window.clearTimeout(searchTimer);
      var q = query.trim();
      if (q === '') {
        show([]);
        return;
      }
      var seq = ++searchSeq;
      searchTimer = window.setTimeout(function () {
        fetch(basePath + '/api/v1/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (response) { return response.ok ? response.json() : { data: [] }; })
          .then(function (json) {
            if (seq !== searchSeq) return;
            show((json.data || []).slice(0, 20).map(function (row) {
              return { title: row.title || row.path, meta: row.path, value: row };
            }));
          })
          .catch(function () { show([]); });
      }, 200);
    }

    function selection() {
      return [textarea.selectionStart, textarea.selectionEnd];
    }

    function copyText(text) {
      function fallback() {
        var tmp = document.createElement('textarea');
        tmp.value = text;
        tmp.setAttribute('readonly', '');
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        tmp.remove();
        textarea.focus();
        return ok;
      }
      function report(ok) {
        setStatus('<span data-editor-status="' + (ok ? 'saved' : 'error') + '">' + esc(ok ? s.copied : s.copyFailed) + '</span>');
      }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () { report(true); }, function () { report(fallback()); });
      } else {
        report(fallback());
      }
    }

    var actions = {
      heading: function (v, a, b) { applyEdit(F.heading(v, a, b)); },
      bold: function (v, a, b) { applyEdit(F.toggleWrap(v, a, b, '**')); },
      italic: function (v, a, b) { applyEdit(F.toggleWrap(v, a, b, '*')); },
      bullets: function (v, a, b) { applyEdit(F.list(v, a, b, 'bullet')); },
      numbers: function (v, a, b) { applyEdit(F.list(v, a, b, 'number')); },
      table: function (v, a, b) { applyEdit(F.table(v, a, b, [s.column || 'Column', s.column || 'Column'])); },
      code: function (v, a, b) { applyEdit(F.toggleWrap(v, a, b, '`')); },
      link: function (v, a, b) {
        var selected = v.slice(a, b);
        openPicker(searchSource, function (row) {
          applyEdit(F.link(textarea.value, a, b, row.path, row.title || row.path));
        }, selected.indexOf('\n') === -1 ? selected.trim() : '');
      },
      image: function () {
        if (imageInput) imageInput.click();
      },
      prior: function (v, a, b) {
        var priors = Array.isArray(config.priors) ? config.priors : [];
        openPicker(localSource(priors.map(function (p) {
          return { title: p.label + (p.date ? ', ' + p.date : ''), meta: p.modality, value: p };
        })), function (p) {
          var current = textarea.value;
          var linkEdit = F.link(current, a, b, p.path, p.label + (p.date ? ', ' + p.date : ''));
          var priorEdit = F.addPrior(current, p.path);
          applyEdit(linkEdit);
          if (priorEdit && priorEdit.unknown) {
            setStatus('<span data-editor-status="error">' + esc(s.priorUnknown) + '</span>');
          } else if (priorEdit) {
            // The frontmatter is before the cursor: its edit shifts the caret
            applyEdit(priorEdit);
            var caret = linkEdit.selStart + priorEdit.insert.length - (priorEdit.to - priorEdit.from);
            textarea.setSelectionRange(caret, caret);
          }
        });
      },
      template: function (v, a, b) {
        var templates = Array.isArray(config.templates) ? config.templates.slice() : [];
        var own = typeof config.template === 'string' ? config.template : '';
        templates.sort(function (x, y) { return (y.path === own) - (x.path === own); });
        openPicker(localSource(templates.map(function (t) {
          return { title: t.title, meta: t.path, value: t };
        })), function (t) {
          fetch(basePath + '/api/v1/pages/' + t.path + '?render=0', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (json) {
              if (!json || typeof json.body !== 'string') throw new Error('template');
              applyEdit(F.insertBlock(textarea.value, a, b, F.templateBody(json.body)));
            })
            .catch(function () {
              setStatus('<span data-editor-status="error">' + esc(s.templateFailed) + '</span>');
            });
        });
      },
      copy: function (v) { copyText(F.bodyOf(v)); }
    };

    if (F && toolbar) {
      toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tb]');
        if (!button) return;
        var action = actions[button.getAttribute('data-tb')];
        if (!action) return;
        var sel = selection();
        action(textarea.value, sel[0], sel[1]);
      });
      textarea.addEventListener('keydown', function (event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return;
        var key = event.key.toLowerCase();
        if (key !== 'b' && key !== 'i') return;
        event.preventDefault();
        var sel = selection();
        actions[key === 'b' ? 'bold' : 'italic'](textarea.value, sel[0], sel[1]);
      });
      if (imageInput) {
        imageInput.addEventListener('change', function () {
          textarea.focus();
          imageFiles(imageInput.files).forEach(upload);
          imageInput.value = '';
        });
      }
      textarea.addEventListener('input', showChars);
    }

    showStatus();
  }

  var forms = document.querySelectorAll('[data-island="editor"]');
  for (var i = 0; i < forms.length; i++) {
    mount(forms[i]);
  }
})();
