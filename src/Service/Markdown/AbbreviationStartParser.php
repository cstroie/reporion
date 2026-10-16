<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

final class AbbreviationStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== '*') {
            return BlockStart::none();
        }

        $line = substr($cursor->getLine(), $cursor->getNextNonSpacePosition());
        if (preg_match('/^\*\[([^\]\s][^\]]*)\]:[ \t]*(\S.*)$/u', rtrim($line), $m) !== 1) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of(new AbbreviationBlockParser(trim($m[1]), trim($m[2])))->at($cursor);
    }
}
