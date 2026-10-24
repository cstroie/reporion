/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The notes app's service worker (D55), served as /notes/sw.js by
 * NotesController::serviceWorker(), which puts PRECACHE (the shell and the
 * versioned assets it loads), CACHE (a name that changes with them) and
 * BASE (the base path) before this text. The shell and the assets come from
 * the cache, so the app opens offline; the API is never cached: notes live
 * in the page's IndexedDB, and nothing the API answers is kept here.
 */
/* global PRECACHE, CACHE, BASE */
'use strict';

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (names) {
    return Promise.all(names.filter(function (name) { return name.indexOf('notes-') === 0 && name !== CACHE; }).map(function (name) { return caches['delete'](name); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

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
