<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Render;

/**
 * Two independent, spec-compliant CommonMark renderers legitimately differ
 * in ways that carry no document meaning: league/commonmark always
 * self-closes void elements XHTML-style (`<img ... />`), marked.js emits
 * HTML5 style (`<img ...>`); and either renderer may put a newline before a
 * nested block (a `<ul>` opening right after its `<li>`'s text) where the
 * other does not. tests/RenderConformanceTest compares meaning, not byte
 * layout, so both are normalised away before comparing — everything inside
 * `<pre>` is left untouched, since that whitespace is real document content.
 */
final class HtmlNormalizer
{
    public static function normalize(string $html): string
    {
        $blocks = [];
        $protected = preg_replace_callback(
            '#<pre\b.*?</pre>#s',
            static function (array $m) use (&$blocks): string {
                $key = "\x00PRE" . \count($blocks) . "\x00";
                $blocks[$key] = $m[0];

                return $key;
            },
            trim($html)
        ) ?? trim($html);

        // Three passes, most specific first, so a real CommonMark soft line
        // break (a newline inside a paragraph's inline content) never gets
        // silently deleted along with genuine renderer layout whitespace —
        // deleting it would let both renderers mishandle a soft break the
        // same wrong way and still pass.
        //
        // 1. Whitespace sitting strictly between two tags (tag on both
        //    sides) is always layout: collapse away.
        $html = preg_replace('/(?<=>)[ \t]*\n[ \t\n]*(?=<)/', '', $protected) ?? $protected;
        // 2. Whitespace immediately before a block-level tag opens (a
        //    nested <ul> right after its parent <li>'s text, say) is layout
        //    too, even though it follows text rather than a closing tag.
        $blockTags = 'ul|ol|table|thead|tbody|tr|td|th|blockquote|pre|div|p|h[1-6]|hr|li';
        $html = preg_replace('/[ \t]*\n[ \t\n]*(?=<(?:' . $blockTags . ')\b)/', '', $html) ?? $html;
        // 3. Anything left is a soft line break inside inline content —
        //    both renderers render that as one space, so normalise to one
        //    space, never nothing.
        $noLayoutWhitespace = preg_replace('/[ \t]*\n[ \t\n]*/', ' ', $html) ?? $html;

        // XHTML- vs HTML5-style self-closing void elements are the same tag.
        $noSelfClosingStyle = str_replace(' />', '>', $noLayoutWhitespace);

        return trim(strtr($noSelfClosingStyle, $blocks));
    }
}
