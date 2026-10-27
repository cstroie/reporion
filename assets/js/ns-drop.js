/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A Markdown file dropped on a namespace view makes a page there, named by the file
 * (the server lowercases it, Support\Slug). The file's text is read here and sent to
 * POST /new as `markdown` (NewPageController), so the page is made exactly as by the
 * new-page form's file field: private, its visibility never taken from the file.
 * Without JavaScript nothing changes.
 */
(function () {
  'use strict';

  var zone = document.querySelector('[data-ns-drop]');
  if (!zone || typeof FileReader === 'undefined') return;

  var max = parseInt(zone.getAttribute('data-max'), 10) || 1000000;
  var label = zone.querySelector('span');
  var hint = label.textContent;
  var depth = 0;
  var timer = null;

  function hasFiles(event) {
    var types = event.dataTransfer && event.dataTransfer.types;
    return !!types && Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  function show(text) {
    window.clearTimeout(timer);
    label.textContent = text;
    zone.hidden = false;
  }

  function hide() {
    depth = 0;
    zone.hidden = true;
    label.textContent = hint;
  }

  function problem(text) {
    show(text);
    timer = window.setTimeout(hide, 3000);
  }

  function send(name, text) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = zone.getAttribute('data-action');
    var ns = zone.getAttribute('data-ns');
    [['path', ns === '' ? '' : ns + ':'], ['filename', name], ['markdown', text]].forEach(function (pair) {
      var field = document.createElement('textarea');
      field.name = pair[0];
      field.value = pair[1];
      form.appendChild(field);
    });
    form.hidden = true;
    document.body.appendChild(form);
    form.submit();
  }

  document.addEventListener('dragenter', function (event) {
    if (!hasFiles(event)) return;
    depth += 1;
    show(hint);
  });
  document.addEventListener('dragover', function (event) {
    if (!hasFiles(event)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
  });
  document.addEventListener('dragleave', function (event) {
    if (!hasFiles(event)) return;
    depth -= 1;
    if (depth <= 0) hide();
  });
  document.addEventListener('drop', function (event) {
    if (!hasFiles(event)) return;
    event.preventDefault();
    hide();
    var files = Array.prototype.slice.call(event.dataTransfer.files || []);
    var file = files.filter(function (f) { return /\.(md|markdown|txt)$/i.test(f.name); })[0];
    if (!file) return problem(zone.getAttribute('data-bad'));
    if (file.size > max) return problem(zone.getAttribute('data-size'));
    var reader = new FileReader();
    reader.onload = function () { send(file.name, String(reader.result)); };
    reader.onerror = function () { problem(zone.getAttribute('data-read')); };
    reader.readAsText(file, 'UTF-8');
  });
})();
