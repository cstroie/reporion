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
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node as CommonMarkNode;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Util\HtmlFilter;
use Reporion\Support\BodyFormat;
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
     * @param bool $examIds         a multi-exam report (Support\Exams): each top-level
     *                              `##` is an exam, anchored `exam-1`, `exam-2`… instead
     *                              of its text's slug — the preview does the same
     */
    public function toHtml(string $markdown, string $basePath = '', bool $unlinkPages = false, ?Closure $mediaSrc = null, bool $examIds = false): RenderResult
    {
        return $this->markdown($markdown, $basePath, $unlinkPages, $mediaSrc, $examIds);
    }

    /**
     * A page's body in its own format (D40, docs/FORMATS.md §3j): markdown
     * through toHtml(), or `format: text` as typed — escaped, preformatted,
     * with no table of contents, links or images to resolve.
     *
     * @param array<string, mixed> $frontmatter the page's (or that revision's) own
     * @param ?Closure(string $sha256, string $ext): ?string $mediaSrc
     */
    public function body(string $body, array $frontmatter, string $basePath = '', bool $unlinkPages = false, ?Closure $mediaSrc = null, bool $examIds = false): RenderResult
    {
        if (BodyFormat::isText($frontmatter)) {
            return new RenderResult(self::textBody($body), [], []);
        }

        return $this->markdown($body, $basePath, $unlinkPages, $mediaSrc, $examIds);
    }

    /** A text body as HTML: every character escaped, every space and line kept */
    public static function textBody(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text), "\n");

        return $text === '' ? '' : '<pre class="wk-plaintext">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>\n";
    }

    /** @param ?Closure(string $sha256, string $ext): ?string $mediaSrc */
    private function markdown(string $markdown, string $basePath, bool $unlinkPages, ?Closure $mediaSrc, bool $examIds): RenderResult
    {
        $document = $this->parser->parse($markdown);
        $this->resolvePageLinks($document, $basePath, $unlinkPages);
        $this->resolveMedia($document, $mediaSrc ?? static fn (string $sha256, string $ext): string => $basePath . '/media/' . $sha256 . '.' . $ext);
        // Anchors first: the table of contents links to them
        $toc = $this->extractToc($document, $examIds ? explode("\n", $markdown) : null);
        $html = (string) $this->renderer->renderDocument($document);
        // A fenced block with no language fence (```) renders bare <pre><code>;
        // marking it nohighlight stops highlight.js (templates/layout.php)
        // guessing a language for it.
        $html = str_replace('<pre><code>', '<pre><code class="nohighlight">', $html);

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
    /**
     * @param ?list<string> $examLines the source lines of a multi-exam report,
     *        whose `##` lines are its exams (Support\Exams); null otherwise
     */
    private function extractToc(CommonMarkNode $document, ?array $examLines): array
    {
        $toc = [];
        $seenSlugs = [];
        $exam = 0;

        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }

            // Only a `##` line is an exam, as Support\Exams counts them — not a setext `---` heading
            if ($examLines !== null && $node->getLevel() === 2 && $node->parent() instanceof Document
                && preg_match('/^ {0,3}##(?:[ \t]|$)/', $examLines[(int) $node->getStartLine() - 1] ?? '') === 1) {
                $slug = 'exam-' . ++$exam;
                $node->data->set('attributes/id', $slug);
                $toc[] = ['level' => 2, 'text' => $this->plainText($node), 'slug' => $slug];
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
