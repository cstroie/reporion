/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor island — local drafts, save on request, image paste.
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

    showStatus();
  }

  var forms = document.querySelectorAll('[data-island="editor"]');
  for (var i = 0; i < forms.length; i++) {
    mount(forms[i]);
  }
})();
