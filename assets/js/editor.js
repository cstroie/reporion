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

    // The form's own field: the whole document in raw mode, the body only
    // in the curated Details-panel mode (phase 14) — `textarea` is where the
    // user types — the same field, or in a multi-exam report (raw mode
    // only, phase 12) the pane of the tab in front
    var docArea = form.querySelector('textarea[name="document"], textarea[name="body"]');
    var textarea = docArea;
    var statusEl = document.getElementById('editor-status');
    var draftBanner = document.getElementById('editor-draft-banner');
    var draftDismiss = document.getElementById('editor-draft-dismiss');

    if (!docArea || !path) return;

    var db = null;
    var timer = null;
    var submitting = false;
    var savedDoc = docArea.value;
    var restoreBtn = document.getElementById('editor-draft-restore');
    var draftWhen = document.getElementById('editor-draft-when');
    var offered = null;

    function setStatus(html) {
      if (statusEl) statusEl.innerHTML = html;
    }

    function showStatus() {
      if (docArea.value === savedDoc) {
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
      if (draft.baseRev !== baseRev || draft.doc === docArea.value) {
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
          docArea.value = offered.doc;
          if (exams) {
            buildExams(docArea.value);
          }
          scheduleDraft();
          showChars();
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
      if (docArea.value === savedDoc) {
        dbDelete(db, path).catch(function () {});
      } else {
        dbPut(db, { path: path, doc: docArea.value, baseRev: baseRev, ts: Date.now() }).catch(function () {});
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
      if (!submitting && docArea.value !== savedDoc) {
        event.preventDefault();
        event.returnValue = '';
      }
    });

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

    function replaceText(el, from, to) {
      var at = el.value.indexOf(from);
      if (at === -1) return;
      el.setRangeText(to, at, at + from.length, 'preserve');
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function upload(file) {
      // The pane it was dropped in, even if another tab is in front when it lands
      var el = textarea;
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
            replaceText(el, placeholder, result.json.markdown);
            return;
          }
          replaceText(el, placeholder, '');
          var message = result.json && result.json.error ? result.json.error.message : s.mediaFailed;
          setStatus('<span data-editor-status="error">' + esc(message) + '</span>');
        })
        .catch(function () {
          replaceText(el, placeholder, '');
          setStatus('<span data-editor-status="error">' + esc(s.mediaFailed) + '</span>');
        });
    }


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
      if (charsEl && s.chars) charsEl.textContent = s.chars.replace('%d', String(docArea.value.length));
    }

    // One edit to a pane (the one in front unless said), kept on its undo stack
    function applyEdit(e, target) {
      var el = target || textarea;
      var before = el.value;
      el.focus();
      el.setSelectionRange(e.from, e.to);
      var done = false;
      try {
        done = e.insert === '' ? document.execCommand('delete') : document.execCommand('insertText', false, e.insert);
      } catch (err) {
        done = false;
      }
      if (!done || el.value === before) {
        el.setRangeText(e.insert, e.from, e.to, 'end');
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (e.selStart !== null && e.selStart !== undefined) {
        el.setSelectionRange(e.selStart, e.selEnd);
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
          var here = textarea;
          // The frontmatter is in the head pane of a multi-exam report
          var fmEl = exams ? headArea : here;
          var linkEdit = F.link(here.value, a, b, p.path, p.label + (p.date ? ', ' + p.date : ''));
          var priorEdit = F.addPrior(fmEl.value, p.path);
          applyEdit(linkEdit, here);
          if (priorEdit && priorEdit.unknown) {
            setStatus('<span data-editor-status="error">' + esc(s.priorUnknown) + '</span>');
          } else if (priorEdit) {
            applyEdit(priorEdit, fmEl);
            // In the same pane the frontmatter is before the cursor: its edit shifts the caret
            var caret = fmEl === here ? linkEdit.selStart + priorEdit.insert.length - (priorEdit.to - priorEdit.from) : linkEdit.selStart;
            here.focus();
            here.setSelectionRange(caret, caret);
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
              var text = F.templateBody(json.body);
              // Into an exam, a template's headings go one level down: its ## must not split the exam
              if (EX && (exams ? textarea !== headArea : config.isReport && EX.examBefore(textarea.value, a))) {
                text = EX.demote(text);
              }
              applyEdit(F.insertBlock(textarea.value, a, b, text));
            })
            .catch(function () {
              setStatus('<span data-editor-status="error">' + esc(s.templateFailed) + '</span>');
            });
        });
      },
      snippets: function (v, a, b) {
        openPicker(localSource(snippetList.map(function (sn) {
          return { title: ';' + sn.name + ' — ' + sn.title, meta: sn.modality ? s.snippetModality : '', value: sn };
        })), function (sn) {
          applyEdit(F.insertSnippet(textarea.value, a, b, sn.body));
        });
      },
      copy: function () { copyText(F.bodyOf(docArea.value)); }
    };

    // Snippets (phase 11, D24): `;name` expands when a space or line break
    // follows it — read from the text just typed, not from key codes, so
    // an external dictation program typing it works the same — or on Tab.
    // Only at the cursor; one Ctrl+Z gives the typed `;name` back.
    var snippetList = Array.isArray(config.snippets) ? config.snippets : [];
    var snippetBodies = {};
    snippetList.forEach(function (sn) { snippetBodies[sn.name] = sn.body; });
    var expanding = false;

    function expand(delimited) {
      if (textarea.selectionStart !== textarea.selectionEnd) return false;
      var e = F.expandAt(textarea.value, textarea.selectionStart, snippetBodies, delimited);
      if (!e) return false;
      expanding = true;
      try {
        applyEdit(e);
      } finally {
        expanding = false;
      }
      return true;
    }

    if (F && toolbar) {
      toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tb]');
        if (!button) return;
        var action = actions[button.getAttribute('data-tb')];
        if (!action) return;
        var sel = selection();
        action(textarea.value, sel[0], sel[1]);
      });
      if (imageInput) {
        imageInput.addEventListener('change', function () {
          textarea.focus();
          imageFiles(imageInput.files).forEach(upload);
          imageInput.value = '';
        });
      }
    }

    // Everything typed into a pane: the document follows, the draft and the
    // count too; images pasted or dropped; Ctrl+B / Ctrl+I; snippets
    function wire(el) {
      el.addEventListener('focus', function () { textarea = el; });
      el.addEventListener('input', function (event) {
        if (exams && el !== docArea) {
          syncExams();
          if (event.inputType !== 'historyUndo' && event.inputType !== 'historyRedo') {
            if (edited[edited.length - 1] !== el) edited.push(el);
            undone = [];
          }
        }
        scheduleDraft();
        showChars();
      });
      // The browser keeps one undo history for the page, not one per
      // textarea: Ctrl+Z in another tab would do nothing while the latest
      // change sits in a hidden one. Undo goes back through the report's
      // changes wherever they are, showing the tab it undoes in.
      el.addEventListener('keydown', function (event) {
        if (!exams || !(event.ctrlKey || event.metaKey) || event.altKey) return;
        var key = event.key.toLowerCase();
        var redo = (key === 'z' && event.shiftKey) || (key === 'y' && !event.shiftKey);
        if (!redo && (key !== 'z' || event.shiftKey)) return;
        event.preventDefault();
        // The panes in the order they were edited: undo the newest; a pane
        // with nothing left to undo steps back to the one edited before it.
        // Redo walks the same way forward.
        var from = redo ? undone : edited;
        var to = redo ? edited : undone;
        while (from.length > 0) {
          var target = from[from.length - 1];
          if (target !== textarea) show(target === headArea ? -1 : panes.indexOf(target));
          var before = target.value;
          document.execCommand(redo ? 'redo' : 'undo');
          if (target.value !== before) {
            if (to[to.length - 1] !== target) to.push(target);
            return;
          }
          from.pop();
        }
      });
      el.addEventListener('paste', function (event) {
        var files = imageFiles(event.clipboardData && event.clipboardData.files);
        if (files.length === 0) return;
        event.preventDefault();
        textarea = el;
        files.forEach(upload);
      });
      el.addEventListener('dragover', function (event) {
        if (event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1) {
          event.preventDefault();
        }
      });
      el.addEventListener('drop', function (event) {
        var files = imageFiles(event.dataTransfer && event.dataTransfer.files);
        if (files.length === 0) return;
        event.preventDefault();
        el.focus();
        textarea = el;
        files.forEach(upload);
      });
      if (!F || !toolbar) return;
      el.addEventListener('keydown', function (event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return;
        var key = event.key.toLowerCase();
        if (key !== 'b' && key !== 'i') return;
        event.preventDefault();
        var sel = selection();
        actions[key === 'b' ? 'bold' : 'italic'](textarea.value, sel[0], sel[1]);
      });
      // Enter at the end of a list line continues it (TODO 13); a plain
      // Enter elsewhere is left to the browser.
      el.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        if (el.selectionStart !== el.selectionEnd) return;
        var e = F.continueList(el.value, el.selectionStart);
        if (!e) return;
        event.preventDefault();
        applyEdit(e, el);
      });
      if (snippetList.length > 0) {
        el.addEventListener('input', function (event) {
          if (expanding) return;
          var type = event.inputType || 'insertText';
          if (type !== 'insertText' && type !== 'insertLineBreak' && type !== 'insertParagraph') return;
          // After this event, not inside it: the browser ignores execCommand
          // while it dispatches input, and only execCommand keeps the undo step
          Promise.resolve().then(function () { expand(true); });
        });
        el.addEventListener('keydown', function (event) {
          if (event.key !== 'Tab' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
          if (expand(false)) event.preventDefault();
        });
      }
    }

    /*
     * A multi-exam report (phase 12): a Head tab — the frontmatter without
     * its exams list, and the shared text — and one tab per exam, each its
     * own textarea (undo follows the report's changes across them, see wire()). The form's field stays the whole
     * document (assets/js/editor-exams.js puts it together on every edit),
     * so saving, the local draft and the preview are unchanged. Without
     * JavaScript, or for an exams list in a shape the script does not
     * read, the one textarea as before.
     */
    var EX = window.ReporionEditorExams;
    var exams = null;
    var edited = [];
    var undone = [];
    var headArea = null;
    var panes = [];
    var current = 0;
    var tabsEl = document.getElementById('editor-exams');
    var examsUnread = false;

    function newPane(text) {
      var el = document.createElement('textarea');
      el.className = docArea.className;
      el.setAttribute('spellcheck', docArea.getAttribute('spellcheck') || 'false');
      el.value = text;
      el.hidden = true;
      docArea.parentNode.insertBefore(el, docArea);
      wire(el);
      return el;
    }

    function state() {
      return { head: headArea.value, exams: exams.exams, parts: panes.map(function (el) { return el.value; }), at: exams.at };
    }

    function syncExams() {
      docArea.value = EX.join(state());
    }

    function tabTitle(i) {
      var first = (panes[i].value.split('\n')[0] || '');
      var title = first.replace(/^ {0,3}##(?:[ \t]+|$)/, '').replace(/(?:^|[ \t])#+[ \t]*$/, '').trim();
      return (i + 1) + ' ' + (/^ {0,3}##(?:[ \t]|$)/.test(first) ? (title || s.examUntitled) : s.examNoHeading);
    }

    function renderTabs() {
      if (!tabsEl) return;
      tabsEl.innerHTML = '';
      tabsEl.hidden = false;
      function button(label, onClick, extra) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'wk-examtab' + (extra || '');
        b.textContent = label;
        b.addEventListener('click', onClick);
        tabsEl.appendChild(b);
        return b;
      }
      if (exams) {
        var head = button(s.examHead, function () { show(-1); }, current === -1 ? ' wk-on' : '');
        head.setAttribute('aria-pressed', String(current === -1));
        panes.forEach(function (el, i) {
          var tab = button(tabTitle(i), function () { show(i); }, current === i ? ' wk-on' : '');
          tab.setAttribute('aria-pressed', String(current === i));
        });
      }
      if (examsUnread) {
        // The list and the ## headings disagree: say so where the tabs would be; Add exam cannot work here
        var why = document.createElement('span');
        why.className = 'wk-examwhy';
        why.setAttribute('role', 'status');
        why.textContent = s.examsUnreadable;
        tabsEl.appendChild(why);
        return;
      }
      var add = button(s.examAdd, addExam, ' wk-examadd');
      add.title = s.examAddHelp;
      if (exams && current >= 0) {
        var spacer = document.createElement('span');
        spacer.className = 'wk-tflex';
        tabsEl.appendChild(spacer);
        button('←', function () { move(-1); }, ' wk-examtool').title = s.examLeft;
        button('→', function () { move(1); }, ' wk-examtool').title = s.examRight;
        button(s.examRemove, removeExam, ' wk-examtool');
      }
    }

    function show(i) {
      current = i;
      var el = i === -1 ? headArea : panes[i];
      headArea.hidden = el !== headArea;
      panes.forEach(function (p) { p.hidden = p !== el; });
      textarea = el;
      renderTabs();
      el.focus();
    }

    function teardown() {
      edited = [];
      undone = [];
      panes.forEach(function (el) { el.remove(); });
      if (headArea) headArea.remove();
      panes = [];
      headArea = null;
    }

    function buildExams(doc, focus) {
      var opened = EX.open(doc);
      teardown();
      if (!opened) {
        exams = null;
        docArea.hidden = false;
        textarea = docArea;
        examsUnread = opened === false;
        if (config.isReport && tabsEl) renderTabs();
        return;
      }
      examsUnread = false;
      exams = { exams: opened.exams, at: opened.at };
      headArea = newPane(opened.head);
      panes = opened.parts.map(newPane);
      docArea.hidden = true;
      syncExams();
      show(focus !== undefined && focus < panes.length ? focus : -1);
    }

    function addExam() {
      var next;
      if (exams) {
        next = EX.addExam(state(), s.examNew);
      } else {
        next = EX.convert(docArea.value, s.examNew);
        if (next.error) {
          setStatus('<span data-editor-status="error">' + esc(s.examShape) + '</span>');
          return;
        }
      }
      docArea.value = EX.join(next);
      buildExams(docArea.value, next.exams.length - 1);
      scheduleDraft();
      showChars();
      // The new exam's title, selected to type over
      var first = textarea.value.indexOf('\n');
      textarea.setSelectionRange(3, first === -1 ? textarea.value.length : first);
    }

    function removeExam() {
      if (current < 0 || !window.confirm(s.examRemoveConfirm.replace('%s', tabTitle(current)))) return;
      var next = EX.removeExam(state(), current);
      docArea.value = EX.join(next);
      buildExams(docArea.value, Math.min(current, next.exams.length - 1));
      scheduleDraft();
      showChars();
    }

    function move(delta) {
      if (current < 0) return;
      var to = current + delta;
      if (to < 0 || to >= panes.length) return;
      docArea.value = EX.join(EX.moveExam(state(), current, delta));
      buildExams(docArea.value, to);
      scheduleDraft();
    }

    wire(docArea);
    if (EX && config.isReport) {
      buildExams(docArea.value, examFromUrl());
      // The document as the tabs write it is what counts as saved
      savedDoc = docArea.value;
      // Alt+PgUp / Alt+PgDn: the tab before or after
      form.addEventListener('keydown', function (event) {
        if (!exams || !event.altKey || (event.key !== 'PageUp' && event.key !== 'PageDown')) return;
        event.preventDefault();
        var next = current + (event.key === 'PageDown' ? 1 : -1);
        if (next >= -1 && next < panes.length) show(next);
      });
    }

    // /{path}/edit?exam=2 opens on that exam
    function examFromUrl() {
      var m = /[?&]exam=(\d+)/.exec(window.location.search);
      return m ? parseInt(m[1], 10) - 1 : undefined;
    }

    /*
     * The Assistant rail (phase 15d). An action sends the selection, else
     * the exam in front, else the report's text (never the frontmatter) to
     * POST /api/v1/ai/complete; the server de-identifies it before anything
     * leaves (Service\Ai\Context). The answer streams into a card; nothing
     * is written until the doctor applies it (A3) — through execCommand, so
     * Ctrl+Z undoes it — and the actions applied go into the save's note.
     */
    var AI = window.ReporionEditorAi;
    var aiConfig = config.ai || null;
    var aiRail = document.getElementById('editor-ai');
    var aiOuts = document.getElementById('editor-ai-outs');
    var aiContext = document.getElementById('editor-ai-context');
    var aiAssisted = document.getElementById('editor-ai-assisted');
    var applied = [];

    function aiText() {
      var a = textarea.selectionStart;
      var b = textarea.selectionEnd;
      // A selection in the whole document never takes the frontmatter along
      if (F && textarea === docArea) {
        var min = F.bodyStart(textarea.value);
        a = Math.max(a, min);
        b = Math.max(b, min);
      }
      var as = aiConfig.strings;
      if (a !== b) return { text: textarea.value.slice(a, b), label: as.selection, exam: null };
      if (exams && current >= 0) return { text: textarea.value, label: as.exam.replace('%d', String(current + 1)), exam: current + 1 };
      return { text: F ? F.bodyOf(docArea.value) : docArea.value, label: as.text, exam: null };
    }

    function aiCard(action) {
      var card = document.createElement('div');
      card.className = 'wk-ai-out';
      card.setAttribute('aria-live', 'polite');
      var head = document.createElement('div');
      head.className = 'wk-ai-out-h';
      var title = document.createElement('span');
      title.className = 'wk-eyebrow';
      title.textContent = action.label;
      var meta = document.createElement('span');
      meta.className = 'wk-mono wk-dim';
      meta.textContent = aiConfig.strings.working;
      head.appendChild(title);
      head.appendChild(meta);
      var body = document.createElement('div');
      body.className = 'wk-ai-text';
      var row = document.createElement('div');
      row.className = 'wk-ai-row';
      card.appendChild(head);
      card.appendChild(body);
      card.appendChild(row);
      aiOuts.insertBefore(card, aiOuts.firstChild);
      return { card: card, meta: meta, body: body, row: row, close: function () { card.remove(); } };
    }

    // result: show — the answer opens here instead of an inline card (one
    // persistent <dialog>, its content reset per run)
    function aiModal(action) {
      var dialog = document.getElementById('editor-ai-modal');
      var title = document.getElementById('editor-ai-modal-title');
      var meta = document.getElementById('editor-ai-modal-meta');
      var body = document.getElementById('editor-ai-modal-body');
      var row = document.getElementById('editor-ai-modal-row');
      dialog.removeAttribute('data-state');
      title.textContent = action.label;
      meta.textContent = aiConfig.strings.working;
      body.textContent = '';
      row.innerHTML = '';
      if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
      return { card: dialog, meta: meta, body: body, row: row, close: function () { if (dialog.open) dialog.close(); } };
    }

    function aiButton(row, label, primary, onClick) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-sm ' + (primary ? 'btn-primary' : 'btn-secondary');
      b.textContent = label;
      b.addEventListener('click', onClick);
      row.appendChild(b);
      return b;
    }

    function aiRun(action, trigger) {
      var sent = aiText();
      if (sent.text.trim() === '' && !action.custom) {
        setStatus('<span data-editor-status="error">' + esc(aiConfig.strings.noText) + '</span>');
        return;
      }
      // Where the answer goes: the pane and selection as they were when asked
      var target = textarea;
      var from = target.selectionStart;
      var to = target.selectionEnd;
      var promptInput = aiRail.querySelector('[data-ai-prompt="' + action.id + '"]');
      var ui = action.result === 'show' ? aiModal(action) : aiCard(action);
      var answer = '';
      if (trigger) trigger.setAttribute('aria-busy', 'true');

      function finish(meta, context) {
        if (trigger) trigger.removeAttribute('aria-busy');
        ui.meta.textContent = meta;
        if (context && aiContext) {
          aiContext.innerHTML = '';
          context.forEach(function (item) {
            var chip = document.createElement('span');
            chip.className = 'wk-chip' + (item === 'no patient identifiers' ? ' wk-chip-off' : '');
            chip.textContent = item;
            aiContext.appendChild(chip);
          });
        }
        var as = aiConfig.strings;
        function applyAs(mode) {
          var e = AI.apply(mode, target.value, Math.min(from, target.value.length), Math.min(to, target.value.length), answer);
          if (!e) return;
          if (exams) {
            var index = target === headArea ? -1 : panes.indexOf(target);
            if (index >= -1 && (index !== -1 || target === headArea)) show(index);
          }
          applyEdit(e, target);
          if (applied.indexOf(action.id) === -1) applied.push(action.id);
          if (aiAssisted) aiAssisted.value = applied.join(',');
          ui.meta.textContent = as.applied;
        }
        if (answer.trim() === '') return;
        if (action.result !== 'show') {
          aiButton(ui.row, as[action.result] || as.apply, true, function () { applyAs(action.result); });
        }
        if (action.result !== 'insert') {
          aiButton(ui.row, as.insert, action.result === 'show', function () { applyAs('insert'); });
        }
        aiButton(ui.row, as.copy, false, function () { copyText(AI.clean(answer)); });
        aiButton(ui.row, as.regenerate, false, function () { ui.close(); aiRun(action, trigger); });
        aiButton(ui.row, as.close, false, function () { ui.close(); });
      }

      function fail(message) {
        ui.card.setAttribute('data-state', 'error');
        ui.body.textContent = message || aiConfig.strings.failed;
        finish('', null);
        aiButton(ui.row, aiConfig.strings.close, false, function () { ui.close(); });
      }

      fetch(basePath + '/api/v1/ai/complete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json' },
        body: JSON.stringify({ path: path, action: action.id, text: sent.text, label: sent.label, exam: sent.exam, prompt: promptInput ? promptInput.value : '', stream: true })
      }).then(function (response) {
        var type = response.headers.get('Content-Type') || '';
        if (type.indexOf('text/event-stream') === -1 || !response.body || !window.TextDecoder) {
          // The whole answer at once: an error, or no streaming on the way
          return response.json().then(function (json) {
            if (!response.ok || json.error) { fail(json.error ? json.error.message : ''); return; }
            answer = json.result || '';
            ui.body.textContent = answer;
            finish((json.ms / 1000).toFixed(1) + ' s', json.context);
          });
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        function pump() {
          return reader.read().then(function (step) {
            if (step.done) return;
            buffer += decoder.decode(step.value, { stream: true });
            var parsed = AI.parseEvents(buffer);
            buffer = parsed.rest;
            var ended = false;
            parsed.events.forEach(function (ev) {
              if (!ev.data) return;
              if (ev.event === 'delta') {
                answer += ev.data.text || '';
                ui.body.textContent = answer;
              } else if (ev.event === 'done') {
                ended = true;
                var tokens = ev.data.usage && ev.data.usage.completion_tokens ? ' · ' + ev.data.usage.completion_tokens + ' tok' : '';
                finish((ev.data.ms / 1000).toFixed(1) + ' s' + tokens, ev.data.context);
              } else if (ev.event === 'error') {
                ended = true;
                fail(ev.data.message);
              }
            });
            return ended ? undefined : pump();
          });
        }
        return pump();
      }).catch(function () { fail(''); });
    }

    if (AI && aiConfig && aiRail) {
      var aiActions = {};
      aiConfig.actions.forEach(function (a) { aiActions[a.id] = a; });
      aiRail.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ai-action]');
        if (!button || button.getAttribute('aria-busy') === 'true') return;
        var action = aiActions[button.getAttribute('data-ai-action')];
        if (action) aiRun(action, button);
      });
      // The rail's buttons must not take the focus (and the selection) from the text
      aiRail.addEventListener('mousedown', function (event) {
        if (event.target.closest('.wk-ai-btn, .wk-ai-row button')) event.preventDefault();
      });
    }

    showChars();
    showStatus();
  }

  var forms = document.querySelectorAll('[data-island="editor"]');
  for (var i = 0; i < forms.length; i++) {
    mount(forms[i]);
  }
})();
