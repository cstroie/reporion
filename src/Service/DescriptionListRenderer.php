<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use League\CommonMark\Extension\DescriptionList\Node\DescriptionList;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * league/commonmark's own <dl> renderer drops the node's attributes; the
 * split preview's `data-line` (Render::markLines()) needs them on the list
 * too. Same markup otherwise.
 */
final class DescriptionListRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        DescriptionList::assertInstanceOf($node);

        $separator = $childRenderer->getBlockSeparator();

        return new HtmlElement('dl', $node->data->get('attributes'), $separator . $childRenderer->renderNodes($node->children()) . $separator);
    }
}
