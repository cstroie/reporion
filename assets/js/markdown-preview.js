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

  // Links between pages — the same rules as Support\InternalLink: the colon
  // path (optionally /-prefixed) or the importer's older ns/page form,
  // resolved to {basePath}/ns:page. `media` and URI schemes are not pages.
  var COLON_FORM = /^\/?([a-z0-9][a-z0-9_-]*(?::[a-z0-9][a-z0-9_.-]*)+)$/i;
  var SLASH_FORM = /^([a-z0-9][a-z0-9_-]*(?:\/[a-z0-9][a-z0-9_.-]*)+)$/i;
  var NOT_PAGES = [
    'callto', 'data', 'file', 'ftp', 'ftps', 'geo', 'git', 'http', 'https', 'irc', 'javascript',
    'magnet', 'mailto', 'media', 'news', 'sms', 'ssh', 'tel', 'urn', 'vbscript', 'xmpp'
  ];

  function pageHref(url, basePath) {
    var hash = url.indexOf('#');
    var target = hash === -1 ? url : url.slice(0, hash);
    var fragment = hash === -1 ? '' : url.slice(hash);
    var m = COLON_FORM.exec(target);
    var path;
    if (m) {
      path = m[1].toLowerCase();
    } else if ((m = SLASH_FORM.exec(target))) {
      path = m[1].replace(/\//g, ':').toLowerCase();
    } else {
      return null;
    }
    return NOT_PAGES.indexOf(path.split(':')[0]) === -1 ? basePath + '/' + path + fragment : null;
  }

  // An attached file, as Support\MediaRef writes it
  var MEDIA_REF = /^media:([0-9a-f]{64})\.(png|jpg|gif|webp)$/;

  // A heading's anchor — Support\Slug::normalize() on its plain text, as
  // Service\Render sets it: diacritics folded, lower-case, runs of anything
  // else as one hyphen, 64 characters; "section" when nothing is left;
  // repeats numbered -2, -3 within one document. (PHP also transliterates a
  // few letters diacritic folding cannot — ß, æ, ø — so a heading made of
  // those could differ; Romanian text never is.)
  function headingSlug(text) {
    var slug = String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64).replace(/^-+|-+$/g, '');
    return slug === '' ? 'section' : slug;
  }

  // options.basePath: where the app is mounted (the editor's island config)
  function configure(marked, options) {
    var basePath = options && typeof options.basePath === 'string' ? options.basePath : '';
    var seenSlugs = {};
    marked.use({
      hooks: {
        // Heading ids are numbered per document
        preprocess: function (markdown) {
          seenSlugs = {};
          return markdown;
        }
      },
      gfm: true,
      breaks: false,
      walkTokens: function (token) {
        if (token.type === 'link') {
          var href = pageHref(token.href, basePath);
          if (href !== null) {
            token.href = href;
          }
        } else if (token.type === 'image') {
          var media = MEDIA_REF.exec(token.href);
          if (media) {
            token.href = basePath + '/media/' + media[1] + '.' + media[2];
          }
        }
      },
      renderer: {
        heading: function (token) {
          // marked's plain-text renderer keeps & < > " ' escaped; the slug is of the text itself
          var text = this.parser.parseInline(token.tokens, this.parser.textRenderer)
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&')
            .replace(/\s+/g, ' ').trim();
          var inner = this.parser.parseInline(token.tokens);
          if (text === '') {
            return '<h' + token.depth + '>' + inner + '</h' + token.depth + '>\n';
          }
          var slug = headingSlug(text);
          if (seenSlugs[slug]) {
            seenSlugs[slug] += 1;
            slug = slug + '-' + seenSlugs[slug];
          } else {
            seenSlugs[slug] = 1;
          }
          return '<h' + token.depth + ' id="' + escapeAttr(slug) + '">' + inner + '</h' + token.depth + '>\n';
        },
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
