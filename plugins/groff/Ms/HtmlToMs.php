<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Plugin\Groff\Ms;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The report's body HTML (Service\Render::body(), the one canonical
 * renderer — invariant 4) as groff ms lines. The subset Render produces is
 * small: headings, paragraphs, lists, emphasis, code, blockquotes, rules,
 * tables, links, images. `#` is `.SH 1`, `##` `.SH 2`, `###` `.SH 3`; deeper
 * headings are a bold paragraph, anything else unknown is read as text.
 * Images are not embedded: their alt text stands in.
 */
final class HtmlToMs
{
    /** @var list<string> */
    private array $out = [];

    /** @return list<string> the ms lines, none with a trailing newline */
    public static function convert(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $me = new self();
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $me->blocks($body);
        }

        return $me->out;
    }

    private function blocks(DOMNode $parent): void
    {
        $pending = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $this->isBlock($node)) {
                $this->flush($pending, '.PP');
                $this->block($node);
                continue;
            }
            $pending .= $this->inline($node, '');
        }
        $this->flush($pending, '.PP');
    }

    private function isBlock(DOMElement $e): bool
    {
        return \in_array($e->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'pre', 'blockquote', 'hr', 'table', 'div', 'section'], true);
    }

    private function block(DOMElement $e): void
    {
        switch ($e->nodeName) {
            case 'h1':
                $this->heading(1, $e);
                break;
            case 'h2':
                $this->heading(2, $e);
                break;
            case 'h3':
                $this->heading(3, $e);
                break;
            case 'h4':
            case 'h5':
            case 'h6':
                $text = '\\f[B]' . $this->inlineChildren($e, 'B') . '\\f[R]';
                $this->flush($text, '.PP');
                break;
            case 'p':
                $text = $this->inlineChildren($e, '');
                $this->flush($text, '.PP');
                break;
            case 'ul':
            case 'ol':
                $this->list($e, 0);
                break;
            case 'pre':
                $this->pre($e);
                break;
            case 'blockquote':
                $this->quote($e);
                break;
            case 'hr':
                $this->out[] = '.sp';
                break;
            case 'table':
                $this->table($e);
                break;
            default: // div, section: their content
                $this->blocks($e);
        }
    }

    private function heading(int $level, DOMElement $e): void
    {
        $this->out[] = '.SH ' . $level;
        foreach ($this->lines(Escape::text($this->plain($e))) as $line) {
            $this->out[] = $line;
        }
    }

    /** A list; nested ones sit in .RS/.RE under the item that holds them */
    private function list(DOMElement $list, int $depth): void
    {
        $n = 0;
        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || $item->nodeName !== 'li') {
                continue;
            }
            ++$n;
            $this->out[] = $list->nodeName === 'ol' ? '.IP "' . $n . '." 3' : '.IP \\(bu 2';
            $text = '';
            $nested = [];
            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && \in_array($child->nodeName, ['ul', 'ol'], true)) {
                    $nested[] = $child;
                } elseif ($child instanceof DOMElement && $child->nodeName === 'p') {
                    $text .= ($text !== '' ? ' ' : '') . $this->inlineChildren($child, '');
                } else {
                    $text .= $this->inline($child, '');
                }
            }
            foreach ($this->lines($this->tidy($text)) as $line) {
                $this->out[] = $line;
            }
            foreach ($nested as $inner) {
                $this->out[] = '.RS';
                $this->list($inner, $depth + 1);
                $this->out[] = '.RE';
            }
        }
    }

    private function pre(DOMElement $e): void
    {
        $this->out[] = '.DS L';
        $this->out[] = '.ft CR';
        foreach (explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $e->textContent), "\n")) as $line) {
            $this->out[] = Escape::literal($line);
        }
        $this->out[] = '.ft P';
        $this->out[] = '.DE';
    }

    private function quote(DOMElement $e): void
    {
        foreach ($e->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'p') {
                $text = $this->inlineChildren($child, '');
                $this->flush($text, '.QP');
            } elseif ($child instanceof DOMElement && $this->isBlock($child)) {
                $this->block($child);
            } else {
                $text = $this->inline($child, '');
                $this->flush($text, '.QP');
            }
        }
    }

    /** tbl: every cell a text block, the header row bold and boxed */
    private function table(DOMElement $table): void
    {
        $rows = [];
        $header = 0;
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && \in_array($cell->nodeName, ['th', 'td'], true)) {
                    $cells[] = [$cell->nodeName === 'th', $this->tidy($this->inlineChildren($cell, ''))];
                }
            }
            if ($cells === []) {
                continue;
            }
            if ($cells[0][0] && \count($rows) === $header) {
                ++$header;
            }
            $rows[] = $cells;
        }
        if ($rows === []) {
            return;
        }
        $columns = max(array_map('count', $rows));
        $this->out[] = '.TS';
        $this->out[] = 'allbox;';
        // A text block per cell, the columns sharing the 16 cm of the line
        $width = '(' . rtrim(rtrim(number_format(16 / $columns, 1, '.', ''), '0'), '.') . 'c)';
        if ($header > 0) {
            $this->out[] = trim(str_repeat('lbw' . $width . ' ', $columns));
        }
        $this->out[] = rtrim(str_repeat('lw' . $width . ' ', $columns)) . '.';
        foreach ($rows as $row) {
            $cells = [];
            for ($i = 0; $i < $columns; ++$i) {
                $text = $row[$i][1] ?? '';
                $cells[] = "T{\n" . implode("\n", $this->lines($text)) . "\nT}";
            }
            $this->out[] = implode("\t", $cells);
        }
        $this->out[] = '.TE';
    }

    /** A request line followed by the text, wrapped onto lines by .br marks; nothing when the text is empty */
    private function flush(string &$text, string $request): void
    {
        $text = $this->tidy($text);
        if ($text !== '') {
            $this->out[] = $request;
            foreach ($this->lines($text) as $line) {
                $this->out[] = $line;
            }
        }
        $text = '';
    }

    /** Collapses whitespace and trims it; the explicit break marks stay */
    private function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t\r\n]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\x01 ?/u', "\x01", $text);

        return trim($text, " \x01");
    }

    /** @return list<string> the text as ms lines: a break mark becomes a .br line */
    private function lines(string $text): array
    {
        $out = [];
        foreach (explode("\x01", $text) as $i => $part) {
            if ($i > 0) {
                $out[] = '.br';
            }
            $part = trim($part);
            if ($part !== '') {
                $out[] = Escape::line($part);
            }
        }

        return $out;
    }

    /** The inline content of $e, $style being the font the surrounding text is set in */
    private function inlineChildren(DOMNode $e, string $style): string
    {
        $text = '';
        foreach ($e->childNodes as $child) {
            $text .= $this->inline($child, $style);
        }

        return $text;
    }

    private function inline(DOMNode $node, string $style): string
    {
        if ($node instanceof DOMText) {
            return Escape::text($node->wholeText);
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        switch ($node->nodeName) {
            case 'strong':
            case 'b':
                return $this->styled($node, $style, 'B');
            case 'em':
            case 'i':
                return $this->styled($node, $style, 'I');
            case 'code':
                return $this->styled($node, $style, 'C');
            case 'br':
                return "\x01";
            case 'img':
                $alt = trim($node->getAttribute('alt'));

                return $alt !== '' ? Escape::text('[' . $alt . ']') : '';
            case 'a':
                $href = $node->getAttribute('href');
                $label = $this->inlineChildren($node, $style);
                if (preg_match('#^https?://#i', $href) === 1 && trim($node->textContent) !== $href) {
                    return $label . ' (\\%' . Escape::text($href) . ')';
                }

                return $label;
            default:
                return $this->inlineChildren($node, $style);
        }
    }

    /** Text set in $add (B, I or C) on top of $style: fonts are named in full, so a nested style returns to the outer one */
    private function styled(DOMElement $e, string $style, string $add): string
    {
        $new = str_contains($style, $add) ? $style : $style . $add;

        return '\\f[' . $this->font($new) . ']' . $this->inlineChildren($e, $new) . '\\f[' . $this->font($style) . ']';
    }

    /** A style set (any of B, I, C) as a groff font name: R, B, I, BI, CR, CB, CI, CBI */
    private function font(string $style): string
    {
        $face = (str_contains($style, 'B') ? 'B' : '') . (str_contains($style, 'I') ? 'I' : '');
        if (str_contains($style, 'C')) {
            return 'C' . ($face !== '' ? $face : 'R');
        }

        return $face !== '' ? $face : 'R';
    }

    private function plain(DOMNode $e): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $e->textContent));
    }
}
