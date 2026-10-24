/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The notes app (D55): an offline-first client of the JSON API, mounted on
 * /notes. A note is an ordinary page under the account's own namespace,
 * users:{username}:notes:{ulid}, made with a ULID on the device so that a
 * note created offline has its path before the server has seen it.
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
    sync: root.querySelector('[data-sync]'),
    theme: root.querySelector('[data-theme-toggle]'),
    signOut: root.querySelector('[data-sign-out]'),
    notices: root.querySelector('[data-notices]'),
    signin: root.querySelector('[data-signin]'),
    search: root.querySelector('[data-search]'),
    add: root.querySelector('[data-new]'),
    items: root.querySelector('[data-items]'),
    empty: root.querySelector('[data-empty]'),
    text: root.querySelector('[data-text]'),
    preview: root.querySelector('[data-preview]'),
    previewLabel: root.querySelector('[data-preview-label]'),
    previewPane: root.querySelector('[data-preview-pane]'),
    del: root.querySelector('[data-delete]'),
    conflict: root.querySelector('[data-conflict]'),
    views: root.querySelectorAll('[data-view]')
  };

  var state = {
    user: null,      // {username, display_name, notes}
    db: null,        // IndexedDB, or null when the browser refuses one (notes then live in memory)
    notes: {},       // path -> note
    current: null,   // the path in the editor
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
    var palette = /(?:^|;\s*)reporion_palette=([a-z-]+)/.exec(document.cookie);
    if (palette && palette[1] !== 'royal-blue') document.body.classList.add('palette-' + palette[1]);
    var label = t('notes.theme') + ': ' + t('notes.theme_' + setting);
    el.theme.title = label;
    el.theme.setAttribute('aria-label', label);
    var icon = el.theme.querySelector('i');
    icon.className = 'ph ' + ({ system: 'ph-circle-half', light: 'ph-sun', dark: 'ph-moon' })[setting];
  }

  el.theme.addEventListener('click', function () {
    var next = ({ system: 'light', light: 'dark', dark: 'system' })[themeSetting()] || 'system';
    try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* this visit only */ }
    applyTheme();
  });
  if (window.matchMedia) {
    var scheme = window.matchMedia('(prefers-color-scheme: light)');
    if (scheme.addEventListener) scheme.addEventListener('change', applyTheme);
  }

  // ---- notices (the notice card's markup, templates/partials/notice.php) ----

  function notice(kind, text, keep) {
    var icon = ({ info: 'info', ok: 'check-circle', warn: 'warning', error: 'warning-octagon' })[kind] || 'info';
    var card = document.createElement('div');
    card.className = 'wk-notice wk-notice-' + kind;
    card.setAttribute('role', kind === 'warn' || kind === 'error' ? 'alert' : 'status');
    var i = document.createElement('i');
    i.className = 'ph ph-' + icon;
    i.setAttribute('aria-hidden', 'true');
    var body = document.createElement('div');
    body.textContent = text;
    card.appendChild(i);
    card.appendChild(body);
    el.notices.appendChild(card);
    if (!keep) setTimeout(function () { card.remove(); }, 5000);
    return card;
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

  function fromServer(page, previous) {
    return {
      path: page.path,
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
      .filter(function (note) { return !note.deleted && (q === '' || note.body.toLowerCase().indexOf(q) !== -1); })
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
    if (note.deleted) {
      if (!note.rev) return forget(note.path);
      return api('DELETE', '/pages/' + note.path).then(check401).then(function (r) {
        if (r.status === 200 || r.status === 404) return forget(note.path);
        throw new Error('delete ' + r.status);
      });
    }
    if (!note.rev) {
      return api('POST', '/pages', { path: note.path, body: sent }).then(check401).then(function (r) {
        if (r.status === 201) return settled(note.path, r.data, sent);
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
    return api('PUT', '/pages/' + note.path, { meta: note.meta || {}, body: sent, base_rev: note.rev, merge: true }).then(check401).then(function (r) {
      if (r.status === 200) {
        if (r.data && r.data.merged) notice('info', t('notes.edited') + ': ' + titleOf(r.data.body), false);
        return settled(note.path, r.data, sent);
      }
      if (r.status === 409 && r.data && r.data.current) return conflicted(note.path, r.data, sent);
      if (r.status === 404) {
        // Deleted on the server (or another device), changed here: made again, the text kept
        notice('warn', t('notes.recreated') + ': ' + titleOf(sent), false);
        return keep(Object.assign({}, state.notes[note.path], { rev: 0, base: '' })).then(function () { return push(state.notes[note.path]); });
      }
      throw new Error('save ' + r.status);
    });
  }

  /** The server took the note: its revision is the new base; text typed meanwhile stays dirty */
  function settled(path, page, sent) {
    var local = state.notes[path];
    if (!local) return Promise.resolve();
    var next = fromServer(page, local);
    if (local.body !== sent) {
      next.body = local.body;
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
    next.dirty = true;
    next.conflict = true;
    next.updated = Date.now();
    notice('warn', t('notes.conflict') + ': ' + titleOf(current.body), false);
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
          if (row.path.indexOf(prefix) !== 0) return;
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
    el.sync.hidden = !state.user;
  }

  // ---- views ----

  function show(name) {
    Array.prototype.forEach.call(el.views, function (view) { view.hidden = view.getAttribute('data-view') !== name; });
    el.signOut.hidden = !state.user;
  }

  function renderList() {
    if (!state.user || state.current) return;
    var notes = visibleNotes();
    el.items.textContent = '';
    notes.forEach(function (note) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'nt-item';
      a.href = '#/n/' + note.path.slice(state.user.notes.length + 1);
      var title = document.createElement('span');
      title.className = 'nt-item-title';
      title.textContent = titleOf(note.body);
      var meta = document.createElement('span');
      meta.className = 'nt-item-meta';
      meta.textContent = new Date(note.updated || Date.now()).toLocaleString();
      a.appendChild(title);
      a.appendChild(meta);
      if (note.dirty) {
        var mark = document.createElement('i');
        mark.className = 'ph ' + (note.conflict ? 'ph-warning' : 'ph-cloud-slash') + ' nt-item-flag';
        mark.title = note.conflict ? t('notes.conflict') : t('notes.unsynced').replace('%d', '1');
        a.appendChild(mark);
      }
      li.appendChild(a);
      el.items.appendChild(li);
    });
    el.empty.hidden = notes.length > 0;
  }

  function openEditor(path) {
    var note = state.notes[path];
    if (!note || note.deleted) {
      notice('warn', t('notes.not_found'), false);
      location.hash = '#/';
      return;
    }
    state.current = path;
    show('editor');
    setPreview(false);
    el.text.value = note.body;
    showConflict(note);
    if (!note.body) el.text.focus();
  }

  function refreshEditorFromStore() {
    if (!state.current) return;
    var note = state.notes[state.current];
    if (!note) { closeEditor(); return; }
    // Only text the writer has not touched since is replaced
    if (document.activeElement !== el.text && el.text.value !== note.body) el.text.value = note.body;
    showConflict(note);
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
    var card = notice('warn', t('notes.conflict_markers'), true);
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm';
    button.textContent = t('notes.conflict_resolved');
    button.addEventListener('click', function () {
      flushSave();
      var current = state.notes[state.current];
      if (MARKER.test(current.body) && !window.confirm(t('notes.conflict_markers'))) return;
      keep(Object.assign({}, current, { conflict: false })).then(function () {
        showConflict(state.notes[state.current]);
        scheduleSync(0);
      });
    });
    card.querySelector('div').appendChild(document.createTextNode(' '));
    card.querySelector('div').appendChild(button);
    el.conflict.appendChild(card);
  }

  function flushSave() {
    if (!timers.save) return;
    clearTimeout(timers.save);
    timers.save = 0;
    saveLocal();
  }

  function saveLocal() {
    timers.save = 0;
    var note = state.notes[state.current];
    if (!note || note.body === el.text.value) return;
    keep(Object.assign({}, note, { body: el.text.value, dirty: true, updated: Date.now() })).then(function () {
      showStatus();
      scheduleSync();
    });
  }

  el.text.addEventListener('input', function () {
    clearTimeout(timers.save);
    timers.save = setTimeout(saveLocal, SAVE_DELAY);
  });
  el.text.addEventListener('blur', flushSave);

  function setPreview(on) {
    el.preview.setAttribute('aria-pressed', on ? 'true' : 'false');
    el.previewLabel.textContent = on ? t('notes.edit') : t('notes.preview');
    el.text.hidden = on;
    el.previewPane.hidden = !on;
  }

  el.preview.addEventListener('click', function () {
    if (el.preview.getAttribute('aria-pressed') === 'true') { setPreview(false); return; }
    flushSave();
    setPreview(true);
    el.previewPane.textContent = '';
    api('POST', '/render', { markdown: el.text.value }).then(function (r) {
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
      location.hash = '#/';
      scheduleSync(0);
    });
  });

  el.add.addEventListener('click', function () {
    var path = state.user.notes + ':' + ulid();
    keep({ path: path, body: '', base: '', rev: 0, meta: {}, dirty: false, deleted: false, conflict: false, updated: Date.now() }).then(function () {
      location.hash = '#/n/' + path.slice(state.user.notes.length + 1);
    });
  });

  el.search.addEventListener('input', renderList);
  el.sync.addEventListener('click', function () { scheduleSync(0); });

  function route() {
    if (!state.user) { show('signin'); return; }
    var m = /^#\/n\/([0-9a-z]+)$/.exec(location.hash);
    if (m) { openEditor(state.user.notes + ':' + m[1]); return; }
    if (state.current) {
      // Leaving a note never typed into: nothing to keep
      var left = state.notes[state.current];
      flushSave();
      if (left && !left.rev && !left.dirty && left.body === '') forget(left.path);
    }
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

  if ('serviceWorker' in navigator && cfg.sw) {
    navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope }).catch(function () { /* online only, then */ });
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
