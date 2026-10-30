<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * `[@key]`, `[@key, p. 12]`, `[@a; @b, ch. 2]` as a CitationNode. A bracket that is a link
 * (`[@x](url)`, `[@x][ref]`) or holds anything else stays CommonMark's.
 */
final class CitationParser implements InlineParserInterface
{
    /** A key as Support\CitationKey writes it */
    private const KEY = '[a-z][a-z0-9-]{1,62}';

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('[@');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $item = '\s*@' . self::KEY . '(?:\s*,[^\];\[]*)?\s*';
        $state = $cursor->saveState();
        $text = $cursor->match('/\G\[' . $item . '(?:;' . $item . ')*\](?![(\[:])/u');
        if ($text === null) {
            $cursor->restoreState($state);

            return false;
        }

        $items = [];
        foreach (explode(';', substr($text, 1, -1)) as $part) {
            if (preg_match('/^\s*@(' . self::KEY . ')(?:\s*,\s*(.*?))?\s*$/su', $part, $m) === 1) {
                $items[] = ['key' => $m[1], 'locator' => trim($m[2] ?? '')];
            }
        }
        $inlineContext->getContainer()->appendChild(new CitationNode($items, $text));

        return true;
    }
}
