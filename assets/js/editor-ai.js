/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The assistant rail's text transforms (roadmap phase 15d), as pure
 * functions — no DOM, so node tests them (tests/Ai/EditorAiTest via
 * tools/run-editor-ai.js); editor.js applies the edit they return
 * ({ from, to, insert }) through execCommand, so Ctrl+Z undoes it.
 *
 * What an answer does, by the action's result mode:
 * - insert:  at the cursor, as a paragraph of its own;
 * - replace: the selection, else the text of the exam in front — below its
 *            `##` heading — or, with no exams, the report's text below its
 *            name heading and exam heading; never the frontmatter;
 * - append:  a section merge — an answer that starts with a `###` heading
 *            the text already has (`### Concluzii`) replaces that section;
 *            anything else is added at the end;
 * - show:    nothing is written (the rail offers "insert at cursor").
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionEditorAi = factory();
  }
}(this, function () {
  'use strict';

  /** Where the body starts: after a leading `---` … `---` block and its blank line, else 0 */
  function bodyStart(text) {
    if (text.slice(0, 4) !== '---\n') return 0;
    var close = text.indexOf('\n---\n', 3);
    if (close === -1) return 0;
    return text.charAt(close + 5) === '\n' ? close + 6 : close + 5;
  }

  function fold(s) {
    return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }

  /** The answer, tidied: no surrounding blank lines, no code fence the model wrapped it in */
  function clean(answer) {
    var t = String(answer).replace(/\r\n?/g, '\n').trim();
    var fenced = /^```[a-z]*\n([\s\S]*?)\n```$/i.exec(t);
    return fenced ? fenced[1].trim() : t;
  }

  /** A block of its own at `at`: a blank line before and after */
  function block(text, at, content) {
    var before = text.slice(0, at);
    var after = text.slice(at);
    var prefix = before === '' || /\n\n$/.test(before) ? '' : (/\n$/.test(before) ? '\n' : '\n\n');
    var suffix = after === '' ? '\n' : (/^\n\n/.test(after) ? '' : (/^\n/.test(after) ? '\n' : '\n\n'));
    return prefix + content + suffix;
  }

  function insert(text, at, answer) {
    return { from: at, to: at, insert: block(text, at, clean(answer)) };
  }

  /** The editable span below the headings that title the text: the name (#) and the exam (##) */
  function contentStart(text) {
    var at = bodyStart(text);
    var re = /^(#{1,2})[ \t][^\n]*\n+/;
    var m;
    while ((m = re.exec(text.slice(at)))) {
      at += m[0].length;
    }
    return at;
  }

  function replace(text, selFrom, selTo, answer) {
    var content = clean(answer);
    if (selFrom !== selTo) {
      return { from: selFrom, to: selTo, insert: content };
    }
    var at = contentStart(text);
    return { from: at, to: text.length, insert: content + '\n' };
  }

  /** The `###` section called `title` in text: [start, end) — to the next heading of level ≤ 3, or the end */
  function section(text, title) {
    var re = /^(#{1,3})[ \t]+(.+?)[ \t#]*$/gm;
    var m;
    var start = -1;
    var from = bodyStart(text);
    re.lastIndex = from;
    while ((m = re.exec(text))) {
      if (start === -1) {
        if (m[1].length === 3 && fold(m[2]) === fold(title)) start = m.index;
      } else {
        return [start, m.index];
      }
    }
    return start === -1 ? null : [start, text.length];
  }

  function append(text, answer) {
    var content = clean(answer);
    var head = /^###[ \t]+(.+?)[ \t#]*$/m.exec(content.split('\n')[0] || '');
    var found = head ? section(text, head[1]) : null;
    if (found) {
      var end = found[1];
      var tail = text.slice(end);
      return { from: found[0], to: end, insert: content + (tail === '' ? '\n' : '\n\n') };
    }
    return { from: text.length, to: text.length, insert: block(text, text.length, content) };
  }

  /** The edit an answer makes in its mode, or null for `show` */
  function apply(mode, text, selFrom, selTo, answer) {
    switch (mode) {
      case 'insert': return insert(text, selFrom, answer);
      case 'replace': return replace(text, selFrom, selTo, answer);
      case 'append': return append(text, answer);
      default: return null;
    }
  }

  /** Server-Sent Events from a text buffer: complete events out, the rest kept */
  function parseEvents(buffer) {
    var events = [];
    var parts = buffer.split('\n\n');
    var rest = parts.pop();
    parts.forEach(function (chunk) {
      var event = 'message';
      var data = '';
      chunk.split('\n').forEach(function (line) {
        if (line.indexOf('event:') === 0) event = line.slice(6).trim();
        else if (line.indexOf('data:') === 0) data += line.slice(5).trim();
      });
      var parsed = null;
      try { parsed = JSON.parse(data); } catch (e) { parsed = null; }
      events.push({ event: event, data: parsed });
    });
    return { events: events, rest: rest };
  }

  return { apply: apply, clean: clean, parseEvents: parseEvents, section: section, contentStart: contentStart };
}));
