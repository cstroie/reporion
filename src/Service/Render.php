<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Node as CommonMarkNode;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Util\HtmlFilter;
use Reporion\Support\Slug;

/**
 * Canonical rendering (CLAUDE.md invariant 4): the page view, print, PDF,
 * ODT and the index all go through toHtml(). The editor's live preview may
 * run marked.js in the browser instead (D17), but only inside the same
 * dialect — generic CommonMark plus tables, nothing else, no raw HTML
 * passthrough, no footnotes, no custom macros — which is exactly the
 * subset tests/RenderConformanceTest holds both parsers to. This class is
 * the dialect: it does not read config, because the dialect is not a site
 * setting.
 */
final class Render
{
    private readonly MarkdownParser $parser;
    private readonly HtmlRenderer $renderer;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => HtmlFilter::ESCAPE,
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    public function toHtml(string $markdown): RenderResult
    {
        $document = $this->parser->parse($markdown);
        $html = (string) $this->renderer->renderDocument($document);

        return new RenderResult($html, $this->extractToc($document), $this->extractWarnings($document));
    }

    /**
     * @return list<array{level: int, text: string, slug: string}>
     */
    private function extractToc(CommonMarkNode $document): array
    {
        $toc = [];
        $seenSlugs = [];

        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }

            $text = $this->plainText($node);
            if ($text === '') {
                continue;
            }

            $slug = Slug::normalize($text);
            // A heading can repeat a word for word title elsewhere in the
            // same document ("Concluzie" after a "Concluzie" subsection,
            // say) — de-duplicate so the toc's slugs stay usable as anchors.
            if (isset($seenSlugs[$slug])) {
                $slug .= '-' . ++$seenSlugs[$slug];
            } else {
                $seenSlugs[$slug] = 1;
            }

            $toc[] = ['level' => $node->getLevel(), 'text' => $text, 'slug' => $slug];
        }

        return $toc;
    }

    /**
     * @return list<string>
     */
    private function extractWarnings(CommonMarkNode $document): array
    {
        $warnings = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                // html_input=ESCAPE already made this safe to render; the
                // warning is so the editor can tell the author their markup
                // was not interpreted (D17: no HTML passthrough).
                $warnings[] = 'Raw HTML is not part of the dialect and was shown as text, not rendered.';
                break;
            }
        }

        return $warnings;
    }

    private function plainText(CommonMarkNode $node): string
    {
        $text = '';
        foreach ($node->iterator() as $descendant) {
            if ($descendant instanceof StringContainerInterface) {
                $text .= $descendant->getLiteral();
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
