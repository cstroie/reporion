<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Delimiter\DelimiterInterface;
use League\CommonMark\Delimiter\Processor\DelimiterProcessorInterface;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Text;

/**
 * One character ($char) opening and closing a <sup> or <sub>. Only a single
 * character pairs (`~~x~~` stays as typed: strikethrough is not in the dialect),
 * and the span holds no space, so a lone "~5 mm ... ~6 mm" is never a subscript.
 */
final class ScriptDelimiterProcessor implements DelimiterProcessorInterface
{
    public function __construct(private readonly string $char, private readonly string $tag)
    {
    }

    public function getOpeningCharacter(): string
    {
        return $this->char;
    }

    public function getClosingCharacter(): string
    {
        return $this->char;
    }

    public function getMinLength(): int
    {
        return 1;
    }

    public function getDelimiterUse(DelimiterInterface $opener, DelimiterInterface $closer): int
    {
        return $opener->getLength() === 1 && $closer->getLength() === 1 ? 1 : 0;
    }

    public function process(AbstractStringContainer $opener, AbstractStringContainer $closer, int $delimiterUse): void
    {
        $inner = [];
        for ($next = $opener->next(); $next !== null && $next !== $closer; $next = $next->next()) {
            $inner[] = $next;
        }

        $spaced = false;
        foreach ($inner as $node) {
            if ($node instanceof Text && preg_match('/\s/u', $node->getLiteral()) === 1) {
                $spaced = true;
                break;
            }
        }

        if ($spaced || $inner === []) {
            // The engine already took the delimiters off the two ends: put them back as typed
            $opener->insertAfter(new Text($this->char));
            $closer->insertBefore(new Text($this->char));

            return;
        }

        $span = new ScriptSpan($this->tag);
        foreach ($inner as $node) {
            $span->appendChild($node);
        }
        $opener->insertAfter($span);
    }
}
