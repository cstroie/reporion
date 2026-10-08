/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Shared modal plumbing (2026-10-08; the look is .wk-modal in wiki.css).
 *  - [data-modal-close] inside a <dialog> closes it (the header's ×).
 *  - ReporionModal.busy(caption): the busy modal — a spinner and a caption
 *    naming what it waits for; no title, no footer, no close (Escape is
 *    held too). Returns { caption(text), close() }; the caller closes it
 *    when the wait is over, whatever its outcome. One instance, built on
 *    first use; a handle from an earlier wait never closes a later one.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-modal-close]');
    var dialog = button && button.closest('dialog');
    if (dialog && dialog.open) dialog.close();
  });

  var dialog = null;
  var text = null;
  var turn = 0;

  function build() {
    dialog = document.createElement('dialog');
    dialog.className = 'wk-modal wk-modal-busy';
    dialog.setAttribute('aria-busy', 'true');
    dialog.setAttribute('aria-labelledby', 'wk-modal-busy-caption');
    var main = document.createElement('div');
    main.className = 'wk-modal-main';
    var spinner = document.createElement('span');
    spinner.className = 'wk-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    text = document.createElement('p');
    text.className = 'wk-modal-caption';
    text.id = 'wk-modal-busy-caption';
    text.setAttribute('role', 'status');
    main.appendChild(spinner);
    main.appendChild(text);
    dialog.appendChild(main);
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); });
    document.body.appendChild(dialog);
  }

  function busy(caption) {
    var none = { caption: function () {}, close: function () {} };
    if (typeof HTMLDialogElement === 'undefined') return none;
    if (!dialog) build();
    var mine = ++turn;
    text.textContent = caption;
    if (!dialog.open) dialog.showModal();
    return {
      caption: function (value) { if (mine === turn) text.textContent = value; },
      close: function () { if (mine === turn && dialog.open) dialog.close(); }
    };
  }

  window.ReporionModal = { busy: busy };
})();
