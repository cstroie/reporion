/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The table of contents beside the text (templates/partials/toc.php): marks
 * the entry of the section in view — the last heading scrolled past the top
 * of the reading area — with aria-current="location", on scroll (one update
 * per frame). Progressive enhancement: without this script the table of
 * contents is plain links.
 */
(function () {
  'use strict';

  var nav = document.querySelector('[data-island="toc"]');
  if (!nav) return;

  var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
  var headings = [];
  links.forEach(function (link) {
    var heading = document.getElementById(decodeURIComponent(link.getAttribute('href').slice(1)));
    if (heading) headings.push({ el: heading, link: link });
  });
  if (headings.length === 0) return;

  var topbar = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--wk-topbar-h')) || 0;

  function mark(link) {
    links.forEach(function (other) {
      if (other === link) {
        other.setAttribute('aria-current', 'location');
      } else {
        other.removeAttribute('aria-current');
      }
    });
  }

  // The current section: the last heading whose top is above the reading line
  function update() {
    var line = topbar + 24;
    var current = headings[0];
    for (var i = 0; i < headings.length; i++) {
      if (headings[i].el.getBoundingClientRect().top - line <= 0) current = headings[i];
    }
    // Scrolled to the very end: the last section, even if its heading never reached the line
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
      current = headings[headings.length - 1];
    }
    mark(current.link);
  }

  var pending = false;
  window.addEventListener('scroll', function () {
    if (pending) return;
    pending = true;
    window.requestAnimationFrame(function () { pending = false; update(); });
  }, { passive: true });
  window.addEventListener('hashchange', update);
  update();
}());
