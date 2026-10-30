<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * What the dialect adds to CommonMark that league/commonmark does not ship:
 * superscript `^x^` and subscript `~x~`, abbreviations `*[MRI]: …`,
 * callouts `::: warning Title` … `:::`, table captions and column spans,
 * ☐/☑ task marks and `[@key]` citations (Render builds their References section). (Highlight `==x==`, autolinks, task lists and tables
 * are the library's own, added in Service\Render.)
 */
final class DialectExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new CitationParser(), 110)
            ->addRenderer(CitationNode::class, new CitationRenderer())
            ->addDelimiterProcessor(new ScriptDelimiterProcessor('^', 'sup'))
            ->addDelimiterProcessor(new ScriptDelimiterProcessor('~', 'sub'))
            ->addRenderer(ScriptSpan::class, new ScriptSpanRenderer())
            ->addBlockStartParser(new AbbreviationStartParser(), 50)
            ->addRenderer(Abbreviation::class, new AbbreviationRenderer())
            ->addEventListener(DocumentParsedEvent::class, new AbbreviationExpander())
            ->addBlockStartParser(new AdmonitionStartParser(), 50)
            ->addRenderer(Admonition::class, new AdmonitionRenderer())
            ->addEventListener(DocumentParsedEvent::class, new TableExtras())
            ->addRenderer(TableCaption::class, new TableCaptionRenderer())
            ->addRenderer(TaskListItemMarker::class, new TaskMarkRenderer(), 10);
    }
}
