/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Shared modal plumbing (2026-10-08; the look is .wk-modal in wiki.css).
 *  - [data-modal-close] inside a <dialog> closes it (the header's ×).
 *  - ReporionModal.busy(caption, onTimeout): the busy modal — a spinner and
 *    a caption naming what it waits for; no title, no footer, no close
 *    (Escape is held too). Returns { caption(text), close() }; the caller
 *    closes it when the wait is over, whatever its outcome. A wait past
 *    LIMIT closes it and calls onTimeout, which stops the request and
 *    shows the error in a dialog.
 *  - ReporionModal.alert(title, message): a dialog with the message as an
 *    error and a Close button. One instance, cloned on
 *    first use; a handle from an earlier wait never closes a later one.
 *    The button that started the wait gets no ring of its own (no
 *    data-busy): the modal is the one sign of it.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-modal-close]');
    var dialog = button && button.closest('dialog');
    if (dialog && dialog.open) dialog.close();
  });

  var LIMIT = 120000; // ms: large — a local model on a long report is slow — but not forever
  var dialog = null;
  var text = null;
  var turn = 0;
  var alertDialog = null;

  // The markup is the <template id="wk-modal-busy"> in templates/layout.php
  function build() {
    var template = document.getElementById('wk-modal-busy');
    if (!template) return;
    dialog = template.content.firstElementChild.cloneNode(true);
    text = dialog.querySelector('.wk-modal-caption');
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); });
    document.body.appendChild(dialog);
  }

  function busy(caption, onTimeout) {
    var none = { caption: function () {}, close: function () {} };
    if (typeof HTMLDialogElement === 'undefined') return none;
    if (!dialog) build();
    if (!dialog) return none;
    var mine = ++turn;
    text.textContent = caption;
    if (!dialog.open) dialog.showModal();
    var timer = setTimeout(function () {
      if (mine !== turn) return;
      if (dialog.open) dialog.close();
      if (onTimeout) onTimeout();
    }, LIMIT);
    return {
      caption: function (value) { if (mine === turn) text.textContent = value; },
      close: function () {
        clearTimeout(timer);
        if (mine === turn && dialog.open) dialog.close();
      }
    };
  }

  // The markup is the <template id="wk-modal-alert"> in templates/layout.php
  function alert(title, message) {
    if (typeof HTMLDialogElement === 'undefined') return;
    if (!alertDialog) {
      var template = document.getElementById('wk-modal-alert');
      if (!template) return;
      alertDialog = template.content.firstElementChild.cloneNode(true);
      document.body.appendChild(alertDialog);
    }
    alertDialog.querySelector('h2').textContent = title;
    alertDialog.querySelector('.wk-modal-main > p').textContent = message;
    if (!alertDialog.open) alertDialog.showModal();
  }

  window.ReporionModal = { busy: busy, alert: alert };
})();
