<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/** One line, nothing to continue */
final class AbbreviationBlockParser extends AbstractBlockContinueParser
{
    private AbbreviationDefinition $block;

    public function __construct(string $abbreviation, string $title)
    {
        $this->block = new AbbreviationDefinition($abbreviation, $title);
    }

    public function getBlock(): AbbreviationDefinition
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }
}
