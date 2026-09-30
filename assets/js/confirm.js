/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * One confirmation modal for every risky form. Put data-confirm="message" on
 * a <form>; submitting it opens a native <dialog> (centred, backdrop, focus
 * trap and Escape for free) and only a click on the confirm button sends the
 * form on. Optional attributes on the form:
 *   data-confirm-title   heading (default: the generic "Are you sure?")
 *   data-confirm-label   confirm button text (default: "Confirm")
 *   data-confirm-tone    "danger" (default) or "primary" — the confirm button's variant
 * Strings for the default title, "Confirm" and "Cancel" come from the
 * <script type="application/json" id="confirm-config"> next to the scripts.
 * Without JavaScript the form simply submits, as before.
 */
(function () {
  'use strict';

  var config = { title: 'Are you sure?', ok: 'Confirm', cancel: 'Cancel' };
  var node = document.getElementById('confirm-config');
  if (node) {
    try { config = Object.assign(config, JSON.parse(node.textContent)); } catch (err) { /* keep the defaults */ }
  }
  if (typeof HTMLDialogElement === 'undefined') return;

  var dialog = null;
  var parts = null;

  function build() {
    dialog = document.createElement('dialog');
    dialog.className = 'wk-modal';
    dialog.setAttribute('aria-labelledby', 'wk-modal-title');

    var title = document.createElement('h2');
    title.className = 'wk-modal-title';
    title.id = 'wk-modal-title';
    var body = document.createElement('p');
    body.className = 'wk-modal-body';
    var actions = document.createElement('div');
    actions.className = 'wk-actions wk-modal-actions';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-secondary';
    cancel.textContent = config.cancel;
    var ok = document.createElement('button');
    ok.type = 'button';

    actions.appendChild(cancel);
    actions.appendChild(ok);
    dialog.appendChild(title);
    dialog.appendChild(body);
    dialog.appendChild(actions);
    document.body.appendChild(dialog);

    cancel.addEventListener('click', function () { dialog.close(); });
    // A click on the backdrop (the dialog element itself) cancels too
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });

    parts = { title: title, body: body, cancel: cancel, ok: ok };
  }

  function ask(form, submitter) {
    if (!dialog) build();
    var tone = form.getAttribute('data-confirm-tone') === 'primary' ? 'btn-primary' : 'btn-danger';
    parts.title.textContent = form.getAttribute('data-confirm-title') || config.title;
    parts.body.textContent = form.getAttribute('data-confirm');
    parts.ok.className = 'btn ' + tone;
    parts.ok.textContent = form.getAttribute('data-confirm-label') || config.ok;
    parts.ok.onclick = function () {
      dialog.close();
      form.setAttribute('data-confirmed', '1');
      // requestSubmit keeps the clicked button's name/value and validation
      if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
      else form.submit();
    };
    dialog.showModal();
    // The safe choice takes focus: Enter cancels, it never confirms by accident
    parts.cancel.focus();
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
    if (form.getAttribute('data-confirmed') === '1') {
      form.removeAttribute('data-confirmed');
      return;
    }
    event.preventDefault();
    ask(form, event.submitter);
  }, true);
})();
