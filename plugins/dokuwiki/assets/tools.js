/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The dokuwiki plugin's editor tools: DokuWiki links, formatting and tables to markdown. Each is a
 * pure function (text, from, to) → an edit with `converted` and `skipped`, built on the helpers of
 * assets/js/editor-format.js (proseTool, mask) and handed to it with addTools(): the editor's
 * Tools section then runs them like its own. In node the module is a function taking that
 * helpers object (tools/run-dokuwiki-tools.js, tests/Plugin/DokuwikiToolsTest.php).
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory;
  } else if (root.ReporionEditorFormat) {
    root.ReporionEditorFormat.addTools(factory(root.ReporionEditorFormat));
  }
}(this, function (F) {
  'use strict';

  /**
   * `[[http://x.org|Text]]` becomes `[Text](http://x.org)`, `[[http://x.org]]` `<http://x.org>`,
   * `[[ns:page|Text]]` `[Text](ns:page)`, `[[a@b.ro|Mail]]` `[Mail](mailto:a@b.ro)`. What cannot be
   * told for sure is left as written and counted in `skipped`: a page with no namespace
   * (`[[page]]`, relative to a DokuWiki namespace we cannot know), `.:` and `..` forms, interwiki
   * (`wp>Page`), shares (`\\host`) and image labels.
   */
  function linkText(t, stats) {
    function destination(url) {
      return url.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
    }

    return t.replace(/\[\[([^\[\]\n]+)\]\]/g, function (whole, inner) {
      var bar = inner.indexOf('|');
      var target = (bar === -1 ? inner : inner.slice(0, bar)).trim();
      var label = bar === -1 ? '' : inner.slice(bar + 1).trim();
      var url = null;
      var plain = '';

      if (target === '' || /^\{\{/.test(label) || /^\\\\/.test(target)) {
        stats.skipped++;
        return whole;
      }
      if (/^(https?|ftp):\/\/\S+$/i.test(target)) {
        url = target;
      } else if (/^www\.\S+\.\S+$/i.test(target)) {
        url = 'http://' + target;
      } else if (/^[^\s@:\/]+@[^\s@:\/]+\.[^\s@:\/]+$/.test(target)) {
        url = 'mailto:' + target;
        plain = target;
      } else {
        // An absolute page id: a leading colon is allowed, a namespace is not optional
        var id = target.replace(/^:/, '');
        var page = id.match(/^([A-Za-z0-9][A-Za-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+)(#\S*)?$/);
        if (!page) {
          stats.skipped++;
          return whole;
        }
        url = page[1].toLowerCase() + (page[2] || '');
        plain = page[1].split(':').pop().replace(/_/g, ' ');
      }

      stats.converted++;
      if (label === '') {
        return /^mailto:|^(https?|ftp):/i.test(url) ? '<' + url.replace(/^mailto:/, '') + '>' : '[' + plain + '](' + url + ')';
      }
      return '[' + label.replace(/([\[\]])/g, '\\$1') + '](' + destination(url) + ')';
    });
  }

  function dokuLinks(text, from, to) {
    return F.proseTool(text, from, to, linkText, true);
  }

  /**
   * `//italic//` `*italic*`, `''mono''` a code span, `<sup>` `<sub>` `^x^` `~x~`, a forced line
   * break `\\` a backslash break (`inCell`: a space, a table cell has no lines). `__underline__` is
   * left: markdown reads it as bold. $t is masked text.
   */
  function inlineMarkup(t, stats, inCell) {
    t = t.replace(/(^|[^:\/\w*])\/\/(?=[^\s\/])([^\n]*?[^\s\/])\/\/(?![\w\/:])/g, function (m, pre, inner) {
      stats.converted++;
      return pre + '*' + inner + '*';
    });
    t = t.replace(/''([^'\n](?:[^\n]*?[^'\n])?)''/g, function (m, inner) {
      if (inner.indexOf('`') !== -1) return m;
      stats.converted++;
      return '`' + inner + '`';
    });
    t = t.replace(/<(sup|sub)>([^\s<>^~]+)<\/\1>/gi, function (m, tag, inner) {
      stats.converted++;
      var mark = tag.toLowerCase() === 'sup' ? '^' : '~';
      return mark + inner + mark;
    });
    if (inCell) {
      return t.replace(/\\\\[ \t]*/g, function () { stats.converted++; return ' '; });
    }
    t = t.replace(/\\\\[ \t]*(?=\n|$)/g, function () { stats.converted++; return '\\'; });
    return t.replace(/\\\\[ \t]+(?=\S)/g, function () { stats.converted++; return '\\\n'; });
  }

  /**
   * `====== H1 ======` … `== H5 ==` become `#` … `#####` (DokuWiki counts the other way round),
   * the inline markup above, `<code python file.py>` and `<file>` blocks as fenced code, and the
   * two-space `  * item` / `  - item` lists (nested by
   * indentation) `- ` and `1. ` lists. A text that already has unindented markdown lists keeps its
   * lists as they are (counted in `skipped`): there `  * x` is a nested markdown item, not a
   * DokuWiki one.
   */
  function dokuMarkup(text, from, to) {
    return F.proseTool(text, from, to, function (t, stats, restore) {
      return codeBlocks(markup(t, stats), stats, restore);
    });
  }

  function markup(t, stats) {
    t = t.replace(/^[ \t]*(={2,6})([ \t]*)([^=\n][^\n]*?)([ \t]*)=+[ \t]*$/gm, function (m, eq, before, title, after) {
      if (eq.length === 2 && (before === '' || after === '')) return m; // `==highlight==` on a line of its own
      stats.converted++;
      return new Array(7 - eq.length + 1).join('#') + ' ' + title;
    });
    t = inlineMarkup(t, stats, false);

    var item = /^((?: {2,}|\t+)[ \t]*)([*-])[ \t]+(\S.*)$/;
    var lines = t.split('\n');
    var listLines = lines.filter(function (line) { return item.test(line); }).length;
    if (listLines === 0) return t;
    if (/^(?:[-*+]|\d+[.)])[ \t]+\S/m.test(t)) {
      stats.skipped += listLines;
      return t;
    }
    var widths = [];
    for (var i = 0; i < lines.length; i++) {
      var m = item.exec(lines[i]);
      if (!m) {
        if (lines[i].trim() !== '') widths = [];
        continue;
      }
      var level = Math.max(1, Math.floor(m[1].replace(/\t/g, '  ').length / 2));
      if (level > widths.length + 1) level = widths.length + 1;
      var pad = 0;
      for (var k = 0; k < level - 1; k++) pad += widths[k];
      var marker = m[2] === '*' ? '- ' : '1. ';
      widths = widths.slice(0, level - 1);
      widths[level - 1] = marker.length;
      lines[i] = new Array(pad + 1).join(' ') + marker + m[3];
      stats.converted++;
    }
    return lines.join('\n');
  }

  /** DokuWiki's (GeSHi's) language names that highlight.js, and so the fence, knows under another */
  var LANGUAGES = { html4strict: 'html', html5: 'html', java5: 'java', text: '', plain: '' };

  /**
   * `<code python rules1.py>…</code>` and `<file …>…</file>` become a fenced block: ```` ```python ````
   * (the file name has no place in a fence and goes), no language for `<code>` or `<code ->`. A
   * fence longer than any backtick run inside is used. These blocks were masked: the placeholders
   * are swapped for the fences last, so nothing else touched what is inside.
   */
  function codeBlocks(t, stats, restore) {
    return t.replace(F.maskToken(), function (token, offset, whole) {
      var original = restore(token);
      var m = /^<(code|file)\b([^>\n]*)>([\s\S]*?)<\/\1>$/.exec(original);
      if (!m) return token;
      var language = m[2].trim().split(/\s+/)[0] || '';
      if (language === '-') language = '';
      language = Object.prototype.hasOwnProperty.call(LANGUAGES, language.toLowerCase()) ? LANGUAGES[language.toLowerCase()] : language;
      if (!/^[A-Za-z0-9_+#.-]*$/.test(language)) language = '';
      var inner = m[3].replace(/^[ \t]*\n/, '').replace(/\n[ \t]*$/, '');
      var longest = (inner.match(/`+/g) || []).reduce(function (n, run) { return Math.max(n, run.length); }, 0);
      var fence = new Array(Math.max(3, longest + 1) + 1).join('`');
      stats.converted++;
      var before = offset > 0 && whole.charAt(offset - 1) !== '\n' ? '\n' : '';
      var after = offset + token.length < whole.length && whole.charAt(offset + token.length) !== '\n' ? '\n' : '';
      return before + fence + language + '\n' + inner + '\n' + fence + after;
    });
  }

  var KEEP_OPEN = '';
  var KEEP_CLOSE = '';

  /**
   * One DokuWiki table row as cells: { head, raw }, a cell's type being the separator before it
   * (`^` head, `|` data). Two separators with nothing between them (no space) are a column span
   * (raw ''). Links, `{{media}}` and `%%nowiki%%` may hold a `|` and are not split.
   */
  function splitRow(line) {
    var kept = [];
    var s = line.trim().replace(/\[\[[^\[\]\n]*\]\]|\{\{[^{}\n]*\}\}|%%[\s\S]*?%%/g, function (m) {
      kept.push(m);
      return KEEP_OPEN + (kept.length - 1) + KEEP_CLOSE;
    });
    var seps = [];
    var re = /[\^|]/g;
    var m;
    while ((m = re.exec(s)) !== null) seps.push(m.index);
    var cells = [];
    for (var k = 0; k < seps.length; k++) {
      var start = seps[k] + 1;
      if (start >= s.length) break;
      var end = k + 1 < seps.length ? seps[k + 1] : s.length;
      cells.push({
        head: s.charAt(seps[k]) === '^',
        raw: s.slice(start, end).replace(/(\d+)/g, function (x, i) { return kept[+i]; })
      });
    }
    return cells;
  }

  /** DokuWiki's alignment is the spaces around a cell's text: two or more on a side push it away from that side */
  function alignOf(raw) {
    if (raw.trim() === '') return '';
    var left = /^ {2,}/.test(raw);
    var right = / {2,}$/.test(raw);
    if (left && right) return 'center';
    return left ? 'right' : (right ? 'left' : '');
  }

  /** The block's lines as a markdown pipe table, or null for a row span (`:::`), which markdown cannot hold */
  function convertTable(block, stats) {
    var rows = block.map(splitRow);
    var spanned = rows.some(function (row) { return row.some(function (c) { return c.raw.trim() === ':::'; }); });
    if (spanned) return null;

    var header = null;
    var first = rows[0];
    if (first.length > 0 && first.every(function (c) { return c.head || c.raw === ''; }) && first.some(function (c) { return c.head; })) {
      header = rows.shift();
    }
    var cols = Math.max.apply(null, (header ? [header] : []).concat(rows).map(function (row) { return row.length; }).concat([1]));

    var align = [];
    for (var c = 0; c < cols; c++) {
      align[c] = '';
      var scan = (header ? [header] : []).concat(rows);
      for (var r = 0; r < scan.length && align[c] === ''; r++) {
        if (scan[r][c]) align[c] = alignOf(scan[r][c].raw);
      }
    }

    function cellText(cell, bold) {
      if (cell.raw === '') return '<<';
      var text = linkText(cell.raw.trim(), stats);
      var masked = F.mask(text);
      text = masked.restore(inlineMarkup(masked.text, stats, true));
      text = text.replace(/^%%([\s\S]*)%%$/, '$1').replace(/\|/g, '\\|');
      return bold && text !== '' ? '**' + text + '**' : text;
    }

    function line(row, isHeader) {
      var out = [];
      for (var i = 0; i < cols; i++) out.push(row[i] ? cellText(row[i], !isHeader && row[i].head) : '');
      return '| ' + out.join(' | ') + ' |';
    }

    var lines = [header ? line(header, true) : '| ' + new Array(cols).join(' | ') + ' |'];
    lines.push('| ' + align.map(function (a) {
      return a === 'center' ? ':---:' : (a === 'right' ? '---:' : (a === 'left' ? ':---' : '---'));
    }).join(' | ') + ' |');
    rows.forEach(function (row) { lines.push(line(row, false)); });
    return lines;
  }

  /**
   * DokuWiki tables (`^ head ^` and `| cell |` rows) to markdown pipe tables: the head row, the
   * alignment from the spaces around a cell's text, a column span (`||`) as the dialect's `<<`
   * cell, a row header (`^` among `|` cells) in bold, links and inline markup inside the cells
   * converted. A table with a row span (`:::`) is left as written (`skipped`), and so is a block
   * that already has a markdown separator row (`|---|---|`).
   */
  function dokuTables(text, from, to) {
    return F.proseTool(text, from, to, function (t, stats) {
      var lines = t.split('\n');
      var out = [];
      var row = /^[ \t]*[\^|].*[\^|][ \t]*$/;
      var separator = /^[ \t]*\|?[ \t]*:?-{2,}:?[ \t]*(?:\|[ \t]*:?-{2,}:?[ \t]*)*\|?[ \t]*$/;
      for (var i = 0; i < lines.length;) {
        if (!row.test(lines[i])) {
          out.push(lines[i++]);
          continue;
        }
        var j = i;
        while (j < lines.length && row.test(lines[j])) j++;
        var block = lines.slice(i, j);
        i = j;
        if (block.some(function (l) { return separator.test(l); })) {
          Array.prototype.push.apply(out, block);
          continue;
        }
        var table = convertTable(block, stats);
        if (table === null) {
          stats.skipped++;
          Array.prototype.push.apply(out, block);
          continue;
        }
        stats.converted++;
        if (out.length > 0 && out[out.length - 1].trim() !== '') out.push('');
        Array.prototype.push.apply(out, table);
        if (i < lines.length && lines[i].trim() !== '') out.push('');
      }
      return out.join('\n');
    }, true);
  }

  return { dokuLinks: dokuLinks, dokuMarkup: dokuMarkup, dokuTables: dokuTables };
}));
