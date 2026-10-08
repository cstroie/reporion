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
 * The markup, with the default title and button texts, is the
 * <template id="wk-modal-confirm"> in templates/layout.php.
 * Without JavaScript the form simply submits, as before.
 */
(function () {
  'use strict';

  var template = document.getElementById('wk-modal-confirm');
  if (typeof HTMLDialogElement === 'undefined' || !template) return;

  var dialog = null;
  var parts = null;

  function build() {
    dialog = template.content.firstElementChild.cloneNode(true);
    document.body.appendChild(dialog);
    parts = {
      title: dialog.querySelector('[data-modal-title]'),
      body: dialog.querySelector('[data-modal-body]'),
      cancel: dialog.querySelector('footer [data-modal-close]'),
      ok: dialog.querySelector('[data-modal-ok]')
    };
    parts.defaults = { title: parts.title.textContent, ok: parts.ok.textContent };
    // × and Cancel (data-modal-close) and a click on the backdrop (the
    // dialog element itself) cancel
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || event.target.closest('[data-modal-close]')) dialog.close();
    });
  }

  function ask(form, submitter) {
    if (!dialog) build();
    var tone = form.getAttribute('data-confirm-tone') === 'primary' ? 'btn-primary' : 'btn-danger';
    parts.title.textContent = form.getAttribute('data-confirm-title') || parts.defaults.title;
    parts.body.textContent = form.getAttribute('data-confirm');
    parts.ok.className = 'btn ' + tone;
    parts.ok.textContent = form.getAttribute('data-confirm-label') || parts.defaults.ok;
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
