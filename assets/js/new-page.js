/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The new-page form's Markdown file: choosing one reads its text into the hidden
 * `markdown` field, which the server takes as the page's content (NewPageController).
 * The field is hidden in the markup and shown here, so without JavaScript the form
 * is just the form it was.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-new-upload]');
  if (!box || typeof FileReader === 'undefined') return;

  var input = box.querySelector('input[type="file"]');
  var target = box.querySelector('textarea[name="markdown"]');
  var error = box.querySelector('[data-new-upload-error]');
  var max = parseInt(box.getAttribute('data-max'), 10) || 1000000;

  function fail(message) {
    target.value = '';
    input.value = '';
    error.textContent = message || '';
    error.hidden = !message;
  }

  box.hidden = false;
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    fail('');
    if (!file) return;
    if (file.size > max) return fail(error.getAttribute('data-size'));
    var reader = new FileReader();
    reader.onload = function () { target.value = String(reader.result); };
    reader.onerror = function () { fail(error.getAttribute('data-read')); };
    reader.readAsText(file, 'UTF-8');
  });
})();
