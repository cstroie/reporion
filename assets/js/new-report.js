/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The guided new-report form (templates/new-report.php): previews the page
 * path and what the CNP says while typing, and narrows the device and
 * template lists to the chosen site and modality. A convenience only —
 * the server (Service\NewReport) recomputes and validates everything, and
 * the form works without this script.
 */
(function () {
  'use strict';

  var WEIGHTS = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];

  // Support\Cnp, for the preview
  function cnpInfo(cnp, examDate) {
    if (!/^[1-9]\d{12}$/.test(cnp)) return null;
    var sum = 0;
    for (var i = 0; i < 12; i++) sum += Number(cnp[i]) * WEIGHTS[i];
    var check = sum % 11 === 10 ? 1 : sum % 11;
    if (check !== Number(cnp[12])) return null;
    var s = Number(cnp[0]);
    var yy = Number(cnp.slice(1, 3));
    var century = s <= 2 ? 1900 : s <= 4 ? 1800 : s <= 6 ? 2000 : (2000 + yy > new Date().getFullYear() ? 1900 : 2000);
    var born = new Date(Date.UTC(century + yy, Number(cnp.slice(3, 5)) - 1, Number(cnp.slice(5, 7))));
    if (born.getUTCMonth() !== Number(cnp.slice(3, 5)) - 1 || born.getUTCDate() !== Number(cnp.slice(5, 7))) return null;
    var at = examDate ? new Date(examDate + 'T00:00:00Z') : new Date();
    var age = at.getUTCFullYear() - born.getUTCFullYear();
    if (at.getUTCMonth() < born.getUTCMonth() || (at.getUTCMonth() === born.getUTCMonth() && at.getUTCDate() < born.getUTCDate())) age--;
    return { sex: s <= 8 ? (s % 2 ? 'M' : 'F') : null, born: born.getUTCFullYear(), age: age >= 0 ? age : null };
  }

  // Support\Slug, for the preview
  function slug(text) {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64).replace(/-+$/, '');
  }

  // Case and diacritics do not matter when searching: "genu" finds "Genunchi", "sold" finds "Șold"
  function fold(text) {
    return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  }

  function format(template, args) {
    var i = 0;
    return template.replace(/%[sd]/g, function () { var v = args[i++]; return v === null || v === undefined ? '—' : String(v); });
  }

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { fn.apply(null, args); }, delay);
    };
  }

  function mount(form) {
    var config = {};
    try { config = JSON.parse(document.getElementById(form.getAttribute('data-config-id')).textContent); } catch (e) { return; }
    var s = config.strings || {};
    var field = function (name) { return form.elements[name]; };
    var pathEl = document.getElementById('nr-path');
    var accessionEl = document.getElementById('nr-accession');
    var cnpInfoEl = document.getElementById('nr-cnp-info');
    var ageEl = document.getElementById('nr-age');
    var search = document.getElementById('nr-template-search');
    if (search) {
      search.hidden = false;
      // Enter narrows, it does not submit the form
      search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') event.preventDefault();
      });
    }
    var initial = { modality: field('modality').value, site: field('site').value, year: (field('date').value || '').slice(2, 4) };

    function update() {
      var modality = field('modality').value;
      var site = field('site').value;
      var date = field('date').value;
      var name = field('name').value.trim();

      // The chosen modality's templates only, narrowed by the search box —
      // matching the template's title/path text or its namespace ("ct"
      // finds "templates:ct:…" even when the title says nothing like it)
      var query = fold(search ? search.value : '');
      Array.prototype.forEach.call(form.querySelectorAll('#nr-templates [data-modality]'), function (row) {
        var ns = fold(row.getAttribute('data-ns') || '');
        row.hidden = (modality !== '' && row.getAttribute('data-modality') !== modality)
          || (query !== '' && fold(row.textContent).indexOf(query) === -1 && ns.indexOf(query) === -1);
        if (row.hidden && row.querySelector('input').checked) form.querySelector('#nr-templates input[value=""]').checked = true;
      });
      // The other exams' template lists too (phase 12)
      Array.prototype.forEach.call(form.querySelectorAll('.wk-more-exam option[data-modality]'), function (option) {
        option.hidden = modality !== '' && option.getAttribute('data-modality') !== modality;
        if (option.hidden && option.selected) option.parentNode.value = '';
      });
      Array.prototype.forEach.call(field('device').options, function (option) {
        if (!option.value) return;
        option.hidden = site !== '' && option.getAttribute('data-site') !== site;
        if (option.hidden && option.selected) field('device').value = '';
      });

      var ns = (config.modalities || {})[modality];
      var namePart = name.length >= 2 ? slug(name) : '';
      if (ns && site && /^\d{4}-\d{2}-\d{2}$/.test(date) && namePart) {
        pathEl.textContent = 'reports:' + ns + ':' + site + ':' + date.slice(2, 4) + date.slice(5, 7) + date.slice(8, 10) + '-' + namePart;
      } else {
        pathEl.innerHTML = '';
        var pending = document.createElement('span');
        pending.className = 'wk-dim';
        pending.textContent = s.pathPending || '';
        pathEl.appendChild(pending);
      }
      if (modality !== initial.modality || site !== initial.site || date.slice(2, 4) !== initial.year) {
        accessionEl.textContent = s.accessionStale || '';
      }

      var cnp = field('cnp').value.replace(/\s+/g, '');
      var age = null;
      if (cnp === '') {
        cnpInfoEl.textContent = s.cnpHelp || '';
      } else {
        var info = cnpInfo(cnp, date);
        cnpInfoEl.textContent = info ? format(s.derived || '', [info.sex, info.born, info.age]) : (cnp.length >= 13 ? s.cnpInvalid : s.cnpHelp) || '';
        if (info && info.sex) field('sex').value = info.sex;
        if (info) field('born').value = info.born;
        age = info ? info.age : null;
      }
      // Without a CNP (or while it is incomplete), a hand-typed birth year
      // still gives an approximate age — no day/month to be exact about
      if (age === null) {
        var born = Number(field('born').value);
        if (born) {
          var atYear = date ? Number(date.slice(0, 4)) : new Date().getFullYear();
          age = atYear - born;
        }
      }
      if (ageEl) ageEl.value = age !== null && age >= 0 ? age : '';
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();

    // Real-time "is this patient already known?" check (TODO 13): as the
    // name is typed, search existing pages by the same title text — the
    // already-built /api/v1/search palette endpoint, not a new one. A
    // convenience only, same spirit as the CNP/path preview above: it
    // never blocks creating the page, it just surfaces candidates to look
    // at first.
    var nameEl = document.getElementById('nr-name');
    var matchesEl = document.getElementById('nr-name-matches');
    var basePath = typeof config.basePath === 'string' ? config.basePath : '';
    if (nameEl && matchesEl) {
      var matchList = matchesEl.querySelector('ul');
      var currentController = null;

      function clearMatches() {
        matchesEl.hidden = true;
        matchList.innerHTML = '';
      }

      function renderMatches(items) {
        matchList.innerHTML = '';
        if (items.length === 0) {
          clearMatches();
          return;
        }
        items.forEach(function (item) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          a.href = basePath + '/' + item.path;
          a.target = '_blank';
          a.rel = 'noopener';
          a.textContent = item.title || item.path;
          li.appendChild(a);
          var path = document.createElement('span');
          path.className = 'wk-mono wk-dim';
          path.textContent = item.path;
          li.appendChild(path);
          matchList.appendChild(li);
        });
        matchesEl.hidden = false;
      }

      var checkName = debounce(function () {
        var name = nameEl.value.trim();
        if (currentController) currentController.abort();
        if (name.length < 3) {
          clearMatches();
          return;
        }
        currentController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var folded = fold(name);
        fetch(basePath + '/api/v1/search?q=' + encodeURIComponent(name), {
          headers: { Accept: 'application/json' },
          signal: currentController ? currentController.signal : undefined
        })
          .then(function (response) { return response.ok ? response.json() : { data: [] }; })
          .then(function (json) {
            var items = Array.isArray(json.data) ? json.data : [];
            // Narrowed to an actual title match — full-text search also
            // matches the name inside a report's body text
            items = items.filter(function (item) { return fold(item.title || '').indexOf(folded) !== -1; });
            renderMatches(items.slice(0, 5));
          })
          .catch(function () {
            // A failed fetch leaves the plain form usable — this is a
            // convenience layer only, never a hard requirement to create
          });
      }, 300);

      nameEl.addEventListener('input', checkName);
    }
  }

  var forms = document.querySelectorAll('[data-island="new-report"]');
  for (var i = 0; i < forms.length; i++) mount(forms[i]);
}());
