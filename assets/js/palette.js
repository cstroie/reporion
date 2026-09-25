/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The palette (⌘K) — docs/architecture-api.md A1: "a small script, no
 * framework, that calls /api/v1/search and navigates. It is the only
 * JavaScript on an otherwise static report page, and it degrades to a
 * plain /search form link if it fails to load."
 *
 * Progressively enhances the existing .wk-search form (present in every
 * signed-in template already, submitting a plain GET to /search) rather
 * than replacing it with a separate overlay: no markup has to exist only
 * for JavaScript, and the degrade-if-this-file-never-loads case is simply
 * "the form still works exactly as before" — nothing to special-case.
 *
 * Vanilla JS, no build step, no dependency (CLAUDE.md "Frontend"). Mounts
 * on every [data-island="palette"] found on the page; there is only ever
 * one per page today, but nothing here assumes that.
 */
(function () {
  'use strict';

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        fn.apply(null, args);
      }, delay);
    };
  }

  function mount(form) {
    var configEl = document.getElementById(form.getAttribute('data-config-id'));
    var config = {};
    try {
      config = configEl ? JSON.parse(configEl.textContent) : {};
    } catch (e) {
      return; // malformed config: leave the plain form alone entirely
    }
    var basePath = typeof config.basePath === 'string' ? config.basePath : '';

    var input = form.querySelector('input[name="q"]');
    if (!input) {
      return;
    }

    var dropdown = document.createElement('div');
    dropdown.className = 'wk-pal-drop';
    dropdown.hidden = true;
    form.appendChild(dropdown);

    var results = [];
    var activeIndex = -1;
    var currentController = null;

    function close() {
      dropdown.hidden = true;
      dropdown.innerHTML = '';
      results = [];
      activeIndex = -1;
    }

    function renderResults() {
      dropdown.innerHTML = '';
      if (results.length === 0) {
        dropdown.hidden = true;
        return;
      }
      results.forEach(function (result, index) {
        var row = document.createElement('a');
        row.className = 'wk-pal-row' + (index === activeIndex ? ' wk-sel' : '');
        row.href = basePath + '/' + result.path;

        var title = document.createElement('div');
        title.className = 'wk-row-t';
        title.textContent = result.title || result.path;
        row.appendChild(title);

        var path = document.createElement('div');
        path.className = 'wk-row-m wk-mono';
        path.textContent = result.path;
        row.appendChild(path);

        if (result.snippet) {
          var snippet = document.createElement('div');
          snippet.className = 'wk-row-s';
          snippet.textContent = result.snippet;
          row.appendChild(snippet);
        }

        dropdown.appendChild(row);
      });
      dropdown.hidden = false;
    }

    function search(term) {
      if (term === '') {
        close();
        return;
      }
      if (currentController) {
        currentController.abort();
      }
      currentController = typeof AbortController !== 'undefined' ? new AbortController() : null;

      fetch(basePath + '/api/v1/search?q=' + encodeURIComponent(term), {
        headers: { Accept: 'application/json' },
        signal: currentController ? currentController.signal : undefined
      })
        .then(function (response) {
          return response.ok ? response.json() : { data: [] };
        })
        .then(function (json) {
          results = Array.isArray(json.data) ? json.data.slice(0, 8) : [];
          activeIndex = -1;
          renderResults();
        })
        .catch(function () {
          // A failed fetch (network error, abort) leaves the plain form
          // usable — Enter still submits it normally.
        });
    }

    var debouncedSearch = debounce(function () {
      search(input.value.trim());
    }, 150);

    input.addEventListener('input', debouncedSearch);

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (!dropdown.hidden) {
          event.preventDefault();
          close();
        }
        return;
      }
      if (results.length === 0) {
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex = Math.min(activeIndex + 1, results.length - 1);
        renderResults();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        renderResults();
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        window.location.href = basePath + '/' + results[activeIndex].path;
      }
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) {
        close();
      }
    });

    var usesMeta = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
    // The hint says what this platform's shortcut actually is
    var hint = form.querySelector('.wk-kbd');
    if (hint) {
      if (!usesMeta) {
        hint.textContent = 'Ctrl K';
      }
      hint.hidden = false;
    }

    document.addEventListener('keydown', function (event) {
      var shortcutHeld = usesMeta ? event.metaKey : event.ctrlKey;
      if (shortcutHeld && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.focus();
        input.select();
      }
    });
  }

  var forms = document.querySelectorAll('[data-island="palette"]');
  for (var i = 0; i < forms.length; i++) {
    mount(forms[i]);
  }
})();
