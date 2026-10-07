/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Multi-exam reports in the editor (roadmap phase 12, docs/FORMATS.md §12),
 * as pure functions — no DOM, so node tests them (tests/Render/
 * EditorExamsTest via tools/run-editor-exams.js); editor.js builds the tabs.
 *
 * Normal edit's tabs (2026-10-07; raw edit's, on the whole document, until
 * then): openBody() cuts the body into the shared head above the first exam
 * and one piece per exam, by the same line rule as Support\Exams — a line of
 * up to three spaces, `##`, then a space, a tab or the line's end, outside a
 * fenced code block. The exams list is the Details panel's cards
 * (editor-meta-exams.js), so the pieces must be one per card. joinBody()
 * puts the body back, every heading with a blank line before it (a `###`
 * typed straight under a paragraph, or an exam pasted without one). The
 * server still loads and saves the whole body.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorExams = factory();
  }
}(this, function () {
  'use strict';

  var FENCE = /^ {0,3}(`{3,}|~{3,})/;
  var BOUNDARY = /^ {0,3}##(?:[ \t]|$)/;
  var HEADING = /^( {0,3})(#{1,6})(?=[ \t]|$)/;

  function lines(text) {
    return text.match(/[^\n]*\n|[^\n]+$/g) || [];
  }

  /** { fm, sep, body }: fm is the frontmatter's inner lines (null when there is none) */
  function splitFront(doc) {
    if (doc.slice(0, 4) !== '---\n') return { fm: null, sep: '', body: doc };
    var close = doc.indexOf('\n---\n', 3);
    if (close === -1) return { fm: null, sep: '', body: doc };
    var after = doc.slice(close + 5);
    var sep = after.charAt(0) === '\n' ? '\n' : '';
    return { fm: doc.slice(4, close + 1), sep: sep, body: after.slice(sep.length) };
  }

  function headingText(line) {
    return line.replace(/\n$/, '').replace(/^ {0,3}##(?:[ \t]+|$)/, '').replace(/(?:^|[ \t])#+[ \t]*$/, '').trim();
  }

  /** The body's shared head and its exams, as Support\Exams::split() */
  function splitBody(body) {
    var head = '';
    var parts = [];
    var fence = null;
    lines(body).forEach(function (line) {
      var bare = line.replace(/\r?\n$/, '');
      var m;
      if (fence !== null) {
        m = FENCE.exec(bare);
        if (m && m[1].charAt(0) === fence.charAt(0) && m[1].length >= fence.length && bare.replace(/^\s+/, '').slice(m[1].length).trim() === '') {
          fence = null;
        }
      } else if ((m = FENCE.exec(bare))) {
        fence = m[1];
      } else if (BOUNDARY.test(bare)) {
        parts.push({ title: headingText(bare), text: '' });
      }
      if (parts.length === 0) {
        head += line;
      } else {
        parts[parts.length - 1].text += line;
      }
    });
    return { head: head, parts: parts };
  }

  function withNewline(text) {
    return text === '' || text.slice(-1) === '\n' ? text : text + '\n';
  }

  /**
   * The body as normal edit's panes: { head, parts } — one piece per card,
   * or null when the ## sections and the cards are not one to one (or there
   * is a single exam), so the body stays one textarea and nothing is hidden
   */
  function openBody(body, count) {
    var split = splitBody(body);
    if (count < 2 || split.parts.length !== count) return null;
    return { head: split.head, parts: split.parts.map(function (p) { return p.text; }) };
  }

  /** The body from the panes: the head, then each exam's piece, every heading spaced */
  function joinBody(state) {
    var pieces = [state.head].concat(state.parts);
    var text = '';
    pieces.forEach(function (piece, i) {
      text += i < pieces.length - 1 ? withNewline(piece) : piece;
    });
    return spaceHeadings(text);
  }

  /** A blank line before every ATX heading that has text right above it — never inside a fenced block */
  function spaceHeadings(text) {
    var fence = null;
    var prev = null;
    var out = '';
    lines(text).forEach(function (line) {
      var m;
      if (fence !== null) {
        m = FENCE.exec(line);
        if (m && m[1].charAt(0) === fence.charAt(0) && m[1].length >= fence.length) fence = null;
      } else if ((m = FENCE.exec(line))) {
        fence = m[1];
      } else if (HEADING.test(line) && prev !== null && prev.trim() !== '') {
        out += '\n';
      }
      out += line;
      prev = line;
    });
    return out;
  }

  /** A template's headings one level down (never past ######), so its ## cannot split an exam */
  function demote(text) {
    var fence = null;
    return lines(text).map(function (line) {
      var m;
      if (fence !== null) {
        m = FENCE.exec(line);
        if (m && m[1].charAt(0) === fence.charAt(0) && m[1].length >= fence.length) fence = null;
        return line;
      }
      if ((m = FENCE.exec(line))) { fence = m[1]; return line; }
      m = HEADING.exec(line);
      return m && m[2].length < 6 ? m[1] + m[2] + '#' + line.slice(m[0].length) : line;
    }).join('');
  }

  /** Whether a top-level `##` comes before offset `at` in a document's body */
  function examBefore(doc, at) {
    var front = splitFront(doc);
    var start = doc.length - front.body.length;
    return at > start && splitBody(doc.slice(start, at)).parts.length > 0;
  }

  return {
    splitBody: splitBody,
    openBody: openBody,
    joinBody: joinBody,
    spaceHeadings: spaceHeadings,
    demote: demote,
    examBefore: examBefore
  };
}));
