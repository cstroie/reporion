/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A small icon-only "Copy" button, top-right of every rendered code block
 * (TODO 13) — the page view, the public layout, compare, the namespace
 * description, and the editor's live preview (called again after every
 * re-render there, since its innerHTML is replaced wholesale). Copies the
 * block's own text, never a button label a screen reader would otherwise
 * read back as code.
 */
(function () {
  'use strict';

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var tmp = document.createElement('textarea');
    tmp.value = text;
    tmp.setAttribute('readonly', '');
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    try { document.execCommand('copy'); } catch (err) { /* ignore */ }
    document.body.removeChild(tmp);

    return Promise.resolve();
  }

  function enhance(root) {
    (root || document).querySelectorAll(':is(.wk-prose, .wk-preview) pre:not(.wk-code-done)').forEach(function (pre) {
      pre.classList.add('wk-code-done');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wk-code-copy';
      btn.setAttribute('aria-label', 'Copy');
      btn.innerHTML = '<i class="ph ph-copy" aria-hidden="true"></i>';
      btn.addEventListener('click', function () {
        var code = pre.querySelector('code') || pre;
        copyText(code.textContent).then(function () {
          btn.classList.add('wk-code-copied');
          setTimeout(function () { btn.classList.remove('wk-code-copied'); }, 1200);
        });
      });
      pre.appendChild(btn);
    });
  }

  window.ReporionCopyCode = { enhance: enhance };
  document.addEventListener('DOMContentLoaded', function () { enhance(document); });
}());
