/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The editor's split preview kept in step with the text, both ways.
 * The server stamps each top-level block and list item of the preview with
 * `data-line`, its first source line (Service\Render, `lines`), so:
 *   - scrolling the text scrolls the preview to the same place, and the other way;
 *   - the block the caret is in is lit in the preview;
 *   - a click in the preview puts the caret on that block's first line
 *   - in a multi-exam report the preview shows the tab in front, not the whole report.
 * Side by side only: stacked (narrow) the page scrolls, not the panes.
 *
 * The line/pixel arithmetic is pure (tested in node, tests/Render/
 * EditorSyncTest via tools/run-editor-sync.js); the rest is DOM glue.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ReporionSync = factory();
    if (typeof document !== 'undefined') root.ReporionSync.init();
  }
}(this, function () {
  'use strict';

  /*
   * blocks: [{ line, top }] in document order, `line` the 1-based source line a
   * block starts on and `top` its pixel offset in the scrolling pane, ending
   * with a sentinel { line: lastLine + 1, top: pane's full height }.
   */

  /** Index of the last block whose key is <= value, -1 if none */
  function lastAtOrBefore(blocks, key, value) {
    var found = -1;
    for (var i = 0; i < blocks.length; i++) {
      if (blocks[i][key] <= value) found = i; else break;
    }
    return found;
  }

  /** The next block that starts on a later line than blocks[i] (the sentinel at the latest) */
  function nextLater(blocks, i) {
    var j = i + 1;
    while (j < blocks.length - 1 && blocks[j].line <= blocks[i].line) j++;
    return Math.min(j, blocks.length - 1);
  }

  /** Pixel offset in the preview of a (fractional) source line */
  function yOfLine(blocks, line) {
    if (blocks.length < 2) return 0;
    var i = lastAtOrBefore(blocks, 'line', line);
    if (i < 0) return 0;
    if (i >= blocks.length - 1) return blocks[blocks.length - 1].top;
    var j = nextLater(blocks, i);
    var span = blocks[j].line - blocks[i].line;
    var frac = span > 0 ? Math.min(1, Math.max(0, (line - blocks[i].line) / span)) : 0;
    return blocks[i].top + frac * (blocks[j].top - blocks[i].top);
  }

  /** The (fractional) source line at a pixel offset in the preview */
  function lineAtY(blocks, y) {
    if (blocks.length < 2) return 1;
    var i = lastAtOrBefore(blocks, 'top', y);
    if (i < 0) return blocks[0].line;
    if (i >= blocks.length - 1) return blocks[blocks.length - 1].line;
    var j = nextLater(blocks, i);
    var height = blocks[j].top - blocks[i].top;
    var frac = height > 0 ? Math.min(1, Math.max(0, (y - blocks[i].top) / height)) : 0;
    return blocks[i].line + frac * (blocks[j].line - blocks[i].line);
  }

  /** The block (not the sentinel) a source line falls in, -1 if before the first */
  function blockAtLine(blocks, line) {
    var i = lastAtOrBefore(blocks, 'line', line);
    return i >= blocks.length - 1 ? blocks.length - 2 : i;
  }

  /**
   * tops: the pixel offset of each source line's top in the text, plus the full
   * height as the last entry. A fractional 0-based line index at a pixel offset.
   */
  function textLineAtY(tops, y) {
    var n = tops.length - 1;
    if (n < 1) return 0;
    var i = 0;
    for (var k = 0; k < n; k++) {
      if (tops[k] <= y) i = k; else break;
    }
    var height = tops[i + 1] - tops[i];
    return i + (height > 0 ? Math.min(1, Math.max(0, (y - tops[i]) / height)) : 0);
  }

  /** The pixel offset of a fractional 0-based line index of the text */
  function textYOfLine(tops, line) {
    var n = tops.length - 1;
    if (n < 1) return 0;
    var i = Math.min(n - 1, Math.max(0, Math.floor(line)));
    return tops[i] + Math.min(1, Math.max(0, line - i)) * (tops[i + 1] - tops[i]);
  }

  var api = {
    yOfLine: yOfLine,
    lineAtY: lineAtY,
    blockAtLine: blockAtLine,
    textLineAtY: textLineAtY,
    textYOfLine: textYOfLine,
    init: function () {}
  };

  // ---- DOM glue: browser only ----------------------------------------------

  api.init = function () {
    var pane = document.getElementById('editor-pane');
    var preview = document.getElementById('editor-preview');
    if (!pane || !preview) return;

    var mirror = null;
    var cache = null;
    var muted = typeof WeakMap === 'function' ? new WeakMap() : null;
    var frame = 0;
    var lit = null;

    /** The scroll we set ourselves is not the user's: ignore its event for a moment */
    function mute(el) { if (muted) muted.set(el, Date.now() + 120); }
    function isMuted(el) { return !!muted && (muted.get(el) || 0) > Date.now(); }
    function setScroll(el, y) {
      var max = Math.max(0, el.scrollHeight - el.clientHeight);
      var next = Math.min(max, Math.max(0, Math.round(y)));
      if (Math.abs(el.scrollTop - next) < 1) return;
      mute(el);
      el.scrollTop = next;
    }
    function atEnd(el) { return el.scrollHeight > el.clientHeight && el.scrollTop + el.clientHeight >= el.scrollHeight - 2; }

    function sideBySide() {
      return !preview.hidden && window.getComputedStyle(pane).flexDirection === 'row';
    }

    function editors() {
      return Array.prototype.slice.call(pane.querySelectorAll('textarea'));
    }
    function active() {
      var all = editors();
      for (var i = 0; i < all.length; i++) if (!all[i].hidden) return all[i];
      return null;
    }

    /**
     * Lines above a textarea's first line in the text the preview was rendered
     * from: the raw document's frontmatter is left out of it; an exam pane (or the
     * head) is rendered alone, from its own first line
     */
    function offsetOf(el) {
      if (el.name === 'document') return -(window.ReporionRender ? window.ReporionRender.skipped(el.value) : 0);
      return 0;
    }

    /** The preview's blocks with their pixel offsets, and the sentinel */
    function previewBlocks() {
      var base = preview.getBoundingClientRect().top - preview.scrollTop;
      var nodes = preview.querySelectorAll('[data-line]');
      var blocks = [];
      for (var i = 0; i < nodes.length; i++) {
        blocks.push({ line: Number(nodes[i].getAttribute('data-line')), top: nodes[i].getBoundingClientRect().top - base, el: nodes[i] });
      }
      var whole = active();
      var rendered = whole ? whole.value.split('\n').length - (whole.name === 'document' && window.ReporionRender ? window.ReporionRender.skipped(whole.value) : 0) : 0;
      var last = blocks.length ? Math.max(blocks[blocks.length - 1].line, rendered) : 0;
      var inner = window.getComputedStyle(preview);
      blocks.push({ line: last + 1, top: preview.scrollHeight - (parseFloat(inner.paddingBottom) || 0), el: null });
      return blocks;
    }

    /** The text's source lines' pixel offsets, measured in a hidden copy of the textarea (wrapped lines included) */
    function textTops(ta) {
      var width = ta.clientWidth;
      if (cache && cache.ta === ta && cache.value === ta.value && cache.width === width) return cache.tops;
      if (!mirror) {
        mirror = document.createElement('div');
        mirror.setAttribute('aria-hidden', 'true');
        document.body.appendChild(mirror);
      }
      var cs = window.getComputedStyle(ta);
      var st = mirror.style;
      st.cssText = 'position:absolute;visibility:hidden;pointer-events:none;top:0;left:-99999px;border:0;box-sizing:border-box;white-space:pre-wrap;overflow-wrap:break-word;word-wrap:break-word;';
      ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontVariant', 'letterSpacing', 'wordSpacing', 'lineHeight', 'tabSize', 'textTransform', 'textIndent', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach(function (p) {
        st[p] = cs[p];
      });
      st.width = width + 'px';
      mirror.textContent = '';
      var lines = ta.value.split('\n');
      var frag = document.createDocumentFragment();
      lines.forEach(function (line) {
        var d = document.createElement('div');
        d.textContent = line === '' ? '​' : line;
        frag.appendChild(d);
      });
      mirror.appendChild(frag);
      var tops = [];
      for (var i = 0; i < mirror.children.length; i++) tops.push(mirror.children[i].offsetTop);
      tops.push(mirror.scrollHeight - (parseFloat(cs.paddingBottom) || 0));
      cache = { ta: ta, value: ta.value, width: width, tops: tops };
      mirror.textContent = '';
      return tops;
    }

    function ready() {
      return sideBySide() && preview.querySelector('[data-line]') !== null;
    }

    function fromText() {
      var ta = active();
      if (!ta || !ready()) return;
      var off = offsetOf(ta);
      if (off === null) return;
      if (atEnd(ta)) { setScroll(preview, preview.scrollHeight); return; }
      var line = textLineAtY(textTops(ta), ta.scrollTop) + 1 + off;
      setScroll(preview, yOfLine(previewBlocks(), line));
    }

    function fromPreview() {
      var ta = active();
      if (!ta || !ready()) return;
      var off = offsetOf(ta);
      if (off === null) return;
      if (atEnd(preview)) { setScroll(ta, ta.scrollHeight); return; }
      var tops = textTops(ta);
      var f = lineAtY(previewBlocks(), preview.scrollTop) - 1 - off;
      f = Math.min(tops.length - 2, Math.max(0, f));
      setScroll(ta, textYOfLine(tops, f));
    }

    function caretLine(ta) {
      return ta.value.slice(0, ta.selectionStart).split('\n').length;
    }

    /** Light the block the caret is in */
    function highlight() {
      if (lit) { lit.classList.remove('wk-sync-on'); lit = null; }
      var ta = active();
      if (!ta || !ready() || document.activeElement !== ta) return;
      var off = offsetOf(ta);
      if (off === null) return;
      var blocks = previewBlocks();
      var i = blockAtLine(blocks, caretLine(ta) + off);
      if (i < 0 || !blocks[i].el) return;
      lit = blocks[i].el;
      lit.classList.add('wk-sync-on');
    }

    function schedule(fn) {
      if (frame) return;
      frame = window.requestAnimationFrame(function () { frame = 0; fn(); });
    }

    // The text scrolls (scroll does not bubble: capture on the pane)
    pane.addEventListener('scroll', function (event) {
      if (event.target === preview || isMuted(event.target)) return;
      schedule(fromText);
    }, true);
    preview.addEventListener('scroll', function () {
      if (isMuted(preview)) return;
      schedule(fromPreview);
    });

    ['keyup', 'click', 'input', 'focusin', 'focusout'].forEach(function (name) {
      pane.addEventListener(name, function () { schedule(highlight); });
    });

    // A click in the preview: the caret goes to that block's line
    preview.addEventListener('click', function (event) {
      if (!ready() || !(event.target instanceof Element) || event.target.closest('a')) return;
      var sel = window.getSelection ? window.getSelection() : null;
      if (sel && String(sel).length > 0) return;
      var block = event.target.closest('[data-line]');
      if (!block) return;
      var line = Number(block.getAttribute('data-line'));
      var ta = active();
      if (!ta) return;
      var off = offsetOf(ta);
      if (off === null) return;
      var index = Math.max(0, line - off - 1);
      var lines = ta.value.split('\n');
      index = Math.min(index, lines.length - 1);
      var pos = 0;
      for (var i = 0; i < index; i++) pos += lines[i].length + 1;
      if (typeof ta.focus === 'function') ta.focus({ preventScroll: true });
      ta.setSelectionRange(pos, pos);
      mute(ta);
      mute(preview);
      ta.scrollTop = Math.max(0, textYOfLine(textTops(ta), index) - ta.clientHeight / 3);
      highlight();
    });

    api.refresh = function () {
      // The preview was just filled (its scroll is back at the top): back to where the text is
      schedule(function () { fromText(); highlight(); });
    };
  };

  api.refresh = function () {};

  return api;
}));
