/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Similar reports (roadmap phase 34e): the report page's panel asks
 * GET /api/v1/pages/{path}/similar once the page is shown and lists what
 * comes back — exam title, date, modality and summary, each a link to the
 * report; never the patient's name. Read only.
 */
(function () {
  'use strict';

  var mount = document.querySelector('[data-island="similar"]');
  var configEl = document.getElementById('similar-config');
  if (!mount || !configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var list = mount.querySelector('[data-similar-list]');
  var s = config.strings;

  function note(text) {
    list.textContent = '';
    var li = document.createElement('li');
    li.className = 'wk-mono wk-dim';
    li.textContent = text;
    list.appendChild(li);
  }

  fetch(config.basePath + '/api/v1/pages/' + config.path + '/similar', {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  }).then(function (response) {
    if (!response.ok) throw new Error(String(response.status));
    return response.json();
  }).then(function (json) {
    var rows = json.data || [];
    if (rows.length === 0) { note(s.none); return; }
    list.textContent = '';
    rows.forEach(function (row) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = config.basePath + '/' + row.path;
      a.textContent = row.exam_title || s.untitled;
      li.appendChild(a);
      var meta = [row.study_date ? String(row.study_date).slice(0, 10) : '', row.modality || '', Math.round(row.score * 100) + '%'].filter(Boolean).join(' · ');
      var small = document.createElement('span');
      small.className = 'wk-mono wk-dim';
      small.textContent = ' ' + meta;
      li.appendChild(small);
      if (row.summary) {
        var p = document.createElement('p');
        p.className = 'wk-dim';
        p.textContent = row.summary;
        li.appendChild(p);
      }
      list.appendChild(li);
    });
  }).catch(function () { note(s.failed); });
})();
