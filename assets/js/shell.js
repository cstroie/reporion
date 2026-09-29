/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The app shell's small enhancements (templates/layout.php, A6). Each one
 * degrades to plain HTML without JavaScript:
 *  - ☰ opens the namespace drawer instead of following its link to the
 *    namespace index;
 *  - the page header's copy-id button copies the pid;
 *  - Escape closes the drawer and any open <details> menu;
 *  - a[data-busy] / form[data-busy]: the link or the submit button shows a
 *    spinner (aria-busy) while the next page loads — for routes that wait on
 *    a slow service; a second submit is ignored;
 *  - form[data-autosubmit] submits on change (its <noscript> button is the
 *    fallback);
 *  - input[data-check-all="name"] (rendered hidden) ticks or clears every
 *    checkbox of that name in its form and shows their state.
 */
(function () {
  'use strict';

  var drawer = document.getElementById('wk-drawer');
  var backdrop = document.querySelector('.wk-drawer-backdrop');
  var opener = null;

  function openDrawer(trigger) {
    opener = trigger;
    drawer.hidden = false;
    backdrop.hidden = false;
    var first = drawer.querySelector('a, button');
    if (first) first.focus();
  }

  function closeDrawer() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden = true;
    backdrop.hidden = true;
    if (opener) opener.focus();
  }

  if (drawer && backdrop) {
    document.querySelectorAll('[data-drawer-open]').forEach(function (trigger) {
      trigger.addEventListener('click', function (event) {
        event.preventDefault();
        openDrawer(trigger);
      });
    });
    document.querySelectorAll('[data-drawer-close]').forEach(function (el) {
      el.addEventListener('click', closeDrawer);
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    closeDrawer();
    document.querySelectorAll('details.wk-menu-wrap[open]').forEach(function (menu) {
      menu.open = false;
    });
  });

  // One open menu at a time; a click outside closes it
  document.addEventListener('click', function (event) {
    document.querySelectorAll('details.wk-menu-wrap[open]').forEach(function (menu) {
      if (!menu.contains(event.target)) menu.open = false;
    });
  });

  document.querySelectorAll('[data-copy-id]').forEach(function (button) {
    button.addEventListener('click', function () {
      var id = button.getAttribute('data-copy-id');
      var title = button.getAttribute('title');
      function done() {
        button.setAttribute('title', button.getAttribute('data-copied') || title);
        setTimeout(function () { button.setAttribute('title', title); }, 2000);
      }
      if (navigator.clipboard) {
        navigator.clipboard.writeText(id).then(done);
      } else {
        var area = document.createElement('textarea');
        area.value = id;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        document.body.removeChild(area);
        done();
      }
    });
  });

  function busy(el) {
    el.setAttribute('data-busy', '');
    el.setAttribute('aria-busy', 'true');
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a[data-busy]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') return;
    busy(link);
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.hasAttribute || !form.hasAttribute('data-busy') || event.defaultPrevented) return;
    if (form.getAttribute('aria-busy') === 'true') {
      event.preventDefault();
      return;
    }
    form.setAttribute('aria-busy', 'true');
    var button = event.submitter || form.querySelector('[type="submit"]');
    if (button) busy(button);
  });

  // Back to a page kept in the back/forward cache: nothing is loading any more
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('[aria-busy="true"]').forEach(function (el) {
      if (el.hasAttribute('data-busy') || el.tagName === 'FORM') el.removeAttribute('aria-busy');
    });
  });

  document.querySelectorAll('form[data-autosubmit]').forEach(function (form) {
    form.addEventListener('change', function () { form.submit(); });
  });

  document.querySelectorAll('input[data-check-all]').forEach(function (all) {
    var form = all.form;
    if (!form) return;
    var name = all.getAttribute('data-check-all');
    function boxes() {
      return Array.prototype.filter.call(form.elements, function (el) {
        return el.type === 'checkbox' && el.name === name && !el.disabled;
      });
    }
    function sync() {
      var list = boxes();
      var on = list.filter(function (el) { return el.checked; }).length;
      all.checked = list.length > 0 && on === list.length;
      all.indeterminate = on > 0 && on < list.length;
    }
    var wrap = all.closest('[hidden]');
    if (wrap) wrap.hidden = false;
    all.addEventListener('change', function () {
      boxes().forEach(function (el) { el.checked = all.checked; });
      all.indeterminate = false;
    });
    form.addEventListener('change', function (event) {
      if (event.target !== all && event.target.name === name) sync();
    });
    sync();
  });
}());
