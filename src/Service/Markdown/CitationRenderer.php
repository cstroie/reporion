<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * A citation as the page shows it: `[1]`, `[1-3]` (numbered styles) or `(Nobody & Somebody, 2024)`
 * (APA), each source linking to its reference below (and, for a reader who may open it, to its
 * library page). A citation Render could not resolve is shown as typed, marked.
 */
final class CitationRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string|HtmlElement
    {
        CitationNode::assertInstanceOf($node);

        if ($node->resolved === null) {
            return new HtmlElement('span', ['class' => 'wk-cite wk-cite-raw'], htmlspecialchars($node->source, ENT_QUOTES));
        }

        $apa = $node->style === 'apa';
        $parts = [];
        $locators = false;
        foreach ($node->resolved as $i => $item) {
            $locator = $node->items[$i]['locator'] ?? '';
            if ($item['number'] === null) {
                $parts[] = new HtmlElement('span', ['class' => 'wk-cite-missing', 'title' => t('library.cite_missing')], htmlspecialchars('@' . $item['key'], ENT_QUOTES));
                continue;
            }
            $notice = $item['notice'] ?? null;
            $link = (string) new HtmlElement('a', ['class' => 'wk-cite-ref' . ($notice !== null ? ' wk-cite-flag wk-cite-flag-' . $notice : ''), 'href' => '#ref-' . $item['key']] + ($notice !== null ? ['title' => t('library.notice.' . $notice)] : []), htmlspecialchars($apa ? $item['label'] : (string) $item['number'], ENT_QUOTES));
            $locators = $locators || $locator !== '';
            $parts[] = $link . ($locator !== '' ? htmlspecialchars(', ' . $locator, ENT_QUOTES) : '');
        }
        $parts = array_map('strval', $parts);

        // A locator belongs to its own source, so with one the sources are told apart by a semicolon
        return new HtmlElement('span', ['class' => 'wk-cite'], ($apa ? '(' : '[') . implode($apa || $locators ? '; ' : ', ', $parts) . ($apa ? ')' : ']'));
    }
}
