/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The app shell's small enhancements (templates/layout.php, A6). Each one
 * degrades to plain HTML without JavaScript:
 *  - ☰ opens the namespace drawer instead of following its link to the
 *    namespace index;
 *  - the page header's copy-id button copies the pid;
 *  - Escape closes the drawer and any open <details> menu.
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
}());
