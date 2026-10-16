<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Node\Inline\Text;

/**
 * After parsing, two additions to a pipe table:
 *  - a paragraph `Table: caption` right after it becomes its <caption>
 *  - a cell holding just `<<` joins the cell on its left (colspan); an empty
 *    cell stays empty, so `| a | | b |` is unchanged, and the first cell of a
 *    row keeps a `<<` as typed
 */
final class TableExtras
{
    public function __invoke(DocumentParsedEvent $event): void
    {
        $tables = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Table) {
                $tables[] = $node;
            }
        }

        foreach ($tables as $table) {
            $this->spans($table);
            $this->caption($table);
        }
    }

    private function spans(Table $table): void
    {
        $cells = [];
        foreach ($table->iterator() as $node) {
            if ($node instanceof TableCell) {
                $cells[] = $node;
            }
        }

        foreach ($cells as $cell) {
            $first = $cell->firstChild();
            if (!$first instanceof Text || $first !== $cell->lastChild() || $first->getLiteral() !== '<<') {
                continue;
            }
            $previous = $cell->previous();
            if (!$previous instanceof TableCell) {
                continue;
            }
            $span = (int) ($previous->data->get('attributes/colspan', '1')) + 1;
            $previous->data->set('attributes/colspan', (string) $span);
            $cell->detach();
        }
    }

    private function caption(Table $table): void
    {
        $paragraph = $table->next();
        if (!$paragraph instanceof Paragraph) {
            return;
        }
        $first = $paragraph->firstChild();
        if (!$first instanceof Text || !str_starts_with($first->getLiteral(), 'Table: ')) {
            return;
        }

        $first->setLiteral(substr($first->getLiteral(), 7));
        $caption = new TableCaption();
        while ($paragraph->firstChild() !== null) {
            $caption->appendChild($paragraph->firstChild());
        }
        $paragraph->detach();
        $table->prependChild($caption);
    }
}
