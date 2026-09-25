/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor preview's marked.js configuration — the one place it lives.
 * Loaded by templates/editor.php in the browser and required by
 * tools/render-with-marked.js, so tests/Render/RenderConformanceTest (D17)
 * gates exactly the parser setup users see.
 *
 * Mirrors Service\Render: generic CommonMark + GFM tables, raw HTML escaped
 * rather than passed through (html_input: escape), and no javascript:,
 * vbscript: or data: URLs (allow_unsafe_links: false).
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionPreview = factory();
  }
}(this, function () {
  'use strict';

  var UNSAFE_URL = /^\s*(javascript|vbscript|data):/i;

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
  }

  function configure(marked) {
    marked.use({
      gfm: true,
      breaks: false,
      renderer: {
        html: function (token) {
          return escapeHtml(typeof token === 'string' ? token : token.text);
        },
        // Unsafe URL: keep the element, drop the URL — as league/commonmark does
        link: function (token) {
          if (!UNSAFE_URL.test(token.href)) {
            return false;
          }
          var title = token.title ? ' title="' + escapeAttr(token.title) + '"' : '';
          return '<a' + title + '>' + this.parser.parseInline(token.tokens) + '</a>';
        },
        image: function (token) {
          if (!UNSAFE_URL.test(token.href)) {
            return false;
          }
          var title = token.title ? ' title="' + escapeAttr(token.title) + '"' : '';
          return '<img src="" alt="' + escapeAttr(token.text) + '"' + title + ' />';
        }
      }
    });
  }

  // Same shape Support\DocumentFormat::parse() accepts: "---\n…\n---\n\n?body"
  function body(documentText) {
    var normalised = String(documentText).replace(/\r\n?/g, '\n');
    var match = /^---\n[\s\S]*?\n---\n\n?/.exec(normalised);
    return match ? normalised.slice(match[0].length) : normalised;
  }

  // Drop unsafe URLs from rendered links and images (browser only)
  function sanitize(container) {
    var nodes = container.querySelectorAll('a[href], img[src]');
    for (var i = 0; i < nodes.length; i++) {
      var attr = nodes[i].tagName === 'A' ? 'href' : 'src';
      if (UNSAFE_URL.test(nodes[i].getAttribute(attr) || '')) {
        nodes[i].removeAttribute(attr);
      }
    }
  }

  return { configure: configure, body: body, sanitize: sanitize };
}));
