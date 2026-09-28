/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor toolbar's text transforms (phase 10), as pure functions: the
 * whole document and a selection in, one edit out — { from, to, insert,
 * selStart, selEnd }: replace [from, to) with insert, then select
 * [selStart, selEnd) in the new text. No DOM, so node tests them
 * (tests/Js/EditorFormatTest via tools/run-editor-format.js); editor.js
 * applies the edit so the browser's undo stack keeps it.
 *
 * Everything written stays inside the dialect (D17: CommonMark + tables);
 * tests/fixtures/render/editor-toolbar.md holds each construct for the
 * conformance test. The frontmatter block is never touched by the text
 * buttons — only addPrior() edits it, and only its `priors` list.
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
   * outside the markers — `** word**` is not emphasis in CommonMark.
   */
  function toggleWrap(text, start, end, marker) {
    var c = clamp(text, start, end);
    start = c[0];
    end = c[1];
    var m = marker.length;
    var selected = text.slice(start, end);

    function isWrapped(s) {
      if (s.length < 2 * m || s.slice(0, m) !== marker || s.slice(-m) !== marker) return false;
      // `**x**` is bold, not italic wrapped in stars — unless it is `***x***`
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
  var NUMBER = /^(\s*)\d+[.)] /;

  /** Adds or removes `- ` / `1. ` on every selected line; numbers count up. */
  function list(text, start, end, kind) {
    var c = clamp(text, start, end);
    var r = lineRange(text, c[0], c[1]);
    var lines = text.slice(r[0], r[1]).split('\n');
    var own = kind === 'number' ? NUMBER : BULLET;
    var other = kind === 'number' ? BULLET : NUMBER;
    var content = lines.filter(function (l) { return l.trim() !== ''; });
    var removing = content.length > 0 && content.every(function (l) { return own.test(l); });
    // An all-blank target (a fresh line with nothing on it yet) gets the
    // first marker, same as any other line — only a blank *separator* line
    // inside a selection that has real content stays untouched (TODO 13).
    var skipBlank = content.length > 0;
    var n = 0;
    var out = lines.map(function (l) {
      if (skipBlank && l.trim() === '') return l;
      if (removing) return l.replace(own, '$1');
      var bare = l.replace(own, '$1').replace(other, '$1');
      var indent = bare.match(/^\s*/)[0];
      n++;
      return indent + (kind === 'number' ? n + '. ' : '- ') + bare.slice(indent.length);
    });
    var insert = out.join('\n');

    return edit(r[0], r[1], insert, r[0], r[0] + insert.length);
  }

  var NUMBER_ITEM = /^(\s*)(\d+)([.)]) /;

  /**
   * Enter pressed at the end of a list line (TODO 13): the next marker on a
   * new line (a number counts up from the current one), or — pressed on an
   * already-empty item — the marker is dropped and the line stays, breaking
   * out of the list the way most editors do on a second Enter. Null when
   * `at` is not at the end of a list line, so the caller falls back to a
   * plain newline.
   */
  function continueList(text, at) {
    if (at < bodyStart(text)) return null;
    var r = lineRange(text, at, at);
    if (at !== r[1]) return null;
    var whole = text.slice(r[0], r[1]);
    var bm = whole.match(BULLET);
    var nm = whole.match(NUMBER_ITEM);
    if (!bm && !nm) return null;
    var marker = bm ? bm[0] : nm[0];
    var indent = bm ? bm[1] : nm[1];
    var rest = whole.slice(marker.length);

    if (rest.trim() === '') {
      return edit(r[0], r[1], indent, r[0] + indent.length, r[0] + indent.length);
    }

    var nextMarker = bm ? marker.slice(indent.length) : (parseInt(nm[2], 10) + 1) + nm[3] + ' ';
    var insert = '\n' + indent + nextMarker;

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

  /** A text block (a template's body) at the cursor, as paragraphs of its own. */
  function insertBlock(text, start, end, content) {
    return block(text, start, end, content.replace(/^\n+/, '').replace(/\n+$/, ''));
  }

  /**
   * Adds path to the frontmatter's `priors` list: appended to a block list
   * (the shape Yaml::dump writes), or a new `priors:` before the closing
   * `---`. Null when it is already there (edit: none) — or, with
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
   * A template's body as inserted: without a leading `# ` heading — in a
   * report the first heading is the patient's name (D30 as amended).
   */
  function templateBody(body) {
    return body.replace(/^\s*# [^\n]*\n*/, '');
  }

  /**
   * Snippets (phase 11, D24): the typed `;name` right before `at`, when it
   * starts a word — at the start of a line or after a space or an opening
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
   * The edit that expands a snippet whose `;name` ends at `at` — typed just
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
  function insertSnippet(text, start, end, body) {
    var c = clamp(text, start, end);
    var s = snippetText(body);

    return edit(c[0], c[1], s.text, c[0] + s.caret, c[0] + s.caret);
  }

  /** Applies an edit to a string — what the browser does, for tests. */
  function apply(text, e) {
    return text.slice(0, e.from) + e.insert + text.slice(e.to);
  }

  return {
    bodyStart: bodyStart,
    bodyOf: bodyOf,
    toggleWrap: toggleWrap,
    heading: heading,
    list: list,
    continueList: continueList,
    table: table,
    codeBlock: codeBlock,
    link: link,
    insertBlock: insertBlock,
    templateBody: templateBody,
    triggerBefore: triggerBefore,
    expandAt: expandAt,
    insertSnippet: insertSnippet,
    addPrior: addPrior,
    apply: apply
  };
}));
