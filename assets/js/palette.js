/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The palette (⌘K): docs/architecture-api.md A1: "a small script, no
 * framework, that calls /api/v1/search and navigates. It is the only
 * JavaScript on an otherwise static report page, and it degrades to a
 * plain /search form link if it fails to load."
 *
 * Progressively enhances the existing .wk-search form (present in every
 * signed-in template already, submitting a plain GET to /search) rather
 * than replacing it with a separate overlay: no markup has to exist only
 * for JavaScript, and the degrade-if-this-file-never-loads case is simply
 * "the form still works exactly as before": nothing to special-case.
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
    // The empty state: home, root, pins, the modality's namespaces
    // (Http\QuickNav): the same list as the top nav's pin menu
    var quick = Array.isArray(config.quick) ? config.quick : [];
    // Commands (a word, then Tab): `new {site} {modality}` and plain ones
    var commands = Array.isArray(config.commands) ? config.commands : [];
    var newTargets = Array.isArray(config.newTargets) ? config.newTargets : [];
    var groups = config.groups && typeof config.groups === 'object' ? config.groups : {};

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
      var lastGroup = null;
      results.forEach(function (result, index) {
        if ((result.quick || result.command) && result.group !== lastGroup && groups[result.group]) {
          var heading = document.createElement('div');
          heading.className = 'wk-quick-h';
          var eyebrow = document.createElement('span');
          eyebrow.className = 'wk-eyebrow';
          eyebrow.textContent = groups[result.group];
          heading.appendChild(eyebrow);
          dropdown.appendChild(heading);
        }
        lastGroup = result.group;
        var row = document.createElement('a');
        row.className = 'wk-pal-row' + (index === activeIndex ? ' wk-sel' : '');
        row.href = basePath + '/' + result.path;
        if (result.complete) {
          row.setAttribute('data-complete', result.complete);
        }

        var title = document.createElement('div');
        title.className = 'wk-row-t';
        if (result.icon) {
          var icon = document.createElement('i');
          icon.className = 'ph ph-' + result.icon;
          title.appendChild(icon);
        }
        title.appendChild(document.createTextNode(result.title || result.path));
        row.appendChild(title);

        if (!result.quick && !result.command) {
          var path = document.createElement('div');
          path.className = 'wk-row-m wk-mono';
          path.textContent = result.path;
          row.appendChild(path);
        }

        if (result.hint) {
          var hintEl = document.createElement('div');
          hintEl.className = 'wk-row-m wk-mono';
          hintEl.textContent = result.hint;
          row.appendChild(hintEl);
        }

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

    function showQuick() {
      if (currentController) {
        currentController.abort();
      }
      results = quick.map(function (link) {
        return { path: link.path, title: link.title, icon: link.icon, group: link.group, quick: true };
      });
      activeIndex = -1;
      renderResults();
    }

    // The commands the typed text asks for: the first word is a command's
    // name (or the start of one), the rest its arguments, each a prefix of
    // the modality or the site, in any order: `new medima mri`.
    function matchCommands(term) {
      var words = term.toLowerCase().split(/\s+/).filter(Boolean);
      if (words.length === 0) {
        return { items: [], only: false };
      }
      var hasArgs = /\s/.test(term) && words.length > 1 || /\s$/.test(term);
      var items = [];
      var only = false;
      commands.forEach(function (cmd) {
        if (words[0] !== cmd.name && !(words.length === 1 && !hasArgs && words[0].length >= 2 && cmd.name.indexOf(words[0]) === 0)) {
          return;
        }
        var exact = words[0] === cmd.name && (hasArgs || words.length > 1);
        if (exact) {
          only = true;
        }
        var args = words.slice(1);
        if (cmd.name === 'new' && exact) {
          newTargets.forEach(function (target) {
            var ok = args.every(function (arg) {
              return target.modality.toLowerCase().indexOf(arg) === 0 || (target.site !== '' && target.site.toLowerCase().indexOf(arg) === 0);
            });
            if (ok && items.length < 12) {
              var label = target.site !== '' ? target.site + ' ' + target.modality : target.modality;
              items.push({ command: true, group: 'commands', icon: cmd.icon, title: cmd.title + ' · ' + label, hint: target.path, path: target.path + '/new', complete: 'new ' + label });
            }
          });
          return;
        }
        if (args.length === 0) {
          items.push({ command: true, group: 'commands', icon: cmd.icon, title: cmd.title, hint: cmd.name, path: cmd.path, complete: cmd.name + (cmd.args ? ' ' : '') });
        }
      });
      return { items: items, only: only };
    }

    function search(term) {
      if (term === '') {
        showQuick();
        return;
      }
      var cmds = matchCommands(term);
      if (cmds.only) {
        // A command with its arguments: only what it offers, no search
        if (currentController) {
          currentController.abort();
        }
        results = cmds.items;
        activeIndex = results.length > 0 ? 0 : -1;
        renderResults();
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
          results = cmds.items.concat(Array.isArray(json.data) ? json.data.slice(0, 8) : []);
          activeIndex = -1;
          renderResults();
        })
        .catch(function () {
          // A failed fetch (network error, abort) leaves the plain form
          // usable: Enter still submits it normally.
        });
    }

    var debouncedSearch = debounce(function () {
      search(input.value.trim());
    }, 150);

    input.addEventListener('input', debouncedSearch);
    input.addEventListener('focus', function () {
      if (input.value.trim() === '') {
        showQuick();
      }
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Tab' && !event.shiftKey && results.length > 0 && input.value.trim() !== '') {
        // Tab completes to the highlighted command, or the first one offered
        var pick = results[activeIndex >= 0 ? activeIndex : 0];
        if (pick && pick.complete) {
          event.preventDefault();
          input.value = pick.complete;
          search(input.value);
          return;
        }
      }
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
