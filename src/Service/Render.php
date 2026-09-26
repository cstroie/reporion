<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node as CommonMarkNode;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Util\HtmlFilter;
use Reporion\Support\InternalLink;
use Reporion\Support\MediaRef;
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

    /**
     * @param string $basePath      where the app is mounted, for links between pages
     *                              (Support\InternalLink) — Request::$basePath
     * @param bool   $unlinkPages   print and export: a link to another page keeps its
     *                              text but loses its address, which would carry that
     *                              page's path — a patient path, for a report (invariant 8)
     * @param ?Closure(string $sha256, string $ext): ?string $mediaSrc
     *                              the src for an attached image (Support\MediaRef) —
     *                              print embeds the bytes; null drops the image for its
     *                              alt text. Default: {basePath}/media/{sha256}.{ext}
     */
    public function toHtml(string $markdown, string $basePath = '', bool $unlinkPages = false, ?Closure $mediaSrc = null): RenderResult
    {
        $document = $this->parser->parse($markdown);
        $this->resolvePageLinks($document, $basePath, $unlinkPages);
        $this->resolveMedia($document, $mediaSrc ?? static fn (string $sha256, string $ext): string => $basePath . '/media/' . $sha256 . '.' . $ext);
        // Anchors first: the table of contents links to them
        $toc = $this->extractToc($document);
        $html = (string) $this->renderer->renderDocument($document);

        return new RenderResult($html, $toc, $this->extractWarnings($document));
    }

    private function resolvePageLinks(CommonMarkNode $document, string $basePath, bool $unlink): void
    {
        $links = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof Link && !$node instanceof Image) {
                $href = InternalLink::href($node->getUrl(), $basePath);
                if ($href !== null) {
                    $links[] = [$node, $href];
                }
            }
        }

        // Changed after the walk, not during it
        foreach ($links as [$node, $href]) {
            if (!$unlink) {
                $node->setUrl($href);
                continue;
            }
            foreach ($node->children() as $child) {
                $node->insertBefore($child);
            }
            $node->detach();
        }
    }

    private function resolveMedia(CommonMarkNode $document, Closure $mediaSrc): void
    {
        $images = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof Image && ($ref = MediaRef::parse($node->getUrl())) !== null) {
                $images[] = [$node, $ref];
            }
        }

        foreach ($images as [$node, $ref]) {
            $src = $mediaSrc($ref['sha256'], $ref['ext']);
            if ($src !== null) {
                $node->setUrl($src);
                continue;
            }
            $node->insertBefore(new Text($this->plainText($node)));
            $node->detach();
        }
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

            $slug = self::headingSlug($text);
            // A heading can repeat a word for word title elsewhere in the
            // same document ("Concluzie" after a "Concluzie" subsection,
            // say) — de-duplicate so the toc's slugs stay usable as anchors.
            if (isset($seenSlugs[$slug])) {
                $slug .= '-' . ++$seenSlugs[$slug];
            } else {
                $seenSlugs[$slug] = 1;
            }

            // The anchor the table of contents links to — assets/js/markdown-preview.js
            // gives the preview the same ids (D17)
            $node->data->set('attributes/id', $slug);
            $toc[] = ['level' => $node->getLevel(), 'text' => $text, 'slug' => $slug];
        }

        return $toc;
    }

    /** A heading's anchor: its slug, or "section" for one with no letters or digits ("## ---") */
    private static function headingSlug(string $text): string
    {
        try {
            return Slug::normalize($text);
        } catch (\InvalidArgumentException) {
            return 'section';
        }
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
