/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The notes app (D55): an offline-first client of the JSON API, mounted on
 * /notes. A note is an ordinary page under the account's own namespace,
 * users:{username}:notes:{ulid}, made with a ULID on the device so that a
 * note created offline has its path before the server has seen it. As in
 * Keep, a note has a title and a text: the title is the page's frontmatter
 * `title` (empty: none, and the list shows the text's first line), the
 * text its body.
 *
 * The browser keeps every note in IndexedDB (one database per account): its
 * text, the revision it was last synced at (`rev`) and that revision's text
 * (`base`), and whether it changed since (`dirty`, `deleted`). Sync runs on
 * open, when the browser comes back online, a moment after typing stops and
 * every minute while the page is visible (no Background Sync: iOS has none):
 *   push  a new note: POST /pages; a changed one: PUT /pages/{path} with
 *         merge: true, so a note changed on two devices is merged on the
 *         server (Support\Merge3), and an overlap comes back with conflict
 *         markers for the writer to resolve; a deleted one: DELETE.
 *   pull  GET /pages?ns=… : a newer revision of a note not changed here is
 *         fetched; one gone from the server and not changed here is dropped
 *         (one changed here is created again: the writer's text is kept).
 * A note holding an unresolved conflict is not pushed until its writer says
 * it is resolved. The preview is the server's renderer (POST /api/v1/render,
 * invariant 4), online only. Text is shown with textContent.
 *
 * The look is an app's (templates/notes.php, notes.css): `data-screen` on the
 * frame puts sign-in, the list (cards) or the editor in front; a wide window
 * shows the list beside the open note. Lasting messages are banners with
 * their buttons, passing ones toasts; the menu holds sync, theme, install,
 * sign out and the version.
 *
 * The app has a version and a build (NotesController::VERSION, build()),
 * shown in the menu. A new service worker waits beside the running one; the
 * page asks it what it is and offers a reload (a notice), checking for one
 * when it becomes visible, comes back online and every hour.
 *
 * Install: once signed in, a banner offers to install the app, with the
 * browser's own prompt (beforeinstallprompt: Chrome, Edge, Android) or, where
 * the browser gives none, how to do it by hand: iOS Safari (Share → Add to
 * Home Screen), Safari on macOS (File → Add to Dock), and Chromium when its
 * event has not come within a few seconds (desktop Chrome may hold it back):
 * the address bar's install icon or the browser menu; the banner gains its
 * Install button if the event comes later. Never when it already runs
 * installed. "Not now" waits 30 days; the menu's Install item stays on any
 * browser that can install it.
 */
(function () {
  'use strict';

  var THEME_KEY = 'reporion.notes.theme';
  var USER_KEY = 'reporion.notes.user';
  var SYNC_DELAY = 2000;
  var SAVE_DELAY = 400;
  var SYNC_EVERY = 60000;
  var PAGE_SIZE = 200;
  var MARKER = /^<<<<<<< /m;

  var root = document.querySelector('[data-island="notes"]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(root.querySelector('script[type="application/json"]').textContent) || {}; } catch (e) { return; }
  var s = cfg.strings || {};
  function t(key) { return s[key] || key; }

  var el = {
    status: root.querySelector('[data-status]'),
    statusIcon: root.querySelector('[data-status-icon]'),
    sync: root.querySelector('[data-sync]'),
    syncNow: root.querySelector('[data-sync-now]'),
    install: root.querySelector('[data-install]'),
    themes: root.querySelectorAll('[data-theme-set]'),
    signOut: root.querySelector('[data-sign-out]'),
    menu: root.querySelector('[data-menu]'),
    menuButton: root.querySelector('[data-menu-button]'),
    menuUser: root.querySelector('[data-menu-user]'),
    banners: root.querySelector('[data-banners]'),
    toasts: root.querySelector('[data-toasts]'),
    signin: root.querySelector('[data-signin]'),
    search: root.querySelector('[data-search]'),
    add: root.querySelector('[data-new]'),
    items: root.querySelector('[data-items]'),
    empty: root.querySelector('[data-empty]'),
    editorNone: root.querySelector('[data-editor-none]'),
    editorNote: root.querySelector('[data-editor-note]'),
    noteStatus: root.querySelector('[data-note-status]'),
    title: root.querySelector('[data-title]'),
    text: root.querySelector('[data-text]'),
    preview: root.querySelector('[data-preview]'),
    previewPane: root.querySelector('[data-preview-pane]'),
    del: root.querySelector('[data-delete]'),
    conflict: root.querySelector('[data-conflict]'),
    tags: root.querySelector('[data-tags]'),
    tagInput: root.querySelector('[data-tag-input]'),
    tagList: root.querySelector('[data-tag-list]'),
    filter: root.querySelector('[data-filter]')
  };

  var state = {
    user: null,      // {username, display_name, notes}
    db: null,        // IndexedDB, or null when the browser refuses one (notes then live in memory)
    notes: {},       // path -> note
    current: null,   // the path in the editor
    tag: null,       // the list's tag filter
    syncing: false,
    again: false,
    failed: false,
    online: navigator.onLine !== false
  };
  var timers = { save: 0, sync: 0 };

  // ---- theme: system, light or dark, kept in this browser ----

  function themeSetting() {
    try { return localStorage.getItem(THEME_KEY) || 'system'; } catch (e) { return 'system'; }
  }

  function applyTheme() {
    var setting = themeSetting();
    var light = setting === 'light' || (setting === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
    document.body.classList.toggle('theme-light', !!light);
    Array.prototype.forEach.call(el.themes, function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-theme-set') === setting ? 'true' : 'false');
    });
    // The system bar takes the app's own background
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', getComputedStyle(root).backgroundColor);
  }

  Array.prototype.forEach.call(el.themes, function (button) {
    button.addEventListener('click', function () {
      try { localStorage.setItem(THEME_KEY, button.getAttribute('data-theme-set')); } catch (e) { /* this visit only */ }
      applyTheme();
    });
  });
  if (window.matchMedia) {
    var scheme = window.matchMedia('(prefers-color-scheme: light)');
    if (scheme.addEventListener) scheme.addEventListener('change', applyTheme);
  }

  // ---- messages: a banner stays until answered, a toast goes after a few seconds ----

  var ICONS = { info: 'info', ok: 'check-circle', warn: 'warning', error: 'warning-octagon' };

  /** A banner (keep) in `parent` (the list's banners by default), else a toast; returns it */
  function notice(kind, text, keep, parent) {
    var card = document.createElement('div');
    card.className = keep ? 'nt-banner nt-banner-' + kind : 'nt-toast nt-toast-' + kind;
    card.setAttribute('role', kind === 'warn' || kind === 'error' ? 'alert' : 'status');
    var i = document.createElement('i');
    i.className = 'ph ph-' + (ICONS[kind] || 'info');
    i.setAttribute('aria-hidden', 'true');
    var body = document.createElement('div');
    body.className = 'nt-banner-body';
    body.textContent = text;
    card.appendChild(i);
    card.appendChild(body);
    if (keep) {
      var actions = document.createElement('div');
      actions.className = 'nt-banner-actions';
      body.appendChild(actions);
      (parent || el.banners).appendChild(card);
    } else {
      el.toasts.appendChild(card);
      setTimeout(function () { card.remove(); }, 4000);
    }
    return card;
  }

  /** A button in a banner's action row */
  function action(card, label, primary, onClick) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'nt-btn ' + (primary ? 'nt-btn-primary' : 'nt-btn-quiet');
    button.textContent = label;
    button.addEventListener('click', onClick);
    card.querySelector('.nt-banner-actions').appendChild(button);
    return button;
  }

  // ---- IndexedDB ----

  function openDb(username) {
    return new Promise(function (resolve) {
      var request;
      try { request = indexedDB.open('reporion-notes-' + username, 1); } catch (e) { resolve(null); return; }
      request.onupgradeneeded = function () {
        if (!request.result.objectStoreNames.contains('notes')) request.result.createObjectStore('notes', { keyPath: 'path' });
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { resolve(null); };
      request.onblocked = function () { resolve(null); };
    });
  }

  function store(mode, work) {
    if (!state.db) return Promise.resolve(null);
    return new Promise(function (resolve, reject) {
      var tx = state.db.transaction('notes', mode);
      var result = work(tx.objectStore('notes'));
      tx.oncomplete = function () { resolve(result && 'result' in result ? result.result : null); };
      tx.onerror = function () { reject(tx.error); };
    });
  }

  function loadAll() {
    return store('readonly', function (os) { return os.getAll(); }).then(function (list) {
      state.notes = {};
      (list || []).forEach(function (note) { state.notes[note.path] = note; });
    });
  }

  function keep(note) {
    state.notes[note.path] = note;
    return store('readwrite', function (os) { os.put(note); }).catch(function () {
      notice('error', t('notes.storage_failed'), false);
    });
  }

  function forget(path) {
    delete state.notes[path];
    return store('readwrite', function (os) { os['delete'](path); }).catch(function () { /* gone from memory either way */ });
  }

  // ---- the API ----

  function api(method, path, body) {
    var init = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(cfg.api + path, init).then(function (response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        return { status: response.status, data: data };
      });
    });
  }

  function offline() {
    state.online = false;
    showStatus();
  }

  // ---- notes ----

  var ULID_CHARS = '0123456789abcdefghjkmnpqrstvwxyz';

  function ulid() {
    var time = Date.now();
    var out = '';
    for (var i = 0; i < 10; i++) {
      out = ULID_CHARS.charAt(time % 32) + out;
      time = Math.floor(time / 32);
    }
    var random = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(random);
    for (var j = 0; j < 16; j++) out += ULID_CHARS.charAt(random[j] % 32);
    return out;
  }

  function titleOf(body) {
    var lines = String(body || '').split('\n');
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].trim();
      if (line === '' || line === '---') continue;
      return line.replace(/^#+\s*/, '').slice(0, 120) || t('notes.untitled');
    }
    return t('notes.untitled');
  }

  /** What a note is called: its title (frontmatter `title`), else its text's first line */
  function labelOf(title, body) {
    return String(title || '').trim() || titleOf(body);
  }

  function pageTitle(page) {
    return page && page.meta && typeof page.meta.title === 'string' ? page.meta.title : '';
  }

  /** A tag as typed, made one: lower case, no leading #, single spaces, at most 40 characters */
  function normalTag(text) {
    return String(text || '').replace(/^#+/, '').replace(/[,\s]+/g, ' ').trim().toLowerCase().slice(0, 40);
  }

  /** The page's `tags` (frontmatter), as a clean list without repeats */
  function tagsOf(page) {
    var raw = page && page.meta ? page.meta.tags : null;
    var list = Array.isArray(raw) ? raw : (typeof raw === 'string' ? raw.split(',') : []);
    var out = [];
    list.forEach(function (tag) {
      var clean = normalTag(tag);
      if (clean && out.indexOf(clean) === -1) out.push(clean);
    });
    return out;
  }

  function sameTags(a, b) {
    return (a || []).join('\n') === (b || []).join('\n');
  }

  /** The frontmatter a save sends: the note's, its title and tags set or (empty) removed */
  function metaFor(note) {
    var meta = Object.assign({ visibility: 'private' }, note.meta || {});
    var title = String(note.title || '').trim();
    if (title) meta.title = title; else delete meta.title;
    if (note.tags && note.tags.length) meta.tags = note.tags.slice(); else delete meta.tags;
    // A note keeps its lines as typed, in the preview and in Reporion alike (Support\BodyFormat)
    meta.breaks = true;
    return meta;
  }

  function fromServer(page, previous) {
    return {
      path: page.path,
      title: pageTitle(page),
      baseTitle: pageTitle(page),
      tags: tagsOf(page),
      baseTags: tagsOf(page),
      body: page.body,
      base: page.body,
      rev: page.rev,
      meta: page.meta || {},
      dirty: false,
      deleted: false,
      conflict: false,
      updated: previous && previous.updated && !previous.dirty ? previous.updated : Date.now()
    };
  }

  function visibleNotes() {
    var q = (el.search.value || '').trim().toLowerCase();
    return Object.keys(state.notes).map(function (path) { return state.notes[path]; })
      .filter(function (note) {
        if (note.deleted || (state.tag && (note.tags || []).indexOf(state.tag) === -1)) return false;
        return q === '' || ((note.title || '') + '\n' + (note.tags || []).join(' ') + '\n' + note.body).toLowerCase().indexOf(q) !== -1;
      })
      .sort(function (a, b) { return (b.updated || 0) - (a.updated || 0); });
  }

  // ---- sync ----

  function scheduleSync(delay) {
    clearTimeout(timers.sync);
    timers.sync = setTimeout(sync, delay === undefined ? SYNC_DELAY : delay);
  }

  function sync() {
    if (!state.user) return Promise.resolve();
    if (state.syncing) { state.again = true; return Promise.resolve(); }
    state.syncing = true;
    state.failed = false;
    showStatus();
    var pending = Object.keys(state.notes).map(function (path) { return state.notes[path]; })
      .filter(function (note) { return note.dirty && !note.conflict; });

    var chain = Promise.resolve();
    pending.forEach(function (note) { chain = chain.then(function () { return push(note); }); });

    return chain.then(pull).then(function () {
      state.online = true;
    }, function (error) {
      if (error && error.signedOut) { signedOut(); return; }
      if (error instanceof TypeError) { offline(); return; }
      state.failed = true;
    }).then(function () {
      state.syncing = false;
      showStatus();
      renderList();
      refreshEditorFromStore();
      if (state.again) { state.again = false; scheduleSync(0); }
    });
  }

  function check401(response) {
    if (response.status === 401) { var e = new Error('signed out'); e.signedOut = true; throw e; }
    return response;
  }

  function push(note) {
    var sent = note.body;
    var sentTitle = note.title || '';
    var sentTags = (note.tags || []).slice();
    if (note.deleted) {
      if (!note.rev) return forget(note.path);
      return api('DELETE', '/pages/' + note.path).then(check401).then(function (r) {
        if (r.status === 200 || r.status === 404) return forget(note.path);
        throw new Error('delete ' + r.status);
      });
    }
    if (!note.rev) {
      return api('POST', '/pages', { path: note.path, meta: metaFor(note), body: sent }).then(check401).then(function (r) {
        if (r.status === 201) return settled(note.path, r.data, sent, sentTitle, sentTags);
        if (r.status === 409) {
          // Already made (a create whose answer was lost): the first revision is ours
          var again = Object.assign({}, note, { rev: 1, base: '' });
          return api('GET', '/pages/' + note.path + '?render=0').then(check401).then(function (got) {
            if (got.status !== 200) throw new Error('create ' + got.status);
            again.meta = got.data.meta || {};
            again.base = got.data.rev === 1 ? got.data.body : '';
            return keep(again).then(function () { return push(again); });
          });
        }
        throw new Error('create ' + r.status);
      });
    }
    return api('PUT', '/pages/' + note.path, { meta: metaFor(note), body: sent, base_rev: note.rev, merge: true }).then(check401).then(function (r) {
      if (r.status === 200) {
        if (r.data && r.data.merged) notice('info', t('notes.edited') + ': ' + labelOf(pageTitle(r.data), r.data.body), false);
        return settled(note.path, r.data, sent, sentTitle, sentTags);
      }
      if (r.status === 409 && r.data && r.data.current) return conflicted(note.path, r.data, sent);
      if (r.status === 404) {
        // Deleted on the server (or another device), changed here: made again, the text kept
        notice('warn', t('notes.recreated') + ': ' + labelOf(sentTitle, sent), false);
        return keep(Object.assign({}, state.notes[note.path], { rev: 0, base: '' })).then(function () { return push(state.notes[note.path]); });
      }
      throw new Error('save ' + r.status);
    });
  }

  /** The server took the note: its revision is the new base; text typed meanwhile stays dirty */
  function settled(path, page, sent, sentTitle, sentTags) {
    var local = state.notes[path];
    if (!local) return Promise.resolve();
    var next = fromServer(page, local);
    if (local.body !== sent || (local.title || '') !== sentTitle || !sameTags(local.tags, sentTags)) {
      next.body = local.body;
      next.title = local.title;
      next.tags = local.tags;
      next.dirty = true;
      next.updated = local.updated;
    } else {
      next.updated = local.updated;
    }
    return keep(next);
  }

  /** A stale save the server could not merge: the text with conflict markers, based on the server's revision */
  function conflicted(path, data, sent) {
    var local = state.notes[path];
    var current = data.current;
    var text = typeof data.merged === 'string'
      ? data.merged
      : '<<<<<<< server\n' + current.body.replace(/\n$/, '') + '\n=======\n' + sent.replace(/\n$/, '') + '\n>>>>>>> this device\n';
    var next = fromServer(current, local);
    next.body = local && local.body !== sent ? local.body : text;
    // The title the device changed is kept, else the server's (it is sent again on Resolved)
    if (local && (local.title || '') !== (local.baseTitle || '')) next.title = local.title;
    if (local && !sameTags(local.tags, local.baseTags)) next.tags = local.tags;
    next.dirty = true;
    next.conflict = true;
    next.updated = Date.now();
    notice('warn', t('notes.conflict') + ': ' + labelOf(pageTitle(current), current.body), false);
    return keep(next);
  }

  function pull() {
    var prefix = state.user.notes + ':';
    var seen = {};
    function page(offset) {
      return api('GET', '/pages?ns=' + encodeURIComponent(state.user.notes) + '&limit=' + PAGE_SIZE + '&offset=' + offset).then(check401).then(function (r) {
        if (r.status !== 200 || !r.data) throw new Error('list ' + r.status);
        var chain = Promise.resolve();
        r.data.data.forEach(function (row) {
          // Notes are the namespace's direct children (Support\Notes::isNote), whatever their slug
          if (row.path.indexOf(prefix) !== 0 || row.path.indexOf(':', prefix.length) !== -1) return;
          seen[row.path] = true;
          var local = state.notes[row.path];
          if (local && (local.dirty || local.rev >= row.rev)) return;
          chain = chain.then(function () { return fetchNote(row.path, row.updated); });
        });
        return chain.then(function () { return r.data.page.next !== null ? page(r.data.page.next) : null; });
      });
    }
    return page(0).then(function () {
      var gone = Object.keys(state.notes).filter(function (path) {
        var note = state.notes[path];
        return !seen[path] && note.rev > 0 && !note.dirty;
      });
      return Promise.all(gone.map(forget));
    });
  }

  function fetchNote(path, updated) {
    return api('GET', '/pages/' + path + '?render=0').then(check401).then(function (r) {
      if (r.status !== 200 || !r.data) return null;
      var local = state.notes[path];
      if (local && local.dirty) return null; // typed into while fetching: the next push merges
      var next = fromServer(r.data, null);
      next.updated = Date.parse(updated) || Date.now();
      return keep(next);
    });
  }

  // ---- status ----

  function showStatus() {
    var unsynced = Object.keys(state.notes).filter(function (path) { return state.notes[path].dirty; }).length;
    var text;
    var kind;
    if (!state.user) { text = ''; kind = ''; }
    else if (!state.online) { text = t('notes.offline'); kind = 'off'; }
    else if (state.syncing) { text = t('notes.syncing'); kind = 'busy'; }
    else if (state.failed) { text = t('notes.sync_failed'); kind = 'warn'; }
    else if (unsynced > 0) { text = t('notes.unsynced').replace('%d', String(unsynced)); kind = 'warn'; }
    else { text = t('notes.synced'); kind = 'ok'; }
    el.status.textContent = text;
    el.status.dataset.kind = kind;
    el.sync.dataset.kind = kind;
    el.sync.title = text;
    el.sync.setAttribute('aria-label', text);
    el.statusIcon.className = 'ph ' + ({ off: 'ph-cloud-slash', busy: 'ph-arrows-clockwise', warn: 'ph-cloud-warning', ok: 'ph-cloud-check' }[kind] || 'ph-cloud-check');
    el.menuUser.textContent = state.user ? t('notes.signed_in_as').replace('%s', state.user.display_name || state.user.username) : '';
    showNoteStatus();
  }

  /** The editor bar's line: this note's state */
  function showNoteStatus(text) {
    var note = state.current && state.notes[state.current];
    if (!note) { el.noteStatus.textContent = ''; return; }
    if (text) { el.noteStatus.textContent = text; return; }
    if (note.conflict) el.noteStatus.textContent = t('notes.conflict');
    else if (note.dirty) el.noteStatus.textContent = t('notes.not_synced_one');
    else el.noteStatus.textContent = t('notes.edited_at').replace('%s', when(note.updated));
  }

  /** A time today, else a date */
  function when(ms) {
    var d = new Date(ms || Date.now());
    var today = new Date();
    return d.toDateString() === today.toDateString()
      ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString([], { day: 'numeric', month: 'short', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
  }

  // ---- views ----

  /** The screen in front: signin | list | editor (a wide window shows the list beside the editor) */
  function show(name) {
    root.setAttribute('data-screen', name);
    if (name !== 'editor') {
      el.editorNote.hidden = true;
      el.editorNone.hidden = false;
    }
    closeMenu();
  }

  /** A card: the title (else nothing), the text's opening lines, when, and a flag when not synced */
  function renderList() {
    if (!state.user) return;
    var notes = visibleNotes();
    el.items.textContent = '';
    notes.forEach(function (note) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'nt-card';
      a.href = '#/n/' + encodeURIComponent(note.path.slice(state.user.notes.length + 1));
      if (note.path === state.current) a.setAttribute('aria-current', 'true');
      var title = String(note.title || '').trim();
      var text = String(note.body || '').trim();
      if (title || !text) {
        var head = document.createElement('span');
        head.className = 'nt-card-title';
        head.textContent = title || t('notes.untitled');
        a.appendChild(head);
      }
      if (text) {
        var body = document.createElement('span');
        body.className = 'nt-card-text';
        body.textContent = text.slice(0, 600);
        a.appendChild(body);
      }
      if (note.tags && note.tags.length) {
        var chips = document.createElement('span');
        chips.className = 'nt-card-tags';
        note.tags.slice(0, 4).forEach(function (tag) {
          var chip = document.createElement('span');
          chip.className = 'nt-chip';
          chip.textContent = tag;
          chips.appendChild(chip);
        });
        a.appendChild(chips);
      }
      var foot = document.createElement('span');
      foot.className = 'nt-card-foot';
      foot.textContent = when(note.updated);
      if (note.dirty) {
        var mark = document.createElement('i');
        mark.className = 'ph ' + (note.conflict ? 'ph-warning' : 'ph-cloud-arrow-up');
        mark.title = note.conflict ? t('notes.conflict') : t('notes.not_synced_one');
        foot.appendChild(mark);
      }
      a.appendChild(foot);
      li.appendChild(a);
      el.items.appendChild(li);
    });
    el.empty.hidden = notes.length > 0;
    renderFilter();
  }

  /** Every tag in use, the most used first: the list's filter bar and the editor's suggestions */
  function tagsInUse() {
    var count = {};
    Object.keys(state.notes).forEach(function (path) {
      var note = state.notes[path];
      if (!note.deleted) (note.tags || []).forEach(function (tag) { count[tag] = (count[tag] || 0) + 1; });
    });
    return Object.keys(count).sort(function (a, b) { return count[b] - count[a] || a.localeCompare(b); });
  }

  function renderFilter() {
    var tags = tagsInUse();
    if (state.tag && tags.indexOf(state.tag) === -1) state.tag = null;
    el.filter.textContent = '';
    el.filter.hidden = tags.length === 0;
    [null].concat(tags).forEach(function (tag) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = tag === null ? t('notes.all') : tag;
      button.setAttribute('aria-pressed', state.tag === tag ? 'true' : 'false');
      button.addEventListener('click', function () {
        state.tag = state.tag === tag ? null : tag;
        renderList();
      });
      el.filter.appendChild(button);
    });
    el.tagList.textContent = '';
    tags.forEach(function (tag) {
      var option = document.createElement('option');
      option.value = tag;
      el.tagList.appendChild(option);
    });
  }

  // ---- a note's tags ----

  function renderTags(note) {
    Array.prototype.forEach.call(el.tags.querySelectorAll('.nt-chip'), function (chip) { chip.remove(); });
    (note.tags || []).forEach(function (tag) {
      var chip = document.createElement('span');
      chip.className = 'nt-chip';
      chip.appendChild(document.createTextNode(tag));
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'nt-chip-x';
      x.setAttribute('aria-label', t('notes.tag_remove').replace('%s', tag));
      x.innerHTML = '<i class="ph ph-x" aria-hidden="true"></i>';
      x.addEventListener('click', function () {
        setTags((state.notes[state.current].tags || []).filter(function (other) { return other !== tag; }));
      });
      chip.appendChild(x);
      el.tags.insertBefore(chip, el.tagInput);
    });
  }

  /** The open note's tags changed: kept on the device at once, then synced like any edit */
  function setTags(tags) {
    var note = state.notes[state.current];
    if (!note || sameTags(note.tags, tags)) return Promise.resolve();
    flushSave();
    note = state.notes[state.current];
    return keep(Object.assign({}, note, { tags: tags, dirty: true, updated: Date.now() })).then(function () {
      renderTags(state.notes[state.current]);
      showStatus();
      showNoteStatus(t('notes.saved'));
      renderList();
      scheduleSync();
    });
  }

  function addTypedTag() {
    var note = state.notes[state.current];
    var tag = normalTag(el.tagInput.value);
    el.tagInput.value = '';
    if (!note || !tag || (note.tags || []).indexOf(tag) !== -1) return;
    setTags((note.tags || []).concat([tag]).slice(0, 20));
  }

  el.tagInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      addTypedTag();
    } else if (event.key === 'Backspace' && el.tagInput.value === '') {
      var note = state.notes[state.current];
      if (note && note.tags && note.tags.length) setTags(note.tags.slice(0, -1));
    }
  });
  // A phone keyboard may send the comma as text, and a datalist pick arrives as input
  el.tagInput.addEventListener('input', function (event) {
    // A suggestion picked from the list replaces the text at once (no inputType, or insertReplacementText)
    if (/,/.test(el.tagInput.value) || !event.inputType || event.inputType === 'insertReplacementText') addTypedTag();
  });
  el.tagInput.addEventListener('blur', addTypedTag);

  function openEditor(path) {
    var note = state.notes[path];
    if (!note || note.deleted) {
      notice('warn', t('notes.not_found'), false);
      location.hash = '#/';
      return;
    }
    state.current = path;
    show('editor');
    el.editorNone.hidden = true;
    el.editorNote.hidden = false;
    setPreview(false);
    el.title.value = note.title || '';
    el.text.value = note.body;
    el.tagInput.value = '';
    renderTags(note);
    showConflict(note);
    showNoteStatus();
    renderList();
    if (!note.body && !note.title) el.title.focus();
  }

  function refreshEditorFromStore() {
    if (!state.current) return;
    var note = state.notes[state.current];
    if (!note) { closeEditor(); return; }
    // Only text the writer has not touched since is replaced
    if (document.activeElement !== el.text && el.text.value !== note.body) el.text.value = note.body;
    if (document.activeElement !== el.title && el.title.value !== (note.title || '')) el.title.value = note.title || '';
    renderTags(note);
    showConflict(note);
    showNoteStatus();
  }

  function closeEditor() {
    flushSave();
    state.current = null;
    show('list');
    renderList();
  }

  function showConflict(note) {
    el.conflict.textContent = '';
    el.conflict.hidden = !note.conflict;
    if (!note.conflict) return;
    var card = notice('warn', t('notes.conflict_markers'), true, el.conflict);
    action(card, t('notes.conflict_resolved'), true, function () {
      flushSave().then(function () {
        var current = state.notes[state.current];
        if (MARKER.test(current.body) && !window.confirm(t('notes.conflict_markers'))) return;
        keep(Object.assign({}, current, { conflict: false })).then(function () {
          showConflict(state.notes[state.current]);
          showNoteStatus();
          scheduleSync(0);
        });
      });
    });
  }

  function flushSave() {
    if (!timers.save) return Promise.resolve();
    clearTimeout(timers.save);
    timers.save = 0;
    return saveLocal();
  }

  function saveLocal() {
    timers.save = 0;
    var note = state.notes[state.current];
    if (!note || (note.body === el.text.value && (note.title || '') === el.title.value)) return Promise.resolve();
    return keep(Object.assign({}, note, { title: el.title.value, body: el.text.value, dirty: true, updated: Date.now() })).then(function () {
      showStatus();
      showNoteStatus(t('notes.saved'));
      renderList();
      scheduleSync();
    });
  }

  function typed() {
    clearTimeout(timers.save);
    timers.save = setTimeout(saveLocal, SAVE_DELAY);
  }
  el.title.addEventListener('input', typed);
  el.text.addEventListener('input', typed);
  el.title.addEventListener('blur', flushSave);
  el.text.addEventListener('blur', flushSave);
  // Enter in the title goes on to the text, as in Keep
  el.title.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); el.text.focus(); }
  });

  function setPreview(on) {
    var label = on ? t('notes.edit') : t('notes.preview');
    el.preview.setAttribute('aria-pressed', on ? 'true' : 'false');
    el.preview.title = label;
    el.preview.setAttribute('aria-label', label);
    el.preview.querySelector('i').className = 'ph ' + (on ? 'ph-pencil-simple' : 'ph-eye');
    el.editorNote.setAttribute('data-mode', on ? 'preview' : 'edit');
    el.text.hidden = on;
    el.title.readOnly = on;
    el.tagInput.readOnly = on;
    el.previewPane.hidden = !on;
  }

  el.preview.addEventListener('click', function () {
    if (el.preview.getAttribute('aria-pressed') === 'true') { setPreview(false); return; }
    flushSave();
    setPreview(true);
    el.previewPane.textContent = '';
    api('POST', '/render', { markdown: el.text.value, breaks: true }).then(function (r) {
      if (r.status === 200 && r.data) el.previewPane.innerHTML = r.data.html; // the server's renderer, escaped there
      else throw new Error('render ' + r.status);
    }).catch(function () {
      var pre = document.createElement('pre');
      pre.textContent = el.text.value;
      el.previewPane.textContent = '';
      el.previewPane.appendChild(pre);
      notice('info', t('notes.preview_offline'), false);
    });
  });

  el.del.addEventListener('click', function () {
    var note = state.notes[state.current];
    if (!note || !window.confirm(t('notes.delete_confirm'))) return;
    clearTimeout(timers.save);
    timers.save = 0;
    keep(Object.assign({}, note, { deleted: true, dirty: true })).then(function () {
      state.current = null;
      if (location.hash === '#/') route(); else location.hash = '#/';
      scheduleSync(0);
    });
  });

  el.add.addEventListener('click', function () {
    var path = state.user.notes + ':' + ulid();
    keep({ path: path, title: '', baseTitle: '', tags: state.tag ? [state.tag] : [], baseTags: [], body: '', base: '', rev: 0, meta: {}, dirty: false, deleted: false, conflict: false, updated: Date.now() }).then(function () {
      location.hash = '#/n/' + encodeURIComponent(path.slice(state.user.notes.length + 1));
    });
  });

  el.search.addEventListener('input', renderList);
  el.sync.addEventListener('click', function () { scheduleSync(0); });
  el.syncNow.addEventListener('click', function () { closeMenu(); scheduleSync(0); });

  // ---- the menu ----

  function closeMenu() {
    el.menu.hidden = true;
    el.menuButton.setAttribute('aria-expanded', 'false');
  }

  el.menuButton.addEventListener('click', function (event) {
    event.stopPropagation();
    var open = el.menu.hidden;
    el.menu.hidden = !open;
    el.menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function (event) {
    if (!el.menu.hidden && !el.menu.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (!el.menu.hidden) { closeMenu(); return; }
    // Escape leaves a note, as Back does
    if (state.current && location.hash !== '#/') location.hash = '#/';
  });

  function route() {
    if (!state.user) { show('signin'); return; }
    // Any slug: the app makes ULIDs, a note written in Reporion by hand may have another
    var m = /^#\/n\/([^\/]+)$/.exec(location.hash);
    var slug = null;
    try { slug = m ? decodeURIComponent(m[1]) : null; } catch (e) { slug = null; }
    var target = slug && slug.indexOf(':') === -1 ? state.user.notes + ':' + slug : null;
    if (state.current && state.current !== target) {
      // Leaving a note never typed into: nothing to keep. What was typed is saved first:
      // keep() updates the note in memory at once, so the check below sees it
      flushSave();
      var left = state.notes[state.current];
      // (a tag the list's filter gave it is no typing: a tag added by hand makes the note dirty)
      if (left && !left.rev && !left.dirty && left.body === '' && !left.title) forget(left.path);
    }
    if (target) { openEditor(target); return; }
    closeEditor();
  }

  window.addEventListener('hashchange', route);

  // ---- account ----

  function start(account) {
    var switching = !state.user || state.user.username !== account.username;
    state.user = account;
    try { localStorage.setItem(USER_KEY, JSON.stringify(account)); } catch (e) { /* asked again next visit */ }
    if (!switching) return Promise.resolve();
    return openDb(account.username).then(function (db) {
      state.db = db;
      if (!db) notice('warn', t('notes.storage_failed'), true);
      return loadAll();
    }).then(function () {
      route();
      showStatus();
      offerInstall();
    });
  }

  function signedOut() {
    // The notes stay in this browser: signing in again sends what is unsynced
    state.user = null;
    state.current = null;
    try { localStorage.removeItem(USER_KEY); } catch (e) { /* nothing kept */ }
    show('signin');
    showStatus();
  }

  el.signin.addEventListener('submit', function (event) {
    event.preventDefault();
    var form = new FormData(el.signin);
    api('POST', '/session', { username: String(form.get('username') || ''), password: String(form.get('password') || '') }).then(function (r) {
      if (r.status !== 200 || !r.data) { notice('error', t('auth.invalid'), false); return null; }
      el.signin.reset();
      state.online = true;
      return start(r.data.data).then(function () { return sync(); });
    }).catch(function () { offline(); notice('error', t('notes.offline'), false); });
  });

  el.signOut.addEventListener('click', function () {
    flushSave();
    var unsynced = Object.keys(state.notes).some(function (path) { return state.notes[path].dirty; });
    if (unsynced && !window.confirm(t('notes.sign_out_unsynced'))) return;
    var name = state.user.username;
    api('DELETE', '/session').catch(function () { /* the cookie goes when it expires */ }).then(function () {
      if (state.db) state.db.close();
      state.db = null;
      state.notes = {};
      try { indexedDB.deleteDatabase('reporion-notes-' + name); } catch (e) { /* left for the next sign-in */ }
      signedOut();
    });
  });

  window.addEventListener('online', function () { state.online = true; scheduleSync(0); });
  window.addEventListener('offline', offline);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') scheduleSync(0);
    else flushSave();
  });
  setInterval(function () { if (document.visibilityState === 'visible') sync(); }, SYNC_EVERY);

  // ---- install: the browser's own prompt where there is one, else how to do it by hand ----

  var INSTALL_KEY = 'reporion.notes.install';
  var INSTALL_AGAIN = 30 * 24 * 3600000;
  // Desktop Chromium may hold its install event back (until the page has been used for a while):
  // past this wait the banner says how to install from the address bar or the menu instead
  var INSTALL_WAIT = 8000;
  var install = { prompt: null, card: null, timer: 0 };

  function installed() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  }

  /**
   * How this browser installs a web app without an event of ours: 'ios' (Safari: Share → Add to
   * Home Screen; other iOS browsers cannot), 'mac' (Safari on macOS: File → Add to Dock), 'menu'
   * (Chromium: the address bar's install icon or its menu), or null (Firefox and others: no install)
   */
  function installWay() {
    var ua = navigator.userAgent;
    var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios) return /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua) ? 'ios' : null;
    if ('onbeforeinstallprompt' in window) return 'menu';
    if (/Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Firefox/.test(ua)) return 'mac';
    return null;
  }

  var INSTALL_HINT = { ios: 'notes.install_ios', mac: 'notes.install_mac', menu: 'notes.install_desktop' };

  function installPostponed() {
    var at = 0;
    try { at = Number(localStorage.getItem(INSTALL_KEY) || 0); } catch (e) { at = 0; }
    return at > 0 && Date.now() - at < INSTALL_AGAIN;
  }

  /** The menu's Install item: wherever this browser can install the app and it is not installed yet */
  function showInstallItem() {
    el.install.hidden = installed() || (!install.prompt && installWay() === null);
  }

  function closeInstall() {
    if (install.card) install.card.remove();
    install.card = null;
  }

  function runInstall() {
    if (!install.prompt) return;
    var prompt = install.prompt;
    install.prompt = null;
    closeInstall();
    prompt.prompt();
    // A dismissed dialog postpones nothing of ours: the browser counts its own refusals
    prompt.userChoice.catch(function () { /* nothing to undo */ }).then(showInstallItem);
  }

  /** The banner's Install button, once the browser has handed over its prompt */
  function withInstallButton(card) {
    if (card.querySelector('[data-install-now]')) return;
    card.querySelector('.nt-banner-body').firstChild.textContent = t('notes.install_ready');
    action(card, t('notes.install'), true, runInstall).setAttribute('data-install-now', '');
  }

  function installBanner(text) {
    install.card = notice('info', text, true);
    action(install.card, t('notes.install_later'), false, function () {
      try { localStorage.setItem(INSTALL_KEY, String(Date.now())); } catch (e) { /* this visit only */ }
      closeInstall();
    });
    return install.card;
  }

  /**
   * The banner, once signed in, unless installed or postponed by "Not now": with the browser's
   * prompt an Install button; without one, how to install by hand (Chromium only after
   * INSTALL_WAIT, in case its event is merely late); one banner, gaining the button if the event comes
   */
  function offerInstall() {
    showInstallItem();
    if (!state.user || installed() || installPostponed()) return;
    if (install.prompt) {
      withInstallButton(install.card || installBanner(t('notes.install_ready')));
      return;
    }
    var way = installWay();
    if (install.card || way === null) return;
    if (way !== 'menu') { installBanner(t(INSTALL_HINT[way])); return; }
    if (!install.timer) {
      install.timer = setTimeout(function () {
        if (!install.card && !install.prompt && !installed() && !installPostponed()) installBanner(t(INSTALL_HINT.menu));
      }, INSTALL_WAIT);
    }
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    // Kept for our own banner and the menu instead of the browser's mini bar
    event.preventDefault();
    install.prompt = event;
    offerInstall();
  });
  window.addEventListener('appinstalled', function () {
    install.prompt = null;
    clearTimeout(install.timer);
    closeInstall();
    showInstallItem();
    notice('ok', t('notes.installed'), false);
  });
  el.install.addEventListener('click', function () {
    closeMenu();
    if (install.prompt) { runInstall(); return; }
    var way = installWay();
    if (way) notice('info', t(INSTALL_HINT[way]), false);
  });
  showInstallItem();

  // ---- updates: a new worker waits until the writer reloads into it ----

  var UPDATE_EVERY = 3600000;
  var update = { offered: false, reloading: false };

  /** What a worker says it is: {version, build}, or null when it does not answer */
  function workerVersion(worker) {
    return new Promise(function (resolve) {
      var channel = new MessageChannel();
      var timer = setTimeout(function () { resolve(null); }, 3000);
      channel.port1.onmessage = function (event) { clearTimeout(timer); resolve(event.data || null); };
      worker.postMessage({ type: 'version' }, [channel.port2]);
    });
  }

  function offerUpdate(worker) {
    if (update.offered) return;
    workerVersion(worker).then(function (info) {
      // Online, the shell is fetched fresh, so this page may already be the new build: switch quietly
      if (info && info.build === cfg.build) { worker.postMessage({ type: 'skip-waiting' }); return; }
      if (update.offered) return;
      update.offered = true;
      var name = info ? info.version + ' (' + info.build + ')' : '';
      var card = notice('info', t('notes.update_ready').replace('%s', name), true);
      var button = action(card, t('notes.update_reload'), true, function () {
        button.disabled = true;
        // What is typed is kept first; the reload follows the new worker taking over
        flushSave().then(function () {
          update.reloading = true;
          worker.postMessage({ type: 'skip-waiting' });
        });
      });
    });
  }

  if ('serviceWorker' in navigator && cfg.sw) {
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      // Only a takeover the writer asked for reloads (the first install's claim does not)
      if (update.reloading) location.reload();
    });
    navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope }).then(function (registration) {
      if (registration.waiting && navigator.serviceWorker.controller) offerUpdate(registration.waiting);
      registration.addEventListener('updatefound', function () {
        var worker = registration.installing;
        if (!worker) return;
        worker.addEventListener('statechange', function () {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) offerUpdate(worker);
        });
      });
      function check() { if (navigator.onLine !== false) registration.update().catch(function () { /* next time */ }); }
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') check(); });
      window.addEventListener('online', check);
      setInterval(check, UPDATE_EVERY);
    }).catch(function () { /* online only, then */ });
  }

  // ---- boot: the last account straight away (offline too), then the server's word ----

  applyTheme();
  var remembered = null;
  try { remembered = JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch (e) { remembered = null; }
  (remembered && remembered.username && remembered.notes ? start(remembered) : Promise.resolve()).then(function () {
    if (!state.user) show('signin');
    return api('GET', '/session');
  }).then(function (r) {
    state.online = true;
    if (r.status === 200 && r.data) {
      return start(r.data.data).then(function () { return sync(); });
    }
    if (r.status === 401) signedOut();
    return null;
  }).catch(function () {
    offline();
  });
})();
