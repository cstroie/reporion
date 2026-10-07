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

  // highlight.js (~200 KB) is fetched only when the page has a code block to
  // colour — its URL is this script tag's data-hljs (layout.php, layout-public.php)
  var HLJS = document.currentScript ? document.currentScript.getAttribute('data-hljs') : null;

  function highlight() {
    if (!HLJS || !document.querySelector('pre code:not(.nohighlight)')) { return; }
    if (window.hljs) { window.hljs.highlightAll(); return; }
    var s = document.createElement('script');
    s.src = HLJS;
    s.onload = function () { if (window.hljs) { window.hljs.highlightAll(); } };
    document.head.appendChild(s);
  }

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

  // Rendered text (the whole page, or one ## section): copied as HTML and as plain text, so it
  // pastes formatted into a mail or a document and as text into a plain field; the copy buttons
  // themselves are left out
  function copyNodes(nodes) {
    var html = nodes.map(function (node) {
      if (node.nodeType !== 1) { return node.textContent; }
      var clone = node.cloneNode(true);
      clone.querySelectorAll('.wk-code-copy, .wk-section-copy').forEach(function (b) { b.remove(); });
      return clone.outerHTML;
    }).join('');
    // innerText keeps the line breaks, and the headings' capitals as shown on screen
    var text = nodes.map(function (node) { return (node.nodeType === 1 ? node.innerText : node.textContent).trim(); })
      .filter(function (t) { return t !== ''; }).join('\n\n');
    if (navigator.clipboard && window.ClipboardItem) {
      return navigator.clipboard.write([new ClipboardItem({
        'text/html': new Blob([html], { type: 'text/html' }),
        'text/plain': new Blob([text], { type: 'text/plain' })
      })]).catch(function () { return copyText(text); });
    }

    return copyText(text);
  }

  function flash(btn) {
    btn.classList.add('wk-code-copied');
    setTimeout(function () { btn.classList.remove('wk-code-copied'); }, 1200);
  }

  // The whole text: the tab row's Copy (page-header.php)
  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('[data-copy-prose]') : null;
    var prose = btn ? document.querySelector('.wk-prosebox .wk-prose') : null;
    if (!btn || !prose) { return; }
    copyNodes(Array.prototype.slice.call(prose.childNodes)).then(function () { flash(btn); });
  });

  // One button per ## heading of the page view: that section, from its ## to the next ## (or #)
  function enhanceSections() {
    var box = document.querySelector('.wk-prosebox[data-copy-section]');
    if (!box) { return; }
    box.querySelectorAll('.wk-prose > h2').forEach(function (h2) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wk-section-copy';
      btn.title = box.dataset.copySection;
      btn.setAttribute('aria-label', box.dataset.copySection);
      btn.innerHTML = '<i class="ph ph-copy" aria-hidden="true"></i>';
      btn.addEventListener('click', function () {
        var nodes = [h2];
        for (var n = h2.nextSibling; n && !(n.nodeType === 1 && /^H[12]$/.test(n.tagName)); n = n.nextSibling) { nodes.push(n); }
        copyNodes(nodes).then(function () { flash(btn); });
      });
      h2.insertBefore(btn, h2.firstChild);
    });
  }

  window.ReporionCopyCode = { enhance: enhance };
  document.addEventListener('DOMContentLoaded', function () { enhance(document); enhanceSections(); highlight(); });
}());
