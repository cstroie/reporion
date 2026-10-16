<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/** Block layout only: dompdf has no flexbox (print.css) */
final class AdmonitionRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        Admonition::assertInstanceOf($node);

        $separator = $childRenderer->getBlockSeparator();
        $title = $node->title === ''
            ? ''
            : '<p class="wk-admonition-title">' . htmlspecialchars($node->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . $separator;
        $attributes = ['class' => 'wk-admonition wk-admonition-' . $node->type] + $node->data->get('attributes');

        return new HtmlElement('div', $attributes, $separator . $title . $childRenderer->renderNodes($node->children()) . $separator);
    }
}
