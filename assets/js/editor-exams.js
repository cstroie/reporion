/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Multi-exam reports in the editor (roadmap phase 12, docs/FORMATS.md §12),
 * as pure functions — no DOM, so node tests them (tests/Render/
 * EditorExamsTest via tools/run-editor-exams.js); editor.js builds the tabs.
 *
 * open() cuts a document into a head — the frontmatter without its `exams:`
 * list, and the shared text above the first exam — and one piece per exam,
 * by the same line rule as Support\Exams: a line of up to three spaces,
 * `##`, then a space, a tab or the line's end, outside a fenced code block.
 * join() puts it back: the exams list is written from the tabs (each
 * exam's title kept in step with its `##` heading), the pieces follow the
 * head. The server still loads and saves the whole document.
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
  var KEYS = ['title', 'region', 'accession', 'study_uid', 'pacs_accession', 'template'];

  /** An exam entry: what the frontmatter says of one exam (the PACS study keys are set by the dicom plugin) */
  function newExam(title) {
    return { title: title || '', region: [], accession: '', study_uid: '', pacs_accession: '', template: '' };
  }

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

  function unquote(value) {
    var v = value.trim();
    if (/^'.*'$/.test(v)) return v.slice(1, -1).replace(/''/g, "'");
    if (/^".*"$/.test(v)) {
      try { return JSON.parse(v); } catch (e) { return v.slice(1, -1); }
    }
    return v;
  }

  function quote(value) {
    return "'" + String(value).replace(/'/g, "''") + "'";
  }

  /**
   * The `exams:` list in frontmatter lines: { exams, rest, at } — rest is
   * the frontmatter without it, at the line it stood on. null without an
   * `exams:` key; false for a shape this does not read (flow style, an
   * unknown key), so the editor keeps the plain single textarea.
   */
  function parseExams(fm) {
    var all = lines(fm);
    var at = -1;
    for (var i = 0; i < all.length; i++) {
      if (/^exams:/.test(all[i])) { at = i; break; }
    }
    if (at === -1) return null;
    if (all[at].replace(/^exams:/, '').trim() !== '') return false;
    var end = at + 1;
    while (end < all.length && (/^[ \t-]/.test(all[end]) || all[end].trim() === '')) end++;

    var exams = [];
    var entryIndent = -1;
    var current = null;
    var listKey = null;
    var ok = true;
    all.slice(at + 1, end).forEach(function (raw) {
      var line = raw.replace(/\n$/, '');
      if (line.trim() === '' || !ok) return;
      var indent = line.length - line.replace(/^\s+/, '').length;
      var dash = /^(\s*)-(?:\s+(.*))?$/.exec(line);
      if (dash && (entryIndent === -1 || indent === entryIndent)) {
        entryIndent = indent;
        current = newExam('');
        exams.push(current);
        listKey = null;
        if (dash[2] && dash[2].trim() !== '') line = new Array(indent + 3).join(' ') + dash[2];
        else return;
      }
      if (current === null) { ok = false; return; }
      if (listKey !== null) {
        var item = /^\s*-\s+(.*)$/.exec(line);
        if (item) { current[listKey].push(unquote(item[1])); return; }
        listKey = null;
      }
      var kv = /^\s+([A-Za-z_]+):(?:\s+(.*))?$/.exec(line);
      if (!kv || KEYS.indexOf(kv[1]) === -1) { ok = false; return; }
      var value = kv[2] === undefined ? '' : kv[2].trim();
      if (kv[1] === 'region') {
        if (value === '') { listKey = 'region'; return; }
        var flow = /^\[(.*)\]$/.exec(value);
        current.region = flow ? flow[1].split(',').map(unquote).filter(function (r) { return r !== ''; }) : [unquote(value)];
        return;
      }
      current[kv[1]] = unquote(value);
    });
    if (!ok) return false;
    return { exams: exams, rest: all.slice(0, at).concat(all.slice(end)).join(''), at: at };
  }

  function dumpExams(exams) {
    var out = 'exams:\n';
    exams.forEach(function (exam) {
      out += '  -\n    title: ' + quote(exam.title) + '\n';
      if (exam.region.length > 0) {
        out += '    region:\n' + exam.region.map(function (r) { return '      - ' + quote(r) + '\n'; }).join('');
      }
      if (exam.accession !== '') out += '    accession: ' + quote(exam.accession) + '\n';
      if (exam.study_uid) out += '    study_uid: ' + quote(exam.study_uid) + '\n';
      if (exam.pacs_accession) out += '    pacs_accession: ' + quote(exam.pacs_accession) + '\n';
      if (exam.template) out += '    template: ' + quote(exam.template) + '\n';
    });
    return out;
  }

  function withNewline(text) {
    return text === '' || text.slice(-1) === '\n' ? text : text + '\n';
  }

  /**
   * A multi-exam document as its panes: { head, exams, parts, at } — head
   * is the text of the head pane, parts the text of each exam's pane.
   * null for a report that declares no exams, false for one this cannot
   * read — an exams list in a shape it does not parse, or one whose
   * entries and `##` headings differ in number: a tab per heading would
   * hide the extra entries (or headings) where they could not be removed,
   * and every save would write them back, so the whole document is
   * edited as text until they agree.
   */
  function open(doc) {
    var front = splitFront(doc);
    if (front.fm === null) return null;
    var parsed = parseExams(front.fm);
    if (!parsed) return parsed;
    var body = splitBody(front.body);
    if (parsed.exams.length !== body.parts.length) return false;
    return {
      head: '---\n' + parsed.rest + '---\n' + front.sep + body.head,
      exams: parsed.exams,
      parts: body.parts.map(function (p) { return p.text; }),
      at: parsed.at
    };
  }

  /** The whole document from the panes: exams list back in the frontmatter, titles from the headings */
  function join(state) {
    var front = splitFront(state.head);
    var exams = state.exams.map(function (exam, i) {
      var first = state.parts[i] !== undefined ? lines(state.parts[i])[0] || '' : '';
      return { title: BOUNDARY.test(first) ? headingText(first) : exam.title, region: exam.region.slice(), accession: exam.accession, study_uid: exam.study_uid || '', pacs_accession: exam.pacs_accession || '', template: exam.template || '' };
    });
    var body = front.fm === null ? state.head : front.body;
    var pieces = [body].concat(state.parts);
    var text = '';
    pieces.forEach(function (piece, i) {
      text += i < pieces.length - 1 ? withNewline(piece) : piece;
    });
    if (front.fm === null) return text;
    var fmLines = lines(front.fm);
    var at = Math.min(state.at, fmLines.length);
    var fm = fmLines.slice(0, at).join('') + dumpExams(exams) + fmLines.slice(at).join('');
    return '---\n' + fm + '---\n' + front.sep + text;
  }

  /** A new exam at the end: its ## and the sections every exam has */
  function addExam(state, title) {
    var parts = state.parts.slice();
    if (parts.length > 0) {
      var last = parts[parts.length - 1];
      parts[parts.length - 1] = /\n\n$/.test(last) ? last : withNewline(last) + '\n';
    } else if (state.head !== '' && !/\n\n$/.test(state.head)) {
      state = Object.assign({}, state, { head: withNewline(state.head) + '\n' });
    }
    parts.push('## ' + title + '\n\n### Descriere\n\n### Concluzii\n');
    return Object.assign({}, state, {
      exams: state.exams.concat([newExam(title)]),
      parts: parts
    });
  }

  /**
   * A single-exam report becoming multi-exam (the first Add exam): its one
   * `##` is exam 1 — the server moves its accession there on save. Only in
   * the one-heading shape (docs/FORMATS.md §11); anything else says why.
   */
  function convert(doc, title) {
    var front = splitFront(doc);
    if (front.fm === null) return { error: 'shape' };
    if (parseExams(front.fm) !== null) return { error: 'shape' };
    var body = splitBody(front.body);
    if (body.parts.length !== 1) return { error: 'shape' };
    var fmLines = lines(front.fm);
    var state = {
      head: '---\n' + front.fm + '---\n' + front.sep + body.head,
      exams: [newExam(body.parts[0].title)],
      parts: [body.parts[0].text],
      at: fmLines.length
    };
    return addExam(state, title);
  }

  function removeExam(state, index) {
    return Object.assign({}, state, {
      exams: state.exams.filter(function (e, i) { return i !== index; }),
      parts: state.parts.filter(function (p, i) { return i !== index; })
    });
  }

  /** Exam `index` moved by `delta` places (the tab's ← and →), its text with it */
  function moveExam(state, index, delta) {
    var to = index + delta;
    if (to < 0 || to >= state.exams.length || index >= state.parts.length || to >= state.parts.length) return state;
    var exams = state.exams.slice();
    // The last piece may lack its final newline: keep pieces separable
    var parts = state.parts.map(withNewline);
    exams.splice(to, 0, exams.splice(index, 1)[0]);
    parts.splice(to, 0, parts.splice(index, 1)[0]);
    return Object.assign({}, state, { exams: exams, parts: parts });
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
    parseExams: parseExams,
    open: open,
    join: join,
    addExam: addExam,
    convert: convert,
    removeExam: removeExam,
    moveExam: moveExam,
    demote: demote,
    examBefore: examBefore
  };
}));
