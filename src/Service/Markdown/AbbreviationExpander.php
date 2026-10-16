<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;

/**
 * After parsing: the document's `*[ABBR]: title` lines are read and removed,
 * and each whole-word, case-sensitive occurrence of an ABBR in running text
 * (not in a link, an image or code) becomes an Abbreviation.
 */
final class AbbreviationExpander
{
    public function __invoke(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();

        $titles = [];
        $definitions = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof AbbreviationDefinition) {
                $titles[$node->abbreviation] = $node->title;
                $definitions[] = $node;
            }
        }
        foreach ($definitions as $definition) {
            $definition->detach();
        }
        if ($titles === []) {
            return;
        }

        uksort($titles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = '/(?<![\p{L}\p{N}_])(' . implode('|', array_map(static fn (string $a): string => preg_quote($a, '/'), array_keys($titles))) . ')(?![\p{L}\p{N}_])/u';

        $texts = [];
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Text) {
                continue;
            }
            for ($parent = $node->parent(); $parent !== null; $parent = $parent->parent()) {
                if ($parent instanceof Link || $parent instanceof Image || $parent instanceof Abbreviation) {
                    continue 2;
                }
            }
            $texts[] = $node;
        }

        foreach ($texts as $text) {
            $parts = preg_split($pattern, $text->getLiteral(), -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false || \count($parts) < 2) {
                continue;
            }
            foreach ($parts as $i => $part) {
                if ($part === '') {
                    continue;
                }
                if ($i % 2 === 1) {
                    $abbr = new Abbreviation($titles[$part]);
                    $abbr->appendChild(new Text($part));
                    $text->insertBefore($abbr);
                } else {
                    $text->insertBefore(new Text($part));
                }
            }
            $text->detach();
        }
    }
}
