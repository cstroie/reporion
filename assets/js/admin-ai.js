/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → AI's server cards (roadmap phase 33c): *Get models* fills the
 * card's model list (a <datalist>, so a Model field offers the server's
 * models and still takes one typed by hand), *Test* asks each alias once
 * and shows what came back. Both use the card's saved settings
 * (POST /admin/ai/servers/{slot}/models|test) — the key never reaches the
 * browser — and never send report text. Without JavaScript the buttons stay
 * hidden and the fields are plain text inputs.
 */
(function () {
  'use strict';

  var configEl = document.getElementById('admin-ai-config');
  if (!configEl) return;
  var config;
  try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
  var s = config.strings;

  function post(slot, what) {
    return fetch(config.basePath + '/admin/ai/servers/' + slot + '/' + what, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().then(function (json) {
        if (!response.ok) throw new Error(json.error && json.error.message ? json.error.message : s.failed);
        return json;
      });
    });
  }

  function format(template, values) {
    var i = 0;
    return template.replace(/%d/g, function () { return String(values[i++]); });
  }

  document.querySelectorAll('[data-ai-card]').forEach(function (bar) {
    var slot = bar.getAttribute('data-ai-card');
    var out = bar.querySelector('[data-ai-out]');
    var results = document.querySelector('[data-ai-results="' + slot + '"]');
    var list = document.getElementById('ai-models-' + slot);
    var getModels = bar.querySelector('[data-ai-get-models]');
    var test = bar.querySelector('[data-ai-test]');
    bar.hidden = false;
    bar.title = s.saveFirst;

    function busy(button, on) {
      if (on) button.setAttribute('aria-busy', 'true'); else button.removeAttribute('aria-busy');
      getModels.disabled = on;
      test.disabled = on;
    }

    getModels.addEventListener('click', function () {
      busy(getModels, true);
      out.textContent = s.working;
      post(slot, 'models').then(function (json) {
        busy(getModels, false);
        if (json.error) { out.textContent = json.error; return; }
        list.innerHTML = '';
        json.data.forEach(function (model) {
          var option = document.createElement('option');
          option.value = model;
          list.appendChild(option);
        });
        // This card's Model fields now offer this card's list
        document.querySelectorAll('[data-ai-model="' + slot + '"]').forEach(function (input) {
          input.setAttribute('list', list.id);
        });
        out.textContent = format(s.models, [json.data.length, json.total]);
      }).catch(function (error) {
        busy(getModels, false);
        out.textContent = error.message || s.failed;
      });
    });

    test.addEventListener('click', function () {
      busy(test, true);
      out.textContent = s.working;
      results.hidden = true;
      results.innerHTML = '';
      post(slot, 'test').then(function (json) {
        busy(test, false);
        out.textContent = '';
        var table = document.createElement('table');
        table.className = 'table wk-ai-test';
        json.data.forEach(function (row) {
          var tr = table.insertRow();
          tr.setAttribute('data-ok', row.ok ? '1' : '0');
          var icon = document.createElement('i');
          icon.className = 'ph ' + (row.ok ? 'ph-check-circle' : 'ph-warning');
          icon.setAttribute('aria-hidden', 'true');
          tr.insertCell().appendChild(icon);
          tr.insertCell().textContent = row.tier;
          var model = tr.insertCell();
          model.className = 'wk-mono';
          model.textContent = row.model + (row.listed === false ? ' — ' + s.unlisted : '');
          var said = tr.insertCell();
          said.textContent = row.ok ? s.ok + (row.answer ? ': ' + row.answer : '') + ' · ' + (row.ms / 1000).toFixed(1) + ' s' : (row.error || s.failed);
        });
        results.appendChild(table);
        results.hidden = false;
      }).catch(function (error) {
        busy(test, false);
        out.textContent = error.message || s.failed;
      });
    });
  });
})();
