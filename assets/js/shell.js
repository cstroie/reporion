/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The app shell's small enhancements (templates/layout.php, A6). Each one
 * degrades to plain HTML without JavaScript:
 *  - the page header's copy-id button copies the pid;
 *  - Escape closes any open <details> menu;
 *  - a[data-busy] / form[data-busy]: the link or the submit button shows a
 *    spinner (aria-busy) while the next page loads: for routes that wait on
 *    a slow service; a second submit is ignored;
 *  - form[data-autosubmit] submits on change (its <noscript> button is the
 *    fallback);
 *  - input[data-check-all="name"] (rendered hidden) ticks or clears every
 *    checkbox of that name in its form and shows their state;
 *  - form[data-appearance] (the top nav's Dark | Light and palette): the
 *    choice is posted in the background and applied in place, so the menu
 *    stays open to compare or switch back: the POST still sets the cookie;
 *  - details[name] is an accordion (Pico's pattern): opening one closes the
 *    others of that name. Current browsers do it natively; this repeats it
 *    for older ones.
 *  - the page tabs fold into their "current ▾" menu whenever the row would
 *    wrap (plugin tabs make its width vary), not only below a breakpoint.
 */
(function () {
  'use strict';

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
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
        navigator.clipboard.writeText(id).then(done, function () { /* not copied: no "Copied" */ });
      } else {
        var area = document.createElement('textarea');
        area.value = id;
        document.body.appendChild(area);
        area.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (err) { copied = false; }
        document.body.removeChild(area);
        if (copied) done();
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

  document.querySelectorAll('form[data-appearance]').forEach(function (form) {
    var kind = form.getAttribute('data-appearance');
    form.addEventListener('submit', function (event) {
      var button = event.submitter;
      if (!button || !button.value || !window.fetch) return;
      event.preventDefault();
      var body = new URLSearchParams(new FormData(form));
      body.set(button.name, button.value);
      // The plain form post, the choice carried (form.submit() sends no submitter)
      function post() {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = button.name;
        input.value = button.value;
        form.appendChild(input);
        form.submit();
      }
      // manual: the 302 back is not followed: its Set-Cookie is still stored.
      // Anything else (an error page, a lost session) is not applied as if
      // it were kept: the form posts the ordinary way and shows what happened
      fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin', redirect: 'manual' })
        .then(function (response) {
          if (response.type === 'opaqueredirect' || response.ok) {
            apply(form, kind, button);
          } else {
            post();
          }
        })
        .catch(post);
    });
  });

  function apply(form, kind, chosen) {
    var classes = document.body.classList;
    if (kind === 'theme') {
      classes.toggle('theme-light', chosen.value === 'light');
      form.querySelectorAll('button[name="theme"]').forEach(function (b) {
        b.setAttribute('aria-pressed', b === chosen ? 'true' : 'false');
      });
      return;
    }
    Array.prototype.slice.call(classes).forEach(function (c) {
      if (c.indexOf('palette-') === 0) classes.remove(c);
    });
    if (chosen.value !== form.getAttribute('data-default')) classes.add('palette-' + chosen.value);
    var check = form.querySelector('.ph-check');
    if (check) chosen.appendChild(check);
  }

  document.addEventListener('toggle', function (event) {
    var opened = event.target;
    if (!opened.matches || !opened.matches('details[name]') || !opened.open) return;
    document.querySelectorAll('details[name="' + opened.getAttribute('name') + '"][open]').forEach(function (other) {
      if (other !== opened) other.open = false;
    });
  }, true);

  // The page tab row: one line or the menu, never two lines (the admin row, .wk-pagetabs-wrap, wraps by design)
  document.querySelectorAll('.wk-pagetabs:not(.wk-pagetabs-wrap)').forEach(function (bar) {
    var row = bar.querySelector('.wk-pagetabs-scroll');
    if (!row) return;
    var fit = function () {
      bar.classList.remove('wk-pagetabs-fold');
      var tabs = row.querySelectorAll('.wk-tab');
      if (tabs.length < 2 || row.offsetParent === null) return; // hidden: a phone has the menu already
      if (tabs[tabs.length - 1].offsetTop > tabs[0].offsetTop) bar.classList.add('wk-pagetabs-fold');
    };
    fit();
    if (window.ResizeObserver) new ResizeObserver(fit).observe(bar); else window.addEventListener('resize', fit);
  });
}());
