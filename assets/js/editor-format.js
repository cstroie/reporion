/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor toolbar's text transforms (phase 10), as pure functions: the
 * whole document and a selection in, one edit out: { from, to, insert,
 * selStart, selEnd }: replace [from, to) with insert, then select
 * [selStart, selEnd) in the new text. No DOM, so node tests them
 * (tests/Render/EditorFormatTest.php via tools/run-editor-format.js); editor.js
 * applies the edit so the browser's undo stack keeps it.
 *
 * Everything written stays inside the dialect (D17: CommonMark + tables);
 * tests/fixtures/render/editor-toolbar.md holds each construct for the
 * conformance test. The frontmatter block is never touched by the text
 * buttons: only addPrior() edits it, and only its `priors` list.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorFormat = factory();
  }
}(this, function () {
  'use strict';

  /** Where the body starts: after a leading `---` … `---` block and its blank line, else 0. */
  function bodyStart(text) {
    if (text.slice(0, 4) !== '---\n') return 0;
    var close = text.indexOf('\n---\n', 3);
    if (close !== -1) return text.charAt(close + 5) === '\n' ? close + 6 : close + 5;
    if (text.slice(-4) === '\n---') return text.length;
    return 0;
  }

  /** The document without its frontmatter, for Copy. */
  function bodyOf(text) {
    return text.slice(bodyStart(text)).replace(/^\n+/, '');
  }

  /** A selection moved out of the frontmatter, to the start of the body. */
  function clamp(text, start, end) {
    var min = bodyStart(text);
    return [Math.max(start, min), Math.max(end, min)];
  }

  function edit(from, to, insert, selStart, selEnd) {
    return { from: from, to: to, insert: insert, selStart: selStart, selEnd: selEnd };
  }

  /** The start of the line holding `at`, and the end of the line holding `atEnd`. */
  function lineRange(text, at, atEnd) {
    var from = text.lastIndexOf('\n', at - 1) + 1;
    // A selection ending just after a newline does not take in the next line
    var last = atEnd > at && text.charAt(atEnd - 1) === '\n' ? atEnd - 1 : atEnd;
    var to = text.indexOf('\n', last);
    return [from, to === -1 ? text.length : to];
  }

  /**
   * Wraps the selection in marker (`**` bold, `*` italic, `` ` `` code), or
   * unwraps it when it already is. Spaces at the selection's edges stay
   * outside the markers:`** word**` is not emphasis in CommonMark.
   */
  function toggleWrap(text, start, end, marker) {
    var c = clamp(text, start, end);
    start = c[0];
    end = c[1];
    var m = marker.length;
    var selected = text.slice(start, end);

    function isWrapped(s) {
      if (s.length < 2 * m || s.slice(0, m) !== marker || s.slice(-m) !== marker) return false;
      // `**x**` is bold, not italic wrapped in stars, unless it is `***x***`
      if (marker === '*') {
        var lead = s.match(/^\*+/)[0].length;
        var trail = s.match(/\*+$/)[0].length;
        return lead !== 2 && trail !== 2;
      }
      return true;
    }

    if (isWrapped(selected)) {
      var inner = selected.slice(m, selected.length - m);
      return edit(start, end, inner, start, start + inner.length);
    }
    var outer = text.slice(start - m, end + m);
    if (start >= m && isWrapped(outer)) {
      return edit(start - m, end + m, selected, start - m, end - m);
    }

    var lead = selected.match(/^\s*/)[0];
    var core = selected.slice(lead.length).replace(/\s+$/, '');
    var trail = selected.slice(lead.length + core.length);
    if (marker === '`' && core.indexOf('\n') !== -1) {
      return codeBlock(text, start, end);
    }
    var insert = lead + marker + core + marker + trail;
    var at = start + lead.length + m;

    return edit(start, end, insert, at, at + core.length);
  }

  /** Cycles the current line: plain → `# ` → `## ` → `### ` → plain (the owner's choice, 2026-09-27: `#` is the first level, as in any editor). */
  function heading(text, start, end) {
    var c = clamp(text, start, end);
    var r = lineRange(text, c[0], c[0]);
    var line = text.slice(r[0], r[1]);
    var m = line.match(/^(#{1,6}) +/);
    var rest = m ? line.slice(m[0].length) : line;
    // plain → # → ## → ### → plain
    var prefix = !m ? '# ' : (m[1].length === 1 ? '## ' : (m[1].length === 2 ? '### ' : ''));
    var insert = prefix + rest;

    return edit(r[0], r[1], insert, r[0] + prefix.length, r[0] + insert.length);
  }

  var BULLET = /^(\s*)[-*+] /;
  var TASK_ITEM = /^(\s*)[-*+] \[[ xX]\] /;
  var NUMBER = /^(\s*)\d+[.)] /;

  /** Adds or removes `- ` / `1. ` on every selected line; numbers count up. */
  function list(text, start, end, kind) {
    var c = clamp(text, start, end);
    var r = lineRange(text, c[0], c[1]);
    var lines = text.slice(r[0], r[1]).split('\n');
    var own = kind === 'number' ? NUMBER : (kind === 'task' ? TASK_ITEM : BULLET);
    var other = kind === 'number' ? BULLET : NUMBER;
    var content = lines.filter(function (l) { return l.trim() !== ''; });
    var removing = content.length > 0 && content.every(function (l) { return own.test(l); });
    // An all-blank target (a fresh line with nothing on it yet) gets the
    // first marker, same as any other line: only a blank *separator* line
    // inside a selection that has real content stays untouched (TODO 13).
    var skipBlank = content.length > 0;
    var n = 0;
    var out = lines.map(function (l) {
      if (skipBlank && l.trim() === '') return l;
      if (removing) return l.replace(own, '$1');
      var bare = l.replace(TASK_ITEM, '$1').replace(own, '$1').replace(other, '$1');
      var indent = bare.match(/^\s*/)[0];
      n++;
      return indent + (kind === 'number' ? n + '. ' : (kind === 'task' ? '- [ ] ' : '- ')) + bare.slice(indent.length);
    });
    var insert = out.join('\n');

    return edit(r[0], r[1], insert, r[0], r[0] + insert.length);
  }

  var NUMBER_ITEM = /^(\s*)(\d+)([.)]) /;
  var QUOTE = /^\s*(?:> ?)+/;
  var TASK_BOX = /^\[[ xX]\] /;

  var TABLE_ROW = /^\s*\|.*\|\s*$/;
  var TABLE_DELIM = /^\s*\|(\s*:?-+:?\s*\|)+\s*$/;

  /**
   * Enter at the end of a table row: a new empty row with as many cells,
   * the cursor in its first one. On the header row the new row goes below
   * the delimiter line; on an already-empty row the row is removed (the
   * way a list is left), leaving a blank line after the table.
   */
  function tableRow(text, r) {
    var whole = text.slice(r[0], r[1]);
    var cells = whole.trim().replace(/^\|/, '').replace(/\|$/, '').split(/(?<!\\)\|/);
    if (TABLE_DELIM.test(whole) && r[0] === 0) return null;
    if (cells.every(function (c) { return c.trim() === ''; }) && !TABLE_DELIM.test(whole)) {
      return edit(r[0], r[1], '', r[0], r[0]);
    }
    var indent = whole.match(/^\s*/)[0];
    var row = indent + '|' + cells.map(function () { return '  |'; }).join('');
    var at = r[1];
    var next = lineRange(text, r[1] + 1, r[1] + 1);
    if (r[1] < text.length && TABLE_DELIM.test(text.slice(next[0], next[1]))) at = next[1];
    var insert = '\n' + row;
    var caret = at + 1 + indent.length + 2;

    return edit(at, at, insert, caret, caret);
  }

  /**
   * Ctrl+Enter on the line(s) holding the selection: a list item becomes a
   * task, a task is ticked, a ticked one goes back to a plain item; a line
   * with no marker becomes an open task. Every line follows the first one's
   * step so a selection stays uniform.
   */
  function toggleTask(text, start, end) {
    var c = clamp(text, start, end);
    var r = lineRange(text, c[0], c[1]);
    var lines = text.slice(r[0], r[1]).split('\n');
    var first = lines.filter(function (l) { return l.trim() !== ''; })[0] || '';
    var step = /^\s*[-*+] \[ \] /.test(first) ? 'tick' : (/^\s*[-*+] \[[xX]\] /.test(first) ? 'plain' : 'open');
    var out = lines.map(function (l) {
      if (l.trim() === '' && lines.length > 1) return l;
      var bare = l.replace(/^(\s*)[-*+] \[[ xX]\] /, '$1').replace(BULLET, '$1').replace(NUMBER, '$1');
      var indent = bare.match(/^\s*/)[0];
      var body = bare.slice(indent.length);
      if (step === 'tick') return indent + '- [x] ' + body;
      if (step === 'plain') return indent + '- ' + body;
      return indent + '- [ ] ' + body;
    });
    var insert = out.join('\n');

    return edit(r[0], r[1], insert, r[0], r[0] + insert.length);
  }

  /**
   * Backspace with the cursor right after an empty item's marker (`- `,
   * `3. `, `- [ ] `, `> `): the marker goes, as a second Enter would do.
   * Null anywhere else, so the browser deletes one character as usual.
   */
  function dropMarker(text, at) {
    if (at < bodyStart(text)) return null;
    var r = lineRange(text, at, at);
    if (at !== r[1]) return null;
    var whole = text.slice(r[0], r[1]);
    var quote = (whole.match(QUOTE) || [''])[0];
    var inner = whole.slice(quote.length);
    var m = inner.match(/^(\s*)(?:[-*+]|\d+[.)]) (?:\[[ xX]\] )?$/);
    var kept;
    if (m) kept = quote + m[1];
    else if (quote !== '' && inner === '') kept = '';
    else return null;
    if (kept === whole) return null;

    return edit(r[0], r[1], kept, r[0] + kept.length, r[0] + kept.length);
  }

  /**
   * Enter pressed at the end of a list line (TODO 13): the next marker on a
   * new line (a number counts up from the current one; a task item `- [x] `
   * opens an unchecked `- [ ] `; a blockquote keeps its `> `), or: pressed
   * on an already-empty item: the marker is dropped and the line stays,
   * breaking out of the list the way most editors do on a second Enter.
   * Null when `at` is not at the end of a list or quote line, so the caller
   * falls back to a plain newline.
   */
  function continueList(text, at) {
    if (at < bodyStart(text)) return null;
    var r = lineRange(text, at, at);
    if (at !== r[1]) return null;
    var whole = text.slice(r[0], r[1]);
    if (TABLE_ROW.test(whole)) return tableRow(text, r);
    var quote = (whole.match(QUOTE) || [''])[0];
    var inner = whole.slice(quote.length);
    var bm = inner.match(BULLET);
    var nm = inner.match(NUMBER_ITEM);
    if (!bm && !nm && quote === '') return null;
    var marker = bm ? bm[0] : (nm ? nm[0] : '');
    var indent = bm ? bm[1] : (nm ? nm[1] : '');
    var rest = inner.slice(marker.length);
    var task = marker !== '' && TASK_BOX.test(rest);
    if (task) rest = rest.slice(4);

    if (rest.trim() === '') {
      var kept = marker !== '' ? quote + indent : '';
      if (marker === '' && quote === '') return null;
      return edit(r[0], r[1], kept, r[0] + kept.length, r[0] + kept.length);
    }

    var nextMarker = bm ? marker.slice(indent.length) : (nm ? (parseInt(nm[2], 10) + 1) + nm[3] + ' ' : '');
    var insert = '\n' + quote + indent + nextMarker + (task ? '[ ] ' : '');

    return edit(at, at, insert, at + insert.length, at + insert.length);
  }

  /** Blank lines around a block, as many as the text on each side needs. */
  function block(text, start, end, content, cursorOffset) {
    var c = clamp(text, start, end);
    var before = text.slice(0, c[0]);
    var after = text.slice(c[1]);
    var prefix = before === '' || /\n\n$/.test(before) ? '' : (/\n$/.test(before) ? '\n' : '\n\n');
    var suffix = after === '' ? '\n' : (/^\n\n/.test(after) ? '' : (/^\n/.test(after) ? '\n' : '\n\n'));
    var insert = prefix + content + suffix;
    var at = c[0] + prefix.length;
    var caret = at + (cursorOffset === undefined ? content.length : cursorOffset);

    return edit(c[0], c[1], insert, caret, caret);
  }

  function codeBlock(text, start, end) {
    var c = clamp(text, start, end);
    var inner = text.slice(c[0], c[1]).replace(/\n+$/, '');
    var fence = /```/.test(inner) ? '~~~~' : '```';

    return block(text, c[0], c[1], fence + '\n' + inner + '\n' + fence, fence.length + 1 + inner.length);
  }

  function cell(s) {
    return s.trim().replace(/\|/g, '\\|');
  }

  /**
   * A table on its own lines. Tab-separated lines (a paste from a
   * spreadsheet) become its rows, the first one the header; otherwise a
   * two-column skeleton with the first header cell selected.
   */
  function table(text, start, end, labels) {
    var c = clamp(text, start, end);
    var selected = text.slice(c[0], c[1]).replace(/\n+$/, '');
    var rows = selected.split('\n').filter(function (l) { return l.trim() !== ''; });
    if (rows.length > 0 && rows.every(function (l) { return l.indexOf('\t') !== -1; })) {
      var grid = rows.map(function (l) { return l.split('\t').map(cell); });
      var width = Math.max.apply(null, grid.map(function (r) { return r.length; }));
      var lines = grid.map(function (r) {
        while (r.length < width) r.push('');
        return '| ' + r.join(' | ') + ' |';
      });
      var delimiter = '|' + new Array(width + 1).join(' --- |');
      lines.splice(1, 0, delimiter);
      return block(text, c[0], c[1], lines.join('\n'));
    }
    var h = labels || ['Column', 'Column'];
    var skeleton = '| ' + h[0] + ' | ' + h[1] + ' |\n| --- | --- |\n|  |  |';
    var e = block(text, c[0], c[1], skeleton, 2);
    e.selEnd = e.selStart + h[0].length;

    return e;
  }

  /** The selection or cursor as a `[text](target)` link; a selection becomes the text. */
  function link(text, start, end, target, label) {
    var c = clamp(text, start, end);
    var selected = text.slice(c[0], c[1]);
    var shown = selected.trim() !== '' && selected.indexOf('\n') === -1 ? selected : label;
    shown = shown.replace(/([\[\]])/g, '\\$1');
    var insert = '[' + shown + '](' + target + ')';

    return edit(c[0], c[1], insert, c[0] + insert.length, c[0] + insert.length);
  }

  /**
   * A web address as a link destination, or '' when it is not one a page may link to: a scheme the
   * dialect allows (http, https, ftp, mailto, tel) is kept; `www.x.ro` and `x.ro/path` get https://;
   * an e-mail address gets mailto:. Spaces and parentheses are percent-encoded, which markdown needs.
   */
  function normalizeUrl(address) {
    var url = String(address).trim();
    if (url === '' || /[\s<>]/.test(url.replace(/ /g, ''))) return '';
    if (/^[a-z][a-z0-9+.-]*:/i.test(url)) {
      if (!/^(https?|ftp|mailto|tel):/i.test(url) || /^[a-z]+:\/*$/i.test(url)) return '';
    } else if (/^[^\s@\/:]+@[^\s@\/:]+\.[^\s@\/:]+$/.test(url)) {
      url = 'mailto:' + url;
    } else if (/^[^\s\/:@]+(\.[^\s\/:@]+)+(?:[\/?#].*)?$/.test(url)) {
      url = 'https://' + url;
    } else {
      return '';
    }
    return url.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
  }

  /**
   * A link to a web address (the toolbar's Link to a web address): `[text](address)` in place of the
   * selection, or `<address>` when there is no text. Null when the address is not one (normalizeUrl).
   */
  function urlLink(text, start, end, address, label) {
    var url = normalizeUrl(address);
    if (url === '') return null;
    var c = clamp(text, start, end);
    var shown = String(label).trim().replace(/\s+/g, ' ').replace(/([\[\]])/g, '\\$1');
    var insert = shown === '' ? '<' + url.replace(/^mailto:/i, '') + '>' : '[' + shown + '](' + url + ')';

    return edit(c[0], c[1], insert, c[0] + insert.length, c[0] + insert.length);
  }

  /** A text block (a template's body) at the cursor, as paragraphs of its own. */
  function insertBlock(text, start, end, content) {
    return block(text, start, end, content.replace(/^\n+/, '').replace(/\n+$/, ''));
  }

  /**
   * Adds path to the frontmatter's `priors` list: appended to a block list
   * (the shape Yaml::dump writes), or a new `priors:` before the closing
   * `---`. Null when it is already there (edit: none), or, with
   * { unknown: true }, when the frontmatter is not in a shape this knows.
   */
  function addPrior(text, path) {
    var close = bodyStart(text);
    if (close === 0) return { unknown: true };
    var fmEnd = text.lastIndexOf('---', close - 1);
    var fm = text.slice(4, fmEnd);
    var lines = fm.split('\n');
    var at = -1;
    for (var i = 0; i < lines.length; i++) {
      if (/^priors:/.test(lines[i])) {
        at = i;
        break;
      }
    }
    var entry = '  - ' + (/^[A-Za-z0-9_][A-Za-z0-9_:.\-]*$/.test(path) ? path : "'" + path.replace(/'/g, "''") + "'");

    if (at === -1) {
      var insertAt = fmEnd;
      return edit(insertAt, insertAt, 'priors:\n' + entry + '\n', null, null);
    }

    var head = lines[at].replace(/\s+$/, '');
    var offset = 4;
    for (var j = 0; j < at; j++) offset += lines[j].length + 1;

    if (head === 'priors:' || head === 'priors: null' || head === 'priors: ~' || head === 'priors: []') {
      var k = at + 1;
      var items = [];
      while (k < lines.length && /^\s*- /.test(lines[k])) {
        items.push(lines[k].replace(/^\s*- /, '').replace(/^'(.*)'$/, '$1').replace(/^"(.*)"$/, '$1').trim());
        k++;
      }
      if (items.indexOf(path) !== -1) return null;
      if (head !== 'priors:' && items.length > 0) return { unknown: true };
      if (k < lines.length && /^\s+\S/.test(lines[k])) return { unknown: true };
      var blockEnd = offset;
      for (var q = at; q < k; q++) blockEnd += lines[q].length + 1;
      if (head !== 'priors:') {
        return edit(offset, offset + lines[at].length + 1, 'priors:\n' + entry + '\n', null, null);
      }
      return edit(blockEnd, blockEnd, entry + '\n', null, null);
    }

    var flow = head.match(/^priors: \[(.*)\]$/);
    if (flow) {
      var existing = flow[1].split(',').map(function (s) { return s.trim().replace(/^'(.*)'$/, '$1').replace(/^"(.*)"$/, '$1'); })
        .filter(function (s) { return s !== ''; });
      if (existing.indexOf(path) !== -1) return null;
      var all = existing.concat([path]).map(function (p) {
        return '  - ' + (/^[A-Za-z0-9_][A-Za-z0-9_:.\-]*$/.test(p) ? p : "'" + p.replace(/'/g, "''") + "'");
      });
      return edit(offset, offset + lines[at].length + 1, 'priors:\n' + all.join('\n') + '\n', null, null);
    }

    return { unknown: true };
  }

  /**
   * The edit that turns text[start, end) (`region`) into `out`: only the stretch that
   * differs is replaced, so the caret and the undo step stay small. With `converted` and
   * `skipped`, which the Tools section reports; from === to and no insert when nothing changed.
   */
  function finish(start, end, region, out, converted, skipped) {
    var result;
    if (out === region) {
      result = edit(0, 0, '', null, null);
      result.converted = 0;
    } else {
      var head = 0;
      while (head < region.length && head < out.length && region.charAt(head) === out.charAt(head)) head++;
      var tail = 0;
      while (tail < region.length - head && tail < out.length - head
        && region.charAt(region.length - 1 - tail) === out.charAt(out.length - 1 - tail)) tail++;
      result = edit(start + head, end - tail, out.slice(head, out.length - tail), null, null);
      result.converted = converted;
    }
    result.skipped = skipped;
    return result;
  }

  var MASK_OPEN = '\uE000';
  var MASK_CLOSE = '\uE001';
  var maskSeq = 0;

  /**
   * What the text tools never touch, swapped for placeholders and put back by restore():
   * code fences and <code> blocks, inline code, [[DokuWiki links]], link destinations and
   * URLs. The text between keeps its lines. With `codeOnly`, only the code: for a tool that
   * works on links itself.
   */
  function mask(region, codeOnly) {
    var kept = [];
    // Each mask has its own number in its placeholders: a tool may mask text that is masked already
    var id = ++maskSeq;
    function hide(m) {
      kept.push(m);
      return MASK_OPEN + id + ',' + (kept.length - 1) + MASK_CLOSE;
    }
    var out = region
      .replace(/(^|\n)( {0,3})(```+|~~~+)[^\n]*\n[\s\S]*?(?:\n {0,3}\3[`~]*[ \t]*(?=\n|$)|$)/g, function (m, nl) { return nl + hide(m.slice(nl.length)); })
      .replace(/<(code|file)\b[^>\n]*>[\s\S]*?<\/\1>/g, hide)
      .replace(/(`+)(?!`)(?:(?!\n[ \t]*\n)[\s\S])*?[^`]\1(?!`)/g, hide);
    if (!codeOnly) {
      out = out
      .replace(/\[\[[^\[\]\n]*\]\]/g, hide)
      .replace(/\]\([^)\n]*\)/g, hide)
      .replace(/<(?:https?|ftp|mailto):[^>\s]*>/gi, hide)
      .replace(/\b(?:https?|ftp):\/\/[^\s<>\])]+|\bwww\.[^\s<>\])]+/gi, hide);
    }
    return {
      text: out,
      restore: function (text) {
        var again = true;
        var own = new RegExp(MASK_OPEN + id + ',(\\d+)' + MASK_CLOSE, 'g');
        while (again) {
          again = false;
          text = text.replace(own, function (m, i) { again = true; return kept[+i]; });
        }
        return text;
      }
    };
  }

  /**
   * The shape of the Tools: the selection, else the whole body (never the frontmatter), with the
   * protected parts masked (only the code with `codeOnly`), through fn(maskedText, stats, restore), where fn
   * counts into stats.converted and stats.skipped. A text that already holds the placeholder
   * characters is left alone.
   */
  function proseTool(text, from, to, fn, codeOnly) {
    var min = bodyStart(text);
    var start = from === to ? min : Math.max(from, min);
    var end = from === to ? text.length : Math.max(to, start);
    var region = text.slice(start, end);
    var stats = { converted: 0, skipped: 0 };
    var out = region;
    if (region.indexOf(MASK_OPEN) === -1 && region.indexOf(MASK_CLOSE) === -1) {
      var masked = mask(region, codeOnly);
      out = masked.restore(fn(masked.text, stats, masked.restore));
    }
    return finish(start, end, region, out, stats.converted, stats.skipped);
  }

  /**
   * Dictated text, tidied (Tools): runs of spaces become one (a line's indent stays; a line
   * indented four or more is code and is left), trailing spaces go (two before a text line
   * are a markdown line break and stay), a space before `, ; . : ! ?` goes, more than one
   * blank line becomes one, and the cedilla ş ţ Ş Ţ become the comma-below ș ț Ș Ț that Romanian
   * is written with (the index folds neither the same way).
   */
  function cleanText(text, from, to) {
    var cedilla = { 'ş': 'ș', 'ţ': 'ț', 'Ş': 'Ș', 'Ţ': 'Ț' };
    return proseTool(text, from, to, function (t, stats) {
      var lines = t.split('\n');
      var changed = 0;
      for (var i = 0; i < lines.length; i++) {
        var orig = lines[i];
        var line = orig.replace(/[şţŞŢ]/g, function (c) { return cedilla[c]; });
        var parts = /^([ \t]*)([\s\S]*)$/.exec(line);
        if (parts[1].replace(/\t/g, '    ').length < 4) {
          var core = parts[2].replace(/[ \t]+$/, '');
          var hardBreak = core !== '' && / {2,}$/.test(parts[2]) && i + 1 < lines.length && lines[i + 1].trim() !== '';
          core = core.replace(/[ \t]+/g, ' ').replace(/([^\s(\[{|])[ \t]+([,;.:!?])(?=[ \t]|$)/g, '$1$2');
          line = parts[1] + core + (hardBreak ? '  ' : '');
          if (core === '') line = '';
        }
        if (line !== orig) changed++;
        lines[i] = line;
      }
      var joined = lines.join('\n').replace(/\n{3,}/g, '\n\n');
      stats.converted = changed + (lines.length - joined.split('\n').length);
      return joined;
    });
  }

  /**
   * Units and degrees (Tools): `cm2` `mm3` `dm2` (and `cm²`) become `cm^2^` (the dialect's
   * superscript), `m2` `m3` only after a number; `45 grade`, `45 de grade` become `45°`,
   * `37 grade Celsius` `37 °C`. `3 grade de severitate` (grades of something) is left.
   */
  function unitsDegrees(text, from, to) {
    var power = { '2': '2', '3': '3', '²': '2', '³': '3' };
    var letters = 'A-Za-zĂÂÎȘȚăâîșțşţ';
    return proseTool(text, from, to, function (t, stats) {
      t = t.replace(/(^|[^A-Za-z_^])(mm|cm|dm)([23²³])(?![A-Za-z0-9_^])/g, function (m, pre, unit, e) {
        stats.converted++;
        return pre + unit + '^' + power[e] + '^';
      });
      t = t.replace(/(\d[ \t]?)m([23²³])(?![A-Za-z0-9_^])/g, function (m, pre, e) {
        stats.converted++;
        return pre + 'm^' + power[e] + '^';
      });
      var number = '(^|[^\\w.,])(\\d+(?:[.,]\\d+)?)';
      t = t.replace(new RegExp(number + '[ \\t]*(?:de[ \\t]+)?grade[ \\t]+(?:Celsius|C)(?![' + letters + '0-9])', 'g'), function (m, pre, n) {
        stats.converted++;
        return pre + n + ' °C';
      });
      t = t.replace(new RegExp(number + '[ \\t]*(?:de[ \\t]+)?grade(?![' + letters + '0-9])(?![ \\t]+de\\b)', 'g'), function (m, pre, n) {
        stats.converted++;
        return pre + n + '°';
      });
      return t;
    });
  }

  /**
   * Markdown pipe tables laid out in columns (Tools): every cell padded to its column's widest
   * (in characters, a cell as it reads: a code span is counted whole), the alignment row
   * rewritten to the same widths (`:---`, `:---:`, `---:`), one leading and trailing pipe, a
   * single space inside them. Alignment decides where the padding goes. Over the selection, or
   * every table of the body; a table in a code fence is left alone. A table with a body row
   * that has more cells than its head, which a reader would cut, is left as written
   * (`skipped`). `converted` counts the tables that changed.
   */
  function alignTables(text, from, to) {
    function width(str) {
      return Array.from(str).length;
    }

    function cellsOf(line) {
      var s = line.trim();
      if (s.charAt(0) === '|') s = s.slice(1);
      if (/(^|[^\\])\|$/.test(s)) s = s.slice(0, -1);
      var cells = [];
      var cur = '';
      for (var i = 0; i < s.length; i++) {
        var c = s.charAt(i);
        if (c === '\\' && s.charAt(i + 1) === '|') {
          cur += '\\|';
          i++;
        } else if (c === '|') {
          cells.push(cur.trim());
          cur = '';
        } else {
          cur += c;
        }
      }
      cells.push(cur.trim());
      return cells;
    }

    var separator = /^[ \t]*\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/;

    return proseTool(text, from, to, function (t, stats, restore) {
      var lines = t.split('\n');
      var out = [];
      for (var i = 0; i < lines.length;) {
        var head = lines[i];
        if (head.indexOf('|') === -1 || i + 1 >= lines.length || lines[i + 1].indexOf('|') === -1 || !separator.test(lines[i + 1])) {
          out.push(lines[i++]);
          continue;
        }
        var headCells = cellsOf(head);
        var rule = cellsOf(lines[i + 1]);
        if (headCells.length !== rule.length) {
          out.push(lines[i++]);
          continue;
        }
        var end = i + 2;
        while (end < lines.length && lines[end].trim() !== '' && lines[end].indexOf('|') !== -1) end++;
        var block = lines.slice(i, end);
        i = end;

        var rows = [headCells].concat(block.slice(2).map(cellsOf));
        if (rows.some(function (row) { return row.length > headCells.length; })) {
          stats.skipped++;
          Array.prototype.push.apply(out, block);
          continue;
        }
        var cols = headCells.length;
        var align = rule.map(function (r) {
          var left = r.charAt(0) === ':';
          var right = r.charAt(r.length - 1) === ':';
          return left && right ? 'center' : (left ? 'left' : (right ? 'right' : ''));
        });
        var shown = rows.map(function (row) {
          var cells = [];
          for (var c = 0; c < cols; c++) cells.push(restore(row[c] || ''));
          return cells;
        });
        var widths = [];
        for (var c = 0; c < cols; c++) {
          widths[c] = 3;
          shown.forEach(function (row) { widths[c] = Math.max(widths[c], width(row[c])); });
        }
        var indent = /^[ \t]*/.exec(head)[0];
        function pad(str, c) {
          var gap = widths[c] - width(str);
          if (align[c] === 'right') return new Array(gap + 1).join(' ') + str;
          if (align[c] === 'center') return new Array(Math.floor(gap / 2) + 1).join(' ') + str + new Array(Math.ceil(gap / 2) + 1).join(' ');
          return str + new Array(gap + 1).join(' ');
        }
        function line(row) {
          return indent + '| ' + row.map(pad).join(' | ') + ' |';
        }
        var rendered = [line(shown[0])];
        rendered.push(indent + '| ' + widths.map(function (w, c) {
          var dashes = new Array(w + 1).join('-');
          if (align[c] === 'center') return ':' + dashes.slice(2) + ':';
          if (align[c] === 'left') return ':' + dashes.slice(1);
          if (align[c] === 'right') return dashes.slice(1) + ':';
          return dashes;
        }).join(' | ') + ' |');
        shown.slice(1).forEach(function (row) { rendered.push(line(row)); });
        if (rendered.join('\n') !== restore(block.join('\n'))) stats.converted++;
        Array.prototype.push.apply(out, rendered);
      }
      return out.join('\n');
    });
  }

  /**
   * A template's body as inserted: without its leading `# ` heading (the
   * template's name) and, right after it, its `## ` heading (the exam's name,
   * D45): the report has both already. A template with no `# ` keeps every
   * heading it has: its first `##` may be a real section.
   */
  function templateBody(body) {
    var rest = body.replace(/^\s*# [^\n]*\n*/, '');
    if (rest === body) return body;
    return rest.replace(/^\s*## [^\n]*\n*/, '');
  }

  /**
   * Snippets (phase 11, D24): the typed `;name` right before `at`, when it
   * starts a word: at the start of a line or after a space or an opening
   * bracket, never inside one. Null in the frontmatter or when nothing
   * matches. Names are lowercase; `;Norm` is `norm`.
   */
  function triggerBefore(text, at) {
    if (at < bodyStart(text)) return null;
    var m = text.slice(0, at).match(/(^|[\s(\[])(;([A-Za-z0-9][A-Za-z0-9-]*))$/);
    if (!m) return null;
    var from = at - m[2].length;
    if (from < bodyStart(text)) return null;

    return { from: from, to: at, name: m[3].toLowerCase() };
  }

  /** A snippet's text with its first `$0` taken out: { text, caret } (caret: offset in text). */
  function snippetText(body) {
    var i = body.indexOf('$0');
    if (i === -1) return { text: body, caret: body.length };

    return { text: body.slice(0, i) + body.slice(i + 2), caret: i };
  }

  /**
   * The edit that expands a snippet whose `;name` ends at `at`: typed just
   * before a space or line break (`delimited`: the character before `at`
   * is that delimiter, and it stays), or right at the cursor (Tab).
   * snippets: { name: body }. Null when there is nothing to expand.
   */
  function expandAt(text, at, snippets, delimited) {
    var end = at;
    if (delimited) {
      var d = text.charAt(at - 1);
      if (d !== ' ' && d !== '\n') return null;
      end = at - 1;
    }
    var t = triggerBefore(text, end);
    if (!t || !Object.prototype.hasOwnProperty.call(snippets, t.name)) return null;
    var s = snippetText(snippets[t.name]);
    var caret = t.from + s.caret;
    // The delimiter typed after the trigger stays; without $0 the cursor goes after it
    if (delimited && s.caret === s.text.length) caret += 1;

    return edit(t.from, t.to, s.text, caret, caret);
  }

  /** A snippet picked from the toolbar, at the cursor (never in the frontmatter). */
  /**
   * The slash menu (2026-10-19): a `/` typed at pos opens it when the line
   * before pos is empty or only a list marker, and nothing follows on the
   * line: never in the middle of text, a date (12/03) or a path.
   */
  function slashAt(text, pos) {
    var value = String(text);
    var from = value.lastIndexOf('\n', pos - 1) + 1;
    var to = value.indexOf('\n', pos);
    var after = value.slice(pos, to === -1 ? value.length : to);
    return /^\s*(?:(?:[-*+]|\d+[.)])\s+)?$/.test(value.slice(from, pos)) && after.trim() === '';
  }

  /** A `###` section heading put at pos, on its own line, the cursor on the line after it */
  function sectionAt(text, pos, name) {
    var value = String(text);
    var from = value.lastIndexOf('\n', pos - 1) + 1;
    var to = value.indexOf('\n', pos);
    if (to === -1) to = value.length;
    var before = value.slice(0, from);
    var lead = before === '' || /\n\n$/.test(before) ? '' : '\n';
    var insert = lead + '### ' + String(name).trim() + '\n\n';
    return { from: from, to: to, insert: insert, selStart: from + insert.length, selEnd: from + insert.length };
  }

  function insertSnippet(text, start, end, body) {
    var c = clamp(text, start, end);
    var s = snippetText(body);

    return edit(c[0], c[1], s.text, c[0] + s.caret, c[0] + s.caret);
  }

  /** Applies an edit to a string: what the browser does, for tests. */
  function apply(text, e) {
    return text.slice(0, e.from) + e.insert + text.slice(e.to);
  }

  /**
   * Tools a plugin adds to the rail's Tools section (the `editor_tool` slot): functions
   * (text, from, to) → an edit with `converted` and `skipped`, named by the entry's `tool`.
   * The plugin's script builds them from this module's helpers (proseTool, mask, edit, finish)
   * and hands them over here.
   */
  var tools = {};

  function addTools(more) {
    Object.keys(more).forEach(function (name) { tools[name] = more[name]; });
  }

  return {
    bodyStart: bodyStart,
    bodyOf: bodyOf,
    toggleWrap: toggleWrap,
    heading: heading,
    list: list,
    continueList: continueList,
    toggleTask: toggleTask,
    dropMarker: dropMarker,
    table: table,
    codeBlock: codeBlock,
    link: link,
    normalizeUrl: normalizeUrl,
    urlLink: urlLink,
    insertBlock: insertBlock,
    templateBody: templateBody,
    triggerBefore: triggerBefore,
    expandAt: expandAt,
    insertSnippet: insertSnippet,
    slashAt: slashAt,
    sectionAt: sectionAt,
    cleanText: cleanText,
    unitsDegrees: unitsDegrees,
    alignTables: alignTables,
    addPrior: addPrior,
    apply: apply,
    edit: edit,
    finish: finish,
    mask: mask,
    proseTool: proseTool,
    tools: tools,
    addTools: addTools,
    maskToken: function () { return new RegExp(MASK_OPEN + '\\d+,\\d+' + MASK_CLOSE, 'g'); }
  };
}));
