<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

final class AdmonitionStartParser implements BlockStartParserInterface
{
    /** The kinds a callout can be: each has its own colour in the stylesheets */
    public const TYPES = ['note', 'tip', 'info', 'warning', 'caution', 'important'];

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== ':') {
            return BlockStart::none();
        }

        $line = substr($cursor->getLine(), $cursor->getNextNonSpacePosition());
        if (preg_match('/^:{3}[ \t]*([A-Za-z]+)(?:[ \t]+(.*?))?[ \t]*$/', $line, $m) !== 1 || !\in_array(strtolower($m[1]), self::TYPES, true)) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of(new AdmonitionParser(strtolower($m[1]), $m[2] ?? ''))->at($cursor);
    }
}
