<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/** A container that ends at a line of just `:::` (or the end of the document, like a code fence) */
final class AdmonitionParser extends AbstractBlockContinueParser
{
    private Admonition $block;

    public function __construct(string $type, string $title)
    {
        $this->block = new Admonition($type, $title);
    }

    public function getBlock(): Admonition
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if (!$cursor->isIndented() && preg_match('/^:{3,}[ \t]*$/', substr($cursor->getLine(), $cursor->getNextNonSpacePosition())) === 1) {
            $cursor->advanceToEnd();

            return BlockContinue::finished();
        }

        return BlockContinue::at($cursor);
    }
}
