/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The notes app's service worker (D55), served as /notes/sw.js by
 * NotesController::serviceWorker(), which puts PRECACHE (the shell and the
 * versioned assets it loads), VERSION and BUILD (the app's), CACHE (a name
 * made of both) and BASE (the base path) before this text. The shell and
 * the assets come from the cache, so the app opens offline; the API is
 * never cached: notes live in the page's IndexedDB, and nothing the API
 * answers is kept here.
 *
 * Updates: a new worker installs beside the running one and waits (no
 * skipWaiting on install), so an open page keeps the version it started
 * with; the page offers a reload and then sends `skip-waiting`. The first
 * install has nothing to wait for and takes over at once. `version` answers
 * the page with VERSION and BUILD on the port it sends.
 *
 * Share target: the manifest names POST /notes/share for the system's share
 * sheet. The worker answers it itself: the shared title, text and URL are
 * kept in a cache of their own (SHARED, a list, so quick shares are all
 * kept) and the app opens; the page takes them once an account is signed in
 * and makes each a new note. Nothing shared is sent to the server here, and
 * sharing works offline.
 */
/* global PRECACHE, VERSION, BUILD, CACHE, BASE */
'use strict';

var SHARED = 'notes-share';
var SHARED_KEY = BASE + '/notes/share/pending';
var SHARED_MAX = 100000;

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(PRECACHE); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (names) {
    return Promise.all(names.filter(function (name) { return name.indexOf('notes-') === 0 && name !== CACHE && name !== SHARED; }).map(function (name) { return caches['delete'](name); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('message', function (event) {
  var type = event.data && event.data.type;
  if (type === 'skip-waiting') self.skipWaiting();
  if (type === 'version' && event.ports && event.ports[0]) event.ports[0].postMessage({ version: VERSION, build: BUILD });
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (request.method === 'POST' && url.pathname === BASE + '/notes/share') {
    event.respondWith(share(request));
    return;
  }
  if (request.method !== 'GET') return;

  // The shell: the network first (a new version when online), the cached copy offline
  if (url.pathname === BASE + '/notes' || url.pathname === BASE + '/notes/') {
    event.respondWith(fetch(request).then(function (response) {
      if (response.ok) {
        var copy = response.clone();
        caches.open(CACHE).then(function (cache) { cache.put(BASE + '/notes', copy); });
      }
      return response;
    }).catch(function () {
      return caches.match(BASE + '/notes');
    }));
    return;
  }

  // Assets (CSS, JS, fonts, icons): the cache first, kept once fetched
  if (url.pathname.indexOf(BASE + '/assets/') === 0) {
    event.respondWith(caches.match(request).then(function (hit) {
      return hit || fetch(request).then(function (response) {
        if (response.ok) {
          var copy = response.clone();
          caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      });
    }));
  }
  // Everything else, the API included, goes to the network untouched
});

/** A share from the system's sheet: kept for the page, then the app opens */
function share(request) {
  function field(form, name) {
    var value = form.get(name);
    return typeof value === 'string' ? value.trim().slice(0, SHARED_MAX) : '';
  }
  return request.formData().then(function (form) {
    var item = { title: field(form, 'title'), text: field(form, 'text'), url: field(form, 'url'), at: Date.now() };
    if (!item.title && !item.text && !item.url) return null;
    return caches.open(SHARED).then(function (cache) {
      return cache.match(SHARED_KEY).then(function (hit) {
        return hit ? hit.json() : [];
      }).then(function (list) {
        list = Array.isArray(list) ? list : [];
        list.push(item);
        return cache.put(SHARED_KEY, new Response(JSON.stringify(list), { headers: { 'Content-Type': 'application/json' } }));
      });
    });
  }).catch(function () { /* the app opens without it */ }).then(function () {
    return Response.redirect(BASE + '/notes', 303);
  });
}
