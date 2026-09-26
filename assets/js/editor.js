/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor island — autosave, IndexedDB draft, 409 conflict flow.
 * Mounts on every [data-island="editor"] form on the page.
 *
 * Degrades cleanly: without JS the form is a plain POST to the
 * SSR save route — exactly what the page works with today.
 *
 * Autosave strategy: debounce 3 s after the last keystroke, PUT
 * the whole document (frontmatter + body) to PUT /api/v1/pages/{path}
 * with the current base_rev. On 200 the new rev is recorded and the
 * IndexedDB draft is cleared. On 409 the conflict panel is shown;
 * the user reconciles in the textarea and saves again (force save
 * with the server's newer base_rev). Drafts survive connection drops
 * (D25) and browser crashes — IndexedDB is per-origin, not per-tab.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 3000;
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
    var conflictEl = document.getElementById('editor-conflict');

    if (!textarea || !path) return;

    var db = null;
    var saving = false;
    var timer = null;
    var lastSavedRev = baseRev;
    var lastSavedDoc = textarea.value;
    var isOnline = navigator.onLine;

    openDB().then(function (d) {
      db = d;
      return dbGet(db, path);
    }).then(function (draft) {
      if (draft && draft.doc !== textarea.value) {
        textarea.value = draft.doc;
        if (draftBanner) {
          draftBanner.hidden = false;
        }
      }
    }).catch(function () {
      /* IndexedDB unavailable — proceed without draft persistence */
    });

    if (draftDismiss) {
      draftDismiss.addEventListener('click', function () {
        if (draftBanner) draftBanner.hidden = true;
      });
    }

    function setStatus(html) {
      if (statusEl) statusEl.innerHTML = html;
    }

    function scheduleSave() {
      if (timer) window.clearTimeout(timer);
      if (!isOnline) {
        setStatus('<span data-editor-status="offline">' + esc(s.offline) + '</span>');
        return;
      }
      setStatus('<span data-editor-status="draft">' + esc(s.draft) + '</span>');
      timer = window.setTimeout(save, DEBOUNCE_MS);
    }

    function save() {
      if (saving || !isOnline) return;
      var doc = textarea.value;
      if (doc === lastSavedDoc) {
        setStatus('<span data-editor-status="saved">' + esc(s.saved) + '</span>');
        return;
      }
      saving = true;
      setStatus('<span data-editor-status="saving">' + esc(s.saving) + '</span>');

      // The whole document, parsed as YAML on the server — never split
      // into meta here: a line-by-line split flattened nested frontmatter
      // (patient) and emptied every list (modality, region, tags)
      fetch(basePath + '/api/v1/pages/' + encodeURIComponent(path), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ document: doc, base_rev: lastSavedRev })
      }).then(function (response) {
        if (response.status === 422) {
          // Frontmatter that does not parse yet (mid-edit): nothing is saved,
          // the draft stays in IndexedDB, and the next keystroke tries again
          return response.json().then(function (json) {
            var message = json && json.error ? json.error.message : s.offline;
            setStatus('<span data-editor-status="error" style="color:var(--color-error);">' + esc(message) + '</span>');
            return null;
          });
        }
        if (response.status === 409) {
          return response.json().then(function (json) {
            saving = false;
            if (conflictEl) conflictEl.hidden = false;
            setStatus('<span data-editor-status="conflict" style="color:var(--color-error);">' + esc(s.conflictTitle) + '</span>');
            /* Update the hidden base_rev so the next save uses the new rev */
            var baseInput = form.querySelector('input[name="base_rev"]');
            if (baseInput && json.current && typeof json.current.rev === 'number') {
              baseInput.value = json.current.rev;
              lastSavedRev = json.current.rev;
            }
            /* Store the submitted doc as a draft so it isn't lost */
            return dbPut(db, { path: path, doc: doc, rev: lastSavedRev, ts: Date.now() });
          });
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      }).then(function (json) {
        if (!json || !json.rev) return;
        lastSavedRev = json.rev;
        lastSavedDoc = textarea.value;
        /* Update the hidden base_rev for the next save */
        var baseInput = form.querySelector('input[name="base_rev"]');
        if (baseInput) baseInput.value = lastSavedRev;
        /* Clear the draft */
        if (db) dbDelete(db, path).catch(function () {});
        setStatus('<span data-editor-status="saved">' + esc(s.saved) + ' (rev ' + lastSavedRev + ')</span>');
        /* Hide conflict panel on successful save after conflict */
        if (conflictEl) conflictEl.hidden = true;
      }).catch(function () {
        /* Network error — store draft locally */
      }).finally(function () {
        saving = false;
        /* Persist current doc to IndexedDB in case the user navigates away */
        if (db) {
          dbPut(db, { path: path, doc: textarea.value, rev: lastSavedRev, ts: Date.now() }).catch(function () {});
        }
      });
    }

    textarea.addEventListener('input', scheduleSave);

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

    window.addEventListener('beforeunload', function () {
      if (db && textarea.value !== lastSavedDoc) {
        try {
          localStorage.setItem('reporion-draft-' + path, textarea.value);
        } catch (e) {
          /* localStorage full or unavailable — ignore */
        }
      }
    });

    /* Restore from localStorage if IndexedDB didn't have a draft */
    if (db) {
      dbGet(db, path).then(function (draft) {
        if (!draft && localStorage.getItem('reporion-draft-' + path)) {
          textarea.value = localStorage.getItem('reporion-draft-' + path);
          if (draftBanner) draftBanner.hidden = false;
          localStorage.removeItem('reporion-draft-' + path);
        }
      }).catch(function () {});
    }

    window.addEventListener('online', function () {
      isOnline = true;
      setStatus('<span data-editor-status="saved">' + esc(s.saved) + '</span>');
      scheduleSave();
    });
    window.addEventListener('offline', function () {
      isOnline = false;
      setStatus('<span data-editor-status="offline">' + esc(s.offline) + '</span>');
    });

    /* Initial status */
    setStatus('<span data-editor-status="saved">' + esc(s.saved) + '</span>');
  }

  var forms = document.querySelectorAll('[data-island="editor"]');
  for (var i = 0; i < forms.length; i++) {
    mount(forms[i]);
  }
})();
